<?php

use function WordPress\Reprint\Server\relative_path_under;

require_once __DIR__ . '/class-file-sync-patch-planner.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths are CLI/API values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint streaming classes use domain names.

/**
 * Materializes one file-sync plan as bounded copy and deletion list steps.
 *
 * The runner does not copy or delete filesystem paths. Each next_step() call
 * processes at most one path selected by FileSyncPatchPlanner, appends its
 * copy and deletion records, and updates the in-memory cursor. The caller
 * flushes pending outputs before storing that cursor.
 *
 * Copy records use the patch-head entry's optional `copy_source_path`;
 * otherwise they use its index path. Deletion records use the planned target
 * path relative to the configured deletion path prefix.
 *
 * An optional paths-requiring-copy file is JSONL with a base64 `path` field.
 * Its paths must be a subset of the two index paths and occur in strictly
 * increasing byte order. A matching selected entry adds a copy only when the
 * patch head contains that path. The runner keeps one lookahead entry and
 * never scans forward to catch up with the planner.
 *
 * The cursor does not store index line decoders. Pass the same decoders to
 * resume() that were used to create the planner passed to start().
 *
 * @phpstan-type IndexEntry array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,empty?:bool,copy_source_path?:string}
 * @phpstan-type PlannerIndexDiffCursor array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null}
 * @phpstan-type PlannerCursor array{patch_base_index_file:string,patch_head_index_file:string,active_deletion_roots_file:string,included_index_path_roots:list<string>,excluded_index_path_roots:list<string>,index_diff_cursor:PlannerIndexDiffCursor,active_deletion_root_byte_offset:int|null}
 * @phpstan-type PlanningPosition array{phase:'planning',file_sync_patch_planner_cursor:PlannerCursor,byte_offset_in_paths_to_copy:int,byte_offset_in_paths_to_delete:int,byte_offset_in_paths_requiring_copy:int,paths_to_copy_count:int,file_bytes_to_copy:int}
 * @phpstan-type CompletePosition array{phase:'complete',paths_to_copy_count:int,file_bytes_to_copy:int}
 * @phpstan-type Cursor array{paths_to_copy_file:string,paths_to_delete_file:string,deletion_path_prefix:string,paths_requiring_copy_file:string|null,position:PlanningPosition|CompletePosition}
 * @phpstan-type StartOptions array{paths_to_copy_file:string,paths_to_delete_file:string,deletion_path_prefix:string,paths_requiring_copy_file?:string|null}
 */
final class FileSyncPlanRunner {
    /** @var Cursor */
    private array $cursor;

    private FileSyncPatchPlanner $patch_planner;

    /** @var resource|null */
    private $paths_to_copy_handle = null;

    /** @var resource|null */
    private $paths_to_delete_handle = null;

    /** @var resource|null */
    private $paths_requiring_copy_handle = null;

    /** Current unconsumed path from the paths-requiring-copy input. */
    private ?string $path_requiring_copy = null;

    /** Byte offset immediately after the retained paths-requiring-copy entry. */
    private ?int $next_path_requiring_copy_byte_offset = null;

    /** Whether the paths-requiring-copy input has reached EOF. */
    private bool $paths_requiring_copy_complete = false;

    private bool $closed = false;

    /**
     * Starts materializing operations from a fresh patch planner.
     *
     * The planner must be open and positioned before its first path. After it
     * is passed here, the caller must not use or close it. The runner closes
     * the planner if setup fails or when close() is called.
     *
     * Both output files are replaced. The optional paths-requiring-copy file
     * is opened read-only.
     *
     * @param FileSyncPatchPlanner $patch_planner Fresh planner whose operations will be materialized.
     * @param array                $options {
     *     Plan output files and path rules.
     *
     *     @type string      $paths_to_copy_file        JSONL copy-plan output.
     *     @type string      $paths_to_delete_file      NUL-delimited deletion-plan output.
     *     @type string      $deletion_path_prefix      Index path prefix stripped from deletion targets.
     *     @type string|null $paths_requiring_copy_file Optional. JSONL paths in strictly increasing byte order which require a patch-head copy. Default null.
     * }
     * @phpstan-param StartOptions $options
     */
    public static function start(
        FileSyncPatchPlanner $patch_planner,
        array $options
    ): self {
        $runner = new self();
        $runner->patch_planner = $patch_planner;

        try {
            if (!$patch_planner->is_positioned_before_first_path()) {
                throw new InvalidArgumentException(
                    "FileSyncPlanRunner::start() requires a fresh patch planner "
                    . "positioned before its first path; "
                    . "is_positioned_before_first_path() returned false."
                );
            }

            $allowed_option_names = [
                "paths_to_copy_file",
                "paths_to_delete_file",
                "deletion_path_prefix",
                "paths_requiring_copy_file",
            ];
            foreach (array_keys($options) as $option_name) {
                if (!in_array($option_name, $allowed_option_names, true)) {
                    throw new InvalidArgumentException(
                        "FileSyncPlanRunner::start() does not accept the "
                        . "{$option_name} option."
                    );
                }
            }

            foreach (
                [
                    "paths_to_copy_file",
                    "paths_to_delete_file",
                    "deletion_path_prefix",
                ] as $required_string_option
            ) {
                if (!array_key_exists($required_string_option, $options)) {
                    throw new InvalidArgumentException(
                        "FileSyncPlanRunner::start() requires the "
                        . "{$required_string_option} option."
                    );
                }
                if (!is_string($options[$required_string_option])) {
                    throw new InvalidArgumentException(
                        "FileSyncPlanRunner::start() requires the "
                        . "{$required_string_option} option to be a string; "
                        . "received "
                        . self::describe_observed_type(
                            $options[$required_string_option]
                        )
                        . "."
                    );
                }
            }

            $paths_requiring_copy_file = array_key_exists(
                "paths_requiring_copy_file",
                $options
            ) ? $options["paths_requiring_copy_file"] : null;
            if (
                $paths_requiring_copy_file !== null
                && !is_string($paths_requiring_copy_file)
            ) {
                throw new InvalidArgumentException(
                    "FileSyncPlanRunner::start() requires the "
                    . "paths_requiring_copy_file option to be a string or null; "
                    . "received "
                    . self::describe_observed_type($paths_requiring_copy_file)
                    . "."
                );
            }

            $runner->cursor = [
                "paths_to_copy_file" => $options["paths_to_copy_file"],
                "paths_to_delete_file" => $options["paths_to_delete_file"],
                "deletion_path_prefix" => $options["deletion_path_prefix"],
                "paths_requiring_copy_file" => $paths_requiring_copy_file,
                "position" => [
                    "phase" => "planning",
                    "file_sync_patch_planner_cursor" =>
                        $patch_planner->get_cursor(),
                    "byte_offset_in_paths_to_copy" => 0,
                    "byte_offset_in_paths_to_delete" => 0,
                    "byte_offset_in_paths_requiring_copy" => 0,
                    "paths_to_copy_count" => 0,
                    "file_bytes_to_copy" => 0,
                ],
            ];

            $runner->open_plan_files_at_byte_offsets(0, 0, 0);
            return $runner;
        } catch (Throwable $throwable) {
            $runner->close();
            throw $throwable;
        }
    }

    /**
     * Returns a PHP 7.4-compatible name for one invalid option value type.
     *
     * @param mixed $value Invalid option value.
     */
    private static function describe_observed_type($value): string
    {
        return $value === null ? "null" : gettype($value);
    }

    /**
     * Resumes materializing a plan at its last stored cursor.
     *
     * Bytes beyond the two stored output offsets are discarded. The index and
     * paths-requiring-copy inputs must still contain the same snapshots used by
     * start().
     *
     * @param array $cursor {
     *     Cursor previously returned by get_cursor().
     *
     *     @type string      $paths_to_copy_file       JSONL copy-plan output path.
     *     @type string      $paths_to_delete_file     NUL-delimited deletion-plan output path.
     *     @type string      $deletion_path_prefix     Prefix stripped from deletion targets.
     *     @type string|null $paths_requiring_copy_file Optional sorted required-copy input path.
     *     @type array       $position {
     *         Current planning or complete position.
     *
     *         @type string   $phase                                 `planning` or `complete`.
     *         @type array    $file_sync_patch_planner_cursor        Planner cursor. Planning only.
     *         @type int      $byte_offset_in_paths_to_copy          Durable copy-output offset. Planning only.
     *         @type int      $byte_offset_in_paths_to_delete        Durable deletion-output offset. Planning only.
     *         @type int      $byte_offset_in_paths_requiring_copy   Durable required-copy offset. Planning only.
     *         @type int      $paths_to_copy_count                    Number of copy records.
     *         @type int      $file_bytes_to_copy                     Combined file bytes.
     *     }
     * }
     * @phpstan-param Cursor $cursor Cursor previously returned by get_cursor().
     * @param callable|null $decode_patch_base_index_line  Decoder used to create the planner passed to start().
     * @param callable|null $decode_patch_head_index_line  Patch-head decoder used to create that planner.
     * @phpstan-param (callable(string):IndexEntry)|null $decode_patch_base_index_line
     * @phpstan-param (callable(string):IndexEntry)|null $decode_patch_head_index_line
     */
    public static function resume(
        array $cursor,
        ?callable $decode_patch_base_index_line = null,
        ?callable $decode_patch_head_index_line = null
    ): self {
        $runner = new self();
        $runner->cursor = $cursor;
        $position = $cursor["position"];
        if ($position["phase"] === "complete") {
            return $runner;
        }

        $runner->patch_planner = FileSyncPatchPlanner::resume(
            $position["file_sync_patch_planner_cursor"],
            $decode_patch_base_index_line,
            $decode_patch_head_index_line
        );
        $runner->open_plan_files_at_byte_offsets(
            $position["byte_offset_in_paths_to_copy"],
            $position["byte_offset_in_paths_to_delete"],
            $position["byte_offset_in_paths_requiring_copy"]
        );
        return $runner;
    }

    /**
     * Processes at most one path and appends its copy and deletion records.
     *
     * False means the plan is complete and remains false on later calls. The
     * final path is written by the call which first returns false.
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
            throw new LogicException(
                "Cannot take a file sync plan runner step after close()."
            );
        }

        if (!$this->patch_planner->next_path()) {
            return $this->complete_plan(
                $position["paths_to_copy_count"],
                $position["file_bytes_to_copy"]
            );
        }

        $patch_base_index_entry =
            $this->patch_planner->get_entry_in_patch_base_index();
        /** @var IndexEntry|null $patch_head_index_entry */
        $patch_head_index_entry =
            $this->patch_planner->get_entry_in_patch_head_index();
        $current_path = $patch_head_index_entry["path"]
            ?? $patch_base_index_entry["path"]
            ?? null;
        if ($current_path === null) {
            throw new LogicException(
                "The file sync patch planner processed a path without an index entry."
            );
        }

        $path_requires_copy = false;
        if (is_resource($this->paths_requiring_copy_handle)) {
            $this->load_path_requiring_copy();
            if ($this->path_requiring_copy !== null) {
                $path_comparison = strcmp(
                    $this->path_requiring_copy,
                    $current_path
                );
                if ($path_comparison < 0) {
                    throw new RuntimeException(
                        "The paths-requiring-copy entry "
                        . base64_encode($this->path_requiring_copy)
                        . " does not match any remaining patch-index path."
                    );
                }
                if ($path_comparison === 0) {
                    $path_requires_copy =
                        $this->patch_planner->current_path_may_change();
                    $position["byte_offset_in_paths_requiring_copy"] =
                        $this->next_path_requiring_copy_byte_offset;
                    $this->path_requiring_copy = null;
                    $this->next_path_requiring_copy_byte_offset = null;
                }
            }
        }

        $operation = $this->patch_planner->get_operation();
        if ($operation !== null && $operation["action"] !== "copy") {
            $target_relative_path = relative_path_under(
                $operation["path"],
                $this->cursor["deletion_path_prefix"]
            );
            if ($target_relative_path === null) {
                throw new RuntimeException(
                    "The planned deletion path "
                    . base64_encode($operation["path"])
                    . " is outside the deletion path prefix "
                    . base64_encode($this->cursor["deletion_path_prefix"])
                    . "."
                );
            }
            self::write_bytes(
                $this->paths_to_delete_handle,
                $target_relative_path . "\0",
                "paths-to-delete output "
                    . $this->cursor["paths_to_delete_file"]
            );
        }

        $operation_needs_copy =
            $operation !== null && $operation["action"] !== "delete";
        if (
            ( $operation_needs_copy || $path_requires_copy )
            && $patch_head_index_entry !== null
        ) {
            $source_path = $patch_head_index_entry["copy_source_path"]
                ?? $patch_head_index_entry["path"];
            // Base64 keeps arbitrary filesystem path bytes representable in JSON.
            $copy_line = json_encode(
                [
                    "path" => base64_encode($source_path),
                    "type" => $patch_head_index_entry["type"] === "link"
                        ? "symlink"
                        : ( $patch_head_index_entry["type"] === "dir"
                            ? "directory"
                            : "file" ),
                    "size" => $patch_head_index_entry["size"],
                    "ctime" => $patch_head_index_entry["ctime"],
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
            self::write_bytes(
                $this->paths_to_copy_handle,
                $copy_line,
                "paths-to-copy output "
                    . $this->cursor["paths_to_copy_file"]
            );
            ++$position["paths_to_copy_count"];
            if ($patch_head_index_entry["type"] === "file") {
                $position["file_bytes_to_copy"] +=
                    $patch_head_index_entry["size"];
            }
        }

        if ($this->patch_planner->is_complete()) {
            return $this->complete_plan(
                $position["paths_to_copy_count"],
                $position["file_bytes_to_copy"]
            );
        }

        $paths_to_copy_byte_offset = ftell($this->paths_to_copy_handle);
        $paths_to_delete_byte_offset = ftell($this->paths_to_delete_handle);
        if (
            !is_int($paths_to_copy_byte_offset)
            || !is_int($paths_to_delete_byte_offset)
        ) {
            throw new RuntimeException(
                "Failed to determine a file sync plan output byte offset."
            );
        }

        $this->cursor["position"] = [
            "phase" => "planning",
            "file_sync_patch_planner_cursor" =>
                $this->patch_planner->get_cursor(),
            "byte_offset_in_paths_to_copy" => $paths_to_copy_byte_offset,
            "byte_offset_in_paths_to_delete" => $paths_to_delete_byte_offset,
            "byte_offset_in_paths_requiring_copy" =>
                $position["byte_offset_in_paths_requiring_copy"],
            "paths_to_copy_count" => $position["paths_to_copy_count"],
            "file_bytes_to_copy" => $position["file_bytes_to_copy"],
        ];
        return true;
    }

    /**
     * Returns the cursor after the latest completed step.
     *
     * @return array {
     *     Cursor which may be stored and passed to resume().
     *
     *     @type string      $paths_to_copy_file       JSONL copy-plan output path.
     *     @type string      $paths_to_delete_file     NUL-delimited deletion-plan output path.
     *     @type string      $deletion_path_prefix     Prefix stripped from deletion targets.
     *     @type string|null $paths_requiring_copy_file Optional sorted required-copy input path.
     *     @type array       $position {
     *         Current planning or complete position.
     *
     *         @type string   $phase                                 `planning` or `complete`.
     *         @type array    $file_sync_patch_planner_cursor        Planner cursor. Planning only.
     *         @type int      $byte_offset_in_paths_to_copy          Durable copy-output offset. Planning only.
     *         @type int      $byte_offset_in_paths_to_delete        Durable deletion-output offset. Planning only.
     *         @type int      $byte_offset_in_paths_requiring_copy   Durable required-copy offset. Planning only.
     *         @type int      $paths_to_copy_count                    Number of copy records.
     *         @type int      $file_bytes_to_copy                     Combined file bytes.
     *     }
     * }
     * @phpstan-return Cursor
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Returns whether every patch path was processed and both outputs are complete. */
    public function is_complete(): bool
    {
        return $this->cursor["position"]["phase"] === "complete";
    }

    /** Returns the number of copy records. */
    public function get_paths_to_copy_count(): int
    {
        return $this->cursor["position"]["paths_to_copy_count"];
    }

    /** Returns the combined file bytes to copy. */
    public function get_file_bytes_to_copy(): int
    {
        return $this->cursor["position"]["file_bytes_to_copy"];
    }

    /**
     * Returns the durable bytes consumed from both patch indexes while planning.
     *
     * @throws LogicException When the plan is complete and no index cursor remains.
     */
    public function get_index_bytes_done(): int
    {
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            throw new LogicException(
                "A completed file sync plan has no open index-diff cursor."
            );
        }

        $index_diff_cursor =
            $position["file_sync_patch_planner_cursor"]["index_diff_cursor"];
        return $index_diff_cursor["old_index_byte_offset"]
            + $index_diff_cursor["new_index_byte_offset"];
    }

    /** Flushes planner state and both plan outputs before the cursor is stored. */
    public function flush_pending_outputs(): void
    {
        if (
            is_resource($this->paths_to_copy_handle)
            && !fflush($this->paths_to_copy_handle)
        ) {
            throw new RuntimeException(
                "Failed to flush the paths-to-copy output: "
                . $this->cursor["paths_to_copy_file"]
            );
        }
        if (
            is_resource($this->paths_to_delete_handle)
            && !fflush($this->paths_to_delete_handle)
        ) {
            throw new RuntimeException(
                "Failed to flush the paths-to-delete output: "
                . $this->cursor["paths_to_delete_file"]
            );
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->flush_pending_outputs();
        }
    }

    /** Closes retained planner and file handles. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (isset($this->patch_planner)) {
            $this->patch_planner->close();
        }
        foreach (
            [
                "paths_to_copy_handle",
                "paths_to_delete_handle",
                "paths_requiring_copy_handle",
            ] as $handle_property
        ) {
            if (is_resource($this->{$handle_property})) {
                fclose($this->{$handle_property});
            }
            $this->{$handle_property} = null;
        }
        $this->closed = true;
    }

    /** Flushes the final outputs and stores a stable complete position. */
    private function complete_plan(
        int $paths_to_copy_count,
        int $file_bytes_to_copy
    ): bool {
        $this->assert_no_unmatched_path_requiring_copy();
        $this->flush_pending_outputs();
        $this->cursor["position"] = [
            "phase" => "complete",
            "paths_to_copy_count" => $paths_to_copy_count,
            "file_bytes_to_copy" => $file_bytes_to_copy,
        ];
        return false;
    }

    /** Loads at most one paths-requiring-copy entry as retained lookahead. */
    private function load_path_requiring_copy(): void
    {
        if (
            $this->path_requiring_copy !== null
            || $this->paths_requiring_copy_complete
            || !is_resource($this->paths_requiring_copy_handle)
        ) {
            return;
        }

        $line = fgets($this->paths_requiring_copy_handle);
        if ($line === false) {
            if (!feof($this->paths_requiring_copy_handle)) {
                throw new RuntimeException(
                    "Failed to read the paths-requiring-copy input."
                );
            }
            $this->paths_requiring_copy_complete = true;
            return;
        }
        $next_byte_offset = ftell($this->paths_requiring_copy_handle);
        if (!is_int($next_byte_offset)) {
            throw new RuntimeException(
                "Failed to determine the paths-requiring-copy byte offset."
            );
        }

        try {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Failed to decode a paths-requiring-copy entry.",
                0,
                $exception
            );
        }
        if (!is_array($entry) || !isset($entry["path"]) || !is_string($entry["path"])) {
            throw new RuntimeException(
                "A paths-requiring-copy entry must contain a base64 path."
            );
        }
        $path = base64_decode($entry["path"], true);
        if ($path === false) {
            throw new RuntimeException(
                "A paths-requiring-copy entry contains an invalid base64 path."
            );
        }
        $this->path_requiring_copy = $path;
        $this->next_path_requiring_copy_byte_offset = $next_byte_offset;
    }

    /** Rejects a paths-requiring-copy entry beyond the completed index union. */
    private function assert_no_unmatched_path_requiring_copy(): void
    {
        if (!is_resource($this->paths_requiring_copy_handle)) {
            return;
        }
        $this->load_path_requiring_copy();
        if ($this->path_requiring_copy !== null) {
            throw new RuntimeException(
                "The paths-requiring-copy entry "
                . base64_encode($this->path_requiring_copy)
                . " does not match any remaining patch-index path."
            );
        }
    }

    /** Opens retained plan files at the supplied durable byte offsets. */
    private function open_plan_files_at_byte_offsets(
        int $paths_to_copy_byte_offset,
        int $paths_to_delete_byte_offset,
        int $paths_requiring_copy_byte_offset
    ): void {
        try {
            $this->paths_to_copy_handle = self::open_output_at_byte_offset(
                $this->cursor["paths_to_copy_file"],
                $paths_to_copy_byte_offset,
                "paths-to-copy output"
            );
            $this->paths_to_delete_handle = self::open_output_at_byte_offset(
                $this->cursor["paths_to_delete_file"],
                $paths_to_delete_byte_offset,
                "paths-to-delete output"
            );
            $paths_requiring_copy_file =
                $this->cursor["paths_requiring_copy_file"];
            if ($paths_requiring_copy_file !== null) {
                $this->paths_requiring_copy_handle = fopen(
                    $paths_requiring_copy_file,
                    "rb"
                );
                if (
                    !is_resource($this->paths_requiring_copy_handle)
                    || fseek(
                        $this->paths_requiring_copy_handle,
                        $paths_requiring_copy_byte_offset
                    ) !== 0
                ) {
                    throw new RuntimeException(
                        "Failed to open the paths-requiring-copy input "
                        . "{$paths_requiring_copy_file} at byte "
                        . "{$paths_requiring_copy_byte_offset}."
                    );
                }
            }
        } catch (Throwable $throwable) {
            $this->close();
            throw $throwable;
        }
    }

    /**
     * Opens one output at its durable cursor and discards later bytes.
     *
     * Plan output is flushed before its cursor is stored, so a valid cursor
     * cannot exceed the output length. A process may stop after writing output
     * but before storing its next cursor. Truncating to the saved offset
     * removes only that unstored tail before the plan continues.
     *
     * @return resource Writable output handle positioned at the cursor.
     */
    private static function open_output_at_byte_offset(
        string $path,
        int $byte_offset,
        string $description
    ) {
        $handle = fopen($path, "c+b");
        if (!is_resource($handle)) {
            throw new RuntimeException("Failed to open the {$description}: {$path}");
        }
        if (
            !ftruncate($handle, $byte_offset)
            || fseek($handle, $byte_offset) !== 0
        ) {
            fclose($handle);
            throw new RuntimeException(
                "Failed to truncate and seek the {$description} {$path} "
                . "to byte {$byte_offset}."
            );
        }
        return $handle;
    }

    /** @param resource $handle Writable plan output. */
    private static function write_bytes(
        $handle,
        string $bytes,
        string $description
    ): void {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException(
                "Short write on the {$description}, is the disk full?"
            );
        }
    }
}
