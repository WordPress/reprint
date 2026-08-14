<?php
declare(strict_types=1);

use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;

require_once __DIR__ . '/../class-external-sort-processor.php';
require_once __DIR__ . '/class-remote-index-reader.php';
require_once __DIR__ . '/class-remote-index-traversal-journal.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors contain private state paths, never HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer processors use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer processors place braces on the following line.

/**
 * Builds one files-pull ownership snapshot from completed index traversals.
 *
 * Scanning and lookup steps read one durable input, write one atom or lookup
 * row, or change phase. ExternalSortProcessor owns both bounded, resumable
 * sorts. Root atoms own strict descendants, exact atoms own only intermediate
 * links, and ancestor atoms only protect recursive work. Output is flushed
 * before its cursor advances; resume truncates its tail. Sorted `paths.jsonl`
 * rows and a fixed-width hash-to-offset `lookup` use one opaque snapshot ID.
 *
 * @phpstan-type Cursor array{phase:'scanning'|'starting_paths_sort'|'sorting_paths'|'building_lookup'|'starting_lookup_sort'|'sorting_lookup'|'preparing_snapshot'|'snapshot_prepared'|'paths_published'|'lookup_published'|'cleaning_work_files'|'complete',traversal_journal_byte_offset:int,current_completion_journal_end_byte_offset:int|null,current_root_index:int,current_range_end_byte_offset:int|null,next_remote_index_byte_offset:int,pending_atom_kind:'root'|'exact'|'ancestor'|null,pending_atom_path_b64:string|null,pending_next_remote_index_byte_offset:int|null,paths_byte_offset:int,lookup_paths_byte_offset:int,lookup_byte_offset:int,paths_sort_cursor:array|null,lookup_sort_cursor:array|null,snapshot_id:string|null,next_work_file_cleanup_index:int}
 */
final class FilesPullOwnershipProcessor
{
    private const LOOKUP_RECORD_BYTES = 82;
    /** Bounds each physical row read by one ownership step. */
    private const MAXIMUM_ROW_BYTES = 65536;
    private const PHASES_WITHOUT_SNAPSHOT_ID = [
        'scanning', 'starting_paths_sort', 'sorting_paths',
        'building_lookup', 'starting_lookup_sort', 'sorting_lookup',
        'preparing_snapshot',
    ];
    private const PHASES_WITH_SNAPSHOT_ID = [
        'snapshot_prepared', 'paths_published', 'lookup_published',
        'cleaning_work_files', 'complete',
    ];

    private string $traversal_journal_path;
    private int $durable_traversal_journal_byte_offset;
    private string $next_remote_index_path;
    private int $durable_next_remote_index_byte_offset;
    private string $ownership_directory;
    private string $work_directory;
    private string $paths_work_path;
    private string $sorted_paths_work_path;
    private string $lookup_work_path;
    private string $sorted_lookup_work_path;
    /** @var Cursor */
    private array $cursor;
    private ?RemoteIndexTraversalJournal $traversal_journal = null;
    /** @var resource|null Phase input handle. */
    private $input_handle = null;
    /** @var resource|null Phase output handle. */
    private $output_handle = null;
    private ?ExternalSortProcessor $sort_processor = null;
    /** @var array|null Current traversal completion retained between steps. */
    private ?array $current_completion = null;
    private bool $closed = false;

    public static function initial_cursor(): array
    {
        return [
            'phase' => 'scanning',
            'traversal_journal_byte_offset' => 0,
            'current_completion_journal_end_byte_offset' => null,
            'current_root_index' => 0,
            'current_range_end_byte_offset' => null,
            'next_remote_index_byte_offset' => 0,
            'pending_atom_kind' => null,
            'pending_atom_path_b64' => null,
            'pending_next_remote_index_byte_offset' => null,
            'paths_byte_offset' => 0,
            'lookup_paths_byte_offset' => 0,
            'lookup_byte_offset' => 0,
            'paths_sort_cursor' => null,
            'lookup_sort_cursor' => null,
            'snapshot_id' => null,
            'next_work_file_cleanup_index' => 0,
        ];
    }

    /** @phpstan-param Cursor $cursor */
    public static function resume(
        string $traversal_journal_path,
        int $durable_traversal_journal_byte_offset,
        string $next_remote_index_path,
        int $durable_next_remote_index_byte_offset,
        string $ownership_directory,
        array $cursor
    ): self {
        $processor = new self();
        $processor->traversal_journal_path = $traversal_journal_path;
        $processor->durable_traversal_journal_byte_offset = $durable_traversal_journal_byte_offset;
        $processor->next_remote_index_path = $next_remote_index_path;
        $processor->durable_next_remote_index_byte_offset = $durable_next_remote_index_byte_offset;
        $processor->ownership_directory = $ownership_directory;
        $processor->work_directory = $ownership_directory . '/work';
        $processor->paths_work_path = $processor->work_directory . '/paths.next.jsonl';
        $processor->sorted_paths_work_path = $processor->work_directory . '/paths.sorted.jsonl';
        $processor->lookup_work_path = $processor->work_directory . '/lookup.next';
        $processor->sorted_lookup_work_path = $processor->work_directory . '/lookup.sorted';
        $processor->cursor = $cursor;
        $processor->assert_cursor();
        if (
            !is_dir($processor->work_directory)
            && !mkdir($processor->work_directory, 0777, true)
            && !is_dir($processor->work_directory)
        ) {
            throw new RuntimeException("Failed to create ownership work directory: {$processor->work_directory}.");
        }
        try {
            $processor->open_phase_handles();
            if ($cursor['phase'] === 'complete') {
                $snapshot_id = $processor->allocated_snapshot_id();
                if (
                    !is_file($processor->snapshot_paths_path($snapshot_id))
                    || !is_file($processor->snapshot_lookup_path($snapshot_id))
                ) {
                    throw new UnexpectedValueException('Completed ownership snapshot artifacts do not exist.');
                }
            }
        } catch (Throwable $throwable) {
            $processor->close();
            throw $throwable;
        }
        return $processor;
    }

    public function next_step(): bool
    {
        if ($this->cursor['phase'] === 'complete') {
            return false;
        }
        if ($this->closed) {
            throw new LogicException('Cannot take an ownership step after close().');
        }
        switch ($this->cursor['phase']) {
            case 'scanning':
                return $this->scan_next_step();
            case 'starting_paths_sort':
                return $this->start_paths_sort();
            case 'sorting_paths':
                return $this->sort_paths_next_step();
            case 'building_lookup':
                return $this->build_next_lookup_record();
            case 'starting_lookup_sort':
                return $this->start_lookup_sort();
            case 'sorting_lookup':
                return $this->sort_lookup_next_step();
            case 'preparing_snapshot':
                return $this->prepare_snapshot();
            case 'snapshot_prepared':
                return $this->publish_paths();
            case 'paths_published':
                return $this->publish_lookup();
            case 'lookup_published':
                $this->cursor['phase'] = 'cleaning_work_files';
                return true;
            case 'cleaning_work_files':
                return $this->remove_next_work_file();
        }
    }

    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** Includes the subordinate sort phase which requires an immediate save. */
    public function get_checkpoint_phase(): string
    {
        if ($this->cursor['phase'] === 'sorting_paths') {
            return 'sorting_paths:' . $this->cursor['paths_sort_cursor']['phase'];
        }
        if ($this->cursor['phase'] === 'sorting_lookup') {
            return 'sorting_lookup:' . $this->cursor['lookup_sort_cursor']['phase'];
        }
        return $this->cursor['phase'];
    }

    /** Extracts only a phase-owned allocated ID from an opaque saved cursor. */
    public static function snapshot_id_from_cursor(?array $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        if (!isset($cursor['phase']) || !array_key_exists('snapshot_id', $cursor)) {
            throw new UnexpectedValueException('Ownership processor cursor cannot identify its snapshot.');
        }
        if (
            !in_array($cursor['phase'], self::PHASES_WITH_SNAPSHOT_ID, true)
            && !in_array($cursor['phase'], self::PHASES_WITHOUT_SNAPSHOT_ID, true)
        ) {
            throw new UnexpectedValueException('Ownership processor cursor phase is invalid.');
        }
        $has_snapshot_id = in_array(
            $cursor['phase'],
            self::PHASES_WITH_SNAPSHOT_ID,
            true
        );
        if (!$has_snapshot_id && $cursor['snapshot_id'] === null) {
            return null;
        }
        if (
            $has_snapshot_id
            && is_string($cursor['snapshot_id'])
            && preg_match('/^[0-9a-f]{64}$/D', $cursor['snapshot_id']) === 1
        ) {
            return $cursor['snapshot_id'];
        }
        throw new UnexpectedValueException('Ownership processor snapshot ID is inconsistent with its phase.');
    }

    public function get_snapshot_id(): string
    {
        if ($this->cursor['phase'] !== 'complete') {
            throw new LogicException('Ownership snapshot is not complete.');
        }
        return $this->allocated_snapshot_id();
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->close_phase_handles();
        }
    }

    /** Idempotently removes one opaque snapshot artifact pair. */
    public static function remove_snapshot(
        string $ownership_directory,
        string $snapshot_id
    ): void {
        if (preg_match('/^[0-9a-f]{64}$/D', $snapshot_id) !== 1) {
            throw new InvalidArgumentException('Ownership snapshot ID must be 64 lowercase hexadecimal characters.');
        }
        foreach (['paths.jsonl', 'lookup'] as $suffix) {
            $path = self::snapshot_path($ownership_directory, $snapshot_id, $suffix);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException("Failed to remove ownership snapshot artifact: {$path}.");
            }
        }
    }

    private function scan_next_step(): bool
    {
        if ($this->cursor['pending_atom_kind'] !== null) {
            return $this->write_pending_atom_step();
        }
        if ($this->cursor['current_completion_journal_end_byte_offset'] === null) {
            $completion = $this->traversal_journal->read_completed_traversal_at(
                $this->cursor['traversal_journal_byte_offset'],
                $this->durable_traversal_journal_byte_offset
            );
            if ($completion === null) {
                if (
                    $this->cursor['next_remote_index_byte_offset']
                        !== $this->durable_next_remote_index_byte_offset
                ) {
                    throw new UnexpectedValueException('Completed traversals do not cover the durable next remote index.');
                }
                $this->cursor['phase'] = 'starting_paths_sort';
                return true;
            }
            if (
                $completion['next_remote_index_start_byte_offset']
                    !== $this->cursor['next_remote_index_byte_offset']
                || $completion['next_remote_index_end_byte_offset']
                    > $this->durable_next_remote_index_byte_offset
            ) {
                throw new UnexpectedValueException('Traversal ranges must be contiguous inside the durable next index.');
            }
            $this->cursor['current_completion_journal_end_byte_offset'] = $completion['next_traversal_journal_byte_offset'];
            $this->cursor['current_range_end_byte_offset'] = $completion['next_remote_index_end_byte_offset'];
            $this->current_completion = $completion;
            return true;
        }

        if ($this->current_completion === null) {
            $this->current_completion =
                $this->traversal_journal->read_completed_traversal_at(
                    $this->cursor['traversal_journal_byte_offset'],
                    $this->durable_traversal_journal_byte_offset
                );
        }
        $completion = $this->current_completion;
        if (
            !is_array($completion)
            || $completion['next_traversal_journal_byte_offset']
                !== $this->cursor['current_completion_journal_end_byte_offset']
            || $completion['next_remote_index_end_byte_offset']
                !== $this->cursor['current_range_end_byte_offset']
            || $this->cursor['current_root_index'] > count($completion['indexed_roots'])
        ) {
            throw new UnexpectedValueException('Ownership cursor does not match its traversal completion.');
        }
        if ($this->cursor['current_root_index'] < count($completion['indexed_roots'])) {
            $this->start_pending_atom('root', $completion['indexed_roots'][$this->cursor['current_root_index']], null);
            return $this->write_pending_atom_step();
        }
        $range_end_byte_offset = $this->cursor['current_range_end_byte_offset'];
        if ($this->cursor['next_remote_index_byte_offset'] < $range_end_byte_offset) {
            return $this->read_next_index_line($range_end_byte_offset);
        }
        if ($this->cursor['next_remote_index_byte_offset'] !== $range_end_byte_offset) {
            throw new UnexpectedValueException('Ownership cursor passed its traversal range.');
        }
        $this->cursor['traversal_journal_byte_offset'] = $this->cursor['current_completion_journal_end_byte_offset'];
        $this->cursor['current_completion_journal_end_byte_offset'] = null;
        $this->cursor['current_root_index'] = 0;
        $this->cursor['current_range_end_byte_offset'] = null;
        $this->current_completion = null;
        return true;
    }

    private function read_next_index_line(int $range_end_byte_offset): bool
    {
        if (fseek($this->input_handle, $this->cursor['next_remote_index_byte_offset']) !== 0) {
            throw new RuntimeException('Failed to seek to the next ownership index entry.');
        }
        $line = self::read_bounded_row(
            $this->input_handle,
            'remote-index'
        );
        $line_end_byte_offset = ftell($this->input_handle);
        if (
            !is_string($line)
            || substr($line, -1) !== "\n"
            || !is_int($line_end_byte_offset)
            || $line_end_byte_offset > $range_end_byte_offset
        ) {
            throw new UnexpectedValueException('Remote-index traversal range ends inside an index entry.');
        }
        $raw_entry = json_decode(substr($line, 0, -1), true);
        if (!is_array($raw_entry)) {
            throw new UnexpectedValueException('Traversal range contains invalid JSON.');
        }
        $decoded_entry = RemoteIndexReader::decode_index_line($line);
        $remote_absolute_path = RemoteIndexTraversalJournal::decode_canonical_path(
            $raw_entry['path'] ?? null,
            'remote-index ownership path'
        );
        if (
            array_key_exists('intermediate', $raw_entry)
            && !is_bool($raw_entry['intermediate'])
        ) {
            throw new UnexpectedValueException('Remote-index intermediate must be boolean.');
        }
        if (!empty($raw_entry['intermediate'])) {
            if ($decoded_entry['type'] !== 'link') {
                throw new UnexpectedValueException('Only a link may be intermediate.');
            }
            $this->start_pending_atom('exact', $remote_absolute_path, $line_end_byte_offset);
            return $this->write_pending_atom_step();
        }
        $this->cursor['next_remote_index_byte_offset'] = $line_end_byte_offset;
        return true;
    }

    private function start_pending_atom(
        string $kind,
        string $remote_absolute_path,
        ?int $next_remote_index_byte_offset
    ): void {
        self::assert_remote_absolute_path($remote_absolute_path);
        $this->cursor['pending_atom_kind'] = $kind;
        $this->cursor['pending_atom_path_b64'] = base64_encode($remote_absolute_path);
        $this->cursor['pending_next_remote_index_byte_offset'] = $next_remote_index_byte_offset;
    }

    private function write_pending_atom_step(): bool
    {
        $remote_absolute_path = base64_decode(
            $this->cursor['pending_atom_path_b64'],
            true
        );
        if (!is_string($remote_absolute_path)) {
            throw new UnexpectedValueException('Ownership cursor has an invalid path.');
        }
        $this->append_atom($this->cursor['pending_atom_kind'], $remote_absolute_path);
        $parent = self::strict_parent($remote_absolute_path);
        if ($parent !== null) {
            $this->cursor['pending_atom_kind'] = 'ancestor';
            $this->cursor['pending_atom_path_b64'] = base64_encode($parent);
            return true;
        }
        if ($this->cursor['pending_next_remote_index_byte_offset'] === null) {
            ++$this->cursor['current_root_index'];
        } else {
            $this->cursor['next_remote_index_byte_offset'] = $this->cursor['pending_next_remote_index_byte_offset'];
        }
        $this->cursor['pending_atom_kind'] = null;
        $this->cursor['pending_atom_path_b64'] = null;
        $this->cursor['pending_next_remote_index_byte_offset'] = null;
        return true;
    }

    private function append_atom(string $kind, string $remote_absolute_path): void
    {
        $line = json_encode([
            'kind' => $kind,
            'path_b64' => base64_encode($remote_absolute_path),
        ], JSON_UNESCAPED_SLASHES) . "\n";
        if (
            fwrite($this->output_handle, $line) !== strlen($line)
            || !fflush($this->output_handle)
        ) {
            throw new RuntimeException('Failed to append an ownership atom.');
        }
        $byte_offset = ftell($this->output_handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException('Failed to read the ownership paths offset.');
        }
        $this->cursor['paths_byte_offset'] = $byte_offset;
    }

    private function build_next_lookup_record(): bool
    {
        $paths_byte_offset = ftell($this->input_handle);
        $line = self::read_bounded_row(
            $this->input_handle,
            'sorted ownership paths'
        );
        if ($line === false) {
            if (!feof($this->input_handle)) {
                throw new RuntimeException('Failed to read sorted ownership paths.');
            }
            $this->cursor['phase'] = 'starting_lookup_sort';
            return true;
        }
        if (!is_int($paths_byte_offset)) {
            throw new RuntimeException('Failed to read the ownership paths position.');
        }
        $atom = self::decode_atom_line($line);
        $record = hash('sha256', $atom['kind'] . "\0" . $atom['path'])
            . ' ' . sprintf('%016x', $paths_byte_offset) . "\n";
        if (
            strlen($record) !== self::LOOKUP_RECORD_BYTES
            || fwrite($this->output_handle, $record) !== self::LOOKUP_RECORD_BYTES
            || !fflush($this->output_handle)
        ) {
            throw new RuntimeException('Failed to append the ownership lookup.');
        }
        $lookup_paths_byte_offset = ftell($this->input_handle);
        $lookup_byte_offset = ftell($this->output_handle);
        if (!is_int($lookup_paths_byte_offset) || !is_int($lookup_byte_offset)) {
            throw new RuntimeException('Failed to read ownership lookup offsets.');
        }
        $this->cursor['lookup_paths_byte_offset'] = $lookup_paths_byte_offset;
        $this->cursor['lookup_byte_offset'] = $lookup_byte_offset;
        return true;
    }

    private function start_paths_sort(): bool
    {
        $this->close_phase_handles();
        $this->sort_processor = ExternalSortProcessor::start(
            $this->paths_work_path,
            $this->sorted_paths_work_path,
            $this->work_directory,
            'ownership-paths',
            [self::class, 'paths_sort_key'],
            true
        );
        $this->cursor['paths_sort_cursor'] = $this->sort_processor->get_cursor();
        $this->cursor['phase'] = 'sorting_paths';
        return true;
    }

    private function sort_paths_next_step(): bool
    {
        $sort_processor = $this->current_sort_processor();
        $has_next = $sort_processor->next_step();
        $this->cursor['paths_sort_cursor'] = $sort_processor->get_cursor();
        if ($has_next) {
            return true;
        }
        $sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['paths_sort_cursor'] = null;
        $this->cursor['paths_byte_offset'] = $this->file_size($this->sorted_paths_work_path);
        $this->cursor['phase'] = 'building_lookup';
        $this->open_phase_handles();
        return true;
    }

    private function start_lookup_sort(): bool
    {
        $this->close_phase_handles();
        $this->sort_processor = ExternalSortProcessor::start(
            $this->lookup_work_path,
            $this->sorted_lookup_work_path,
            $this->work_directory,
            'ownership-lookup',
            [self::class, 'lookup_sort_key'],
            false
        );
        $this->cursor['lookup_sort_cursor'] = $this->sort_processor->get_cursor();
        $this->cursor['phase'] = 'sorting_lookup';
        return true;
    }

    private function sort_lookup_next_step(): bool
    {
        $sort_processor = $this->current_sort_processor();
        $has_next = $sort_processor->next_step();
        $this->cursor['lookup_sort_cursor'] = $sort_processor->get_cursor();
        if ($has_next) {
            return true;
        }
        $sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['lookup_sort_cursor'] = null;
        $this->cursor['lookup_byte_offset'] = $this->file_size($this->sorted_lookup_work_path);
        $this->cursor['phase'] = 'preparing_snapshot';
        return true;
    }

    public static function paths_sort_key(string $line): string
    {
        $atom = self::decode_atom_line($line);
        return $atom['path'] . "\0" . $atom['kind'];
    }

    public static function lookup_sort_key(string $line): string
    {
        if (preg_match('/^([0-9a-f]{64}) [0-9a-f]{16}$/D', $line, $matches) !== 1) {
            throw new UnexpectedValueException('Ownership lookup row is invalid.');
        }
        return $matches[1];
    }

    private function prepare_snapshot(): bool
    {
        $snapshots_directory = $this->ownership_directory . '/snapshots';
        if (
            !is_dir($snapshots_directory)
            && !mkdir($snapshots_directory, 0777, true)
            && !is_dir($snapshots_directory)
        ) {
            throw new RuntimeException("Failed to create snapshot directory: {$snapshots_directory}.");
        }
        $snapshot_id = bin2hex(random_bytes(32));
        if (
            file_exists($this->snapshot_paths_path($snapshot_id))
            || is_link($this->snapshot_paths_path($snapshot_id))
            || file_exists($this->snapshot_lookup_path($snapshot_id))
            || is_link($this->snapshot_lookup_path($snapshot_id))
        ) {
            throw new RuntimeException('Generated ownership snapshot ID is already in use.');
        }
        $this->cursor['snapshot_id'] = $snapshot_id;
        $this->cursor['phase'] = 'snapshot_prepared';
        return true;
    }

    private function publish_paths(): bool
    {
        $this->publish_artifact(
            $this->sorted_paths_work_path,
            $this->snapshot_paths_path($this->allocated_snapshot_id())
        );
        $this->cursor['phase'] = 'paths_published';
        return true;
    }

    private function publish_lookup(): bool
    {
        $this->publish_artifact(
            $this->sorted_lookup_work_path,
            $this->snapshot_lookup_path($this->allocated_snapshot_id())
        );
        $this->cursor['phase'] = 'lookup_published';
        return true;
    }

    private function publish_artifact(string $source, string $destination): void
    {
        if (is_file($source)) {
            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException("Ownership snapshot destination already exists: {$destination}.");
            }
            if (!rename($source, $destination)) {
                throw new RuntimeException("Failed to publish ownership snapshot artifact: {$destination}.");
            }
            return;
        }
        if (!is_file($destination) || is_link($destination)) {
            throw new RuntimeException("Ownership snapshot artifact disappeared: {$destination}.");
        }
    }

    private function remove_next_work_file(): bool
    {
        $work_files = [$this->paths_work_path, $this->lookup_work_path];
        $index = $this->cursor['next_work_file_cleanup_index'];
        if ($index < count($work_files)) {
            if (is_file($work_files[$index]) && !unlink($work_files[$index])) {
                throw new RuntimeException("Failed to remove ownership work file: {$work_files[$index]}.");
            }
            ++$this->cursor['next_work_file_cleanup_index'];
            return true;
        }
        $this->cursor['phase'] = 'complete';
        return false;
    }

    private static function snapshot_path(
        string $ownership_directory,
        string $snapshot_id,
        string $suffix
    ): string {
        return $ownership_directory . '/snapshots/'
            . $snapshot_id . '.' . $suffix;
    }

    private function snapshot_paths_path(string $snapshot_id): string
    {
        return self::snapshot_path(
            $this->ownership_directory,
            $snapshot_id,
            'paths.jsonl'
        );
    }

    private function snapshot_lookup_path(string $snapshot_id): string
    {
        return self::snapshot_path(
            $this->ownership_directory,
            $snapshot_id,
            'lookup'
        );
    }

    private function allocated_snapshot_id(): string
    {
        if (!is_string($this->cursor['snapshot_id'])) {
            throw new LogicException('Ownership snapshot ID has not been allocated.');
        }
        return $this->cursor['snapshot_id'];
    }

    private function current_sort_processor(): ExternalSortProcessor
    {
        if ($this->sort_processor === null) {
            throw new LogicException('Ownership sort processor is not open.');
        }
        return $this->sort_processor;
    }

    private function open_phase_handles(): void
    {
        if ($this->cursor['phase'] === 'scanning') {
            $this->traversal_journal = new RemoteIndexTraversalJournal(
                $this->traversal_journal_path
            );
            $this->traversal_journal->open_and_truncate_to_saved_byte_offset(
                $this->durable_traversal_journal_byte_offset
            );
            $this->input_handle = @fopen($this->next_remote_index_path, 'rb');
            $stat = is_resource($this->input_handle) ? fstat($this->input_handle) : false;
            if (
                $stat === false
                || $this->durable_next_remote_index_byte_offset > $stat['size']
            ) {
                throw new RuntimeException('Failed to open the durable next remote index.');
            }
            $this->output_handle = self::open_output(
                $this->paths_work_path,
                $this->cursor['paths_byte_offset']
            );
        } elseif ($this->cursor['phase'] === 'sorting_paths') {
            $this->sort_processor = ExternalSortProcessor::resume(
                $this->paths_work_path,
                $this->sorted_paths_work_path,
                $this->work_directory,
                'ownership-paths',
                [self::class, 'paths_sort_key'],
                true,
                $this->cursor['paths_sort_cursor']
            );
        } elseif ($this->cursor['phase'] === 'building_lookup') {
            $this->input_handle = @fopen($this->sorted_paths_work_path, 'rb');
            if (
                !is_resource($this->input_handle)
                || fseek($this->input_handle, $this->cursor['lookup_paths_byte_offset']) !== 0
            ) {
                throw new RuntimeException('Failed to resume ownership lookup input.');
            }
            $this->output_handle = self::open_output(
                $this->lookup_work_path,
                $this->cursor['lookup_byte_offset']
            );
        } elseif ($this->cursor['phase'] === 'sorting_lookup') {
            $this->sort_processor = ExternalSortProcessor::resume(
                $this->lookup_work_path,
                $this->sorted_lookup_work_path,
                $this->work_directory,
                'ownership-lookup',
                [self::class, 'lookup_sort_key'],
                false,
                $this->cursor['lookup_sort_cursor']
            );
        }
    }

    private function close_phase_handles(): void
    {
        if ($this->sort_processor !== null) {
            $this->sort_processor->close();
            $this->sort_processor = null;
        }
        if ($this->traversal_journal !== null) {
            $this->traversal_journal->close();
            $this->traversal_journal = null;
        }
        foreach (['input_handle', 'output_handle'] as $property) {
            if (is_resource($this->{$property})) {
                fclose($this->{$property});
            }
            $this->{$property} = null;
        }
    }

    /** Strictly validates the opaque persisted cursor before opening files. */
    private function assert_cursor(): void
    {
        $expected_keys = array_keys(self::initial_cursor());
        $actual_keys = array_keys($this->cursor);
        sort($actual_keys);
        sort($expected_keys);
        if ($actual_keys !== $expected_keys) {
            throw new InvalidArgumentException('Ownership cursor fields are invalid.');
        }
        if (!in_array($this->cursor['phase'], array_merge(
            self::PHASES_WITHOUT_SNAPSHOT_ID,
            self::PHASES_WITH_SNAPSHOT_ID
        ), true)) {
            throw new InvalidArgumentException('Ownership cursor phase is invalid.');
        }
        foreach ([
            'traversal_journal_byte_offset', 'current_root_index',
            'next_remote_index_byte_offset', 'paths_byte_offset',
            'lookup_paths_byte_offset', 'lookup_byte_offset',
            'next_work_file_cleanup_index',
        ] as $field) {
            if (!is_int($this->cursor[$field]) || $this->cursor[$field] < 0) {
                throw new InvalidArgumentException("Ownership cursor {$field} is invalid.");
            }
        }
        foreach ([
            'current_completion_journal_end_byte_offset',
            'current_range_end_byte_offset',
            'pending_next_remote_index_byte_offset',
        ] as $field) {
            if (
                $this->cursor[$field] !== null
                && ( !is_int($this->cursor[$field]) || $this->cursor[$field] < 0 )
            ) {
                throw new InvalidArgumentException("Ownership cursor {$field} is invalid.");
            }
        }
        if (
            !in_array($this->cursor['pending_atom_kind'], [
                'root', 'exact', 'ancestor', null,
            ], true)
            || ( $this->cursor['pending_atom_kind'] === null )
                !== ( $this->cursor['pending_atom_path_b64'] === null )
        ) {
            throw new InvalidArgumentException('Ownership cursor pending atom is invalid.');
        }
        $pending_path = is_string($this->cursor['pending_atom_path_b64'])
            ? base64_decode($this->cursor['pending_atom_path_b64'], true)
            : false;
        if (
            $this->cursor['pending_atom_path_b64'] !== null
            && ( !is_string($pending_path)
                || base64_encode($pending_path) !== $this->cursor['pending_atom_path_b64'] )
        ) {
            throw new InvalidArgumentException('Ownership cursor pending path is invalid.');
        }
        if (is_string($pending_path)) {
            self::assert_remote_absolute_path($pending_path);
        }
        $has_completion =
            $this->cursor['current_completion_journal_end_byte_offset'] !== null;
        if (
            $has_completion !== ( $this->cursor['current_range_end_byte_offset'] !== null )
            || ( !$has_completion && $this->cursor['current_root_index'] !== 0 )
            || ( $this->cursor['pending_atom_kind'] === null
                && $this->cursor['pending_next_remote_index_byte_offset'] !== null )
            || ( $this->cursor['pending_atom_kind'] === 'root'
                && $this->cursor['pending_next_remote_index_byte_offset'] !== null )
            || ( $this->cursor['pending_atom_kind'] === 'exact'
                && $this->cursor['pending_next_remote_index_byte_offset'] === null )
        ) {
            throw new InvalidArgumentException('Ownership cursor boundaries are inconsistent.');
        }
        if (
            $this->durable_traversal_journal_byte_offset < 0
            || $this->durable_next_remote_index_byte_offset < 0
            || $this->cursor['traversal_journal_byte_offset']
                > $this->durable_traversal_journal_byte_offset
            || $this->cursor['next_remote_index_byte_offset']
                > $this->durable_next_remote_index_byte_offset
        ) {
            throw new InvalidArgumentException('Ownership cursor exceeds a durable input boundary.');
        }
        $sorting_paths = $this->cursor['phase'] === 'sorting_paths';
        $sorting_lookup = $this->cursor['phase'] === 'sorting_lookup';
        if (
            ( $sorting_paths && !is_array($this->cursor['paths_sort_cursor']) )
            || ( !$sorting_paths && $this->cursor['paths_sort_cursor'] !== null )
            || ( $sorting_lookup && !is_array($this->cursor['lookup_sort_cursor']) )
            || ( !$sorting_lookup && $this->cursor['lookup_sort_cursor'] !== null )
        ) {
            throw new InvalidArgumentException('Ownership nested sort cursor is inconsistent with its phase.');
        }
        try {
            self::snapshot_id_from_cursor($this->cursor);
        } catch (UnexpectedValueException $exception) {
            throw new InvalidArgumentException(
                'Ownership snapshot cursor is inconsistent with its phase.',
                0,
                $exception
            );
        }
        if (
            $this->cursor['next_work_file_cleanup_index'] > 2
            || ( !in_array($this->cursor['phase'], ['cleaning_work_files', 'complete'], true)
                && $this->cursor['next_work_file_cleanup_index'] !== 0 )
            || ( $this->cursor['phase'] === 'complete'
                && $this->cursor['next_work_file_cleanup_index'] !== 2 )
        ) {
            throw new InvalidArgumentException('Ownership work-file cleanup cursor is invalid.');
        }
    }

    /**
     * @param resource $handle Open input stream.
     * @return string|false One complete row, or false at EOF.
     */
    private static function read_bounded_row($handle, string $name)
    {
        $byte_offset = ftell($handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException("Failed to read the {$name} row offset.");
        }
        $row = fgets($handle, self::MAXIMUM_ROW_BYTES + 1);
        if ($row === false) {
            if (feof($handle)) {
                return false;
            }
            throw new RuntimeException("Failed to read the {$name} row at byte offset {$byte_offset}.");
        }
        if (substr($row, -1) !== "\n") {
            $row_bytes = strlen($row);
            if (feof($handle)) {
                throw new UnexpectedValueException(
                    "The {$name} row at byte offset {$byte_offset} is unterminated after {$row_bytes} bytes."
                );
            }
            throw new UnexpectedValueException(
                "The {$name} row at byte offset {$byte_offset} exceeds the maximum of "
                . self::MAXIMUM_ROW_BYTES . " bytes; read {$row_bytes} bytes without LF."
            );
        }
        return $row;
    }

    private static function decode_atom_line(string $line): array
    {
        $atom = json_decode(rtrim($line, "\n"), true);
        if (!is_array($atom) || array_keys($atom) !== ['kind', 'path_b64']) {
            throw new UnexpectedValueException('Ownership paths contain an invalid atom.');
        }
        if (!in_array($atom['kind'], ['root', 'exact', 'ancestor'], true)) {
            throw new UnexpectedValueException('Ownership atom kind is invalid.');
        }
        $path = is_string($atom['path_b64'])
            ? base64_decode($atom['path_b64'], true)
            : false;
        if (!is_string($path) || base64_encode($path) !== $atom['path_b64']) {
            throw new UnexpectedValueException('Ownership atom path has invalid base64.');
        }
        self::assert_remote_absolute_path($path);
        return ['kind' => $atom['kind'], 'path' => $path];
    }

    private static function strict_parent(string $remote_absolute_path): ?string
    {
        if ($remote_absolute_path === '/') {
            return null;
        }
        $last_separator = strrpos($remote_absolute_path, '/');
        return $last_separator === 0
            ? '/'
            : substr($remote_absolute_path, 0, $last_separator);
    }

    /** @return resource */
    private static function open_output(string $path, int $byte_offset)
    {
        $handle = @fopen($path, 'c+b');
        $stat = is_resource($handle) ? fstat($handle) : false;
        if (
            $stat === false
            || $byte_offset > $stat['size']
            || !ftruncate($handle, $byte_offset)
            || fseek($handle, $byte_offset) !== 0
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException("Failed to resume ownership output: {$path}.");
        }
        return $handle;
    }

    private function file_size(string $path): int
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (!is_int($size)) {
            throw new RuntimeException("Failed to read ownership file size: {$path}.");
        }
        return $size;
    }

    private static function assert_remote_absolute_path(string $remote_absolute_path): void
    {
        assert_valid_path($remote_absolute_path, 'ownership path');
        if (
            $remote_absolute_path === ''
            || $remote_absolute_path[0] !== '/'
            || normalize_path($remote_absolute_path) !== $remote_absolute_path
        ) {
            throw new UnexpectedValueException(
                'Ownership path must be a normalized remote absolute path.'
            );
        }
    }
}
