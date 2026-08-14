<?php
declare(strict_types=1);

use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;

require_once __DIR__ . '/../class-external-sort-processor.php';
require_once __DIR__ . '/../index/class-file-sync-change-scope.php';
require_once __DIR__ . '/class-remote-to-local-path-mapper.php';

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors contain private state paths, never HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer processors use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer processors place braces on the following line.

/**
 * Maps one remote file-sync change scope into local index coordinates.
 *
 * Current and prior exact ownership atoms are the only candidates which can
 * be selected despite the ordinary local indexer's default skips. One scan
 * step reads one atom, checks one mapping boundary, or appends one candidate.
 * The path-only sidecar is sorted and deduplicated before atomic publication.
 * Protected snapshots never supply candidates; the mapped change scope still
 * consults them before an exact path is retained.
 *
 * A root-owned remote region must not gain stricter default skips after path
 * mapping. Each owned root placement and each reachable explicit remap is
 * checked separately, so a normal local traversal can still observe every
 * remote-visible descendant without scanning all default-skipped directories.
 *
 * @phpstan-type SelectedAtomCursor array{selection_fingerprint:string,selected_snapshot_index:int,paths_byte_offset:int}
 * @phpstan-type Cursor array{phase:'scanning_atoms'|'starting_sort'|'sorting_paths'|'complete',configuration_fingerprint:string,selected_atom_cursor:SelectedAtomCursor,pending_root_path_b64:string|null,pending_mapping_index:int,paths_byte_offset:int,sort_cursor:array|null}
 */
final class FileSyncChangeScopeMappingProcessor
{
    private const MAXIMUM_PATH_ROW_BYTES = 65536;
    private const CONTEXT_RANKS = [
        'ordinary' => 0,
        'wp_content' => 1,
        'all_descendants' => 2,
    ];

    private FileSyncChangeScope $remote_change_scope;
    private FileSyncChangeScope $local_change_scope;
    private RemoteToLocalPathMapper $remote_to_local_path_mapper;
    private string $work_directory;
    private string $unsorted_paths_file;
    private string $selected_paths_file;
    private string $configuration_fingerprint;
    /** @phpstan-var Cursor */
    private array $cursor;
    /** @var resource|null */
    private $paths_handle = null;
    private ?ExternalSortProcessor $sort_processor = null;
    private bool $closed = false;

    public static function start(
        FileSyncChangeScope $remote_change_scope,
        RemoteToLocalPathMapper $remote_to_local_path_mapper,
        string $work_directory
    ): self {
        $work_directory = self::prepare_work_directory($work_directory);
        $unsorted_paths_file = $work_directory
            . '/selected-default-skipped-index-paths.unsorted.jsonl';
        if (is_link($unsorted_paths_file)) {
            throw new InvalidArgumentException(
                'Selected default-skipped paths work file must not be a symbolic link.'
            );
        }
        $paths_handle = @fopen($unsorted_paths_file, 'wb');
        if (!is_resource($paths_handle) || !fflush($paths_handle)) {
            if (is_resource($paths_handle)) {
                fclose($paths_handle);
            }
            throw new RuntimeException(
                "Failed to initialize selected default-skipped paths work file: {$unsorted_paths_file}."
            );
        }
        fclose($paths_handle);

        $phase = $remote_change_scope->includes_caches()
            ? 'starting_sort'
            : 'scanning_atoms';
        return self::resume(
            $remote_change_scope,
            $remote_to_local_path_mapper,
            $work_directory,
            [
                'phase' => $phase,
                'configuration_fingerprint' => self::configuration_fingerprint(
                    $remote_change_scope,
                    $remote_to_local_path_mapper,
                    $work_directory
                ),
                'selected_atom_cursor' => $remote_change_scope->includes_caches()
                    ? $remote_change_scope->completed_selected_ownership_atom_cursor()
                    : $remote_change_scope->initial_selected_ownership_atom_cursor(),
                'pending_root_path_b64' => null,
                'pending_mapping_index' => 0,
                'paths_byte_offset' => 0,
                'sort_cursor' => null,
            ]
        );
    }

    /** @phpstan-param Cursor $cursor */
    public static function resume(
        FileSyncChangeScope $remote_change_scope,
        RemoteToLocalPathMapper $remote_to_local_path_mapper,
        string $work_directory,
        array $cursor
    ): self {
        $work_directory = self::prepare_work_directory($work_directory);
        $remote_config = $remote_change_scope->get_config();
        if ($remote_config['index_path_coordinates'] !== 'remote_absolute') {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping requires remote_absolute index paths.'
            );
        }

        $processor = new self();
        $processor->remote_change_scope = $remote_change_scope;
        $processor->remote_to_local_path_mapper = $remote_to_local_path_mapper;
        $processor->work_directory = $work_directory;
        $processor->unsorted_paths_file = $work_directory
            . '/selected-default-skipped-index-paths.unsorted.jsonl';
        $processor->selected_paths_file = $work_directory
            . '/selected-default-skipped-index-paths.jsonl';
        $processor->configuration_fingerprint = self::configuration_fingerprint(
            $remote_change_scope,
            $remote_to_local_path_mapper,
            $work_directory
        );
        $processor->cursor = $cursor;
        $processor->assert_cursor();
        if (!hash_equals(
            $processor->configuration_fingerprint,
            $cursor['configuration_fingerprint']
        )) {
            throw new UnexpectedValueException(
                'File-sync change-scope mapping configuration changed after start.'
            );
        }
        $processor->local_change_scope =
            $remote_to_local_path_mapper->map_change_scope_to_local_index(
                $remote_change_scope,
                $processor->selected_paths_file
            );
        try {
            $processor->open_phase_resources();
            if (
                $cursor['phase'] === 'complete'
                && !is_file($processor->selected_paths_file)
            ) {
                throw new UnexpectedValueException(
                    'Completed file-sync change-scope selected paths file does not exist.'
                );
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
            throw new LogicException(
                'Cannot take a file-sync change-scope mapping step after close().'
            );
        }
        switch ($this->cursor['phase']) {
            case 'scanning_atoms':
                return $this->scan_next_atom_or_mapping();
            case 'starting_sort':
                return $this->start_sort();
            case 'sorting_paths':
                return $this->sort_next_step();
        }
    }

    /** @phpstan-return Cursor */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /** @return array<string,mixed> Strict local-relative change-scope config. */
    public function get_local_change_scope_config(): array
    {
        if ($this->cursor['phase'] !== 'complete') {
            throw new LogicException(
                'Local file-sync change-scope config is unavailable before mapping completes.'
            );
        }
        return $this->local_change_scope->get_config();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->paths_handle)) {
            fclose($this->paths_handle);
        }
        $this->paths_handle = null;
        if ($this->sort_processor !== null) {
            $this->sort_processor->close();
            $this->sort_processor = null;
        }
        $this->local_change_scope->close();
    }

    private function scan_next_atom_or_mapping(): bool
    {
        $this->open_phase_resources();
        if ($this->cursor['pending_root_path_b64'] !== null) {
            return $this->validate_next_root_mapping();
        }

        $result = $this->remote_change_scope->read_next_selected_ownership_atom(
            $this->cursor['selected_atom_cursor']
        );
        if ($result['atom'] === null) {
            $this->cursor['selected_atom_cursor'] = $result['cursor'];
            if ($result['complete']) {
                $this->close_paths_handle();
                $this->cursor['phase'] = 'starting_sort';
            }
            return true;
        }

        $atom = $result['atom'];
        if ($atom['kind'] === 'root') {
            $this->cursor['selected_atom_cursor'] = $result['cursor'];
            $this->cursor['pending_root_path_b64'] = base64_encode($atom['path']);
            $this->cursor['pending_mapping_index'] = 0;
            return true;
        }
        if ($atom['kind'] === 'exact') {
            $this->append_selected_exact_path($atom['path']);
        }
        $this->cursor['selected_atom_cursor'] = $result['cursor'];
        return true;
    }

    private function validate_next_root_mapping(): bool
    {
        $remote_root = base64_decode(
            $this->cursor['pending_root_path_b64'],
            true
        );
        if (!is_string($remote_root)) {
            throw new UnexpectedValueException(
                'File-sync change-scope mapping cursor root path is invalid.'
            );
        }
        $mapping_prefixes =
            $this->remote_to_local_path_mapper
                ->get_resolved_remote_mapping_prefixes();
        $mapping_index = $this->cursor['pending_mapping_index'];
        if ($mapping_index === 0) {
            $this->assert_region_mapping_preserves_default_skips($remote_root);
        } else {
            $remote_mapping_prefix = $mapping_prefixes[$mapping_index - 1];
            if (
                $remote_mapping_prefix !== $remote_root
                && path_is_same_as_or_descendant_of(
                    $remote_mapping_prefix,
                    $remote_root
                )
                && !FileIndexProcessor::path_is_default_skipped_below_root(
                    $remote_root,
                    $remote_mapping_prefix
                )
            ) {
                $this->assert_region_mapping_preserves_default_skips(
                    $remote_mapping_prefix
                );
            }
        }

        ++$this->cursor['pending_mapping_index'];
        if ($this->cursor['pending_mapping_index'] > count($mapping_prefixes)) {
            $this->cursor['pending_root_path_b64'] = null;
            $this->cursor['pending_mapping_index'] = 0;
        }
        return true;
    }

    private function assert_region_mapping_preserves_default_skips(
        string $remote_absolute_path
    ): void {
        if (!$this->remote_change_scope->root_owned_region_may_change(
            $remote_absolute_path
        )) {
            return;
        }
        $remote_context = FileIndexProcessor::default_skip_descendant_context(
            $remote_absolute_path
        );
        $local_absolute_path =
            $this->remote_to_local_path_mapper->map_path($remote_absolute_path);
        $local_context = FileIndexProcessor::path_is_default_skipped_below_root(
            $this->remote_to_local_path_mapper->get_filesystem_root(),
            $local_absolute_path
        )
            ? 'all_descendants'
            : FileIndexProcessor::default_skip_descendant_context(
                $local_absolute_path
            );
        if (
            self::CONTEXT_RANKS[$remote_context]
                < self::CONTEXT_RANKS[$local_context]
        ) {
            throw new InvalidArgumentException(
                "Remote region {$remote_absolute_path} uses {$remote_context} default-skip context, "
                . "but it maps to {$local_absolute_path} with stricter {$local_context} context."
            );
        }
    }

    private function append_selected_exact_path(
        string $remote_absolute_path
    ): void {
        $local_relative_path =
            $this->local_change_scope
                ->map_remote_absolute_path_to_index_path($remote_absolute_path);
        if (
            !$this->local_change_scope->index_entry_may_change(
                $local_relative_path,
                'link'
            )
        ) {
            return;
        }
        $local_absolute_path =
            $this->remote_to_local_path_mapper->map_path($remote_absolute_path);
        if (!FileIndexProcessor::path_is_default_skipped_below_root(
            $this->remote_to_local_path_mapper->get_filesystem_root(),
            $local_absolute_path
        )) {
            return;
        }

        $line = json_encode([
            'path_b64' => base64_encode($local_relative_path),
        ], JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($line) > self::MAXIMUM_PATH_ROW_BYTES) {
            throw new UnexpectedValueException(
                'Selected default-skipped index path row exceeds the 65536-byte limit.'
            );
        }
        if (
            fwrite($this->paths_handle, $line) !== strlen($line)
            || !fflush($this->paths_handle)
        ) {
            throw new RuntimeException(
                'Failed to append a selected default-skipped index path.'
            );
        }
        $byte_offset = ftell($this->paths_handle);
        if (!is_int($byte_offset)) {
            throw new RuntimeException(
                'Failed to read the selected default-skipped paths byte offset.'
            );
        }
        $this->cursor['paths_byte_offset'] = $byte_offset;
    }

    private function start_sort(): bool
    {
        $this->sort_processor = ExternalSortProcessor::start(
            $this->unsorted_paths_file,
            $this->selected_paths_file,
            $this->work_directory,
            'selected-default-skipped-paths',
            self::path_row_key_extractor(),
            true
        );
        $this->cursor['sort_cursor'] = $this->sort_processor->get_cursor();
        $this->cursor['phase'] = 'sorting_paths';
        return true;
    }

    private function sort_next_step(): bool
    {
        $this->open_phase_resources();
        if ($this->sort_processor->next_step()) {
            $this->cursor['sort_cursor'] = $this->sort_processor->get_cursor();
            return true;
        }
        $this->sort_processor->close();
        $this->sort_processor = null;
        $this->cursor['sort_cursor'] = null;
        $this->cursor['phase'] = 'complete';
        return false;
    }

    private function open_phase_resources(): void
    {
        if (
            $this->cursor['phase'] === 'scanning_atoms'
            && !is_resource($this->paths_handle)
        ) {
            if (is_link($this->unsorted_paths_file)) {
                throw new UnexpectedValueException(
                    'Selected default-skipped paths work file became a symbolic link.'
                );
            }
            $handle = @fopen($this->unsorted_paths_file, 'c+b');
            if (!is_resource($handle)) {
                throw new RuntimeException(
                    "Failed to open selected default-skipped paths work file: {$this->unsorted_paths_file}."
                );
            }
            $stat = fstat($handle);
            if (
                !is_array($stat)
                || !isset($stat['size'])
                || !is_int($stat['size'])
                || $this->cursor['paths_byte_offset'] > $stat['size']
            ) {
                fclose($handle);
                throw new UnexpectedValueException(
                    'Selected default-skipped paths cursor exceeds its work file.'
                );
            }
            if (
                !ftruncate($handle, $this->cursor['paths_byte_offset'])
                || fseek($handle, $this->cursor['paths_byte_offset']) !== 0
            ) {
                fclose($handle);
                throw new RuntimeException(
                    'Failed to resume the selected default-skipped paths work file.'
                );
            }
            $this->paths_handle = $handle;
        }
        if (
            $this->cursor['phase'] === 'sorting_paths'
            && $this->sort_processor === null
        ) {
            $this->sort_processor = ExternalSortProcessor::resume(
                $this->unsorted_paths_file,
                $this->selected_paths_file,
                $this->work_directory,
                'selected-default-skipped-paths',
                self::path_row_key_extractor(),
                true,
                $this->cursor['sort_cursor']
            );
        }
    }

    private function close_paths_handle(): void
    {
        if (is_resource($this->paths_handle)) {
            fclose($this->paths_handle);
        }
        $this->paths_handle = null;
    }

    /** @return callable(string):string */
    private static function path_row_key_extractor(): callable
    {
        return static function (string $line): string {
            $row = json_decode($line, true);
            if (!is_array($row) || array_keys($row) !== ['path_b64']) {
                throw new UnexpectedValueException(
                    'Selected default-skipped path row must contain only path_b64.'
                );
            }
            $path = is_string($row['path_b64'])
                ? base64_decode($row['path_b64'], true)
                : false;
            if (!is_string($path) || base64_encode($path) !== $row['path_b64']) {
                throw new UnexpectedValueException(
                    'Selected default-skipped path row contains an invalid path.'
                );
            }
            return $path;
        };
    }

    private static function prepare_work_directory(string $work_directory): string
    {
        assert_valid_path($work_directory, 'file-sync change-scope mapping work directory');
        if (
            !is_dir($work_directory)
            && !mkdir($work_directory, 0777, true)
            && !is_dir($work_directory)
        ) {
            throw new RuntimeException(
                "Failed to create file-sync change-scope mapping work directory: {$work_directory}."
            );
        }
        $resolved_work_directory = realpath($work_directory);
        if (!is_string($resolved_work_directory)) {
            throw new RuntimeException(
                "Failed to resolve file-sync change-scope mapping work directory: {$work_directory}."
            );
        }
        return $resolved_work_directory;
    }

    private static function configuration_fingerprint(
        FileSyncChangeScope $remote_change_scope,
        RemoteToLocalPathMapper $remote_to_local_path_mapper,
        string $work_directory
    ): string {
        $configuration = json_encode([
            'remote_change_scope' => $remote_change_scope->get_config(),
            'remote_to_local_path_mapper' =>
                $remote_to_local_path_mapper->get_config(),
            'work_directory_b64' => base64_encode($work_directory),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($configuration)) {
            throw new RuntimeException(
                'Failed to fingerprint file-sync change-scope mapping configuration.'
            );
        }
        return hash('sha256', $configuration);
    }

    private function assert_cursor(): void
    {
        $actual_keys = array_keys($this->cursor);
        sort($actual_keys, SORT_STRING);
        $expected_keys = [
            'phase',
            'configuration_fingerprint',
            'selected_atom_cursor',
            'pending_root_path_b64',
            'pending_mapping_index',
            'paths_byte_offset',
            'sort_cursor',
        ];
        sort($expected_keys, SORT_STRING);
        if ($actual_keys !== $expected_keys) {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping cursor fields are invalid.'
            );
        }
        if (!in_array($this->cursor['phase'], [
            'scanning_atoms', 'starting_sort', 'sorting_paths', 'complete',
        ], true)) {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping cursor phase is invalid.'
            );
        }
        if (
            !is_string($this->cursor['configuration_fingerprint'])
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $this->cursor['configuration_fingerprint']
            ) !== 1
            || !is_array($this->cursor['selected_atom_cursor'])
            || !is_int($this->cursor['pending_mapping_index'])
            || $this->cursor['pending_mapping_index'] < 0
            || !is_int($this->cursor['paths_byte_offset'])
            || $this->cursor['paths_byte_offset'] < 0
            || (
                $this->cursor['sort_cursor'] !== null
                && !is_array($this->cursor['sort_cursor'])
            )
        ) {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping cursor values are invalid.'
            );
        }

        $selected_atoms_complete = $this->remote_change_scope
            ->selected_ownership_atom_cursor_is_complete(
                $this->cursor['selected_atom_cursor']
            );
        $is_scanning = $this->cursor['phase'] === 'scanning_atoms';
        if ($is_scanning === $selected_atoms_complete) {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping cursor phase disagrees with its selected-atom cursor.'
            );
        }

        if ($this->cursor['pending_root_path_b64'] !== null) {
            $pending_root = is_string($this->cursor['pending_root_path_b64'])
                ? base64_decode($this->cursor['pending_root_path_b64'], true)
                : false;
            if (
                !$is_scanning
                || !is_string($pending_root)
                || base64_encode($pending_root)
                    !== $this->cursor['pending_root_path_b64']
            ) {
                throw new InvalidArgumentException(
                    'File-sync change-scope mapping cursor root path is invalid.'
                );
            }
            assert_valid_path(
                $pending_root,
                'file-sync change-scope mapping cursor root'
            );
            if (
                $this->cursor['pending_mapping_index']
                    > count(
                        $this->remote_to_local_path_mapper
                            ->get_resolved_remote_mapping_prefixes()
                    )
            ) {
                throw new InvalidArgumentException(
                    'File-sync change-scope mapping cursor mapping index is invalid.'
                );
            }
        } elseif ($this->cursor['pending_mapping_index'] !== 0) {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping cursor has no root for its mapping index.'
            );
        }

        $has_sort_cursor = is_array($this->cursor['sort_cursor']);
        if (
            ( $this->cursor['phase'] === 'sorting_paths' ) !== $has_sort_cursor
        ) {
            throw new InvalidArgumentException(
                'File-sync change-scope mapping sort cursor is inconsistent with its phase.'
            );
        }
        if (!$is_scanning) {
            $source_stat = @lstat($this->unsorted_paths_file);
            if (
                !is_array($source_stat)
                || ( $source_stat['mode'] & 0170000 ) !== 0100000
                || $source_stat['size'] !== $this->cursor['paths_byte_offset']
            ) {
                throw new UnexpectedValueException(
                    'File-sync change-scope mapping source does not match its completed scan boundary.'
                );
            }
        }
    }
}
