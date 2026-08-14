<?php
declare(strict_types=1);

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Reprint\Exporter\assert_valid_relative_path;
use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\relative_path_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors contain private state paths, never HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Decides which indexed changes belong to one files-pull selection.
 *
 * The current snapshot describes this run and is authoritative where it owns
 * a path. Earlier snapshots for the same selection cover paths which have
 * disappeared. Snapshots for other selections protect their paths from those
 * earlier snapshots. Explicit exclusions always override ownership.
 * Root-derived ownership replays the producer's default-skip checks below the
 * deepest owning root. An exact atom confirms that the producer emitted that
 * intermediate link, so default-skip checks do not override it.
 *
 * Snapshot lookups use the fixed-width hash index published by
 * FilesPullOwnershipProcessor. Each atom probe uses binary search, then reads
 * hash-collision candidates one bounded path row at a time. Only one snapshot
 * pair remains open, and snapshot contents are never materialized in memory.
 *
 * @phpstan-type MapperConfig array{filesystem_root_b64:string,original_remote_roots_b64:list<string>,resolved_path_mappings:list<array{remote_prefix_b64:string,local_prefix_b64:string}>,local_followed_symlinks_root_b64:string|null}
 * @phpstan-type RemoteAbsoluteConfig array{index_path_coordinates:'remote_absolute',ownership_directory_b64:string,current_snapshot_id:string,prior_snapshot_ids:list<string>,protected_snapshot_ids:list<string>,excluded_remote_absolute_path_roots_b64:list<string>,include_caches:bool}
 * @phpstan-type LocalRelativeConfig array{index_path_coordinates:'local_relative',ownership_directory_b64:string,current_snapshot_id:string,prior_snapshot_ids:list<string>,protected_snapshot_ids:list<string>,excluded_remote_absolute_path_roots_b64:list<string>,include_caches:bool,remote_to_local_path_mapper_config:MapperConfig,selected_default_skipped_index_paths_file_b64:string}
 * @phpstan-type Config RemoteAbsoluteConfig|LocalRelativeConfig
 * @phpstan-type SelectedAtomCursor array{selection_fingerprint:string,selected_snapshot_index:int,paths_byte_offset:int}
 */
final class FileSyncChangeScope
{
    private const LOOKUP_RECORD_BYTES = 82;
    private const MAX_PATH_ROW_BYTES = 64 * 1024;

    /** @phpstan-var Config */
    private array $config;
    private string $ownership_directory;
    private ?RemoteToLocalPathMapper $remote_to_local_path_mapper = null;
    private ?string $selected_default_skipped_index_paths_file = null;
    /** @var list<string> */
    private array $excluded_remote_absolute_path_roots = [];
    private ?string $open_snapshot_id = null;
    /** @var resource|null */
    private $open_paths_handle = null;
    /** @var resource|null */
    private $open_lookup_handle = null;
    private int $open_lookup_record_count = 0;
    private int $open_paths_bytes = 0;
    private bool $closed = false;

    /**
     * Opens every snapshot named by an opaque identifier in a strict saved config.
     *
     * @phpstan-param Config $config
     */
    public static function from_config(array $config): self
    {
        $scope = new self();
        $scope->set_config($config);

        $snapshot_ids = array_merge(
            [$scope->config['current_snapshot_id']],
            $scope->config['prior_snapshot_ids'],
            $scope->config['protected_snapshot_ids']
        );
        $snapshot_ids = array_values(array_unique($snapshot_ids));
        try {
            foreach ($snapshot_ids as $snapshot_id) {
                $scope->open_snapshot($snapshot_id);
                $scope->close_open_snapshot();
            }
        } catch (Throwable $throwable) {
            $scope->close();
            throw $throwable;
        }
        return $scope;
    }

    /** @phpstan-return Config */
    public function get_config(): array
    {
        return $this->config;
    }

    /**
     * Reports whether this lifecycle may change one index entry.
     *
     * Root atoms own strict descendants. Exact atoms own only a link at the
     * same path. Current ownership wins over another selection's protection;
     * earlier ownership does not.
     * A local-relative entry needs one allowing alias and no blocking alias.
     */
    public function index_entry_may_change(
        string $index_path,
        string $type
    ): bool {
        $this->assert_open();
        self::assert_supported_index_entry_type($type);
        if ($this->config['index_path_coordinates'] === 'remote_absolute') {
            return $this->remote_index_entry_change_decision(
                $index_path,
                $type
            ) === 'allow';
        }

        $change_is_allowed = false;
        foreach (
            $this->remote_paths_mapping_to_local_index_path($index_path)
            as $remote_absolute_path
        ) {
            $decision = $this->remote_index_entry_change_decision(
                $remote_absolute_path,
                $type
            );
            if ($decision === 'block') {
                return false;
            }
            if ($decision === 'allow') {
                $change_is_allowed = true;
            }
        }
        return $change_is_allowed;
    }

    /** @return 'allow'|'block'|'unowned' */
    private function remote_index_entry_change_decision(
        string $remote_absolute_path,
        string $type
    ): string {
        $this->assert_open();
        self::assert_remote_absolute_path($remote_absolute_path);
        if (
            $type !== 'dir'
            && $this->subtree_intersects_exclusion($remote_absolute_path)
        ) {
            return 'block';
        }
        $current_ownership = $this->snapshots_entry_ownership(
            [$this->config['current_snapshot_id']],
            $remote_absolute_path,
            $type
        );
        if ($current_ownership !== 'unowned') {
            if (
                $this->entry_is_blocked(
                    $remote_absolute_path,
                    $current_ownership
                )
            ) {
                return 'block';
            }

            // A current root above the path owns the whole namespace and wins
            // protected overlap. Exact ownership controls only the link entry,
            // so it cannot replace a namespace reserved for a descendant.
            if (
                $type === 'link'
                && $this->snapshots_subtree_relation(
                    [$this->config['current_snapshot_id']],
                    $remote_absolute_path
                ) !== 'owns'
                && (
                    $this->snapshots_reserve_subtree_namespace(
                        [$this->config['current_snapshot_id']],
                        $remote_absolute_path
                    )
                    || $this->snapshots_reserve_subtree_namespace(
                        $this->config['protected_snapshot_ids'],
                        $remote_absolute_path
                    )
                )
            ) {
                return 'block';
            }
            return 'allow';
        }
        if (
            $this->snapshots_subtree_relation(
                [$this->config['current_snapshot_id']],
                $remote_absolute_path
            ) === 'intersects'
        ) {
            return 'block';
        }
        $protected_ownership = $this->snapshots_entry_ownership(
            $this->config['protected_snapshot_ids'],
            $remote_absolute_path,
            $type
        );
        if ($protected_ownership !== 'unowned') {
            return 'block';
        }
        if (
            $this->snapshots_subtree_relation(
                $this->config['protected_snapshot_ids'],
                $remote_absolute_path
            ) !== 'none'
        ) {
            return 'block';
        }
        $prior_ownership = $this->snapshots_entry_ownership(
            $this->config['prior_snapshot_ids'],
            $remote_absolute_path,
            $type
        );
        if ($prior_ownership === 'unowned') {
            return 'unowned';
        }
        return $this->entry_is_blocked(
            $remote_absolute_path,
            $prior_ownership
        )
            ? 'block'
            : 'allow';
    }

    /** @param 'owned'|'default_skipped' $ownership */
    private function entry_is_blocked(
        string $remote_absolute_path,
        string $ownership
    ): bool {
        return (
            $this->path_is_excluded($remote_absolute_path)
            || $ownership === 'default_skipped'
        );
    }

    /**
     * Reports whether this lifecycle may change a path and its whole subtree.
     *
     * Without caches, no remote index can show every default-skipped child, so
     * recursive work is never safe. Current whole-subtree ownership wins. Any
     * other current intersection blocks prior authority, as does any protected
     * intersection.
     * A local-relative subtree also needs continuous remote-to-local mapping.
     */
    public function subtree_may_change(
        string $index_path
    ): bool {
        return $this->subtree_change_is_allowed(
            $index_path,
            null,
            true
        );
    }

    /**
     * Reports whether this lifecycle may remove a verified empty directory.
     *
     * The governing type is the desired type for a replacement and `dir` for
     * a deletion. The caller confirms emptiness separately, so cache omission
     * does not block exact removal. Descendant exclusions and ownership
     * intersections still protect the directory namespace.
     */
    public function directory_entry_may_change(
        string $index_path,
        string $governing_type
    ): bool {
        $this->assert_open();
        self::assert_supported_index_entry_type($governing_type);
        if ($this->config['index_path_coordinates'] === 'remote_absolute') {
            return (
                $this->remote_index_entry_change_decision(
                    $index_path,
                    $governing_type
                ) === 'allow'
                && $this->remote_subtree_change_decision(
                    $index_path,
                    $governing_type,
                    false
                ) === 'allow'
            );
        }

        $path_mapper = $this->get_local_path_mapper();
        $change_is_allowed = false;
        foreach (
            $this->remote_paths_mapping_to_local_index_path($index_path)
            as $remote_absolute_path
        ) {
            $entry_decision = $this->remote_index_entry_change_decision(
                $remote_absolute_path,
                $governing_type
            );
            $subtree_decision = $this->remote_subtree_change_decision(
                $remote_absolute_path,
                $governing_type,
                false
            );
            if (
                $entry_decision === 'block'
                || $subtree_decision === 'block'
            ) {
                return false;
            }
            if (
                $entry_decision === 'allow'
                && $subtree_decision === 'allow'
            ) {
                if (
                    !$path_mapper->remote_path_owns_mapped_local_subtree(
                        $remote_absolute_path
                    )
                ) {
                    return false;
                }
                $change_is_allowed = true;
            }
        }
        return $change_is_allowed;
    }

    private function subtree_change_is_allowed(
        string $index_path,
        ?string $governing_type,
        bool $requires_cache_visibility
    ): bool {
        $this->assert_open();
        if ($this->config['index_path_coordinates'] === 'remote_absolute') {
            return $this->remote_subtree_change_decision(
                $index_path,
                $governing_type,
                $requires_cache_visibility
            ) === 'allow';
        }

        $path_mapper = $this->get_local_path_mapper();
        $change_is_allowed = false;
        foreach (
            $this->remote_paths_mapping_to_local_index_path($index_path)
            as $remote_absolute_path
        ) {
            $decision = $this->remote_subtree_change_decision(
                $remote_absolute_path,
                $governing_type,
                $requires_cache_visibility
            );
            if ($decision === 'block') {
                return false;
            }
            if ($decision === 'allow') {
                if (
                    !$path_mapper->remote_path_owns_mapped_local_subtree(
                        $remote_absolute_path
                    )
                ) {
                    return false;
                }
                $change_is_allowed = true;
            }
        }
        return $change_is_allowed;
    }

    /** @return 'allow'|'block'|'unowned' */
    private function remote_subtree_change_decision(
        string $remote_absolute_path,
        ?string $governing_type,
        bool $requires_cache_visibility
    ): string {
        $this->assert_open();
        self::assert_remote_absolute_path($remote_absolute_path);
        $current_relation = $this->snapshots_subtree_relation(
            [$this->config['current_snapshot_id']],
            $remote_absolute_path
        );
        if ($current_relation === 'owns') {
            return $this->subtree_is_blocked(
                $remote_absolute_path,
                $requires_cache_visibility
            )
                ? 'block'
                : 'allow';
        }

        $current_owns_governing_entry = (
            $governing_type !== null
            && $this->snapshots_entry_ownership(
                [$this->config['current_snapshot_id']],
                $remote_absolute_path,
                $governing_type
            ) === 'owned'
        );
        if ($current_owns_governing_entry) {
            // Exact ownership controls this entry, not descendants. It wins
            // an exact overlap but not a current or protected reservation.
            if (
                $this->snapshots_reserve_subtree_namespace(
                    [$this->config['current_snapshot_id']],
                    $remote_absolute_path
                )
                || $this->snapshots_reserve_subtree_namespace(
                    $this->config['protected_snapshot_ids'],
                    $remote_absolute_path
                )
            ) {
                return 'block';
            }
            return $this->subtree_is_blocked(
                $remote_absolute_path,
                $requires_cache_visibility
            )
                ? 'block'
                : 'allow';
        }
        if ($current_relation === 'intersects') {
            return 'block';
        }

        $protected_relation = $this->snapshots_subtree_relation(
            $this->config['protected_snapshot_ids'],
            $remote_absolute_path
        );
        if ($protected_relation !== 'none') {
            return 'block';
        }
        $prior_relation = $this->snapshots_subtree_relation(
            $this->config['prior_snapshot_ids'],
            $remote_absolute_path
        );
        $prior_owns_governing_entry = (
            $governing_type !== null
            && $this->snapshots_entry_ownership(
                $this->config['prior_snapshot_ids'],
                $remote_absolute_path,
                $governing_type
            ) === 'owned'
        );
        if ($prior_relation !== 'owns' && !$prior_owns_governing_entry) {
            return 'unowned';
        }
        return $this->subtree_is_blocked(
            $remote_absolute_path,
            $requires_cache_visibility
        )
            ? 'block'
            : 'allow';
    }

    private function subtree_is_blocked(
        string $remote_absolute_path,
        bool $requires_cache_visibility
    ): bool {
        return (
            (
                $requires_cache_visibility
                && !$this->config['include_caches']
            )
            || $this->subtree_intersects_exclusion($remote_absolute_path)
        );
    }

    private static function assert_supported_index_entry_type(
        string $type
    ): void {
        if (!in_array($type, ['file', 'link', 'dir'], true)) {
            throw new InvalidArgumentException(
                "Index entry type must be file, link, or dir; got {$type}."
            );
        }
    }

    /**
     * Maps one changeable remote entry into this local-relative index.
     *
     * The remote entry must itself be allowed, and no remote alias for its
     * local path may block the change.
     *
     * @return string|null Local path relative to the filesystem root, or null.
     */
    public function map_changeable_remote_index_entry_to_local_index_path(
        string $remote_absolute_path,
        string $type
    ): ?string {
        $this->get_local_path_mapper();
        if (
            $this->remote_index_entry_change_decision(
                $remote_absolute_path,
                $type
            ) !== 'allow'
        ) {
            return null;
        }
        $local_index_path = $this->map_remote_absolute_path_to_index_path(
            $remote_absolute_path
        );
        if (!$this->index_entry_may_change($local_index_path, $type)) {
            return null;
        }
        return $local_index_path;
    }

    /**
     * Maps one remote absolute path into this local-relative index.
     *
     * @return string Local path relative to the filesystem root.
     */
    public function map_remote_absolute_path_to_index_path(
        string $remote_absolute_path
    ): string {
        $path_mapper = $this->get_local_path_mapper();
        self::assert_remote_absolute_path($remote_absolute_path);
        $local_absolute_path = $path_mapper->map_path($remote_absolute_path);
        $local_relative_path = relative_path_under(
            $local_absolute_path,
            $path_mapper->get_filesystem_root()
        );
        if ($local_relative_path === null) {
            throw new LogicException(
                'Remote-to-local path mapping escaped the filesystem root.'
            );
        }
        return $local_relative_path;
    }

    /** Returns the filesystem root of this local-relative index. */
    public function get_filesystem_root(): string
    {
        return $this->get_local_path_mapper()->get_filesystem_root();
    }

    public function includes_caches(): bool
    {
        return $this->config['include_caches'];
    }

    /**
     * Reports whether root ownership can change any entry in a remote region.
     *
     * A full exclusion blocks the region. Current root ownership wins over
     * another selection, while a protected root blocks prior ownership.
     */
    public function root_owned_region_may_change(
        string $remote_absolute_path
    ): bool {
        $this->assert_open();
        $this->assert_remote_atom_enumeration();
        self::assert_remote_absolute_path($remote_absolute_path);
        if ($this->path_is_excluded($remote_absolute_path)) {
            return false;
        }
        if ($this->snapshots_have_root_at_or_above(
            [$this->config['current_snapshot_id']],
            $remote_absolute_path
        )) {
            return true;
        }
        if ($this->snapshots_have_root_at_or_above(
            $this->config['protected_snapshot_ids'],
            $remote_absolute_path
        )) {
            return false;
        }
        return $this->snapshots_have_root_at_or_above(
            $this->config['prior_snapshot_ids'],
            $remote_absolute_path
        );
    }

    /**
     * Returns the sorted path-only sidecar for default-skipped local paths.
     */
    public function get_selected_default_skipped_index_paths_file(): string
    {
        $this->get_local_path_mapper();
        if ($this->selected_default_skipped_index_paths_file === null) {
            throw new LogicException(
                'Local-relative file-sync change scope has no selected default-skipped paths file.'
            );
        }
        return $this->selected_default_skipped_index_paths_file;
    }

    /** @phpstan-return SelectedAtomCursor */
    public function initial_selected_ownership_atom_cursor(): array
    {
        $this->assert_open();
        $this->assert_remote_atom_enumeration();
        return [
            'selection_fingerprint' => $this->selected_snapshot_fingerprint(),
            'selected_snapshot_index' => 0,
            'paths_byte_offset' => 0,
        ];
    }

    /** @phpstan-return SelectedAtomCursor */
    public function completed_selected_ownership_atom_cursor(): array
    {
        $cursor = $this->initial_selected_ownership_atom_cursor();
        $cursor['selected_snapshot_index'] = count(
            $this->selected_snapshot_ids()
        );
        return $cursor;
    }

    /**
     * Validates one selected-atom cursor and reports whether it reached EOF.
     *
     * @phpstan-param SelectedAtomCursor $cursor
     */
    public function selected_ownership_atom_cursor_is_complete(
        array $cursor
    ): bool {
        $this->assert_open();
        $this->assert_remote_atom_enumeration();
        $this->assert_selected_atom_cursor($cursor);
        return $cursor['selected_snapshot_index']
            === count($this->selected_snapshot_ids());
    }

    /**
     * Reads one current-or-prior ownership atom or advances one snapshot.
     *
     * Protected snapshots decide whether work is blocked, but never supply
     * work candidates. Callers retain the returned cursor unchanged between
     * bounded steps.
     *
     * @phpstan-param SelectedAtomCursor $cursor
     * @return array {
     *     @type array|null $atom     One ownership atom, or null for a snapshot transition.
     *     @type array      $cursor   Cursor for the next bounded read.
     *     @type bool       $complete Whether every selected snapshot is consumed.
     * }
     * @phpstan-return array{atom:array{kind:'root'|'exact'|'ancestor',path:string}|null,cursor:SelectedAtomCursor,complete:bool}
     */
    public function read_next_selected_ownership_atom(array $cursor): array
    {
        $this->assert_open();
        $this->assert_remote_atom_enumeration();
        $this->assert_selected_atom_cursor($cursor);
        $snapshot_ids = $this->selected_snapshot_ids();
        $snapshot_index = $cursor['selected_snapshot_index'];
        if ($snapshot_index === count($snapshot_ids)) {
            return ['atom' => null, 'cursor' => $cursor, 'complete' => true];
        }

        $snapshot_id = $snapshot_ids[$snapshot_index];
        $this->open_snapshot($snapshot_id);
        if ($cursor['paths_byte_offset'] === $this->open_paths_bytes) {
            ++$cursor['selected_snapshot_index'];
            $cursor['paths_byte_offset'] = 0;
            return [
                'atom' => null,
                'cursor' => $cursor,
                'complete' => $cursor['selected_snapshot_index'] === count($snapshot_ids),
            ];
        }
        if ($cursor['paths_byte_offset'] > $this->open_paths_bytes) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} atom cursor exceeds its paths file."
            );
        }

        $atom = $this->read_path_atom(
            $snapshot_id,
            $cursor['paths_byte_offset']
        );
        $next_paths_byte_offset = ftell($this->open_paths_handle);
        if (!is_int($next_paths_byte_offset)) {
            throw new RuntimeException(
                "Ownership snapshot {$snapshot_id} path position cannot be read."
            );
        }
        $cursor['paths_byte_offset'] = $next_paths_byte_offset;
        return ['atom' => $atom, 'cursor' => $cursor, 'complete' => false];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->close_open_snapshot();
    }

    /** @return list<string> */
    private function remote_paths_mapping_to_local_index_path(
        string $local_relative_path
    ): array {
        $path_mapper = $this->get_local_path_mapper();
        if ($local_relative_path === '') {
            $local_absolute_path = $path_mapper->get_filesystem_root();
        } else {
            assert_valid_relative_path(
                $local_relative_path,
                'file-sync change-scope local relative path'
            );
            $local_absolute_path = wp_join_unix_paths(
                $path_mapper->get_filesystem_root(),
                $local_relative_path
            );
        }
        return $path_mapper->remote_paths_mapping_to($local_absolute_path);
    }

    private function get_local_path_mapper(): RemoteToLocalPathMapper
    {
        if ($this->remote_to_local_path_mapper === null) {
            throw new LogicException(
                'The file-sync change scope uses remote_absolute index paths. '
                . 'Map it to local_relative before asking for local filesystem coordinates.'
            );
        }
        return $this->remote_to_local_path_mapper;
    }

    /**
     * @param list<string> $snapshot_ids
     * @return 'owned'|'default_skipped'|'unowned'
     */
    private function snapshots_entry_ownership(
        array $snapshot_ids,
        string $remote_absolute_path,
        string $type
    ): string {
        $has_exact_ownership = (
            $type === 'link'
            && $this->snapshots_contain_atom(
                $snapshot_ids,
                'exact',
                $remote_absolute_path
            )
        );
        $owning_root = $this->deepest_owning_root(
            $snapshot_ids,
            $remote_absolute_path
        );
        if (!$has_exact_ownership && $owning_root === null) {
            return 'unowned';
        }
        if ($has_exact_ownership || $this->config['include_caches']) {
            return 'owned';
        }
        if (
            $owning_root !== null
            && !FileIndexProcessor::path_is_default_skipped_below_root(
                $owning_root,
                $remote_absolute_path
            )
        ) {
            return 'owned';
        }
        return 'default_skipped';
    }

    /**
     * @param list<string> $snapshot_ids
     * @return 'owns'|'intersects'|'none'
     */
    private function snapshots_subtree_relation(
        array $snapshot_ids,
        string $remote_absolute_path
    ): string {
        if ($this->deepest_owning_root(
            $snapshot_ids,
            $remote_absolute_path
        ) !== null) {
            return 'owns';
        }
        $intersects = $this->snapshots_contain_atom(
            $snapshot_ids,
            'root',
            $remote_absolute_path
        ) || $this->snapshots_contain_atom(
            $snapshot_ids,
            'exact',
            $remote_absolute_path
        ) || $this->snapshots_contain_atom(
            $snapshot_ids,
            'ancestor',
            $remote_absolute_path
        );
        return $intersects ? 'intersects' : 'none';
    }

    /**
     * Reports a root or ancestor namespace reservation, excluding exact atoms.
     *
     * @param list<string> $snapshot_ids
     */
    private function snapshots_reserve_subtree_namespace(
        array $snapshot_ids,
        string $remote_absolute_path
    ): bool {
        return (
            $this->deepest_owning_root(
                $snapshot_ids,
                $remote_absolute_path
            ) !== null
            || $this->snapshots_contain_atom(
                $snapshot_ids,
                'root',
                $remote_absolute_path
            )
            || $this->snapshots_contain_atom(
                $snapshot_ids,
                'ancestor',
                $remote_absolute_path
            )
        );
    }

    /** @param list<string> $snapshot_ids */
    private function snapshots_contain_atom(
        array $snapshot_ids,
        string $kind,
        string $remote_absolute_path
    ): bool {
        foreach ($snapshot_ids as $snapshot_id) {
            if ($this->snapshot_contains_atom(
                $snapshot_id,
                $kind,
                $remote_absolute_path
            )) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $snapshot_ids */
    private function snapshots_have_root_at_or_above(
        array $snapshot_ids,
        string $remote_absolute_path
    ): bool {
        return $this->snapshots_contain_atom(
            $snapshot_ids,
            'root',
            $remote_absolute_path
        ) || $this->deepest_owning_root(
            $snapshot_ids,
            $remote_absolute_path
        ) !== null;
    }

    /** @param list<string> $snapshot_ids */
    private function deepest_owning_root(
        array $snapshot_ids,
        string $remote_absolute_path
    ): ?string {
        $ancestor = self::strict_parent($remote_absolute_path);
        while ($ancestor !== null) {
            if ($this->snapshots_contain_atom(
                $snapshot_ids,
                'root',
                $ancestor
            )) {
                return $ancestor;
            }
            $ancestor = self::strict_parent($ancestor);
        }
        return null;
    }

    private function snapshot_contains_atom(
        string $snapshot_id,
        string $kind,
        string $remote_absolute_path
    ): bool {
        $this->open_snapshot($snapshot_id);
        $digest = hash('sha256', $kind . "\0" . $remote_absolute_path);
        $lower = 0;
        $upper = $this->open_lookup_record_count;
        while ($lower < $upper) {
            $middle = $lower + intdiv($upper - $lower, 2);
            $record = $this->read_lookup_record($snapshot_id, $middle);
            if (strcmp($record['digest'], $digest) < 0) {
                $lower = $middle + 1;
            } else {
                $upper = $middle;
            }
        }

        for (
            $record_index = $lower;
            $record_index < $this->open_lookup_record_count;
            ++$record_index
        ) {
            $record = $this->read_lookup_record($snapshot_id, $record_index);
            if ($record['digest'] !== $digest) {
                return false;
            }
            $atom = $this->read_path_atom($snapshot_id, $record['paths_byte_offset']);
            if ($atom['kind'] === $kind && $atom['path'] === $remote_absolute_path) {
                return true;
            }
        }
        return false;
    }

    /** @return array{digest:string,paths_byte_offset:int} */
    private function read_lookup_record(string $snapshot_id, int $record_index): array
    {
        if (
            $this->open_snapshot_id !== $snapshot_id
            || !is_resource($this->open_lookup_handle)
        ) {
            throw new LogicException('Ownership lookup read the wrong open snapshot.');
        }
        $byte_offset = $record_index * self::LOOKUP_RECORD_BYTES;
        if (fseek($this->open_lookup_handle, $byte_offset) !== 0) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} lookup byte {$byte_offset} cannot be read."
            );
        }
        $record = fread(
            $this->open_lookup_handle,
            self::LOOKUP_RECORD_BYTES
        );
        if ($record === false) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} lookup read failed at byte {$byte_offset}."
            );
        }
        if (strlen($record) !== self::LOOKUP_RECORD_BYTES) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} lookup record at byte {$byte_offset} "
                . 'has ' . strlen($record) . ' bytes; expected 82.'
            );
        }
        if (preg_match(
            '/^([0-9a-f]{64}) ([0-9a-f]{16})\n$/D',
            $record,
            $matches
        ) !== 1) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} lookup record at byte {$byte_offset} "
                . 'must contain a lowercase SHA-256 digest, one 16-digit offset, and a newline.'
            );
        }
        $maximum_offset_hex = sprintf('%016x', PHP_INT_MAX);
        if (strcmp($matches[2], $maximum_offset_hex) > 0) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} has a path offset larger than PHP_INT_MAX."
            );
        }
        $paths_byte_offset = hexdec($matches[2]);
        if (
            !is_int($paths_byte_offset)
            || $paths_byte_offset >= $this->open_paths_bytes
        ) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} path offset {$matches[2]} "
                . "is outside its {$this->open_paths_bytes}-byte paths file."
            );
        }
        return [
            'digest' => $matches[1],
            'paths_byte_offset' => $paths_byte_offset,
        ];
    }

    /** @return array{kind:'root'|'exact'|'ancestor',path:string} */
    private function read_path_atom(string $snapshot_id, int $paths_byte_offset): array
    {
        if (
            $this->open_snapshot_id !== $snapshot_id
            || !is_resource($this->open_paths_handle)
        ) {
            throw new LogicException('Ownership path read the wrong open snapshot.');
        }
        if (fseek($this->open_paths_handle, $paths_byte_offset) !== 0) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} path offset cannot be read."
            );
        }
        $line = fgets($this->open_paths_handle, self::MAX_PATH_ROW_BYTES + 1);
        if (!is_string($line)) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} path row at byte {$paths_byte_offset} cannot be read."
            );
        }
        if (substr($line, -1) !== "\n") {
            $condition = feof($this->open_paths_handle)
                ? 'has no final newline'
                : 'exceeds the 65536-byte path-row limit';
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} path row at byte {$paths_byte_offset} {$condition}."
            );
        }
        $atom = json_decode(substr($line, 0, -1), true);
        if (!is_array($atom)) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} path row at byte {$paths_byte_offset} must be JSON."
            );
        }
        if (array_keys($atom) !== ['kind', 'path_b64']) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} path row at byte {$paths_byte_offset} "
                . 'must contain only kind and path_b64, in that order.'
            );
        }
        if (!in_array($atom['kind'], ['root', 'exact', 'ancestor'], true)) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} has an invalid atom kind."
            );
        }
        $path = is_string($atom['path_b64'])
            ? base64_decode($atom['path_b64'], true)
            : false;
        if (!is_string($path) || base64_encode($path) !== $atom['path_b64']) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} has an invalid atom path."
            );
        }
        try {
            self::assert_remote_absolute_path($path);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} atom path must be normalized and absolute.",
                0,
                $exception
            );
        }
        return ['kind' => $atom['kind'], 'path' => $path];
    }

    private function open_snapshot(string $snapshot_id): void
    {
        if ($this->open_snapshot_id === $snapshot_id) {
            return;
        }
        $this->close_open_snapshot();
        $snapshot_path_prefix = $this->ownership_directory
            . '/snapshots/' . $snapshot_id;
        $paths_path = $snapshot_path_prefix . '.paths.jsonl';
        $lookup_path = $snapshot_path_prefix . '.lookup';
        $paths_handle = @fopen($paths_path, 'rb');
        if (!is_resource($paths_handle)) {
            throw new RuntimeException(
                "Ownership snapshot {$snapshot_id} paths file is not readable: {$paths_path}."
            );
        }
        $lookup_handle = @fopen($lookup_path, 'rb');
        if (!is_resource($lookup_handle)) {
            fclose($paths_handle);
            throw new RuntimeException(
                "Ownership snapshot {$snapshot_id} lookup file is not readable: {$lookup_path}."
            );
        }
        $paths_stat = fstat($paths_handle);
        $lookup_stat = fstat($lookup_handle);
        if ($paths_stat === false || $lookup_stat === false) {
            fclose($paths_handle);
            fclose($lookup_handle);
            throw new RuntimeException(
                "Ownership snapshot {$snapshot_id} file size cannot be read."
            );
        }
        if ($lookup_stat['size'] % self::LOOKUP_RECORD_BYTES !== 0) {
            fclose($paths_handle);
            fclose($lookup_handle);
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} lookup has {$lookup_stat['size']} bytes; "
                . 'its size must be a multiple of 82.'
            );
        }
        $paths_are_empty = $paths_stat['size'] === 0;
        $lookup_is_empty = $lookup_stat['size'] === 0;
        if ($paths_are_empty !== $lookup_is_empty) {
            fclose($paths_handle);
            fclose($lookup_handle);
            throw new UnexpectedValueException(
                "Ownership snapshot {$snapshot_id} has {$paths_stat['size']} path bytes "
                . "and {$lookup_stat['size']} lookup bytes; both files must be empty together."
            );
        }
        $this->open_snapshot_id = $snapshot_id;
        $this->open_paths_handle = $paths_handle;
        $this->open_lookup_handle = $lookup_handle;
        $this->open_lookup_record_count = intdiv(
            $lookup_stat['size'],
            self::LOOKUP_RECORD_BYTES
        );
        $this->open_paths_bytes = $paths_stat['size'];
    }

    private function close_open_snapshot(): void
    {
        if (is_resource($this->open_paths_handle)) {
            fclose($this->open_paths_handle);
        }
        if (is_resource($this->open_lookup_handle)) {
            fclose($this->open_lookup_handle);
        }
        $this->open_snapshot_id = null;
        $this->open_paths_handle = null;
        $this->open_lookup_handle = null;
        $this->open_lookup_record_count = 0;
        $this->open_paths_bytes = 0;
    }

    private function path_is_excluded(string $remote_absolute_path): bool
    {
        return path_is_same_as_or_descendant_of(
            $remote_absolute_path,
            $this->excluded_remote_absolute_path_roots
        );
    }

    private function subtree_intersects_exclusion(
        string $remote_absolute_path
    ): bool {
        return path_is_same_as_or_descendant_of(
            $remote_absolute_path,
            $this->excluded_remote_absolute_path_roots
        ) || path_is_same_as_or_descendant_of(
            $this->excluded_remote_absolute_path_roots,
            $remote_absolute_path
        );
    }

    private function set_config(array $config): void
    {
        $index_path_coordinates = $config['index_path_coordinates'] ?? null;
        if (
            $index_path_coordinates !== 'remote_absolute'
            && $index_path_coordinates !== 'local_relative'
        ) {
            throw new InvalidArgumentException(
                'File-sync change-scope index_path_coordinates must be '
                . 'remote_absolute or local_relative.'
            );
        }
        $expected_keys = [
            'index_path_coordinates',
            'ownership_directory_b64',
            'current_snapshot_id',
            'prior_snapshot_ids',
            'protected_snapshot_ids',
            'excluded_remote_absolute_path_roots_b64',
            'include_caches',
        ];
        if ($index_path_coordinates === 'local_relative') {
            $expected_keys[] = 'remote_to_local_path_mapper_config';
            $expected_keys[] = 'selected_default_skipped_index_paths_file_b64';
        }
        $actual_keys = array_keys($config);
        sort($actual_keys, SORT_STRING);
        $sorted_expected_keys = $expected_keys;
        sort($sorted_expected_keys, SORT_STRING);
        if ($actual_keys !== $sorted_expected_keys) {
            throw new InvalidArgumentException(
                'File-sync change-scope config fields must be exactly '
                . implode(', ', $expected_keys)
                . '; received '
                . json_encode(array_keys($config), JSON_UNESCAPED_SLASHES)
                . '.'
            );
        }
        $this->ownership_directory = self::decode_config_path(
            $config['ownership_directory_b64'],
            'ownership directory'
        );
        self::assert_snapshot_id($config['current_snapshot_id'], 'current snapshot ID');
        self::assert_snapshot_id_list(
            $config['prior_snapshot_ids'],
            'prior snapshot IDs'
        );
        self::assert_snapshot_id_list(
            $config['protected_snapshot_ids'],
            'protected snapshot IDs'
        );
        $this->excluded_remote_absolute_path_roots =
            self::decode_remote_absolute_path_list(
                $config['excluded_remote_absolute_path_roots_b64']
            );
        if (!is_bool($config['include_caches'])) {
            throw new InvalidArgumentException(
                'File-sync change-scope include_caches must be a boolean.'
            );
        }
        if ($index_path_coordinates === 'local_relative') {
            $mapper_config = $config['remote_to_local_path_mapper_config'];
            $expected_mapper_keys = [
                'filesystem_root_b64',
                'original_remote_roots_b64',
                'resolved_path_mappings',
                'local_followed_symlinks_root_b64',
            ];
            if (!is_array($mapper_config)) {
                throw new InvalidArgumentException(
                    'Local-relative file-sync change scope requires a path-mapper config.'
                );
            }
            $actual_mapper_keys = array_keys($mapper_config);
            sort($actual_mapper_keys, SORT_STRING);
            $sorted_expected_mapper_keys = $expected_mapper_keys;
            sort($sorted_expected_mapper_keys, SORT_STRING);
            if ($actual_mapper_keys !== $sorted_expected_mapper_keys) {
                throw new InvalidArgumentException(
                    'Remote-to-local path-mapper config fields must be exactly '
                    . implode(', ', $expected_mapper_keys)
                    . '.'
                );
            }
            try {
                $path_mapper = RemoteToLocalPathMapper::from_config(
                    $mapper_config
                );
            } catch (Throwable $throwable) {
                throw new InvalidArgumentException(
                    'Local-relative file-sync change scope has an invalid path-mapper config.',
                    0,
                    $throwable
                );
            }
            $canonical_mapper_config = $path_mapper->get_config();
            foreach ($expected_mapper_keys as $mapper_key) {
                if ($canonical_mapper_config[$mapper_key] !== $mapper_config[$mapper_key]) {
                    throw new InvalidArgumentException(
                        'Remote-to-local path-mapper config paths must use canonical base64.'
                    );
                }
            }
            $this->remote_to_local_path_mapper = $path_mapper;
            $this->selected_default_skipped_index_paths_file =
                self::decode_config_path(
                    $config['selected_default_skipped_index_paths_file_b64'],
                    'selected default-skipped index paths file'
                );
        }
        /** @var Config $config */
        $this->config = $config;
    }

    private static function assert_snapshot_id_list($snapshot_ids, string $name): void
    {
        if (!is_array($snapshot_ids) || array_values($snapshot_ids) !== $snapshot_ids) {
            throw new InvalidArgumentException(
                "File-sync change-scope {$name} must be a list."
            );
        }
        foreach ($snapshot_ids as $snapshot_id) {
            self::assert_snapshot_id($snapshot_id, $name);
        }
        $sorted_snapshot_ids = $snapshot_ids;
        sort($sorted_snapshot_ids, SORT_STRING);
        if (
            $snapshot_ids !== $sorted_snapshot_ids
            || count($snapshot_ids) !== count(array_unique($snapshot_ids))
        ) {
            throw new InvalidArgumentException(
                "File-sync change-scope {$name} must be byte-sorted with no duplicates."
            );
        }
    }

    private static function assert_snapshot_id($snapshot_id, string $name): void
    {
        if (
            !is_string($snapshot_id)
            || preg_match('/^[0-9a-f]{64}$/D', $snapshot_id) !== 1
        ) {
            throw new InvalidArgumentException(
                "File-sync change-scope {$name} must contain 64 lowercase hexadecimal characters."
            );
        }
    }

    /** @return list<string> */
    private static function decode_remote_absolute_path_list($encoded_paths): array
    {
        if (!is_array($encoded_paths) || array_values($encoded_paths) !== $encoded_paths) {
            throw new InvalidArgumentException(
                'File-sync change-scope exclusions must be a list.'
            );
        }
        $decoded_paths = [];
        foreach ($encoded_paths as $encoded_path) {
            $decoded_paths[] = self::decode_remote_absolute_path(
                $encoded_path,
                'excluded remote absolute path root'
            );
        }
        $sorted_paths = $decoded_paths;
        sort($sorted_paths, SORT_STRING);
        if (
            $decoded_paths !== $sorted_paths
            || count($decoded_paths) !== count(array_unique($decoded_paths))
        ) {
            throw new InvalidArgumentException(
                'File-sync change-scope exclusions must be byte-sorted with no duplicates.'
            );
        }
        return $decoded_paths;
    }

    private static function decode_config_path($encoded_path, string $name): string
    {
        $path = is_string($encoded_path)
            ? base64_decode($encoded_path, true)
            : false;
        if (
            !is_string($path)
            || base64_encode($path) !== $encoded_path
            || $path === ''
            || strpos($path, "\0") !== false
        ) {
            throw new InvalidArgumentException(
                "File-sync change-scope {$name} must be canonical base64 for a nonempty path."
            );
        }
        return $path;
    }

    private static function decode_remote_absolute_path(
        $encoded_path,
        string $name
    ): string {
        $path = self::decode_config_path($encoded_path, $name);
        self::assert_remote_absolute_path($path);
        return $path;
    }

    private static function assert_remote_absolute_path(string $path): void
    {
        assert_valid_path($path, 'file-sync change-scope remote absolute path');
        if (
            $path === ''
            || $path[0] !== '/'
            || normalize_path($path) !== $path
        ) {
            throw new InvalidArgumentException(
                'File-sync change-scope paths must be normalized remote absolute paths.'
            );
        }
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

    /** @return list<string> */
    private function selected_snapshot_ids(): array
    {
        return array_values(array_unique(array_merge(
            [$this->config['current_snapshot_id']],
            $this->config['prior_snapshot_ids']
        )));
    }

    private function selected_snapshot_fingerprint(): string
    {
        $framed_selection = pack(
            'N',
            strlen($this->ownership_directory)
        ) . $this->ownership_directory;
        foreach ($this->selected_snapshot_ids() as $snapshot_id) {
            $framed_selection .= pack('N', strlen($snapshot_id)) . $snapshot_id;
        }
        return hash('sha256', $framed_selection);
    }

    private function assert_remote_atom_enumeration(): void
    {
        if ($this->config['index_path_coordinates'] !== 'remote_absolute') {
            throw new LogicException(
                'Selected ownership atoms may be read only from a remote_absolute file-sync change scope.'
            );
        }
    }

    /** @phpstan-param SelectedAtomCursor $cursor */
    private function assert_selected_atom_cursor(array $cursor): void
    {
        $actual_keys = array_keys($cursor);
        sort($actual_keys, SORT_STRING);
        $expected_keys = [
            'selection_fingerprint',
            'selected_snapshot_index',
            'paths_byte_offset',
        ];
        sort($expected_keys, SORT_STRING);
        if ($actual_keys !== $expected_keys) {
            throw new InvalidArgumentException(
                'Selected ownership-atom cursor fields are invalid.'
            );
        }
        if (
            !is_string($cursor['selection_fingerprint'])
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $cursor['selection_fingerprint']
            ) !== 1
            || !hash_equals(
                $this->selected_snapshot_fingerprint(),
                $cursor['selection_fingerprint']
            )
        ) {
            throw new InvalidArgumentException(
                'Selected ownership-atom cursor does not match the change scope.'
            );
        }
        if (
            !is_int($cursor['selected_snapshot_index'])
            || $cursor['selected_snapshot_index'] < 0
            || $cursor['selected_snapshot_index'] > count($this->selected_snapshot_ids())
            || !is_int($cursor['paths_byte_offset'])
            || $cursor['paths_byte_offset'] < 0
            || (
                $cursor['selected_snapshot_index'] === count($this->selected_snapshot_ids())
                && $cursor['paths_byte_offset'] !== 0
            )
        ) {
            throw new InvalidArgumentException(
                'Selected ownership-atom cursor positions are invalid.'
            );
        }
    }

    private function assert_open(): void
    {
        if ($this->closed) {
            throw new LogicException('Cannot query a file-sync change scope after close().');
        }
    }
}
