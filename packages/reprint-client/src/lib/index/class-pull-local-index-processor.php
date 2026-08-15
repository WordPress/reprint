<?php

use function Reprint\Importer\file_sync_index_path_may_change;
use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\sort_index_file_preserving_duplicate_paths;
use function Reprint\Importer\write_local_index_entry;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\read_file_index_directory_is_empty;
use function WordPress\Reprint\Exporter\read_file_index_entry_from_stat;
use function WordPress\Reprint\Exporter\relative_path_under;

require_once __DIR__ . '/../local-index-update-functions.php';
require_once __DIR__ . '/../sort-index-file.php';
require_once __DIR__ . '/../pull/class-remote-index-reader.php';
require_once __DIR__ . '/../pull/class-remote-to-local-path-mapper.php';
require_once __DIR__ . '/class-file-index-diff-processor.php';
require_once __DIR__ . '/class-file-sync-patch-processor.php';
require_once __DIR__ . '/file-sync-path-functions.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Index paths and files are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Reprint streaming classes use domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing streaming classes.

/**
 * Builds a local-coordinate index for the next remote tree.
 *
 * A pull mapping can change path order. The processor maps one selected remote
 * entry per step, sorts the mapped entries by local relative path, then merges
 * them with the retained local index. It replaces retained entries inside the
 * selected roots and keeps entries outside them or across an excluded root.
 *
 * start_patch_result() checks the WAL-applied remote index against the next
 * remote index, then copies retained local metadata into local coordinates.
 * FileSyncPatchProcessor can use that temporary index to plan cleanup after
 * remote catch-up without comparing ctime values from different machines.
 *
 * start_next_local_index() requires the selected paths in the remote index and
 * next remote index to match in presence, type, size, and ctime. Candidate
 * entries use the WAL-applied retained local metadata recorded after fetch. A
 * remote empty-directory row is written only when the local directory is
 * physically empty. A child omitted from the pull therefore keeps its parent
 * directory implicit instead of recording that parent as empty.
 *
 * The completed candidate is checked by FileSyncPatchProcessor against one
 * final fresh local index. Any selected difference means local state changed
 * after the pull work was confirmed, so the processor returns `restart`. A
 * candidate with no selected difference returns `complete` and can replace the
 * retained local index.
 *
 * One next_step() maps and confirms one remote entry, sorts one work file,
 * starts the merge, scans one implied-parent record, merges one local path, or
 * performs one final verification step. Flush pending output before storing
 * get_cursor(). Resume truncates output to its saved byte offset.
 *
 *     $processor = PullLocalIndexProcessor::start_next_local_index(
 *         $work_directory,
 *         $next_remote_index_file,
 *         $remote_index_file,
 *         $local_index_file,
 *         $path_mapper,
 *         $storage_path,
 *         false
 *     );
 *     do {
 *         $has_next_step = $processor->next_step();
 *         $processor->flush_pending_output();
 *         save_cursor($processor->get_cursor());
 *     } while ($has_next_step);
 *     if ($processor->get_status() === 'complete') {
 *         replace_local_index($processor->get_index_path());
 *     }
 *     $processor->close();
 *
 * @phpstan-type MapperConfig array{filesystem_root_b64:string,original_remote_roots_b64:list<string>,resolved_path_mappings:list<array{remote_prefix_b64:string,local_prefix_b64:string}>,local_followed_symlinks_root_b64:string|null}
 * @phpstan-type IndexDiffCursor array{old_index_byte_offset:int,new_index_byte_offset:int,preceding_new_index_entry_path_b64:string|null}
 * @phpstan-type Position array{phase:'mapping',remote_index_byte_offset?:int,remote_index_diff_cursor?:IndexDiffCursor,mapped_index_byte_offset:int,mapped_index_parents_byte_offset:int}|array{phase:'sorting'|'sorting_parents'|'starting_merge'}|array{phase:'merging',index_diff_cursor:IndexDiffCursor,mapped_index_parent_cursor:IndexDiffCursor,index_byte_offset:int}|array{phase:'verifying',file_sync_patch_processor_cursor:array}|array{phase:'complete'|'restart'}
 * @phpstan-type Cursor array{metadata_source:'remote_recorded'|'retained_local'|'locally_observed',next_remote_index_file_b64:string,remote_index_file_b64:string|null,remote_index_exists:bool,retained_local_index_file_b64:string,retained_local_index_exists:bool,mapped_index_file_b64:string,mapped_index_parents_file_b64:string,index_file_b64:string,path_mapper_config:MapperConfig,included_local_index_path_roots_b64:list<string>,excluded_local_index_path_roots_b64:list<string>,verification_storage_path_b64:string,verification_include_caches:bool,position:Position}
 */
final class PullLocalIndexProcessor
{
    /** @var Cursor Cursor after the latest completed step. */
    private array $cursor;

    private string $next_remote_index_file;
    private string $retained_local_index_file;
    private string $mapped_index_file;
    private string $mapped_index_parents_file;
    private string $index_file;
    private RemoteToLocalPathMapper $path_mapper;

    /** @var list<string> */
    private array $included_local_index_path_roots;

    /** @var list<string> */
    private array $excluded_local_index_path_roots;

    private ?RemoteIndexReader $remote_index_reader = null;
    private ?FileIndexDiffProcessor $remote_index_diff = null;
    private bool $remote_index_path_selected = false;

    /** @var resource|null Mapped entries written before sorting. */
    private $mapped_index_handle = null;

    /** @var resource|null Implied-parent records written while mapping. */
    private $mapped_index_parents_handle = null;

    private ?FileIndexDiffProcessor $index_diff = null;
    private bool $index_diff_path_selected = false;
    private ?FileIndexDiffProcessor $mapped_index_parent_reader = null;
    private bool $mapped_index_parent_path_selected = false;

    /** @var resource|null */
    private $index_handle = null;

    private ?FileSyncPatchProcessor $verification_processor = null;

    private bool $closed = false;

    /**
     * Starts a local-coordinate patch-result index after remote catch-up.
     *
     * @param string                  $work_directory            Existing directory for processor work files.
     * @param string                  $next_remote_index_file Immutable next remote index.
     * @param string                  $remote_index_file WAL-applied remote index after catch-up.
     * @param string                  $retained_local_index_file Retained local index, or a missing path on the first pull.
     * @param RemoteToLocalPathMapper $path_mapper               Path mapping used by this pull.
     * @param list<string> $included_local_index_path_roots Local roots which the pull may change.
     * @param list<string> $excluded_local_index_path_roots Local roots which the pull must not affect.
     * @param bool         $include_caches                  Whether the local scan includes default-skipped paths.
     */
    public static function start_patch_result(
        string $work_directory,
        string $next_remote_index_file,
        string $remote_index_file,
        string $retained_local_index_file,
        RemoteToLocalPathMapper $path_mapper,
        array $included_local_index_path_roots = [""],
        array $excluded_local_index_path_roots = [],
        bool $include_caches = false
    ): self {
        return self::start(
            "retained_local", $work_directory,
            $next_remote_index_file,
            $remote_index_file,
            $retained_local_index_file,
            $path_mapper,
            $included_local_index_path_roots,
            $excluded_local_index_path_roots,
            "",
            $include_caches
        );
    }

    /**
     * Starts the next local index using local metadata recorded after fetch.
     *
     * @param string                  $work_directory            Existing directory for processor work files.
     * @param string                  $next_remote_index_file Immutable next remote index.
     * @param string                  $remote_index_file WAL-applied remote index containing confirmed fetch records, or a missing path for an empty index.
     * @param string                  $retained_local_index_file WAL-applied local index containing confirmed local metadata.
     * @param RemoteToLocalPathMapper $path_mapper               Path mapping used by this pull.
     * @param string       $storage_path                    Reprint storage path omitted from final verification.
     * @param bool         $include_caches                  Whether final verification includes cache paths.
     * @param list<string> $included_local_index_path_roots Local roots which the pull may change.
     * @param list<string> $excluded_local_index_path_roots Local roots which the pull must not affect.
     */
    public static function start_next_local_index(
        string $work_directory,
        string $next_remote_index_file,
        string $remote_index_file,
        string $retained_local_index_file,
        RemoteToLocalPathMapper $path_mapper,
        string $storage_path,
        bool $include_caches,
        array $included_local_index_path_roots = [""],
        array $excluded_local_index_path_roots = []
    ): self {
        return self::start(
            "locally_observed", $work_directory,
            $next_remote_index_file,
            $remote_index_file,
            $retained_local_index_file,
            $path_mapper,
            $included_local_index_path_roots,
            $excluded_local_index_path_roots,
            $storage_path,
            $include_caches
        );
    }

    /**
     * Resumes from a cursor returned by get_cursor().
     *
     * Mapping and merging outputs are truncated to the stored byte offsets.
     * The work files required by the saved phase must still exist. During
     * mapping, the remote index must retain its starting presence; a saved
     * missing path remains an empty index. A complete cursor also requires its
     * completed output index.
     *
     * @phpstan-param Cursor $cursor
     */
    public static function resume(array $cursor): self
    {
        $processor = new self();
        $processor->cursor = $cursor;
        if (
            $cursor["metadata_source"] !== "remote_recorded"
            && $cursor["metadata_source"] !== "retained_local"
            && $cursor["metadata_source"] !== "locally_observed"
        ) {
            throw new InvalidArgumentException("Pull local index cursor contains an invalid metadata source.");
        }
        $processor->next_remote_index_file = self::decode_cursor_path($cursor["next_remote_index_file_b64"]);
        $processor->retained_local_index_file = self::decode_cursor_path($cursor["retained_local_index_file_b64"]);
        $processor->mapped_index_file = self::decode_cursor_path($cursor["mapped_index_file_b64"]);
        $processor->mapped_index_parents_file = self::decode_cursor_path(
            $cursor["mapped_index_parents_file_b64"]
        );
        $processor->index_file = self::decode_cursor_path($cursor["index_file_b64"]);
        $processor->path_mapper = RemoteToLocalPathMapper::from_config($cursor["path_mapper_config"]);
        $decode_path = [self::class, "decode_cursor_path"];
        $processor->included_local_index_path_roots = array_map(
            $decode_path, $cursor["included_local_index_path_roots_b64"]
        );
        $processor->excluded_local_index_path_roots = array_map(
            $decode_path, $cursor["excluded_local_index_path_roots_b64"]
        );
        $position = $cursor["position"];
        self::require_file($processor->index_file, "output index");
        if (
            $position["phase"] === "complete"
            || $position["phase"] === "restart"
        ) {
            return $processor;
        }
        if ($position["phase"] === "verifying") {
            $processor->verification_processor =
                FileSyncPatchProcessor::resume(
                    $position["file_sync_patch_processor_cursor"]
                );
            return $processor;
        }
        self::require_file($processor->mapped_index_file, "mapped index");
        self::require_file(
            $processor->mapped_index_parents_file,
            "mapped index parents"
        );

        if ($position["phase"] === "mapping") {
            self::require_file($processor->next_remote_index_file, "next remote index");
            try {
                if ($cursor["metadata_source"] !== "remote_recorded") {
                    if ($cursor["remote_index_file_b64"] === null) {
                        throw new InvalidArgumentException(
                            "Pull local index cursor is missing its remote index."
                        );
                    }
                    $remote_index_file = self::decode_cursor_path(
                        $cursor["remote_index_file_b64"]
                    );
                    $remote_index_exists = self::optional_file_exists(
                        $remote_index_file,
                        "remote index"
                    );
                    if (
                        $remote_index_exists
                            !== $cursor["remote_index_exists"]
                    ) {
                        throw new RuntimeException(
                            "The remote index presence changed during pull local indexing."
                        );
                    }
                    $processor->remote_index_diff =
                        FileIndexDiffProcessor::resume(
                            $remote_index_file,
                            $processor->next_remote_index_file,
                            $position[
                                "remote_index_diff_cursor"
                            ],
                            [RemoteIndexReader::class, "decode_index_line"]
                        );
                } else {
                    $processor->remote_index_reader = new RemoteIndexReader(
                        $processor->next_remote_index_file
                    );
                    $processor->remote_index_reader->open();
                    $processor->remote_index_reader->seek_to_byte_offset(
                        $position["remote_index_byte_offset"]
                    );
                }
                $processor->mapped_index_handle =
                    self::open_and_truncate_to_saved_byte_offset(
                        $processor->mapped_index_file,
                        $position["mapped_index_byte_offset"],
                        "mapped pull index"
                    );
                $processor->mapped_index_parents_handle =
                    self::open_and_truncate_to_saved_byte_offset(
                        $processor->mapped_index_parents_file,
                        $position["mapped_index_parents_byte_offset"],
                        "mapped pull index parents"
                    );
            } catch (Throwable $throwable) {
                $processor->close();
                throw $throwable;
            }
            return $processor;
        }

        if (
            $position["phase"] === "sorting"
            || $position["phase"] === "sorting_parents"
        ) {
            return $processor;
        }
        $processor->assert_retained_local_index_state();
        if ($position["phase"] === "starting_merge") {
            return $processor;
        }
        if ($position["phase"] !== "merging") {
            throw new InvalidArgumentException("Pull local index cursor contains an invalid phase.");
        }

        try {
            $processor->index_diff = FileIndexDiffProcessor::resume(
                $processor->retained_local_index_file,
                $processor->mapped_index_file,
                $position["index_diff_cursor"]
            );
            $processor->mapped_index_parent_reader =
                FileIndexDiffProcessor::resume(
                    "",
                    $processor->mapped_index_parents_file,
                    $position["mapped_index_parent_cursor"]
                );
            $processor->index_handle =
                self::open_and_truncate_to_saved_byte_offset(
                    $processor->index_file,
                    $position["index_byte_offset"],
                    "pull local index"
                );
        } catch (Throwable $throwable) {
            $processor->close();
            throw $throwable;
        }
        return $processor;
    }

    /**
     * Maps one remote entry, sorts the mapped index, or merges one local path.
     *
     * True means another step may be attempted. False is stable; get_status()
     * then returns `complete` or `restart`.
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
            throw new LogicException("Cannot take a pull local index step after close().");
        }

        if ($position["phase"] === "mapping") {
            $remote_index_entry = null;
            $local_absolute_path = null;
            $local_relative_path = null;
            $path_may_change = null;
            if ($this->cursor["metadata_source"] !== "remote_recorded") {
                if (
                    !$this->remote_index_path_selected
                    && !$this->remote_index_diff->next_path()
                ) {
                    $this->remote_index_diff->close();
                    $this->remote_index_diff = null;
                } else {
                    $this->remote_index_path_selected = true;
                    $remote_absolute_path =
                        $this->remote_index_diff->get_path();
                    $local_absolute_path = $this->path_mapper->map_path(
                        $remote_absolute_path
                    );
                    $local_relative_path = relative_path_under(
                        $local_absolute_path,
                        $this->path_mapper->get_filesystem_root()
                    );
                    if ($local_relative_path === null) {
                        throw new RuntimeException(
                            "Remote path maps outside the writable local tree: "
                            . base64_encode($remote_absolute_path)
                            . "."
                        );
                    }
                    $path_may_change = file_sync_index_path_may_change(
                        $local_relative_path,
                        $this->included_local_index_path_roots,
                        $this->excluded_local_index_path_roots
                    );
                    if (
                        $path_may_change
                        && $this->remote_index_diff
                            ->get_path_transition() !== "unchanged"
                    ) {
                        return $this->restart();
                    }
                    $next_remote_path_type =
                        $this->remote_index_diff
                            ->get_path_type_in_new_index();
                    if (
                        $path_may_change
                        && $next_remote_path_type !== null
                        && !$this->cursor["verification_include_caches"]
                        && FileIndexProcessor::path_is_default_skipped(
                            $local_relative_path
                        )
                    ) {
                        throw new RuntimeException(
                            "Cannot mirror mapped path "
                            . base64_encode($local_relative_path)
                            . " because the local scan omits it by default. "
                            . "Use --include-caches or --mode=catch-up."
                        );
                    }
                    if ($next_remote_path_type !== null) {
                        $remote_index_entry = [
                            "path" => $remote_absolute_path,
                            "ctime" => $this->remote_index_diff
                                ->get_ctime_in_new_index(),
                            "size" => $this->remote_index_diff
                                ->get_size_in_new_index(),
                            "type" => $next_remote_path_type,
                        ];
                        $directory_is_empty =
                            $this->remote_index_diff
                                ->get_directory_is_empty_in_new_index();
                        if ($directory_is_empty !== null) {
                            $remote_index_entry["empty"] =
                                $directory_is_empty;
                        }
                    }
                }
            } else {
                $remote_index_entry = $this->remote_index_reader->next_entry();
                if ($remote_index_entry === null) {
                    $this->remote_index_reader->close();
                    $this->remote_index_reader = null;
                }
            }

            if (
                $remote_index_entry === null
                && !$this->remote_index_path_selected
            ) {
                $this->flush_pending_output();
                fclose($this->mapped_index_handle);
                $this->mapped_index_handle = null;
                fclose($this->mapped_index_parents_handle);
                $this->mapped_index_parents_handle = null;
                $this->cursor["position"] = ["phase" => "sorting"];
                return true;
            }
            if ($remote_index_entry === null) {
                $this->remote_index_path_selected =
                    $this->remote_index_diff->next_path();
                $this->cursor["position"] = [
                    "phase" => "mapping",
                    "remote_index_diff_cursor" =>
                        $this->remote_index_diff->get_cursor(),
                    "mapped_index_byte_offset" =>
                        $position["mapped_index_byte_offset"],
                    "mapped_index_parents_byte_offset" =>
                        $position["mapped_index_parents_byte_offset"],
                ];
                return true;
            }

            if ($local_absolute_path === null) {
                $local_absolute_path = $this->path_mapper->map_path(
                    $remote_index_entry["path"]
                );
                $local_relative_path = relative_path_under(
                    $local_absolute_path,
                    $this->path_mapper->get_filesystem_root()
                );
                if ($local_relative_path === null) {
                    throw new RuntimeException(
                        "Remote path maps outside the writable local tree: "
                        . base64_encode($remote_index_entry["path"])
                        . "."
                    );
                }
                $path_may_change = file_sync_index_path_may_change(
                    $local_relative_path,
                    $this->included_local_index_path_roots,
                    $this->excluded_local_index_path_roots
                );
            }
            if ($path_may_change && $local_relative_path !== "") {
                if (
                    $remote_index_entry["type"] === "dir"
                    && ( $remote_index_entry["empty"] ?? null ) !== true
                ) {
                    throw new RuntimeException(
                        "Remote directory was not confirmed empty during indexing: "
                        . base64_encode($remote_index_entry["path"])
                        . "."
                    );
                }
                $mapped_index_entry = [
                    "path" => $local_relative_path,
                    "ctime" => $remote_index_entry["ctime"],
                    "size" => $remote_index_entry["size"],
                    "type" => $remote_index_entry["type"],
                ];
                if ($this->cursor["metadata_source"] === "retained_local") {
                    $mapped_index_entry["remote_absolute_path"] =
                        $remote_index_entry["path"];
                }
                if (array_key_exists("empty", $remote_index_entry)) {
                    $mapped_index_entry["empty"] = $remote_index_entry["empty"];
                }

                if (
                    $this->cursor["metadata_source"] === "locally_observed"
                    && $remote_index_entry["type"] === "dir"
                ) {
                    clearstatcache(true, $local_absolute_path);
                    $local_path_stat = @lstat($local_absolute_path);
                    if ($local_path_stat === false) {
                        return $this->restart();
                    }
                    $local_index_path = read_file_index_entry_from_stat(
                        $local_absolute_path,
                        $local_path_stat
                    );
                    if ($local_index_path["type"] !== "dir") {
                        return $this->restart();
                    }
                    if (read_file_index_directory_is_empty(
                        $local_absolute_path
                    ) !== true) {
                        $mapped_index_entry = null;
                    } else {
                        $mapped_index_entry["empty"] = true;
                    }
                }

                if ($mapped_index_entry !== null) {
                    write_local_index_entry(
                        $this->mapped_index_handle,
                        $mapped_index_entry
                    );
                }
                // The selected path makes each ancestor non-empty even when
                // the path's own directory row stays sparse.
                $mapped_index_parent_path = $local_relative_path;
                while (true) {
                    $last_separator = strrpos(
                        $mapped_index_parent_path,
                        "/"
                    );
                    if ($last_separator === false) {
                        break;
                    }
                    $mapped_index_parent_path = substr(
                        $mapped_index_parent_path,
                        0,
                        $last_separator
                    );
                    if ($mapped_index_parent_path === "") {
                        break;
                    }
                    write_local_index_entry(
                        $this->mapped_index_parents_handle,
                        [
                            "path" => $mapped_index_parent_path,
                            "ctime" => 0,
                            "size" => 0,
                            "type" => "dir",
                        ]
                    );
                }
            }

            $mapped_index_byte_offset = ftell($this->mapped_index_handle);
            if (!is_int($mapped_index_byte_offset)) {
                throw new RuntimeException(
                    "Failed to read the mapped pull index byte offset."
                );
            }
            $mapped_index_parents_byte_offset = ftell(
                $this->mapped_index_parents_handle
            );
            if (!is_int($mapped_index_parents_byte_offset)) {
                throw new RuntimeException(
                    "Failed to read the mapped pull index parents byte offset."
                );
            }
            $mapping_position = [
                "phase" => "mapping",
                "mapped_index_byte_offset" => $mapped_index_byte_offset,
                "mapped_index_parents_byte_offset" =>
                    $mapped_index_parents_byte_offset,
            ];
            if ($this->cursor["metadata_source"] !== "remote_recorded") {
                $this->remote_index_path_selected =
                    $this->remote_index_diff->next_path();
                $mapping_position[
                    "remote_index_diff_cursor"
                ] = $this->remote_index_diff->get_cursor();
            } else {
                $mapping_position["remote_index_byte_offset"] =
                    $this->remote_index_reader->byte_offset();
            }
            $this->cursor["position"] = $mapping_position;
            return true;
        }

        if ($position["phase"] === "verifying") {
            $has_next_verification_step =
                $this->verification_processor->next_step();
            if ($this->verification_processor->get_operation() !== null) {
                return $this->restart();
            }
            if (!$has_next_verification_step) {
                $this->cursor["position"] = ["phase" => "complete"];
                $this->close();
                return false;
            }
            $this->cursor["position"] = [
                "phase" => "verifying",
                "file_sync_patch_processor_cursor" =>
                    $this->verification_processor->get_cursor(),
            ];
            return true;
        }

        if ($position["phase"] === "sorting") {
            if (
                !sort_index_file_preserving_duplicate_paths(
                    $this->mapped_index_file
                )
            ) {
                throw new RuntimeException("Failed to sort the mapped pull index.");
            }
            $this->cursor["position"] = ["phase" => "sorting_parents"];
            return true;
        }

        if ($position["phase"] === "sorting_parents") {
            if (!sort_index_file($this->mapped_index_parents_file)) {
                throw new RuntimeException(
                    "Failed to sort the mapped pull index parents."
                );
            }
            $this->cursor["position"] = ["phase" => "starting_merge"];
            return true;
        }

        if ($position["phase"] === "starting_merge") {
            $this->assert_retained_local_index_state();
            $this->index_handle = fopen($this->index_file, "w+b");
            if (!is_resource($this->index_handle)) {
                throw new RuntimeException("Failed to open the pull local index.");
            }
            try {
                $this->index_diff = FileIndexDiffProcessor::create(
                    $this->retained_local_index_file,
                    $this->mapped_index_file
                );
                $this->mapped_index_parent_reader =
                    FileIndexDiffProcessor::create(
                        "",
                        $this->mapped_index_parents_file
                    );
            } catch (Throwable $throwable) {
                $this->close();
                throw $throwable;
            }
            $this->cursor["position"] = [
                "phase" => "merging",
                "index_diff_cursor" => $this->index_diff->get_cursor(),
                "mapped_index_parent_cursor" =>
                    $this->mapped_index_parent_reader->get_cursor(),
                "index_byte_offset" => 0,
            ];
            return true;
        }

        if (!$this->index_diff_path_selected && !$this->index_diff->next_path()) {
            return $this->finish_merge();
        }
        $this->index_diff_path_selected = true;
        $new_path_type = $this->index_diff->get_path_type_in_new_index();
        $old_path_type = $this->index_diff->get_path_type_in_old_index();
        if (
            $new_path_type !== null
            && $this->index_diff->get_preceding_path_in_new_index()
                === $this->index_diff->get_path()
        ) {
            throw new RuntimeException(
                "Two remote paths map to the same local relative path: "
                . base64_encode($this->index_diff->get_path())
                . "."
            );
        }

        $write_new_entry = $new_path_type !== null;
        $write_old_entry = $new_path_type === null
            && $old_path_type !== null
            && !file_sync_index_path_may_change(
                $this->index_diff->get_path(),
                $this->included_local_index_path_roots,
                $this->excluded_local_index_path_roots
            );
        $has_mapped_descendant = false;
        if ($write_new_entry || $write_old_entry) {
            if (!$this->mapped_index_parent_path_selected) {
                $this->mapped_index_parent_path_selected =
                    $this->mapped_index_parent_reader->next_path();
            }
            if (
                $this->mapped_index_parent_path_selected
                && strcmp(
                    $this->mapped_index_parent_reader->get_path(),
                    $this->index_diff->get_path()
                ) < 0
            ) {
                $this->mapped_index_parent_path_selected =
                    $this->mapped_index_parent_reader->next_path();
                $this->cursor["position"]["mapped_index_parent_cursor"] =
                    $this->mapped_index_parent_reader->get_cursor();
                return true;
            }
            $has_mapped_descendant =
                $this->mapped_index_parent_path_selected
                && $this->mapped_index_parent_reader->get_path()
                    === $this->index_diff->get_path();
        }
        if ($has_mapped_descendant) {
            // Descendants imply their directory ancestors in a sparse index.
            if ($write_new_entry && $new_path_type === "dir") {
                $write_new_entry = false;
            } elseif ($write_new_entry) {
                throw new RuntimeException(
                    "Mapped local index path "
                    . base64_encode($this->index_diff->get_path())
                    . " has type {$new_path_type} and has a mapped descendant."
                );
            } elseif ($old_path_type === "dir") {
                $write_old_entry = false;
            } else {
                throw new RuntimeException(
                    "Retained local index path "
                    . base64_encode($this->index_diff->get_path())
                    . " has type {$old_path_type} and has a selected mapped descendant."
                );
            }
        }
        $use_retained_local_metadata =
            $this->cursor["metadata_source"] !== "remote_recorded"
            && $write_new_entry
            && $old_path_type === $new_path_type;
        if (
            $this->cursor["metadata_source"] === "locally_observed"
            && $write_new_entry
            && !$use_retained_local_metadata
        ) {
            return $this->restart();
        }
        if ($write_new_entry || $write_old_entry) {
            $index_entry = [
                "path" => $this->index_diff->get_path(),
                "ctime" => $write_new_entry
                    ? ( $use_retained_local_metadata
                        ? $this->index_diff->get_ctime_in_old_index()
                        : ( $this->cursor["metadata_source"] === "retained_local"
                            ? -1
                            : $this->index_diff->get_ctime_in_new_index() ) )
                    : $this->index_diff->get_ctime_in_old_index(),
                "size" => $write_new_entry
                    ? ( $use_retained_local_metadata
                        ? $this->index_diff->get_size_in_old_index()
                        : $this->index_diff->get_size_in_new_index() )
                    : $this->index_diff->get_size_in_old_index(),
                "type" => $write_new_entry ? $new_path_type : $old_path_type,
            ];
            $directory_is_empty = $write_new_entry
                ? $this->index_diff->get_directory_is_empty_in_new_index()
                : $this->index_diff->get_directory_is_empty_in_old_index();
            if ($directory_is_empty !== null) {
                $index_entry["empty"] = $directory_is_empty;
            }
            $remote_absolute_path =
                $this->index_diff->get_remote_absolute_path_in_new_index();
            if (
                $this->cursor["metadata_source"] === "retained_local"
                && $write_new_entry && $remote_absolute_path !== null
            ) {
                $index_entry["remote_absolute_path"] = $remote_absolute_path;
            }
            write_local_index_entry($this->index_handle, $index_entry);
        }

        $this->index_diff_path_selected = $this->index_diff->next_path();
        $index_byte_offset = ftell($this->index_handle);
        if (!is_int($index_byte_offset)) {
            throw new RuntimeException("Failed to read the pull local index byte offset.");
        }
        if (!$this->index_diff_path_selected) {
            return $this->finish_merge();
        }
        $this->cursor["position"] = [
            "phase" => "merging",
            "index_diff_cursor" => $this->index_diff->get_cursor(),
            "mapped_index_parent_cursor" =>
                $this->mapped_index_parent_reader->get_cursor(),
            "index_byte_offset" => $index_byte_offset,
        ];
        return true;
    }

    /** @phpstan-return Cursor Cursor after the latest completed step. */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Returns the current durable phase. */
    public function get_phase(): string
    {
        return $this->cursor["position"]["phase"];
    }

    /** Returns the phase whose transition requires a caller checkpoint. */
    public function get_checkpoint_phase(): string
    {
        if ($this->get_phase() !== "verifying") {
            return $this->get_phase();
        }
        return "verifying:" . $this->verification_processor->get_phase();
    }

    /** Returns `complete` or `restart` after next_step() returns false. */
    public function get_status(): ?string
    {
        $phase = $this->cursor["position"]["phase"];
        return $phase === "complete" || $phase === "restart"
            ? $phase
            : null;
    }

    /** Returns the processor-owned patch-result or next-local-index path. */
    public function get_index_path(): string
    {
        return $this->index_file;
    }

    /** Flushes append-only output before the caller stores get_cursor(). */
    public function flush_pending_output(): void
    {
        if (is_resource($this->mapped_index_handle) && !fflush($this->mapped_index_handle)) {
            throw new RuntimeException("Failed to flush the mapped pull index.");
        }
        if (
            is_resource($this->mapped_index_parents_handle)
            && !fflush($this->mapped_index_parents_handle)
        ) {
            throw new RuntimeException(
                "Failed to flush the mapped pull index parents."
            );
        }
        if (is_resource($this->index_handle) && !fflush($this->index_handle)) {
            throw new RuntimeException("Failed to flush the pull local index.");
        }
        if ($this->verification_processor !== null) {
            $this->verification_processor->flush_pending_outputs();
        }
    }

    /** Closes retained handles. Repeated calls do nothing. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->remote_index_reader !== null) {
            $this->remote_index_reader->close();
            $this->remote_index_reader = null;
        }
        if ($this->remote_index_diff !== null) {
            $this->remote_index_diff->close();
            $this->remote_index_diff = null;
        }
        if (is_resource($this->mapped_index_handle)) {
            fclose($this->mapped_index_handle);
            $this->mapped_index_handle = null;
        }
        if (is_resource($this->mapped_index_parents_handle)) {
            fclose($this->mapped_index_parents_handle);
            $this->mapped_index_parents_handle = null;
        }
        if ($this->index_diff !== null) {
            $this->index_diff->close();
            $this->index_diff = null;
        }
        if ($this->mapped_index_parent_reader !== null) {
            $this->mapped_index_parent_reader->close();
            $this->mapped_index_parent_reader = null;
        }
        if (is_resource($this->index_handle)) {
            fclose($this->index_handle);
            $this->index_handle = null;
        }
        if ($this->verification_processor !== null) {
            $this->verification_processor->close();
            $this->verification_processor = null;
        }
        $this->closed = true;
    }

    private static function start(
        string $metadata_source,
        string $work_directory,
        string $next_remote_index_file,
        ?string $remote_index_file,
        string $retained_local_index_file,
        RemoteToLocalPathMapper $path_mapper,
        array $included_local_index_path_roots,
        array $excluded_local_index_path_roots,
        string $verification_storage_path,
        bool $verification_include_caches
    ): self {
        if (!is_dir($work_directory)) {
            throw new LogicException("Cannot start pull local indexing without its work directory: {$work_directory}.");
        }
        self::require_file($next_remote_index_file, "next remote index");
        $remote_index_exists = false;
        if ($metadata_source !== "remote_recorded") {
            if ($remote_index_file === null) {
                throw new LogicException(
                    "Cannot build the next local index without its remote index."
                );
            }
            $remote_index_exists = self::optional_file_exists(
                $remote_index_file,
                "remote index"
            );
        }
        if (file_exists($retained_local_index_file) && !is_file($retained_local_index_file)) {
            throw new LogicException("The retained local index path is not a file: {$retained_local_index_file}.");
        }

        $mapped_index_file = wp_join_unix_paths(
            $work_directory, "mapped_remote_index.jsonl"
        );
        $mapped_index_parents_file = wp_join_unix_paths(
            $work_directory,
            "mapped_remote_index_parents.jsonl"
        );
        $index_file = wp_join_unix_paths(
            $work_directory,
            $metadata_source !== "locally_observed"
                ? "pull_patch_result_index.jsonl"
                : "next_local_index.jsonl"
        );
        if (file_put_contents($mapped_index_file, "") !== 0) {
            throw new RuntimeException("Failed to open the mapped pull index.");
        }
        if (file_put_contents($mapped_index_parents_file, "") !== 0) {
            throw new RuntimeException(
                "Failed to open the mapped pull index parents."
            );
        }
        if (file_put_contents($index_file, "") !== 0) {
            throw new RuntimeException("Failed to initialize the pull local index.");
        }
        if ($metadata_source !== "remote_recorded") {
            $position = [
                "phase" => "mapping",
                "remote_index_diff_cursor" => [
                    "old_index_byte_offset" => 0,
                    "new_index_byte_offset" => 0,
                    "preceding_new_index_entry_path_b64" => null,
                ],
                "mapped_index_byte_offset" => 0,
                "mapped_index_parents_byte_offset" => 0,
            ];
        } else {
            $position = [
                "phase" => "mapping",
                "remote_index_byte_offset" => 0,
                "mapped_index_byte_offset" => 0,
                "mapped_index_parents_byte_offset" => 0,
            ];
        }
        return self::resume([
            "metadata_source" => $metadata_source,
            "next_remote_index_file_b64" => base64_encode($next_remote_index_file),
            "remote_index_file_b64" =>
                $remote_index_file === null
                    ? null
                    : base64_encode($remote_index_file),
            "remote_index_exists" => $remote_index_exists,
            "retained_local_index_file_b64" => base64_encode($retained_local_index_file),
            "retained_local_index_exists" => is_file($retained_local_index_file),
            "mapped_index_file_b64" => base64_encode($mapped_index_file),
            "mapped_index_parents_file_b64" =>
                base64_encode($mapped_index_parents_file),
            "index_file_b64" => base64_encode($index_file),
            "path_mapper_config" => $path_mapper->get_config(),
            "included_local_index_path_roots_b64" => array_map("base64_encode", $included_local_index_path_roots),
            "excluded_local_index_path_roots_b64" => array_map("base64_encode", $excluded_local_index_path_roots),
            "verification_storage_path_b64" =>
                base64_encode($verification_storage_path),
            "verification_include_caches" => $verification_include_caches,
            "position" => $position,
        ]);
    }

    /** Finishes the patch result or starts final next-index verification. */
    private function finish_merge(): bool
    {
        $this->flush_pending_output();
        $this->index_diff->close();
        $this->index_diff = null;
        $this->mapped_index_parent_reader->close();
        $this->mapped_index_parent_reader = null;
        fclose($this->index_handle);
        $this->index_handle = null;
        if ($this->cursor["metadata_source"] !== "locally_observed") {
            $this->cursor["position"] = ["phase" => "complete"];
            $this->close();
            return false;
        }

        $this->verification_processor =
            FileSyncPatchProcessor::start_from_fresh_local_tree(
                dirname($this->index_file),
                $this->path_mapper->get_filesystem_root(),
                $this->index_file,
                self::decode_cursor_path(
                    $this->cursor["verification_storage_path_b64"]
                ),
                $this->included_local_index_path_roots,
                $this->excluded_local_index_path_roots,
                $this->cursor["verification_include_caches"]
            );
        $this->cursor["position"] = [
            "phase" => "verifying",
            "file_sync_patch_processor_cursor" =>
                $this->verification_processor->get_cursor(),
        ];
        return true;
    }

    /** Stores stable restart and closes retained handles. */
    private function restart(): bool
    {
        $this->cursor["position"] = ["phase" => "restart"];
        $this->close();
        return false;
    }

    /** Confirms that the retained index still has its starting presence. */
    private function assert_retained_local_index_state(): void
    {
        if (is_file($this->retained_local_index_file) !== $this->cursor["retained_local_index_exists"]) {
            throw new RuntimeException("The retained local index presence changed during pull local indexing.");
        }
    }

    /** Decodes one arbitrary-byte path stored in the JSON cursor. */
    private static function decode_cursor_path(string $encoded_path): string
    {
        $path = base64_decode($encoded_path, true);
        if ($path === false) {
            throw new InvalidArgumentException("Pull local index cursor contains an invalid base64 path.");
        }
        return $path;
    }

    /** Requires one regular file before a phase uses it. */
    private static function require_file(string $path, string $name): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Pull local index {$name} is missing: {$path}.");
        }
    }

    /** Returns whether an optional regular file is present. */
    private static function optional_file_exists(
        string $path,
        string $name
    ): bool {
        if (is_file($path)) {
            return true;
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException(
                "The {$name} path is not a file: {$path}."
            );
        }
        return false;
    }

    /**
     * Opens an append-only output at its last saved byte offset.
     *
     * @return resource Open output handle.
     */
    private static function open_and_truncate_to_saved_byte_offset(
        string $path,
        int $byte_offset,
        string $name
    ) {
        $handle = fopen($path, "r+b");
        if (
            !is_resource($handle)
            || !ftruncate($handle, $byte_offset)
            || fseek($handle, $byte_offset) !== 0
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException("Failed to restore the {$name} to byte offset {$byte_offset}.");
        }
        return $handle;
    }
}
