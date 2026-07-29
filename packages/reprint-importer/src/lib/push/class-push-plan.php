<?php

use function Reprint\Importer\compare_local_index_paths;
use function Reprint\Importer\read_index_entry;
use function Reprint\Importer\write_index_entry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.

/**
 * Internal bounded local-index and change planner.
 *
 * PushPlan builds a path-sorted fresh local index, then diffs it against the
 * local index at the caller-supplied path. It writes durable lists of local
 * paths to push and local paths to delete without accumulating an index or
 * path list in memory.
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
 * ctime is machine-local, so the local index must describe the same local
 * machine. files-pull and committed files-push operations update the local
 * index; PushFilesSender and files-diff each supply its path and prevent it
 * from changing during the plan lifecycle. File and symlink changes are
 * determined by type, ctime, and size.
 * Directory changes use the indexer's empty-directory marker; non-empty
 * directories are represented by their descendants.
 *
 * With no local index, every indexed file, symlink, and empty directory is
 * selected, and no deletion can be detected. Paths skipped by the indexer's
 * default rules are absent from the fresh local index. Excluded paths are
 * omitted from both path lists but remain in the fresh local index.
 *
 * ## Durability and memory
 *
 * Each indexing step advances one FileIndexProcessor traversal event and
 * flushes any appended JSONL bytes before updating the traversal cursor and
 * committed byte offset returned to the caller.
 * A separate step starts the index diff. Each diff step compares at most one
 * path represented by either index and flushes only the output changed by that
 * step before updating its next cursor. The caller stores that cursor before
 * returning from its own step. `resume()` discards bytes beyond saved offsets,
 * so an interrupted step cannot leave duplicate durable entries.
 *
 * PushPlan retains the next entry from each index and one deleted directory
 * whose descendants need no separate deletion. It never loads an index or path
 * list in full.
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type IndexingCursor array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}
 * @phpstan-type StartingDiffCursor array{phase:'starting_diff'}
 * @phpstan-type IndexDiffCursor array{phase:'diffing',byte_offset_in_fresh_index:int,byte_offset_in_local_index:int,byte_offset_in_local_paths_to_push:int,byte_offset_in_local_paths_to_delete:int,deleted_directory_path_b64:string|null}
 * @phpstan-type CompleteCursor array{phase:'complete'}
 * @phpstan-type PushPlanCursor IndexingCursor|StartingDiffCursor|IndexDiffCursor|CompleteCursor
 */
class PushPlan
{
    /** @var string Canonical local tree root inspected while building the fresh local index. */
    private string $local_tree_root;

    /** @var string Caller-owned active plan directory. */
    private string $plan_directory;

    /** @var string Local index path supplied by the caller. */
    private string $local_index_path;

    /** @var string JSONL file of local paths to push. */
    private string $local_paths_to_push;

    /** @var string Raw NUL-delimited local paths to delete. */
    private string $local_paths_to_delete;

    /** @var string Plan-owned fresh local index. */
    private string $fresh_local_index;

    /** @var string Excluded paths file supplied by the caller. */
    private string $excluded_paths_path;

    /** @var list<string> Decoded excluded paths. */
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

    /** @var array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool}|null */
    private ?array $local_index_lookahead_entry = null;

    /** @var bool Whether $local_index_lookahead_entry has been read, including EOF. */
    private bool $local_index_lookahead_entry_loaded = false;

    /** @var string|null Deleted directory whose descendants need no separate deletion. */
    private ?string $deleted_directory_path = null;

    /** @var resource|null Open fresh local index retained during indexing or the index diff. */
    private $fresh_local_index_handle = null;
    /** @var resource|null */
    private $local_index_handle = null;
    /** @var resource|null */
    private $local_paths_to_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_delete_handle = null;
    /**
     * Starts a push plan by opening a fresh local index traversal.
     *
     * @param string $plan_directory              Caller-owned active plan directory.
     * @param string $local_tree_root              Canonical local tree root.
     * @param string $local_index_path             Local index this plan diffs against.
     * @param string $excluded_paths_path          Excluded paths file.
     * @return self Open plan positioned at the initial indexing cursor.
     */
    public static function start(
        string $plan_directory,
        string $local_tree_root,
        string $local_index_path,
        string $excluded_paths_path
    ): self {
        $plan = new self(
            $plan_directory,
            $local_tree_root,
            $local_index_path,
            $excluded_paths_path
        );
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
            "phase" => "indexing",
            "file_index_cursor" => $plan->file_index_processor->get_cursor(),
            "fresh_local_index_byte_offset" => 0,
        ];
        return $plan;
    }

    /**
     * Resumes the unfinished push plan retained in local push state.
     *
     * Reopens only the processor and files required by the cursor's current
     * internal phase.
     *
     * @param string $plan_directory      Caller-owned active plan directory.
     * @param string $local_tree_root     Canonical local tree root.
     * @param string $local_index_path    Local index this plan diffs against.
     * @param string $excluded_paths_path Excluded paths file.
     * @phpstan-param PushPlanCursor $cursor Cursor previously returned by get_cursor().
     * @return self Open plan positioned at its last durable cursor.
     */
    public static function resume(
        string $plan_directory,
        string $local_tree_root,
        string $local_index_path,
        string $excluded_paths_path,
        array $cursor
    ): self {
        $plan = new self(
            $plan_directory,
            $local_tree_root,
            $local_index_path,
            $excluded_paths_path
        );
        $plan->cursor = $cursor;
        if ($cursor["phase"] !== "complete") {
            $plan->excluded_paths = $plan->load_excluded_paths();
        }
        if ($cursor["phase"] === "indexing") {
            $plan->open_fresh_local_index_for_continuation();
        } elseif ($cursor["phase"] === "diffing") {
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
     * Reads the completed local paths-to-push list.
     *
     * @return Generator Completed plan entries.
     * @phpstan-return Generator<int,array{path_b64:string,type:'file'|'dir'|'link',size:int,ctime:int},mixed,void>
     */
    public function read_planned_local_paths_to_push(): Generator
    {
        $local_paths_to_push_handle = fopen($this->local_paths_to_push, 'rb');
        if (!is_resource($local_paths_to_push_handle)) {
            throw new RuntimeException('Failed to open the completed local paths-to-push list.');
        }
        try {
            while (true) {
                $line = fgets($local_paths_to_push_handle);
                if ($line === false) {
                    if (!feof($local_paths_to_push_handle)) {
                        throw new RuntimeException('Failed to read the completed local paths-to-push list.');
                    }
                    return;
                }
                /** @var array{path_b64:string,type:'file'|'dir'|'link',size:int,ctime:int} $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                yield $entry;
            }
        } finally {
            fclose($local_paths_to_push_handle);
        }
    }

    /**
     * Reads the completed local paths-to-delete list.
     *
     * @return Generator Completed local paths to delete.
     * @phpstan-return Generator<int,string,mixed,void>
     */
    public function read_planned_local_paths_to_delete(): Generator
    {
        $local_paths_to_delete_handle = fopen($this->local_paths_to_delete, 'rb');
        if (!is_resource($local_paths_to_delete_handle)) {
            throw new RuntimeException('Failed to open the completed local paths-to-delete list.');
        }
        try {
            while (true) {
                $local_path_to_delete = stream_get_line($local_paths_to_delete_handle, 1048576, "\0");
                if ($local_path_to_delete === false) {
                    if (!feof($local_paths_to_delete_handle)) {
                        throw new RuntimeException('Failed to read the completed local paths-to-delete list.');
                    }
                    return;
                }
                yield $local_path_to_delete;
            }
        } finally {
            fclose($local_paths_to_delete_handle);
        }
    }

    /**
     * Initializes paths in the caller-owned active plan directory.
     *
     * @param string $plan_directory              Caller-owned active plan directory.
     * @param string $local_tree_root              Canonical local tree root.
     * @param string $local_index_path    Local index this plan diffs against.
     * @param string $excluded_paths_path Excluded paths file.
     */
    private function __construct(
        string $plan_directory,
        string $local_tree_root,
        string $local_index_path,
        string $excluded_paths_path
    ) {
        $plan_directory = rtrim($plan_directory, "/");
        if (!is_dir($plan_directory)) {
            throw new LogicException("Cannot open a push plan without its directory: {$plan_directory}");
        }
        $this->plan_directory = $plan_directory;
        $this->set_local_tree_root($local_tree_root);
        $this->local_index_path = $local_index_path;
        $this->local_paths_to_push = $plan_directory . "/local_paths_to_push.jsonl";
        $this->local_paths_to_delete = $plan_directory . "/local_paths_to_delete";
        $this->fresh_local_index = $plan_directory . "/fresh_local_index.jsonl";
        $this->excluded_paths_path = $excluded_paths_path;
    }

    /**
     * Stores the canonical root of the local tree represented by this plan.
     *
     * @param string $local_tree_root Local tree root selected by the caller.
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
     * Any bytes appended after the cursor last stored by the caller are
     * discarded before FileIndexProcessor continues from that same step.
     */
    private function open_fresh_local_index_for_continuation(): void
    {
        /** @var IndexingCursor $cursor */
        $cursor = $this->cursor;
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
        $cursor = $this->cursor;
        $this->fresh_local_index_entry = null;
        $this->fresh_local_index_entry_loaded = false;
        $this->local_index_lookahead_entry = null;
        $this->local_index_lookahead_entry_loaded = false;
        $this->deleted_directory_path =
            $cursor["deleted_directory_path_b64"] === null
                ? null
                : base64_decode($cursor["deleted_directory_path_b64"]);
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

        if (is_file($this->local_index_path)) {
            $this->local_index_handle = fopen($this->local_index_path, "rb");
            if (!is_resource($this->local_index_handle)) {
                throw new RuntimeException("Failed to open the local index: {$this->local_index_path}");
            }
        }
        $this->seek_to_cursor(
            $this->fresh_local_index_handle,
            $cursor["byte_offset_in_fresh_index"],
            "fresh local index"
        );
        if ($this->local_index_handle) {
            $this->seek_to_cursor(
                $this->local_index_handle,
                $cursor["byte_offset_in_local_index"],
                "local index"
            );
        }
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
        if ($this->cursor["phase"] === "complete") {
            return false;
        }
        if ($this->closed) {
            throw new LogicException("Cannot take a push plan step after close().");
        }

        switch ($this->cursor["phase"]) {
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
            $this->cursor = ["phase" => "starting_diff"];
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
        $this->cursor = [
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
        $this->cursor = [
            "phase" => "diffing",
            "byte_offset_in_fresh_index" => 0,
            "byte_offset_in_local_index" => 0,
            "byte_offset_in_local_paths_to_push" => 0,
            "byte_offset_in_local_paths_to_delete" => 0,
            "deleted_directory_path_b64" => null,
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
                "Cannot push the unsupported local path: "
                . base64_encode($index_entry["path"])
                . "."
            );
        }
        if (
            $index_entry["type"] === "dir"
            && !array_key_exists("empty", $index_entry)
        ) {
            throw new RuntimeException(
                "Could not inspect the local directory: "
                . base64_encode($index_entry["path"])
                . "."
            );
        }
        $local_path = substr($index_entry["path"], strlen($this->local_tree_root) + 1);
        $index_entry["path"] = $local_path;
        write_index_entry($this->fresh_local_index_handle, $index_entry);
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
        $cursor = $this->cursor;

        $byte_offset_in_fresh_index = $cursor["byte_offset_in_fresh_index"];
        $byte_offset_in_local_index = $cursor["byte_offset_in_local_index"];
        $local_paths_to_push_changed = false;
        $local_paths_to_delete_changed = false;

        if (!$this->fresh_local_index_entry_loaded) {
            $this->fresh_local_index_entry =
                read_index_entry($this->fresh_local_index_handle);
            $this->fresh_local_index_entry_loaded = true;
        }
        if (!$this->local_index_lookahead_entry_loaded) {
            $this->local_index_lookahead_entry = read_index_entry(
                $this->local_index_handle
            );
            $this->local_index_lookahead_entry_loaded = true;
        }
        $fresh_local_index_entry = $this->fresh_local_index_entry;
        $local_index_entry = $this->local_index_lookahead_entry;

        if (
            $fresh_local_index_entry !== null
            || $local_index_entry !== null
        ) {
            // Base64 does not preserve byte order ('0' sorts before 'A'
            // in ASCII but encodes a higher value), so ordering uses the
            // decoded filesystem path components.
            if ($local_index_entry === null) {
                $path_comparison = -1;
            } elseif ($fresh_local_index_entry === null) {
                $path_comparison = 1;
            } else {
                $path_comparison = compare_local_index_paths(
                    $fresh_local_index_entry["path"],
                    $local_index_entry["path"]
                );
            }

            $fresh_is_non_empty_directory = false;
            if ($path_comparison <= 0) {
                $fresh_is_non_empty_directory =
                    $fresh_local_index_entry["type"] === "dir"
                    && !$fresh_local_index_entry["empty"];
            }

            $local_index_is_non_empty_directory = false;
            if ($path_comparison >= 0) {
                $local_index_is_non_empty_directory =
                    $local_index_entry["type"] === "dir"
                    && !$local_index_entry["empty"];

                /*
                 * Directory entries sort immediately before one contiguous
                 * run of descendants. Once this entry leaves the deleted
                 * directory, no later entry can return to it.
                 */
                if (
                    $this->deleted_directory_path !== null
                    && strpos(
                        $local_index_entry["path"],
                        $this->deleted_directory_path . "/"
                    ) !== 0
                ) {
                    $this->deleted_directory_path = null;
                }
            }

            if ($path_comparison < 0) {
                // New files, symlinks, and empty directories need to be
                // pushed. A new non-empty directory is represented by its
                // descendants.
                if (
                    !$fresh_is_non_empty_directory
                    && !$this->path_conflicts_with_excluded_paths(
                        $fresh_local_index_entry["path"]
                    )
                ) {
                    $this->append_local_path_to_push(
                        $fresh_local_index_entry
                    );
                    $local_paths_to_push_changed = true;
                }
            } elseif ($path_comparison > 0) {
                // A deleted non-empty directory emits one root. Its later
                // descendant entries are already covered by that path.
                if (
                    !$this->path_conflicts_with_excluded_paths(
                        $local_index_entry["path"]
                    )
                    && $this->deleted_directory_path === null
                ) {
                    $this->append_local_path_to_delete(
                        $local_index_entry["path"]
                    );
                    $local_paths_to_delete_changed = true;
                    if ($local_index_is_non_empty_directory) {
                        $this->deleted_directory_path =
                            $local_index_entry["path"];
                    }
                }
            } else {
                $fresh_is_file_or_link =
                    $fresh_local_index_entry["type"] === "file"
                    || $fresh_local_index_entry["type"] === "link";
                $local_index_is_file_or_link =
                    $local_index_entry["type"] === "file"
                    || $local_index_entry["type"] === "link";
                $fresh_is_empty_directory =
                    $fresh_local_index_entry["type"] === "dir"
                    && $fresh_local_index_entry["empty"];
                $local_index_is_empty_directory =
                    $local_index_entry["type"] === "dir"
                    && $local_index_entry["empty"];
                $non_empty_directory_becomes_empty =
                    $fresh_is_empty_directory
                    && $local_index_is_non_empty_directory;
                $empty_directory_needs_push =
                    $fresh_is_empty_directory
                    && !$local_index_is_empty_directory;
                // File and symlink changes are defined by type, ctime, and
                // size. Other index values do not select a path for upload.
                $changed_file_or_link_needs_push = $fresh_is_file_or_link
                    && (
                        $fresh_local_index_entry["ctime"]
                            !== $local_index_entry["ctime"]
                        || $fresh_local_index_entry["size"]
                            !== $local_index_entry["size"]
                        || $fresh_local_index_entry["type"]
                            !== $local_index_entry["type"]
                    );
                $needs_delete =
                    $fresh_is_file_or_link !== $local_index_is_file_or_link
                    || $non_empty_directory_becomes_empty;
                $needs_push = $empty_directory_needs_push
                    || $changed_file_or_link_needs_push;
                $path_is_excluded =
                    $this->path_conflicts_with_excluded_paths(
                        $fresh_local_index_entry["path"]
                    );

                if (
                    $needs_delete
                    && !$path_is_excluded
                    && $this->deleted_directory_path === null
                ) {
                    $this->append_local_path_to_delete(
                        $local_index_entry["path"]
                    );
                    $local_paths_to_delete_changed = true;
                    if ($local_index_is_non_empty_directory) {
                        $this->deleted_directory_path =
                            $local_index_entry["path"];
                    }
                }
                if ($needs_push && !$path_is_excluded) {
                    $this->append_local_path_to_push(
                        $fresh_local_index_entry
                    );
                    $local_paths_to_push_changed = true;
                }
            }

            if ($path_comparison <= 0) {
                $byte_offset_in_fresh_index = ftell($this->fresh_local_index_handle);
                $this->fresh_local_index_entry =
                    read_index_entry($this->fresh_local_index_handle);
            }
            if ($path_comparison >= 0) {
                $byte_offset_in_local_index = ftell($this->local_index_handle);
                $this->local_index_lookahead_entry = read_index_entry(
                    $this->local_index_handle
                );
            }
        }

        if (
            ( $local_paths_to_push_changed && !fflush($this->local_paths_to_push_handle) )
            || ( $local_paths_to_delete_changed && !fflush($this->local_paths_to_delete_handle) )
        ) {
            throw new RuntimeException("Failed to flush a push-plan output.");
        }

        $complete = $this->fresh_local_index_entry === null
            && $this->local_index_lookahead_entry === null;
        if ($complete) {
            $this->deleted_directory_path = null;
        }
        $cursor_after_step = $complete
            ? ["phase" => "complete"]
            : [
                "phase" => "diffing",
                "byte_offset_in_fresh_index" => $byte_offset_in_fresh_index,
                "byte_offset_in_local_index" => $byte_offset_in_local_index,
                "byte_offset_in_local_paths_to_push" => ftell($this->local_paths_to_push_handle),
                "byte_offset_in_local_paths_to_delete" => ftell($this->local_paths_to_delete_handle),
                "deleted_directory_path_b64" =>
                    $this->deleted_directory_path === null
                        ? null
                        : base64_encode($this->deleted_directory_path),
            ];
        $this->cursor = $cursor_after_step;
        return !$complete;
    }

    /**
     * Closes every plan file handle and prevents further plan steps.
     *
     * The cursor returned to the caller and the plan-owned files remain
     * available until the caller no longer needs them.
     */
    public function close(): void
    {
        if (isset($this->file_index_processor)) {
            $this->file_index_processor->close();
        }
        $this->close_fresh_local_index_handle();
        if (is_resource($this->local_index_handle)) {
            fclose($this->local_index_handle);
        }
        if (is_resource($this->local_paths_to_push_handle)) {
            fclose($this->local_paths_to_push_handle);
        }
        if (is_resource($this->local_paths_to_delete_handle)) {
            fclose($this->local_paths_to_delete_handle);
        }
        $this->local_index_handle = null;
        $this->local_paths_to_push_handle = null;
        $this->local_paths_to_delete_handle = null;
        $this->fresh_local_index_entry = null;
        $this->fresh_local_index_entry_loaded = false;
        $this->local_index_lookahead_entry = null;
        $this->local_index_lookahead_entry_loaded = false;
        $this->deleted_directory_path = null;
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
                "path_b64" => base64_encode($entry["path"]),
                "type" => $entry["type"],
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
     * Loads the excluded paths used throughout one planning run.
     *
     * @return list<string> Decoded document-root-relative excluded paths.
     */
    private function load_excluded_paths(): array
    {
        $contents = file_get_contents($this->excluded_paths_path);
        if (!is_string($contents)) {
            throw new RuntimeException(
                "Failed to read excluded paths: {$this->excluded_paths_path}"
            );
        }
        $excluded_paths_b64 = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        /** @var list<string> $excluded_paths_b64 */
        $excluded_paths = [];
        foreach ($excluded_paths_b64 as $excluded_path_b64) {
            /** @var string $excluded_path */
            $excluded_path = base64_decode($excluded_path_b64);
            $excluded_paths[] = $excluded_path;
        }
        return $excluded_paths;
    }

}
