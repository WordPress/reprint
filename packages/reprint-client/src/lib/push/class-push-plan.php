<?php

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/../index/class-file-sync-patch-processor.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Journal failures are CLI/API values, never HTML output.

/**
 * Writes the path lists used by a files push.
 *
 * FileSyncPatchProcessor scans the current local tree and compares its fresh
 * local index with the saved local index. PushPlan writes each resulting
 * operation to one of two files: JSONL paths to push and NUL-delimited paths to
 * delete. It also counts the files and file bytes selected for push.
 *
 * PushFilesSender owns the public lifecycle and stores the PushPlan cursor.
 * PushPlan stores the FileSyncPatchProcessor cursor unchanged beside the
 * durable offsets of its two output files. resume() truncates both outputs to
 * those offsets before it appends more operations.
 * PushFilesSender also owns the plan directory after the plan reaches a
 * terminal result. PushPlan closes its handles but does not remove artifacts
 * which the sender still needs to upload or save.
 *
 * File and symlink changes use type, ctime, and size. Directory changes use the
 * indexer's empty-directory entry; descendants represent non-empty
 * directories. With no saved local index, the plan pushes every file, symlink,
 * and empty directory and finds no deletion. Target exclusions remain in the
 * fresh local index but produce no push or delete operation.
 *
 * Each next_step() call advances the processor once and writes at most one
 * operation. Neither class loads an index or path list in memory.
 *
 * @phpstan-type ProcessingCursor array{phase:'processing',file_sync_patch_processor_cursor:array<string,mixed>,byte_offset_in_local_paths_to_push:int,byte_offset_in_local_paths_to_delete:int,local_paths_to_push_count:int|null,local_file_bytes_to_push:int|null}
 * @phpstan-type CompleteCursor array{phase:'complete',local_paths_to_push_count:int|null,local_file_bytes_to_push:int|null}
 * @phpstan-type PushPlanPosition ProcessingCursor|CompleteCursor
 * @phpstan-type PushPlanCursor array{plan_directory:string,local_index_file:string,document_root_local_relative_path:string,position:PushPlanPosition}
 */
class PushPlan
{
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

    /** @var string Plan path containing receiver-owned exclusions for the active push. */
    private string $excluded_paths_file;

    /** @var PushPlanCursor Current cursor returned to the caller. */
    private array $cursor;

    /** @var bool Whether close() has closed this plan's file handles. */
    private bool $closed = false;

    /** Local scan and patch planner retained while the plan is open. */
    private FileSyncPatchProcessor $file_sync_patch_processor;

    /** @var resource|null */
    private $local_paths_to_push_handle = null;
    /** @var resource|null */
    private $local_paths_to_delete_handle = null;

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
            $local_index_file,
            $document_root_local_relative_path
        );
        if (!@copy($excluded_paths_path, $plan->excluded_paths_file)) {
            throw new RuntimeException("Failed to copy excluded paths into the push plan: {$excluded_paths_path}");
        }
        $contents = file_get_contents($plan->excluded_paths_file);
        if (!is_string($contents)) {
            throw new RuntimeException(
                "Failed to read excluded paths: {$plan->excluded_paths_file}"
            );
        }
        try {
            $excluded_paths_b64 = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Failed to decode excluded paths: {$plan->excluded_paths_file}",
                0,
                $exception
            );
        }
        /** @var list<string> $excluded_paths_b64 */
        $excluded_index_path_roots = [];
        foreach ($excluded_paths_b64 as $excluded_path_b64) {
            $excluded_path = base64_decode($excluded_path_b64, true);
            if ($excluded_path === false) {
                throw new RuntimeException(
                    "Failed to decode an excluded path: {$plan->excluded_paths_file}"
                );
            }
            $excluded_index_path_roots[] =
                $plan->document_root_local_relative_path === ""
                    ? $excluded_path
                    : wp_join_unix_paths(
                        $plan->document_root_local_relative_path,
                        $excluded_path
                    );
        }
        $plan->file_sync_patch_processor =
            FileSyncPatchProcessor::start_to_fresh_local_tree(
                $plan->plan_directory,
                $filesystem_root,
                $plan->local_index_file,
                $plan->plan_directory,
                [$plan->document_root_local_relative_path],
                $excluded_index_path_roots
            );
        $plan->cursor = [
            "plan_directory" => $plan->plan_directory,
            "local_index_file" => $plan->local_index_file,
            "document_root_local_relative_path" => $plan->document_root_local_relative_path,
            "position" => [
                "phase" => "processing",
                "file_sync_patch_processor_cursor" =>
                    $plan->file_sync_patch_processor->get_cursor(),
                "byte_offset_in_local_paths_to_push" => 0,
                "byte_offset_in_local_paths_to_delete" => 0,
                "local_paths_to_push_count" => 0,
                "local_file_bytes_to_push" => 0,
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
            $cursor["local_index_file"],
            $cursor["document_root_local_relative_path"]
        );
        $plan->cursor = $cursor;
        $position = $plan->cursor["position"];
        if ($position["phase"] !== "complete") {
            $plan->file_sync_patch_processor =
                FileSyncPatchProcessor::resume(
                    $position["file_sync_patch_processor_cursor"]
                );
            if ($plan->file_sync_patch_processor->get_phase() === "planning") {
                $plan->open_plan_output_files(
                    $position["byte_offset_in_local_paths_to_push"],
                    $position["byte_offset_in_local_paths_to_delete"]
                );
            }
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
     * Returns progress through the open plan.
     *
     * Durable byte offsets across both indexes describe diff progress. An
     * exact path count would require another pass over the local index.
     *
     * @return array {
     *     Current planning progress.
     *
     *     @type string $phase             Current PushPlan phase.
     *     @type int    $index_bytes_done  Index bytes consumed. Present while diffing.
     *     @type int    $index_bytes_total Combined size of both indexes. Present while diffing.
     * }
     * @phpstan-return array{phase:string,index_bytes_done?:int,index_bytes_total?:int}
     */
    public function get_progress(): array
    {
        if ($this->cursor["position"]["phase"] === "complete") {
            return ["phase" => "complete"];
        }
        $processor_progress =
            $this->file_sync_patch_processor->get_progress();
        $progress = ["phase" => $this->get_phase()];
        if (isset(
            $processor_progress["index_bytes_done"],
            $processor_progress["index_bytes_total"]
        )) {
            $progress["index_bytes_done"] =
                $processor_progress["index_bytes_done"];
            $progress["index_bytes_total"] =
                $processor_progress["index_bytes_total"];
        }
        return $progress;
    }

    /** Returns the current file sync patch processor phase. */
    public function get_phase(): string
    {
        if ($this->cursor["position"]["phase"] === "complete") {
            return "complete";
        }
        return $this->file_sync_patch_processor->get_phase();
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
        return wp_join_unix_paths(
            $this->plan_directory,
            "fresh_local_index.jsonl"
        );
    }

    /**
     * Flushes plan files before the owner persists the current cursor.
     *
     * A later process truncates bytes beyond that cursor before appending.
     */
    public function flush_pending_outputs(): void
    {
        if (isset($this->file_sync_patch_processor)) {
            $this->file_sync_patch_processor->flush_pending_outputs();
        }
        if (
            ( is_resource($this->local_paths_to_push_handle) && !fflush($this->local_paths_to_push_handle) )
            || ( is_resource($this->local_paths_to_delete_handle) && !fflush($this->local_paths_to_delete_handle) )
        ) {
            throw new RuntimeException("Failed to flush a push-plan output.");
        }
    }

    /**
     * Initializes paths in the caller-owned active plan directory.
     *
     * @param string $plan_directory   Caller-owned active plan directory.
     * @param string $local_index_file Local index file this plan diffs against.
     * @param string $document_root_local_relative_path Document root relative to the local filesystem root.
     */
    private function __construct(
        string $plan_directory,
        string $local_index_file,
        string $document_root_local_relative_path
    ) {
        $plan_directory = trim_right_slash($plan_directory);
        if (!is_dir($plan_directory)) {
            throw new LogicException("Cannot open a push plan without its directory: {$plan_directory}");
        }
        $this->plan_directory = $plan_directory;
        $this->local_index_file = $local_index_file;
        $this->document_root_local_relative_path =
            rtrim($document_root_local_relative_path, "/");
        $this->local_paths_to_push = wp_join_unix_paths($plan_directory, "local_paths_to_push.jsonl");
        $this->local_paths_to_delete = wp_join_unix_paths($plan_directory, "local_paths_to_delete");
        $this->excluded_paths_file = wp_join_unix_paths($plan_directory, "excluded_paths.json");
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

        $has_next_step = $this->file_sync_patch_processor->next_step();
        if (
            $this->file_sync_patch_processor->get_phase() === "planning"
            && !is_resource($this->local_paths_to_push_handle)
        ) {
            $this->open_plan_output_files(
                $position["byte_offset_in_local_paths_to_push"],
                $position["byte_offset_in_local_paths_to_delete"]
            );
        }

        $local_paths_to_push_count =
            $position["local_paths_to_push_count"];
        $local_file_bytes_to_push =
            $position["local_file_bytes_to_push"];
        $operation = $this->file_sync_patch_processor->get_operation();
        if ($operation !== null) {
            if ($operation["action"] !== "copy") {
                $this->append_local_path_to_delete($operation["path"]);
            }
            if ($operation["action"] !== "delete") {
                $this->append_local_path_to_push($operation);
                if ($local_paths_to_push_count !== null) {
                    ++$local_paths_to_push_count;
                }
                if (
                    $local_file_bytes_to_push !== null
                    && $operation["expected_source"]["type"] === "file"
                ) {
                    $local_file_bytes_to_push +=
                        $operation["expected_source"]["size"];
                }
            }
        }

        if (!$has_next_step) {
            $this->flush_pending_outputs();
            $this->cursor["position"] = [
                "phase" => "complete",
                "local_paths_to_push_count" => $local_paths_to_push_count,
                "local_file_bytes_to_push" => $local_file_bytes_to_push,
            ];
            return false;
        }

        $local_paths_to_push_byte_offset = is_resource(
            $this->local_paths_to_push_handle
        )
            ? ftell($this->local_paths_to_push_handle)
            : $position["byte_offset_in_local_paths_to_push"];
        $local_paths_to_delete_byte_offset = is_resource(
            $this->local_paths_to_delete_handle
        )
            ? ftell($this->local_paths_to_delete_handle)
            : $position["byte_offset_in_local_paths_to_delete"];
        if (
            !is_int($local_paths_to_push_byte_offset)
            || !is_int($local_paths_to_delete_byte_offset)
        ) {
            throw new RuntimeException(
                "Failed to determine a push-plan output byte offset."
            );
        }
        $this->cursor["position"] = [
            "phase" => "processing",
            "file_sync_patch_processor_cursor" =>
                $this->file_sync_patch_processor->get_cursor(),
            "byte_offset_in_local_paths_to_push" =>
                $local_paths_to_push_byte_offset,
            "byte_offset_in_local_paths_to_delete" =>
                $local_paths_to_delete_byte_offset,
            "local_paths_to_push_count" => $local_paths_to_push_count,
            "local_file_bytes_to_push" => $local_file_bytes_to_push,
        ];
        return true;
    }

    /** Opens both patch-plan outputs at their durable byte offsets. */
    private function open_plan_output_files(
        int $byte_offset_in_local_paths_to_push,
        int $byte_offset_in_local_paths_to_delete
    ): void {
        $this->local_paths_to_push_handle =
            $this->open_push_plan_output_file_at_byte_offset(
                $this->local_paths_to_push,
                $byte_offset_in_local_paths_to_push
            );
        $this->local_paths_to_delete_handle =
            $this->open_push_plan_output_file_at_byte_offset(
                $this->local_paths_to_delete,
                $byte_offset_in_local_paths_to_delete
            );
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
        if (isset($this->file_sync_patch_processor)) {
            $this->file_sync_patch_processor->close();
        }
        if (is_resource($this->local_paths_to_push_handle)) {
            fclose($this->local_paths_to_push_handle);
        }
        if (is_resource($this->local_paths_to_delete_handle)) {
            fclose($this->local_paths_to_delete_handle);
        }
        $this->local_paths_to_push_handle = null;
        $this->local_paths_to_delete_handle = null;
        $this->closed = true;
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
     * Appends one copy or replace operation to the JSONL push list.
     *
     * Base64 keeps arbitrary filesystem path bytes representable in JSON.
     *
     * @param array $operation {
     *     Copy or replace operation selected by FileSyncPatchPlanner.
     *
     *     @type string $action          `copy` or `replace`.
     *     @type string $path            Local relative path selected for push.
     *     @type array  $expected_source {
     *         Source state required when the path is pushed.
     *
     *         @type string $type  Expected `file`, `link`, or `dir` type.
     *         @type int    $size  Expected size.
     *         @type int    $ctime Expected inode change time.
     *     }
     * }
     * @phpstan-param array{action:'copy'|'replace',path:string,expected_source:array{type:string,size:int,ctime:int}} $operation
     */
    private function append_local_path_to_push(array $operation): void {
        $local_path_to_push_json_line = json_encode(
            [
                "path" => base64_encode($operation["path"]),
                "type" => $operation["expected_source"]["type"] === "link"
                    ? "symlink"
                    : ( $operation["expected_source"]["type"] === "dir"
                        ? "directory"
                        : "file" ),
                "size" => $operation["expected_source"]["size"],
                "ctime" => $operation["expected_source"]["ctime"],
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
        $document_root_relative_path = relative_path_under(
            $path,
            $this->document_root_local_relative_path
        );
        if ($document_root_relative_path === null) {
            return;
        }
        $document_root_relative_path_with_nul = $document_root_relative_path . "\0";
        if (fwrite($this->local_paths_to_delete_handle, $document_root_relative_path_with_nul) !== strlen($document_root_relative_path_with_nul)) {
            throw new RuntimeException("Short write on local paths to delete {$this->local_paths_to_delete}, is the disk full?");
        }
    }

}
