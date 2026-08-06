<?php

use function Reprint\Importer\sort_index_file;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_path_segments;
use function WordPress\Reprint\Exporter\path_is_within_root;
use function WordPress\Reprint\Exporter\path_remainder_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.

/**
 * Internal bounded local-index and change planner.
 *
 * PushPlan builds a path-sorted fresh local index, then diffs it against the
 * local index supplied by its caller. It writes durable lists of
 * local paths to push and local paths to delete without accumulating an index
 * or path list in memory.
 *
 * PushFilesSender or the files-diff command owns the caller-visible lifecycle,
 * lock, top-level phase, result, and terminal behavior. PushPlan owns
 * FileIndexProcessor, the fresh local index, the index diff, the meaning of
 * its cursor, and the two completed path lists. A caller which resumes across
 * processes stores the cursor returned by get_cursor().
 *
 * ## Durable boundary
 *
 * The PushPlan cursor contains one of four internal phases: `indexing`,
 * `starting_diff`, `diffing`, or `complete`. A false next_step() result means
 * both indexes reached EOF; the caller stores the returned cursor and closes
 * the plan before changing its phase. The completed files remain in the
 * caller-owned plan directory until the caller no longer needs them.
 *
 * ## Change detection
 *
 * ctime is machine-local, so the local index must describe the same filesystem
 * root on the same local machine. The caller supplies the local index for its
 * remote Reprint API URL. File and symlink changes are determined by type,
 * ctime, and size. Directory changes use the indexer's empty-directory marker;
 * non-empty directories are represented by their descendants.
 *
 * With no local index, every file, symlink, and empty directory is
 * selected, and no deletion can be detected. Excluded paths are omitted from
 * both path lists but remain in the fresh local index.
 *
 * The index reader trusts the entry values produced by the indexer. It retains
 * failure handling for reading lines, decoding JSON, and decoding base64 paths.
 *
 * ## Durability and memory
 *
 * Each indexing step advances one FileIndexProcessor traversal event and
 * updates the traversal cursor and fresh-index byte offset returned to the
 * caller. A separate step starts the index diff. Each diff step compares at
 * most one path represented by either index and updates its next cursor.
 * The owner flushes pending output before storing a cursor. `resume()` discards
 * bytes beyond saved offsets, so an interrupted step cannot leave duplicate
 * durable entries.
 *
 * PushPlan retains the next entry from each index and the top of an append-only
 * deleted-directory stack needed to suppress redundant descendant deletions. It
 * never loads an index, path list, or the stack in full.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type IndexingCursor array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}
 * @phpstan-type StartingDiffCursor array{phase:'starting_diff'}
 * @phpstan-type IndexDiffCursor array{phase:'diffing',byte_offset_in_fresh_local_index:int,byte_offset_in_local_index:int,byte_offset_in_local_paths_to_push:int,byte_offset_in_local_paths_to_delete:int,local_paths_to_push_count:int|null,deleted_directory_stack_top_byte_offset:int|null,previous_fresh_local_index_entry_path:string|null}
 * @phpstan-type CompleteCursor array{phase:'complete',local_paths_to_push_count:int|null}
 * @phpstan-type PushPlanPosition IndexingCursor|StartingDiffCursor|IndexDiffCursor|CompleteCursor
 * @phpstan-type PushPlanCursor array{plan_directory:string,filesystem_root:string,local_index_file:string,document_root_local_relative_path:string,position:PushPlanPosition}
 * @phpstan-type DeletedDirectoryStackEntry array{path:string,previous_byte_offset:int|null}
 */
class PushPlan
{
    /** @var string Resolved filesystem root inspected while building the fresh local index. */
    private string $filesystem_root;

    /** @var string Document root relative to the local filesystem root. */
    private string $document_root_local_relative_path;

    /** @var string Caller-owned active plan directory. */
    private string $plan_directory;

    /** @var string Local index supplied by the caller. */
    private string $local_index_file;

    /** @var string JSONL file of local paths to push. */
    private string $local_paths_to_push;

    /** @var string Raw NUL-delimited local paths to delete. */
    private string $local_paths_to_delete;

    /** @var string Plan-owned fresh local index file. */
    private string $fresh_local_index_file;

    /** @var string Plan path containing receiver-owned exclusions for the active push. */
    private string $excluded_paths_file;

    /** @var string Append-only deleted-directory stack for the active plan. */
    private string $deleted_directories_stack;

    /** @var list<string> Receiver-owned paths that the plan must not push or delete. */
    private array $excluded_paths = [];

    /** @var PushPlanCursor Current cursor returned to the caller. */
    private array $cursor;

    /** @var bool Whether close() has closed this plan's file handles. */
    private bool $closed = false;

    /** @var FileIndexProcessor Fresh local index traversal retained during indexing. */
    private FileIndexProcessor $file_index_processor;

    /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}|null */
    private ?array $fresh_local_index_entry = null;

    /** @var bool Whether $fresh_local_index_entry has been read, including EOF. */
    private bool $fresh_local_index_entry_loaded = false;

    /** @var string|null Path of the fresh entry consumed before the lookahead entry. */
    private ?string $previous_fresh_local_index_entry_path = null;

    /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}|null */
    private ?array $local_index_lookahead_entry = null;

    /** @var bool Whether $local_index_lookahead_entry has been read, including EOF. */
    private bool $local_index_lookahead_entry_loaded = false;

    /** @var DeletedDirectoryStackEntry|null Top active deleted-directory stack entry. */
    private ?array $deleted_directory_stack_entry = null;

    /** @var resource|null Open fresh local index retained during indexing or the index diff. */
    private $fresh_local_index_handle = null;
    /** @var resource|null */
    private $local_index_file_handle = null;
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
     * @param string $plan_directory      Caller-owned active plan directory.
     * @param string $filesystem_root     Resolved filesystem root.
     * @param string $local_index_file    Local index file this plan diffs against.
     * @param string $excluded_paths_path Caller-owned target exclusions file.
     * @param string $document_root_local_relative_path Document root relative to the local filesystem root.
     * @return self Open plan positioned at the initial indexing cursor.
     */
    public static function start(
        string $plan_directory,
        string $filesystem_root,
        string $local_index_file,
        string $excluded_paths_path,
        string $document_root_local_relative_path = ""
    ): self {
        $plan = new self(
            $plan_directory,
            $filesystem_root,
            $local_index_file,
            $document_root_local_relative_path
        );
        if (!@copy($excluded_paths_path, $plan->excluded_paths_file)) {
            throw new RuntimeException("Failed to copy excluded paths into the push plan: {$excluded_paths_path}");
        }
        $plan->excluded_paths = $plan->load_excluded_paths();
        $plan->fresh_local_index_handle = fopen($plan->fresh_local_index_file, "w+b");
        if (!is_resource($plan->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to open the fresh local index: {$plan->fresh_local_index_file}");
        }
        $plan->file_index_processor = FileIndexProcessor::start(
            [$plan->filesystem_root],
            $plan->filesystem_root,
            false,
            false,
            $plan->plan_directory
        );
        $plan->cursor = [
            "plan_directory" => $plan->plan_directory,
            "filesystem_root" => $plan->filesystem_root,
            "local_index_file" => $plan->local_index_file,
            "document_root_local_relative_path" => $plan->document_root_local_relative_path,
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
        if (!array_key_exists("document_root_local_relative_path", $cursor)) {
            // Older cursors had no document-root mapping and used local relative paths unchanged.
            $cursor["document_root_local_relative_path"] = "";
        }
        $position = $cursor["position"];
        if (
            ($position["phase"] === "diffing" || $position["phase"] === "complete")
            && !array_key_exists("local_paths_to_push_count", $position)
        ) {
            // Keep the plan single-pass when an older cursor has no path count.
            $position["local_paths_to_push_count"] = null;
            $cursor["position"] = $position;
        }

        $plan = new self(
            $cursor["plan_directory"],
            $cursor["filesystem_root"],
            $cursor["local_index_file"],
            $cursor["document_root_local_relative_path"]
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
     * Returns the number of local paths in the completed push plan.
     *
     * Null means the plan resumed from an older cursor without a saved count.
     */
    public function get_local_paths_to_push_count(): ?int
    {
        $position = $this->cursor["position"];
        if ($position["phase"] !== "complete") {
            throw new LogicException("Cannot count local paths to push before the push plan is complete.");
        }
        return $position["local_paths_to_push_count"];
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
        return $this->fresh_local_index_file;
    }

    /**
     * Flushes plan files before the owner persists the current cursor.
     *
     * A later process truncates bytes beyond that cursor before appending.
     */
    public function flush_pending_outputs(): void
    {
        if (
            is_resource($this->fresh_local_index_handle)
            && !fflush($this->fresh_local_index_handle)
        ) {
            throw new RuntimeException("Failed to flush the fresh local index.");
        }
        if (
            ( is_resource($this->local_paths_to_push_handle) && !fflush($this->local_paths_to_push_handle) )
            || ( is_resource($this->local_paths_to_delete_handle) && !fflush($this->local_paths_to_delete_handle) )
            || ( is_resource($this->deleted_directories_stack_handle) && !fflush($this->deleted_directories_stack_handle) )
        ) {
            throw new RuntimeException("Failed to flush a push-plan output.");
        }
    }

    /**
     * Initializes paths in the caller-owned active plan directory.
     *
     * @param string $plan_directory   Caller-owned active plan directory.
     * @param string $filesystem_root  Resolved filesystem root.
     * @param string $local_index_file Local index file this plan diffs against.
     * @param string $document_root_local_relative_path Document root relative to the local filesystem root.
     */
    private function __construct(
        string $plan_directory,
        string $filesystem_root,
        string $local_index_file,
        string $document_root_local_relative_path
    ) {
        $plan_directory = rtrim($plan_directory, "/");
        if (!is_dir($plan_directory)) {
            throw new LogicException("Cannot open a push plan without its directory: {$plan_directory}");
        }
        $this->plan_directory = $plan_directory;
        $this->set_filesystem_root($filesystem_root);
        $this->local_index_file = $local_index_file;
        $this->document_root_local_relative_path =
            rtrim($document_root_local_relative_path, "/");
        $this->local_paths_to_push = $plan_directory . "/local_paths_to_push.jsonl";
        $this->local_paths_to_delete = $plan_directory . "/local_paths_to_delete";
        $this->fresh_local_index_file = $plan_directory . "/fresh_local_index.jsonl";
        $this->excluded_paths_file = $plan_directory . "/excluded_paths.json";
        $this->deleted_directories_stack = $plan_directory . "/deleted_directories_stack.jsonl";
    }

    /**
     * Stores the resolved filesystem root represented by this plan.
     *
     * @param string $filesystem_root Filesystem root selected by the caller.
     */
    private function set_filesystem_root(string $filesystem_root): void
    {
        clearstatcache(true, $filesystem_root);
        $resolved_local_filesystem_root = realpath($filesystem_root);
        if ($resolved_local_filesystem_root === false || !is_dir($resolved_local_filesystem_root) || is_link($filesystem_root)) {
            throw new InvalidArgumentException("PushPlan requires the filesystem root to be a real directory.");
        }
        $this->filesystem_root = rtrim($resolved_local_filesystem_root, "/");
    }

    /**
     * Reopens the fresh local index at the byte offset stored with its traversal cursor.
     *
     * Any bytes appended after the cursor last stored by the caller are
     * discarded before FileIndexProcessor continues from that same step.
     */
    private function open_fresh_local_index_for_continuation(): void
    {
        /** @var IndexingCursor $cursor */
        $cursor = $this->cursor["position"];
        $this->fresh_local_index_handle = fopen($this->fresh_local_index_file, "r+b");
        if (!is_resource($this->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to reopen the fresh local index: {$this->fresh_local_index_file}");
        }
        if (!ftruncate($this->fresh_local_index_handle, $cursor["fresh_local_index_byte_offset"])) {
            throw new RuntimeException("Failed to discard uncommitted fresh-local-index bytes.");
        }
        if (fseek($this->fresh_local_index_handle, $cursor["fresh_local_index_byte_offset"]) !== 0) {
            throw new RuntimeException("Failed to seek to the fresh local index byte offset.");
        }
        $this->file_index_processor = FileIndexProcessor::resume(
            [$this->filesystem_root],
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
        $this->previous_fresh_local_index_entry_path =
            $cursor["previous_fresh_local_index_entry_path"];
        $this->local_index_lookahead_entry = null;
        $this->local_index_lookahead_entry_loaded = false;
        $this->deleted_directory_stack_entry = null;
        $this->local_paths_to_push_handle = $this->open_push_plan_output_file_at_byte_offset(
            $this->local_paths_to_push,
            $cursor["byte_offset_in_local_paths_to_push"]
        );
        $this->local_paths_to_delete_handle = $this->open_push_plan_output_file_at_byte_offset(
            $this->local_paths_to_delete,
            $cursor["byte_offset_in_local_paths_to_delete"]
        );
        $this->fresh_local_index_handle = fopen($this->fresh_local_index_file, "rb");
        if (!is_resource($this->fresh_local_index_handle)) {
            throw new RuntimeException("Failed to open the retained fresh local index: {$this->fresh_local_index_file}");
        }

        if (is_file($this->local_index_file)) {
            $this->local_index_file_handle = fopen($this->local_index_file, "rb");
            if (!is_resource($this->local_index_file_handle)) {
                throw new RuntimeException("Failed to open the local index: {$this->local_index_file}");
            }
        }
        $this->seek_index_file_to_byte_offset(
            $this->fresh_local_index_handle,
            $cursor["byte_offset_in_fresh_local_index"],
            "fresh local index"
        );
        if ($this->local_index_file_handle) {
            $this->seek_index_file_to_byte_offset(
                $this->local_index_file_handle,
                $cursor["byte_offset_in_local_index"],
                "local index"
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
     * calls. The owning caller closes the plan before using its path lists.
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
            if (!fflush($this->fresh_local_index_handle)) {
                throw new RuntimeException("Failed to flush the fresh local index.");
            }
            $this->file_index_processor->close();
            $this->close_fresh_local_index_handle();
            $this->cursor["position"] = ["phase" => "starting_diff"];
            return;
        }

        switch ($this->file_index_processor->get_step_status()) {
            case FileIndexProcessor::STATUS_INDEXED:
                foreach ($this->file_index_processor->get_index_entries() as $file_index_processor_entry) {
                    $this->append_fresh_local_index_entry($file_index_processor_entry);
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
     * Sorts the fresh local index by raw path, then starts the index diff.
     */
    private function start_index_diff(): void
    {
        if (!sort_index_file($this->fresh_local_index_file)) {
            throw new RuntimeException(
                "Failed to sort the fresh local index: {$this->fresh_local_index_file}"
            );
        }
        if (file_put_contents($this->deleted_directories_stack, "") !== 0) {
            throw new RuntimeException("Failed to initialize the deleted-directory stack: {$this->deleted_directories_stack}");
        }
        $this->cursor["position"] = [
            "phase" => "diffing",
            "byte_offset_in_fresh_local_index" => 0,
            "byte_offset_in_local_index" => 0,
            "byte_offset_in_local_paths_to_push" => 0,
            "byte_offset_in_local_paths_to_delete" => 0,
            "local_paths_to_push_count" => 0,
            "deleted_directory_stack_top_byte_offset" => null,
            "previous_fresh_local_index_entry_path" => null,
        ];
        $this->open_plan_files();
    }

    /**
     * Appends one FileIndexProcessor entry in the JSONL format consumed by the
     * index diff.
     *
     * @param array<string,mixed> $file_index_processor_entry Filesystem path details from FileIndexProcessor.
     */
    private function append_fresh_local_index_entry(array $file_index_processor_entry): void
    {
        if ($file_index_processor_entry["type"] === "other") {
            throw new RuntimeException(
                "Cannot push the unsupported local path: "
                . base64_encode($file_index_processor_entry["path"])
                . "."
            );
        }
        if (
            $file_index_processor_entry["type"] === "dir"
            && !array_key_exists("empty", $file_index_processor_entry)
        ) {
            throw new RuntimeException(
                "Could not inspect the local directory: "
                . base64_encode($file_index_processor_entry["path"])
                . "."
            );
        }

        $local_relative_path = path_remainder_under(
            $file_index_processor_entry["path"],
            $this->filesystem_root
        );
        if ($local_relative_path === null) {
            throw new LogicException("File index path is outside the filesystem root.");
        }
        $local_relative_path = ltrim($local_relative_path, "/");
        $fresh_local_index_entry = [
            "path" => base64_encode($local_relative_path),
            "ctime" => $file_index_processor_entry["ctime"],
            "size" => $file_index_processor_entry["size"],
            "type" => $file_index_processor_entry["type"],
        ];
        if ($file_index_processor_entry["type"] === "dir") {
            $fresh_local_index_entry["empty"] = $file_index_processor_entry["empty"];
        }
        $fresh_local_index_json_line = json_encode(
            $fresh_local_index_entry,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (
            fwrite($this->fresh_local_index_handle, $fresh_local_index_json_line)
            !== strlen($fresh_local_index_json_line)
        ) {
            throw new RuntimeException("Failed to write a fresh local index entry.");
        }
    }

    /**
     * Compares at most one path and updates the resulting push plan cursor.
     *
     * Exclusions suppress planned changes, not entries in the retained fresh
     * local index.
     *
     * @return bool Whether another index diff step may be performed.
     */
    private function next_index_diff_step(): bool
    {
        /** @var IndexDiffCursor $cursor */
        $cursor = $this->cursor["position"];

        $byte_offset_in_fresh_local_index = $cursor["byte_offset_in_fresh_local_index"];
        $byte_offset_in_local_index = $cursor["byte_offset_in_local_index"];
        $local_paths_to_push_count = $cursor["local_paths_to_push_count"];
        $deleted_directory_stack_top_byte_offset = $cursor["deleted_directory_stack_top_byte_offset"];

        if (!$this->fresh_local_index_entry_loaded) {
            $this->fresh_local_index_entry = $this->read_next_index_entry($this->fresh_local_index_handle);
            $this->fresh_local_index_entry_loaded = true;
        }
        if (!$this->local_index_lookahead_entry_loaded) {
            $this->local_index_lookahead_entry = $this->read_next_index_entry(
                $this->local_index_file_handle
            );
            $this->local_index_lookahead_entry_loaded = true;
        }
        $fresh_local_index_entry = $this->fresh_local_index_entry;
        $local_index_entry = $this->local_index_lookahead_entry;

        if ($fresh_local_index_entry !== null || $local_index_entry !== null) {
            // Base64 does not preserve byte order ('0' sorts before 'A'
            // in ASCII but encodes a higher value), so ordering uses the
            // decoded path bytes.
            if ($local_index_entry === null) {
                $path_comparison = -1;
            } elseif ($fresh_local_index_entry === null) {
                $path_comparison = 1;
            } else {
                $path_comparison = strcmp($fresh_local_index_entry["path"], $local_index_entry["path"]);
            }

            $fresh_local_index_entry_shape = null;
            if ($path_comparison <= 0) {
                $fresh_local_index_entry_shape = $this->index_entry_shape($fresh_local_index_entry);
            }

            $local_index_entry_shape = null;
            if ($path_comparison >= 0) {
                $local_index_entry_shape = $this->index_entry_shape($local_index_entry);

                // Byte sorting can put a sibling such as `a-other` before
                // `a/child`. Keep a deleted root while local index entries
                // remain within that root's descendants.
                if ($this->deleted_directory_stack_entry !== null) {
                    $descendant_prefix = $this->deleted_directory_stack_entry["path"] . "/";
                    if (
                        !path_is_within_root(
                            $local_index_entry["path"],
                            $this->deleted_directory_stack_entry["path"]
                        )
                        && strcmp($local_index_entry["path"], $descendant_prefix) > 0
                    ) {
                        $deleted_directory_stack_top_byte_offset = $this->deleted_directory_stack_entry["previous_byte_offset"];
                        $this->deleted_directory_stack_entry = $this->read_deleted_directory_stack_entry(
                            $deleted_directory_stack_top_byte_offset
                        );
                    }
                }
            }

            if ($path_comparison < 0) {
                // New files, symlinks, and empty directories need to be pushed.
                $fresh_local_index_entry_replaces_local_subtree = $local_index_entry !== null
                    && $local_index_entry["path"] !== $fresh_local_index_entry["path"]
                    && path_is_within_root(
                        $local_index_entry["path"],
                        $fresh_local_index_entry["path"]
                    );
                if (
                    $fresh_local_index_entry_replaces_local_subtree
                    && !$this->path_conflicts_with_excluded_paths($fresh_local_index_entry["path"])
                    && !$this->deleted_directory_stack_covers_path(
                        $fresh_local_index_entry["path"],
                        $this->deleted_directory_stack_entry
                    )
                ) {
                    $this->append_local_path_to_delete($fresh_local_index_entry["path"]);
                    $deleted_directory_stack_top_byte_offset =
                        $this->append_deleted_directory_stack_entry(
                            $fresh_local_index_entry["path"],
                            $deleted_directory_stack_top_byte_offset
                        );
                }
                if (!$this->path_conflicts_with_excluded_paths($fresh_local_index_entry["path"])) {
                    $this->append_local_path_to_push($fresh_local_index_entry);
                    if ($local_paths_to_push_count !== null) {
                        ++$local_paths_to_push_count;
                    }
                }
            } elseif ($path_comparison > 0) {
                $local_empty_directory_is_implied_by_fresh_descendant =
                    $local_index_entry_shape === "empty_directory"
                    && $this->fresh_index_contains_path_or_descendant(
                        $local_index_entry["path"]
                    );
                $local_path_to_delete = $this->local_path_to_delete(
                    $local_index_entry["path"]
                );
                // A sparse index entry derives one deleted root, covering its
                // later descendant entries.
                if (
                    !$local_empty_directory_is_implied_by_fresh_descendant
                    && !$this->path_conflicts_with_excluded_paths($local_path_to_delete)
                    && !$this->deleted_directory_stack_covers_path(
                        $local_index_entry["path"],
                        $this->deleted_directory_stack_entry
                    )
                ) {
                    $this->append_local_path_to_delete($local_path_to_delete);
                    if ($local_path_to_delete !== $local_index_entry["path"]) {
                        $deleted_directory_stack_top_byte_offset = $this->append_deleted_directory_stack_entry(
                            $local_path_to_delete,
                            $deleted_directory_stack_top_byte_offset
                        );
                    }
                }
            } else {
                $fresh_local_index_entry_is_file_or_symlink = $fresh_local_index_entry_shape === "file"
                    || $fresh_local_index_entry_shape === "symlink";
                $local_index_entry_is_file_or_symlink = $local_index_entry_shape === "file"
                    || $local_index_entry_shape === "symlink";
                $empty_directory_needs_push = $fresh_local_index_entry_shape === "empty_directory"
                    && $local_index_entry_shape !== "empty_directory";
                // File and symlink changes are defined by type, ctime, and
                // size. Other index values do not select a path for upload.
                $changed_file_or_symlink_needs_push = $fresh_local_index_entry_is_file_or_symlink
                    && (
                        $fresh_local_index_entry["ctime"] !== $local_index_entry["ctime"]
                        || $fresh_local_index_entry["size"] !== $local_index_entry["size"]
                        || $fresh_local_index_entry["type"] !== $local_index_entry["type"]
                    );
                $needs_delete =
                    $fresh_local_index_entry_is_file_or_symlink !== $local_index_entry_is_file_or_symlink;
                $needs_push = $empty_directory_needs_push
                    || $changed_file_or_symlink_needs_push;
                $path_is_excluded = $this->path_conflicts_with_excluded_paths($fresh_local_index_entry["path"]);

                if (
                    $needs_delete
                    && !$path_is_excluded
                    && !$this->deleted_directory_stack_covers_path(
                        $local_index_entry["path"],
                        $this->deleted_directory_stack_entry
                    )
                ) {
                    $this->append_local_path_to_delete($local_index_entry["path"]);
                }
                if ($needs_push && !$path_is_excluded) {
                    $this->append_local_path_to_push($fresh_local_index_entry);
                    if ($local_paths_to_push_count !== null) {
                        ++$local_paths_to_push_count;
                    }
                }
            }

            if ($path_comparison <= 0) {
                $byte_offset_in_fresh_local_index = ftell($this->fresh_local_index_handle);
                $this->previous_fresh_local_index_entry_path =
                    $fresh_local_index_entry["path"];
                $this->fresh_local_index_entry = $this->read_next_index_entry($this->fresh_local_index_handle);
            }
            if ($path_comparison >= 0) {
                $byte_offset_in_local_index = ftell($this->local_index_file_handle);
                $this->local_index_lookahead_entry = $this->read_next_index_entry(
                    $this->local_index_file_handle
                );
            }
        }

        $complete = $this->fresh_local_index_entry === null
            && $this->local_index_lookahead_entry === null;
        if ($complete) {
            if (
                !fflush($this->local_paths_to_push_handle)
                || !fflush($this->local_paths_to_delete_handle)
                || !fflush($this->deleted_directories_stack_handle)
            ) {
                throw new RuntimeException("Failed to flush a push-plan output.");
            }
            $deleted_directory_stack_top_byte_offset = null;
            $this->deleted_directory_stack_entry = null;
        }
        $cursor_after_step = $complete
            ? [
                "phase" => "complete",
                "local_paths_to_push_count" => $local_paths_to_push_count,
            ]
            : [
                "phase" => "diffing",
                "byte_offset_in_fresh_local_index" => $byte_offset_in_fresh_local_index,
                "byte_offset_in_local_index" => $byte_offset_in_local_index,
                "byte_offset_in_local_paths_to_push" => ftell($this->local_paths_to_push_handle),
                "byte_offset_in_local_paths_to_delete" => ftell($this->local_paths_to_delete_handle),
                "local_paths_to_push_count" => $local_paths_to_push_count,
                "deleted_directory_stack_top_byte_offset" => $deleted_directory_stack_top_byte_offset,
                "previous_fresh_local_index_entry_path" =>
                    $this->previous_fresh_local_index_entry_path,
            ];
        $this->cursor["position"] = $cursor_after_step;
        return !$complete;
    }

    /**
     * Closes every plan file handle and prevents further plan steps.
     *
     * The cursor returned to the caller and the plan-owned files remain
     * available to resume the plan or save the completed fresh local index
     * after a successful push.
     */
    public function close(): void
    {
        if (isset($this->file_index_processor)) {
            $this->file_index_processor->close();
        }
        $this->close_fresh_local_index_handle();
        if (is_resource($this->local_index_file_handle)) {
            fclose($this->local_index_file_handle);
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
        $this->local_index_file_handle = null;
        $this->local_paths_to_push_handle = null;
        $this->local_paths_to_delete_handle = null;
        $this->deleted_directories_stack_handle = null;
        $this->fresh_local_index_entry = null;
        $this->fresh_local_index_entry_loaded = false;
        $this->previous_fresh_local_index_entry_path = null;
        $this->local_index_lookahead_entry = null;
        $this->local_index_lookahead_entry_loaded = false;
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
     * @param string $push_plan_output_file Path to the push-plan output file.
     * @param int    $byte_offset           Durable byte offset at which writing resumes.
     * @return resource Writable output handle positioned at the durable offset.
     */
    private function open_push_plan_output_file_at_byte_offset(
        string $push_plan_output_file,
        int $byte_offset
    )
    {
        $push_plan_output_file_handle = fopen($push_plan_output_file, "c+b");
        if (!$push_plan_output_file_handle) {
            throw new RuntimeException("Failed to open push plan output for writing: {$push_plan_output_file}");
        }
        if (
            !ftruncate($push_plan_output_file_handle, $byte_offset)
            || fseek($push_plan_output_file_handle, $byte_offset) !== 0
        ) {
            fclose($push_plan_output_file_handle);
            throw new RuntimeException(
                "Failed to truncate and seek push plan output "
                . "{$push_plan_output_file} to byte {$byte_offset}."
            );
        }
        return $push_plan_output_file_handle;
    }

    /**
     * Positions an index file handle at its durable byte offset.
     *
     * The plan owns immutable index files, and records their consumed byte
     * offsets only after finishing the corresponding step.
     *
     * @param resource $index_file_handle Open index file handle to position.
     * @param int      $byte_offset       Durable byte offset saved in the cursor.
     * @param string   $index_description Human-readable index name used in failures.
     */
    private function seek_index_file_to_byte_offset(
        $index_file_handle,
        int $byte_offset,
        string $index_description
    ): void
    {
        if (fseek($index_file_handle, $byte_offset) !== 0) {
            throw new RuntimeException("Failed to seek the {$index_description} to byte {$byte_offset}.");
        }
    }

    /**
     * Returns the highest deleted directory without a fresh entry below it.
     *
     * Only the previous fresh entry and the current lookahead can neighbor a
     * path in byte order, so this derives one subtree root without retaining
     * the tree.
     */
    private function local_path_to_delete(string $local_relative_path): string
    {
        $local_relative_path_components = wp_unix_path_segments($local_relative_path);
        $candidate_local_relative_path_components = [];
        for ($index = 0, $component_count = count($local_relative_path_components) - 1; $index < $component_count; ++$index) {
            $candidate_local_relative_path_components[] = $local_relative_path_components[$index];
            $candidate_local_relative_path = wp_join_unix_paths(
                ...$candidate_local_relative_path_components
            );
            if (!$this->fresh_index_contains_path_or_descendant($candidate_local_relative_path)) {
                return $candidate_local_relative_path;
            }
        }
        return $local_relative_path;
    }

    /**
     * Checks the adjacent fresh entries for a path or one of its descendants.
     */
    private function fresh_index_contains_path_or_descendant(string $local_relative_path): bool
    {
        // Use an invalid path that cannot match an indexed local relative path
        // so both comparisons below always receive strings.
        $previous_fresh_local_index_entry_path =
            $this->previous_fresh_local_index_entry_path ?? "\0";
        $next_fresh_local_index_entry_path =
            $this->fresh_local_index_entry["path"] ?? "\0";

        return path_is_within_root(
            $previous_fresh_local_index_entry_path,
            $local_relative_path
        ) || path_is_within_root(
            $next_fresh_local_index_entry_path,
            $local_relative_path
        );
    }

    /**
     * Returns the logical entry kind used by the transition table.
     *
     * @param array $index_entry {
     *     Parsed index entry.
     *
     *     @type string $path  Decoded filesystem path.
     *     @type string $type  Entry type: `file`, `link`, or `dir`.
     *     @type int    $ctime Indexed change timestamp.
     *     @type int    $size  Indexed size used for change detection.
     *     @type bool   $empty Whether a directory is empty. Present for directory entries.
     * }
     * @phpstan-param array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool} $index_entry
     * @return 'file'|'symlink'|'empty_directory'
     */
    private function index_entry_shape(array $index_entry): string
    {
        if ($index_entry["type"] === "file") {
            return "file";
        }
        if ($index_entry["type"] === "link") {
            return "symlink";
        }
        return "empty_directory";
    }

    /**
     * Appends one path and its planned type, size, and ctime to the JSONL list.
     *
     * Base64 keeps arbitrary filesystem path bytes representable in JSON.
     *
     * @param array $fresh_local_index_entry {
     *     Fresh local index entry selected for push.
     *
     *     @type string $path  Decoded filesystem path.
     *     @type string $type  Entry type: `file`, `link`, or `dir`.
     *     @type int    $size  Indexed size used for change detection.
     *     @type int    $ctime Indexed change timestamp.
     * }
     * @phpstan-param array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool} $fresh_local_index_entry
     */
    private function append_local_path_to_push(array $fresh_local_index_entry): void
    {
        $local_path_to_push_json_line = json_encode(
            [
                "path" => base64_encode($fresh_local_index_entry["path"]),
                "type" => $fresh_local_index_entry["type"] === "link"
                    ? "symlink"
                    : ($fresh_local_index_entry["type"] === "dir" ? "directory" : "file"),
                "size" => $fresh_local_index_entry["size"],
                "ctime" => $fresh_local_index_entry["ctime"],
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (
            fwrite($this->local_paths_to_push_handle, $local_path_to_push_json_line)
            !== strlen($local_path_to_push_json_line)
        ) {
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
        $document_root_relative_path =
            $this->local_relative_path_to_document_root_relative_path($path);
        if ($document_root_relative_path === null) {
            return;
        }
        $document_root_relative_path_with_nul = $document_root_relative_path . "\0";
        if (fwrite($this->local_paths_to_delete_handle, $document_root_relative_path_with_nul) !== strlen($document_root_relative_path_with_nul)) {
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
        return $entry !== null
            && $path !== $entry["path"]
            && path_is_within_root($path, $entry["path"]);
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
        $document_root_relative_path =
            $this->local_relative_path_to_document_root_relative_path($path);
        if ($document_root_relative_path === null) {
            return true;
        }
        foreach ($this->excluded_paths as $excluded_path) {
            if (
                path_is_within_root($document_root_relative_path, $excluded_path)
                || path_is_within_root($excluded_path, $document_root_relative_path)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the document-root-relative path, or null when the local
     * relative path is outside the document root.
     */
    private function local_relative_path_to_document_root_relative_path(
        string $local_relative_path
    ): ?string {
        if ($this->document_root_local_relative_path === "") {
            return $local_relative_path;
        }
        $path_remainder = path_remainder_under(
            $local_relative_path,
            $this->document_root_local_relative_path
        );
        if ($path_remainder === null) {
            return null;
        }
        return $path_remainder === ""
            ? ""
            : substr($path_remainder, 1);
    }

    /**
     * Loads the caller-owned exclusions used throughout one planning run.
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
     * Reads and decodes the next index entry.
     *
     * A null index file handle represents a missing local index.
     * The indexer's entry schema is trusted; only file reads, JSON decoding,
     * and base64 path decoding are handled here as fallible operations.
     *
     * @param resource|null $index_file_handle Open index file handle, or null when no local index exists.
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
    private function read_next_index_entry($index_file_handle): ?array
    {
        if (!$index_file_handle) {
            return null;
        }
        $index_entry_json = fgets($index_file_handle);
        if ($index_entry_json === false) {
            if (!feof($index_file_handle)) {
                throw new RuntimeException("Failed to read an index line.");
            }
            return null;
        }

        try {
            $index_entry = json_decode($index_entry_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Unexpected index line, it is not valid JSON: " . substr($index_entry_json, 0, 120),
                0,
                $exception
            );
        }
        /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool} $index_entry */
        $local_relative_path = base64_decode($index_entry["path"], true);
        if ($local_relative_path === false) {
            throw new RuntimeException(
                "The index path is not valid base64: " . substr($index_entry_json, 0, 120)
            );
        }
        $index_entry["path"] = $local_relative_path;
        return $index_entry;
    }

}
