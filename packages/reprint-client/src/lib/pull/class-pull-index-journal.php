<?php

use function Reprint\Importer\merge_local_index_mutations;
use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\write_local_index_update;
use function WordPress\Reprint\Exporter\relative_path_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Keeps `pull/index.wal` and applies its records to the remote and local
 * indexes.
 *
 * Files-pull changes a local path first. It then writes one WAL record. This
 * class does not change files, links, or directories. It only records finished
 * work and updates the indexes.
 *
 * ## WAL records
 *
 * Each record is one line of JSON. Paths use base64 because a Unix path may
 * contain bytes which are not valid UTF-8.
 *
 * A `+` record adds or replaces a path. For example, files-pull copies
 * `/srv/site/file.txt` to `/var/www/file.txt`, where `/var/www` is the
 * filesystem root. It writes this record (shown on several lines here):
 *
 *     {
 *         "op": "+",
 *         "remote_absolute_path_b64": "L3Nydi9zaXRlL2ZpbGUudHh0",
 *         "remote_path_ctime": 10,
 *         "remote_path_size": 4,
 *         "remote_path_type": "file",
 *         "local_relative_path_b64": "ZmlsZS50eHQ=",
 *         "local_path_ctime": 12,
 *         "local_path_size": 4,
 *         "local_path_type": "file"
 *     }
 *
 * The `remote_*` fields become the remote index entry. The `local_*` fields
 * become the local index entry. The local type, size, and ctime come from
 * lstat() after files-pull changes the local path.
 *
 * A `-` record removes a path. This record removes it only from the remote
 * index:
 *
 *     {
 *         "op": "-",
 *         "remote_absolute_path_b64": "L3Nydi9zaXRlL2ZpbGUudHh0"
 *     }
 *
 * To remove the path from both indexes, the record also names the local path:
 *
 *     {
 *         "op": "-",
 *         "remote_absolute_path_b64": "L3Nydi9zaXRlL2ZpbGUudHh0",
 *         "local_relative_path_b64": "ZmlsZS50eHQ="
 *     }
 *
 * No local type, size, or ctime is needed because the local path is already
 * gone. A skipped path or a path outside the filesystem root also has no local
 * fields.
 *
 * Applying the `+` record above writes these index entries:
 *
 *     remote index:
 *     {"path":"L3Nydi9zaXRlL2ZpbGUudHh0","ctime":10,"size":4,"type":"file"}
 *
 *     local index:
 *     {"path":"ZmlsZS50eHQ=","ctime":12,"size":4,"type":"file"}
 *
 * ## Saving and applying records
 *
 * Call flush() before saving the cursor for the recorded work. This makes
 * sure the WAL record reaches the file before the cursor moves past it.
 *
 * apply_pending_records() closes the WAL writer, updates the remote index,
 * and then updates the local index when needed. It clears the WAL only after
 * the remote index and all local changes have been saved.
 *
 * If the process stops before the WAL is cleared, call
 * apply_pending_records() again. It rebuilds its temporary files and applies
 * the full WAL again. A repeated `+` replaces the same entry. A repeated `-`
 * for a missing entry does nothing.
 *
 * A final line without a newline is not applied. flush() runs before the
 * matching cursor is saved, so files-pull repeats that path after it resumes.
 *
 * ## WAL lifecycle
 *
 * open() creates the WAL. Its presence, even when empty, means files-pull has
 * not finished. Applying all records leaves it empty. Call remove_empty_wal()
 * only after files-pull completes or aborts.
 */
class PullIndexJournal
{
    /** @var callable(string):void Writes one journal audit message. */
    private $log_audit_message;

    /** @var string Path to pull/index.wal. */
    private string $pull_index_wal_path;

    /** @var resource|null Open file handle for $pull_index_wal_path while writing. */
    private $pull_index_wal_handle;

    /** @var string Path to the remote index. */
    private string $remote_index_path;

    /** @var string Path to the local index shared with push. */
    private string $local_index_path;

    /** @var string Filesystem root used to make local paths relative. */
    private string $filesystem_root;

    /**
     * Stores the paths and audit callback used by this journal.
     *
     * The constructor does not create or open the WAL.
     *
     * @param callable(string):void $log_audit_message   Writes one audit log message.
     * @param string                $pull_index_wal_path Path to `pull/index.wal`.
     * @param string                $remote_index_path   Remote index path.
     * @param string                $local_index_path    Local index path.
     * @param string                $filesystem_root     Resolved filesystem root.
     */
    public function __construct(
        callable $log_audit_message,
        string $pull_index_wal_path,
        string $remote_index_path,
        string $local_index_path,
        string $filesystem_root
    ) {
        $this->log_audit_message = $log_audit_message;
        $this->pull_index_wal_path = $pull_index_wal_path;
        $this->remote_index_path = $remote_index_path;
        $this->local_index_path = $local_index_path;
        $this->filesystem_root = $filesystem_root;
    }

    /**
     * Opens the WAL for append.
     *
     * A second call keeps the same handle. If the WAL does not exist, this
     * method creates it and writes an audit message.
     *
     * @throws RuntimeException When the WAL cannot be opened.
     */
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
            ($this->log_audit_message)(
                "FILE CREATE | {$this->pull_index_wal_path} | pull index WAL",
            );
        }
    }

    /**
     * Checks whether the WAL is open for append.
     *
     * @return bool True when the writer handle is open.
     */
    public function is_open(): bool
    {
        return is_resource($this->pull_index_wal_handle);
    }

    /**
     * Adds a `+` record after files-pull writes a local path.
     *
     * For example:
     *
     *     filesystem root:      /var/www
     *     remote absolute path: /srv/site/file.txt
     *     local absolute path:  /var/www/file.txt
     *
     * Applying that record produces these decoded entries:
     *
     *     remote index: /srv/site/file.txt  file, size 4, ctime 10
     *     local index:  file.txt            file, size 4, ctime 12
     *
     * The local index must contain the result of the pull. Otherwise,
     * files-diff and PushPlan compare the path with the old local index and
     * treat the pulled path as a new local change.
     *
     * A null local path, a path outside the filesystem root, or a skipped path
     * updates only the remote index.
     *
     * @param string      $remote_absolute_path Source absolute path.
     * @param int         $remote_path_ctime     Source change timestamp.
     * @param int         $remote_path_size      Source size in bytes.
     * @param string      $remote_path_type      `file`, `link`, or `dir`.
     * @param string|null $local_absolute_path   Local path after the pull, or
     *                                           null to update only the remote
     *                                           index.
     * @throws RuntimeException When the local path cannot be read or the
     *                          record cannot be written.
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

    /**
     * Adds a `-` record after files-pull removes a local path.
     *
     * The record always removes the remote index entry. If the local path is
     * under the filesystem root and is not skipped, it removes the local index
     * entry too.
     *
     * @param string $remote_absolute_path Deleted source absolute path.
     * @param string $local_absolute_path  Local path already removed.
     * @throws RuntimeException When the record cannot be appended.
     */
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

    /**
     * Adds a `-` record which changes only the remote index.
     *
     * The record has no `local_relative_path_b64`, so the local index does not
     * change.
     *
     * @param string $remote_absolute_path Source absolute path to remove.
     * @throws RuntimeException When the record cannot be appended.
     */
    public function record_remote_invalidation(string $remote_absolute_path): void
    {
        $this->write_record([
            "op" => "-",
            "remote_absolute_path_b64" => base64_encode($remote_absolute_path),
        ]);
    }

    /**
     * Flushes WAL records before the caller saves the matching cursor.
     *
     * A closed journal has nothing to flush. Saving the cursor first could
     * skip a record after a process stops.
     *
     * @throws RuntimeException When the open WAL cannot be flushed.
     */
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
     * Applies every complete WAL record to the indexes.
     *
     * This method closes the writer first. It replaces the remote index, then
     * the local index when the WAL contains local changes. It clears the WAL
     * only after both replacements finish.
     *
     * If the process stops before that point, the WAL stays in place. The next
     * call opens new `.new` and `.local` files and applies the full WAL again.
     * A repeated `+` replaces the same entry. A repeated `-` for a missing
     * entry writes nothing.
     *
     * A final record without a newline is skipped. flush() runs before the
     * caller saves its cursor, so files-pull repeats that path after resume.
     *
     * @throws RuntimeException When the WAL or an index cannot be read,
     *                          replaced, or cleared.
     */
    public function apply_pending_records(): void
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

        ($this->log_audit_message)(
            "INDEX MERGE START | merging pull index WAL into {$this->remote_index_path}",
        );

        $remote_index_reader = new RemoteIndexReader($this->remote_index_path);
        $remote_index_reader->open();
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

        $remote_index_entry = $remote_index_reader->next_entry();
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
                $remote_index_entry = $remote_index_reader->next_entry();
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
                $remote_index_entry = $remote_index_reader->next_entry();
                $remote_index_update = $this->read_remote_index_update(
                    $pull_index_wal_file_handle,
                    $remote_index_update_lookahead
                );
            } elseif ($remote_index_entry_path_comparison < 0) {
                if ($last_written_remote_index_entry_path !== $remote_index_entry["path"]) {
                    $write_remote_index_entry($remote_index_replacement_file_handle, $remote_index_entry);
                    $last_written_remote_index_entry_path = $remote_index_entry["path"];
                }
                $remote_index_entry = $remote_index_reader->next_entry();
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

        $remote_index_reader->close();
        fclose($pull_index_wal_file_handle);
        fclose($remote_index_replacement_file_handle);

        if (!rename($remote_index_replacement_file, $this->remote_index_path)) {
            throw new RuntimeException("Failed to replace the remote index file.");
        }
        ($this->log_audit_message)("INDEX MERGE COMPLETE | {$this->remote_index_path} updated");

        /*
         * The WAL follows completion order. The local index merge needs local
         * path order, so write the local changes to a temporary file and sort
         * it.
         *
         * The WAL stays until the remote index and all local changes are
         * saved. After a stop, this temporary file can be discarded and
         * rebuilt. Records without a local path change only the remote index.
         * A final record without a newline is skipped; files-pull repeats its
         * path from the last saved cursor.
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
        ($this->log_audit_message)(
            "FILE TRUNCATE | {$this->pull_index_wal_path} | pull index WAL batch applied"
        );
    }

    /**
     * Removes the WAL when it is empty.
     *
     * This method closes the writer first. It refuses to remove a WAL with
     * records because those records have not been applied. A missing WAL needs
     * no work.
     *
     * @throws RuntimeException When pending records remain or the WAL
     *                          cannot be closed or removed.
     */
    public function remove_empty_wal(): void
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
     * Writes one JSON record and its newline to the WAL.
     *
     * If the process writes only part of the string, readers skip that final
     * line. The cursor still points before the record, so files-pull repeats
     * the path.
     *
     * @param array $pull_index_wal_record {
     *     One finished files-pull change. Local fields are present when the
     *     local index must change.
     *
     *     @type string $op                       `+` adds or replaces; `-`
     *                                             removes.
     *     @type string $remote_absolute_path_b64 Base64 remote absolute path.
     *     @type int    $remote_path_ctime        Remote ctime. Present for `+`.
     *     @type int    $remote_path_size         Remote size. Present for `+`.
     *     @type string $remote_path_type         Remote type. Present for `+`.
     *     @type string $local_relative_path_b64  Base64 local relative path
     *                                             when the local index must
     *                                             change.
     *     @type int    $local_path_ctime         Local ctime. Present for a
     *                                             local `+`.
     *     @type int    $local_path_size          Local size. Present for a
     *                                             local `+`.
     *     @type string $local_path_type          Local type. Present for a
     *                                             local `+`.
     * }
     * @throws RuntimeException When the complete record cannot be appended.
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

    /**
     * Returns the path used by the local index.
     *
     * The path is relative to the filesystem root. This method returns null
     * for the root itself, a path outside it, or a skipped path.
     *
     * @param string $local_absolute_path Absolute local path.
     * @return string|null Relative path, or null when the local index must not
     *                     contain it.
     */
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

    /**
     * Reads the next complete WAL record as a remote index change.
     *
     * Blank lines are skipped. A final line without a newline returns null.
     * Local fields are ignored.
     *
     * @param resource|null $pull_index_wal_file_handle Open WAL reader.
     * @return array|null {
     *     Remote-index update, or null at EOF or an incomplete final record.
     *
     *     @type string      $path   Decoded remote absolute path.
     *     @type bool        $delete Whether to remove the remote index entry.
     *     @type int         $ctime  Remote ctime, or zero for a deletion.
     *     @type int         $size   Remote size, or zero for a deletion.
     *     @type string|null $type   Remote type, or null for a deletion.
     * }
     * @throws RuntimeException When a complete record or its remote path is
     *                          invalid.
     */
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
     * Reads changes for one remote path until the next path starts.
     *
     * This method returns the last change for that path. It saves the first
     * change for the following path for the next call. The merge keeps at most
     * two decoded WAL records in memory.
     *
     * @param resource|null $pull_index_wal_file_handle Open WAL reader.
     * @param array|null    $remote_index_update_lookahead First update for the
     *                                                       following path.
     * @return array|null {
     *     Last consecutive update for one path, or null at EOF.
     *
     *     @type string      $path   Decoded remote absolute path.
     *     @type bool        $delete Whether to remove the remote index entry.
     *     @type int         $ctime  Remote ctime, or zero for a deletion.
     *     @type int         $size   Remote size, or zero for a deletion.
     *     @type string|null $type   Remote type, or null for a deletion.
     * }
     * @throws RuntimeException When a complete WAL record is invalid.
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
