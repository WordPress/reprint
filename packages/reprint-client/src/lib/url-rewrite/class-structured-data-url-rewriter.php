<?php

/**
 * Rewrites exact ASCII URL bases in a decoded database value.
 *
 * Plain text is copied byte for byte around exact source-base replacements.
 * Complete PHP serialization is parsed without evaluating it; string values
 * are rewritten recursively and every changed byte-length prefix is rebuilt.
 * Malformed, partial, embedded, and custom serialized payloads remain opaque.
 * Escaped or otherwise alternate URL spellings remain unchanged.
 */
class StructuredDataUrlRewriter
{
    private const MAX_NESTING_DEPTH = 256;

    /**
     * Exact absolute source bases and pathless target origins for plain text.
     *
     * Longer source bases come first so overlapping mappings choose the most
     * specific match.
     *
     * @var array<string, string>
     */
    private array $literal_mapping = [];

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Constructor exceptions are not HTML output.
    public function __construct(array $url_mapping)
    {
        foreach ($url_mapping as $source_url => $target_url) {
            $source_base = self::normalize_ascii_base_url($source_url, false);
            $target_origin = self::normalize_target_origin($target_url);
            if (
                isset($this->literal_mapping[$source_base])
                && $this->literal_mapping[$source_base] !== $target_origin
            ) {
                throw new InvalidArgumentException(
                    "The source URL {$source_base} has more than one target origin."
                );
            }

            $this->literal_mapping[$source_base] = $target_origin;
        }
        uksort(
            $this->literal_mapping,
            static function (string $first, string $second): int {
                return strlen($second) <=> strlen($first);
            }
        );
    }

    /**
     * Validate and normalize a pathless target origin.
     */
    public static function normalize_target_origin(string $url): string
    {
        return self::normalize_ascii_base_url($url, true);
    }

    /**
     * Normalize one supported literal base and reject unsafe mapping shapes.
     */
    private static function normalize_ascii_base_url(string $url, bool $is_target): string
    {
        $role = $is_target ? 'target' : 'source';
        if ($url === '' || preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw new InvalidArgumentException(
                "The {$role} URL must contain only printable ASCII bytes; got {$url}."
            );
        }

        $matches = [];
        $matched = preg_match(
            '~\A(?<scheme>https?)://(?<host>[A-Za-z0-9.-]+)(?::(?<port>[0-9]{1,5}))?(?<path>/[^?#]*)?\z~',
            $url,
            $matches,
            PREG_UNMATCHED_AS_NULL
        );
        $port = $matches['port'] ?? null;
        if (
            $matched !== 1
            || !self::is_valid_ascii_domain( (string) ( $matches['host'] ?? '' ) )
            || ( $port !== null && ( (int) $port < 1 || (int) $port > 65535 ) )
        ) {
            throw new InvalidArgumentException(
                "The {$role} URL must be an absolute ASCII HTTP URL with a lower-case scheme, a DNS host, an optional port from 1 through 65535, and without credentials, a query, or a fragment; got {$url}."
            );
        }

        $path = (string) ( $matches['path'] ?? '' );
        if ($is_target && $path !== '' && $path !== '/') {
            throw new InvalidArgumentException(
                "The target URL must be an origin without a path; got {$url}."
            );
        }
        if (
            !$is_target
            && $path !== ''
            && preg_match(
                "#\A/(?:[A-Za-z0-9._~:/@!$&'()*+,;=-]|%[0-9A-Fa-f]{2})*\z#",
                $path
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "The source URL path contains unsupported bytes; got {$url}."
            );
        }
        if (!$is_target && $path !== '/' && substr($path, -1) === '/') {
            throw new InvalidArgumentException(
                "The source URL path must not end with a slash; got {$url}."
            );
        }

        return $path === '/' ? substr($url, 0, -1) : $url;
    }

    /**
     * Return whether a host is a supported ASCII DNS name.
     */
    private static function is_valid_ascii_domain(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match(
                    '/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/',
                    $label
                ) !== 1
            ) {
                return false;
            }
        }

        return true;
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Rewrite URLs in a single decoded value.
     *
     * @param string $value The decoded database value.
     * @return string The rewritten value, or the original if no changes were made.
     */
    public function rewrite(string $value): string
    {
        return $this->rewrite_value($value, 0);
    }

    /** Rewrite one value at a bounded structured-data nesting depth. */
    private function rewrite_value(string $value, int $depth): string
    {
        if (
            $value === ''
            || $this->literal_mapping === []
            || $depth > self::MAX_NESTING_DEPTH
        ) {
            return $value;
        }

        if (!$this->contains_source_base($value)) {
            return $value;
        }

        if ($this->could_be_complete_php_serialization($value)) {
            $rewritten_serialization = $this->rewrite_php_serialization($value, $depth);
            if ($rewritten_serialization !== null) {
                return $rewritten_serialization;
            }
        }

        if ($this->might_contain_php_serialization($value)) {
            return $value;
        }

        return $this->rewrite_literal_url_bases($value);
    }

    /**
     * Return whether the first byte can begin serialization with string leaves.
     */
    private function could_be_complete_php_serialization(string $value): bool
    {
        $first_byte = $value[0] ?? '';
        return $first_byte === 'a'
            || $first_byte === 's'
            || $first_byte === 'O'
            || $first_byte === 'C'
            || $first_byte === 'E';
    }

    /**
     * Rewrite a complete PHP serialization without invoking unserialize().
     *
     * Recursive rewriting handles a serialization stored inside a serialized
     * string before the outer string length is recalculated.
     *
     * @return string|null Rewritten bytes, or null when the input is malformed.
     */
    private function rewrite_php_serialization(string $value, int $depth): ?string
    {
        $processor = new PhpSerializationProcessor($value);
        if ($processor->is_malformed()) {
            return null;
        }

        while ($processor->next_value()) {
            $original_value = $processor->get_value();
            $rewritten_value = $this->rewrite_value($original_value, $depth + 1);
            if ($rewritten_value !== $original_value) {
                $processor->set_value($rewritten_value);
            }
        }

        return $processor->get_updated_serialization();
    }

    /**
     * Return whether a value contains any exact configured source-base bytes.
     */
    private function contains_source_base(string $value): bool
    {
        foreach ($this->literal_mapping as $source_base => $_target_origin) {
            if (strpos($value, $source_base) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Conservatively recognize a PHP serialization token at any nesting or
     * escaping depth. False positives only leave a value unchanged.
     */
    private function might_contain_php_serialization(string $value): bool
    {
        return preg_match('/(?:a|s|S|O|C|E):[0-9]+:/', $value) === 1;
    }

    /**
     * Splice exact source bases into the original byte string.
     */
    private function rewrite_literal_url_bases(string $content): string
    {
        $content_length = strlen($content);
        $cursor = 0;
        $output = null;

        while ($cursor < $content_length) {
            $best_position = null;
            $best_source_base = null;
            $best_target_origin = null;

            foreach ($this->literal_mapping as $source_base => $target_origin) {
                $position = strpos($content, $source_base, $cursor);
                while ($position !== false) {
                    // Ambiguous punctuation may be part of another URL. Skip
                    // it instead of risking a partial-base match.
                    $starts_at_boundary = $position === 0;
                    if (!$starts_at_boundary) {
                        $previous_byte = $content[$position - 1];
                        $starts_at_boundary = strpos(" \t\r\n\f\v", $previous_byte) !== false
                            || strpos('"[{<>', $previous_byte) !== false;
                    }

                    $match_end = $position + strlen($source_base);
                    $ends_at_boundary = $match_end === $content_length;
                    if (!$ends_at_boundary) {
                        $next_byte = $content[$match_end];
                        $ends_at_boundary = strpos(" \t\r\n\f\v", $next_byte) !== false
                            || strpos('/?#"[]{}<>', $next_byte) !== false;
                    }

                    if ($starts_at_boundary && $ends_at_boundary) {
                        break;
                    }
                    $position = strpos($content, $source_base, $position + 1);
                }

                if (
                    $position !== false
                    && ( $best_position === null || $position < $best_position )
                ) {
                    $best_position = $position;
                    $best_source_base = $source_base;
                    $best_target_origin = $target_origin;
                }
            }

            if (
                $best_position === null
                || $best_source_base === null
                || $best_target_origin === null
            ) {
                break;
            }

            if ($output === null) {
                $output = '';
            }
            $output .= substr($content, $cursor, $best_position - $cursor)
                . $best_target_origin;
            $cursor = $best_position + strlen($best_source_base);
        }

        return $output === null ? $content : $output . substr($content, $cursor);
    }
}
