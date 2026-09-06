<?php

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
     * Prepares source URL base => target URL pairs.
     *
     * Invalid pairs are skipped as a whole. They cannot produce a partial
     * domain replacement.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    public function __construct(array $url_mapping)
    {
        foreach ($url_mapping as $source_url => $target_url) {
            $entry = $this->create_entry($source_url, $target_url);
            if ($entry !== null) {
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
        $target = $this->get_supported_url_parts($target_url, false);
        if ($source === null || $target === null) {
            return null;
        }

        // A source URL ending at its authority uses / as the URL separator,
        // not as an initial path to remove. Leave its original spelling alone.
        $source_path = $source['path'] === '/' ? '' : $source['path'];

        return [
            'source_authority' => $source['authority'],
            'source_path'      => $source_path,
            'source_base'      => $source['authority'] . $source_path,
            'target_domain'    => $target['host'],
            'target_scheme'    => $target['scheme'],
            'target_path'      => $target['path'],
            'target_port'      => $target['port'],
            'pattern'          => $this->create_url_candidate_pattern(
                $source['scheme'],
                $source['authority'],
                $source_path,
                $target['path'] !== ''
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

        $scheme = strtolower( (string) $parts['scheme'] );
        $host = (string) $parts['host'];
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $has_unsupported_target_path =
            !$is_source_url
            && $path !== ''
            && preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $path) !== 1;
        if (( $scheme !== 'http' && $scheme !== 'https' )
            || ( !$is_source_url && $has_unsupported_target_path )
            || !( $this->is_alphanumeric_dot_hyphen_domain_name($host) || ( $is_source_url && $this->is_ip_address($host) ) )
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

    private function is_ip_address(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    private function is_alphanumeric_dot_hyphen_domain_name(string $domain): bool
    {
        return filter_var($domain, FILTER_VALIDATE_IP) === false
            && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) === 1;
    }

    private function contains_only_exclamation_mark_through_tilde_bytes(string $path): bool
    {
        return $path === '' || preg_match('/^[\x21-\x7E]+$/', $path) === 1;
    }
}
