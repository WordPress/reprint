<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.
/**
 * Per-remote-site memory of the last completed push and local push planning.
 *
 * ctime is machine-local, so push never compares a local timestamp against
 * a remote one. Each machine is compared against its own past instead
 * (markdown/PUSH-SYNC.md, "Change detection"). This class stores that past
 * on the local machine, one directory per remote site:
 *
 *     <state-dir>/push/<site>/local_index_at_previous_push.jsonl
 *
 * The local index at the previous push is copied from the fresh local index only
 * after the receiver commits successfully. The source index records an `empty`
 * boolean on every directory entry, so both indexes distinguish an empty
 * directory from a non-empty one and planning never reads the source tree.
 *
 * next_step() performs one bounded planning step. It merges the
 * path-sorted fresh local index and the local index at the previous push while
 * writing two durable files:
 *
 *     local_paths_to_push.jsonl   files, symlinks, and empty directories to
 *                                 inspect and upload
 *     local_paths_to_delete       raw NUL-delimited paths to delete
 *
 * start() copies the caller's fresh local index into its site directory.
 * resume() keeps using that private copy. Both operations position the indexes
 * and outputs at the last durable cursor. Each step flushes both outputs before
 * advancing that cursor.
 * On the next call, even from a new instance, bytes beyond the committed output
 * lengths are truncated before planning resumes at the two saved index offsets.
 * A process that dies before the cursor changes therefore replays only
 * uncommitted output, without duplicate records.
 *
 * With no local index from a previous push, every current file, symlink, and
 * empty directory is selected and no deletion can be detected. The
 * local_paths_to_push file carries only paths because the sender rechecks the
 * filesystem before upload.
 * Planning holds one line from each index plus the seen-deleted-directory
 * stack documented in next_step(); indexes and plans are never accumulated.
 *
 * @phpstan-type PlanningCursor array{byte_offset_in_fresh_index:int,byte_offset_in_previous_index:int,local_paths_to_push_bytes:int,local_paths_to_delete_bytes:int,progress_changed:int,progress_deleted:int,seen_deleted_directories:list<string>,excluded_paths_b64:list<string>}
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

    /** @var PlanningCursor Last durable planning boundary. */
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
     * Start a plan from a fresh local index.
     *
     * @param list<string> $excluded_paths Receiver-owned paths that the plan must not push or delete.
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
            "local_paths_to_push_bytes" => 0,
            "local_paths_to_delete_bytes" => 0,
            "progress_changed" => 0,
            "progress_deleted" => 0,
            "seen_deleted_directories" => [],
            "excluded_paths_b64" => array_map("base64_encode", $excluded_paths),
        ];
        $plan->save_cursor($plan->cursor);
        $plan->open_plan_files();
        return $plan;
    }

    /** Resume the unfinished plan retained in a site directory. */
    public static function resume(string $site_dir): self
    {
        $plan = new self($site_dir);
        $cursor = $plan->load_cursor();
        if ($cursor === null) {
            throw new LogicException("Cannot resume a push plan without an unfinished plan: {$plan->cursor_file}");
        }
        if (!is_file($plan->fresh_local_index)) {
            throw new RuntimeException("Cannot resume local planning, the retained fresh local index is missing: {$plan->fresh_local_index}");
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

    /** Open and position the files used by start() and resume(). */
    private function open_plan_files(): void
    {
        $this->local_paths_to_push_handle = $this->open_and_truncate_and_seek(
            $this->local_paths_to_push,
            $this->cursor["local_paths_to_push_bytes"]
        );
        $this->local_paths_to_delete_handle = $this->open_and_truncate_and_seek(
            $this->local_paths_to_delete,
            $this->cursor["local_paths_to_delete_bytes"]
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
     * Store the fresh local index after a successful push.
     *
     * The push driver calls this at the end of a successful push; from then
     * on "changed locally" means "different from the local index at the
     * previous push".
     * The copy is atomic (temp file + rename) and the fresh local index is left
     * untouched. A killed process therefore leaves the previous complete file
     * in effect rather than publishing a truncated replacement.
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
     * Perform one bounded local planning step.
     *
     * start() establishes a new plan and resume() opens an unfinished one.
     * `complete` means both indexes reached EOF. The caller closes the plan
     * before using its output files.
     *
     * Exclusions suppress network changes, not entries in the retained fresh
     * local index saved as the local index at the previous push after success.
     *
     * @return array {
     *     Result of this planning step.
     *
     *     @type string $status                      `planning` or `complete`.
     *     @type int    $local_paths_to_push_count   Number of push paths written so far.
     *     @type int    $local_paths_to_delete_count Number of delete paths written so far.
     * }
     * @phpstan-return array{status:'planning'|'complete',local_paths_to_push_count:int,local_paths_to_delete_count:int}
     */
    public function next_step(): array
    {
        if ($this->closed) {
            throw new LogicException("Cannot advance a push plan after close().");
        }

        $byte_offset_in_fresh_index = $this->cursor["byte_offset_in_fresh_index"];
        $byte_offset_in_previous_index = $this->cursor["byte_offset_in_previous_index"];
        $progress_changed = $this->cursor["progress_changed"];
        $progress_deleted = $this->cursor["progress_deleted"];
        // This stack can grow with overlapping deleted-directory prefix
        // ranges. We accept that memory and cursor growth to avoid emitting
        // redundant descendant deletions.
        $seen_deleted_directories = $this->cursor["seen_deleted_directories"];

        $entry_fresh_index = $this->parse_next_index_entry($this->fresh_local_index_handle);
        $entry_previous_index = $this->parse_next_index_entry(
            $this->local_index_at_previous_push_handle
        );

        $records_processed = 0;
        while ($entry_fresh_index !== null || $entry_previous_index !== null) {
            // Base64 does not preserve byte order ('0' sorts before 'A'
            // in ASCII but encodes a higher value), so ordering uses the
            // decoded path bytes.
            if ($entry_previous_index === null) {
                $order = -1;
            } elseif ($entry_fresh_index === null) {
                $order = 1;
            } else {
                $order = strcmp($entry_fresh_index["path"], $entry_previous_index["path"]);
            }

            $records_for_path = $order === 0 ? 2 : 1;
            if ($records_processed + $records_for_path > self::MAX_INDEX_ENTRIES_PER_STEP) {
                break;
            }

            $current_shape = null;
            if ($order <= 0) {
                $current_shape = $this->entry_shape($entry_fresh_index, "fresh local index");
            }

            $local_index_at_previous_push_shape = null;
            if ($order >= 0) {
                $local_index_at_previous_push_shape = $this->entry_shape($entry_previous_index, "local index at the previous push");
            }

            if ($order < 0) {
                // New files, symlinks, and empty directories need to be
                // pushed. A new non-empty directory is represented by its
                // descendants.
                if (
                    $current_shape !== "non_empty_directory"
                    && !$this->path_conflicts_with_excluded_paths($entry_fresh_index["path"])
                ) {
                    $this->write_local_path_to_push($entry_fresh_index["path"]);
                    ++$progress_changed;
                }
            } elseif ($order > 0) {
                // A deleted non-empty directory emits one root. Its later
                // descendant entries are already covered by that record.
                if (
                    !$this->path_conflicts_with_excluded_paths($entry_previous_index["path"])
                    && !$this->is_covered_by_seen_deleted_directory(
                        $entry_previous_index["path"],
                        $seen_deleted_directories
                    )
                ) {
                    $this->write_local_path_to_delete($entry_previous_index["path"]);
                    ++$progress_deleted;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $this->remember_deleted_directory(
                            $entry_previous_index["path"],
                            $seen_deleted_directories
                        );
                    }
                }
            } else {
                $current_is_file_or_symlink = $current_shape === "file" || $current_shape === "symlink";
                $local_index_at_previous_push_is_file_or_symlink = $local_index_at_previous_push_shape === "file" || $local_index_at_previous_push_shape === "symlink";
                $non_empty_directory_becomes_empty = $current_shape === "empty_directory"
                    && $local_index_at_previous_push_shape === "non_empty_directory";
                $empty_directory_needs_push = $current_shape === "empty_directory"
                    && $local_index_at_previous_push_shape !== "empty_directory";
                $changed_file_or_symlink_needs_push = $current_is_file_or_symlink
                    && $entry_fresh_index != $entry_previous_index;
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
                    $this->write_local_path_to_delete($entry_previous_index["path"]);
                    ++$progress_deleted;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $this->remember_deleted_directory(
                            $entry_previous_index["path"],
                            $seen_deleted_directories
                        );
                    }
                }
                if ($needs_push && !$path_is_excluded) {
                    // Comparing decoded JSON objects keeps field order and
                    // slash escaping out of file and symlink detection. A
                    // writer field change may select every value once: a
                    // wasted upload, but never a missed local change.
                    $this->write_local_path_to_push($entry_fresh_index["path"]);
                    ++$progress_changed;
                }
            }

            if ($order <= 0) {
                $byte_offset_in_fresh_index = $this->handle_byte_offset(
                    $this->fresh_local_index_handle,
                    $this->fresh_local_index
                );
                $entry_fresh_index = $this->parse_next_index_entry($this->fresh_local_index_handle);
            }
            if ($order >= 0) {
                $byte_offset_in_previous_index = $this->handle_byte_offset(
                    $this->local_index_at_previous_push_handle,
                    $this->local_index_at_previous_push
                );
                $entry_previous_index = $this->parse_next_index_entry(
                    $this->local_index_at_previous_push_handle
                );
            }
            $records_processed += $records_for_path;
        }

        if (
            !fflush($this->local_paths_to_push_handle)
            || !fflush($this->local_paths_to_delete_handle)
        ) {
            throw new RuntimeException("Failed to flush local push planning output.");
        }

        $complete = $entry_fresh_index === null && $entry_previous_index === null;
        if ($complete) {
            $seen_deleted_directories = [];
        }
        $cursor_after_step = [
            "byte_offset_in_fresh_index" => $byte_offset_in_fresh_index,
            "byte_offset_in_previous_index" => $byte_offset_in_previous_index,
            "local_paths_to_push_bytes" => $this->handle_byte_offset(
                $this->local_paths_to_push_handle,
                $this->local_paths_to_push
            ),
            "local_paths_to_delete_bytes" => $this->handle_byte_offset($this->local_paths_to_delete_handle, $this->local_paths_to_delete),
            "progress_changed" => $progress_changed,
            "progress_deleted" => $progress_deleted,
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
            "status" => $complete ? "complete" : "planning",
            "local_paths_to_push_count" => $this->cursor["progress_changed"],
            "local_paths_to_delete_count" => $this->cursor["progress_deleted"],
        ];
    }

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
     * Open one output at its last committed byte and discard a later tail.
     *
     * @return resource
     */
    private function open_and_truncate_and_seek(string $path, int $committed_bytes)
    {
        $handle = fopen($path, "c+b");
        if (!$handle) {
            throw new RuntimeException("Failed to open planning output for writing: {$path}");
        }
        $identity = fstat($handle);
        $actual_bytes = is_array($identity) ? (int) $identity["size"] : -1;
        if ($actual_bytes < $committed_bytes) {
            fclose($handle);
            throw new RuntimeException(
                "Planning output {$path} contains {$actual_bytes} bytes, shorter than the cursor-recorded {$committed_bytes} bytes."
            );
        }
        if (!ftruncate($handle, $committed_bytes) || fseek($handle, $committed_bytes) !== 0) {
            fclose($handle);
            throw new RuntimeException("Failed to truncate and seek planning output {$path} to {$committed_bytes} bytes.");
        }
        return $handle;
    }

    /** @param resource $handle */
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
     * Return the plain logical kind needed by the transition table.
     *
     * @param array<string, mixed> $entry Parsed index entry.
     * @return 'file'|'symlink'|'empty_directory'|'non_empty_directory'
     */
    private function entry_shape(array $entry, string $index_description): string
    {
        $type = $entry["type"] ?? null;
        if ($type === "file") {
            return "file";
        }
        if ($type === "link") {
            return "symlink";
        }
        if ($type !== "dir") {
            throw new RuntimeException(
                "Unexpected {$index_description} entry type: " . json_encode($type)
            );
        }
        if (!array_key_exists("empty", $entry) || !is_bool($entry["empty"])) {
            throw new RuntimeException(
                "Directory entry in the {$index_description} has no boolean empty field: "
                . json_encode($entry, JSON_UNESCAPED_SLASHES)
            );
        }
        return $entry["empty"] ? "empty_directory" : "non_empty_directory";
    }

    private function write_local_path_to_push(string $path): void
    {
        $line = json_encode(
            ["path" => base64_encode($path)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (fwrite($this->local_paths_to_push_handle, $line) !== strlen($line)) {
            throw new RuntimeException("Short write on local push path list {$this->local_paths_to_push}, is the disk full?");
        }
    }

    private function write_local_path_to_delete(string $path): void
    {
        $record = $path . "\0";
        if (fwrite($this->local_paths_to_delete_handle, $record) !== strlen($record)) {
            throw new RuntimeException("Short write on local paths to delete {$this->local_paths_to_delete}, is the disk full?");
        }
    }

    /**
     * Remember a deleted directory after discarding intervals passed by the
     * sorted local index at the previous push.
     *
     * @param string[] $seen_deleted_directories Directories whose descendant ranges remain active.
     */
    private function remember_deleted_directory(string $path, array &$seen_deleted_directories): void
    {
        if ($this->is_covered_by_seen_deleted_directory($path, $seen_deleted_directories)) {
            return;
        }
        $seen_deleted_directories[] = $path;
    }

    /**
     * Report whether a seen deleted directory already covers this path.
     *
     * Byte sorting can put a sibling such as `a-other` before `a/child`, so
     * one seen directory is insufficient. Passed descendant ranges are
     * removed from the end of the stack.
     *
     * @param string[] $seen_deleted_directories Directories whose descendant ranges remain active.
     */
    private function is_covered_by_seen_deleted_directory(
        string $path,
        array &$seen_deleted_directories
    ): bool {
        while ($seen_deleted_directories !== []) {
            $root = $seen_deleted_directories[count($seen_deleted_directories) - 1];
            $descendant_prefix = $root . "/";
            if (strpos($path, $descendant_prefix) === 0) {
                return true;
            }
            if (strcmp($path, $descendant_prefix) <= 0) {
                return false;
            }
            array_pop($seen_deleted_directories);
        }
        return false;
    }

    /**
     * Report whether changing a path could change an excluded path.
     *
     * An exact path, its descendant, and its ancestor all conflict: replacing
     * an ancestor directory could otherwise remove the excluded value.
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
     * Read and parse the next index line.
     *
     * @param resource|null $handle
     * @return array{path:string,type?:mixed,ctime?:mixed,size?:mixed,empty?:mixed}|null
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
        if (!is_array($entry) || !array_key_exists("path", $entry) || !is_string($entry["path"])) {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        $entry["path"] = base64_decode($entry["path"], true);
        if ($entry["path"] === false || $entry["path"] === "" || strpos($entry["path"], "\0") !== false) {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        /** @var array{path:string,type?:mixed,ctime?:mixed,size?:mixed,empty?:mixed} $entry */
        return $entry;
    }

    /** @return PlanningCursor|null */
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
        /** @var PlanningCursor $cursor */
        return $cursor;
    }

    /** @param PlanningCursor $cursor */
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

    private function remove_cursor(): void
    {
        if (is_file($this->cursor_file) && !unlink($this->cursor_file)) {
            throw new RuntimeException("Failed to remove the cursor: {$this->cursor_file}");
        }
    }

    /** @param resource $handle */
    private function handle_byte_offset($handle, string $path): int
    {
        $offset = ftell($handle);
        if (!is_int($offset)) {
            throw new RuntimeException("Failed to determine the committed planning length for {$path}.");
        }
        return $offset;
    }

    /** Copy a file atomically so readers only ever see the old or new contents. */
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
