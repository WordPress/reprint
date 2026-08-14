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
     *     source_ascii_host: string,
     *     source_host_uses_idn: bool,
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
     * A source may include an initial valid UTF-8 path without whitespace or
     * control characters. A target must be an HTTP(S) URL with a supported
     * domain, optional port, and optional restricted path:
     *
     * ```
     * [
     *     'https://source.example/media' => 'https://destination.example/assets',
     * ]
     * ```
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
     *     source_ascii_host: string,
     *     source_host_uses_idn: bool,
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
     *     source_ascii_host: string,
     *     source_host_uses_idn: bool,
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
        $source_authority_pattern = '(?i:' . preg_quote($source['authority'], '~') . ')';
        if ($source['host_uses_idn']) {
            // This branch locates Unicode authority candidates. IDNA below
            // decides whether each candidate names the configured host.
            $unicode_host_character = "[^\\s<>@/\\\\:?#,!;()\\[\\]{}>\"']";
            $source_authority_pattern =
                '(?:' . $source_authority_pattern
                . '|(?<unicode_host>(?=' . $unicode_host_character . '*[\\x80-\\xFF])'
                . $unicode_host_character . '+)'
                . ( $source['port'] === null ? '' : ':' . $source['port'] )
                . ')';
        }

        return [
            'source_authority'     => $source['authority'],
            'source_ascii_host'    => $source['ascii_host'],
            'source_host_uses_idn' => $source['host_uses_idn'],
            'source_path'          => $source_path,
            'source_base'          => $source['authority'] . $source_path,
            'target_domain'        => $target['host'],
            'target_scheme'        => $target['scheme'],
            'target_path'          => $target['path'],
            'target_port'          => $target['port'],
            'pattern'              => $this->create_url_candidate_pattern(
                $source['scheme'],
                $source_authority_pattern,
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
        string $source_authority_pattern,
        string $source_path,
        bool $requires_path_slash
    ): string
    {
        $separator_escape = '\\\\{0,8}';
        $source_path_pattern = '';
        if ($source_path !== '') {
            $source_path_spellings = [$source_path];
            // NFC writes characters such as é as one code point. NFD writes
            // the same character as e followed by a combining accent. Build
            // both spellings because the mapping and input may use different
            // forms for the same path.
            foreach ([Normalizer::FORM_C, Normalizer::FORM_D] as $normalization_form) {
                $normalized_source_path = Normalizer::normalize(
                    $source_path,
                    $normalization_form
                );
                if (
                    is_string($normalized_source_path)
                    && !in_array($normalized_source_path, $source_path_spellings, true)
                ) {
                    $source_path_spellings[] = $normalized_source_path;
                }
            }

            $source_path_suffix_patterns = [];
            foreach ($source_path_spellings as $source_path_spelling) {
                $source_path_suffix_patterns[] = str_replace(
                    '/',
                    $separator_escape . '/',
                    preg_quote(substr($source_path_spelling, 1), '~')
                );
            }

            $source_path_pattern =
                '(?<path_slash>' . $separator_escape . '/)'
                . '(?:' . implode('|', $source_path_suffix_patterns) . ')';
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
                (?<authority>' . $source_authority_pattern . ')
                ' . $source_path_pattern . '
            )
            ' . $candidate_boundary_pattern . '
        ~x';
    }

    /**
     * @return array{
     *     scheme: string,
     *     host: string,
     *     ascii_host: string,
     *     host_uses_idn: bool,
     *     authority: string,
     *     path: string,
     *     port: int|null
     * }|null
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
        if ($is_source_url) {
            // parse_url() replaces control-valued bytes with underscores.
            // Some UTF-8 continuation bytes fall in that range. Read the
            // source authority and path from the configured URL after
            // parse_url() has checked its structure.
            $authority_separator_at = strpos($url, '://');
            if ($authority_separator_at === false) {
                return null;
            }
            $authority_starts_at = $authority_separator_at + 3;
            $path_starts_at = strpos($url, '/', $authority_starts_at);
            $source_authority = $path_starts_at === false
                ? substr($url, $authority_starts_at)
                : substr(
                    $url,
                    $authority_starts_at,
                    $path_starts_at - $authority_starts_at
                );
            if (isset($parts['port'])) {
                $port_suffix = ':' . $parts['port'];
                if (substr($source_authority, -strlen($port_suffix)) !== $port_suffix) {
                    return null;
                }
                $host = substr($source_authority, 0, -strlen($port_suffix));
            } else {
                $host = $source_authority;
            }
            $path = $path_starts_at === false ? '' : substr($url, $path_starts_at);
        }

        $host_is_ascii_domain = $this->is_alphanumeric_dot_hyphen_domain_name($host);
        $host_is_ip_address =
            $is_source_url
            && !$host_is_ascii_domain
            && $this->is_ip_address($host);
        $host_contains_punycode_label =
            $host_is_ascii_domain
            && stripos('.' . $host, '.xn--') !== false;
        $host_uses_idn =
            $is_source_url
            && (
                $host_contains_punycode_label
                || ( !$host_is_ascii_domain && !$host_is_ip_address )
            );
        $ascii_host = $host;
        if ($host_uses_idn) {
            if (
                !$host_is_ascii_domain
                && preg_match('/^[\p{L}\p{M}\p{N}.-]+$/u', $host) !== 1
            ) {
                return null;
            }
            $ascii_host = self::to_ascii_idn_hostname($host);
            if ($ascii_host === null) {
                return null;
            }
        }

        $has_unsupported_target_path =
            !$is_source_url
            && $path !== ''
            && preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $path) !== 1;
        $path_is_supported = $is_source_url
            ? preg_match('/^[^\p{Z}\p{C}]*$/u', $path) === 1
            : $this->contains_only_exclamation_mark_through_tilde_bytes($path);
        $host_is_supported =
            $host_is_ascii_domain
            || $host_is_ip_address
            || (
                $host_uses_idn
                && $this->is_alphanumeric_dot_hyphen_domain_name($ascii_host)
            );
        if (( $scheme !== 'http' && $scheme !== 'https' )
            || ( !$is_source_url && $has_unsupported_target_path )
            || !$host_is_supported
            || !$path_is_supported) {
            return null;
        }

        return [
            'scheme'        => $scheme,
            'host'          => $host,
            'ascii_host'    => $ascii_host,
            'host_uses_idn' => $host_uses_idn,
            'authority'     => $ascii_host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
            'path'          => $path,
            'port'          => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    /**
     * Converts a Unicode or Punycode hostname to its lowercase ASCII form.
     *
     * Returns null when IDNA rejects the hostname.
     */
    public static function to_ascii_idn_hostname(string $hostname): ?string
    {
        $idn_info = [];
        $ascii_hostname = idn_to_ascii(
            $hostname,
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES,
            INTL_IDNA_VARIANT_UTS46,
            $idn_info
        );
        if (
            !is_string($ascii_hostname)
            || $ascii_hostname === ''
            || ( $idn_info['errors'] ?? 0 ) !== 0
        ) {
            return null;
        }

        return strtolower($ascii_hostname);
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
