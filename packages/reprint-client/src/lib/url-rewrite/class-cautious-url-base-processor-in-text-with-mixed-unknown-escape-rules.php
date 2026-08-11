<?php

/**
 * Replaces a known URL base without interpreting the surrounding text.
 *
 * For example, given this shortcode:
 *
 * ```
 * [vc_video link="https:\/\/source.example\/wp-content\/uploads\/video.mp4"]
 * ```
 *
 * and this mapping:
 *
 * ```
 * https://source.example => https://destination.example
 * ```
 *
 * the result is:
 *
 * ```
 * [vc_video link="https:\/\/destination.example\/wp-content\/uploads\/video.mp4"]
 * ```
 *
 * The configured base is source.example, so that is the complete byte range
 * replaced. The protocol, escaped slashes, path, and shortcode syntax are not
 * part of the base and remain unchanged.
 *
 * This processor is for text whose escaping rules are unknown. The backslashes
 * above might come from JSON, CSS, a shortcode serializer, or another format.
 * Parsing the URL and writing it back would force this processor to choose one
 * of those formats and could corrupt the value.
 *
 * Instead, the processor performs one narrow operation: find the configured
 * source base as bytes and replace that entire slice with a target domain. It
 * does not decode, normalize, or re-encode the input.
 *
 * Supported sources:
 *
 * - ASCII domains and IPv4 or IPv6 addresses, with an optional port.
 * - An optional initial path containing only bytes from `!` (0x21) through
 *   `~` (0x7E). Spaces and multibyte characters are rejected. That path is
 *   part of the source base and is removed with it. Mapping
 *   https://source.example/media to
 *   https://destination.example changes
 *   https://source.example/media/logo.png to
 *   https://destination.example/logo.png.
 * - Literal, scheme-less, and slash-escaped URL spellings. The recognized
 *   HTTP(S) separators may have one or three preceding backslashes, including
 *   https:\/\/, https\:\/\/, and https:\\\/\\\/.
 * - Other parts of the URL may surround the configured base. For example,
 *   https://user:password@source.example/logo.png?download=1#preview becomes
 *   https://user:password@destination.example/logo.png?download=1#preview.
 *   Only source.example is replaced. The username, password, path, query, and
 *   fragment remain byte-for-byte unchanged.
 *
 * Unsupported mappings are discarded as a whole. There is no partial
 * replacement:
 *
 * - A target path is not supported. Writing it would require choosing whether
 *   each slash should be /, \/, or something else.
 * - Target ports, user information, queries, fragments, IPv4/IPv6 addresses,
 *   and Unicode domains are not supported. Punycode domains are supported.
 * - Unicode source domains and paths are not supported.
 *
 * CSS hexadecimal escapes such as https\3a \2f \2f ... and percent-encoded
 * separators are not recognized. They need a parser for the enclosing format.
 * Complete PHP serializations, JSON documents, and block markup must likewise
 * be parsed first; pass only the resulting text leaves to this processor.
 *
 * The HTTP(S) scheme and authority are matched case-insensitively. A configured
 * source path remains byte-for-byte and case-sensitive because URL paths may
 * name different resources when their case differs. A scheme may begin at the
 * start of the value or after a byte other than an ASCII letter, plus sign, or
 * hyphen. Scheme-less authorities use a stricter boundary so the scanner does
 * not mistake part of another URL or identifier for a match.
 *
 * Example usage:
 *
 * ```php
 * $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules(
 *     '[vc_video link="https:\\/\\/source.example\\/media\\/video.mp4"]',
 *     [
 *         'https://source.example' => 'https://destination.example',
 *     ]
 * );
 *
 * while ($processor->next_url()) {
 *     $processor->replace_url_base();
 * }
 *
 * $rewritten = $processor->get_updated_text();
 * ```
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules {
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
     *     base_length: int
     * }|null
     */
    private ?array $matched_url = null;

    /** @var array<int, array{start: int, length: int, replacement: string}> */
    private array $lexical_updates = [];

    /**
     * Creates a processor for one opaque text value.
     *
     * A source may include an initial path containing only bytes from `!`
     * (0x21) through `~` (0x7E). A target must be an HTTP(S) URL with a
     * supported domain and no path or other URL components:
     *
     * ```
     * [
     *     'https://source.example/media' => 'https://destination.example',
     * ]
     * ```
     *
     * Invalid mappings are skipped as a whole. They cannot produce a partial
     * domain replacement.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
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
     * Finds the next configured source URL base.
     *
     * The match remains current until the next call. Call replace_url_base()
     * first to queue its replacement. Calling next_url() again without doing
     * so skips the current match.
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
     * Queues replacement of the complete current source base.
     *
     * Mapping source.example/media to destination.example changes
     * https://source.example/media/logo.png to
     * https://destination.example/logo.png. The protocol and logo.png suffix
     * are outside the matched base and remain unchanged.
     */
    public function replace_url_base(): bool
    {
        if ($this->matched_url === null) {
            return false;
        }

        $this->lexical_updates[$this->matched_url['start']] = [
            'start'       => $this->matched_url['start'],
            'length'      => $this->matched_url['base_length'],
            'replacement' => $this->matched_url['target_domain'],
        ];

        return true;
    }

    /**
     * Returns the input with all queued base replacements applied.
     *
     * Bytes outside the queued ranges are copied unchanged.
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
                    'start'       => $authority_start,
                    'base_length' => strlen($matches['base'][0]),
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
                (?i:' . preg_quote($source_scheme, '~') . ')
                ' . $escaped_separator . ':
                ' . $escaped_separator . '/
                ' . $escaped_separator . '/
                (?:[^\s<>@/\\\\]+@)?
            )?
            (?<base>
                (?<authority>(?i:' . preg_quote($source_authority, '~') . '))
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
        if (( $scheme !== 'http' && $scheme !== 'https' )
            || ( !$is_source_url && ( array_key_exists('port', $parts) || $path !== '' ) )
            || !( $this->is_alphanumeric_dot_hyphen_domain_name($host) || ( $is_source_url && $this->is_ip_address($host) ) )
            || !$this->contains_only_exclamation_mark_through_tilde_bytes($path)) {
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

    private function contains_only_exclamation_mark_through_tilde_bytes(string $path): bool
    {
        return $path === '' || preg_match('/^[\x21-\x7E]+$/', $path) === 1;
    }
}
