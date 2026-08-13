<?php

use function Reprint\Importer\append_file_sync_path_stack_entry;
use function Reprint\Importer\file_sync_index_path_may_change;
use function Reprint\Importer\read_file_sync_path_stack_entry;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_relative_path;
use function WordPress\Reprint\Exporter\read_file_index_entry_from_stat;
use function WordPress\Reprint\Exporter\realpath_with_missing_tail;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/class-file-sync-patch-processor.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint streaming classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing streaming classes.

/**
 * Removes local paths which block a file-sync patch.
 *
 * This processor scans the local tree and asks FileSyncPatchProcessor for the
 * patch from that tree to a supplied result index. It applies only `delete`
 * and `replace` operations. A `copy` operation leaves the path for the caller
 * to write after cleanup.
 *
 * Every removal names one exact path. Directories are removed with rmdir(),
 * never by walking their children. An excluded, skipped, or newly created
 * child therefore keeps its directory in place. Empty parents are pruned in
 * separate steps after patch planning ends.
 *
 * A planned removal is stored in the cursor before it is applied. If a process
 * stops after removing the path but before storing the next cursor, resume()
 * repeats the same removal. A missing path counts as already removed.
 *
 *     $cleanup = FileSyncCleanupProcessor::start(
 *         $work_directory,
 *         $filesystem_root,
 *         $patch_result_index,
 *         $storage_path,
 *         ['wp-content'],
 *         ['wp-content/uploads/keep']
 *     );
 *     do {
 *         $has_next_step = $cleanup->next_step();
 *         $cleanup->flush_pending_output();
 *         save_cursor($cleanup->get_cursor());
 *     } while ($has_next_step);
 *     $status = $cleanup->get_status();
 *     $cleanup->close();
 *     if ($status === 'restart') {
 *         restart_file_sync();
 *     }
 *
 * @phpstan-type PatchCursor array<string,mixed>
 * @phpstan-type PlanningPosition array{phase:'planning',file_sync_patch_processor_cursor:PatchCursor}
 * @phpstan-type ExpectedBase array{type:string,size:int,ctime:int}
 * @phpstan-type RemovingPosition array{phase:'removing',path_b64:string,expected_base:ExpectedBase,file_sync_patch_processor_cursor:PatchCursor}
 * @phpstan-type PruningPosition array{phase:'pruning'}
 * @phpstan-type Position PlanningPosition|RemovingPosition|PruningPosition|array{phase:'complete'|'restart'}
 * @phpstan-type Cursor array{filesystem_root_b64:string,empty_parent_paths_file_b64:string,empty_parent_paths_file_byte_offset:int,empty_parent_path_stack_top_byte_offset:int|null,included_index_path_roots_b64:list<string>,excluded_index_path_roots_b64:list<string>,position:Position}
 * @phpstan-type EmptyParentPath array{path:string,previous_byte_offset:int|null,expected_ctime:int|null}
 */
final class FileSyncCleanupProcessor
{
    /** @var Cursor Cursor after the latest completed step. */
    private array $cursor;

    /** Canonical filesystem root decoded from the cursor. */
    private string $filesystem_root;

    /** @var list<string> Roots whose descendants may be removed. */
    private array $included_index_path_roots;

    /** @var list<string> Roots which cleanup must not affect. */
    private array $excluded_index_path_roots;

    /** Patch planning retained while the cursor is planning or removing. */
    private FileSyncPatchProcessor $patch_processor;

    /** @var resource|null Append-only stack of empty parent paths. */
    private $empty_parent_paths_handle = null;

    /** @var EmptyParentPath|null Top path on the empty-parent stack. */
    private ?array $empty_parent_path = null;

    /** Byte offset of the top path on the empty-parent stack. */
    private ?int $empty_parent_path_byte_offset = null;

    /** Whether close() released the retained handles. */
    private bool $closed = false;

    /**
     * Starts cleanup before the first local-index step.
     *
     * The work directory must already exist. Its cleanup files are replaced.
     * The patch result index describes the local paths which the caller wants
     * to write after cleanup.
     *
     * @param string       $work_directory             Existing directory for cleanup state.
     * @param string       $filesystem_root            Filesystem root scanned and changed by cleanup.
     * @param string       $patch_result_index_file    Index which the later file sync must produce.
     * @param string       $storage_path               Reprint storage path omitted from the local scan.
     * @param list<string> $included_index_path_roots  Roots whose descendants cleanup may remove.
     * @param list<string> $excluded_index_path_roots  Roots which cleanup must not affect.
     * @param bool         $include_caches             Whether the local scan includes cache directories.
     */
    public static function start(
        string $work_directory,
        string $filesystem_root,
        string $patch_result_index_file,
        string $storage_path,
        array $included_index_path_roots = [""],
        array $excluded_index_path_roots = [],
        bool $include_caches = false
    ): self {
        if (!is_dir($work_directory)) {
            throw new LogicException(
                "Cannot start file sync cleanup without its work directory: {$work_directory}"
            );
        }
        $work_directory = trim_right_slash($work_directory);
        $processor = new self();
        $processor->patch_processor =
            FileSyncPatchProcessor::start_from_fresh_local_tree(
                $work_directory,
                $filesystem_root,
                $patch_result_index_file,
                $storage_path,
                $included_index_path_roots,
                $excluded_index_path_roots,
                $include_caches
            );
        $patch_cursor = $processor->patch_processor->get_cursor();
        $canonical_filesystem_root = self::decode_cursor_path(
            $patch_cursor["position"]["fresh_local_index_cursor"][
                "filesystem_root_b64"
            ],
            "filesystem root"
        );
        $empty_parent_paths_file = wp_join_unix_paths(
            $work_directory,
            "empty_parent_paths_stack.jsonl"
        );
        $processor->empty_parent_paths_handle = fopen(
            $empty_parent_paths_file,
            "w+b"
        );
        if (!is_resource($processor->empty_parent_paths_handle)) {
            $processor->patch_processor->close();
            throw new RuntimeException(
                "Failed to initialize the empty parent paths file: {$empty_parent_paths_file}"
            );
        }
        $processor->filesystem_root = $canonical_filesystem_root;
        $processor->included_index_path_roots = $included_index_path_roots;
        $processor->excluded_index_path_roots = $excluded_index_path_roots;
        $processor->cursor = [
            "filesystem_root_b64" => base64_encode(
                $canonical_filesystem_root
            ),
            "empty_parent_paths_file_b64" => base64_encode(
                $empty_parent_paths_file
            ),
            "empty_parent_paths_file_byte_offset" => 0,
            "empty_parent_path_stack_top_byte_offset" => null,
            "included_index_path_roots_b64" => array_map(
                "base64_encode",
                $included_index_path_roots
            ),
            "excluded_index_path_roots_b64" => array_map(
                "base64_encode",
                $excluded_index_path_roots
            ),
            "position" => [
                "phase" => "planning",
                "file_sync_patch_processor_cursor" => $patch_cursor,
            ],
        ];
        return $processor;
    }

    /**
     * Reopens cleanup at a cursor returned by get_cursor().
     *
     * Bytes appended to the empty-parent stack after the saved byte offset are
     * discarded. They came from a step whose cursor was not stored and will be
     * appended again when that step is repeated.
     *
     * @param array $cursor {
     *     Cursor returned by get_cursor().
     *
     *     @type string       $filesystem_root_b64                         Base64-encoded canonical filesystem root.
     *     @type string       $empty_parent_paths_file_b64                 Base64-encoded path to the parent-path stack.
     *     @type int          $empty_parent_paths_file_byte_offset         Confirmed byte offset in the parent-path stack.
     *     @type int|null     $empty_parent_path_stack_top_byte_offset     Byte offset of the current stack entry.
     *     @type list<string> $included_index_path_roots_b64               Base64-encoded roots whose contents may be removed.
     *     @type list<string> $excluded_index_path_roots_b64               Base64-encoded roots cleanup must not affect.
     *     @type array        $position                                    Current planning, removing, pruning, complete, or restart phase.
     * }
     * @phpstan-param Cursor $cursor
     */
    public static function resume(array $cursor): self
    {
        $processor = new self();
        $processor->cursor = $cursor;
        $processor->filesystem_root = self::decode_cursor_path(
            $cursor["filesystem_root_b64"],
            "filesystem root"
        );
        $resolved_filesystem_root = realpath($processor->filesystem_root);
        if (
            $resolved_filesystem_root === false
            || $resolved_filesystem_root !== $processor->filesystem_root
            || !is_dir($processor->filesystem_root)
            || is_link($processor->filesystem_root)
        ) {
            throw new RuntimeException(
                "The file sync cleanup filesystem root no longer resolves to its saved path."
            );
        }
        $processor->included_index_path_roots = self::decode_path_roots(
            $cursor["included_index_path_roots_b64"],
            "included"
        );
        $processor->excluded_index_path_roots = self::decode_path_roots(
            $cursor["excluded_index_path_roots_b64"],
            "excluded"
        );
        $position = $cursor["position"];
        if (
            $position["phase"] === "complete"
            || $position["phase"] === "restart"
        ) {
            return $processor;
        }
        $empty_parent_paths_file = self::decode_cursor_path(
            $cursor["empty_parent_paths_file_b64"],
            "empty parent paths file"
        );
        $processor->empty_parent_paths_handle = fopen(
            $empty_parent_paths_file,
            "r+b"
        );
        if (!is_resource($processor->empty_parent_paths_handle)) {
            throw new RuntimeException(
                "Failed to reopen the empty parent paths file: {$empty_parent_paths_file}"
            );
        }
        try {
            if (
                !ftruncate(
                    $processor->empty_parent_paths_handle,
                    $cursor["empty_parent_paths_file_byte_offset"]
                )
                || fseek(
                    $processor->empty_parent_paths_handle,
                    $cursor["empty_parent_paths_file_byte_offset"]
                ) !== 0
            ) {
                throw new RuntimeException(
                    "Failed to truncate and seek the empty parent paths file to byte "
                    . $cursor["empty_parent_paths_file_byte_offset"]
                    . "."
                );
            }
            if ($cursor["empty_parent_path_stack_top_byte_offset"] !== null) {
                $processor->empty_parent_path_byte_offset =
                    $cursor["empty_parent_path_stack_top_byte_offset"];
                $saved_parent_path = read_file_sync_path_stack_entry(
                    $processor->empty_parent_paths_handle,
                    $cursor["empty_parent_path_stack_top_byte_offset"]
                );
                $processor->empty_parent_path = [
                    "path" => $saved_parent_path["path"],
                    "previous_byte_offset" =>
                        $saved_parent_path["previous_byte_offset"],
                    "expected_ctime" =>
                        $saved_parent_path["expected_ctime"],
                ];
            }
            if (
                $position["phase"] === "planning"
                || $position["phase"] === "removing"
            ) {
                $processor->patch_processor = FileSyncPatchProcessor::resume(
                    $position["file_sync_patch_processor_cursor"]
                );
            }
        } catch (Throwable $resume_failure) {
            fclose($processor->empty_parent_paths_handle);
            $processor->empty_parent_paths_handle = null;
            throw $resume_failure;
        }
        return $processor;
    }

    /**
     * Performs one patch-planning, exact-removal, or parent-pruning step.
     *
     * Planning stores a `delete` or `replace` operation as the next durable
     * phase. A later step applies that operation. Pruning removes at most one
     * empty directory. False is stable; get_status() then returns `complete`
     * or `restart`.
     */
    public function next_step(): bool
    {
        $position = $this->cursor["position"];
        if (
            $position["phase"] === "complete"
            || $position["phase"] === "restart"
        ) {
            return false;
        }
        if ($this->closed) {
            throw new LogicException(
                "Cannot take a file sync cleanup step after close()."
            );
        }

        if ($position["phase"] === "planning") {
            $has_next_patch_step = $this->patch_processor->next_step();
            $patch_cursor = $this->patch_processor->get_cursor();
            $operation = $this->patch_processor->get_operation();
            // A selected root marks where traversal begins, not a result
            // entry. Keep that boundary when its contents are empty.
            if (
                $operation !== null
                && (
                    $operation["action"] === "delete"
                    || $operation["action"] === "replace"
                )
                && !in_array(
                    $operation["path"],
                    $this->included_index_path_roots,
                    true
                )
            ) {
                if (!isset($operation["expected_base"])) {
                    throw new LogicException(
                        "Exact file sync cleanup removal has no patch-base entry."
                    );
                }
                $this->cursor["position"] = [
                    "phase" => "removing",
                    "path_b64" => base64_encode($operation["path"]),
                    "expected_base" => $operation["expected_base"],
                    "file_sync_patch_processor_cursor" => $patch_cursor,
                ];
                return true;
            }
            if (!$has_next_patch_step) {
                $this->patch_processor->close();
                $this->cursor["position"] = ["phase" => "pruning"];
                if ($this->empty_parent_path === null) {
                    $this->cursor["position"] = ["phase" => "complete"];
                    return false;
                }
                return true;
            }
            $this->cursor["position"] = [
                "phase" => "planning",
                "file_sync_patch_processor_cursor" => $patch_cursor,
            ];
            return true;
        }

        if ($position["phase"] === "removing") {
            $index_path = self::decode_cursor_path(
                $position["path_b64"],
                "pending cleanup path"
            );
            $absolute_path = $this->absolute_selected_path($index_path);
            $path_stat = @lstat($absolute_path);
            $path_removed = !is_array($path_stat);
            if (is_array($path_stat)) {
                $local_index_entry = read_file_index_entry_from_stat(
                    $absolute_path,
                    $path_stat
                );
                $path_type = $local_index_entry["type"];
                if (
                    $path_type !== $position["expected_base"]["type"]
                    || $local_index_entry["size"]
                        !== $position["expected_base"]["size"]
                    || $local_index_entry["ctime"]
                        !== $position["expected_base"]["ctime"]
                ) {
                    $this->patch_processor->close();
                    $this->cursor["position"] = ["phase" => "restart"];
                    return false;
                }
                if ($path_type === "dir") {
                    $path_removed = @rmdir($absolute_path);
                    if (!$path_removed) {
                        clearstatcache(true, $absolute_path);
                        $remaining_path_stat = @lstat($absolute_path);
                        if (
                            is_array($remaining_path_stat)
                            && ( $remaining_path_stat["mode"] & 0170000 )
                                === 0040000
                        ) {
                            if ($this->directory_has_children($absolute_path)) {
                                $this->patch_processor->close();
                                $this->cursor["position"] = [
                                    "phase" => "restart",
                                ];
                                return false;
                            }
                            throw new RuntimeException(
                                "Failed to remove the empty local directory selected for cleanup: "
                                . base64_encode($index_path)
                            );
                        } elseif (is_array($remaining_path_stat)) {
                            $this->patch_processor->close();
                            $this->cursor["position"] = [
                                "phase" => "restart",
                            ];
                            return false;
                        } else {
                            $path_removed = true;
                        }
                    }
                } else {
                    $path_removed = @unlink($absolute_path);
                    if (!$path_removed) {
                        clearstatcache(true, $absolute_path);
                        $path_removed = !is_array(@lstat($absolute_path));
                    }
                    if (!$path_removed) {
                        throw new RuntimeException(
                            "Failed to remove the local path selected for cleanup: "
                            . base64_encode($index_path)
                        );
                    }
                }
            }
            if ($path_removed) {
                $this->schedule_parent_path($index_path);
            }
            $patch_cursor = $position[
                "file_sync_patch_processor_cursor"
            ];
            if ($patch_cursor["position"]["phase"] === "complete") {
                $this->patch_processor->close();
                if ($this->empty_parent_path === null) {
                    $this->cursor["position"] = ["phase" => "complete"];
                    return false;
                }
                $this->cursor["position"] = ["phase" => "pruning"];
                return true;
            }
            $this->cursor["position"] = [
                "phase" => "planning",
                "file_sync_patch_processor_cursor" => $patch_cursor,
            ];
            return true;
        }

        if ($this->empty_parent_path === null) {
            $this->cursor["position"] = ["phase" => "complete"];
            return false;
        }
        $index_path = $this->empty_parent_path["path"];
        $previous_byte_offset =
            $this->empty_parent_path["previous_byte_offset"];
        $absolute_path = $this->absolute_selected_path($index_path);
        $path_stat = @lstat($absolute_path);
        $removed = false;
        // Parent pruning is optional cleanup after the exact removals. A
        // changed parent belongs to a later fresh scan, so leave it in place.
        $parent_is_unchanged_directory = is_array($path_stat)
            && ( $path_stat["mode"] & 0170000 ) === 0040000
            && $this->empty_parent_path["expected_ctime"] !== null
            && (int) $path_stat["ctime"]
                === $this->empty_parent_path["expected_ctime"];
        if ($parent_is_unchanged_directory) {
            $removed = @rmdir($absolute_path);
            if (!$removed) {
                clearstatcache(true, $absolute_path);
                $remaining_path_stat = @lstat($absolute_path);
                if (
                    is_array($remaining_path_stat)
                    && ( $remaining_path_stat["mode"] & 0170000 ) === 0040000
                ) {
                    if (!$this->directory_has_children($absolute_path)) {
                        throw new RuntimeException(
                            "Failed to prune an empty parent directory during cleanup: "
                            . base64_encode($index_path)
                        );
                    }
                }
            }
        }
        $this->empty_parent_path_byte_offset = $previous_byte_offset;
        $this->cursor["empty_parent_path_stack_top_byte_offset"] =
            $previous_byte_offset;
        if ($previous_byte_offset === null) {
            $this->empty_parent_path = null;
        } else {
            $saved_parent_path = read_file_sync_path_stack_entry(
                $this->empty_parent_paths_handle,
                $previous_byte_offset
            );
            $this->empty_parent_path = [
                "path" => $saved_parent_path["path"],
                "previous_byte_offset" =>
                    $saved_parent_path["previous_byte_offset"],
                "expected_ctime" =>
                    $saved_parent_path["expected_ctime"],
            ];
        }
        if ($removed || !is_array($path_stat)) {
            $this->schedule_parent_path($index_path);
        }
        if ($this->empty_parent_path === null) {
            $this->cursor["position"] = ["phase" => "complete"];
            return false;
        }
        $this->cursor["position"] = ["phase" => "pruning"];
        return true;
    }

    /**
     * Returns everything needed to resume after the latest completed step.
     *
     * @return array {
     *     @type string       $filesystem_root_b64                         Base64-encoded canonical filesystem root.
     *     @type string       $empty_parent_paths_file_b64                 Base64-encoded path to the parent-path stack.
     *     @type int          $empty_parent_paths_file_byte_offset         Confirmed byte offset in the parent-path stack.
     *     @type int|null     $empty_parent_path_stack_top_byte_offset     Byte offset of the current stack entry.
     *     @type list<string> $included_index_path_roots_b64               Base64-encoded roots whose contents may be removed.
     *     @type list<string> $excluded_index_path_roots_b64               Base64-encoded roots cleanup must not affect.
     *     @type array        $position                                    Current planning, removing, pruning, complete, or restart phase.
     * }
     * @phpstan-return Cursor
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Returns the current cleanup phase. */
    public function get_phase(): string
    {
        if ($this->cursor["position"]["phase"] !== "planning") {
            return $this->cursor["position"]["phase"];
        }
        return $this->patch_processor->get_phase();
    }

    /** Returns `complete` or `restart` after next_step() returns false. */
    public function get_status(): ?string
    {
        $phase = $this->cursor["position"]["phase"];
        return $phase === "complete" || $phase === "restart"
            ? $phase
            : null;
    }

    /** Flushes processor work files before the caller stores get_cursor(). */
    public function flush_pending_output(): void
    {
        if (
            isset($this->patch_processor)
            && $this->cursor["position"]["phase"] !== "pruning"
            && $this->cursor["position"]["phase"] !== "complete"
            && $this->cursor["position"]["phase"] !== "restart"
        ) {
            $this->patch_processor->flush_pending_outputs();
        }
        if (
            is_resource($this->empty_parent_paths_handle)
            && !fflush($this->empty_parent_paths_handle)
        ) {
            throw new RuntimeException(
                "Failed to flush the empty parent paths file."
            );
        }
    }

    /** Closes retained handles. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (isset($this->patch_processor)) {
            $this->patch_processor->close();
        }
        if (is_resource($this->empty_parent_paths_handle)) {
            fclose($this->empty_parent_paths_handle);
        }
        $this->empty_parent_paths_handle = null;
        $this->closed = true;
    }

    /** Returns one selected absolute path without following its final symlink. */
    private function absolute_selected_path(string $index_path): string
    {
        assert_valid_relative_path($index_path, "File sync cleanup path");
        if (
            !file_sync_index_path_may_change(
                $index_path,
                $this->included_index_path_roots,
                $this->excluded_index_path_roots
            )
        ) {
            throw new RuntimeException(
                "File sync cleanup cursor selected a path outside its allowed roots: "
                . base64_encode($index_path)
            );
        }
        $absolute_path = wp_join_unix_paths(
            $this->filesystem_root,
            $index_path
        );
        $parent = realpath_with_missing_tail(dirname($absolute_path));
        if (
            relative_path_under($parent, $this->filesystem_root) === null
        ) {
            throw new RuntimeException(
                "A parent of the local cleanup path resolves outside the filesystem root: "
                . base64_encode($index_path)
            );
        }
        return $absolute_path;
    }

    /**
     * Pushes a removable parent path after a child was removed.
     *
     * Repeated paths are useful. Two siblings may schedule the same parent;
     * the first prune attempt can fail while the sibling still exists and the
     * later attempt can remove the directory after both siblings are gone.
     */
    private function schedule_parent_path(string $index_path): void
    {
        $separator = strrpos($index_path, "/");
        if ($separator === false) {
            return;
        }
        $parent_path = substr($index_path, 0, $separator);
        if (
            in_array($parent_path, $this->included_index_path_roots, true)
            || !file_sync_index_path_may_change(
                $parent_path,
                $this->included_index_path_roots,
                $this->excluded_index_path_roots
            )
        ) {
            return;
        }
        $parent_absolute_path = $this->absolute_selected_path($parent_path);
        clearstatcache(true, $parent_absolute_path);
        $parent_stat = @lstat($parent_absolute_path);
        if (
            is_array($parent_stat)
            && ( $parent_stat["mode"] & 0170000 ) !== 0040000
        ) {
            return;
        }
        $expected_ctime = is_array($parent_stat)
            ? (int) $parent_stat["ctime"]
            : null;
        $byte_offset = append_file_sync_path_stack_entry(
            $this->empty_parent_paths_handle,
            $parent_path,
            $this->empty_parent_path_byte_offset,
            $expected_ctime
        );
        $next_byte_offset = ftell($this->empty_parent_paths_handle);
        if (!is_int($next_byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the empty parent paths byte offset."
            );
        }
        $this->cursor["empty_parent_paths_file_byte_offset"] =
            $next_byte_offset;
        $previous_byte_offset = $this->empty_parent_path_byte_offset;
        $this->empty_parent_path_byte_offset = $byte_offset;
        $this->cursor["empty_parent_path_stack_top_byte_offset"] =
            $byte_offset;
        $this->empty_parent_path = [
            "path" => $parent_path,
            "previous_byte_offset" => $previous_byte_offset,
            "expected_ctime" => $expected_ctime,
        ];
    }

    /** Reports whether one directory contains an entry other than dot entries. */
    private function directory_has_children(string $absolute_path): bool
    {
        $directory_handle = @opendir($absolute_path);
        if (!is_resource($directory_handle)) {
            throw new RuntimeException(
                "Failed to inspect a local directory during file sync cleanup: "
                . base64_encode($absolute_path)
            );
        }
        try {
            while (true) {
                $entry = readdir($directory_handle);
                if ($entry === false) {
                    return false;
                }
                if ($entry !== "." && $entry !== "..") {
                    return true;
                }
            }
        } finally {
            closedir($directory_handle);
        }
    }

    /** Decodes arbitrary-byte path roots stored in the JSON cursor. */
    private static function decode_path_roots(
        array $path_roots_b64,
        string $field_name
    ): array {
        $path_roots = [];
        foreach ($path_roots_b64 as $path_root_b64) {
            $path_roots[] = self::decode_cursor_path(
                $path_root_b64,
                $field_name . " path root"
            );
        }
        return $path_roots;
    }

    /** Decodes one arbitrary-byte path stored in the JSON cursor. */
    private static function decode_cursor_path(
        string $encoded_path,
        string $field_name
    ): string {
        $path = base64_decode($encoded_path, true);
        if ($path === false) {
            throw new InvalidArgumentException(
                "File sync cleanup cursor contains an invalid base64 {$field_name}."
            );
        }
        return $path;
    }
}
