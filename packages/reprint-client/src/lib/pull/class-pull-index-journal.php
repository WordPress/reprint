<?php

use function Reprint\Importer\merge_local_index_mutations;
use function Reprint\Importer\read_remote_index_entry;
use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\write_local_index_update;
use function WordPress\Reprint\Exporter\relative_path_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Owns pull/index.wal — the append-only journal of completed files-pull
 * mutations — and publishes its records into the accounted indexes.
 *
 * Each record describes one completed local mutation: a pulled file, symlink,
 * or empty directory; a completed local deletion; or a remote invalidation
 * with no local projection. ImportClient appends records as it completes work
 * and calls flush() before saving the cursor which makes that work durable.
 * apply_pending() merges complete records into the remote index and projects
 * them into the local index, truncating the journal only after both
 * replacements finish, so an interrupted apply replays the batch. An
 * unterminated final record is ignored; the resumed files-pull repeats it
 * from the preceding durable cursor.
 *
 * The journal file doubles as the unfinished files-pull lifecycle marker:
 * open() creates it, applied batches leave the empty file in place, and
 * remove_empty_marker() removes it once files-pull completes or aborts.
 */
class PullIndexJournal
{
    private ImportClient $client;

    /** @var string Path to pull/index.wal. */
    private string $pull_index_wal_path;

    /** @var resource|null Open file handle for $pull_index_wal_path while writing. */
    private $pull_index_wal_handle;

    /** @var string Remote index accounted for in the filesystem root. */
    private string $remote_index_path;

    /** @var string Retained filesystem-root snapshot shared with push. */
    private string $local_index_path;

    /** @var string Resolved absolute filesystem root local paths project under. */
    private string $filesystem_root;

    public function __construct(
        ImportClient $client,
        string $pull_index_wal_path,
        string $remote_index_path,
        string $local_index_path,
        string $filesystem_root
    ) {
        $this->client = $client;
        $this->pull_index_wal_path = $pull_index_wal_path;
        $this->remote_index_path = $remote_index_path;
        $this->local_index_path = $local_index_path;
        $this->filesystem_root = $filesystem_root;
    }

    /** Creates or retains the journal file and opens it for appending. */
    public function open(): void
    {
        if ($this->pull_index_wal_handle) {
            return;
        }
        $pull_index_wal_is_new = !is_file($this->pull_index_wal_path);
        $this->pull_index_wal_handle = fopen($this->pull_index_wal_path, "a");
        if (!$this->pull_index_wal_handle) {
            throw new RuntimeException("Failed to open the pull index WAL.");
        }
        if ($pull_index_wal_is_new) {
            $this->client->audit_log(
                "FILE CREATE | {$this->pull_index_wal_path} | pull index WAL",
            );
        }
    }

    /** Whether the journal is open for appending. */
    public function is_open(): bool
    {
        return is_resource($this->pull_index_wal_handle);
    }

    /**
     * Records a completed remote upsert, deriving the local index projection
     * from the local absolute path when one is provided.
     *
     * Files-pull provides the local absolute path only after it contains the
     * pulled file, symlink, or empty directory. For example:
     *
     *     filesystem root:      /var/www
     *     remote absolute path: /srv/site/file.txt
     *     local absolute path:  /var/www/file.txt
     *
     * Applying the journal produces decoded entries such as:
     *
     *     remote index: /srv/site/file.txt  file, size 4, ctime 10
     *     local index:  file.txt            file, size 4, ctime 12
     *
     * The remote index records the remote state files-pull accounted for. The
     * local index records the resulting local path type, size, and ctime.
     * Without that local index entry, files-diff and PushPlan would compare
     * the pulled path with the older local index and select it as a local
     * change. A null local absolute path, a path outside the filesystem root,
     * or a default-skipped path updates only the remote index.
     */
    public function record_remote_upsert(
        string $remote_absolute_path,
        int $remote_path_ctime,
        int $remote_path_size,
        string $remote_path_type,
        ?string $local_absolute_path = null
    ): void {
        $pull_index_wal_record = [
            "op" => "+",
            "remote_absolute_path_b64" => base64_encode($remote_absolute_path),
            "remote_path_ctime" => $remote_path_ctime,
            "remote_path_size" => $remote_path_size,
            "remote_path_type" => $remote_path_type,
        ];
        $local_relative_path = $local_absolute_path === null
            ? null
            : $this->local_relative_path_from_local_absolute_path(
                $local_absolute_path
            );
        if ($local_relative_path !== null) {
            clearstatcache(true, $local_absolute_path);
            $local_path_stat = lstat($local_absolute_path);
            if ($local_path_stat === false) {
                throw new RuntimeException(
                    "Failed to inspect the pulled local absolute path: {$local_absolute_path}."
                );
            }
            $local_file_type_bits = $local_path_stat["mode"] & 0170000;
            if ($local_file_type_bits === 0120000) {
                $local_path_type = "link";
            } elseif ($local_file_type_bits === 0040000) {
                $local_path_type = "dir";
            } elseif ($local_file_type_bits === 0100000) {
                $local_path_type = "file";
            } else {
                throw new RuntimeException(
                    "The pulled local absolute path has an unsupported type: {$local_absolute_path}."
                );
            }
            $pull_index_wal_record["local_relative_path_b64"] =
                base64_encode($local_relative_path);
            $pull_index_wal_record["local_path_ctime"] = (int) $local_path_stat["ctime"];
            $pull_index_wal_record["local_path_size"] =
                $local_path_type === "dir" ? 0 : (int) $local_path_stat["size"];
            $pull_index_wal_record["local_path_type"] = $local_path_type;
        }
        $this->write_record($pull_index_wal_record);
    }

    /** Records a completed local deletion. Call only after the local path is gone. */
    public function record_successful_deletion(
        string $remote_absolute_path,
        string $local_absolute_path
    ): void {
        $pull_index_wal_record = [
            "op" => "-",
            "remote_absolute_path_b64" => base64_encode($remote_absolute_path),
        ];
        $local_relative_path = $this->local_relative_path_from_local_absolute_path(
            $local_absolute_path
        );
        if ($local_relative_path !== null) {
            $pull_index_wal_record["local_relative_path_b64"] =
                base64_encode($local_relative_path);
        }
        $this->write_record($pull_index_wal_record);
    }

    /** Records remote state which this pull did not account for locally. */
    public function record_remote_invalidation(string $remote_absolute_path): void
    {
        $this->write_record([
            "op" => "-",
            "remote_absolute_path_b64" => base64_encode($remote_absolute_path),
        ]);
    }

    /** Flushes appended records. Call immediately before saving a cursor checkpoint. */
    public function flush(): void
    {
        if (
            $this->pull_index_wal_handle
            && !fflush($this->pull_index_wal_handle)
        ) {
            throw new RuntimeException('Failed to flush the pull index WAL.');
        }
    }

    /**
     * Closes the writer, applies complete records to the remote index and
     * then the local index, and truncates the journal only after both
     * replacements succeed.
     */
    public function apply_pending(): void
    {
        if ($this->pull_index_wal_handle) {
            $pull_index_wal_closed = fclose($this->pull_index_wal_handle);
            $this->pull_index_wal_handle = null;
            if (!$pull_index_wal_closed) {
                throw new RuntimeException("Failed to flush the pull index WAL.");
            }
        }
        clearstatcache(true, $this->pull_index_wal_path);
        if (
            !is_file($this->pull_index_wal_path)
            || filesize($this->pull_index_wal_path) === 0
        ) {
            return;
        }

        $remote_index_replacement_file = $this->remote_index_path . ".new";

        $this->client->audit_log(
            "INDEX MERGE START | merging pull index WAL into {$this->remote_index_path}",
        );

        $remote_index_file_handle = file_exists($this->remote_index_path)
            ? fopen($this->remote_index_path, "r")
            : null;
        $pull_index_wal_file_handle = fopen($this->pull_index_wal_path, "r");
        $remote_index_replacement_file_handle = fopen($remote_index_replacement_file, "w");

        if (!$pull_index_wal_file_handle || !$remote_index_replacement_file_handle) {
            throw new RuntimeException("Failed to merge remote index updates.");
        }

        $write_remote_index_entry = function ($remote_index_destination_file_handle, array $remote_index_entry_to_write): void {
            $remote_index_json_line = json_encode(
                [
                    "path" => base64_encode($remote_index_entry_to_write["path"]),
                    "ctime" => (int) $remote_index_entry_to_write["ctime"],
                    "size" => (int) $remote_index_entry_to_write["size"],
                    "type" => (string) $remote_index_entry_to_write["type"],
                ],
                JSON_UNESCAPED_SLASHES,
            );
            if ($remote_index_json_line !== false) {
                fwrite($remote_index_destination_file_handle, $remote_index_json_line . "\n");
            }
        };

        $remote_index_entry = read_remote_index_entry($remote_index_file_handle);
        $remote_index_update_lookahead = null;
        $remote_index_update = $this->read_remote_index_update(
            $pull_index_wal_file_handle,
            $remote_index_update_lookahead
        );
        $last_written_remote_index_entry_path = null;

        while ($remote_index_entry !== null || $remote_index_update !== null) {
            if ($remote_index_update === null) {
                if ($last_written_remote_index_entry_path !== $remote_index_entry["path"]) {
                    $write_remote_index_entry($remote_index_replacement_file_handle, $remote_index_entry);
                    $last_written_remote_index_entry_path = $remote_index_entry["path"];
                }
                $remote_index_entry = read_remote_index_entry($remote_index_file_handle);
                continue;
            }

            if ($remote_index_entry === null) {
                if (
                    !$remote_index_update["delete"] &&
                    $last_written_remote_index_entry_path !== $remote_index_update["path"]
                ) {
                    $write_remote_index_entry($remote_index_replacement_file_handle, $remote_index_update);
                    $last_written_remote_index_entry_path = $remote_index_update["path"];
                }
                $remote_index_update = $this->read_remote_index_update(
                    $pull_index_wal_file_handle,
                    $remote_index_update_lookahead
                );
                continue;
            }

            $remote_index_entry_path_comparison = strcmp($remote_index_entry["path"], $remote_index_update["path"]);
            if ($remote_index_entry_path_comparison === 0) {
                if (
                    !$remote_index_update["delete"] &&
                    $last_written_remote_index_entry_path !== $remote_index_update["path"]
                ) {
                    $write_remote_index_entry($remote_index_replacement_file_handle, $remote_index_update);
                    $last_written_remote_index_entry_path = $remote_index_update["path"];
                }
                $remote_index_entry = read_remote_index_entry($remote_index_file_handle);
                $remote_index_update = $this->read_remote_index_update(
                    $pull_index_wal_file_handle,
                    $remote_index_update_lookahead
                );
            } elseif ($remote_index_entry_path_comparison < 0) {
                if ($last_written_remote_index_entry_path !== $remote_index_entry["path"]) {
                    $write_remote_index_entry($remote_index_replacement_file_handle, $remote_index_entry);
                    $last_written_remote_index_entry_path = $remote_index_entry["path"];
                }
                $remote_index_entry = read_remote_index_entry($remote_index_file_handle);
            } else {
                if (
                    !$remote_index_update["delete"] &&
                    $last_written_remote_index_entry_path !== $remote_index_update["path"]
                ) {
                    $write_remote_index_entry($remote_index_replacement_file_handle, $remote_index_update);
                    $last_written_remote_index_entry_path = $remote_index_update["path"];
                }
                $remote_index_update = $this->read_remote_index_update(
                    $pull_index_wal_file_handle,
                    $remote_index_update_lookahead
                );
            }
        }

        if ($remote_index_file_handle) {
            fclose($remote_index_file_handle);
        }
        fclose($pull_index_wal_file_handle);
        fclose($remote_index_replacement_file_handle);

        if (!rename($remote_index_replacement_file, $this->remote_index_path)) {
            throw new RuntimeException("Failed to replace the remote index file.");
        }
        $this->client->audit_log("INDEX MERGE COMPLETE | {$this->remote_index_path} updated");

        /*
         * Rebuild the sorted local index updates from the pull index WAL. This
         * temporary file is disposable: the WAL remains until both index
         * replacements finish, so resume can discard a partial file and replay
         * the batch. The WAL is in completion order, while the local index
         * merge requires local relative path byte order. Records without a
         * local relative path update only the remote index. An unterminated
         * final record is repeated from the preceding durable cursor when
         * files-pull resumes.
         */
        $local_index_updates_path = $this->pull_index_wal_path . ".local";
        $pull_index_wal_file_handle = fopen($this->pull_index_wal_path, "r");
        $local_index_updates_handle = fopen($local_index_updates_path, "w");
        if (!$pull_index_wal_file_handle || !$local_index_updates_handle) {
            throw new RuntimeException("Failed to prepare the local index updates.");
        }

        $local_index_updates_written = 0;
        while (( $pull_index_wal_json_line = fgets($pull_index_wal_file_handle) ) !== false) {
            if (
                substr($pull_index_wal_json_line, -1) !== "\n"
                && feof($pull_index_wal_file_handle)
            ) {
                break;
            }
            $pull_index_wal_record = json_decode($pull_index_wal_json_line, true);
            if (!is_array($pull_index_wal_record)) {
                throw new RuntimeException("Invalid pull index WAL line format.");
            }
            if (!array_key_exists("local_relative_path_b64", $pull_index_wal_record)) {
                continue;
            }
            $local_index_update = [
                "op" => $pull_index_wal_record["op"],
                "path" => $pull_index_wal_record["local_relative_path_b64"],
            ];
            if ($pull_index_wal_record["op"] === "+") {
                $local_index_update += [
                    "ctime" => $pull_index_wal_record["local_path_ctime"],
                    "size" => $pull_index_wal_record["local_path_size"],
                    "type" => $pull_index_wal_record["local_path_type"],
                ];
            }
            write_local_index_update(
                $local_index_updates_handle,
                $local_index_update
            );
            ++$local_index_updates_written;
        }
        fclose($pull_index_wal_file_handle);
        fclose($local_index_updates_handle);

        if ($local_index_updates_written > 0) {
            sort_index_file($local_index_updates_path);
            merge_local_index_mutations(
                $this->local_index_path,
                $local_index_updates_path
            );
        }
        @unlink($local_index_updates_path);

        if (file_put_contents($this->pull_index_wal_path, "") === false) {
            throw new RuntimeException(
                "Failed to clear the applied pull index WAL."
            );
        }
        $this->client->audit_log(
            "FILE TRUNCATE | {$this->pull_index_wal_path} | pull index WAL batch applied"
        );
    }

    /** Removes the marker after files-pull completes or is aborted. Refuses a non-empty journal. */
    public function remove_empty_marker(): void
    {
        if (is_resource($this->pull_index_wal_handle)) {
            if (!fclose($this->pull_index_wal_handle)) {
                throw new RuntimeException("Failed to flush the pull index WAL.");
            }
            $this->pull_index_wal_handle = null;
        }
        clearstatcache(true, $this->pull_index_wal_path);
        if (
            is_file($this->pull_index_wal_path)
            && filesize($this->pull_index_wal_path) > 0
        ) {
            throw new RuntimeException(
                "Cannot remove an unapplied pull index WAL."
            );
        }
        if (
            is_file($this->pull_index_wal_path)
            && !unlink($this->pull_index_wal_path)
        ) {
            throw new RuntimeException("Failed to remove the pull index WAL.");
        }
    }

    /**
     * Appends one complete record to the pull index WAL.
     *
     * @param array $pull_index_wal_record {
     *     One completed pull mutation, with local fields when files-pull
     *     changed a non-skipped path beneath the filesystem root.
     *
     *     @type string $op                       `+` upsert or `-` deletion.
     *     @type string $remote_absolute_path_b64 Base64 remote absolute path.
     *     @type int    $remote_path_ctime        Remote ctime for `+`.
     *     @type int    $remote_path_size         Remote size for `+`.
     *     @type string $remote_path_type         Remote type for `+`.
     *     @type string $local_relative_path_b64  Base64 local relative path
     *                                             when the completed mutation
     *                                             belongs in the local index.
     *     @type int    $local_path_ctime         Local ctime for a local `+`.
     *     @type int    $local_path_size          Local size for a local `+`.
     *     @type string $local_path_type          Local type for a local `+`.
     * }
     */
    private function write_record(
        array $pull_index_wal_record
    ): void
    {
        if (!$this->pull_index_wal_handle) {
            $this->open();
        }
        $pull_index_wal_json_line = json_encode(
            $pull_index_wal_record,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (
            fwrite($this->pull_index_wal_handle, $pull_index_wal_json_line)
            !== strlen($pull_index_wal_json_line)
        ) {
            throw new RuntimeException(
                "Failed to write to the pull index WAL (disk full?)."
            );
        }
    }

    /** Returns the local relative path stored in the local index. */
    private function local_relative_path_from_local_absolute_path(
        string $local_absolute_path
    ): ?string {
        $local_relative_path = relative_path_under(
            $local_absolute_path,
            $this->filesystem_root
        );
        if (
            $local_relative_path === null
            || $local_relative_path === ""
        ) {
            return null;
        }
        return FileIndexProcessor::path_is_default_skipped(
            $local_relative_path
        )
            ? null
            : $local_relative_path;
    }

    /** Reads one raw remote index projection from the pull index WAL. */
    private function read_raw_remote_index_update(
        $pull_index_wal_file_handle
    ): ?array {
        if (!$pull_index_wal_file_handle) {
            return null;
        }
        while (( $pull_index_wal_json_line = fgets($pull_index_wal_file_handle) ) !== false) {
            if (substr($pull_index_wal_json_line, -1) !== "\n" && feof($pull_index_wal_file_handle)) {
                return null;
            }
            $pull_index_wal_json_line = trim($pull_index_wal_json_line);
            if ($pull_index_wal_json_line === "") {
                continue;
            }
            $pull_index_wal_record = json_decode($pull_index_wal_json_line, true);
            if (!is_array($pull_index_wal_record)) {
                throw new RuntimeException("Invalid pull index WAL line format.");
            }
            $pull_index_wal_operation = $pull_index_wal_record["op"] ?? null;
            $remote_absolute_path_base64 =
                $pull_index_wal_record["remote_absolute_path_b64"] ?? null;
            if (
                !is_string($remote_absolute_path_base64)
                || $remote_absolute_path_base64 === ""
            ) {
                throw new RuntimeException(
                    "Invalid pull index WAL remote absolute path."
                );
            }
            $remote_absolute_path = base64_decode($remote_absolute_path_base64, true);
            if ($remote_absolute_path === false || $remote_absolute_path === "") {
                throw new RuntimeException(
                    "Invalid pull index WAL remote absolute path (base64 decode failed)."
                );
            }
            if ($pull_index_wal_operation === "-") {
                return [
                    "path" => $remote_absolute_path,
                    "delete" => true,
                    "ctime" => 0,
                    "size" => 0,
                    "type" => null,
                ];
            }
            if ($pull_index_wal_operation === "+") {
                return [
                    "path" => $remote_absolute_path,
                    "delete" => false,
                    "ctime" => (int) ($pull_index_wal_record["remote_path_ctime"] ?? 0),
                    "size" => (int) ($pull_index_wal_record["remote_path_size"] ?? 0),
                    "type" => (string) ($pull_index_wal_record["remote_path_type"] ?? "file"),
                ];
            }
        }
        return null;
    }

    /**
     * Reads one remote index update, keeping the last consecutive update for
     * the same remote absolute path.
     *
     * @param mixed      $pull_index_wal_file_handle Open WAL handle.
     * @param array|null $remote_index_update_lookahead Retained lookahead.
     */
    private function read_remote_index_update(
        $pull_index_wal_file_handle,
        ?array &$remote_index_update_lookahead = null
    ): ?array {
        if (!$pull_index_wal_file_handle) {
            return null;
        }
        $current_remote_index_update =
            $remote_index_update_lookahead
            ?? $this->read_raw_remote_index_update(
                $pull_index_wal_file_handle
            );
        $remote_index_update_lookahead = null;
        if ($current_remote_index_update === null) {
            return null;
        }

        while (true) {
            $next_remote_index_update = $this->read_raw_remote_index_update(
                $pull_index_wal_file_handle
            );
            if ($next_remote_index_update === null) {
                return $current_remote_index_update;
            }
            if (
                $next_remote_index_update["path"]
                !== $current_remote_index_update["path"]
            ) {
                $remote_index_update_lookahead =
                    $next_remote_index_update;
                return $current_remote_index_update;
            }
            $current_remote_index_update = $next_remote_index_update;
        }
    }
}
