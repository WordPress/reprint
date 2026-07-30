<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mapping failures are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Maps local-index paths back to the target document-root coordinate system.
 *
 * A pull relationship stores absolute remote and local prefixes. Push plans
 * use local document-root-relative paths for filesystem work and target
 * document-root-relative paths for protocol work. An absent mapping means the
 * two relative coordinate systems are identical.
 *
 * @phpstan-type StoredPrefixRule array{kind:'default'|'remap',remote_prefix_b64:string,local_prefix_b64:string}
 * @phpstan-type StoredPathMapping array{target_url_fingerprint:string,filesystem_root_b64:string,local_tree_b64:string,target_document_root_b64:string,prefix_rules:list<StoredPrefixRule>}
 * @phpstan-type DecodedPrefixRule array{remote_prefix:string,local_prefix:string}
 */
final class PushPathMapping
{
    /** @var string Canonical local document root. */
    private string $local_tree;

    /** @var string|null Absolute target document root, or null for identity. */
    private ?string $target_document_root;

    /** @var list<DecodedPrefixRule> Rules sorted by descending local-prefix length. */
    private array $prefix_rules;

    /**
     * Uses identical local and target relative paths.
     */
    public static function identity(string $local_tree): self
    {
        return new self($local_tree, null, []);
    }

    /**
     * Loads and validates the immutable mapping used by one push relationship.
     */
    public static function from_file(
        string $mapping_file,
        string $local_tree
    ): self {
        $mapping = self::read_file($mapping_file);
        $stored_local_tree = base64_decode(
            $mapping['local_tree_b64'],
            true
        );
        $target_document_root = base64_decode(
            $mapping['target_document_root_b64'],
            true
        );
        /** @var string $stored_local_tree */
        /** @var string $target_document_root */
        $canonical_local_tree = realpath($local_tree);
        if (
            $canonical_local_tree === false
            || self::normalize_path($stored_local_tree)
                !== self::normalize_path($canonical_local_tree)
        ) {
            throw new RuntimeException(
                'path-mapping.json belongs to a different local tree.'
            );
        }
        $decoded_prefix_rules = [];
        foreach ($mapping['prefix_rules'] as $prefix_rule) {
            $remote_prefix = base64_decode(
                $prefix_rule['remote_prefix_b64'],
                true
            );
            $local_prefix = base64_decode(
                $prefix_rule['local_prefix_b64'],
                true
            );
            /** @var string $remote_prefix */
            /** @var string $local_prefix */
            $decoded_prefix_rules[] = [
                'remote_prefix' => $remote_prefix,
                'local_prefix' => $local_prefix,
            ];
        }
        return new self(
            $local_tree,
            $target_document_root,
            $decoded_prefix_rules
        );
    }

    /**
     * Reads the persisted representation used for relationship discovery.
     *
     * @return array {
     *     @type string $target_url_fingerprint    Target URL fingerprint.
     *     @type string $filesystem_root_b64       Pull filesystem root.
     *     @type string $local_tree_b64            Canonical local tree.
     *     @type string $target_document_root_b64  Target document root.
     *     @type array  $prefix_rules              Resolved prefix rules.
     * }
     * @phpstan-return StoredPathMapping
     */
    public static function read_file(string $mapping_file): array
    {
        $json = file_get_contents($mapping_file);
        if (!is_string($json)) {
            throw new RuntimeException(
                'Failed to read the path mapping: ' . $mapping_file . '.'
            );
        }
        $mapping = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($mapping)) {
            throw new RuntimeException(
                'The path mapping is not a JSON object: '
                . $mapping_file
                . '.'
            );
        }
        foreach ([
            'target_url_fingerprint',
            'filesystem_root_b64',
            'local_tree_b64',
            'target_document_root_b64',
        ] as $key) {
            if (!isset($mapping[$key]) || !is_string($mapping[$key])) {
                throw new RuntimeException(
                    'The path mapping '
                    . $mapping_file
                    . ' has no string '
                    . $key
                    . '.'
                );
            }
        }
        foreach ([
            'filesystem_root_b64',
            'local_tree_b64',
            'target_document_root_b64',
        ] as $key) {
            if (base64_decode($mapping[$key], true) === false) {
                throw new RuntimeException(
                    'The path mapping '
                    . $mapping_file
                    . ' has invalid base64 in '
                    . $key
                    . '.'
                );
            }
        }
        if (!isset($mapping['prefix_rules']) || !is_array($mapping['prefix_rules'])) {
            throw new RuntimeException(
                'The path mapping '
                . $mapping_file
                . ' has no prefix_rules array.'
            );
        }
        foreach ($mapping['prefix_rules'] as $position => $prefix_rule) {
            if (
                !is_array($prefix_rule)
                || !in_array(
                    $prefix_rule['kind'] ?? null,
                    ['default', 'remap'],
                    true
                )
                || !isset(
                    $prefix_rule['remote_prefix_b64'],
                    $prefix_rule['local_prefix_b64']
                )
                || !is_string($prefix_rule['remote_prefix_b64'])
                || !is_string($prefix_rule['local_prefix_b64'])
                || base64_decode(
                    $prefix_rule['remote_prefix_b64'],
                    true
                ) === false
                || base64_decode(
                    $prefix_rule['local_prefix_b64'],
                    true
                ) === false
            ) {
                throw new RuntimeException(
                    'The path mapping '
                    . $mapping_file
                    . ' has an invalid prefix rule at position '
                    . $position
                    . '.'
                );
            }
        }
        /** @var StoredPathMapping $mapping */
        return $mapping;
    }

    /**
     * Returns the target document-root-relative coordinate for a local path.
     */
    public function local_path_to_target_path(string $local_path): string
    {
        self::assert_relative_path($local_path, 'local-index path');
        if ($this->target_document_root === null) {
            return $local_path;
        }
        $local_absolute_path =
            $this->local_tree . '/' . $local_path;
        foreach ($this->prefix_rules as $prefix_rule) {
            $remainder = self::path_remainder_under(
                $local_absolute_path,
                $prefix_rule['local_prefix']
            );
            if ($remainder === null) {
                continue;
            }
            $target_absolute_path = self::join_paths(
                $prefix_rule['remote_prefix'],
                $remainder
            );
            $target_path = self::path_remainder_under(
                $target_absolute_path,
                $this->target_document_root
            );
            if ($target_path === null || $target_path === '') {
                throw new RuntimeException(
                    'The local-index path '
                    . base64_encode($local_path)
                    . ' does not map to a path below the target document root.'
                );
            }
            return $target_path;
        }
        throw new RuntimeException(
            'The local-index path '
            . base64_encode($local_path)
            . ' is not covered by path-mapping.json.'
        );
    }

    /**
     * Rewrites a local symlink target into the target path coordinates.
     *
     * Absolute targets and relative targets from an unchanged link coordinate
     * keep their spelling when they resolve outside the local tree. A relative
     * target from a remapped link cannot be reconstructed without a mapped
     * local target, so it is rejected instead of changing its destination.
     */
    public function local_symlink_target_to_target(
        string $local_path,
        string $symlink_target
    ): string {
        if ($this->target_document_root === null) {
            return $symlink_target;
        }
        $local_symlink_absolute_path =
            $this->local_tree . '/' . $local_path;
        $local_target_absolute_path = $symlink_target !== ''
            && $symlink_target[0] === '/'
                ? self::normalize_path($symlink_target)
                : self::normalize_path(
                    dirname($local_symlink_absolute_path)
                    . '/'
                    . $symlink_target
                );
        $local_target_path = self::path_remainder_under(
            $local_target_absolute_path,
            $this->local_tree
        );
        $target_link_path =
            $this->local_path_to_target_path($local_path);
        if ($local_target_path === null) {
            if (
                $symlink_target !== ''
                && $symlink_target[0] !== '/'
                && $target_link_path !== $local_path
            ) {
                throw new RuntimeException(
                    'The remapped local symlink '
                    . base64_encode($local_path)
                    . ' has a relative target outside the local tree: '
                    . base64_encode($symlink_target)
                    . '.'
                );
            }
            return $symlink_target;
        }
        $target_path = $local_target_path === ''
            ? ''
            : $this->local_path_to_target_path($local_target_path);
        return self::relative_path(
            dirname('/' . $target_link_path),
            '/' . $target_path
        );
    }

    /**
     * Validates and stores one decoded mapping.
     *
     * @param list<DecodedPrefixRule> $prefix_rules
     */
    private function __construct(
        string $local_tree,
        ?string $target_document_root,
        array $prefix_rules
    ) {
        $canonical_local_tree = realpath($local_tree);
        if (
            $canonical_local_tree === false
            || !is_dir($canonical_local_tree)
            || is_link($local_tree)
        ) {
            throw new InvalidArgumentException(
                'Push path mapping requires a real local tree directory.'
            );
        }
        $this->local_tree =
            rtrim(self::normalize_path($canonical_local_tree), '/') ?: '/';
        if ($target_document_root === null) {
            $this->target_document_root = null;
            $this->prefix_rules = [];
            return;
        }
        $target_document_root =
            rtrim(self::normalize_path($target_document_root), '/') ?: '/';
        self::assert_absolute_normalized_path(
            $target_document_root,
            'target document root'
        );
        $decoded_rules = [];
        $covers_local_tree = false;
        $remote_prefix_by_local_prefix = [];
        foreach ($prefix_rules as $prefix_rule) {
            $remote_prefix =
                rtrim(
                    self::normalize_path($prefix_rule['remote_prefix']),
                    '/'
                ) ?: '/';
            $local_prefix =
                rtrim(
                    self::normalize_path($prefix_rule['local_prefix']),
                    '/'
                ) ?: '/';
            self::assert_absolute_normalized_path(
                $remote_prefix,
                'remote prefix'
            );
            self::assert_absolute_normalized_path(
                $local_prefix,
                'local prefix'
            );
            if (
                self::path_remainder_under(
                    $remote_prefix,
                    $target_document_root
                ) === null
            ) {
                throw new RuntimeException(
                    'The remote prefix '
                    . base64_encode($remote_prefix)
                    . ' is outside the target document root '
                    . base64_encode($target_document_root)
                    . '.'
                );
            }
            if (
                self::path_remainder_under(
                    $local_prefix,
                    $this->local_tree
                ) === null
            ) {
                throw new RuntimeException(
                    'The local prefix '
                    . base64_encode($local_prefix)
                    . ' is outside the local tree '
                    . base64_encode($this->local_tree)
                    . '.'
                );
            }
            self::assert_no_symlink_component(
                $local_prefix,
                $this->local_tree
            );
            if (
                isset($remote_prefix_by_local_prefix[$local_prefix])
                && $remote_prefix_by_local_prefix[$local_prefix]
                    !== $remote_prefix
            ) {
                throw new RuntimeException(
                    'The local prefix '
                    . base64_encode($local_prefix)
                    . ' maps to more than one remote prefix.'
                );
            }
            $remote_prefix_by_local_prefix[$local_prefix] = $remote_prefix;
            if ($local_prefix === $this->local_tree) {
                $covers_local_tree = true;
            }
            $decoded_rules[] = [
                'remote_prefix' => $remote_prefix,
                'local_prefix' => $local_prefix,
            ];
        }
        usort(
            $decoded_rules,
            static function (array $left, array $right): int {
                return strlen($right['local_prefix'])
                    <=> strlen($left['local_prefix']);
            }
        );
        if ($decoded_rules === []) {
            throw new RuntimeException(
                'path-mapping.json contains no prefix rules.'
            );
        }
        if (!$covers_local_tree) {
            throw new RuntimeException(
                'path-mapping.json does not cover the local tree.'
            );
        }
        $this->target_document_root = $target_document_root;
        $this->prefix_rules = $decoded_rules;
    }

    /** Validates one document-root-relative path. */
    private static function assert_relative_path(
        string $path,
        string $description
    ): void {
        if (
            $path === ''
            || $path[0] === '/'
            || strpos($path, "\0") !== false
            || self::normalize_path('/' . $path) !== '/' . $path
        ) {
            throw new RuntimeException(
                'The '
                . $description
                . ' is not a normalized relative path: '
                . base64_encode($path)
                . '.'
            );
        }
    }

    /** Validates one absolute normalized prefix. */
    private static function assert_absolute_normalized_path(
        string $path,
        string $description
    ): void {
        if (
            $path === ''
            || $path[0] !== '/'
            || strpos($path, "\0") !== false
            || self::normalize_path($path) !== $path
        ) {
            throw new RuntimeException(
                'The '
                . $description
                . ' is not a normalized absolute path: '
                . base64_encode($path)
                . '.'
            );
        }
    }

    /** Rejects a mapping prefix which traverses an existing symlink. */
    private static function assert_no_symlink_component(
        string $path,
        string $root
    ): void {
        $remainder = self::path_remainder_under($path, $root);
        if ($remainder === null || $remainder === '') {
            return;
        }
        $current_path = $root;
        foreach (explode('/', $remainder) as $component) {
            $current_path .= '/' . $component;
            if (is_link($current_path)) {
                throw new RuntimeException(
                    'The local mapping prefix traverses the symlink '
                    . base64_encode($current_path)
                    . '.'
                );
            }
            if (!file_exists($current_path)) {
                return;
            }
        }
    }

    /** Returns a normalized path without filesystem access. */
    private static function normalize_path(string $path): string
    {
        $absolute = $path !== '' && $path[0] === '/';
        $components = [];
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                array_pop($components);
                continue;
            }
            $components[] = $component;
        }
        return ( $absolute ? '/' : '' ) . implode('/', $components);
    }

    /** Returns the remainder below a prefix, including an empty remainder. */
    private static function path_remainder_under(
        string $path,
        string $prefix
    ): ?string {
        $path = rtrim($path, '/') ?: '/';
        $prefix = rtrim($prefix, '/') ?: '/';
        if ($path === $prefix) {
            return '';
        }
        $prefix_with_separator =
            $prefix === '/' ? '/' : $prefix . '/';
        if (strpos($path, $prefix_with_separator) !== 0) {
            return null;
        }
        return substr($path, strlen($prefix_with_separator));
    }

    /** Joins an absolute prefix and a relative remainder. */
    private static function join_paths(
        string $prefix,
        string $remainder
    ): string {
        return rtrim($prefix, '/')
            . ( $remainder === '' ? '' : '/' . ltrim($remainder, '/') );
    }

    /** Computes one relative path between two absolute logical paths. */
    private static function relative_path(
        string $from_directory,
        string $to_path
    ): string {
        $from_components =
            explode('/', trim(self::normalize_path($from_directory), '/'));
        $to_components =
            explode('/', trim(self::normalize_path($to_path), '/'));
        if ($from_components === ['']) {
            $from_components = [];
        }
        if ($to_components === ['']) {
            $to_components = [];
        }
        while (
            $from_components !== []
            && $to_components !== []
            && $from_components[0] === $to_components[0]
        ) {
            array_shift($from_components);
            array_shift($to_components);
        }
        $relative_components = array_merge(
            array_fill(0, count($from_components), '..'),
            $to_components
        );
        return $relative_components === []
            ? '.'
            : implode('/', $relative_components);
    }
}
