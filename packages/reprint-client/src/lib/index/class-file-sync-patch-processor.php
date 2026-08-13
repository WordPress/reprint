<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/class-file-sync-patch-planner.php';
require_once __DIR__ . '/class-fresh-local-index-processor.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths are CLI/API values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint streaming classes use domain names.

/**
 * Scans the local tree and plans the patch between it and a saved index.
 *
 * The processor owns the fresh local index and FileSyncPatchPlanner lifecycle.
 * Each next_step() call advances the local scan once, starts the patch planner,
 * or processes one path from the two indexes. Store get_cursor() unchanged
 * after flushing pending output. A later process resumes with that cursor
 * alone.
 *
 * The start method name states the patch direction. Push uses
 * start_to_fresh_local_tree(): the saved index is the patch base and the
 * current local tree is the result. start_from_fresh_local_tree() reverses
 * that direction: the current local tree is the patch base and the supplied
 * index is the result.
 *
 * The supplied index must describe the same filesystem root on the same
 * machine. File and symlink changes use ctime, which cannot be compared across
 * machines.
 *
 *     $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
 *         $work_directory,
 *         $filesystem_root,
 *         $saved_local_index,
 *         $storage_path
 *     );
 *     do {
 *         $has_next_step = $processor->next_step();
 *         $operation = $processor->get_operation();
 *         if ($operation !== null) {
 *             append_operation($operation);
 *         }
 *         $processor->flush_pending_outputs();
 *         save_cursor($processor->get_cursor());
 *     } while ($has_next_step);
 *     $processor->close();
 *
 * @phpstan-type FileIndexCursor array{stack:list<array{dir:string,after:string|null}>}
 * @phpstan-type FreshIndexPosition array{phase:'indexing',file_index_cursor:FileIndexCursor,fresh_local_index_byte_offset:int}|array{phase:'sorting'}|array{phase:'complete'}
 * @phpstan-type FreshIndexCursor array{fresh_local_index_file_b64:string,filesystem_root_b64:string,storage_path_b64:string,include_caches:bool,position:FreshIndexPosition}
 * @phpstan-type PlannerIndexDiffCursor array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null}
 * @phpstan-type PlannerCursor array{patch_base_index_file_b64:string,patch_result_index_file_b64:string,active_deletion_roots_file_b64:string,included_index_path_roots_b64:list<string>,excluded_index_path_roots_b64:list<string>,index_diff_cursor:PlannerIndexDiffCursor,active_deletion_root_byte_offset:int|null}
 * @phpstan-type FreshTreePosition array{phase:'indexing'|'sorting'|'starting_patch',patch_base_index_file_b64:string,patch_result_index_file_b64:string,included_index_path_roots_b64:list<string>,excluded_index_path_roots_b64:list<string>,fresh_local_index_cursor:FreshIndexCursor}
 * @phpstan-type Position FreshTreePosition|array{phase:'planning',file_sync_patch_planner_cursor:PlannerCursor}|array{phase:'complete'}
 * @phpstan-type Cursor array{fresh_local_index_file_b64:string,position:Position}
 * @phpstan-type SyncOperation array{action:'copy'|'delete'|'replace',path:string,expected_source?:array{type:string,size:int,ctime:int}}
 */
final class FileSyncPatchProcessor {
    /** @var Cursor */
    private array $cursor;

    /** Fresh local index path decoded from the cursor. */
    private string $fresh_local_index_file;

    private FreshLocalIndexProcessor $fresh_local_index_processor;

    private FileSyncPatchPlanner $patch_planner;

    /** @var SyncOperation|null */
    private ?array $operation = null;

    private bool $closed = false;

    /**
     * Plans the patch which changes a saved index into the current local tree.
     *
     * @param string       $work_directory             Existing directory for the fresh index and planner state.
     * @param string       $filesystem_root            Filesystem root scanned for the fresh index.
     * @param string       $patch_base_index_file      Saved tree before the patch, or a missing file for an empty tree.
     * @param string       $storage_path               Reprint storage path omitted from the fresh index.
     * @param list<string> $included_index_path_roots  Roots within which changes may be planned.
     * @param list<string> $excluded_index_path_roots  Roots which changes must not affect.
     * @param bool         $include_caches             Whether the local scan includes cache directories.
     */
    public static function start_to_fresh_local_tree(
        string $work_directory,
        string $filesystem_root,
        string $patch_base_index_file,
        string $storage_path,
        array $included_index_path_roots = [""],
        array $excluded_index_path_roots = [],
        bool $include_caches = false
    ): self {
        $work_directory = trim_right_slash($work_directory);
        return self::create(
            $work_directory,
            $filesystem_root,
            $patch_base_index_file,
            wp_join_unix_paths($work_directory, "fresh_local_index.jsonl"),
            $storage_path,
            $included_index_path_roots,
            $excluded_index_path_roots,
            $include_caches
        );
    }

    /**
     * Plans the patch which changes the current local tree into a saved index.
     *
     * Copy and replace operations read their expected source state from the
     * supplied patch-result index.
     *
     * @param string       $work_directory             Existing directory for the fresh index and planner state.
     * @param string       $filesystem_root            Filesystem root scanned for the fresh index.
     * @param string       $patch_result_index_file    Tree which the patch must produce.
     * @param string       $storage_path               Reprint storage path omitted from the fresh index.
     * @param list<string> $included_index_path_roots  Roots within which changes may be planned.
     * @param list<string> $excluded_index_path_roots  Roots which changes must not affect.
     * @param bool         $include_caches             Whether the local scan includes cache directories.
     */
    public static function start_from_fresh_local_tree(
        string $work_directory,
        string $filesystem_root,
        string $patch_result_index_file,
        string $storage_path,
        array $included_index_path_roots = [""],
        array $excluded_index_path_roots = [],
        bool $include_caches = false
    ): self {
        $work_directory = trim_right_slash($work_directory);
        return self::create(
            $work_directory,
            $filesystem_root,
            wp_join_unix_paths($work_directory, "fresh_local_index.jsonl"),
            $patch_result_index_file,
            $storage_path,
            $included_index_path_roots,
            $excluded_index_path_roots,
            $include_caches
        );
    }

    /** @phpstan-param Cursor $cursor Cursor returned by get_cursor(). */
    public static function resume(array $cursor): self
    {
        $processor = new self();
        $processor->cursor = $cursor;
        $processor->fresh_local_index_file = self::decode_cursor_path(
            $cursor["fresh_local_index_file_b64"],
            "fresh local index file"
        );
        $position = $cursor["position"];
        if (
            $position["phase"] === "indexing"
            || $position["phase"] === "sorting"
            || $position["phase"] === "starting_patch"
        ) {
            $processor->fresh_local_index_processor =
                FreshLocalIndexProcessor::resume(
                    $position["fresh_local_index_cursor"]
                );
        } elseif ($position["phase"] === "planning") {
            $processor->patch_planner = FileSyncPatchPlanner::resume(
                $position["file_sync_patch_planner_cursor"]
            );
        }
        return $processor;
    }

    /**
     * Performs one local-index or patch-planning step.
     *
     * get_operation() returns the operation selected by a planning step and
     * null for every other step. The last planning step returns false and may
     * carry the last operation. Read get_operation() before using the boolean
     * to end the loop. Later calls remain false and return no operation.
     */
    public function next_step(): bool
    {
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            $this->operation = null;
            return false;
        }
        if ($this->closed) {
            throw new LogicException(
                "Cannot take a file sync patch step after close()."
            );
        }
        $this->operation = null;

        if (
            $position["phase"] === "indexing"
            || $position["phase"] === "sorting"
        ) {
            $this->fresh_local_index_processor->next_step();
            $fresh_local_index_cursor =
                $this->fresh_local_index_processor->get_cursor();
            $fresh_local_index_phase =
                $this->fresh_local_index_processor->get_phase();
            $position["phase"] = $fresh_local_index_phase === "complete"
                ? "starting_patch"
                : $fresh_local_index_phase;
            $position["fresh_local_index_cursor"] =
                $fresh_local_index_cursor;
            $this->cursor["position"] = $position;
            return true;
        }

        if ($position["phase"] === "starting_patch") {
            $this->fresh_local_index_processor->close();
            $included_index_path_roots = [];
            foreach (
                $position["included_index_path_roots_b64"]
                as $included_index_path_root_b64
            ) {
                $included_index_path_root = base64_decode(
                    $included_index_path_root_b64,
                    true
                );
                if ($included_index_path_root === false) {
                    throw new InvalidArgumentException(
                        "File sync patch processor cursor contains an invalid base64 included path root."
                    );
                }
                $included_index_path_roots[] = $included_index_path_root;
            }
            $excluded_index_path_roots = [];
            foreach (
                $position["excluded_index_path_roots_b64"]
                as $excluded_index_path_root_b64
            ) {
                $excluded_index_path_root = base64_decode(
                    $excluded_index_path_root_b64,
                    true
                );
                if ($excluded_index_path_root === false) {
                    throw new InvalidArgumentException(
                        "File sync patch processor cursor contains an invalid base64 excluded path root."
                    );
                }
                $excluded_index_path_roots[] = $excluded_index_path_root;
            }
            $this->patch_planner = FileSyncPatchPlanner::create(
                self::decode_cursor_path(
                    $position["patch_base_index_file_b64"],
                    "patch base index file"
                ),
                self::decode_cursor_path(
                    $position["patch_result_index_file_b64"],
                    "patch result index file"
                ),
                wp_join_unix_paths(
                    dirname($this->fresh_local_index_file),
                    "deleted_directories_stack.jsonl"
                ),
                $included_index_path_roots,
                $excluded_index_path_roots
            );
            $this->cursor["position"] = [
                "phase" => "planning",
                "file_sync_patch_planner_cursor" =>
                    $this->patch_planner->get_cursor(),
            ];
            return true;
        }

        if (!$this->patch_planner->next_path()) {
            $this->cursor["position"] = ["phase" => "complete"];
            return false;
        }
        $this->operation = $this->patch_planner->get_operation();
        if ($this->patch_planner->is_complete()) {
            $this->cursor["position"] = ["phase" => "complete"];
            return false;
        }
        $this->cursor["position"] = [
            "phase" => "planning",
            "file_sync_patch_planner_cursor" =>
                $this->patch_planner->get_cursor(),
        ];
        return true;
    }

    /**
     * Returns the operation selected by the latest planning step.
     *
     * Delete operations contain only `action` and `path`. Copy and replace
     * operations also contain the result index entry which must be copied.
     * Non-planning steps and processed paths which need no change return null.
     *
     * @return array|null {
     *     @type string $action          `copy`, `delete`, or `replace`.
     *     @type string $path            Local relative path selected by the patch.
     *     @type array  $expected_source {
     *         Result index entry required by `copy` and `replace`.
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
        return $this->operation;
    }

    /** @phpstan-return Cursor Cursor after the latest completed step. */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Returns `indexing`, `sorting`, `starting_patch`, `planning`, or `complete`. */
    public function get_phase(): string
    {
        return $this->cursor["position"]["phase"];
    }

    /** Returns the processor-owned fresh local index path. */
    public function get_fresh_local_index_path(): string
    {
        return $this->fresh_local_index_file;
    }

    /**
     * Returns index bytes consumed while planning the patch.
     *
     * @return array {
     *     @type string $phase             Current processor phase.
     *     @type int    $index_bytes_done  Index bytes consumed. Present while planning.
     *     @type int    $index_bytes_total Combined index size. Present while planning.
     * }
     * @phpstan-return array{phase:string,index_bytes_done?:int,index_bytes_total?:int}
     */
    public function get_progress(): array
    {
        $position = $this->cursor["position"];
        $progress = ["phase" => $position["phase"]];
        if ($position["phase"] !== "planning") {
            return $progress;
        }
        $file_sync_patch_planner_cursor =
            $position["file_sync_patch_planner_cursor"];
        $patch_base_index_file = self::decode_cursor_path(
            $file_sync_patch_planner_cursor["patch_base_index_file_b64"],
            "patch base index file"
        );
        $patch_result_index_file = self::decode_cursor_path(
            $file_sync_patch_planner_cursor["patch_result_index_file_b64"],
            "patch result index file"
        );
        $patch_base_index_bytes = is_file(
            $patch_base_index_file
        )
            ? filesize($patch_base_index_file)
            : 0;
        $patch_result_index_bytes = is_file(
            $patch_result_index_file
        )
            ? filesize($patch_result_index_file)
            : 0;
        if (
            !is_int($patch_base_index_bytes)
            || !is_int($patch_result_index_bytes)
        ) {
            return $progress;
        }
        $index_bytes_total = $patch_base_index_bytes
            + $patch_result_index_bytes;
        $index_diff_cursor =
            $file_sync_patch_planner_cursor["index_diff_cursor"];
        $index_bytes_done = $index_diff_cursor["new_index_byte_offset"]
            + $index_diff_cursor["old_index_byte_offset"];
        $progress["index_bytes_done"] = min(
            $index_bytes_done,
            $index_bytes_total
        );
        $progress["index_bytes_total"] = $index_bytes_total;
        return $progress;
    }

    /** Flushes nested output before the caller stores get_cursor(). */
    public function flush_pending_outputs(): void
    {
        if (isset($this->fresh_local_index_processor)) {
            $this->fresh_local_index_processor->flush_pending_output();
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->flush_pending_outputs();
        }
    }

    /** Closes retained handles. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (isset($this->fresh_local_index_processor)) {
            $this->fresh_local_index_processor->close();
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->close();
        }
        $this->closed = true;
    }

    private static function create(
        string $work_directory,
        string $filesystem_root,
        string $patch_base_index_file,
        string $patch_result_index_file,
        string $storage_path,
        array $included_index_path_roots,
        array $excluded_index_path_roots,
        bool $include_caches
    ): self {
        if (!is_dir($work_directory)) {
            throw new LogicException(
                "Cannot start file sync patch processing without its work directory: {$work_directory}"
            );
        }
        $processor = new self();
        $fresh_local_index_file = wp_join_unix_paths(
            $work_directory,
            "fresh_local_index.jsonl"
        );
        $processor->fresh_local_index_file = $fresh_local_index_file;
        $processor->fresh_local_index_processor =
            FreshLocalIndexProcessor::start(
                $fresh_local_index_file,
                $filesystem_root,
                $storage_path,
                $include_caches
            );
        $processor->cursor = [
            "fresh_local_index_file_b64" => base64_encode(
                $fresh_local_index_file
            ),
            "position" => [
                "phase" => "indexing",
                "patch_base_index_file_b64" => base64_encode(
                    $patch_base_index_file
                ),
                "patch_result_index_file_b64" => base64_encode(
                    $patch_result_index_file
                ),
                "included_index_path_roots_b64" => array_map(
                    "base64_encode",
                    $included_index_path_roots
                ),
                "excluded_index_path_roots_b64" => array_map(
                    "base64_encode",
                    $excluded_index_path_roots
                ),
                "fresh_local_index_cursor" =>
                    $processor->fresh_local_index_processor->get_cursor(),
            ],
        ];
        return $processor;
    }

    /** Decodes one arbitrary-byte path stored in the JSON cursor. */
    private static function decode_cursor_path(
        string $encoded_path,
        string $field_name
    ): string {
        $path = base64_decode($encoded_path, true);
        if ($path === false) {
            throw new InvalidArgumentException(
                "File sync patch processor cursor contains an invalid base64 {$field_name}."
            );
        }
        return $path;
    }
}
