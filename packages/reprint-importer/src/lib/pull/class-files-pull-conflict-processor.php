<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Processor failures become CLI values, never HTML output.

use function WordPress\Reprint\Exporter\compare_paths;
use function WordPress\Reprint\Exporter\path_sort_key;

/**
 * Builds files-pull conflicts and the planned local index one bounded step at a time.
 *
 * The owning ImportClient retains the caller-visible lifecycle. This processor
 * owns one local index processor, the local-index and remote-index merges, the
 * five required external sorts, and the local/remote change intersection.
 * Every output cursor stores byte offsets only after appended bytes have been
 * flushed. Resume truncates output to those offsets before continuing.
 *
 * Remote index streams use endpoint traversal order, which is not globally
 * merge-sorted. The processor sorts both immutable remote indexes into raw path
 * order before merging them. Conflict ancestry needs depth-first component
 * order, and the import-index diff later returns to raw path order, so remote
 * changes and conflicts each receive their required order before use.
 */
final class FilesPullConflictProcessor {
    private const SORT_CHUNK_BYTES = 1024 * 1024;

    /** @var array<string,mixed> Durable continuation cursor. */
    private array $cursor;

    /** @var bool Whether close() has been called. */
    private bool $closed = false;

    /** @var FileIndexProcessor|null Active local index retained across steps. */
    private ?FileIndexProcessor $local_index_processor = null;

    /** @var ExternalMergeSortProcessor|null Active sort retained across steps. */
    private ?ExternalMergeSortProcessor $sort_processor = null;

    /** @var array<string,resource> Phase input handles retained across cheap steps. */
    private array $input_handles = [];

    /** @var array<string,array{offset:int,result:array<string,mixed>}> */
    private array $lookahead_records = [];

    /** @var array<string,resource> Phase output handles retained across cheap steps. */
    private array $output_handles = [];

    /**
     * Starts conflict preparation against stable local and remote baselines.
     *
     * @param list<string> $selected_remote_paths Absolute remote path prefixes selected by --only.
     */
    public static function start(
        string $plan_directory,
        string $local_tree_root,
        string $previous_local_index,
        string $previous_remote_index,
        string $current_remote_index,
        string $remote_document_root,
        array $selected_remote_paths,
        string $conflicts_path,
        string $planned_local_index_path
    ): self {
        if (!is_dir($plan_directory)) {
            throw new InvalidArgumentException(
                'FilesPullConflictProcessor requires an existing plan directory.'
            );
        }
        $canonical_local_tree_root = realpath($local_tree_root);
        if ($canonical_local_tree_root === false) {
            throw new InvalidArgumentException(
                'FilesPullConflictProcessor requires an existing local tree.'
            );
        }
        $local_tree_root =
            rtrim($canonical_local_tree_root, '/') ?: '/';
        $fresh_local_index =
            $plan_directory . '/fresh_local_index.jsonl';
        if (file_put_contents($fresh_local_index, '') === false) {
            throw new RuntimeException(
                'Failed to initialize the fresh files-pull local index.'
            );
        }
        $local_index_processor = FileIndexProcessor::start(
            [$local_tree_root],
            $local_tree_root,
            false,
            false,
            $plan_directory
        );
        $processor = new self();
        $processor->local_index_processor =
            $local_index_processor;
        $processor->cursor = [
            'plan_directory' => $plan_directory,
            'local_tree_root' => $local_tree_root,
            'previous_local_index' => $previous_local_index,
            'previous_remote_index' => $previous_remote_index,
            'current_remote_index' => $current_remote_index,
            'remote_document_root_b64' =>
                base64_encode($remote_document_root),
            'selected_remote_paths_b64' => array_map(
                'base64_encode',
                $selected_remote_paths
            ),
            'conflicts_path' => $conflicts_path,
            'planned_local_index_path' =>
                $planned_local_index_path,
            'position' => [
                'phase' => 'indexing_local',
                'file_index_cursor' =>
                    $local_index_processor->get_cursor(),
                'fresh_local_index_offset' => 0,
            ],
        ];
        return $processor;
    }

    /**
     * Resumes conflict preparation at its last durable boundary.
     *
     * @param array<string,mixed> $cursor Cursor returned by get_cursor().
     */
    public static function resume(array $cursor): self
    {
        if (
            !isset($cursor['position']['phase'])
            || !is_string($cursor['position']['phase'])
        ) {
            throw new InvalidArgumentException(
                'The files-pull conflict cursor is missing its phase.'
            );
        }
        $processor = new self();
        $processor->cursor = $cursor;
        return $processor;
    }

    /**
     * Performs one bounded planning step.
     *
     * @return bool Whether another step may be attempted.
     */
    public function next_step(): bool
    {
        $phase = $this->cursor['position']['phase'];
        if ($phase === 'complete') {
            return false;
        }
        if ($this->closed) {
            throw new LogicException(
                'Cannot take a files-pull conflict step after close().'
            );
        }

        switch ($phase) {
            case 'indexing_local':
                return $this->index_next_local_path_step();
            case 'starting_local_change_diff':
                return $this->start_local_change_diff();
            case 'diffing_local_changes':
                return $this->diff_next_local_index_entry();
            case 'starting_planned_local_index':
                return $this->start_planned_local_index();
            case 'building_planned_local_index':
                return $this->build_next_planned_local_index_entry();
            case 'starting_planned_local_index_sort':
                return $this->start_planned_local_index_sort();
            case 'sorting_planned_local_index':
                return $this->sort_next_planned_local_index_step();
            case 'starting_previous_remote_index_sort':
                return $this->start_previous_remote_index_sort();
            case 'sorting_previous_remote_index':
                return $this->sort_next_previous_remote_index_step();
            case 'starting_current_remote_index_sort':
                return $this->start_current_remote_index_sort();
            case 'sorting_current_remote_index':
                return $this->sort_next_current_remote_index_step();
            case 'starting_remote_diff':
                return $this->start_remote_diff();
            case 'diffing_remote':
                return $this->diff_next_remote_index_entry();
            case 'starting_remote_change_sort':
                return $this->start_remote_change_sort();
            case 'sorting_remote_changes':
                return $this->sort_next_remote_change_step();
            case 'starting_intersection':
                return $this->start_intersection();
            case 'intersecting':
                return $this->intersect_next_change();
            case 'starting_conflict_sort':
                return $this->start_conflict_sort();
            case 'sorting_conflicts':
                return $this->sort_next_conflict_step();
        }

        throw new RuntimeException(
            'Unknown files-pull conflict phase: ' . $phase . '.'
        );
    }

    /** @return array<string,mixed> Current durable cursor. */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Returns the atomically published raw-path conflict list. */
    public function get_conflicts_path(): string
    {
        return $this->cursor['conflicts_path'];
    }

    /** Returns the raw-path local state captured during conflict planning. */
    public function get_planned_local_index_path(): string
    {
        return $this->cursor['planned_local_index_path'];
    }

    /** Idempotently closes retained subordinate processors. */
    public function close(): void
    {
        if ($this->local_index_processor !== null) {
            $this->local_index_processor->close();
            $this->local_index_processor = null;
        }
        if ($this->sort_processor !== null) {
            $this->sort_processor->close();
            $this->sort_processor = null;
        }
        $this->close_retained_io();
        $this->closed = true;
    }

    /** Indexes at most one local filesystem path. */
    private function index_next_local_path_step(): bool
    {
        $position = $this->cursor['position'];
        if ($this->local_index_processor === null) {
            $file_index_cursor = json_encode(
                $position['file_index_cursor'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $this->local_index_processor =
                FileIndexProcessor::resume(
                    [$this->cursor['local_tree_root']],
                    $file_index_cursor,
                    false,
                    false,
                    $this->cursor['plan_directory']
                );
        }
        if (!$this->local_index_processor->next_index_step()) {
            $this->local_index_processor->close();
            $this->local_index_processor = null;
            $this->close_retained_io();
            $this->cursor['position'] = [
                'phase' => 'starting_local_change_diff',
            ];
            return true;
        }

        $fresh_local_index_offset =
            $position['fresh_local_index_offset'];
        $index_entries = [];
        switch ($this->local_index_processor->get_step_status()) {
            case FileIndexProcessor::STATUS_INDEXED:
                $index_entries =
                    $this->local_index_processor->get_index_entries();
                break;

            case FileIndexProcessor::STATUS_SKIPPED:
                $skipped_path =
                    $this->local_index_processor
                        ->get_default_skipped_path();
                if ($skipped_path === null) {
                    break;
                }
                clearstatcache(true, $skipped_path);
                $skipped_stat = @lstat($skipped_path);
                if (!is_array($skipped_stat)) {
                    break;
                }
                $file_type_bits =
                    $skipped_stat['mode']
                    & FileIndexProcessor::STAT_TYPE_MASK;
                if ($file_type_bits === FileIndexProcessor::STAT_TYPE_LINK) {
                    $skipped_type = 'link';
                } elseif ($file_type_bits === FileIndexProcessor::STAT_TYPE_FILE) {
                    $skipped_type = 'file';
                } elseif ($file_type_bits === FileIndexProcessor::STAT_TYPE_DIR) {
                    $skipped_type = 'dir';
                } else {
                    $skipped_type = 'other';
                }
                $skipped_entry = [
                    'path' => $skipped_path,
                    'ctime' => (int) $skipped_stat['ctime'],
                    'size' =>
                        $skipped_type === 'file'
                        || $skipped_type === 'link'
                            ? (int) $skipped_stat['size']
                            : 0,
                    'type' => $skipped_type,
                ];
                if ($skipped_type === 'dir') {
                    $directory_handle = @opendir($skipped_path);
                    if (!is_resource($directory_handle)) {
                        throw new RuntimeException(
                            'Could not inspect a default-skipped local '
                            . 'directory while preparing files-pull conflicts: '
                            . base64_encode($skipped_path)
                            . '.'
                        );
                    }
                    do {
                        $directory_entry = readdir($directory_handle);
                    } while (
                        $directory_entry === '.'
                        || $directory_entry === '..'
                    );
                    closedir($directory_handle);
                    $skipped_entry['empty'] =
                        $directory_entry === false;
                }
                $index_entries[] = $skipped_entry;
                break;

            case FileIndexProcessor::STATUS_DIRECTORY_ERROR:
                $directory_error =
                    $this->local_index_processor
                        ->get_directory_error();
                throw new RuntimeException(
                    $directory_error['message']
                    . ': '
                    . base64_encode($directory_error['path'])
                    . '.'
                );

            case FileIndexProcessor::STATUS_PATH_UNAVAILABLE:
            case FileIndexProcessor::STATUS_DIRECTORY_COMPLETE:
                break;
        }
        foreach ($index_entries as $index_entry) {
            if (
                $index_entry['type'] === 'dir'
                && !array_key_exists('empty', $index_entry)
            ) {
                throw new RuntimeException(
                    'Could not inspect a local directory while '
                    . 'preparing files-pull conflicts: '
                    . base64_encode($index_entry['path'])
                    . '.'
                );
            }
            $relative_path = substr(
                $index_entry['path'],
                strlen($this->cursor['local_tree_root']) + 1
            );
            if (!is_string($relative_path)) {
                throw new RuntimeException(
                    'A files-pull local path is outside its local tree.'
                );
            }
            $entry = $index_entry;
            $entry['path'] = $relative_path;
            $fresh_local_index_offset =
                $this->append_at_offset(
                    $this->fresh_local_index_path(),
                    $fresh_local_index_offset,
                    $this->encode_index_entry($entry)
                );
        }
        $this->cursor['position'] = [
            'phase' => 'indexing_local',
            'file_index_cursor' =>
                $this->local_index_processor->get_cursor(),
            'fresh_local_index_offset' =>
                $fresh_local_index_offset,
        ];
        return true;
    }

    /** Initializes the depth-first local-change stream. */
    private function start_local_change_diff(): bool
    {
        $this->close_retained_io();
        if (file_put_contents($this->local_changes_path(), '') === false) {
            throw new RuntimeException(
                'Failed to initialize the local files-pull changes.'
            );
        }
        $this->cursor['position'] = [
            'phase' => 'diffing_local_changes',
            'fresh_local_index_offset' => 0,
            'previous_local_index_offset' => 0,
            'local_changes_offset' => 0,
        ];
        return true;
    }

    /** Compares at most one path across the fresh and previous local indexes. */
    private function diff_next_local_index_entry(): bool
    {
        $position = $this->cursor['position'];
        $fresh = $this->read_index_entry_at(
            $this->fresh_local_index_path(),
            $position['fresh_local_index_offset']
        );
        $previous = $this->read_index_entry_at(
            $this->cursor['previous_local_index'],
            $position['previous_local_index_offset']
        );
        if ($fresh['entry'] === null && $previous['entry'] === null) {
            $this->cursor['position'] = [
                'phase' => 'starting_planned_local_index',
            ];
            return true;
        }

        if ($previous['entry'] === null) {
            $comparison = -1;
        } elseif ($fresh['entry'] === null) {
            $comparison = 1;
        } else {
            $comparison = compare_paths(
                $fresh['entry']['path'],
                $previous['entry']['path']
            );
        }

        $fresh_offset = $position['fresh_local_index_offset'];
        $previous_offset =
            $position['previous_local_index_offset'];
        $changed_path = null;
        if ($comparison < 0) {
            $changed_path = $fresh['entry']['path'];
            $fresh_offset = $fresh['next_offset'];
        } elseif ($comparison > 0) {
            $changed_path = $previous['entry']['path'];
            $previous_offset = $previous['next_offset'];
        } else {
            $fresh_entry = $fresh['entry'];
            $previous_entry = $previous['entry'];
            $fresh_offset = $fresh['next_offset'];
            $previous_offset = $previous['next_offset'];

            $changed =
                $fresh_entry['type'] !== $previous_entry['type'];
            if (!$changed && $fresh_entry['type'] === 'dir') {
                // Directory ctime and size reflect child changes already
                // represented by later entries. Only emptiness changes the
                // directory itself.
                $changed =
                    $fresh_entry['empty']
                    !== $previous_entry['empty'];
            } elseif (!$changed) {
                $changed =
                    $fresh_entry['ctime']
                        !== $previous_entry['ctime']
                    || $fresh_entry['size']
                        !== $previous_entry['size'];
            }
            if ($changed) {
                $changed_path = $fresh_entry['path'];
            }
        }

        $local_changes_offset =
            $position['local_changes_offset'];
        if ($changed_path !== null) {
            $local_changes_offset = $this->append_at_offset(
                $this->local_changes_path(),
                $local_changes_offset,
                json_encode(
                    ['path' => base64_encode($changed_path)],
                    JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR
                ) . "\n"
            );
        }
        $this->cursor['position'] = [
            'phase' => 'diffing_local_changes',
            'fresh_local_index_offset' => $fresh_offset,
            'previous_local_index_offset' => $previous_offset,
            'local_changes_offset' => $local_changes_offset,
        ];
        return true;
    }

    /** Initializes the raw-path planned-local-index transformation. */
    private function start_planned_local_index(): bool
    {
        $this->close_retained_io();
        $path = $this->planned_local_index_unsorted_path();
        if (file_put_contents($path, '') === false) {
            throw new RuntimeException(
                'Failed to initialize the planned local index.'
            );
        }
        $this->cursor['position'] = [
            'phase' => 'building_planned_local_index',
            'fresh_local_index_offset' => 0,
            'planned_local_index_offset' => 0,
        ];
        return true;
    }

    /** Transforms at most one relative fresh-local-index entry. */
    private function build_next_planned_local_index_entry(): bool
    {
        $position = $this->cursor['position'];
        $input = $this->read_index_entry_at(
            $this->fresh_local_index_path(),
            $position['fresh_local_index_offset']
        );
        if ($input['entry'] === null) {
            $this->cursor['position'] = [
                'phase' => 'starting_planned_local_index_sort',
            ];
            return true;
        }

        $entry = $input['entry'];
        $remote_document_root = $this->remote_document_root();
        $entry['path'] =
            $remote_document_root . '/' . $entry['path'];
        $line = $this->encode_index_entry($entry);
        $output_offset = $this->append_at_offset(
            $this->planned_local_index_unsorted_path(),
            $position['planned_local_index_offset'],
            $line
        );
        $this->cursor['position'] = [
            'phase' => 'building_planned_local_index',
            'fresh_local_index_offset' => $input['next_offset'],
            'planned_local_index_offset' => $output_offset,
        ];
        return true;
    }

    /** Opens the bounded raw-path sort for the planned local index. */
    private function start_planned_local_index_sort(): bool
    {
        $this->close_retained_io();
        $sort = ExternalMergeSortProcessor::start(
            $this->planned_local_index_unsorted_path(),
            $this->cursor['planned_local_index_path'],
            $this->prepare_empty_directory('sort-planned-local'),
            static function (string $line): string {
                $entry = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $path = base64_decode($entry['path'] ?? '', true);
                if ($path === false) {
                    throw new RuntimeException(
                        'A planned local path is not valid base64.'
                    );
                }
                return $path;
            },
            self::SORT_CHUNK_BYTES,
            true
        );
        $this->sort_processor = $sort;
        $sort_cursor = $sort->get_cursor();
        $this->cursor['position'] = [
            'phase' => 'sorting_planned_local_index',
            'sort_cursor' => $sort_cursor,
        ];
        return true;
    }

    /** Performs one planned-local-index sort step. */
    private function sort_next_planned_local_index_step(): bool
    {
        if ($this->sort_processor === null) {
            $this->sort_processor = ExternalMergeSortProcessor::resume(
                $this->cursor['position']['sort_cursor'],
                static function (string $line): string {
                    $entry = json_decode(
                        $line,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    $path = base64_decode($entry['path'] ?? '', true);
                    if ($path === false) {
                        throw new RuntimeException(
                            'A planned local path is not valid base64.'
                        );
                    }
                    return $path;
                }
            );
        }
        $has_next_step = $this->sort_processor->next_step();
        $sort_cursor = $this->sort_processor->get_cursor();
        if ($has_next_step) {
            $this->cursor['position']['sort_cursor'] = $sort_cursor;
            return true;
        }
        $this->sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['position'] = [
            'phase' => 'starting_previous_remote_index_sort',
        ];
        return true;
    }

    /** Opens the raw-path sort for the previous remote index. */
    private function start_previous_remote_index_sort(): bool
    {
        return $this->start_remote_index_sort(
            $this->cursor['previous_remote_index'],
            $this->previous_remote_index_sorted_path(),
            'sort-previous-remote-index',
            'sorting_previous_remote_index'
        );
    }

    /** Performs one previous-remote-index sort step. */
    private function sort_next_previous_remote_index_step(): bool
    {
        return $this->sort_next_remote_index_step(
            'starting_current_remote_index_sort'
        );
    }

    /** Opens the raw-path sort for the current remote index. */
    private function start_current_remote_index_sort(): bool
    {
        return $this->start_remote_index_sort(
            $this->cursor['current_remote_index'],
            $this->current_remote_index_sorted_path(),
            'sort-current-remote-index',
            'sorting_current_remote_index'
        );
    }

    /** Performs one current-remote-index sort step. */
    private function sort_next_current_remote_index_step(): bool
    {
        return $this->sort_next_remote_index_step(
            'starting_remote_diff'
        );
    }

    /** Starts one bounded raw-path remote-index sort. */
    private function start_remote_index_sort(
        string $input_path,
        string $output_path,
        string $work_directory_name,
        string $sorting_phase
    ): bool {
        $this->close_retained_io();
        $sort = ExternalMergeSortProcessor::start(
            $input_path,
            $output_path,
            $this->prepare_empty_directory($work_directory_name),
            $this->remote_index_sort_key_extractor(),
            self::SORT_CHUNK_BYTES,
            true
        );
        $this->sort_processor = $sort;
        $this->cursor['position'] = [
            'phase' => $sorting_phase,
            'sort_cursor' => $sort->get_cursor(),
        ];
        return true;
    }

    /** Performs one remote-index sort step. */
    private function sort_next_remote_index_step(
        string $next_phase
    ): bool {
        if ($this->sort_processor === null) {
            $this->sort_processor = ExternalMergeSortProcessor::resume(
                $this->cursor['position']['sort_cursor'],
                $this->remote_index_sort_key_extractor()
            );
        }
        $has_next_step = $this->sort_processor->next_step();
        if ($has_next_step) {
            $this->cursor['position']['sort_cursor'] =
                $this->sort_processor->get_cursor();
            return true;
        }
        $this->sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['position'] = [
            'phase' => $next_phase,
        ];
        return true;
    }

    /**
     * Returns the raw path key shared by both remote-index sorts.
     *
     * @return callable(string): string
     */
    private function remote_index_sort_key_extractor(): callable
    {
        return static function (string $line): string {
            $entry = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $path = base64_decode($entry['path'] ?? '', true);
            if ($path === false) {
                throw new RuntimeException(
                    'A remote index path is not valid base64.'
                );
            }
            return $path;
        };
    }

    /** Initializes the raw remote-change stream. */
    private function start_remote_diff(): bool
    {
        if (file_put_contents($this->remote_changes_raw_path(), '') === false) {
            throw new RuntimeException(
                'Failed to initialize the remote files-pull changes.'
            );
        }
        $this->cursor['position'] = [
            'phase' => 'diffing_remote',
            'previous_remote_offset' => 0,
            'current_remote_offset' => 0,
            'remote_changes_offset' => 0,
        ];
        return true;
    }

    /** Compares at most one path across the previous and current remote indexes. */
    private function diff_next_remote_index_entry(): bool
    {
        $position = $this->cursor['position'];
        $previous = $this->read_index_entry_at(
            $this->previous_remote_index_sorted_path(),
            $position['previous_remote_offset']
        );
        $current = $this->read_index_entry_at(
            $this->current_remote_index_sorted_path(),
            $position['current_remote_offset']
        );
        if ($previous['entry'] === null && $current['entry'] === null) {
            $this->cursor['position'] = [
                'phase' => 'starting_remote_change_sort',
            ];
            return true;
        }

        if ($previous['entry'] === null) {
            $comparison = 1;
        } elseif ($current['entry'] === null) {
            $comparison = -1;
        } else {
            $comparison = strcmp(
                $previous['entry']['path'],
                $current['entry']['path']
            );
        }

        $remote_change = null;
        $previous_offset = $position['previous_remote_offset'];
        $current_offset = $position['current_remote_offset'];
        if ($comparison < 0) {
            $remote_change = [
                'entry' => $previous['entry'],
                'replace_subtree' => true,
            ];
            $previous_offset = $previous['next_offset'];
        } elseif ($comparison > 0) {
            $remote_change = [
                'entry' => $current['entry'],
                'replace_subtree' =>
                    $current['entry']['type'] !== 'dir',
            ];
            $current_offset = $current['next_offset'];
        } else {
            $previous_offset = $previous['next_offset'];
            $current_offset = $current['next_offset'];
            if (
                $previous['entry']['ctime']
                    !== $current['entry']['ctime']
                || $previous['entry']['size']
                    !== $current['entry']['size']
                || $previous['entry']['type']
                    !== $current['entry']['type']
            ) {
                $remote_change = [
                    'entry' => $current['entry'],
                    'replace_subtree' =>
                        $current['entry']['type'] !== 'dir',
                ];
            }
        }

        $remote_changes_offset = $position['remote_changes_offset'];
        if ($remote_change !== null) {
            $record = $this->remote_change_record(
                $remote_change['entry'],
                $remote_change['replace_subtree']
            );
            if ($record !== null) {
                $remote_changes_offset = $this->append_at_offset(
                    $this->remote_changes_raw_path(),
                    $remote_changes_offset,
                    json_encode(
                        $record,
                        JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR
                    ) . "\n"
                );
            }
        }
        $this->cursor['position'] = [
            'phase' => 'diffing_remote',
            'previous_remote_offset' => $previous_offset,
            'current_remote_offset' => $current_offset,
            'remote_changes_offset' => $remote_changes_offset,
        ];
        return true;
    }

    /** Starts the depth-first remote-change sort. */
    private function start_remote_change_sort(): bool
    {
        $this->close_retained_io();
        $sort = ExternalMergeSortProcessor::start(
            $this->remote_changes_raw_path(),
            $this->remote_changes_depth_first_path(),
            $this->prepare_empty_directory('sort-remote-changes'),
            static function (string $line): string {
                $entry = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $path = base64_decode($entry['path'] ?? '', true);
                if ($path === false) {
                    throw new RuntimeException(
                        'A remote files-pull change path is not valid base64.'
                    );
                }
                return path_sort_key($path);
            },
            self::SORT_CHUNK_BYTES,
            true
        );
        $this->sort_processor = $sort;
        $sort_cursor = $sort->get_cursor();
        $this->cursor['position'] = [
            'phase' => 'sorting_remote_changes',
            'sort_cursor' => $sort_cursor,
        ];
        return true;
    }

    /** Performs one remote-change sort step. */
    private function sort_next_remote_change_step(): bool
    {
        if ($this->sort_processor === null) {
            $this->sort_processor = ExternalMergeSortProcessor::resume(
                $this->cursor['position']['sort_cursor'],
                static function (string $line): string {
                    $entry = json_decode(
                        $line,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    $path = base64_decode($entry['path'] ?? '', true);
                    if ($path === false) {
                        throw new RuntimeException(
                            'A remote files-pull change path is not valid base64.'
                        );
                    }
                    return path_sort_key($path);
                }
            );
        }
        $has_next_step = $this->sort_processor->next_step();
        $sort_cursor = $this->sort_processor->get_cursor();
        if ($has_next_step) {
            $this->cursor['position']['sort_cursor'] = $sort_cursor;
            return true;
        }
        $this->sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['position'] = [
            'phase' => 'starting_intersection',
        ];
        return true;
    }

    /** Initializes conflict intersection and its append-only ancestor stack. */
    private function start_intersection(): bool
    {
        $this->close_retained_io();
        foreach ([
            $this->conflicts_depth_first_path(),
            $this->local_change_stack_path(),
        ] as $path) {
            if (file_put_contents($path, '') === false) {
                throw new RuntimeException(
                    'Failed to initialize the files-pull conflict intersection.'
                );
            }
        }
        $this->cursor['position'] = [
            'phase' => 'intersecting',
            'local_changes_offset' => 0,
            'remote_changes_offset' => 0,
            'conflicts_offset' => 0,
            'local_change_stack_top_offset' => null,
            'local_change_stack_offset' => 0,
            'conflicted_replacement_path_b64' => null,
        ];
        return true;
    }

    /** Advances either one local change or one remote change. */
    private function intersect_next_change(): bool
    {
        $position = $this->cursor['position'];
        $local_change = $this->read_local_change_at(
            $position['local_changes_offset']
        );
        $remote_change = $this->read_remote_change_at(
            $position['remote_changes_offset']
        );
        if ($remote_change['entry'] === null) {
            $this->cursor['position'] = [
                'phase' => 'starting_conflict_sort',
            ];
            return true;
        }

        $active_replacement = isset(
            $position['conflicted_replacement_path_b64']
        )
            ? base64_decode(
                $position['conflicted_replacement_path_b64'],
                true
            )
            : null;
        if ($active_replacement === false) {
            throw new RuntimeException(
                'The conflicted replacement path is not valid base64.'
            );
        }
        if (
            $active_replacement !== null
            && !$this->path_is_same_or_descendant(
                $remote_change['entry']['path'],
                $active_replacement
            )
        ) {
            $active_replacement = null;
        }

        if (
            $local_change['path'] !== null
            && compare_paths(
                $local_change['path'],
                $remote_change['entry']['path']
            ) <= 0
        ) {
            $stack =
                $this->pop_one_local_change_stack_entry(
                    $position['local_change_stack_top_offset'],
                    $local_change['path']
                );
            if (!$stack['reached_ancestor']) {
                $this->cursor['position'] = $position;
                $this->cursor['position']
                    ['local_change_stack_top_offset'] =
                        $stack['top_offset'];
                $this->cursor['position']
                    ['conflicted_replacement_path_b64'] =
                        $active_replacement === null
                            ? null
                            : base64_encode($active_replacement);
                return true;
            }
            $stack =
                $this->append_local_change_stack_entry(
                    $local_change['path'],
                    $stack['top_offset'],
                    $position['local_change_stack_offset']
                );
            $this->cursor['position'] = [
                'phase' => 'intersecting',
                'local_changes_offset' =>
                    $local_change['next_offset'],
                'remote_changes_offset' =>
                    $position['remote_changes_offset'],
                'conflicts_offset' =>
                    $position['conflicts_offset'],
                'local_change_stack_top_offset' =>
                    $stack['top_offset'],
                'local_change_stack_offset' =>
                    $stack['next_offset'],
                'conflicted_replacement_path_b64' =>
                    $active_replacement === null
                        ? null
                        : base64_encode($active_replacement),
            ];
            return true;
        }

        $stack =
            $this->pop_one_local_change_stack_entry(
                $position['local_change_stack_top_offset'],
                $remote_change['entry']['path']
            );
        if (!$stack['reached_ancestor']) {
            $this->cursor['position'] = $position;
            $this->cursor['position']
                ['local_change_stack_top_offset'] =
                    $stack['top_offset'];
            $this->cursor['position']
                ['conflicted_replacement_path_b64'] =
                    $active_replacement === null
                        ? null
                        : base64_encode($active_replacement);
            return true;
        }
        $stack_top_offset = $stack['top_offset'];
        $has_conflict =
            $active_replacement !== null
            || $stack_top_offset !== null;
        if (
            !$has_conflict
            && $remote_change['entry']['replace_subtree']
            && $local_change['path'] !== null
        ) {
            $has_conflict = $this->path_is_same_or_descendant(
                $local_change['path'],
                $remote_change['entry']['path']
            );
        }
        if (
            $has_conflict
            && $active_replacement === null
            && $remote_change['entry']['replace_subtree']
        ) {
            $active_replacement =
                $remote_change['entry']['path'];
        }

        $conflicts_offset = $position['conflicts_offset'];
        if ($has_conflict) {
            $line = json_encode(
                [
                    'path' =>
                        base64_encode(
                            $remote_change['entry']['remote_path']
                        ),
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
            $conflicts_offset = $this->append_at_offset(
                $this->conflicts_depth_first_path(),
                $conflicts_offset,
                $line
            );
        }
        $this->cursor['position'] = [
            'phase' => 'intersecting',
            'local_changes_offset' =>
                $position['local_changes_offset'],
            'remote_changes_offset' =>
                $remote_change['next_offset'],
            'conflicts_offset' => $conflicts_offset,
            'local_change_stack_top_offset' =>
                $stack_top_offset,
            'local_change_stack_offset' =>
                $position['local_change_stack_offset'],
            'conflicted_replacement_path_b64' =>
                $active_replacement === null
                    ? null
                    : base64_encode($active_replacement),
        ];
        return true;
    }

    /** Starts the raw remote-path conflict sort and publication. */
    private function start_conflict_sort(): bool
    {
        $this->close_retained_io();
        $sort = ExternalMergeSortProcessor::start(
            $this->conflicts_depth_first_path(),
            $this->cursor['conflicts_path'],
            $this->prepare_empty_directory('sort-conflicts'),
            static function (string $line): string {
                $entry = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $path = base64_decode($entry['path'] ?? '', true);
                if ($path === false) {
                    throw new RuntimeException(
                        'A files-pull conflict path is not valid base64.'
                    );
                }
                return $path;
            },
            self::SORT_CHUNK_BYTES,
            true
        );
        $this->sort_processor = $sort;
        $sort_cursor = $sort->get_cursor();
        $this->cursor['position'] = [
            'phase' => 'sorting_conflicts',
            'sort_cursor' => $sort_cursor,
        ];
        return true;
    }

    /** Performs one raw conflict sort step and reaches a stable terminal state. */
    private function sort_next_conflict_step(): bool
    {
        if ($this->sort_processor === null) {
            $this->sort_processor = ExternalMergeSortProcessor::resume(
                $this->cursor['position']['sort_cursor'],
                static function (string $line): string {
                    $entry = json_decode(
                        $line,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    $path = base64_decode($entry['path'] ?? '', true);
                    if ($path === false) {
                        throw new RuntimeException(
                            'A files-pull conflict path is not valid base64.'
                        );
                    }
                    return $path;
                }
            );
        }
        $has_next_step = $this->sort_processor->next_step();
        $sort_cursor = $this->sort_processor->get_cursor();
        if ($has_next_step) {
            $this->cursor['position']['sort_cursor'] = $sort_cursor;
            return true;
        }
        $this->sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['position'] = ['phase' => 'complete'];
        return false;
    }

    /**
     * Reads one base64-path index entry at a durable byte offset.
     *
     * @return array{entry:array<string,mixed>|null,next_offset:int}
     */
    private function read_index_entry_at(
        string $path,
        int $offset
    ): array {
        if (!is_file($path)) {
            return ['entry' => null, 'next_offset' => $offset];
        }
        $lookahead_key = 'index:' . $path;
        if (
            isset($this->lookahead_records[$lookahead_key])
            && $this->lookahead_records[$lookahead_key]['offset'] === $offset
        ) {
            /** @var array{entry:array<string,mixed>|null,next_offset:int} */
            return $this->lookahead_records[$lookahead_key]['result'];
        }
        $handle = $this->input_handle($path);
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException(
                'Failed to seek in a files-pull conflict index.'
            );
        }
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                if (!feof($handle)) {
                    throw new RuntimeException(
                        'Failed to read a files-pull conflict index.'
                    );
                }
                $next_offset = ftell($handle);
                $result = [
                    'entry' => null,
                    'next_offset' =>
                        is_int($next_offset)
                            ? $next_offset
                            : $offset,
                ];
                break;
            }
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $decoded_path =
                base64_decode($entry['path'] ?? '', true);
            if ($decoded_path === false) {
                throw new RuntimeException(
                    'A files-pull conflict index path is not valid base64.'
                );
            }
            $entry['path'] = $decoded_path;
            $entry['ctime'] = (int) ( $entry['ctime'] ?? 0 );
            $entry['size'] = (int) ( $entry['size'] ?? 0 );
            $entry['type'] = (string) ( $entry['type'] ?? '' );
            if ($entry['type'] === 'dir') {
                $entry['empty'] =
                    (bool) ( $entry['empty'] ?? false );
            }
            $next_offset = ftell($handle);
            if (!is_int($next_offset)) {
                throw new RuntimeException(
                    'Failed to determine a files-pull conflict index offset.'
                );
            }
            $result = [
                'entry' => $entry,
                'next_offset' => $next_offset,
            ];
            break;
        }
        $this->lookahead_records[$lookahead_key] = [
            'offset' => $offset,
            'result' => $result,
        ];
        return $result;
    }

    /** Encodes one decoded-path index entry as JSONL. */
    private function encode_index_entry(array $entry): string
    {
        $record = [
            'path' => base64_encode($entry['path']),
            'ctime' => (int) $entry['ctime'],
            'size' => (int) $entry['size'],
            'type' => (string) $entry['type'],
        ];
        if ($entry['type'] === 'dir') {
            $record['empty'] = (bool) ( $entry['empty'] ?? false );
        }
        return json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * Converts one absolute remote change into local-tree path space.
     *
     * @return array<string,mixed>|null
     */
    private function remote_change_record(
        array $remote_entry,
        bool $replace_subtree
    ): ?array {
        if (!$this->remote_path_is_selected($remote_entry['path'])) {
            return null;
        }
        $relative_path = $this->path_remainder_under(
            $remote_entry['path'],
            $this->remote_document_root()
        );
        if ($relative_path === null || $relative_path === '') {
            return null;
        }
        $relative_path = ltrim($relative_path, '/');
        if (FileIndexProcessor::path_is_default_skipped($relative_path)) {
            return null;
        }
        return [
            'path' => base64_encode($relative_path),
            'remote_path' =>
                base64_encode($remote_entry['path']),
            'replace_subtree' => $replace_subtree,
        ];
    }

    /** Reports whether an absolute remote path belongs to the selected pull scope. */
    private function remote_path_is_selected(string $path): bool
    {
        $selected_paths = $this->selected_remote_paths();
        if ($selected_paths === []) {
            return true;
        }
        foreach ($selected_paths as $prefix) {
            $remainder = $this->path_remainder_under($path, $prefix);
            if ($remainder === '') {
                return false;
            }
            if ($remainder !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads one depth-first local-change record.
     *
     * @return array{path:string|null,next_offset:int}
     */
    private function read_local_change_at(int $offset): array
    {
        $path = $this->local_changes_path();
        $lookahead_key = 'local-change';
        if (
            isset($this->lookahead_records[$lookahead_key])
            && $this->lookahead_records[$lookahead_key]['offset'] === $offset
        ) {
            /** @var array{path:string|null,next_offset:int} */
            return $this->lookahead_records[$lookahead_key]['result'];
        }
        $handle = $this->input_handle($path);
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException(
                'Failed to seek in the depth-first local changes.'
            );
        }
        $line = fgets($handle);
        if ($line === false) {
            if (!feof($handle)) {
                throw new RuntimeException(
                    'Failed to read the depth-first local changes.'
                );
            }
            $result = ['path' => null, 'next_offset' => $offset];
        } else {
            $entry = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $local_path = base64_decode($entry['path'] ?? '', true);
            if ($local_path === false) {
                throw new RuntimeException(
                    'A depth-first local change path is not valid base64.'
                );
            }
            $next_offset = ftell($handle);
            if (!is_int($next_offset)) {
                throw new RuntimeException(
                    'Failed to determine the local-change offset.'
                );
            }
            $result = [
                'path' => $local_path,
                'next_offset' => $next_offset,
            ];
        }
        $this->lookahead_records[$lookahead_key] = [
            'offset' => $offset,
            'result' => $result,
        ];
        return $result;
    }

    /**
     * Reads one depth-first remote-change record.
     *
     * @return array{entry:array<string,mixed>|null,next_offset:int}
     */
    private function read_remote_change_at(int $offset): array
    {
        $path = $this->remote_changes_depth_first_path();
        $lookahead_key = 'remote-change';
        if (
            isset($this->lookahead_records[$lookahead_key])
            && $this->lookahead_records[$lookahead_key]['offset'] === $offset
        ) {
            /** @var array{entry:array<string,mixed>|null,next_offset:int} */
            return $this->lookahead_records[$lookahead_key]['result'];
        }
        $handle = $this->input_handle($path);
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException(
                'Failed to seek in the depth-first remote changes.'
            );
        }
        $line = fgets($handle);
        if ($line === false) {
            if (!feof($handle)) {
                throw new RuntimeException(
                    'Failed to read the depth-first remote changes.'
                );
            }
            $result = ['entry' => null, 'next_offset' => $offset];
        } else {
            $record = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $local_path =
                base64_decode($record['path'] ?? '', true);
            $remote_path =
                base64_decode($record['remote_path'] ?? '', true);
            if ($local_path === false || $remote_path === false) {
                throw new RuntimeException(
                    'A depth-first remote change path is not valid base64.'
                );
            }
            $next_offset = ftell($handle);
            if (!is_int($next_offset)) {
                throw new RuntimeException(
                    'Failed to determine the remote-change offset.'
                );
            }
            $result = [
                'entry' => [
                    'path' => $local_path,
                    'remote_path' => $remote_path,
                    'replace_subtree' =>
                        (bool) ( $record['replace_subtree'] ?? false ),
                ],
                'next_offset' => $next_offset,
            ];
        }
        $this->lookahead_records[$lookahead_key] = [
            'offset' => $offset,
            'result' => $result,
        ];
        return $result;
    }

    /**
     * Pops at most one stack entry when its top does not contain the path.
     *
     * @return array{top_offset:int|null,reached_ancestor:bool}
     */
    private function pop_one_local_change_stack_entry(
        ?int $top_offset,
        string $path
    ): array {
        if ($top_offset === null) {
            return [
                'top_offset' => null,
                'reached_ancestor' => true,
            ];
        }
        $entry = $this->read_local_change_stack_entry($top_offset);
        if ($this->path_is_same_or_descendant($path, $entry['path'])) {
            return [
                'top_offset' => $top_offset,
                'reached_ancestor' => true,
            ];
        }
        return [
            'top_offset' => $entry['previous_offset'],
            'reached_ancestor' => false,
        ];
    }

    /**
     * Appends one local-change ancestor after the durable stack boundary.
     *
     * @return array{top_offset:int,next_offset:int}
     */
    private function append_local_change_stack_entry(
        string $path,
        ?int $previous_offset,
        int $durable_offset
    ): array {
        $line = json_encode(
            [
                'path_b64' => base64_encode($path),
                'previous_offset' => $previous_offset,
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        return [
            'top_offset' => $durable_offset,
            'next_offset' => $this->append_at_offset(
                $this->local_change_stack_path(),
                $durable_offset,
                $line
            ),
        ];
    }

    /**
     * Reads one linked local-change stack entry.
     *
     * @return array{path:string,previous_offset:int|null}
     */
    private function read_local_change_stack_entry(int $offset): array
    {
        $handle = $this->input_handle(
            $this->local_change_stack_path()
        );
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException(
                'Failed to seek in the local files-pull change stack.'
            );
        }
        $line = fgets($handle);
        if ($line === false) {
            throw new RuntimeException(
                'Failed to read a local files-pull change-stack entry.'
            );
        }
        $record = json_decode(
            $line,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $path = base64_decode($record['path_b64'] ?? '', true);
        if ($path === false) {
            throw new RuntimeException(
                'A local change-stack path is not valid base64.'
            );
        }
        return [
            'path' => $path,
            'previous_offset' =>
                isset($record['previous_offset'])
                    ? (int) $record['previous_offset']
                    : null,
        ];
    }

    /** Appends bytes after discarding anything beyond a durable offset. */
    private function append_at_offset(
        string $path,
        int $offset,
        string $bytes
    ): int {
        if (!isset($this->output_handles[$path])) {
            $handle = fopen($path, 'c+b');
            if (!is_resource($handle)) {
                throw new RuntimeException(
                    'Failed to open a files-pull conflict output: '
                    . $path
                    . '.'
                );
            }
            $this->output_handles[$path] = $handle;
        }
        $handle = $this->output_handles[$path];
        if (!ftruncate($handle, $offset)) {
            throw new RuntimeException(
                'Failed to discard uncommitted conflict output bytes.'
            );
        }
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException(
                'Failed to seek in a files-pull conflict output.'
            );
        }
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException(
                'Failed to append a files-pull conflict output.'
            );
        }
        if (!fflush($handle)) {
            throw new RuntimeException(
                'Failed to flush a files-pull conflict output.'
            );
        }
        $next_offset = ftell($handle);
        if (!is_int($next_offset)) {
            throw new RuntimeException(
                'Failed to determine a files-pull conflict output offset.'
            );
        }
        return $next_offset;
    }

    /**
     * Returns one retained input handle.
     *
     * @return resource
     */
    private function input_handle(string $path)
    {
        if (!isset($this->input_handles[$path])) {
            $handle = fopen($path, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException(
                    'Failed to open a files-pull conflict input: '
                    . $path
                    . '.'
                );
            }
            $this->input_handles[$path] = $handle;
        }
        return $this->input_handles[$path];
    }

    /** Closes handles retained by the phase which just finished. */
    private function close_retained_io(): void
    {
        foreach ($this->input_handles as $handle) {
            fclose($handle);
        }
        foreach ($this->output_handles as $handle) {
            fclose($handle);
        }
        $this->input_handles = [];
        $this->lookahead_records = [];
        $this->output_handles = [];
    }

    /** Creates an empty processor-owned work directory. */
    private function prepare_empty_directory(string $name): string
    {
        $path = $this->cursor['plan_directory'] . '/' . $name;
        if (is_dir($path)) {
            $directory_handle = opendir($path);
            if (!is_resource($directory_handle)) {
                throw new RuntimeException(
                    'Failed to inspect a files-pull sort directory.'
                );
            }
            do {
                $directory_entry = readdir($directory_handle);
            } while (
                $directory_entry === '.'
                || $directory_entry === '..'
            );
            closedir($directory_handle);
            if ($directory_entry !== false) {
                throw new RuntimeException(
                    'A new files-pull sort directory is not empty.'
                );
            }
            return $path;
        }
        if (!mkdir($path, 0755, true)) {
            throw new RuntimeException(
                'Failed to create a files-pull sort directory.'
            );
        }
        return $path;
    }

    /** Decodes the remote document root from the durable cursor. */
    private function remote_document_root(): string
    {
        $path = base64_decode(
            $this->cursor['remote_document_root_b64'],
            true
        );
        if ($path === false) {
            throw new RuntimeException(
                'The files-pull conflict remote document root is invalid.'
            );
        }
        return $path;
    }

    /** @return list<string> Decoded selected absolute remote paths. */
    private function selected_remote_paths(): array
    {
        $paths = [];
        foreach (
            $this->cursor['selected_remote_paths_b64']
            as $encoded_path
        ) {
            $path = base64_decode($encoded_path, true);
            if ($path === false) {
                throw new RuntimeException(
                    'A selected files-pull path is not valid base64.'
                );
            }
            $paths[] = $path;
        }
        return $paths;
    }

    /** Returns a path remainder, an empty string for equality, or null outside the prefix. */
    private function path_remainder_under(
        string $path,
        string $prefix
    ): ?string {
        $path = rtrim($path, '/');
        $prefix = rtrim($prefix, '/');
        if ($path === $prefix) {
            return '';
        }
        if (strpos($path, $prefix . '/') === 0) {
            return substr($path, strlen($prefix));
        }
        return null;
    }

    /** Reports whether a path is equal to or below an ancestor. */
    private function path_is_same_or_descendant(
        string $path,
        string $possible_ancestor
    ): bool {
        return $path === $possible_ancestor
            || strpos($path, $possible_ancestor . '/') === 0;
    }

    private function fresh_local_index_path(): string
    {
        return $this->cursor['plan_directory']
            . '/fresh_local_index.jsonl';
    }

    private function local_changes_path(): string
    {
        return $this->cursor['plan_directory']
            . '/local_changes.depth-first.jsonl';
    }

    private function planned_local_index_unsorted_path(): string
    {
        return $this->cursor['plan_directory']
            . '/planned_local_index.unsorted.jsonl';
    }

    private function previous_remote_index_sorted_path(): string
    {
        return $this->cursor['plan_directory']
            . '/previous_remote_index.raw-sorted.jsonl';
    }

    private function current_remote_index_sorted_path(): string
    {
        return $this->cursor['plan_directory']
            . '/current_remote_index.raw-sorted.jsonl';
    }

    private function remote_changes_raw_path(): string
    {
        return $this->cursor['plan_directory']
            . '/remote_changes.raw.jsonl';
    }

    private function remote_changes_depth_first_path(): string
    {
        return $this->cursor['plan_directory']
            . '/remote_changes.depth-first.jsonl';
    }

    private function conflicts_depth_first_path(): string
    {
        return $this->cursor['plan_directory']
            . '/conflicts.depth-first.jsonl';
    }

    private function local_change_stack_path(): string
    {
        return $this->cursor['plan_directory']
            . '/local-change-stack.jsonl';
    }
}
