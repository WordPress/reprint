<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_path_segments;
use function WordPress\Reprint\Exporter\path_is_descendant_of;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;

require_once __DIR__ . '/class-file-index-diff-processor.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint streaming classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing streaming classes.

/**
 * Plans a file-sync patch from two indexed file trees.
 *
 * The patch base index describes the state before the patch. The patch result
 * index describes the state after it. Both indexes use the same path
 * coordinates and are sorted by decoded path bytes. The caller chooses these
 * indexes based on the sync intent:
 *
 * - To make two file trees identical, compare the current destination index
 *   with the current source index.
 * - To copy only source changes, compare the source index saved at the last
 *   sync with the current source index. Apply that patch to the destination.
 *
 * The planner does not know the sync intent. It receives the two indexes
 * already chosen by its caller.
 *
 * FileIndexDiffProcessor aligns their entries and labels each path. This
 * planner turns those labels into one operation for the current path:
 *
 * - `copy` copies the patch-result entry.
 * - `delete` deletes a path from the patch base.
 * - `replace` deletes a path, then copies the patch-result entry there.
 *
 * One path may need both actions. Replacing a directory tree with a file, for
 * example, deletes the tree and copies the file. The planner combines
 * a removed subtree into one deletion when the indexes allow it. It does not
 * detect renames or compare file contents. Here, minimizing means removing
 * redundant path operations, not finding the smallest possible patch.
 *
 * ## Sparse directory entries
 *
 * Non-empty directories have no index entry. Their descendants imply that the
 * directory exists. The planner uses the paths preceding and following the
 * current position to decide whether a missing directory still has a result
 * descendant. When a whole directory disappeared, it emits the highest
 * missing directory instead of deleting every indexed descendant.
 *
 * An append-only file keeps that deletion active while later base entries are
 * descendants of it. A sibling such as `tree-other` may sort between `tree`
 * and `tree/child`, so each active root links to the preceding one instead of
 * assuming all descendants are adjacent.
 *
 * ## Selection
 *
 * Included and excluded roots use the same coordinates as the index paths. An
 * empty included root selects every relative path. A planned operation is
 * omitted when its path falls outside every included root or intersects an
 * excluded root. The index diff still consumes that path.
 *
 * ## Traversal and resume
 *
 * Each call to next_path() processes at most one path. The getters describe
 * that path until the following call. is_complete() becomes true after the
 * last path. A false next_path() result means no path remains and stays false.
 *
 *     $planner = FileSyncPatchPlanner::create(
 *         $patch_base_index_file,
 *         $patch_result_index_file,
 *         $active_deletion_roots_file
 *     );
 *     while ($planner->next_path()) {
 *         $operation = $planner->get_operation();
 *         if ($operation !== null) {
 *             append_sync_operation($operation);
 *         }
 *         save_cursor($planner->get_cursor());
 *     }
 *     $planner->close();
 *
 * The cursor contains everything resume() needs: both index paths, the active
 * deletion roots file, path selection, and the current byte offsets. The
 * active deletion roots file may contain bytes beyond the cursor after an
 * interruption. resume() ignores those bytes.
 *
 * @phpstan-type IndexDiffCursor array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null}
 * @phpstan-type Cursor array{patch_base_index_file:string,patch_result_index_file:string,active_deletion_roots_file:string,included_index_path_roots:list<string>,excluded_index_path_roots:list<string>,index_diff_cursor:IndexDiffCursor,active_deletion_root_byte_offset:int|null}
 * @phpstan-type ActiveDeletionRoot array{path:string,previous_byte_offset:int|null}
 * @phpstan-type ExpectedSource array{type:string,size:int,ctime:int}
 * @phpstan-type DeleteOperation array{action:'delete',path:string}
 * @phpstan-type CopyOperation array{action:'copy'|'replace',path:string,expected_source:ExpectedSource}
 * @phpstan-type SyncOperation DeleteOperation|CopyOperation
 */
final class FileSyncPatchPlanner
{
    /** Sorted comparison of the patch base and result indexes. */
    private FileIndexDiffProcessor $index_diff;

    /** @var resource Open append-only file of active deletion roots. */
    private $active_deletion_roots_handle;

    /** @var list<string> Index path roots within which changes may be planned. */
    private array $included_index_path_roots;

    /** @var list<string> Index path roots which no planned operation may affect. */
    private array $excluded_index_path_roots;

    /** @var ActiveDeletionRoot|null Top active deleted-directory root. */
    private ?array $active_deletion_root = null;

    /** @var Cursor Cursor after the last path processed by next_path(). */
    private array $cursor;

    /** Whether the diff processor has already selected the next path. */
    private bool $index_diff_path_selected = false;

    /** @var SyncOperation|null Operation selected for the current path. */
    private ?array $operation = null;

    /** Whether both indexes reached EOF. */
    private bool $complete = false;

    /** Whether close() made this planner terminal. */
    private bool $closed = false;

    /**
     * Creates a planner before the first path in either index.
     *
     * The active deletion roots file is replaced with an empty file. Use
     * resume() to continue from a cursor returned by get_cursor().
     *
     * @param string       $patch_base_index_file          Tree state before the patch, or a missing path for an empty tree.
     * @param string       $patch_result_index_file        Tree state described by the patch.
     * @param string       $active_deletion_roots_file     State for directory deletions which cover paths not processed yet.
     * @param list<string> $included_index_path_roots      Roots within which changes may be planned.
     * @param list<string> $excluded_index_path_roots      Roots which changes must not affect.
     * @return self Open planner positioned before the first path.
     */
    public static function create(
        string $patch_base_index_file,
        string $patch_result_index_file,
        string $active_deletion_roots_file,
        array $included_index_path_roots = [""],
        array $excluded_index_path_roots = []
    ): self {
        if (file_put_contents($active_deletion_roots_file, "") !== 0) {
            throw new RuntimeException(
                "Failed to initialize the active deletion roots file: {$active_deletion_roots_file}"
            );
        }
        return self::resume(
            [
                "patch_base_index_file" => $patch_base_index_file,
                "patch_result_index_file" => $patch_result_index_file,
                "active_deletion_roots_file" => $active_deletion_roots_file,
                "included_index_path_roots" => $included_index_path_roots,
                "excluded_index_path_roots" => $excluded_index_path_roots,
                "index_diff_cursor" => [
                    "old_index_byte_offset" => 0,
                    "new_index_byte_offset" => 0,
                    "preceding_new_index_entry_path_b64" => null,
                ],
                "active_deletion_root_byte_offset" => null,
            ]
        );
    }

    /**
     * Reopens a planner at its last stored cursor.
     *
     * Both index files must still contain the same snapshots used by create().
     * The active deletion roots file must still belong to this plan.
     *
     * @param array $cursor {
     *     Cursor returned by get_cursor().
     *
     *     @type string       $patch_base_index_file                    Tree state before the patch.
     *     @type string       $patch_result_index_file                  Tree state described by the patch.
     *     @type string       $active_deletion_roots_file               State for active directory deletions.
     *     @type list<string> $included_index_path_roots                Roots within which changes may be planned.
     *     @type list<string> $excluded_index_path_roots                Roots which changes must not affect.
     *     @type array        $index_diff_cursor                        File-index diff cursor.
     *     @type int|null     $active_deletion_root_byte_offset    Active deletion-root offset.
     * }
     * @phpstan-param Cursor $cursor
     * @return self Open planner restored at the supplied cursor.
     */
    public static function resume(array $cursor): self
    {
        $planner = new self();
        $planner->cursor = $cursor;
        $planner->included_index_path_roots =
            $cursor["included_index_path_roots"];
        $planner->excluded_index_path_roots =
            $cursor["excluded_index_path_roots"];
        $planner->index_diff = FileIndexDiffProcessor::resume(
            $cursor["patch_base_index_file"],
            $cursor["patch_result_index_file"],
            $cursor["index_diff_cursor"]
        );
        $planner->active_deletion_roots_handle = fopen(
            $cursor["active_deletion_roots_file"],
            "a+b"
        );
        if (!is_resource($planner->active_deletion_roots_handle)) {
            $planner->index_diff->close();
            throw new RuntimeException(
                "Failed to open the active deletion roots file: "
                . $cursor["active_deletion_roots_file"]
            );
        }
        $planner->active_deletion_root =
            $planner->read_active_deletion_root(
                $cursor["active_deletion_root_byte_offset"]
            );
        return $planner;
    }

    /**
     * Processes the next path found in the patch base or result index.
     *
     * True means the getters describe one processed path. False means there
     * was no path left to process. A true result may also make is_complete()
     * true when that path was the last one.
     */
    public function next_path(): bool
    {
        $this->assert_open();
        $this->operation = null;
        if ($this->complete) {
            return false;
        }
        if (
            !$this->index_diff_path_selected
            && !$this->index_diff->next_path()
        ) {
            $this->complete = true;
            return false;
        }
        $this->index_diff_path_selected = true;

        $index_path = $this->index_diff->get_path();
        $patch_base_path_type = $this->index_diff->get_path_type_in_old_index();
        $patch_result_path_type = $this->index_diff->get_path_type_in_new_index();
        $path_transition = $this->index_diff->get_path_transition();
        $patch_result_entry_shape = $patch_result_path_type === null
            ? null
            : $this->index_entry_shape($patch_result_path_type);
        $patch_base_entry_shape = $patch_base_path_type === null
            ? null
            : $this->index_entry_shape($patch_base_path_type);
        $path_to_delete = null;
        $path_to_copy = null;
        $active_deletion_root_byte_offset =
            $this->cursor["active_deletion_root_byte_offset"];

        if (
            $patch_base_path_type !== null
            && $this->active_deletion_root !== null
        ) {
            // Byte sorting can place `a-other` before `a/child`. Keep the
            // active root until the patch base has moved beyond its subtree.
            $descendant_prefix =
                $this->active_deletion_root["path"] . "/";
            if (
                !path_is_same_as_or_descendant_of(
                    $index_path,
                    $this->active_deletion_root["path"]
                )
                && strcmp($index_path, $descendant_prefix) > 0
            ) {
                $active_deletion_root_byte_offset =
                    $this->active_deletion_root["previous_byte_offset"];
                $this->active_deletion_root =
                    $this->read_active_deletion_root(
                        $active_deletion_root_byte_offset
                    );
            }
        }

        if ($path_transition === "added") {
            // A NUL byte cannot occur in an index path. Use it when there is
            // no following patch-base path so the descendant test cannot match.
            $patch_result_entry_replaces_patch_base_subtree =
                path_is_same_as_or_descendant_of(
                    $this->index_diff->get_following_path_in_old_index()
                        ?? "\0",
                    $index_path
                );
            if (
                $patch_result_entry_replaces_patch_base_subtree
                && $this->path_may_change($index_path)
                && !$this->active_deletion_root_covers_path($index_path)
            ) {
                $path_to_delete = $index_path;
                $active_deletion_root_byte_offset =
                    $this->append_active_deletion_root(
                        $index_path,
                        $active_deletion_root_byte_offset
                    );
            }
            if ($this->path_may_change($index_path)) {
                $path_to_copy = $index_path;
            }
        } elseif ($path_transition === "deleted") {
            $patch_base_empty_directory_is_implied_by_patch_result_descendant =
                $patch_base_entry_shape === "empty_directory"
                && $this->patch_result_index_contains_path_or_descendant(
                    $index_path,
                    $this->index_diff->get_preceding_path_in_new_index(),
                    $this->index_diff->get_following_path_in_new_index()
                );

            // Find the highest patch-base directory without a patch-result
            // entry below it. Only the adjacent result entries can neighbor
            // each parent in byte order, so no index rescan is needed.
            $candidate_path_to_delete = $index_path;
            $index_path_components = wp_unix_path_segments($index_path);
            $candidate_path_components = [];
            for (
                $index = 0,
                $component_count = count($index_path_components) - 1;
                $index < $component_count;
                ++$index
            ) {
                $candidate_path_components[] = $index_path_components[$index];
                $candidate_path = wp_join_unix_paths(
                    ...$candidate_path_components
                );
                if (
                    !$this->patch_result_index_contains_path_or_descendant(
                        $candidate_path,
                        $this->index_diff->get_preceding_path_in_new_index(),
                        $this->index_diff->get_following_path_in_new_index()
                    )
                ) {
                    $candidate_path_to_delete = $candidate_path;
                    break;
                }
            }
            if (
                !$patch_base_empty_directory_is_implied_by_patch_result_descendant
                && $this->path_may_change($candidate_path_to_delete)
                && !$this->active_deletion_root_covers_path($index_path)
            ) {
                $path_to_delete = $candidate_path_to_delete;
                if ($candidate_path_to_delete !== $index_path) {
                    $active_deletion_root_byte_offset =
                        $this->append_active_deletion_root(
                            $candidate_path_to_delete,
                            $active_deletion_root_byte_offset
                        );
                }
            }
        } else {
            $patch_result_entry_is_file_or_symlink =
                $patch_result_entry_shape === "file"
                || $patch_result_entry_shape === "symlink";
            $patch_base_entry_is_file_or_symlink =
                $patch_base_entry_shape === "file"
                || $patch_base_entry_shape === "symlink";
            $empty_directory_needs_copy =
                $patch_result_entry_shape === "empty_directory"
                && $patch_base_entry_shape !== "empty_directory";
            $changed_file_or_symlink_needs_copy =
                $patch_result_entry_is_file_or_symlink
                && $path_transition === "modified";
            // The diff defines modification by type, size, and ctime. Only a
            // modified file or symlink needs its result value copied.
            $needs_delete =
                $patch_result_entry_is_file_or_symlink
                !== $patch_base_entry_is_file_or_symlink;
            $path_may_change = $this->path_may_change($index_path);

            if (
                $needs_delete
                && $path_may_change
                && !$this->active_deletion_root_covers_path($index_path)
            ) {
                $path_to_delete = $index_path;
            }
            if (
                ( $empty_directory_needs_copy
                    || $changed_file_or_symlink_needs_copy )
                && $path_may_change
            ) {
                $path_to_copy = $index_path;
            }
        }

        if ($path_to_copy !== null) {
            $this->operation = [
                "action" => $path_to_delete === null ? "copy" : "replace",
                "path" => $path_to_copy,
                "expected_source" => [
                    "type" => $patch_result_path_type,
                    "size" => $this->index_diff->get_size_in_new_index(),
                    "ctime" => $this->index_diff->get_ctime_in_new_index(),
                ],
            ];
        } elseif ($path_to_delete !== null) {
            $this->operation = [
                "action" => "delete",
                "path" => $path_to_delete,
            ];
        }

        $this->complete = !$this->index_diff->next_path();
        $this->index_diff_path_selected = !$this->complete;
        if ($this->complete) {
            $active_deletion_root_byte_offset = null;
            $this->active_deletion_root = null;
        }
        $this->cursor["index_diff_cursor"] = $this->index_diff->get_cursor();
        $this->cursor["active_deletion_root_byte_offset"] =
            $active_deletion_root_byte_offset;
        return true;
    }

    /** Returns whether the last processed path exhausted both indexes. */
    public function is_complete(): bool
    {
        return $this->complete;
    }

    /**
     * Returns the operation selected for the current path.
     *
     * Null means the current path needs no operation. A `delete` operation has
     * only an action and path. A `copy` or `replace` operation also records the
     * source state expected when the operation is performed. `replace` means
     * delete the path before copying the patch-result entry there.
     *
     * @return array|null {
     *     Current sync operation, or null.
     *
     *     @type string $action          `copy`, `delete`, or `replace`.
     *     @type string $path            Path on which to operate.
     *     @type array  $expected_source {
     *         Source state required by `copy` and `replace`. Absent for `delete`.
     *
     *         @type string $type  Expected `file`, `link`, or `dir` type.
     *         @type int    $size  Expected size.
     *         @type int    $ctime Expected inode change time.
     *     }
     * }
     * @phpstan-return SyncOperation|null
     */
    public function get_operation(): ?array
    {
        $this->assert_open();
        return $this->operation;
    }

    /**
     * Returns everything needed to resume after the current path.
     *
     * @return array {
     *     Cursor for resume().
     *
     *     @type string       $patch_base_index_file                    Tree state before the patch.
     *     @type string       $patch_result_index_file                  Tree state described by the patch.
     *     @type string       $active_deletion_roots_file               State for active directory deletions.
     *     @type list<string> $included_index_path_roots                Roots within which changes may be planned.
     *     @type list<string> $excluded_index_path_roots                Roots which changes must not affect.
     *     @type array        $index_diff_cursor                        File-index diff cursor.
     *     @type int|null     $active_deletion_root_byte_offset    Active deletion-root offset.
     * }
     * @phpstan-return Cursor
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Flushes active deletion roots before the cursor is stored. */
    public function flush_pending_outputs(): void
    {
        if (!fflush($this->active_deletion_roots_handle)) {
            throw new RuntimeException(
                "Failed to flush the active deletion roots file."
            );
        }
    }

    /** Closes the index diff and work-file handle. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->index_diff->close();
        if (is_resource($this->active_deletion_roots_handle)) {
            fclose($this->active_deletion_roots_handle);
        }
        $this->closed = true;
    }

    /** Checks adjacent patch-result entries for a path or its descendant. */
    private function patch_result_index_contains_path_or_descendant(
        string $index_path,
        ?string $preceding_patch_result_index_path,
        ?string $following_patch_result_index_path
    ): bool {
        // NUL cannot occur in an index path and cannot match either test.
        $preceding_patch_result_index_path =
            $preceding_patch_result_index_path ?? "\0";
        $following_patch_result_index_path =
            $following_patch_result_index_path ?? "\0";

        return path_is_same_as_or_descendant_of(
            $preceding_patch_result_index_path,
            $index_path
        ) || path_is_same_as_or_descendant_of(
            $following_patch_result_index_path,
            $index_path
        );
    }

    /** Returns the logical entry kind used by the plan transition table. */
    private function index_entry_shape(string $path_type): string
    {
        if ($path_type === "file") {
            return "file";
        }
        if ($path_type === "link") {
            return "symlink";
        }
        return "empty_directory";
    }

    /** Adds one active deletion root and returns its byte offset. */
    private function append_active_deletion_root(
        string $index_path,
        ?int $previous_byte_offset
    ): int {
        if (fseek($this->active_deletion_roots_handle, 0, SEEK_END) !== 0) {
            throw new RuntimeException(
                "Failed to seek to the end of the active deletion roots file."
            );
        }
        $byte_offset = ftell($this->active_deletion_roots_handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the active deletion roots byte offset."
            );
        }
        $line = json_encode(
            [
                "path_b64" => base64_encode($index_path),
                "previous_byte_offset" => $previous_byte_offset,
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (
            fwrite($this->active_deletion_roots_handle, $line)
            !== strlen($line)
        ) {
            throw new RuntimeException(
                "Failed to append to the active deletion roots file."
            );
        }
        $this->active_deletion_root = [
            "path" => $index_path,
            "previous_byte_offset" => $previous_byte_offset,
        ];
        return $byte_offset;
    }

    /** Returns one active deletion root addressed by its byte offset. */
    private function read_active_deletion_root(
        ?int $byte_offset
    ): ?array {
        if ($byte_offset === null) {
            return null;
        }
        if (
            fseek(
                $this->active_deletion_roots_handle,
                $byte_offset
            ) !== 0
        ) {
            throw new RuntimeException(
                "Failed to seek in the active deletion roots file."
            );
        }
        $line = fgets($this->active_deletion_roots_handle);
        if (!is_string($line)) {
            throw new RuntimeException(
                "Failed to read the active deletion root at byte {$byte_offset}."
            );
        }
        try {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Failed to decode the active deletion root at byte {$byte_offset}.",
                0,
                $exception
            );
        }
        /** @var array{path_b64:string,previous_byte_offset:int|null} $entry */
        $index_path = base64_decode($entry["path_b64"], true);
        if ($index_path === false) {
            throw new RuntimeException(
                "Failed to decode the deleted-directory path at byte {$byte_offset}."
            );
        }
        return [
            "path" => $index_path,
            "previous_byte_offset" => $entry["previous_byte_offset"],
        ];
    }

    /** Reports whether the active deletion root contains the index path. */
    private function active_deletion_root_covers_path(
        string $index_path
    ): bool {
        return $this->active_deletion_root !== null
            && path_is_descendant_of(
                $index_path,
                $this->active_deletion_root["path"]
            );
    }

    /**
     * Reports whether a planned operation may change the index path.
     *
     * The path must be inside an included root. It must not be an excluded
     * root, sit below one, or contain one. The last case prevents a parent
     * deletion or replacement from changing an excluded descendant.
     */
    private function path_may_change(string $index_path): bool
    {
        $is_included = false;
        foreach ($this->included_index_path_roots as $included_index_path_root) {
            if (
                $included_index_path_root === ""
                || path_is_same_as_or_descendant_of(
                    $index_path,
                    $included_index_path_root
                )
            ) {
                $is_included = true;
                break;
            }
        }
        if (!$is_included) {
            return false;
        }
        foreach ($this->excluded_index_path_roots as $excluded_index_path_root) {
            if (
                $excluded_index_path_root === ""
                || path_is_same_as_or_descendant_of(
                    $index_path,
                    $excluded_index_path_root
                )
                || path_is_same_as_or_descendant_of(
                    $excluded_index_path_root,
                    $index_path
                )
            ) {
                return false;
            }
        }
        return true;
    }

    /** Rejects calls after close(). */
    private function assert_open(): void
    {
        if ($this->closed) {
            throw new LogicException(
                "Cannot use a closed file sync patch planner."
            );
        }
    }
}
