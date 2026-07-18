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
 * boolean on every directory entry, so both inputs distinguish an empty
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
 * A new plan copies the caller's fresh local index into its site directory.
 * Resumed instances keep using that private copy instead of the constructor
 * argument. The constructor retains the initial cursor and positions both
 * inputs. Each step flushes both outputs before advancing that cursor.
 * On the next call, even from a new instance, bytes beyond the committed output
 * lengths are truncated before planning resumes at the two saved input offsets.
 * A process that dies before the cursor changes therefore replays only
 * uncommitted output, without duplicate records.
 *
 * With no local index from a previous push, every current file, symlink, and
 * empty directory is selected and no deletion can be detected. The
 * local_paths_to_push file carries only paths because the sender rechecks the
 * filesystem before upload.
 * Planning holds one line from each input and active delete roots bounded by
 * path nesting; indexes and plans are never accumulated in memory.
 *
 * @phpstan-type PlanningCursor array{fresh_local_index_byte_offset:int,local_index_at_previous_push_byte_offset:int,local_paths_to_push_bytes:int,local_paths_to_delete_bytes:int,progress_changed:int,progress_deleted:int,active_local_delete_roots_b64:list<string>}
 */
class PushPlan
{
    private const PLANNING_RECORDS_PER_STEP = 1000;

    private string $site_dir;

    /** @var string Paths and source metadata from the last completed push. */
    public string $local_index_at_previous_push;

    /** @var string JSONL file of local paths to push. */
    public string $local_paths_to_push;

    /** @var string Raw NUL-delimited local paths to delete. */
    public string $local_paths_to_delete;

    /** @var string Plan-owned copy of the fresh local index. */
    public string $fresh_local_index;

    /** @var string Path to the durable cursor for this site's push plan. */
    private string $cursor_file;

    /** @var list<string> Receiver-owned paths that the plan must not push or delete. */
    private array $excluded_paths = [];

    /** @var PlanningCursor|null Last durable planning boundary, or null after close(). */
    private ?array $cursor = null;

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

    /** @param list<string> $excluded_paths */
    public function __construct(string $site_dir, string $local_index_path, array $excluded_paths = [])
    {
        $this->site_dir = rtrim($site_dir, "/");
        if (!is_dir($this->site_dir) && !@mkdir($this->site_dir, 0755, true) && !is_dir($this->site_dir)) {
            throw new RuntimeException("Failed to create the push plan directory: {$this->site_dir}");
        }
        $this->local_index_at_previous_push = $this->site_dir . "/local_index_at_previous_push.jsonl";
        $this->local_paths_to_push = $this->site_dir . "/local_paths_to_push.jsonl";
        $this->local_paths_to_delete = $this->site_dir . "/local_paths_to_delete";
        $this->fresh_local_index = $this->site_dir . "/fresh_local_index.jsonl";
        $this->cursor_file = $this->site_dir . "/cursor.json";

        $this->cursor = $this->load_cursor();
        if ($this->cursor === null) {
            if (!is_file($local_index_path)) {
                throw new RuntimeException("Cannot plan local files, the fresh local index file is missing: {$local_index_path}");
            }
            $this->atomic_copy($local_index_path, $this->fresh_local_index);
            $this->cursor = [
                "fresh_local_index_byte_offset" => 0,
                "local_index_at_previous_push_byte_offset" => 0,
                "local_paths_to_push_bytes" => 0,
                "local_paths_to_delete_bytes" => 0,
                "progress_changed" => 0,
                "progress_deleted" => 0,
                "active_local_delete_roots_b64" => [],
            ];
            $this->save_cursor($this->cursor);
        } elseif (!is_file($this->fresh_local_index)) {
            throw new RuntimeException("Cannot resume local planning, the retained fresh local index is missing: {$this->fresh_local_index}");
        }
        $this->excluded_paths = $excluded_paths;
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
            $this->cursor["fresh_local_index_byte_offset"],
            "fresh local index"
        );
        if ($this->local_index_at_previous_push_handle) {
            $this->safe_seek(
                $this->local_index_at_previous_push_handle,
                $this->cursor["local_index_at_previous_push_byte_offset"],
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
        $this->atomic_copy($this->fresh_local_index, $this->local_index_at_previous_push);
        $this->remove_cursor();
    }

    /**
     * Perform one bounded local planning step.
     *
     * With no saved cursor, a call starts a new plan and replaces any older
     * output. A saved `planning` cursor resumes the same plan. `complete` means
     * both output files are immutable and ready for the sender.
     *
     * Exclusions suppress network changes, not entries in the retained fresh
     * local index saved as the local index at the previous push after success.
     *
     * @return array {
     *     Result of this planning step.
     *
     *     @type string $status `planning` or `complete`.
     *     @type array  $cursor The new durable boundary.
     * }
     * @phpstan-return array{status:'planning'|'complete',cursor:PlanningCursor}
     */
    public function next_step(): array
    {
        if ($this->closed) {
            throw new LogicException("Cannot advance a push plan after close().");
        }

        $fresh_local_index_byte_offset = $this->cursor["fresh_local_index_byte_offset"];
        $local_index_at_previous_push_byte_offset = $this->cursor["local_index_at_previous_push_byte_offset"];
        $progress_changed = $this->cursor["progress_changed"];
        $progress_deleted = $this->cursor["progress_deleted"];
        $active_local_delete_roots = [];
        foreach ($this->cursor["active_local_delete_roots_b64"] as $encoded_root) {
            $root = base64_decode($encoded_root, true);
            if ($root === false || $root === "") {
                throw new RuntimeException("Planning cursor contains an invalid active local delete root.");
            }
            $active_local_delete_roots[] = $root;
        }

        $this->parse_next_index_entry(
            $this->fresh_local_index_handle,
            $current_entry,
            $current_path,
            $current_base64_path,
            $next_fresh_local_index_byte_offset
        );
        $this->parse_next_index_entry(
            $this->local_index_at_previous_push_handle,
            $local_index_at_previous_push_entry,
            $local_index_at_previous_push_path,
            $local_index_at_previous_push_base64_path,
            $next_local_index_at_previous_push_byte_offset
        );

        $records_processed = 0;
        while ($current_entry !== null || $local_index_at_previous_push_entry !== null) {
            // Base64 does not preserve byte order ('0' sorts before 'A'
            // in ASCII but encodes a higher value), so ordering uses the
            // decoded path bytes.
            if ($local_index_at_previous_push_entry === null) {
                $order = -1;
            } elseif ($current_entry === null) {
                $order = 1;
            } else {
                $order = strcmp($current_path, $local_index_at_previous_push_path);
            }

            $records_for_path = $order === 0 ? 2 : 1;
            if ($records_processed + $records_for_path > self::PLANNING_RECORDS_PER_STEP) {
                break;
            }

            $current_shape = null;
            if ($order <= 0) {
                $current_shape = $this->entry_shape($current_entry, "fresh local index");
            }

            $local_index_at_previous_push_shape = null;
            if ($order >= 0) {
                $local_index_at_previous_push_shape = $this->entry_shape($local_index_at_previous_push_entry, "local index at the previous push");
            }

            if ($order < 0) {
                // New files, symlinks, and empty directories need to be
                // pushed. A new non-empty directory is represented by its
                // descendants.
                if (
                    $current_shape !== "non_empty_directory"
                    && !$this->path_conflicts_with_excluded_paths($current_path, $this->excluded_paths)
                ) {
                    $this->write_list_path(
                        $this->local_paths_to_push_handle,
                        $current_base64_path,
                        $this->local_paths_to_push
                    );
                    ++$progress_changed;
                }
            } elseif ($order > 0) {
                // A deleted non-empty directory emits one root. Its later
                // descendant entries are already covered by that record.
                if (
                    !$this->path_conflicts_with_excluded_paths($local_index_at_previous_push_path, $this->excluded_paths)
                    && !$this->is_covered_by_active_local_delete_root(
                        $local_index_at_previous_push_path,
                        $active_local_delete_roots
                    )
                ) {
                    $this->write_local_path_to_delete($this->local_paths_to_delete_handle, $local_index_at_previous_push_path);
                    ++$progress_deleted;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $this->remember_active_local_delete_root(
                            $local_index_at_previous_push_path,
                            $active_local_delete_roots
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
                    && $current_entry != $local_index_at_previous_push_entry;
                $needs_delete = $current_is_file_or_symlink !== $local_index_at_previous_push_is_file_or_symlink
                    || $non_empty_directory_becomes_empty;
                $needs_push = $empty_directory_needs_push
                    || $changed_file_or_symlink_needs_push;
                $path_is_excluded = $this->path_conflicts_with_excluded_paths(
                    $current_path,
                    $this->excluded_paths
                );

                if (
                    $needs_delete
                    && !$path_is_excluded
                    && !$this->is_covered_by_active_local_delete_root(
                        $local_index_at_previous_push_path,
                        $active_local_delete_roots
                    )
                ) {
                    $this->write_local_path_to_delete($this->local_paths_to_delete_handle, $local_index_at_previous_push_path);
                    ++$progress_deleted;
                    $this->remember_active_local_delete_root(
                        $local_index_at_previous_push_path,
                        $active_local_delete_roots
                    );
                }
                if ($needs_push && !$path_is_excluded) {
                    // Comparing decoded JSON objects keeps field order and
                    // slash escaping out of file and symlink detection. A
                    // writer field change may select every value once: a
                    // wasted upload, but never a missed local change.
                    $this->write_list_path(
                        $this->local_paths_to_push_handle,
                        $current_base64_path,
                        $this->local_paths_to_push
                    );
                    ++$progress_changed;
                }
            }

            if ($order <= 0) {
                $fresh_local_index_byte_offset = $next_fresh_local_index_byte_offset;
                $this->parse_next_index_entry(
                    $this->fresh_local_index_handle,
                    $current_entry,
                    $current_path,
                    $current_base64_path,
                    $next_fresh_local_index_byte_offset
                );
            }
            if ($order >= 0) {
                $local_index_at_previous_push_byte_offset = $next_local_index_at_previous_push_byte_offset;
                $this->parse_next_index_entry(
                    $this->local_index_at_previous_push_handle,
                    $local_index_at_previous_push_entry,
                    $local_index_at_previous_push_path,
                    $local_index_at_previous_push_base64_path,
                    $next_local_index_at_previous_push_byte_offset
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

        $complete = $current_entry === null && $local_index_at_previous_push_entry === null;
        if ($complete) {
            $active_local_delete_roots = [];
        }
        $this->cursor = [
            "fresh_local_index_byte_offset" => $fresh_local_index_byte_offset,
            "local_index_at_previous_push_byte_offset" => $local_index_at_previous_push_byte_offset,
            "local_paths_to_push_bytes" => $this->handle_byte_offset(
                $this->local_paths_to_push_handle,
                $this->local_paths_to_push
            ),
            "local_paths_to_delete_bytes" => $this->handle_byte_offset($this->local_paths_to_delete_handle, $this->local_paths_to_delete),
            "progress_changed" => $progress_changed,
            "progress_deleted" => $progress_deleted,
            "active_local_delete_roots_b64" => array_map("base64_encode", $active_local_delete_roots),
        ];
        $this->save_cursor($this->cursor);
        if (!$complete) {
            // The merge reads one entry ahead. Return both handles to the
            // durable offsets so the next step reads that entry again.
            $this->safe_seek(
                $this->fresh_local_index_handle,
                $this->cursor["fresh_local_index_byte_offset"],
                "fresh local index"
            );
            if ($this->local_index_at_previous_push_handle) {
                $this->safe_seek(
                    $this->local_index_at_previous_push_handle,
                    $this->cursor["local_index_at_previous_push_byte_offset"],
                    "local index at the previous push"
                );
            }
        }

        return [
            "status" => $complete ? "complete" : "planning",
            "cursor" => $this->cursor,
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
        $this->cursor = null;
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
            throw new RuntimeException("Failed to restore planning output {$path} to {$committed_bytes} bytes.");
        }
        return $handle;
    }

    /** @param resource $handle */
    private function safe_seek($handle, int $byte_offset, string $description): void
    {
        $identity = fstat($handle);
        $input_bytes = is_array($identity) ? (int) $identity["size"] : -1;
        if ($byte_offset > $input_bytes) {
            throw new RuntimeException(
                "The {$description} cursor offset {$byte_offset} exceeds its {$input_bytes}-byte file."
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

    /** @param resource $handle */
    private function write_list_path($handle, string $base64_path, string $output_path): void
    {
        $line = json_encode(["path" => $base64_path], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new RuntimeException("Short write on local push path list {$output_path}, is the disk full?");
        }
    }

    /** @param resource $handle */
    private function write_local_path_to_delete($handle, string $path): void
    {
        $record = $path . "\0";
        if (fwrite($handle, $record) !== strlen($record)) {
            throw new RuntimeException("Short write on local paths to delete {$this->local_paths_to_delete}, is the disk full?");
        }
    }

    /**
     * Remember an emitted local delete root after discarding intervals passed
     * by the sorted local index at the previous push.
     *
     * @param string[] $active_local_delete_roots Roots whose descendant ranges remain active.
     */
    private function remember_active_local_delete_root(string $path, array &$active_local_delete_roots): void
    {
        if ($this->is_covered_by_active_local_delete_root($path, $active_local_delete_roots)) {
            return;
        }
        $active_local_delete_roots[] = $path;
    }

    /**
     * Report whether a prior local delete root already covers this path.
     *
     * Byte sorting can put a sibling such as `a-other` before `a/child`, so
     * one active root is insufficient. Active intervals form a stack whose
     * depth is bounded by path nesting rather than the number of index rows.
     *
     * @param string[] $active_local_delete_roots Roots whose descendant ranges remain active.
     */
    private function is_covered_by_active_local_delete_root(
        string $path,
        array &$active_local_delete_roots
    ): bool {
        while ($active_local_delete_roots !== []) {
            $root = $active_local_delete_roots[count($active_local_delete_roots) - 1];
            $descendant_prefix = $root . "/";
            if (strpos($path, $descendant_prefix) === 0) {
                return true;
            }
            if (strcmp($path, $descendant_prefix) <= 0) {
                return false;
            }
            array_pop($active_local_delete_roots);
        }
        return false;
    }

    /**
     * Report whether changing a path could change an excluded path.
     *
     * An exact path, its descendant, and its ancestor all conflict: replacing
     * an ancestor directory could otherwise remove the excluded value.
     *
     * @param string[] $excluded_paths Raw receiver-owned paths.
     */
    private function path_conflicts_with_excluded_paths(string $path, array $excluded_paths): bool
    {
        foreach ($excluded_paths as $excluded_path) {
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
     * All out-parameters become null at EOF. The next byte offset belongs to
     * the first byte after the parsed line and becomes durable only when the
     * caller consumes that entry.
     *
     * @param resource|null $handle
     * @param array<string, mixed>|null $entry
     */
    private function parse_next_index_entry(
        $handle,
        ?array &$entry,
        ?string &$path,
        ?string &$base64_path,
        ?int &$next_byte_offset
    ): void {
        $entry = null;
        $path = null;
        $base64_path = null;
        $next_byte_offset = null;
        if (!$handle) {
            return;
        }
        $raw_line = fgets($handle);
        if ($raw_line === false) {
            if (!feof($handle)) {
                throw new RuntimeException("Failed to read a local push index line.");
            }
            return;
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
        $base64_path = $entry["path"];
        $path = base64_decode($base64_path, true);
        if ($path === false || $path === "" || strpos($path, "\0") !== false) {
            throw new RuntimeException("Invalid index path in line: " . substr($raw_line, 0, 120));
        }
        $next_byte_offset = ftell($handle);
        if (!is_int($next_byte_offset)) {
            throw new RuntimeException("Failed to determine the next local push index byte offset.");
        }
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
        /** @var PlanningCursor $cursor */
        return $cursor;
    }

    /** @param PlanningCursor $cursor */
    private function save_cursor(array $cursor): void
    {
        $contents = json_encode($cursor, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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
