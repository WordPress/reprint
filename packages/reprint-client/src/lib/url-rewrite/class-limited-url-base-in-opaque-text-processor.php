<?php

/**
 * Replaces a configured URL base in text whose format is unknown.
 *
 * Given this escaped shortcode:
 *
 * ```
 * [vc_video link="https:\/\/source.example\/wp-content\/uploads\/2026\/01\/video.mp4"]
 * ```
 *
 * and this domain-to-domain mapping:
 *
 * ```
 * https://source.example => https://destination.example
 * ```
 *
 * it replaces the complete configured base, source.example, and produces:
 *
 * ```
 * [vc_video link="https:\/\/destination.example\/wp-content\/uploads\/2026\/01\/video.mp4"]
 * ```
 *
 * The protocol, escaped path, and shortcode syntax are outside the configured
 * base, so they remain byte-for-byte identical.
 *
 * The motivation is boring: opaque text has no reliable escape contract. A
 * backslash may belong to JSON, CSS, a shortcode, HTML, or an application
 * convention. This class therefore replaces one exact source-base slice with
 * one target domain. It never parses, decodes, normalizes, or re-encodes input
 * URL bytes.
 *
 * It handles these cases:
 *
 * - A domain-to-domain mapping in literal, scheme-less, or slash-escaped URLs.
 * - A source base with an initial ASCII path when the target has no path. The
 *   entire source base is removed as one slice. For example, mapping
 *   https://source.example/media to https://destination.example changes
 *   https://source.example/media/logo.png to
 *   https://destination.example/logo.png.
 * - HTTP(S) spellings with one or three backslashes before the colon and/or
 *   either slash: https:\/\/, https\:\/\/, and https:\\\/\\\/.
 * - An ASCII source domain, or an IPv4 or IPv6 source address with an optional
 *   port. Punycode destination domains are accepted.
 * - User information before the source authority, and query and fragment
 *   suffixes after the base. Those bytes remain untouched.
 *
 * It deliberately does not handle these cases:
 *
 * - A target with a path. Inserting that path would require deciding whether
 *   its separators should be /, \/, or another representation. The complete
 *   mapping is ignored; the processor does not fall back to changing only the
 *   domain.
 * - CSS hexadecimal escapes such as https\3a \2f \2f ..., percent-encoded
 *   separators, a Unicode destination domain, or an IPv4/IPv6 destination.
 *   Those require a parser that knows the surrounding format.
 * - A complete PHP serialization, JSON document, or block-markup value. Call
 *   this only for a text leaf after the processor for that format has exposed
 *   it. This class cannot preserve a representation it did not parse.
 *
 * Source authorities and paths match as exact ASCII bytes; case variants are
 * not equivalent here. A candidate scheme starts at the beginning of the value
 * or after any byte other than an ASCII letter, plus sign, or hyphen. This
 * accepts a URL after an equals sign, while rejecting a URL embedded in an
 * identifier or a longer scheme name. A scheme-less authority has a stronger
 * left boundary so it cannot match the host portion of a malformed URL.
 *
 * Example usage:
 *
 * ```php
 * $processor = new LimitedURLBaseInOpaqueTextProcessor(
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
     *     base_length: int
     * }|null
     */
    private ?array $matched_url = null;

    /** @var array<int, array{start: int, length: int, replacement: string}> */
    private array $lexical_updates = [];

    /**
     * Construct a processor for one opaque text value.
     *
     * Each mapping uses a complete HTTP(S) source URL base and a target URL
     * containing only its domain. For example:
     *
     * ```
     * [
     *     'https://source.example/media' => 'https://destination.example',
     * ]
     * ```
     *
     * A matching occurrence replaces source.example/media as one slice. A
     * target path, port, IP address, user information, query, or fragment makes
     * the mapping unsupported, so that mapping is ignored entirely.
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
     * Find the next configured source URL base in the text.
     *
     * A current URL remains available until the next call. Call
     * replace_url_base() to queue the base replacement, then call this method
     * again. Calling replace_url_base() is optional: advancing skips that
     * match without changing it.
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
     * Queue replacement of the complete current source URL base.
     *
     * A mapping from source.example/media to destination.example turns
     * https://source.example/media/logo.png into
     * https://destination.example/logo.png. It never keeps /media after
     * claiming to replace that source base, and it never falls back to a
     * domain-only replacement for an unsupported target mapping.
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
     * Return the input with queued URL-base replacements applied.
     *
     * This does not mutate the original text. Bytes outside queued URL-base
     * ranges, including URL suffixes and unrelated escape bytes, are copied
     * exactly.
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
