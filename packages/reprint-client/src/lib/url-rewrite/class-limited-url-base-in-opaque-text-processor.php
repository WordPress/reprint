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
 * and this mapping:
 *
 * ```
 * https://source.example/wp-content/uploads => https://destination.example/wp-content/uploads
 * ```
 *
 * it changes source.example to destination.example and produces:
 *
 * ```
 * [vc_video link="https:\/\/destination.example\/wp-content\/uploads\/2026\/01\/video.mp4"]
 * ```
 *
 * The escaped path bytes are left alone. The complete configured source base
 * and target base have the same initial path, so replacing the domain yields
 * the complete target base without choosing a new escape spelling.
 *
 * The motivation is boring: opaque text has no reliable escape contract. A
 * backslash may belong to JSON, CSS, a shortcode, HTML, or an application
 * convention. This class only changes bytes when it can name the exact range
 * to replace. It does not parse, decode, normalize, or re-encode input URL
 * bytes.
 *
 * It handles these cases:
 *
 * - Literal URL bases. A mapping from source.example/media to
 *   destination.example/assets changes
 *   https://source.example/media/logo.png to
 *   https://destination.example/assets/logo.png. The protocol and logo.png
 *   suffix remain from the input.
 * - Scheme-less URLs and HTTP(S) spellings with one or three backslashes
 *   before the colon and/or either slash: https:\/\/, https\:\/\/, and
 *   https:\\\/\\\/.
 * - A configured ASCII source domain, or an IPv4 or IPv6 source address with
 *   an optional port. Punycode destination domains are accepted.
 * - User information before the source authority, and query and fragment
 *   suffixes after the base. Those bytes remain untouched.
 *
 * It deliberately does not handle these cases:
 *
 * - An escaped source path with a different target path. Writing the target
 *   would require deciding whether its separators should be /, \/, or another
 *   representation. The URL is left unchanged.
 * - CSS hexadecimal escapes such as https\3a \2f \2f ..., percent-encoded
 *   separators, a Unicode destination domain or path, or an IPv4/IPv6
 *   destination. Those require a parser that knows the surrounding format.
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
 *     '[vc_video link="https://source.example/wp-content/uploads/video.mp4"]',
 *     [
 *         'https://source.example/wp-content/uploads' => 'https://destination.example/assets',
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
     *     target_path: string,
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
     *     target_path: string,
     *     pattern: string,
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
     * Construct a processor for one opaque text value.
     *
     * Each mapping uses a complete HTTP(S) source URL base and target URL
     * base. A target may have an ASCII path, for example:
     *
     * ```
     * [
     *     'https://source.example/wp-content/uploads' => 'https://destination.example/assets',
     * ]
     * ```
     *
     * A literal occurrence replaces source.example/wp-content/uploads with
     * destination.example/assets. An escaped occurrence is left alone because
     * this processor does not encode the target path.
     *
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
     * Queue replacement of the current source URL base.
     *
     * For a literal input, a mapping from source.example/media to
     * destination.example/assets turns
     * https://source.example/media/logo.png into
     * https://destination.example/assets/logo.png. It leaves the protocol and
     * logo.png untouched.
     *
     * The same mapping does not change https:\/\/source.example\/media\/logo.png.
     * Choosing how to write the target path would re-encode bytes from the
     * opaque input. When both configured paths are the same, replacing only
     * the authority preserves the existing path representation.
     */
    public function replace_url_base(): bool
    {
        if ($this->matched_url === null) {
            return false;
        }

        $length = $this->matched_url['authority_length'];
        $replacement = $this->matched_url['target_domain'];
        if ($this->matched_url['source_path'] !== $this->matched_url['target_path']) {
            if ($this->matched_url['has_escaped_separator']) {
                return false;
            }

            $length = $this->matched_url['base_length'];
            $replacement .= $this->matched_url['target_path'];
        }

        $this->lexical_updates[$this->matched_url['start']] = [
            'start'       => $this->matched_url['start'],
            'length'      => $length,
            'replacement' => $replacement,
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
     *     target_path: string,
     *     pattern: string,
     *     start: int,
     *     authority_length: int,
     *     base_length: int,
     *     has_escaped_separator: bool
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
                    'start'                 => $authority_start,
                    'authority_length'      => strlen($matches['authority'][0]),
                    'base_length'           => strlen($matches['base'][0]),
                    'has_escaped_separator' => strpos($matches['base'][0], '\\') !== false,
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
     *     target_path: string,
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
            'target_path'      => $target['path'],
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
            || ( !$allow_ip_and_port && array_key_exists('port', $parts) )
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
