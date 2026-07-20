<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.

/**
 * Internal planner for one PushFilesSender lifecycle.
 *
 * PushPlan builds a path-sorted fresh local index, then diffs it against the
 * local index at the previous push. It writes durable lists of local paths to
 * push and local paths to delete without accumulating an index or path list in
 * memory.
 *
 * PushFilesSender is the only caller-visible processor. It owns the lifecycle
 * lock, top-level phase, transport, result, commit, restart, and removal.
 * PushPlan owns FileIndexProcessor, the fresh local index, the index diff, the
 * meaning of its cursor, and the two completed path lists. PushFilesSender
 * stores the cursor returned by get_cursor().
 *
 * ## Durable boundary
 *
 * While sender.json says `planning`, its PushPlan cursor contains one of
 * four internal phases: `indexing`, `starting_diff`, `diffing`, or `complete`.
 * A false next_step() result means both indexes reached EOF; the sender stores
 * the returned cursor and closes the plan before changing its phase. The
 * completed files remain in the sender-owned plan directory until the sender
 * finishes or discards the push.
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
 * omitted from both path lists but remain in the fresh local index saved
 * after success.
 *
 * The index reader trusts the entry values produced by the indexer. It retains
 * failure handling for reading lines, decoding JSON, and decoding base64 paths.
 *
 * ## Durability and memory
 *
 * Each indexing step advances one FileIndexProcessor traversal event and
 * flushes any appended JSONL bytes before updating the traversal cursor and
 * committed byte offset returned to the sender.
 * A separate step starts the index diff. Each diff step compares at most one
 * path represented by either index and flushes only the output changed by that
 * step before updating its next cursor. The sender stores that cursor before
 * returning from its own step. `resume()` discards bytes beyond saved offsets,
 * so an interrupted step cannot leave duplicate durable entries.
 *
 * PushPlan retains the next entry from each index and the top of an append-only
 * deleted-directory stack needed to suppress redundant descendant deletions. It
 * never loads an index, path list, or the stack in full.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type IndexingCursor array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}
 * @phpstan-type StartingDiffCursor array{phase:'starting_diff'}
 * @phpstan-type IndexDiffCursor array{phase:'diffing',byte_offset_in_fresh_index:int,byte_offset_in_previous_index:int,byte_offset_in_local_paths_to_push:int,byte_offset_in_local_paths_to_delete:int,deleted_directory_stack_top_byte_offset:int|null}
 * @phpstan-type CompleteCursor array{phase:'complete'}
 * @phpstan-type PushPlanPosition IndexingCursor|StartingDiffCursor|IndexDiffCursor|CompleteCursor
 * @phpstan-type PushPlanCursor array{plan_directory:string,local_tree_root:string,local_index_at_previous_push:string,position:PushPlanPosition}
 * @phpstan-type DeletedDirectoryStackEntry array{path:string,previous_byte_offset:int|null}
 */
class PushPlan
{
    /** @var string Canonical local tree root inspected while building the fresh local index. */
    private string $local_tree_root;

    /** @var string Sender-owned active plan directory. */
    private string $plan_directory;

    /** @var string Paths and metadata from the last completed push. */
    private string $local_index_at_previous_push;

    /** @var string JSONL file of local paths to push. */
    private string $local_paths_to_push;

    /** @var string Raw NUL-delimited local paths to delete. */
    private string $local_paths_to_delete;

    /** @var string Plan-owned fresh local index. */
    private string $fresh_local_index;

    /** @var string Plan path containing receiver-owned exclusions for the active push. */
    private string $excluded_paths_file;

    /** @var string Append-only deleted-directory stack for the active plan. */
    private string $deleted_directories_stack;

    /** @var list<string> Receiver-owned paths that the plan must not push or delete. */
    private array $excluded_paths = [];

    /** @var PushPlanCursor Current cursor returned to PushFilesSender. */
    private array $cursor;

    /** @var bool Whether close() has closed this plan's file handles. */
    private bool $closed = false;

    /** @var FileIndexProcessor Fresh local index traversal retained during indexing. */
    private FileIndexProcessor $file_index_processor;

    /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}|null */
    private ?array $fresh_local_index_entry = null;

    /** @var bool Whether $fresh_local_index_entry has been read, including EOF. */
    private bool $fresh_local_index_entry_loaded = false;

    /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}|null */
    private ?array $previous_local_index_entry = null;

    /** @var bool Whether $previous_local_index_entry has been read, including EOF. */
    private bool $previous_local_index_entry_loaded = false;

    /** @var DeletedDirectoryStackEntry|null Top active deleted-directory stack entry. */
    private ?array $deleted_directory_stack_entry = null;

    /** @var resource|null Open fresh local index retained during indexing or the index diff. */
    private $fresh_local_index_handle = null;
    /** @var resource|null */
    private $local_index_at_previous_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_delete_handle = null;
    /** @var resource|null */
    private $deleted_directories_stack_handle = null;

    /**
     * Starts a push plan by opening a fresh local index traversal.
     *
     * Copies the target exclusions into the plan directory before opening the
     * fresh local index traversal. Until the caller stores the returned cursor,
     * an interrupted start is repeated and overwrites these initial plan files.
     *
     * @param string $plan_directory              Sender-owned active plan directory.
     * @param string $local_tree_root              Canonical local tree root.
     * @param string $local_index_at_previous_push Index saved after the previous successful push.
     * @param string $excluded_paths_path          Sender-owned target exclusions file.
     * @return self Open plan positioned at the initial indexing cursor.
     */
    public static function start(
        string $plan_directory,
        string $local_tree_root,
        string $local_index_at_previous_push,
        string $excluded_paths_path
    ): self {
        $plan = new self($plan_directory, $local_tree_root, $local_index_at_previous_push);
        if (!@copy($excluded_paths_path, $plan->excluded_paths_file)) {
            throw new RuntimeException("Failed to copy excluded paths into the push plan: {$excluded_paths_path}");
        }
        $plan->excluded_paths = $plan->load_excluded_paths();
        $plan->fresh_local_index_handle = fopen($plan->fresh_local_index, "w+b");
        if (!is_resource($plan->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to open the fresh local index: {$plan->fresh_local_index}");
        }
        $plan->file_index_processor = FileIndexProcessor::start(
            [$plan->local_tree_root],
            $plan->local_tree_root,
            false,
            false,
            $plan->plan_directory
        );
        $plan->cursor = [
            "plan_directory" => $plan->plan_directory,
            "local_tree_root" => $plan->local_tree_root,
            "local_index_at_previous_push" => $plan->local_index_at_previous_push,
            "position" => [
                "phase" => "indexing",
                "file_index_cursor" => $plan->file_index_processor->get_cursor(),
                "fresh_local_index_byte_offset" => 0,
            ],
        ];
        return $plan;
    }

    /**
     * Resumes the unfinished push plan retained in local push state.
     *
     * Reopens only the processor and files required by the cursor's current
     * internal phase.
     *
     * @phpstan-param PushPlanCursor $cursor Cursor previously returned by get_cursor().
     * @return self Open plan positioned at its last durable cursor.
     */
    public static function resume(array $cursor): self
    {
        $plan = new self(
            $cursor["plan_directory"],
            $cursor["local_tree_root"],
            $cursor["local_index_at_previous_push"]
        );
        $plan->cursor = $cursor;
        $position = $plan->cursor["position"];
        if ($position["phase"] !== "complete") {
            $plan->excluded_paths = $plan->load_excluded_paths();
        }
        if ($position["phase"] === "indexing") {
            $plan->open_fresh_local_index_for_continuation();
        } elseif ($position["phase"] === "diffing") {
            $plan->open_plan_files();
        }
        return $plan;
    }

    /**
     * Returns the cursor required to resume this plan.
     *
     * @phpstan-return PushPlanCursor Current cursor after the latest completed step.
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /**
     * Returns the JSONL local paths to push list.
     */
    public function get_local_paths_to_push_path(): string
    {
        return $this->local_paths_to_push;
    }

    /**
     * Returns the raw NUL-delimited path list produced for local deletions.
     */
    public function get_local_paths_to_delete_path(): string
    {
        return $this->local_paths_to_delete;
    }

    /**
     * Returns the plan-owned fresh local index path.
     */
    public function get_fresh_local_index_path(): string
    {
        return $this->fresh_local_index;
    }

    /**
     * Initializes paths in the sender-owned active plan directory.
     *
     * @param string $plan_directory              Sender-owned active plan directory.
     * @param string $local_tree_root              Canonical local tree root.
     * @param string $local_index_at_previous_push Index saved after the previous successful push.
     */
    private function __construct(
        string $plan_directory,
        string $local_tree_root,
        string $local_index_at_previous_push
    ) {
        $plan_directory = rtrim($plan_directory, "/");
        if (!is_dir($plan_directory)) {
            throw new LogicException("Cannot open a push plan without its directory: {$plan_directory}");
        }
        $this->plan_directory = $plan_directory;
        $this->set_local_tree_root($local_tree_root);
        $this->local_index_at_previous_push = $local_index_at_previous_push;
        $this->local_paths_to_push = $plan_directory . "/local_paths_to_push.jsonl";
        $this->local_paths_to_delete = $plan_directory . "/local_paths_to_delete";
        $this->fresh_local_index = $plan_directory . "/fresh_local_index.jsonl";
        $this->excluded_paths_file = $plan_directory . "/excluded_paths.json";
        $this->deleted_directories_stack = $plan_directory . "/deleted_directories_stack.jsonl";
    }

    /**
     * Stores the canonical root of the local tree represented by this push.
     *
     * @param string $local_tree_root Local tree root selected by PushFilesSender.
     */
    private function set_local_tree_root(string $local_tree_root): void
    {
        clearstatcache(true, $local_tree_root);
        $canonical_local_tree_root = realpath($local_tree_root);
        if ($canonical_local_tree_root === false || !is_dir($canonical_local_tree_root) || is_link($local_tree_root)) {
            throw new InvalidArgumentException("PushPlan requires the local tree root to be a real directory.");
        }
        $this->local_tree_root = rtrim($canonical_local_tree_root, "/");
    }

    /**
     * Reopens the fresh local index at the byte offset stored with its traversal cursor.
     *
     * Any bytes appended after the cursor last stored by the sender are
     * discarded before FileIndexProcessor continues from that same step.
     */
    private function open_fresh_local_index_for_continuation(): void
    {
        /** @var IndexingCursor $cursor */
        $cursor = $this->cursor["position"];
        $this->fresh_local_index_handle = fopen($this->fresh_local_index, "r+b");
        if (!is_resource($this->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to reopen the fresh local index: {$this->fresh_local_index}");
        }
        if (!ftruncate($this->fresh_local_index_handle, $cursor["fresh_local_index_byte_offset"])) {
            throw new RuntimeException("Failed to discard uncommitted fresh-local-index bytes.");
        }
        if (fseek($this->fresh_local_index_handle, $cursor["fresh_local_index_byte_offset"]) !== 0) {
            throw new RuntimeException("Failed to seek to the fresh local index byte offset.");
        }
        $this->file_index_processor = FileIndexProcessor::resume(
            [$this->local_tree_root],
            json_encode($cursor["file_index_cursor"], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            false,
            false,
            $this->plan_directory
        );
    }

    /**
     * Opens and positions the files used by start() and resume().
     *
     * Indexes are positioned at their durable cursor offsets. Output bytes
     * beyond their durable offsets are discarded before writing continues.
     */
    private function open_plan_files(): void
    {
        /** @var IndexDiffCursor $cursor */
        $cursor = $this->cursor["position"];
        $this->fresh_local_index_entry = null;
        $this->fresh_local_index_entry_loaded = false;
        $this->previous_local_index_entry = null;
        $this->previous_local_index_entry_loaded = false;
        $this->deleted_directory_stack_entry = null;
        $this->local_paths_to_push_handle = $this->open_and_truncate_and_seek(
            $this->local_paths_to_push,
            $cursor["byte_offset_in_local_paths_to_push"]
        );
        $this->local_paths_to_delete_handle = $this->open_and_truncate_and_seek(
            $this->local_paths_to_delete,
            $cursor["byte_offset_in_local_paths_to_delete"]
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
        $this->seek_to_cursor(
            $this->fresh_local_index_handle,
            $cursor["byte_offset_in_fresh_index"],
            "fresh local index"
        );
        if ($this->local_index_at_previous_push_handle) {
            $this->seek_to_cursor(
                $this->local_index_at_previous_push_handle,
                $cursor["byte_offset_in_previous_index"],
                "local index at the previous push"
            );
        }
        $this->deleted_directories_stack_handle = fopen($this->deleted_directories_stack, "a+b");
        if (!is_resource($this->deleted_directories_stack_handle)) {
            throw new RuntimeException("Failed to open the deleted-directory stack: {$this->deleted_directories_stack}");
        }
        $this->deleted_directory_stack_entry = $this->read_deleted_directory_stack_entry(
            $cursor["deleted_directory_stack_top_byte_offset"]
        );
    }

    /**
     * Performs one step for the current internal phase.
     *
     * A false return means planning is complete and remains false on later
     * calls. The owning sender closes the plan before using its path lists.
     *
     * @return bool Whether another planning step may be performed.
     */
    public function next_step(): bool
    {
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            return false;
        }
        if ($this->closed) {
            throw new LogicException("Cannot take a push plan step after close().");
        }

        switch ($position["phase"]) {
            case "indexing":
                $this->next_file_index_step();
                return true;
            case "starting_diff":
                $this->start_index_diff();
                return true;
            case "diffing":
                return $this->next_index_diff_step();
        }
    }

    /**
     * Performs one filesystem traversal step and updates its exact continuation point.
     *
     * Completed index entries are appended and flushed before the cursor moves
     * past them. Steps which omit a path still update the changed traversal
     * cursor. A directory failure leaves the caller's stored cursor unchanged,
     * so the next plan run attempts that same directory again.
     */
    private function next_file_index_step(): void
    {
        if (!$this->file_index_processor->next_index_step()) {
            $this->file_index_processor->close();
            $this->close_fresh_local_index_handle();
            $this->cursor["position"] = ["phase" => "starting_diff"];
            return;
        }

        $fresh_local_index_changed = false;
        switch ($this->file_index_processor->get_step_status()) {
            case FileIndexProcessor::STATUS_INDEXED:
                foreach ($this->file_index_processor->get_index_entries() as $index_entry) {
                    $this->append_fresh_local_index_entry($index_entry);
                    $fresh_local_index_changed = true;
                }
                break;

            case FileIndexProcessor::STATUS_DIRECTORY_ERROR:
                $directory_error = $this->file_index_processor->get_directory_error();
                throw new RuntimeException(
                    $directory_error["message"] . ": " . base64_encode($directory_error["path"]) . "."
                );

            case FileIndexProcessor::STATUS_SKIPPED:
            case FileIndexProcessor::STATUS_PATH_UNAVAILABLE:
            case FileIndexProcessor::STATUS_DIRECTORY_COMPLETE:
                break;
        }

        if ($fresh_local_index_changed && !fflush($this->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to flush the fresh local index.");
        }
        $fresh_local_index_byte_offset = ftell($this->fresh_local_index_handle);
        if (!is_int($fresh_local_index_byte_offset)) {
            throw new RuntimeException("Failed to determine the fresh local index byte offset.");
        }
        $this->cursor["position"] = [
            "phase" => "indexing",
            "file_index_cursor" => $this->file_index_processor->get_cursor(),
            "fresh_local_index_byte_offset" => $fresh_local_index_byte_offset,
        ];
    }

    /**
     * Starts the index diff and opens its plan files.
     */
    private function start_index_diff(): void
    {
        if (file_put_contents($this->deleted_directories_stack, "") !== 0) {
            throw new RuntimeException("Failed to initialize the deleted-directory stack: {$this->deleted_directories_stack}");
        }
        $this->cursor["position"] = [
            "phase" => "diffing",
            "byte_offset_in_fresh_index" => 0,
            "byte_offset_in_previous_index" => 0,
            "byte_offset_in_local_paths_to_push" => 0,
            "byte_offset_in_local_paths_to_delete" => 0,
            "deleted_directory_stack_top_byte_offset" => null,
        ];
        $this->open_plan_files();
    }

    /**
     * Appends one FileIndexProcessor entry in the JSONL format consumed by the
     * index diff.
     *
     * @param array<string,mixed> $index_entry Filesystem path details from FileIndexProcessor.
     */
    private function append_fresh_local_index_entry(array $index_entry): void
    {
        if ($index_entry["type"] === "other") {
            throw new RuntimeException(
                "Cannot push the unsupported local path: " . base64_encode($index_entry["path"]) . "."
            );
        }
        if ($index_entry["type"] === "dir" && !array_key_exists("empty", $index_entry)) {
            throw new RuntimeException(
                "Could not inspect the local directory: " . base64_encode($index_entry["path"]) . "."
            );
        }

        $local_path = substr($index_entry["path"], strlen($this->local_tree_root) + 1);
        $fresh_local_index_entry = [
            "path" => base64_encode($local_path),
            "ctime" => $index_entry["ctime"],
            "size" => $index_entry["size"],
            "type" => $index_entry["type"],
        ];
        if ($index_entry["type"] === "dir") {
            $fresh_local_index_entry["empty"] = $index_entry["empty"];
        }
        $line = json_encode($fresh_local_index_entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->fresh_local_index_handle, $line) !== strlen($line)) {
            throw new RuntimeException("Failed to write a fresh local index entry.");
        }
    }

    /**
     * Compares at most one path and updates the resulting push plan cursor.
     *
     * Exclusions suppress network changes, not entries in the retained fresh
     * local index saved as the local index at the previous push after success.
     *
     * @return bool Whether another index diff step may be performed.
     */
    private function next_index_diff_step(): bool
    {
        /** @var IndexDiffCursor $cursor */
        $cursor = $this->cursor["position"];

        $byte_offset_in_fresh_index = $cursor["byte_offset_in_fresh_index"];
        $byte_offset_in_previous_index = $cursor["byte_offset_in_previous_index"];
        $deleted_directory_stack_top_byte_offset = $cursor["deleted_directory_stack_top_byte_offset"];
        $local_paths_to_push_changed = false;
        $local_paths_to_delete_changed = false;
        $deleted_directories_stack_changed = false;

        if (!$this->fresh_local_index_entry_loaded) {
            $this->fresh_local_index_entry = $this->parse_next_index_entry($this->fresh_local_index_handle);
            $this->fresh_local_index_entry_loaded = true;
        }
        if (!$this->previous_local_index_entry_loaded) {
            $this->previous_local_index_entry = $this->parse_next_index_entry(
                $this->local_index_at_previous_push_handle
            );
            $this->previous_local_index_entry_loaded = true;
        }
        $entry_fresh_index = $this->fresh_local_index_entry;
        $entry_previous_index = $this->previous_local_index_entry;

        if ($entry_fresh_index !== null || $entry_previous_index !== null) {
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

            $current_shape = null;
            if ($path_comparison <= 0) {
                $current_shape = $this->entry_shape($entry_fresh_index);
            }

            $local_index_at_previous_push_shape = null;
            if ($path_comparison >= 0) {
                $local_index_at_previous_push_shape = $this->entry_shape($entry_previous_index);

                // Byte sorting can put a sibling such as `a-other` before
                // `a/child`. Every retained non-empty directory has a later
                // descendant, so adjacent previous-index entries can pass at
                // most the top sibling range.
                if ($this->deleted_directory_stack_entry !== null) {
                    $descendant_prefix = $this->deleted_directory_stack_entry["path"] . "/";
                    if (
                        strpos($entry_previous_index["path"], $descendant_prefix) !== 0
                        && strcmp($entry_previous_index["path"], $descendant_prefix) > 0
                    ) {
                        $deleted_directory_stack_top_byte_offset = $this->deleted_directory_stack_entry["previous_byte_offset"];
                        $this->deleted_directory_stack_entry = $this->read_deleted_directory_stack_entry(
                            $deleted_directory_stack_top_byte_offset
                        );
                    }
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
                    $this->append_local_path_to_push($entry_fresh_index);
                    $local_paths_to_push_changed = true;
                }
            } elseif ($path_comparison > 0) {
                // A deleted non-empty directory emits one root. Its later
                // descendant entries are already covered by that path.
                if (
                    !$this->path_conflicts_with_excluded_paths($entry_previous_index["path"])
                    && !$this->deleted_directory_stack_covers_path(
                        $entry_previous_index["path"],
                        $this->deleted_directory_stack_entry
                    )
                ) {
                    $this->append_local_path_to_delete($entry_previous_index["path"]);
                    $local_paths_to_delete_changed = true;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $deleted_directory_stack_top_byte_offset = $this->append_deleted_directory_stack_entry(
                            $entry_previous_index["path"],
                            $deleted_directory_stack_top_byte_offset
                        );
                        $deleted_directories_stack_changed = true;
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
                // size. Other index values do not select a path for upload.
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
                    && !$this->deleted_directory_stack_covers_path(
                        $entry_previous_index["path"],
                        $this->deleted_directory_stack_entry
                    )
                ) {
                    $this->append_local_path_to_delete($entry_previous_index["path"]);
                    $local_paths_to_delete_changed = true;
                    if ($local_index_at_previous_push_shape === "non_empty_directory") {
                        $deleted_directory_stack_top_byte_offset = $this->append_deleted_directory_stack_entry(
                            $entry_previous_index["path"],
                            $deleted_directory_stack_top_byte_offset
                        );
                        $deleted_directories_stack_changed = true;
                    }
                }
                if ($needs_push && !$path_is_excluded) {
                    $this->append_local_path_to_push($entry_fresh_index);
                    $local_paths_to_push_changed = true;
                }
            }

            if ($path_comparison <= 0) {
                $byte_offset_in_fresh_index = ftell($this->fresh_local_index_handle);
                $this->fresh_local_index_entry = $this->parse_next_index_entry($this->fresh_local_index_handle);
            }
            if ($path_comparison >= 0) {
                $byte_offset_in_previous_index = ftell($this->local_index_at_previous_push_handle);
                $this->previous_local_index_entry = $this->parse_next_index_entry(
                    $this->local_index_at_previous_push_handle
                );
            }
        }

        if (
            ( $local_paths_to_push_changed && !fflush($this->local_paths_to_push_handle) )
            || ( $local_paths_to_delete_changed && !fflush($this->local_paths_to_delete_handle) )
            || ( $deleted_directories_stack_changed && !fflush($this->deleted_directories_stack_handle) )
        ) {
            throw new RuntimeException("Failed to flush a push-plan output.");
        }

        $complete = $this->fresh_local_index_entry === null
            && $this->previous_local_index_entry === null;
        if ($complete) {
            $deleted_directory_stack_top_byte_offset = null;
            $this->deleted_directory_stack_entry = null;
        }
        $cursor_after_step = $complete
            ? ["phase" => "complete"]
            : [
                "phase" => "diffing",
                "byte_offset_in_fresh_index" => $byte_offset_in_fresh_index,
                "byte_offset_in_previous_index" => $byte_offset_in_previous_index,
                "byte_offset_in_local_paths_to_push" => ftell($this->local_paths_to_push_handle),
                "byte_offset_in_local_paths_to_delete" => ftell($this->local_paths_to_delete_handle),
                "deleted_directory_stack_top_byte_offset" => $deleted_directory_stack_top_byte_offset,
            ];
        $this->cursor["position"] = $cursor_after_step;
        return !$complete;
    }

    /**
     * Closes every plan file handle and prevents further plan steps.
     *
     * The cursor returned to the sender and the plan-owned files remain
     * available to resume the plan or save the completed fresh local index
     * after a successful push.
     */
    public function close(): void
    {
        if (isset($this->file_index_processor)) {
            $this->file_index_processor->close();
        }
        $this->close_fresh_local_index_handle();
        if (is_resource($this->local_index_at_previous_push_handle)) {
            fclose($this->local_index_at_previous_push_handle);
        }
        if (is_resource($this->local_paths_to_push_handle)) {
            fclose($this->local_paths_to_push_handle);
        }
        if (is_resource($this->local_paths_to_delete_handle)) {
            fclose($this->local_paths_to_delete_handle);
        }
        if (is_resource($this->deleted_directories_stack_handle)) {
            fclose($this->deleted_directories_stack_handle);
        }
        $this->local_index_at_previous_push_handle = null;
        $this->local_paths_to_push_handle = null;
        $this->local_paths_to_delete_handle = null;
        $this->deleted_directories_stack_handle = null;
        $this->fresh_local_index_entry = null;
        $this->fresh_local_index_entry_loaded = false;
        $this->previous_local_index_entry = null;
        $this->previous_local_index_entry_loaded = false;
        $this->deleted_directory_stack_entry = null;
        $this->closed = true;
    }

    /**
     * Closes the fresh local index retained while indexing or diffing the indexes.
     */
    private function close_fresh_local_index_handle(): void
    {
        if (is_resource($this->fresh_local_index_handle)) {
            fclose($this->fresh_local_index_handle);
        }
        $this->fresh_local_index_handle = null;
    }

    /**
     * Opens one output at its durable cursor offset and discards later bytes.
     *
     * Plan output is flushed before its cursor is stored, so a valid cursor
     * cannot exceed the output length. A process may stop after writing output
     * but before storing its next cursor. Truncating to the saved offset
     * removes only that uncommitted tail before the plan continues.
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
        if (!ftruncate($handle, $byte_offset) || fseek($handle, $byte_offset) !== 0) {
            fclose($handle);
            throw new RuntimeException("Failed to truncate and seek push plan output {$path} to byte {$byte_offset}.");
        }
        return $handle;
    }

    /**
     * Positions an index handle at its durable cursor offset.
     *
     * The plan owns immutable index files, and records their consumed byte
     * offsets only after finishing the corresponding step.
     *
     * @param resource $handle      Open index handle to position.
     * @param int      $byte_offset Durable byte offset saved in the cursor.
     * @param string   $description Human-readable index name used in failures.
     */
    private function seek_to_cursor($handle, int $byte_offset, string $description): void
    {
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
     * Appends one path and its planned type, size, and ctime to the JSONL list.
     *
     * Base64 keeps arbitrary filesystem path bytes representable in JSON.
     *
     * @param array $entry {
     *     Fresh local index entry selected for push.
     *
     *     @type string $path  Decoded filesystem path.
     *     @type string $type  Entry type: `file`, `link`, or `dir`.
     *     @type int    $size  Indexed size used for change detection.
     *     @type int    $ctime Indexed change timestamp.
     * }
     * @phpstan-param array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool} $entry
     */
    private function append_local_path_to_push(array $entry): void
    {
        $line = json_encode(
            [
                "path" => base64_encode($entry["path"]),
                "type" => $entry["type"] === "link" ? "symlink" : ($entry["type"] === "dir" ? "directory" : "file"),
                "size" => $entry["size"],
                "ctime" => $entry["ctime"],
            ],
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
        $path_with_nul = $path . "\0";
        if (fwrite($this->local_paths_to_delete_handle, $path_with_nul) !== strlen($path_with_nul)) {
            throw new RuntimeException("Short write on local paths to delete {$this->local_paths_to_delete}, is the disk full?");
        }
    }

    /**
     * Appends one active directory and links it to the preceding stack entry.
     *
     * @param string   $path                 Raw directory path selected for deletion.
     * @param int|null $previous_byte_offset Byte offset of the preceding active entry.
     * @return int Byte offset of the appended entry.
     */
    private function append_deleted_directory_stack_entry(string $path, ?int $previous_byte_offset): int
    {
        if (fseek($this->deleted_directories_stack_handle, 0, SEEK_END) !== 0) {
            throw new RuntimeException("Failed to seek to the end of the deleted-directory stack.");
        }
        $byte_offset = ftell($this->deleted_directories_stack_handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException("Failed to determine the deleted-directory stack byte offset.");
        }
        $line = json_encode(
            [
                "path_b64" => base64_encode($path),
                "previous_byte_offset" => $previous_byte_offset,
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (fwrite($this->deleted_directories_stack_handle, $line) !== strlen($line)) {
            throw new RuntimeException("Failed to append to the deleted-directory stack.");
        }
        $this->deleted_directory_stack_entry = [
            "path" => $path,
            "previous_byte_offset" => $previous_byte_offset,
        ];
        return $byte_offset;
    }

    /**
     * Reads one stack entry addressed by the planning cursor.
     *
     * @param int|null $byte_offset Entry byte offset, or null for an empty stack.
     * @return DeletedDirectoryStackEntry|null Decoded stack entry, or null.
     */
    private function read_deleted_directory_stack_entry(?int $byte_offset): ?array
    {
        if ($byte_offset === null) {
            return null;
        }
        if (fseek($this->deleted_directories_stack_handle, $byte_offset) !== 0) {
            throw new RuntimeException("Failed to seek in the deleted-directory stack.");
        }
        $line = fgets($this->deleted_directories_stack_handle);
        if (!is_string($line)) {
            throw new RuntimeException("Failed to read the deleted-directory stack entry at byte {$byte_offset}.");
        }
        try {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Failed to decode the deleted-directory stack entry at byte {$byte_offset}.", 0, $exception);
        }
        /** @var array{path_b64:string,previous_byte_offset:int|null} $entry */
        $path = base64_decode($entry["path_b64"], true);
        if ($path === false) {
            throw new RuntimeException("Failed to decode the deleted-directory path at byte {$byte_offset}.");
        }
        return [
            "path" => $path,
            "previous_byte_offset" => $entry["previous_byte_offset"],
        ];
    }

    /**
     * Reports whether the active deleted directory contains the path.
     *
     * @param string                          $path  Raw filesystem path to classify.
     * @param DeletedDirectoryStackEntry|null $entry Top active stack entry.
     */
    private function deleted_directory_stack_covers_path(string $path, ?array $entry): bool
    {
        return $entry !== null && strpos($path, $entry["path"] . "/") === 0;
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
     * Loads the sender-owned exclusions used throughout one planning run.
     *
     * @return list<string> Decoded document-root-relative excluded paths.
     */
    private function load_excluded_paths(): array
    {
        $contents = file_get_contents($this->excluded_paths_file);
        if (!is_string($contents)) {
            throw new RuntimeException("Failed to read excluded paths: {$this->excluded_paths_file}");
        }
        try {
            $excluded_paths_b64 = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Failed to decode excluded paths: {$this->excluded_paths_file}", 0, $exception);
        }
        /** @var list<string> $excluded_paths_b64 */
        $excluded_paths = [];
        foreach ($excluded_paths_b64 as $excluded_path_b64) {
            $excluded_path = base64_decode($excluded_path_b64, true);
            if ($excluded_path === false) {
                throw new RuntimeException("Failed to decode an excluded path: {$this->excluded_paths_file}");
            }
            $excluded_paths[] = $excluded_path;
        }
        return $excluded_paths;
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

}
