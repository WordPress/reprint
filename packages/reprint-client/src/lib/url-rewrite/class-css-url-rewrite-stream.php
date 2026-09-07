<?php

/**
 * Rewrites source-site URL prefixes as CSS download bytes arrive.
 *
 * A URL can span two network callbacks. Retaining a short suffix lets the next
 * callback complete a match without keeping the whole stylesheet in memory.
 * The retained bytes can also be saved with the download checkpoint for resume.
 *
 * This scans bytes, not CSS syntax: matching prefixes are replaced wherever
 * they occur, and all other bytes are preserved. It recognizes HTTP(S) and
 * protocol-relative URLs, including slash escapes, but does not decode CSS
 * hexadecimal escapes. Callers write the returned bytes to the destination;
 * this class performs no file I/O.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- URL processors use unprefixed class names.
class CssUrlRewriteStream {

    /**
     * Longest source prefixes first, so a specific path wins over its parent.
     *
     * @var list<array{pattern:string,scheme:string,authority:string,path:string,source_bytes:int}>
     */
    private array $mappings = [];
    private int $lookahead_bytes = 1;
    private string $pending = '';
    private string $previous_byte = '';

    /**
     * Prepares URL matches, optionally restoring bytes retained at a checkpoint.
     *
     * Unsupported source or target URL bases are skipped. A restored cursor
     * must use the same mappings as the download that produced it.
     *
     * @param array<string,string> $url_mapping Source URL bases as keys, target URL bases as values.
     * @param array|null $cursor {
     *     State returned by get_cursor(), or null to start a file.
     *     @type string $pending_b64       Unprocessed source suffix, base64 encoded.
     *     @type string $previous_byte_b64 Source byte before that suffix, base64 encoded, for checking the match's left boundary.
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
            // Require URL syntax and a left boundary: old.example inside
            // another URL's path or credentials must not become a site match.
            // Credential prefixes would also need an unbounded lookahead.
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
     * Returns bytes ready to write and retains a bounded source suffix.
     *
     * The suffix is long enough to hold an incomplete mapped prefix and its
     * following delimiter. Matching uses original source bytes, so replacement
     * URLs are not matched again. The previous source byte is retained only to
     * check the next match's left boundary; it is not emitted twice.
     *
     * @param string $chunk Next source bytes, in file order.
     * @param bool $is_last Whether this is the end of the file, not merely the
     *                      end of a multipart part. Releases all retained bytes.
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
     * Returns the source bytes needed to resume matching in a new instance.
     *
     * Save this with the corresponding source fetch cursor and the number of
     * transformed bytes written locally. Neither file offset alone describes
     * the pending bytes. Base64 keeps arbitrary CSS bytes safe in JSON state.
     *
     * @return array {
     *     State accepted by the constructor's $cursor argument.
     *     @type string $pending_b64       Unprocessed source suffix, base64 encoded.
     *     @type string $previous_byte_b64 Source byte before that suffix, base64 encoded, for checking the match's left boundary.
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
     * Extracts the scheme, host, port, and path supported by the byte matcher.
     *
     * Only HTTP(S) bases without credentials, queries, or fragments are accepted.
     * Hosts may be ASCII names or IP addresses. Restricting path characters lets
     * replacements work without determining how the surrounding CSS is quoted.
     *
     * @return array|null {
     *     Parsed URL base, or null when the matcher cannot handle its syntax.
     *     @type string $scheme    Lowercase HTTP(S) scheme.
     *     @type string $authority Host and optional port, including brackets around IPv6 addresses.
     *     @type string $path      Path prefix with trailing slashes removed.
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
