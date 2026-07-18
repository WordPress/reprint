<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.
/**
 * Core class used to plan local filesystem changes for one remote site.
 *
 * PushPlan merges a path-sorted fresh local index with the local index at the
 * previous push. It writes durable lists of local paths to push and local paths
 * to delete without reading the source tree or accumulating either index in
 * memory.
 *
 * ## Usage
 *
 * A push plan has four stages:
 *
 *  1. Start a new plan with `start()`, or continue an unfinished plan with
 *     `resume()`.
 *  2. Call `next_step()` until it reports `complete`.
 *  3. Call `close()`, then use the two path lists to perform the push.
 *  4. After the receiver commits successfully, call `after_successful_push()`.
 *
 * Example:
 *
 *     $plan = PushPlan::start($site_dir, $fresh_local_index_path);
 *     do {
 *         $result = $plan->next_step();
 *     } while ($result["status"] === "more");
 *     $plan->close();
 *
 *     // Push the selected paths and wait for the receiver to commit them.
 *     $plan->after_successful_push();
 *
 * A later process continues an unfinished plan with `resume()`. The retained
 * cursor preserves its progress and the excluded paths originally passed to
 * `start()`.
 *
 * ## Change detection
 *
 * ctime is machine-local, so PushPlan compares the local machine only with its
 * own state at the previous successful push. File and symlink changes are
 * determined by type, ctime, and size. Directory changes use the indexer's
 * empty-directory marker; non-empty directories are represented by their
 * descendants.
 *
 * With no local index from a previous push, every file, symlink, and empty
 * directory is selected, and no deletion can be detected. Excluded paths are
 * omitted from both path lists but remain in the fresh local index published
 * after success.
 *
 * The index reader trusts the entry fields produced by the indexer. It retains
 * failure handling for reading lines, decoding JSON, and decoding base64 paths.
 *
 * ## Durability and memory
 *
 * `start()` copies the fresh local index into plan-owned state. Each bounded
 * step flushes both path lists before atomically publishing the next cursor.
 * `resume()` discards bytes written beyond the saved output offsets and
 * continues from the saved index offsets, so an interrupted step cannot leave
 * duplicate durable records.
 *
 * PushPlan holds one entry from each index and the active deleted-directory
 * ranges needed to suppress redundant descendant deletions. It never loads an
 * index or path list in full.
 *
 * @phpstan-type PushPlanCursor array{byte_offset_in_fresh_index:int,byte_offset_in_previous_index:int,byte_offset_in_local_paths_to_push:int,byte_offset_in_local_paths_to_delete:int,local_paths_to_push_count:int,local_paths_to_delete_count:int,seen_deleted_directories:list<string>,excluded_paths_b64:list<string>}
 */
class PushPlan
{
    private const MAX_INDEX_ENTRIES_PER_STEP = 1000;

    /** @var string Paths and source metadata from the last completed push. */
    private string $local_index_at_previous_push;

    /** @var string JSONL file of local paths to push. */
    private string $local_paths_to_push;

    /** @var string Raw NUL-delimited local paths to delete. */
    private string $local_paths_to_delete;

    /** @var string Plan-owned copy of the fresh local index. */
    private string $fresh_local_index;

    /** @var string Path to the durable cursor for this site's push plan. */
    private string $cursor_file;

    /** @var list<string> Receiver-owned paths that the plan must not push or delete. */
    private array $excluded_paths = [];

    /** @var PushPlanCursor Last durable push plan boundary. */
    private array $cursor;

    /** @var bool Whether close() has closed this plan's file handles. */
    private bool $closed = false;

    /** @var resource|null */
    private $fresh_local_index_handle = null;
    /** @var resource|null */
    private $local_index_at_previous_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_delete_handle = null;

    /**
     * Starts a push plan from a fresh local index.
     *
     * Copies the fresh local index into plan-owned state and writes the
     * initial cursor before opening the plan files. An existing cursor is
     * rejected so an unfinished plan cannot be overwritten.
     *
     * @param string       $site_dir               Per-site directory for durable push-plan state.
     * @param string       $fresh_local_index_path Path to the path-sorted fresh local index.
     * @param list<string> $excluded_paths         Receiver-owned paths that the plan must not push or delete.
     * @return self Open plan positioned at the initial cursor.
     */
    public static function start(
        string $site_dir,
        string $fresh_local_index_path,
        array $excluded_paths = []
    ): self {
        $plan = new self($site_dir);
        if (is_file($plan->cursor_file)) {
            throw new LogicException("Cannot start a push plan while an unfinished plan exists: {$plan->cursor_file}");
        }
        if (!is_file($fresh_local_index_path)) {
            throw new RuntimeException("Cannot plan local files, the fresh local index file is missing: {$fresh_local_index_path}");
        }

        $plan->atomic_copy($fresh_local_index_path, $plan->fresh_local_index);
        $plan->excluded_paths = $excluded_paths;
        $plan->cursor = [
            "byte_offset_in_fresh_index" => 0,
            "byte_offset_in_previous_index" => 0,
            "byte_offset_in_local_paths_to_push" => 0,
            "byte_offset_in_local_paths_to_delete" => 0,
            "local_paths_to_push_count" => 0,
            "local_paths_to_delete_count" => 0,
            "seen_deleted_directories" => [],
            "excluded_paths_b64" => array_map("base64_encode", $excluded_paths),
        ];
        $plan->save_cursor($plan->cursor);
        $plan->open_plan_files();
        return $plan;
    }

    /**
     * Resumes the unfinished push plan retained in a site directory.
     *
     * Reuses the plan-owned fresh local index and the offsets, counts, and
     * deleted-directory ranges in the durable cursor. Excluded paths are the
     * ones originally passed to start().
     *
     * @param string $site_dir Per-site directory containing the unfinished plan.
     * @return self Open plan positioned at its last durable cursor.
     */
    public static function resume(string $site_dir): self
    {
        $plan = new self($site_dir);
        $cursor = $plan->load_cursor();
        if ($cursor === null) {
            throw new LogicException("Cannot resume a push plan without an unfinished plan: {$plan->cursor_file}");
        }
        if (!is_file($plan->fresh_local_index)) {
            throw new RuntimeException("Cannot resume the push plan, the retained fresh local index is missing: {$plan->fresh_local_index}");
        }

        $plan->cursor = $cursor;
        foreach ($cursor["excluded_paths_b64"] as $excluded_path_b64) {
            $excluded_path = base64_decode($excluded_path_b64, true);
            if ($excluded_path === false) {
                throw new RuntimeException("The push plan cursor contains an invalid excluded path: {$plan->cursor_file}");
            }
            $plan->excluded_paths[] = $excluded_path;
        }
        $plan->open_plan_files();
        return $plan;
    }

    /**
     * Initializes the paths for one site's durable push-plan files.
     *
     * Creates the per-site directory when it does not already exist. Plan
     * files are opened only after start() or resume() establishes a cursor.
     *
     * @param string $site_dir Per-site directory for durable push-plan state.
     */
    private function __construct(string $site_dir)
    {
        $site_dir = rtrim($site_dir, "/");
        if (!is_dir($site_dir) && !@mkdir($site_dir, 0755, true) && !is_dir($site_dir)) {
            throw new RuntimeException("Failed to create the push plan directory: {$site_dir}");
        }
        $this->local_index_at_previous_push = $site_dir . "/local_index_at_previous_push.jsonl";
        $this->local_paths_to_push = $site_dir . "/local_paths_to_push.jsonl";
        $this->local_paths_to_delete = $site_dir . "/local_paths_to_delete";
        $this->fresh_local_index = $site_dir . "/fresh_local_index.jsonl";
        $this->cursor_file = $site_dir . "/cursor.json";
    }

    /**
     * Opens and positions the files used by start() and resume().
     *
     * Indexes are positioned at their durable cursor offsets. Output bytes
     * beyond their durable offsets are discarded before writing continues.
     */
    private function open_plan_files(): void
    {
        $this->local_paths_to_push_handle = $this->open_and_truncate_and_seek(
            $this->local_paths_to_push,
            $this->cursor["byte_offset_in_local_paths_to_push"]
        );
        $this->local_paths_to_delete_handle = $this->open_and_truncate_and_seek(
            $this->local_paths_to_delete,
            $this->cursor["byte_offset_in_local_paths_to_delete"]
        );
        $this->fresh_local_index_handle = fopen($this->fresh_local_index, "rb");
        if (!is_resource($this->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to open the retained fresh local index: {$this->fresh_local_index}");
        }

        if (is_file($this->local_index_at_previous_push)) {
            $this->local_index_at_previous_push_handle = fopen($this->local_index_at_previous_push, "rb");
            if (!is_resource($this->local_index_at_previous_push_handle)) {
                throw new RuntimeException("Failed to open local index at the previous push: {$this->local_index_at_previous_push}");
            }
        }
        $this->safe_seek(
            $this->fresh_local_index_handle,
            $this->cursor["byte_offset_in_fresh_index"],
            "fresh local index"
        );
        if ($this->local_index_at_previous_push_handle) {
            $this->safe_seek(
                $this->local_index_at_previous_push_handle,
                $this->cursor["byte_offset_in_previous_index"],
                "local index at the previous push"
            );
        }
    }

    /**
     * Publishes the fresh local index after a successful push.
     *
     * Only a closed plan whose cursor consumed both indexes can publish its
     * fresh local index. From then on, "changed locally" means "different from
     * the local index at the previous push".
     *
     * The copy is atomic (temporary file plus rename), and the fresh local
     * index is left untouched. A killed process therefore leaves the previous
     * complete file in effect rather than publishing a truncated replacement.
     */
    public function after_successful_push(): void
    {
        if (!$this->closed) {
            throw new LogicException("Close the push plan before recording a successful push.");
        }
        if (!is_file($this->fresh_local_index)) {
            throw new RuntimeException("Cannot record a successful push, the fresh local index is missing: {$this->fresh_local_index}");
        }

        $fresh_local_index_bytes = filesize($this->fresh_local_index);
        if (!is_int($fresh_local_index_bytes)) {
            throw new RuntimeException("Failed to determine the fresh local index length: {$this->fresh_local_index}");
        }
        $previous_index_bytes = 0;
        if (is_file($this->local_index_at_previous_push)) {
            $previous_index_bytes = filesize($this->local_index_at_previous_push);
            if (!is_int($previous_index_bytes)) {
                throw new RuntimeException("Failed to determine the previous local index length: {$this->local_index_at_previous_push}");
            }
        }
        if (
            $this->cursor["byte_offset_in_fresh_index"] !== $fresh_local_index_bytes
            || $this->cursor["byte_offset_in_previous_index"] !== $previous_index_bytes
        ) {
            throw new LogicException(
                "Cannot record a successful push before the plan is complete: "
                . "the fresh local index is at {$this->cursor["byte_offset_in_fresh_index"]} of {$fresh_local_index_bytes} bytes "
                . "and the previous local index is at {$this->cursor["byte_offset_in_previous_index"]} of {$previous_index_bytes} bytes."
            );
        }

        $this->atomic_copy($this->fresh_local_index, $this->local_index_at_previous_push);
        $this->remove_cursor();
    }

    /**
     * Performs one bounded push plan step.
     *
     * start() establishes a new plan and resume() opens an unfinished one.
     * `complete` means both indexes reached EOF. The caller closes the plan
     * before using its output files.
     *
     * Exclusions suppress network changes, not entries in the retained fresh
     * local index saved as the local index at the previous push after success.
     *
     * @return array {
     *     Result of this plan step.
     *
     *     @type string $status                      `more` or `complete`.
     *     @type int    $local_paths_to_push_count   Number of local paths to push written so far.
     *     @type int    $local_paths_to_delete_count Number of local paths to delete written so far.
     * }
     * @phpstan-return array{status:'more'|'complete',local_paths_to_push_count:int,local_paths_to_delete_count:int}
     */
    public function next_step(): array
    {
        if ($this->closed) {
            throw new LogicException("Cannot advance a push plan after close().");
        }

        $byte_offset_in_fresh_index = $this->cursor["byte_offset_in_fresh_index"];
        $byte_offset_in_previous_index = $this->cursor["byte_offset_in_previous_index"];
        $local_paths_to_push_count = $this->cursor["local_paths_to_push_count"];
        $local_paths_to_delete_count = $this->cursor["local_paths_to_delete_count"];
        // This stack can grow with overlapping deleted-directory prefix
        // ranges. We accept that memory and cursor growth to avoid emitting
        // redundant descendant deletions.
        $seen_deleted_directories = $this->cursor["seen_deleted_directories"];

        $entry_fresh_index = $this->parse_next_index_entry($this->fresh_local_index_handle);
        $entry_previous_index = $this->parse_next_index_entry(
            $this->local_index_at_previous_push_handle
        );

        $index_entries_processed = 0;
        while ($entry_fresh_index !== null || $entry_previous_index !== null) {
            // Base64 does not preserve byte order ('0' sorts before 'A'
            // in ASCII but encodes a higher value), so ordering uses the
            // decoded path bytes.
            if ($entry_previous_index === null) {
                $path_comparison = -1;
            } elseif ($entry_fresh_index === null) {
                $path_comparison = 1;
            } else {
                $path_comparison = strcmp($entry_fresh_index["path"], $entry_previous_index["path"]);
            }

            $index_entries_for_path = $path_comparison === 0 ? 2 : 1;
            if ($index_entries_processed + $index_entries_for_path > self::MAX_INDEX_ENTRIES_PER_STEP) {
                break;
            }

            $current_shape = null;
            if ($path_comparison <= 0) {
                $current_shape = $this->entry_shape($entry_fresh_index);
            }

            $local_index_at_previous_push_shape = null;
            if ($path_comparison >= 0) {
                $local_index_at_previous_push_shape = $this->entry_shape($entry_previous_index);

                // Byte sorting can put a sibling such as `a-other` before
                // `a/child`. Keep every deleted directory that could still
                // contain a later path, and discard the ranges already passed.
                while ($seen_deleted_directories !== []) {
                    $deleted_directory = $seen_deleted_directories[
                        count($seen_deleted_directories) - 1
                    ];
                    $descendant_prefix = $deleted_directory . "/";
                    if (
                        strpos($entry_previous_index["path"], $descendant_prefix) === 0
                        || strcmp($entry_previous_index["path"], $descendant_prefix) <= 0
                    ) {
                        break;
                    }
                    array_pop($seen_deleted_directories);
                }
            }

            if ($path_comparison < 0) {
                // New files, symlinks, and empty directories need to be
                // pushed. A new non-empty directory is represented by its
                // descendants.
                if (
                    $current_shape !== "non_empty_directory"
                    && !$this->path_conflicts_with_excluded_paths($entry_fresh_index["path"])
                ) {
                    $this->append_local_path_to_push($entry_fresh_index["path"]);
                    ++$local_paths_to_push_count;
                }
            } elseif ($path_comparison > 0) {
                // A deleted non-empty directory emits one root. Its later
                // descendant entries are already covered by that record.
                if (
                    !$this->path_conflicts_with_excluded_paths($entry_previous_index["path"])
                    && !$this->is_covered_by_seen_deleted_directory(
                        $entry_previous_index["path"],
                        $seen_deleted_directories
                    )
                ) {
                    $this->append_local_path_to_delete($entry_previous_index["path"]);
                    ++$local_paths_to_delete_count;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $seen_deleted_directories[] = $entry_previous_index["path"];
                    }
                }
            } else {
                $current_is_file_or_symlink = $current_shape === "file" || $current_shape === "symlink";
                $local_index_at_previous_push_is_file_or_symlink = $local_index_at_previous_push_shape === "file" || $local_index_at_previous_push_shape === "symlink";
                $non_empty_directory_becomes_empty = $current_shape === "empty_directory"
                    && $local_index_at_previous_push_shape === "non_empty_directory";
                $empty_directory_needs_push = $current_shape === "empty_directory"
                    && $local_index_at_previous_push_shape !== "empty_directory";
                // File and symlink changes are defined by type, ctime, and
                // size. Other index fields do not select a path for upload.
                $changed_file_or_symlink_needs_push = $current_is_file_or_symlink
                    && (
                        $entry_fresh_index["ctime"] !== $entry_previous_index["ctime"]
                        || $entry_fresh_index["size"] !== $entry_previous_index["size"]
                        || $entry_fresh_index["type"] !== $entry_previous_index["type"]
                    );
                $needs_delete = $current_is_file_or_symlink !== $local_index_at_previous_push_is_file_or_symlink
                    || $non_empty_directory_becomes_empty;
                $needs_push = $empty_directory_needs_push
                    || $changed_file_or_symlink_needs_push;
                $path_is_excluded = $this->path_conflicts_with_excluded_paths($entry_fresh_index["path"]);

                if (
                    $needs_delete
                    && !$path_is_excluded
                    && !$this->is_covered_by_seen_deleted_directory(
                        $entry_previous_index["path"],
                        $seen_deleted_directories
                    )
                ) {
                    $this->append_local_path_to_delete($entry_previous_index["path"]);
                    ++$local_paths_to_delete_count;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $seen_deleted_directories[] = $entry_previous_index["path"];
                    }
                }
                if ($needs_push && !$path_is_excluded) {
                    $this->append_local_path_to_push($entry_fresh_index["path"]);
                    ++$local_paths_to_push_count;
                }
            }

            if ($path_comparison <= 0) {
                $byte_offset_in_fresh_index = ftell($this->fresh_local_index_handle);
                $entry_fresh_index = $this->parse_next_index_entry($this->fresh_local_index_handle);
            }
            if ($path_comparison >= 0) {
                $byte_offset_in_previous_index = ftell($this->local_index_at_previous_push_handle);
                $entry_previous_index = $this->parse_next_index_entry(
                    $this->local_index_at_previous_push_handle
                );
            }
            $index_entries_processed += $index_entries_for_path;
        }

        if (
            !fflush($this->local_paths_to_push_handle)
            || !fflush($this->local_paths_to_delete_handle)
        ) {
            throw new RuntimeException("Failed to flush local_paths_to_push or local_paths_to_delete.");
        }

        $complete = $entry_fresh_index === null && $entry_previous_index === null;
        if ($complete) {
            $seen_deleted_directories = [];
        }
        $cursor_after_step = [
            "byte_offset_in_fresh_index" => $byte_offset_in_fresh_index,
            "byte_offset_in_previous_index" => $byte_offset_in_previous_index,
            "byte_offset_in_local_paths_to_push" => ftell($this->local_paths_to_push_handle),
            "byte_offset_in_local_paths_to_delete" => ftell($this->local_paths_to_delete_handle),
            "local_paths_to_push_count" => $local_paths_to_push_count,
            "local_paths_to_delete_count" => $local_paths_to_delete_count,
            "seen_deleted_directories" => $seen_deleted_directories,
            "excluded_paths_b64" => $this->cursor["excluded_paths_b64"],
        ];
        $this->save_cursor($cursor_after_step);
        $this->cursor = $cursor_after_step;
        if (!$complete) {
            // The merge reads one entry ahead. Return both handles to the
            // durable offsets so the next step reads that entry again.
            $this->safe_seek(
                $this->fresh_local_index_handle,
                $this->cursor["byte_offset_in_fresh_index"],
                "fresh local index"
            );
            if ($this->local_index_at_previous_push_handle) {
                $this->safe_seek(
                    $this->local_index_at_previous_push_handle,
                    $this->cursor["byte_offset_in_previous_index"],
                    "local index at the previous push"
                );
            }
        }

        return [
            "status" => $complete ? "complete" : "more",
            "local_paths_to_push_count" => $this->cursor["local_paths_to_push_count"],
            "local_paths_to_delete_count" => $this->cursor["local_paths_to_delete_count"],
        ];
    }

    /**
     * Closes every plan file handle and prevents further plan steps.
     *
     * The durable cursor and plan-owned files remain available to resume the
     * plan or to publish the completed fresh local index after a successful
     * push.
     */
    public function close(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        if (is_resource($this->local_index_at_previous_push_handle)) {
            fclose($this->local_index_at_previous_push_handle);
        }
        if (is_resource($this->local_paths_to_push_handle)) {
            fclose($this->local_paths_to_push_handle);
        }
        if (is_resource($this->local_paths_to_delete_handle)) {
            fclose($this->local_paths_to_delete_handle);
        }
        $this->fresh_local_index_handle = null;
        $this->local_index_at_previous_push_handle = null;
        $this->local_paths_to_push_handle = null;
        $this->local_paths_to_delete_handle = null;
        $this->closed = true;
    }

    /**
     * Opens one output at its durable cursor offset and discards later bytes.
     *
     * A process may stop after writing output but before publishing its next
     * cursor. Truncating to the saved offset removes only that uncommitted
     * tail before the plan continues.
     *
     * @param string $path        Path to the push-plan output file.
     * @param int    $byte_offset Durable byte offset at which writing resumes.
     * @return resource Writable output handle positioned at the durable offset.
     */
    private function open_and_truncate_and_seek(string $path, int $byte_offset)
    {
        $handle = fopen($path, "c+b");
        if (!$handle) {
            throw new RuntimeException("Failed to open push plan output for writing: {$path}");
        }
        $identity = fstat($handle);
        $actual_bytes = is_array($identity) ? (int) $identity["size"] : -1;
        if ($actual_bytes < $byte_offset) {
            fclose($handle);
            throw new RuntimeException(
                "Push plan output {$path} contains {$actual_bytes} bytes, shorter than its cursor byte offset {$byte_offset}."
            );
        }
        if (!ftruncate($handle, $byte_offset) || fseek($handle, $byte_offset) !== 0) {
            fclose($handle);
            throw new RuntimeException("Failed to truncate and seek push plan output {$path} to byte {$byte_offset}.");
        }
        return $handle;
    }

    /**
     * Positions an index handle at its durable cursor offset.
     *
     * Rejects an offset beyond the current file length instead of silently
     * positioning the handle at an invalid plan boundary.
     *
     * @param resource $handle      Open index handle to position.
     * @param int      $byte_offset Durable byte offset recorded in the cursor.
     * @param string   $description Human-readable index name used in failures.
     */
    private function safe_seek($handle, int $byte_offset, string $description): void
    {
        $identity = fstat($handle);
        $file_bytes = is_array($identity) ? (int) $identity["size"] : -1;
        if ($byte_offset > $file_bytes) {
            throw new RuntimeException(
                "The {$description} cursor offset {$byte_offset} exceeds its {$file_bytes}-byte file."
            );
        }
        if (fseek($handle, $byte_offset) !== 0) {
            throw new RuntimeException("Failed to seek the {$description} to byte {$byte_offset}.");
        }
    }

    /**
     * Returns the logical entry kind used by the transition table.
     *
     * @param array $entry {
     *     Parsed local index entry.
     *
     *     @type string $path  Decoded filesystem path.
     *     @type string $type  Entry type: `file`, `link`, or `dir`.
     *     @type int    $ctime Indexed change timestamp.
     *     @type int    $size  Indexed size used for change detection.
     *     @type bool   $empty Whether a directory is empty. Present for directory entries.
     * }
     * @phpstan-param array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool} $entry
     * @return 'file'|'symlink'|'empty_directory'|'non_empty_directory'
     */
    private function entry_shape(array $entry): string
    {
        if ($entry["type"] === "file") {
            return "file";
        }
        if ($entry["type"] === "link") {
            return "symlink";
        }
        return $entry["empty"] ? "empty_directory" : "non_empty_directory";
    }

    /**
     * Appends one path to the JSONL list of local paths to push.
     *
     * Base64 keeps arbitrary filesystem path bytes representable in JSON.
     *
     * @param string $path Raw filesystem path selected for push.
     */
    private function append_local_path_to_push(string $path): void
    {
        $line = json_encode(
            ["path" => base64_encode($path)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (fwrite($this->local_paths_to_push_handle, $line) !== strlen($line)) {
            throw new RuntimeException("Short write on local push path list {$this->local_paths_to_push}, is the disk full?");
        }
    }

    /**
     * Appends one path to the NUL-delimited list of local paths to delete.
     *
     * @param string $path Raw filesystem path selected for deletion.
     */
    private function append_local_path_to_delete(string $path): void
    {
        $record = $path . "\0";
        if (fwrite($this->local_paths_to_delete_handle, $record) !== strlen($record)) {
            throw new RuntimeException("Short write on local paths to delete {$this->local_paths_to_delete}, is the disk full?");
        }
    }

    /**
     * Indicates whether an active deleted directory contains the path.
     *
     * next_step() discards passed directory ranges before calling this method,
     * so only the last active directory can contain the current path.
     *
     * @param string   $path                     Raw filesystem path to classify.
     * @param string[] $seen_deleted_directories Deleted directories whose ranges remain active.
     * @return bool Whether a previously selected directory deletion covers the path.
     */
    private function is_covered_by_seen_deleted_directory(
        string $path,
        array $seen_deleted_directories
    ): bool {
        if ($seen_deleted_directories === []) {
            return false;
        }
        $deleted_directory = $seen_deleted_directories[count($seen_deleted_directories) - 1];
        return strpos($path, $deleted_directory . "/") === 0;
    }

    /**
     * Indicates whether pushing or deleting the path could change an excluded
     * path.
     *
     * The path conflicts when it is excluded, is inside an excluded directory,
     * or contains an excluded descendant. The last case prevents deleting or
     * replacing a directory from removing an excluded descendant with it.
     *
     * @param string $path Raw filesystem path considered for push or deletion.
     * @return bool Whether operating on the path could change an excluded path.
     */
    private function path_conflicts_with_excluded_paths(string $path): bool
    {
        foreach ($this->excluded_paths as $excluded_path) {
            if (
                $path === $excluded_path
                || strpos($path, $excluded_path . "/") === 0
                || strpos($excluded_path, $path . "/") === 0
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads and decodes the next local index entry.
     *
     * A null handle represents the missing local index at the previous push.
     * The indexer's entry schema is trusted; only file reads, JSON decoding,
     * and base64 path decoding are handled here as fallible operations.
     *
     * @param resource|null $handle Open local index handle, or null when no previous index exists.
     * @return array|null {
     *     Decoded index entry, or null at EOF or when the handle is null.
     *
     *     @type string $path  Decoded filesystem path.
     *     @type string $type  Entry type: `file`, `link`, or `dir`.
     *     @type int    $ctime Indexed change timestamp.
     *     @type int    $size  Indexed size used for change detection.
     *     @type bool   $empty Whether a directory is empty. Present for directory entries.
     * }
     * @phpstan-return array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}|null
     */
    private function parse_next_index_entry($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        $raw_line = fgets($handle);
        if ($raw_line === false) {
            if (!feof($handle)) {
                throw new RuntimeException("Failed to read a local push index line.");
            }
            return null;
        }

        try {
            $entry = json_decode($raw_line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Unexpected index line, it is not valid JSON: " . substr($raw_line, 0, 120),
                0,
                $exception
            );
        }
        /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool} $entry */
        $path = base64_decode($entry["path"], true);
        if ($path === false) {
            throw new RuntimeException("The index path is not valid base64: " . substr($raw_line, 0, 120));
        }
        $entry["path"] = $path;
        return $entry;
    }

    /**
     * Loads the durable cursor for an unfinished push plan.
     *
     * Deleted-directory paths are decoded from base64 so path comparisons use
     * their original bytes. Excluded paths remain encoded until resume().
     *
     * @return array|null {
     *     Durable cursor, or null when no unfinished plan exists.
     *
     *     @type int      $byte_offset_in_fresh_index                Consumed bytes in the fresh local index.
     *     @type int      $byte_offset_in_previous_index             Consumed bytes in the local index at the previous push.
     *     @type int      $byte_offset_in_local_paths_to_push        Durable bytes in the local paths to push output.
     *     @type int      $byte_offset_in_local_paths_to_delete      Durable bytes in the local paths to delete output.
     *     @type int      $local_paths_to_push_count                 Number of local paths to push written so far.
     *     @type int      $local_paths_to_delete_count               Number of local paths to delete written so far.
     *     @type string[] $seen_deleted_directories                  Decoded deleted-directory paths with active ranges.
     *     @type string[] $excluded_paths_b64                        Base64-encoded receiver-owned paths.
     * }
     * @phpstan-return PushPlanCursor|null
     */
    private function load_cursor(): ?array
    {
        if (!is_file($this->cursor_file)) {
            return null;
        }
        $contents = file_get_contents($this->cursor_file);
        if (!is_string($contents)) {
            throw new RuntimeException("Failed to read the cursor: {$this->cursor_file}");
        }
        try {
            $cursor = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                "The cursor is not valid JSON: {$this->cursor_file}",
                0,
                $error
            );
        }
        if (!is_array($cursor)) {
            throw new RuntimeException("The cursor must be a JSON object: {$this->cursor_file}");
        }
        $decoded_directories = [];
        foreach ($cursor["seen_deleted_directories"] as $encoded_directory) {
            /** @var string $directory */
            $directory = base64_decode($encoded_directory, true);
            $decoded_directories[] = $directory;
        }
        $cursor["seen_deleted_directories"] = $decoded_directories;
        /** @var PushPlanCursor $cursor */
        return $cursor;
    }

    /**
     * Persists the next durable push-plan boundary atomically.
     *
     * Deleted-directory paths are base64-encoded because JSON cannot represent
     * arbitrary filesystem path bytes. A temporary file and rename prevent
     * readers from observing a partial cursor.
     *
     * @param array $cursor {
     *     Cursor to publish as the durable plan boundary.
     *
     *     @type int      $byte_offset_in_fresh_index                Consumed bytes in the fresh local index.
     *     @type int      $byte_offset_in_previous_index             Consumed bytes in the local index at the previous push.
     *     @type int      $byte_offset_in_local_paths_to_push        Durable bytes in the local paths to push output.
     *     @type int      $byte_offset_in_local_paths_to_delete      Durable bytes in the local paths to delete output.
     *     @type int      $local_paths_to_push_count                 Number of local paths to push written so far.
     *     @type int      $local_paths_to_delete_count               Number of local paths to delete written so far.
     *     @type string[] $seen_deleted_directories                  Decoded deleted-directory paths with active ranges.
     *     @type string[] $excluded_paths_b64                        Base64-encoded receiver-owned paths.
     * }
     * @phpstan-param PushPlanCursor $cursor
     */
    private function save_cursor(array $cursor): void
    {
        $cursor_for_storage = $cursor;
        $cursor_for_storage["seen_deleted_directories"] = array_map(
            "base64_encode",
            $cursor["seen_deleted_directories"]
        );
        $contents = json_encode($cursor_for_storage, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary_cursor = $this->cursor_file . ".tmp";
        if (file_put_contents($temporary_cursor, $contents) !== strlen($contents)) {
            throw new RuntimeException("Failed to write the cursor: {$temporary_cursor}");
        }
        if (!rename($temporary_cursor, $this->cursor_file)) {
            throw new RuntimeException("Failed to move the cursor into place: {$this->cursor_file}");
        }
    }

    /**
     * Removes the cursor after the completed fresh local index is published.
     *
     * With no cursor, the site directory no longer contains an unfinished
     * push plan and start() may create the next one.
     */
    private function remove_cursor(): void
    {
        if (is_file($this->cursor_file) && !unlink($this->cursor_file)) {
            throw new RuntimeException("Failed to remove the cursor: {$this->cursor_file}");
        }
    }

    /**
     * Replaces a target file atomically with a complete copy of the source.
     *
     * The copy is written beside the target and renamed into place so readers
     * observe either the previous complete file or the new complete file.
     *
     * @param string $source Existing file to copy.
     * @param string $target File path to replace atomically.
     */
    private function atomic_copy(string $source, string $target): void
    {
        if (!is_file($source)) {
            throw new RuntimeException("Cannot copy to {$target}, the source file is missing: {$source}");
        }
        $tmp = $target . ".tmp";
        if (!copy($source, $tmp)) {
            throw new RuntimeException("Failed to copy {$source} to the temporary file {$tmp}.");
        }
        if (!rename($tmp, $target)) {
            throw new RuntimeException("Failed to move the temporary file into place: {$target}");
        }
    }
}
