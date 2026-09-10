<?php

use WordPress\DataLiberation\URL\WPURL;

/**
 * Prepares URL mappings used for cautious byte replacement.
 *
 * Preparing a mapping parses and validates every source and target, builds the
 * pattern for each supported pair, and sorts longer source bases first. A
 * database value may contain many text leaves, but all leaves use the same URL
 * mapping. Create this object once and share it with each text processor.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class CautiousURLBaseRewriteMapping {
    /**
     * @var array<int, array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_scheme: string,
     *     target_path: string,
     *     target_port: int|null,
     *     pattern: string
     * }>
     */
    private array $entries = [];

    /**
     * Host => decoded, ASCII-lowercase child-site path set and its longest key in bytes.
     * For example, /shop/news is a hash key, with longest_path_bytes=10.
     * The cached length stops a deep URL from hashing ever-longer prefixes.
     * These are hash keys, not rewrite rules: a million sites must not mean a million regexes
     * or a million comparisons for each URL. HTTP and HTTPS share each set.
     *
     * @var array<string, array{paths: array<string, true>, longest_path_bytes: int}>
     */
    private array $excluded_paths = [];

    /**
     * Prepares source URL base => target URL pairs.
     *
     * Invalid pairs are skipped as a whole. They cannot produce a partial
     * domain replacement.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     * @param array<string, string[]> $excluded_paths Source HTTP(S) origin =>
     *     child-site paths, such as ['https://network.test' => ['/shop/news/']].
     *     The importer checks this list at the preflight response boundary.
     */
    public function __construct(array $url_mapping, array $excluded_paths = [])
    {
        foreach ($excluded_paths as $origin => $paths) {
            $path_set = [];
            $longest_path_bytes = 0;
            foreach ($paths as $path) {
                // WordPress's normal site-directory collation treats /news/
                // and /NEWS/ alike. Fold lookup keys, never the emitted URL.
                $path = strtolower(rtrim(rawurldecode($path), '/'));
                $path_set[$path] = true;
                $longest_path_bytes = max($longest_path_bytes, strlen($path));
            }
            // Use the same URL parser as HTML rewriting. Also retain the
            // literal authority for the cautious scanner (e.g. an explicit
            // :443). Assignments share the set through PHP copy-on-write.
            $hosts = [];
            foreach (['http:', 'https:'] as $protocol) {
                $url = $protocol . substr($origin, strpos($origin, ':') + 1);
                $parsed = WPURL::parse($url);
                $parts = parse_url($url);
                $authority = strtolower($parts['host']) . ( isset($parts['port']) ? ':' . $parts['port'] : '' );
                $hosts[] = $parsed->host;
                $hosts[] = $authority;
            }
            foreach (array_unique($hosts) as $host) {
                if ($path_set !== []) {
                    $this->excluded_paths[$host] = [
                        'paths' => isset($this->excluded_paths[$host])
                            ? $this->excluded_paths[$host]['paths'] + $path_set : $path_set,
                        'longest_path_bytes' => max($longest_path_bytes, $this->excluded_paths[$host]['longest_path_bytes'] ?? 0),
                    ];
                }
            }
        }
        foreach ($url_mapping as $source_url => $target_url) {
            $entry = $this->create_entry($source_url, $target_url);
            if ($entry !== null) {
                // Child paths may be stored under network.test while this rule
                // matches network.test:443. Give that literal spelling the same
                // lookup set. PHP shares its array until a write; no path list
                // is copied or scanned here. Parse once per rule, not per URL.
                if ($this->excluded_paths !== []) {
                    $parsed = WPURL::parse($source_url);
                    if ($parsed && !isset($this->excluded_paths[$entry['source_authority']])
                        && isset($this->excluded_paths[$parsed->host])) {
                        $this->excluded_paths[$entry['source_authority']] = $this->excluded_paths[$parsed->host];
                    }
                }
                $this->entries[] = $entry;
            }
        }

        usort(
            $this->entries,
            static function (array $first, array $second): int {
                return strlen($second['source_base']) <=> strlen($first['source_base']);
            }
        );
    }

    /**
     * Returns the prepared mappings in longest-source-first order.
     *
     * @return array<int, array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_scheme: string,
     *     target_path: string,
     *     target_port: int|null,
     *     pattern: string
     * }>
     */
    public function get_entries(): array
    {
        return $this->entries;
    }

    /** Let the text scanner skip path extraction when this host has no child sites. */
    public function has_excluded_paths_for_host(string $host): bool
    {
        return isset($this->excluded_paths[strtolower($host)]);
    }

    /**
     * Return true if the URL points to a child site and must stay on the source.
     *
     * For a child site at /shop/news/ on this host:
     * - /shop/news returns true.
     * - /shop/news/article returns true.
     * - /shop/newsletter returns false.
     *
     * Look up path prefixes in this host's child-path set, without scanning all
     * sites. Skip prefixes longer than the longest stored child path.
     *
     * The caller must resolve "." and ".." with the URL parser first.
     * Decode percent escapes and ignore ASCII case for comparison only.
     * This method does not change the URL.
     */
    public function excludes_path(string $host, string $path): bool
    {
        $host = strtolower($host);
        if ($path === '' || !isset($this->excluded_paths[$host])) {
            return false;
        }
        $path = strtolower(rawurldecode($path));
        $longest_path_bytes = $this->excluded_paths[$host]['longest_path_bytes'];
        for ($offset = strpos($path, '/', 1); $offset !== false && $offset <= $longest_path_bytes; $offset = strpos($path, '/', $offset + 1)) {
            if (isset($this->excluded_paths[$host]['paths'][substr($path, 0, $offset)])) {
                return true;
            }
        }
        return strlen($path) <= $longest_path_bytes && isset($this->excluded_paths[$host]['paths'][$path]);
    }

    /**
     * @return array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_scheme: string,
     *     target_path: string,
     *     target_port: int|null,
     *     pattern: string
     * }|null
     */
    private function create_entry(string $source_url, string $target_url): ?array
    {
        $source = $this->get_supported_url_parts($source_url, true);
        // A same-URL rule keeps sibling pages or media at the source. Reuse
        // the source parts: this rule does not insert a new host or path.
        $target = $source_url === $target_url ? $source : $this->get_supported_url_parts($target_url, false);
        if ($source === null || $target === null) {
            return null;
        }

        // A source URL ending at its authority uses / as the URL separator,
        // not as an initial path to remove. Leave its original spelling alone.
        $source_path = $source['path'] === '/' ? '' : $source['path'];
        $target_path = $source_url === $target_url ? $source_path : $target['path'];

        return [
            'source_authority' => $source['authority'],
            'source_path'      => $source_path,
            'source_base'      => $source['authority'] . $source_path,
            'target_domain'    => $target['host'],
            'target_scheme'    => $target['scheme'],
            'target_path'      => $target_path,
            'target_port'      => $target['port'],
            'pattern'          => $this->create_url_candidate_pattern(
                $source['scheme'],
                $source['authority'],
                $source_path,
                $target_path !== ''
            ),
        ];
    }

    /**
     * Build a candidate pattern adapted from URLInTextProcessor's URL finder.
     *
     * The pattern recognizes one mapping's absolute, protocol-relative, and
     * scheme-less forms. It captures the first slash before the authority and
     * the first slash in or after the configured source base. The first
     * available capture supplies the spelling for a target path.
     */
    private function create_url_candidate_pattern(
        string $source_scheme,
        string $source_authority,
        string $source_path,
        bool $requires_path_slash
    ): string
    {
        $separator_escape = '\\\\{0,8}';
        $source_path_pattern = '';
        if ($source_path !== '') {
            $source_path_pattern =
                '(?<path_slash>' . $separator_escape . '/)'
                . str_replace(
                    '/',
                    $separator_escape . '/',
                    preg_quote(substr($source_path, 1), '~')
                );
        }
        $candidate_boundary_pattern = '(?=
            $
            | ' . $separator_escape . '/
            | [/?# \t\r\n,!;)\]}>"\']
        )';
        if ($requires_path_slash && $source_path === '') {
            $candidate_boundary_pattern = '(?(url_slash)
                ' . $candidate_boundary_pattern . '
                |
                (?=(?<path_slash>' . $separator_escape . '/))
            )';
        }

        return '~
            (?<![A-Za-z0-9._%+\\/@-])
            (?:
                (?:
                    (?<scheme>(?i:' . preg_quote($source_scheme, '~') . '))
                    (?<scheme_colon>' . $separator_escape . ':)
                    |
                    (?<!:)
                )
                (?<url_slash>(?<url_slash_escape>' . $separator_escape . ')/)
                \k<url_slash_escape>/
                (?:[^\s<>@/\\\\]+@)?
            )?
            (?<base>
                (?<authority>(?i:' . preg_quote($source_authority, '~') . '))
                ' . $source_path_pattern . '
            )
            ' . $candidate_boundary_pattern . '
        ~x';
    }

    /**
     * @return array{scheme: string, host: string, authority: string, path: string, port: int|null}|null
     */
    private function get_supported_url_parts(string $url, bool $is_source_url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $unsupported_part) {
            if (array_key_exists($unsupported_part, $parts)) {
                return null;
            }
        }

        $parsed = WPURL::parse($url);
        if (!$parsed) {
            return null;
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        $host = (string) $parts['host'];
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $has_unsupported_target_path =
            !$is_source_url
            && $path !== ''
            && preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $path) !== 1;
        // These limits apply only to literal replacement in unknown text.
        // A parsed HTTP host can contain quotes, but inserting one could end
        // the surrounding value. Hostname syntax keeps output to letters,
        // digits, hyphens and dots; IPv4 and a trailing dot need no escaping.
        // IPv6 sources can be matched and removed without inserting brackets.
        // Format-aware rewriters accept the full URL and use their serializers.
        $literal_host = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        $source_ip = $is_source_url && filter_var(trim($parsed->hostname, '[]'), FILTER_VALIDATE_IP) !== false;
        if (( $scheme !== 'http' && $scheme !== 'https' )
            || ( !$is_source_url && $has_unsupported_target_path )
            || !( $literal_host || $source_ip )
            || !$this->contains_only_exclamation_mark_through_tilde_bytes($path)) {
            return null;
        }

        return [
            'scheme'    => $scheme,
            'host'      => $host,
            'authority' => $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
            'path'      => $path,
            'port'      => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    private function contains_only_exclamation_mark_through_tilde_bytes(string $path): bool
    {
        return $path === '' || preg_match('/^[\x21-\x7E]+$/', $path) === 1;
    }
}
