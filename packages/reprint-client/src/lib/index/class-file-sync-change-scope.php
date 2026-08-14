<?php
declare(strict_types=1);

use function WordPress\Reprint\Exporter\assert_valid_path;
use function WordPress\Reprint\Exporter\normalize_path;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors contain private state paths, never HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Decides which remote-index changes belong to one files-pull selection.
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
 * @phpstan-type Config array{index_path_coordinates:'remote_absolute',ownership_directory_b64:string,current_snapshot_id:string,prior_snapshot_ids:list<string>,protected_snapshot_ids:list<string>,excluded_remote_absolute_path_roots_b64:list<string>,include_caches:bool}
 */
final class FileSyncChangeScope
{
    private const LOOKUP_RECORD_BYTES = 82;
    private const MAX_PATH_ROW_BYTES = 64 * 1024;

    /** @phpstan-var Config */
    private array $config;
    private string $ownership_directory;
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
     */
    public function index_entry_may_change(
        string $index_path,
        string $type
    ): bool {
        return $this->remote_index_entry_change_decision(
            $index_path,
            $type
        ) === 'allow';
    }

    /** @return 'allow'|'block'|'unowned' */
    private function remote_index_entry_change_decision(
        string $remote_absolute_path,
        string $type
    ): string {
        $this->assert_open();
        self::assert_remote_absolute_path($remote_absolute_path);
        if (!in_array($type, ['file', 'link', 'dir'], true)) {
            throw new InvalidArgumentException(
                "Index entry type must be file, link, or dir; got {$type}."
            );
        }
        $current_ownership = $this->snapshots_entry_ownership(
            [$this->config['current_snapshot_id']],
            $remote_absolute_path,
            $type
        );
        if ($current_ownership !== 'unowned') {
            return $this->entry_is_blocked(
                $remote_absolute_path,
                $current_ownership
            )
                ? 'block'
                : 'allow';
        }
        $protected_ownership = $this->snapshots_entry_ownership(
            $this->config['protected_snapshot_ids'],
            $remote_absolute_path,
            $type
        );
        if ($protected_ownership !== 'unowned') {
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
     */
    public function subtree_may_change(
        string $index_path
    ): bool {
        return $this->remote_subtree_change_decision(
            $index_path
        ) === 'allow';
    }

    /** @return 'allow'|'block'|'unowned' */
    private function remote_subtree_change_decision(
        string $remote_absolute_path
    ): string {
        $this->assert_open();
        self::assert_remote_absolute_path($remote_absolute_path);
        $current_relation = $this->snapshots_subtree_relation(
            [$this->config['current_snapshot_id']],
            $remote_absolute_path
        );
        if ($current_relation === 'owns') {
            return $this->subtree_is_blocked($remote_absolute_path)
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
        if ($prior_relation !== 'owns') {
            return 'unowned';
        }
        return $this->subtree_is_blocked($remote_absolute_path)
            ? 'block'
            : 'allow';
    }

    private function subtree_is_blocked(string $remote_absolute_path): bool
    {
        return (
            !$this->config['include_caches']
            || $this->subtree_intersects_exclusion($remote_absolute_path)
        );
    }

    public function includes_caches(): bool
    {
        return $this->config['include_caches'];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->close_open_snapshot();
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
            && !$this->root_traversal_has_default_skip(
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

    private function root_traversal_has_default_skip(
        string $root,
        string $remote_absolute_path
    ): bool {
        // The producer schedules the root without classifying it, then checks
        // each child using that child's full absolute path.
        $relative_path = $root === '/'
            ? substr($remote_absolute_path, 1)
            : substr($remote_absolute_path, strlen($root) + 1);
        $prefix = $root;
        foreach (explode('/', $relative_path) as $component) {
            $prefix = $prefix === '/'
                ? '/' . $component
                : $prefix . '/' . $component;
            if (FileIndexProcessor::path_is_default_skipped($prefix)) {
                return true;
            }
        }
        return false;
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
        $expected_keys = [
            'index_path_coordinates',
            'ownership_directory_b64',
            'current_snapshot_id',
            'prior_snapshot_ids',
            'protected_snapshot_ids',
            'excluded_remote_absolute_path_roots_b64',
            'include_caches',
        ];
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
        if ($config['index_path_coordinates'] !== 'remote_absolute') {
            throw new InvalidArgumentException(
                'File-sync change-scope index_path_coordinates must be remote_absolute.'
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

    private function assert_open(): void
    {
        if ($this->closed) {
            throw new LogicException('Cannot query a file-sync change scope after close().');
        }
    }
}
