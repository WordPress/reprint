<?php

use function Reprint\Importer\merge_local_index_mutations;
use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\write_local_index_update;
use function WordPress\Reprint\Exporter\relative_path_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Applies completed files-pull mutations from `pull/index.wal` to the
 * remote and local indexes.
 *
 * Each record represents a file, symlink, empty-directory, or deletion
 * operation which has already completed. The journal does not perform those
 * filesystem mutations. It owns the append-only record format and applies
 * the recorded mutations to both indexes.
 *
 * ## Record format
 *
 * Paths are base64-encoded because Unix path bytes are not necessarily valid
 * UTF-8. For a filesystem root of `/var/www`, a completed pull from
 * `/srv/site/file.txt` to `/var/www/file.txt` appends this JSON object as one
 * line (wrapped here for readability):
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
 * The remote fields describe the source state which files-pull accounted for.
 * For an upsert, lstat() supplies the local type, size, and ctime after the
 * local mutation completes. A skipped path, a path outside the filesystem
 * root, or a remote invalidation has no local fields. For example,
 * invalidating the same remote path appends:
 *
 *     {
 *         "op": "-",
 *         "remote_absolute_path_b64": "L3Nydi9zaXRlL2ZpbGUudHh0"
 *     }
 *
 * A completed selected deletion beneath the filesystem root uses the same `-`
 * operation and includes only `local_relative_path_b64`, so applying the
 * journal removes the local index entry without inspecting a path which is
 * already gone. Applying the upsert example produces these one-line index
 * entries:
 *
 *     remote index:
 *     {"path":"L3Nydi9zaXRlL2ZpbGUudHh0","ctime":10,"size":4,"type":"file"}
 *
 *     local index:
 *     {"path":"ZmlsZS50eHQ=","ctime":12,"size":4,"type":"file"}
 *
 * ## Applying records and interruption
 *
 * Call flush() immediately before saving a cursor which covers the appended
 * records. apply_pending_records() closes the writer, merges all complete
 * records into the remote index, projects records with local fields into the
 * local index, and truncates the WAL only after the remote index and any
 * changed local index have been replaced by rename().
 *
 * Applying a batch is not resumable within the method, but it is safe to
 * restart. If the process stops before WAL truncation, the next call recreates
 * the work files and replays the complete batch. Upserts replace the same
 * entry and deletions of absent entries write nothing, so replay is
 * idempotent. An unterminated final JSONL record is ignored; files-pull resumes
 * from the preceding durable cursor and repeats that mutation.
 *
 * ## Lifecycle marker
 *
 * The WAL also marks an unfinished files-pull lifecycle. open() creates or
 * retains it, successful application leaves an empty file in place, and
 * remove_empty_marker() removes that empty file only after files-pull
 * completes or aborts.
 */
class PullIndexJournal
{
    /** @var callable(string):void Writes one journal audit message. */
    private $log_audit_message;

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

    /**
     * Configures the journal, index paths, and local projection root.
     *
     * Construction does not create or open the WAL. A journal which never
     * begins a files-pull lifecycle therefore creates no files.
     *
     * @param callable(string):void $log_audit_message   Writes one audit log message.
     * @param string                $pull_index_wal_path Path to `pull/index.wal`.
     * @param string                $remote_index_path   Remote index path.
     * @param string                $local_index_path    Local index path.
     * @param string                $filesystem_root     Resolved local projection root.
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
     * Creates or retains the WAL and opens it for appending.
     *
     * Repeated calls retain the existing writer. Creating a new WAL also
     * creates the unfinished files-pull lifecycle marker and writes its audit
     * record.
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
     * Indicates whether the WAL is open for appending.
     *
     * @return bool True while this object retains the writer handle.
     */
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
     *
     * @param string      $remote_absolute_path Source absolute path.
     * @param int         $remote_path_ctime     Source change timestamp.
     * @param int         $remote_path_size      Source size in bytes.
     * @param string      $remote_path_type      `file`, `link`, or `dir`.
     * @param string|null $local_absolute_path   Resulting local absolute path,
     *                                           or null for no local projection.
     * @throws RuntimeException When the resulting local path cannot be
     *                          inspected or the record cannot be appended.
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
     * Records a remote deletion after its selected local path is gone.
     *
     * The remote path is always removed from the accounted remote index. A
     * local path beneath the filesystem root also removes its relative path
     * from the local index. Paths outside the root and default-skipped paths
     * have no local projection.
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
     * Invalidates remote state without changing the local index.
     *
     * The `-` record omits `local_relative_path_b64`, so applying the journal
     * removes only the remote index entry. Use this after files-pull
     * intentionally leaves no local path which it can account for.
     *
     * @param string $remote_absolute_path Source absolute path to invalidate.
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
     * Flushes appended records before the corresponding cursor is saved.
     *
     * A closed journal has nothing to flush. This ordering ensures a saved
     * cursor never claims a completed mutation whose record remains only in a
     * PHP stream buffer.
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
     * Applies all complete pending records to the accounted indexes.
     *
     * Applying a record here means folding its `+` or `-` projection into the
     * remote index and, when local fields are present, the local index. The
     * corresponding filesystem mutation has already completed. This method
     * closes the writer before reading and truncates the WAL only after the
     * remote replacement and any required local replacement succeed.
     *
     * Not resumable mid-way, but safe to restart: the remote index and any
     * changed local index are replaced by atomic rename(), and the journal is
     * truncated only after the final required rename. A crash anywhere before
     * that leaves the journal intact, so the next apply_pending_records()
     * reruns the whole merge. Re-applying an already-applied batch is
     * idempotent — an upsert rewrites an identical entry and a deletion of an
     * absent path writes nothing. Partial `.new`/`.local` work files are
     * recreated with mode "w" on rerun. An unterminated final record is
     * skipped; its work is repeated on resume because flush() runs before the
     * cursor checkpoint which would have covered it.
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
        ($this->log_audit_message)(
            "FILE TRUNCATE | {$this->pull_index_wal_path} | pull index WAL batch applied"
        );
    }

    /**
     * Removes the empty WAL which marks an unfinished files-pull lifecycle.
     *
     * The writer is closed first. A non-empty WAL is refused because removing
     * it would discard completed mutations which have not reached their
     * required indexes. A missing marker already represents the requested
     * result.
     *
     * @throws RuntimeException When pending records remain or the marker
     *                          cannot be closed or removed.
     */
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
     * The JSON object and terminating newline are written together. If a
     * process stops after only part of that string reaches the file, readers
     * treat the unterminated final bytes as incomplete and leave the cursor's
     * mutation to be repeated.
     *
     * @param array $pull_index_wal_record {
     *     One completed pull mutation, with local fields when files-pull
     *     changed a non-skipped path beneath the filesystem root.
     *
     *     @type string $op                       `+` upsert or `-` deletion.
     *     @type string $remote_absolute_path_b64 Base64 remote absolute path.
     *     @type int    $remote_path_ctime        Remote ctime. Present for `+`.
     *     @type int    $remote_path_size         Remote size. Present for `+`.
     *     @type string $remote_path_type         Remote type. Present for `+`.
     *     @type string $local_relative_path_b64  Base64 local relative path
     *                                             when the completed mutation
     *                                             belongs in the local index.
     *     @type int    $local_path_ctime         Local ctime. Present for a
     *                                             projected `+`.
     *     @type int    $local_path_size          Local size. Present for a
     *                                             projected `+`.
     *     @type string $local_path_type          Local type. Present for a
     *                                             projected `+`.
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
     * Returns the filesystem-root-relative path used by the local index.
     *
     * Paths outside the root, the root itself, and default-skipped paths have
     * no local projection and return null.
     *
     * @param string $local_absolute_path Local absolute path to project.
     * @return string|null Relative path, or null when it must not enter the
     *                     local index.
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
     * Reads one remote-index projection from the next complete WAL record.
     *
     * Blank lines are skipped. An unterminated final record returns null so
     * the mutation is repeated from the preceding durable cursor. Local
     * projection fields are intentionally ignored by this pass.
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
     *                          malformed.
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
     * Reads one remote index update, keeping the last consecutive update for
     * the same remote absolute path.
     *
     * The first update for another path becomes lookahead for the next call,
     * so the merge retains at most two decoded WAL entries in memory.
     *
     * @param resource|null $pull_index_wal_file_handle Open WAL reader.
     * @param array|null    $remote_index_update_lookahead Retained first
     *                                                       update for the
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
     * @throws RuntimeException When a complete WAL record is malformed.
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
