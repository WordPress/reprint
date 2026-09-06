<?php

/**
 * Rewrites mapped HTTP(S) prefixes in CSS without buffering a stylesheet.
 *
 * Only the prefix is replaced. CSS quotes, url() syntax, suffixes, and comments
 * keep their original bytes. Literal and slash-escaped URLs are supported;
 * hexadecimal CSS escapes require a CSS parser and are left alone.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Matches the existing URL processor names.
class CssUrlRewriteStream {

    /** @var list<array{pattern:string,scheme:string,authority:string,path:string,source_bytes:int}> */
    private array $mappings = [];
    private int $lookahead_bytes = 1;
    private string $pending = '';
    private string $previous_byte = '';

    /**
     * @param array<string,string> $url_mapping Source URL base => target URL.
     * @param array|null $cursor {
     *     State saved with the download's completed multipart part.
     *     @type string $pending_b64       Unwritten source bytes, base64 encoded.
     *     @type string $previous_byte_b64 Source byte before the pending prefix.
     * }
     */
    public function __construct(array $url_mapping, ?array $cursor = null)
    {
        foreach ($url_mapping as $source_url => $target_url) {
            $source = $this->url_parts($source_url);
            $target = $this->url_parts($target_url);
            if ($source === null || $target === null) {
                continue;
            }
            $slash = '\\\\{0,8}/';
            $path = str_replace('/', $slash, preg_quote($source['path'], '~'));
            // No bare domains or user information: those would allow matches
            // inside unrelated URLs or require unbounded prefix lookahead.
            $pattern = '~(?<![A-Za-z0-9._%+\\\\/@-])'
                . '(?:(?<scheme>(?i:' . $source['scheme'] . '))(?<colon>\\\\{0,8}:)|(?<!:))'
                . '(?<slash>' . $slash . ')\k<slash>'
                . '(?i:' . preg_quote($source['authority'], '~') . ')'
                . $path . '(?=$|' . $slash . '|[?# \t\r\n,!;)\]}>"\'])~';
            $source_bytes = strlen($source['authority'] . $source['path']);
            $this->mappings[] = [
                'pattern' => $pattern,
                'scheme' => $target['scheme'],
                'authority' => $target['authority'],
                'path' => $target['path'],
                'source_bytes' => $source_bytes,
            ];
            $this->lookahead_bytes = max(
                $this->lookahead_bytes,
                5 + 9 + 18 + $source_bytes + 8 * substr_count($source['path'], '/') + 10
            );
        }
        usort($this->mappings, static function (array $first, array $second): int {
            return $second['source_bytes'] <=> $first['source_bytes'];
        });
        if ($cursor !== null) {
            $this->pending = base64_decode($cursor['pending_b64'], true);
            $this->previous_byte = base64_decode($cursor['previous_byte_b64'], true);
        }
    }

    /**
     * Returns transformed bytes ready to write, retaining only a URL prefix.
     * The final call also releases the tail; no stylesheet cache is discarded.
     */
    public function rewrite_chunk(string $chunk, bool $is_last): string
    {
        $text = $this->previous_byte . $this->pending . $chunk;
        $offset = strlen($this->previous_byte);
        $limit = $is_last ? strlen($text) : max($offset, strlen($text) - $this->lookahead_bytes);
        $output = '';
        while ($offset < $limit) {
            $next_match = null;
            $next_mapping = null;
            foreach ($this->mappings as $mapping) {
                if (preg_match($mapping['pattern'], $text, $matches, PREG_OFFSET_CAPTURE, $offset) === 1
                    && ( $next_match === null || $matches[0][1] < $next_match[0][1] )) {
                    $next_match = $matches;
                    $next_mapping = $mapping;
                }
            }
            if ($next_match === null || $next_match[0][1] >= $limit) {
                $output .= substr($text, $offset, $limit - $offset);
                $offset = $limit;
                break;
            }
            $output .= substr($text, $offset, $next_match[0][1] - $offset);
            $colon = $next_match['colon'][0] !== '' ? $next_match['colon'][0] : ':';
            $slash = $next_match['slash'][0];
            if ($next_match['scheme'][0] !== '') {
                $output .= $next_mapping['scheme'] . $colon;
            }
            $output .= $slash . $slash . str_replace(':', $colon, $next_mapping['authority']);
            $output .= str_replace('/', $slash, $next_mapping['path']);
            $offset = $next_match[0][1] + strlen($next_match[0][0]);
        }
        $this->previous_byte = $offset > 0 ? $text[$offset - 1] : '';
        $this->pending = substr($text, $offset);
        return $output;
    }

    /**
     * @return array {
     *     Bounded source tail required to continue after a process stops.
     *     @type string $pending_b64       Unwritten source bytes, base64 encoded.
     *     @type string $previous_byte_b64 Source byte before the pending prefix.
     * }
     */
    public function get_cursor(): array
    {
        return [
            'pending_b64' => base64_encode($this->pending),
            'previous_byte_b64' => base64_encode($this->previous_byte),
        ];
    }
    /**
     * Accept URL bases whose literal bytes are safe in quoted or unquoted CSS.
     * This includes local IPv4/IPv6 targets; no opaque-text escaping is guessed.
     *
     * @return array|null {
     *     Supported URL base, or null for unsupported syntax.
     *     @type string $scheme    Lowercase HTTP(S) scheme.
     *     @type string $authority Host and optional port.
     *     @type string $path      Initial path without trailing slashes.
     * }
     * @phpstan-return array{scheme:string,authority:string,path:string}|null
     */
    private function url_parts(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = $parts['host'];
        $path = rtrim($parts['path'] ?? '', '/');
        if (!in_array($scheme, ['http', 'https'], true)
            || ( preg_match('/^[A-Za-z0-9.-]+$/D', $host) !== 1 && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false )
            || preg_match('#^[A-Za-z0-9._~%/!$&*+,;=:@-]*$#D', $path) !== 1) {
            return null;
        }
        return [
            'scheme' => $scheme,
            'authority' => $host . ( isset($parts['port']) ? ':' . $parts['port'] : '' ),
            'path' => $path,
        ];
    }
}
