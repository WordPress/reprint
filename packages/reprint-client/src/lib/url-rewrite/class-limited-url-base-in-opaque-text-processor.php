<?php

/**
 * Replaces configured URL bases in text whose surrounding format is unknown.
 *
 * This is deliberately not a URL rewriter. It finds a configured source
 * authority and initial path, with an optional HTTP(S) scheme spelling. The
 * initial path qualifies the mapping; it is never replaced. The
 * processor replaces the configured source authority with the destination
 * domain and preserves the protocol, path, and every following byte
 * byte-for-byte. The configured initial path only qualifies the mapping; it
 * is never replaced.
 * In particular, this class never parses, decodes, normalizes, or re-encodes
 * bytes from the input URL. Source authorities and paths match as exact ASCII
 * bytes; case variants are not equivalent here.
 *
 * A configured source may use an ASCII domain or an IP address, with an
 * optional port, so exports made from a local development server can still be
 * imported. The destination must use an alphanumeric, dot, and hyphen domain
 * with no path, port, user information, query, or fragment. Punycode domains
 * are supported. IPv4 and IPv6 destination addresses, Unicode destinations,
 * destination paths, and every unsupported mapping are left for a parser
 * which knows the surrounding data format.
 *
 * Call this only with a string leaf that its caller has already classified as
 * text. It is not a parser for a complete PHP serialization, JSON document,
 * or block-markup value: those formats must first be handled by a processor
 * which knows their representation. Keep complete structured values out of
 * this processor and its tests; this class cannot preserve their syntax.
 *
 * A candidate scheme starts at the beginning of the value or after any byte
 * other than an ASCII letter, plus sign, or hyphen. This deliberately accepts
 * a URL after an equals sign, such as a URL-valued query parameter, while
 * rejecting a URL embedded in an identifier or a longer scheme name. A
 * scheme-less authority requires a stronger left boundary so it cannot match
 * the host portion of a malformed or unsupported URL.
 *
 * The scanner accepts the literal protocol spelling (`https://`) and forms
 * with one or three backslashes before the colon and/or either slash
 * (`https:\/\/`, `https\:\/\/`, and `https:\\\/\\\/`). The three-backslash
 * form is one JSON escaping layer around an already escaped URL. This covers
 * common JSON-like and site-builder spellings without making a claim about
 * their escape rules.
 * Optional user information before the source authority, plus query and
 * fragment suffixes after it, remain untouched.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class LimitedURLBaseInOpaqueTextProcessor {
    /**
     * @var array<int, array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     pattern: string
     * }>
     */
    private array $url_mappings = [];

    private string $text;

    private int $bytes_already_scanned = 0;

    /**
     * @var array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     pattern: string,
     *     start: int,
     *     authority_length: int,
     *     base_length: int
     * }|null
     */
    private ?array $matched_url = null;

    /** @var array<int, array{start: int, length: int, replacement: string}> */
    private array $lexical_updates = [];

    /**
     * @param array<string, string> $url_mapping Source URL base => target URL base.
     */
    public function __construct(string $text, array $url_mapping)
    {
        $this->text = $text;

        foreach ($url_mapping as $source_url => $target_url) {
            $mapping = $this->create_url_mapping($source_url, $target_url);
            if ($mapping !== null) {
                $this->url_mappings[] = $mapping;
            }
        }

        usort(
            $this->url_mappings,
            static function (array $first, array $second): int {
                return strlen($second['source_base']) <=> strlen($first['source_base']);
            }
        );
    }

    /**
     * Move to the next configured source URL base in the text.
     *
     * A current URL remains available until the next call. Call
     * replace_url_base() before advancing to queue its replacement.
     */
    public function next_url(): bool
    {
        $this->matched_url = $this->find_next_url_base();
        if ($this->matched_url === null) {
            return false;
        }

        $this->bytes_already_scanned = $this->matched_url['start'] + $this->matched_url['base_length'];
        return true;
    }

    /**
     * Queue the current URL-base replacement.
     *
     * The configured target protocol is intentionally not used. The target
     * mapping cannot contain a path, so the replacement always covers only
     * the source authority.
     */
    public function replace_url_base(): bool
    {
        if ($this->matched_url === null) {
            return false;
        }

        $this->lexical_updates[$this->matched_url['start']] = [
            'start'       => $this->matched_url['start'],
            'length'      => $this->matched_url['authority_length'],
            'replacement' => $this->matched_url['target_domain'],
        ];

        return true;
    }

    /**
     * Apply queued URL-base replacements without changing any other bytes.
     */
    public function get_updated_text(): string
    {
        if ($this->lexical_updates === []) {
            return $this->text;
        }

        ksort($this->lexical_updates);
        $bytes_already_copied = 0;
        $updated_text = '';
        foreach ($this->lexical_updates as $update) {
            $updated_text .= substr(
                $this->text,
                $bytes_already_copied,
                $update['start'] - $bytes_already_copied
            );
            $updated_text .= $update['replacement'];
            $bytes_already_copied = $update['start'] + $update['length'];
        }

        return $updated_text . substr($this->text, $bytes_already_copied);
    }

    /**
     * @return array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     pattern: string,
     *     start: int,
     *     authority_length: int,
     *     base_length: int
     * }|null
     */
    private function find_next_url_base(): ?array
    {
        $next_match = null;
        foreach ($this->url_mappings as $mapping) {
            $found = preg_match(
                $mapping['pattern'],
                $this->text,
                $matches,
                PREG_OFFSET_CAPTURE,
                $this->bytes_already_scanned
            );
            if ($found !== 1) {
                continue;
            }

            $authority_start = $matches['authority'][1];
            if ($next_match !== null && $authority_start >= $next_match['start']) {
                continue;
            }

            $next_match = array_merge(
                $mapping,
                [
                    'start'            => $authority_start,
                    'authority_length' => strlen($matches['authority'][0]),
                    'base_length'      => strlen($matches['base'][0]),
                ]
            );
        }

        return $next_match;
    }

    /**
     * Build a candidate pattern adapted from URLInTextProcessor's URL finder.
     *
     * The pattern recognizes only this mapping's authority and initial path.
     * Capturing those slices, rather than a complete parsed URL, lets callers
     * replace the authority without rendering any surrounding syntax.
     */
    private function create_url_candidate_pattern(
        string $source_scheme,
        string $source_authority,
        string $source_path
    ): string
    {
        $escaped_separator = '(?:\\\\{1}|\\\\{3})?';
        $source_path_pattern = str_replace(
            '/',
            $escaped_separator . '/',
            preg_quote($source_path, '~')
        );

        return '~
            (?<![A-Za-z0-9._%+\\/@-])
            (?:
                ' . preg_quote($source_scheme, '~') . '
                ' . $escaped_separator . ':
                ' . $escaped_separator . '/
                ' . $escaped_separator . '/
                (?:[^\s<>@/\\\\]+@)?
            )?
            (?<base>
                (?<authority>' . preg_quote($source_authority, '~') . ')
                ' . $source_path_pattern . '
            )
            (?=
                $
                | ' . $escaped_separator . '/
                | [/?# \t\r\n.,!;:)\]}>"\']
            )
        ~x';
    }

    /**
     * @return array{
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     pattern: string
     * }|null
     */
    private function create_url_mapping(string $source_url, string $target_url): ?array
    {
        $source = $this->get_supported_url_parts($source_url, true);
        $target = $this->get_supported_url_parts($target_url, false);
        if ($source === null || $target === null) {
            return null;
        }

        return [
            'source_authority' => $source['authority'],
            'source_path'      => $source['path'],
            'source_base'      => $source['authority'] . $source['path'],
            'target_domain'    => $target['host'],
            'pattern'          => $this->create_url_candidate_pattern(
                $source['scheme'],
                $source['authority'],
                $source['path']
            ),
        ];
    }

    /**
     * @return array{scheme: string, host: string, authority: string, path: string}|null
     */
    private function get_supported_url_parts(string $url, bool $allow_ip_and_port): ?array
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
        if (( $scheme !== 'http' && $scheme !== 'https' )
            || ( !$allow_ip_and_port && ( array_key_exists('port', $parts) || $path !== '' ) )
            || !( $this->is_alphanumeric_dot_hyphen_domain_name($host) || ( $allow_ip_and_port && $this->is_ip_address($host) ) )
            || !$this->is_ascii_path($path)) {
            return null;
        }

        return [
            'scheme'    => $scheme,
            'host'      => $host,
            'authority' => $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
            'path'      => $path,
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

    private function is_ascii_path(string $path): bool
    {
        return $path === '' || preg_match('/^[\x21-\x7E]+$/', $path) === 1;
    }
}
