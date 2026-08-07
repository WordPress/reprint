<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;

/**
 * Exposes the exact source span claimed by BlockMarkupProcessor.
 *
 * The upstream processor keeps token offsets protected. Bookmarks are its
 * public mechanism for retaining an exact token boundary without decoding or
 * rebuilding the token.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Internal class loaded explicitly.
class BlockMarkupSpanProcessor extends BlockMarkupProcessor {

    private const URL_REWRITE_BOOKMARK = 'structured-url-rewrite-token';

    /**
     * Return the exact byte span of the current token.
     *
     * @return array|null {
     *     Current token span, or null when no concrete token is selected.
     *
     *     @type int $start  Zero-based byte offset.
     *     @type int $length Byte length.
     * }
     * @phpstan-return array{start: int, length: int}|null
     */
    public function get_current_token_span(): ?array
    {
        if (!$this->set_bookmark(self::URL_REWRITE_BOOKMARK)) {
            return null;
        }

        $span = $this->bookmarks[self::URL_REWRITE_BOOKMARK];
        $this->release_bookmark(self::URL_REWRITE_BOOKMARK);

        return [
            'start' => $span->start,
            'length' => $span->length,
        ];
    }

    /**
     * Return exact value spans for attributes in the current opening tag.
     *
     * The block processor has already established the tag boundary. This
     * lexical pass mirrors the HTML tokenizer's attribute delimiters inside
     * that boundary and never decodes or rebuilds the tag.
     *
     * @return array<string, array{start: int, length: int, quote: string}>|null
     *     Attribute spans keyed by lowercase name, or null when the token is
     *     not a complete opening tag.
     */
    public function get_current_attribute_value_spans(): ?array
    {
        if ($this->get_token_type() !== '#tag' || $this->is_tag_closer()) {
            return null;
        }

        $token_span = $this->get_current_token_span();
        if ($token_span === null) {
            return null;
        }

        $token = substr($this->html, $token_span['start'], $token_span['length']);
        $token_length = strlen($token);
        if ($token_length < 3 || $token[0] !== '<') {
            return null;
        }

        $position = 1;
        $tag_name_length = strcspn($token, "/> \t\f\r\n", $position);
        if ($tag_name_length === 0) {
            return null;
        }
        $position += $tag_name_length;
        $attributes = [];

        while ($position < $token_length) {
            $position += strspn($token, " \t\f\r\n/", $position);
            if ($position >= $token_length || $token[$position] === '>') {
                return $attributes;
            }

            $name_start = $position;
            $name_length = strcspn($token, "=/> \t\f\r\n", $position);
            if ($name_length === 0) {
                return null;
            }
            $name = strtolower(substr($token, $name_start, $name_length));
            $position += $name_length;
            $position += strspn($token, " \t\f\r\n", $position);

            if ($position >= $token_length) {
                return null;
            }
            if ($token[$position] !== '=') {
                if (!isset($attributes[$name])) {
                    $attributes[$name] = [
                        'start' => $token_span['start'] + $position,
                        'length' => 0,
                        'quote' => '',
                    ];
                }
                continue;
            }

            ++$position;
            $position += strspn($token, " \t\f\r\n", $position);
            if ($position >= $token_length) {
                return null;
            }

            $quote = '';
            if ($token[$position] === '"' || $token[$position] === "'") {
                $quote = $token[$position];
                $value_start = ++$position;
                $value_end = strpos($token, $quote, $value_start);
                if ($value_end === false) {
                    return null;
                }
                $position = $value_end + 1;
            } else {
                $value_start = $position;
                $value_length = strcspn($token, "> \t\f\r\n", $value_start);
                $value_end = $value_start + $value_length;
                $position = $value_end;
            }

            if (!isset($attributes[$name])) {
                $attributes[$name] = [
                    'start' => $token_span['start'] + $value_start,
                    'length' => $value_end - $value_start,
                    'quote' => $quote,
                ];
            }
        }

        return null;
    }
}
