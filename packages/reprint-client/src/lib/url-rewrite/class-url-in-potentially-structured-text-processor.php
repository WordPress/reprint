<?php

/**
 * Replaces configured URL bases in text whose surrounding format is unknown.
 *
 * This is deliberately not a URL rewriter. It finds a configured HTTP(S)
 * scheme spelling followed by the configured source domain and initial path.
 * The initial path qualifies the mapping; it is not normally replaced. The
 * processor replaces the configured source authority with the destination
 * domain and preserves the protocol, path, and
 * every following byte byte-for-byte. It may replace the configured initial
 * path only when the complete URL base uses literal, unescaped separators.
 * In particular, this class never parses, decodes, normalizes, or re-encodes
 * bytes from the input URL. Source authorities and paths match as exact ASCII
 * bytes; case variants are not equivalent here.
 *
 * A configured source may use an ASCII domain or an IP address, with an
 * optional port, so exports made from a local development server can still be
 * imported. The destination must use an ASCII domain and ASCII path, with no
 * port, user information, query, or fragment. Punycode domains are ASCII and
 * therefore supported. IPv4 and IPv6 destination addresses, Unicode
 * destinations, and every unsupported mapping are left for a parser which
 * knows the surrounding data format.
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
 * rejecting a URL embedded in an identifier or a longer scheme name.
 *
 * The scanner accepts the literal protocol spelling (`https://`) and forms
 * with one or three backslashes before the colon and/or either slash
 * (`https:\/\/`, `https\:\/\/`, and `https:\\\/\\\/`). The three-backslash
 * form is one JSON escaping layer around an already escaped URL. Escaped
 * separators prevent initial-path replacement, so the processor never has to
 * manufacture an escaped target path. This covers common JSON-like and
 * site-builder spellings without making a claim about their escape rules.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class URLInPotentiallyStructuredTextProcessor {
    /**
     * @var array<int, array{
     *     source_scheme: string,
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_path: string
     * }>
     */
    private array $url_mappings = [];

    private string $text;

    private int $bytes_already_scanned = 0;

    /**
     * @var array{
     *     source_scheme: string,
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_path: string,
     *     start: int,
     *     authority_length: int,
     *     base_length: int,
     *     has_escaped_separator: bool
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
     * The configured target protocol is intentionally not used. If source and
     * target paths match, the replacement covers only the domain. Different
     * paths are replaced only for a fully literal base, never by generating an
     * escaped target path.
     */
    public function replace_url_base(): bool
    {
        if ($this->matched_url === null) {
            return false;
        }

        $replacement = $this->matched_url['target_domain'];
        $length = $this->matched_url['authority_length'];
        if ($this->matched_url['source_path'] !== $this->matched_url['target_path']) {
            if ($this->matched_url['has_escaped_separator']) {
                return false;
            }

            $replacement .= $this->matched_url['target_path'];
            $length = $this->matched_url['base_length'];
        }

        $this->lexical_updates[$this->matched_url['start']] = [
            'start'       => $this->matched_url['start'],
            'length'      => $length,
            'replacement' => $replacement,
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
     *     source_scheme: string,
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_path: string,
     *     start: int,
     *     authority_length: int,
     *     base_length: int,
     *     has_escaped_separator: bool
     * }|null
     */
    private function find_next_url_base(): ?array
    {
        while (true) {
            $next_start = null;
            foreach ($this->url_mappings as $mapping) {
                $start = strpos($this->text, $mapping['source_authority'], $this->bytes_already_scanned);
                if ($start !== false && ( $next_start === null || $start < $next_start )) {
                    $next_start = $start;
                }
            }

            if ($next_start === null) {
                return null;
            }

            foreach ($this->url_mappings as $mapping) {
                if (substr($this->text, $next_start, strlen($mapping['source_authority'])) !== $mapping['source_authority']) {
                    continue;
                }

                $match = $this->match_url_base_at($mapping, $next_start);
                if ($match !== null) {
                    return $match;
                }
            }

            $this->bytes_already_scanned = $next_start + 1;
        }
    }

    /**
     * @param array{
     *     source_scheme: string,
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_path: string
     * } $mapping
     * @return array{
     *     source_scheme: string,
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_path: string,
     *     start: int,
     *     authority_length: int,
     *     base_length: int,
     *     has_escaped_separator: bool
     * }|null
     */
    private function match_url_base_at(array $mapping, int $start): ?array
    {
        $scheme_match = $this->find_scheme_before($start, $mapping['source_scheme']);
        if ($scheme_match === null) {
            return null;
        }

        $position = $start + strlen($mapping['source_authority']);
        $has_escaped_separator = $scheme_match['has_escaped_separator'];
        $source_path_length = strlen($mapping['source_path']);
        for ($path_position = 0; $path_position < $source_path_length; $path_position++) {
            $expected_byte = $mapping['source_path'][$path_position];
            if ($expected_byte === '/') {
                foreach ([3, 1] as $backslash_count) {
                    $escaped_separator = str_repeat('\\', $backslash_count) . '/';
                    if (substr($this->text, $position, strlen($escaped_separator)) === $escaped_separator) {
                        $has_escaped_separator = true;
                        $position += strlen($escaped_separator);
                        continue 2;
                    }
                }
                if (( $this->text[$position] ?? '' ) === '/') {
                    ++$position;
                    continue;
                }
                return null;
            }

            if (( $this->text[$position] ?? '' ) !== $expected_byte) {
                return null;
            }
            ++$position;
        }

        if (!$this->has_url_boundary_after($position)) {
            return null;
        }

        return array_merge(
            $mapping,
            [
                'start'                 => $start,
                'authority_length'      => strlen($mapping['source_authority']),
                'base_length'           => $position - $start,
                'has_escaped_separator' => $has_escaped_separator,
            ]
        );
    }

    /**
     * @return array{has_escaped_separator: bool}|null
     */
    private function find_scheme_before(int $domain_start, string $source_scheme): ?array
    {
        foreach ([0, 1, 3] as $colon_backslash_count) {
            foreach ([0, 1, 3] as $first_slash_backslash_count) {
                foreach ([0, 1, 3] as $second_slash_backslash_count) {
                    $separator = str_repeat('\\', $colon_backslash_count) . ':'
                        . str_repeat('\\', $first_slash_backslash_count) . '/'
                        . str_repeat('\\', $second_slash_backslash_count) . '/';
                    $prefix = $source_scheme . $separator;
                    $scheme_start = $domain_start - strlen($prefix);
                    if ($scheme_start < 0
                        || substr($this->text, $scheme_start, strlen($prefix)) !== $prefix
                        || !$this->has_scheme_left_boundary($scheme_start)) {
                        continue;
                    }

                    return [
                        'has_escaped_separator' => (
                            $colon_backslash_count > 0
                            || $first_slash_backslash_count > 0
                            || $second_slash_backslash_count > 0
                        ),
                    ];
                }
            }
        }

        return null;
    }

    private function has_scheme_left_boundary(int $start): bool
    {
        if ($start === 0) {
            return true;
        }

        $byte = $this->text[$start - 1];
        return ! (
            ( $byte >= 'A' && $byte <= 'Z' )
            || ( $byte >= 'a' && $byte <= 'z' )
            || $byte === '+'
            || $byte === '-'
        );
    }

    private function has_url_boundary_after(int $end): bool
    {
        if ($end === strlen($this->text)) {
            return true;
        }

        return substr($this->text, $end, 4) === str_repeat('\\', 3) . '/'
            || substr($this->text, $end, 2) === '\\/'
            || strpos("/?# \t\r\n.,!;:)]}>\"'", $this->text[$end]) !== false;
    }

    /**
     * @return array{
     *     source_scheme: string,
     *     source_authority: string,
     *     source_path: string,
     *     source_base: string,
     *     target_domain: string,
     *     target_path: string
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
            'source_scheme'    => $source['scheme'],
            'source_authority' => $source['authority'],
            'source_path'      => $source['path'],
            'source_base'      => $source['authority'] . $source['path'],
            'target_domain'    => $target['host'],
            'target_path'      => $target['path'],
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
            || ( !$allow_ip_and_port && array_key_exists('port', $parts) )
            || !( $this->is_ascii_domain($host) || ( $allow_ip_and_port && $this->is_ip_address($host) ) )
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

    private function is_ascii_domain(string $domain): bool
    {
        return filter_var($domain, FILTER_VALIDATE_IP) === false
            && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) === 1;
    }

    private function is_ascii_path(string $path): bool
    {
        return $path === '' || preg_match('/^[\x21-\x7E]+$/', $path) === 1;
    }
}
