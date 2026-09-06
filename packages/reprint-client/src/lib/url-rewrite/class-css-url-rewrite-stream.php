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

    private CautiousURLBaseRewriteMapping $mapping;
    /** @var list<string> Bounded URL-prefix patterns, longest source first. */
    private array $patterns = [];
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
        $this->mapping = new CautiousURLBaseRewriteMapping($url_mapping);
        foreach ($this->mapping->get_entries() as $entry) {
            $slash = '\\\\{0,8}/';
            $path = str_replace('/', $slash, preg_quote($entry['source_path'], '~'));
            // No bare domains or user information: those would allow matches
            // inside unrelated URLs or require unbounded prefix lookahead.
            $this->patterns[] = '~(?<![A-Za-z0-9._%+\\\\/@-])'
                . '(?:(?i:' . $entry['source_scheme'] . ')\\\\{0,8}:|(?<!:))'
                . '(' . $slash . ')\1'
                . '(?i:' . preg_quote($entry['source_authority'], '~') . ')'
                . $path . '(?=$|' . $slash . '|[?# \t\r\n,!;)\]}>"\'])~';
            $this->lookahead_bytes = max(
                $this->lookahead_bytes,
                5 + 9 + 18 + strlen($entry['source_base'])
                    + 8 * substr_count($entry['source_path'], '/') + 10
            );
        }
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
            foreach ($this->patterns as $pattern) {
                if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset) === 1
                    && ( $next_match === null || $matches[0][1] < $next_match[1] )) {
                    $next_match = $matches[0];
                }
            }
            if ($next_match === null || $next_match[1] >= $limit) {
                $output .= substr($text, $offset, $limit - $offset);
                $offset = $limit;
                break;
            }
            $output .= substr($text, $offset, $next_match[1] - $offset);
            $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules(
                $next_match[0],
                $this->mapping
            );
            while ($processor->next_url()) {
                $processor->replace_url_base();
            }
            $output .= $processor->get_updated_text();
            $offset = $next_match[1] + strlen($next_match[0]);
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
}
