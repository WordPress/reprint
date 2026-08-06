<?php

/**
 * Cursor-based iterator over string leaf values in a JSON value.
 *
 * Mirrors the PhpSerializationProcessor API: construct with the raw JSON,
 * then loop with next_value()/get_value()/set_value(). Call get_result()
 * to retrieve the possibly modified JSON string.
 *
 * The complete document is validated before iteration. String values are
 * located by byte offsets, while object member names remain structural and
 * are not exposed. Replacements splice only the changed string token into
 * the original document, preserving every unrelated byte.
 */
class JsonStringIterator
{
    private const MAXIMUM_DEPTH = 512;

    /** @var string The original JSON string. */
    private string $original;

    /** @var int Cached byte length of the original JSON string. */
    private int $original_length;

    /** @var bool Whether the complete input is valid JSON. */
    private bool $valid = false;

    /** @var bool Whether changed string content must be safe inside HTML. */
    private bool $escape_html_characters;

    /**
     * Byte locations and structural context for every string value.
     *
     * @var array<int, array{
     *     token_start: int,
     *     token_end: int,
     *     nesting_depth: int,
     *     object_key: string|null
     * }>
     */
    private array $bookmarks = [];

    /** @var array<int, string> Decoded original values, populated lazily. */
    private array $decoded_values = [];

    /** @var array<int, string> Replacement decoded values by bookmark index. */
    private array $replacements = [];

    /** @var int Current cursor position. -1 means before the first value. */
    private int $cursor = -1;

    /**
     * Validate and index the JSON document.
     *
     * @param string $json                   The complete JSON value.
     * @param bool   $escape_html_characters Whether replacement characters
     *                                       such as angle brackets and
     *                                       ampersands must be HTML-safe.
     */
    public function __construct(string $json, bool $escape_html_characters = false)
    {
        $this->original = $json;
        $this->original_length = strlen($json);
        $this->escape_html_characters = $escape_html_characters;

        json_decode($json, false, self::MAXIMUM_DEPTH, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        $this->valid = $this->index_string_values();
        if (!$this->valid) {
            $this->bookmarks = [];
        }
    }

    /**
     * Whether the complete input was malformed JSON.
     */
    public function is_malformed(): bool
    {
        return !$this->valid;
    }

    /**
     * Advance to the next string value.
     *
     * @return bool True if there is another value, false otherwise.
     */
    public function next_value(): bool
    {
        if (!$this->valid) {
            return false;
        }

        ++$this->cursor;
        return $this->cursor < count($this->bookmarks);
    }

    /**
     * Get the current decoded string value.
     *
     * Must only be called after next_value() returns true.
     */
    public function get_value(): string
    {
        if (array_key_exists($this->cursor, $this->replacements)) {
            return $this->replacements[$this->cursor];
        }

        return $this->get_original_value($this->cursor);
    }

    /**
     * Get the current value's container nesting depth.
     *
     * A direct member of the root object has depth 1. A root string scalar
     * has depth 0.
     */
    public function get_current_nesting_depth(): int
    {
        return $this->bookmarks[$this->cursor]['nesting_depth'];
    }

    /**
     * Get the current value's object member name, if it has one.
     */
    public function get_current_object_key(): ?string
    {
        return $this->bookmarks[$this->cursor]['object_key'];
    }

    /**
     * Replace the current decoded string value.
     *
     * Must only be called after next_value() returns true.
     *
     * @throws InvalidArgumentException When the replacement is not valid UTF-8.
     */
    public function set_value(string $new_value): void
    {
        if ($new_value === $this->get_original_value($this->cursor)) {
            unset($this->replacements[$this->cursor]);
            return;
        }

        if (json_encode($new_value) === false) {
            throw new InvalidArgumentException('The replacement JSON string must contain valid UTF-8.');
        }

        $this->replacements[$this->cursor] = $new_value;
    }

    /**
     * Return the JSON with changed string tokens spliced into the original.
     */
    public function get_result(): string
    {
        if ($this->replacements === []) {
            return $this->original;
        }

        $result = '';
        $last_token_end = 0;
        ksort($this->replacements);

        foreach ($this->replacements as $index => $new_value) {
            $bookmark = $this->bookmarks[$index];
            $result .= substr(
                $this->original,
                $last_token_end,
                $bookmark['token_start'] - $last_token_end
            );
            $result .= $this->encode_replacement_token($index, $new_value);
            $last_token_end = $bookmark['token_end'];
        }

        return $result . substr($this->original, $last_token_end);
    }

    /**
     * Locate JSON string tokens and bookmark values rather than member names.
     */
    private function index_string_values(): bool
    {
        $position = 0;
        $nesting_depth = 0;
        $container_types = [];
        $pending_object_keys = [];

        while ($position < $this->original_length) {
            $byte = $this->original[$position];

            if ($byte === '{') {
                ++$nesting_depth;
                $container_types[$nesting_depth] = 'object';
                ++$position;
                continue;
            }

            if ($byte === '[') {
                ++$nesting_depth;
                $container_types[$nesting_depth] = 'array';
                ++$position;
                continue;
            }

            if ($byte === '}' || $byte === ']') {
                unset(
                    $container_types[$nesting_depth],
                    $pending_object_keys[$nesting_depth]
                );
                --$nesting_depth;
                ++$position;
                continue;
            }

            if ($byte !== '"') {
                ++$position;
                continue;
            }

            $token_start = $position;
            $token_end = $token_start + 1;
            while ($token_end < $this->original_length) {
                if ($this->original[$token_end] === '"') {
                    ++$token_end;
                    break;
                }

                if ($this->original[$token_end] === '\\') {
                    $token_end += 2;
                    continue;
                }

                ++$token_end;
            }
            if (
                $token_end > $this->original_length
                || $this->original[$token_end - 1] !== '"'
            ) {
                return false;
            }

            $next_position = $token_end;
            while ($next_position < $this->original_length) {
                $next_byte = $this->original[$next_position];
                if (
                    $next_byte !== ' '
                    && $next_byte !== "\t"
                    && $next_byte !== "\n"
                    && $next_byte !== "\r"
                ) {
                    break;
                }
                ++$next_position;
            }

            if (
                $next_position < $this->original_length
                && $this->original[$next_position] === ':'
            ) {
                $object_key = $this->decode_string_token($token_start, $token_end);
                if ($object_key === null) {
                    return false;
                }
                $pending_object_keys[$nesting_depth] = $object_key;
            } else {
                $this->bookmarks[] = [
                    'token_start' => $token_start,
                    'token_end' => $token_end,
                    'nesting_depth' => $nesting_depth,
                    'object_key' => ( $container_types[$nesting_depth] ?? null ) === 'object'
                        ? ( $pending_object_keys[$nesting_depth] ?? null )
                        : null,
                ];
                unset($pending_object_keys[$nesting_depth]);
            }

            $position = $token_end;
        }

        return true;
    }

    /**
     * Decode one validated JSON string token.
     */
    private function decode_string_token(int $token_start, int $token_end): ?string
    {
        $decoded_value = json_decode(
            substr($this->original, $token_start, $token_end - $token_start)
        );

        return json_last_error() === JSON_ERROR_NONE && is_string($decoded_value)
            ? $decoded_value
            : null;
    }

    /**
     * Decode and cache one original string value.
     */
    private function get_original_value(int $index): string
    {
        if (array_key_exists($index, $this->decoded_values)) {
            return $this->decoded_values[$index];
        }

        $bookmark = $this->bookmarks[$index];
        $decoded_value = $this->decode_string_token(
            $bookmark['token_start'],
            $bookmark['token_end']
        );
        if ($decoded_value === null) {
            throw new LogicException('A validated JSON string token could not be decoded.');
        }

        $this->decoded_values[$index] = $decoded_value;
        return $decoded_value;
    }

    /**
     * Encode a changed token while retaining unchanged prefix and suffix bytes.
     */
    private function encode_replacement_token(int $index, string $new_value): string
    {
        if ($this->escape_html_characters) {
            $encoded_value = json_encode($new_value, JSON_HEX_TAG | JSON_HEX_AMP);
            if (!is_string($encoded_value)) {
                throw new LogicException('A validated replacement JSON string could not be encoded.');
            }
            return $encoded_value;
        }

        $bookmark = $this->bookmarks[$index];
        $original_value = $this->get_original_value($index);
        $original_length = strlen($original_value);
        $new_length = strlen($new_value);
        $common_prefix_length = 0;
        while (
            $common_prefix_length < $original_length
            && $common_prefix_length < $new_length
        ) {
            $original_character_length = $this->get_utf8_character_length(
                $original_value,
                $common_prefix_length
            );
            $new_character_length = $this->get_utf8_character_length(
                $new_value,
                $common_prefix_length
            );
            if (
                $original_character_length !== $new_character_length
                || substr(
                    $original_value,
                    $common_prefix_length,
                    $original_character_length
                ) !== substr(
                    $new_value,
                    $common_prefix_length,
                    $new_character_length
                )
            ) {
                break;
            }
            $common_prefix_length += $original_character_length;
        }

        $original_suffix_start = $original_length;
        $new_suffix_start = $new_length;
        while (
            $original_suffix_start > $common_prefix_length
            && $new_suffix_start > $common_prefix_length
        ) {
            $original_character_start = $original_suffix_start - 1;
            while (
                $original_character_start > 0
                && ( ord($original_value[$original_character_start]) & 0xC0 ) === 0x80
            ) {
                --$original_character_start;
            }
            $new_character_start = $new_suffix_start - 1;
            while (
                $new_character_start > 0
                && ( ord($new_value[$new_character_start]) & 0xC0 ) === 0x80
            ) {
                --$new_character_start;
            }
            if (
                $original_character_start < $common_prefix_length
                || $new_character_start < $common_prefix_length
                || substr(
                    $original_value,
                    $original_character_start,
                    $original_suffix_start - $original_character_start
                ) !== substr(
                    $new_value,
                    $new_character_start,
                    $new_suffix_start - $new_character_start
                )
            ) {
                break;
            }

            $original_suffix_start = $original_character_start;
            $new_suffix_start = $new_character_start;
        }

        $raw_prefix_end = $this->get_raw_offset_for_decoded_offset(
            $bookmark,
            $common_prefix_length
        );
        $raw_suffix_start = $this->get_raw_offset_for_decoded_offset(
            $bookmark,
            $original_suffix_start
        );
        $new_middle = substr(
            $new_value,
            $common_prefix_length,
            $new_suffix_start - $common_prefix_length
        );

        $encoding_flags = 0;
        $original_token = substr(
            $this->original,
            $bookmark['token_start'],
            $bookmark['token_end'] - $bookmark['token_start']
        );
        if (strpos($original_token, '\\/') === false) {
            $encoding_flags |= JSON_UNESCAPED_SLASHES;
        }
        if (strpos($original_token, '\\u') === false) {
            $encoding_flags |= JSON_UNESCAPED_UNICODE;
        }
        $encoded_middle = json_encode($new_middle, $encoding_flags);
        if (!is_string($encoded_middle)) {
            throw new LogicException('A validated replacement JSON string could not be encoded.');
        }
        $encoded_middle = substr($encoded_middle, 1, -1);

        return substr(
            $this->original,
            $bookmark['token_start'],
            $raw_prefix_end - $bookmark['token_start']
        )
            . $encoded_middle
            . substr(
                $this->original,
                $raw_suffix_start,
                $bookmark['token_end'] - $raw_suffix_start
            );
    }

    /**
     * Translate a decoded string offset back to its original token byte offset.
     *
     * @param array{token_start: int, token_end: int, nesting_depth: int, object_key: string|null} $bookmark
     */
    private function get_raw_offset_for_decoded_offset(array $bookmark, int $target_offset): int
    {
        $raw_position = $bookmark['token_start'] + 1;
        $raw_end = $bookmark['token_end'] - 1;
        $decoded_position = 0;

        while ($decoded_position < $target_offset && $raw_position < $raw_end) {
            if ($this->original[$raw_position] !== '\\') {
                $character_length = $this->get_utf8_character_length(
                    $this->original,
                    $raw_position
                );
                $raw_position += $character_length;
                $decoded_position += $character_length;
                continue;
            }

            if ($this->original[$raw_position + 1] !== 'u') {
                $raw_position += 2;
                ++$decoded_position;
                continue;
            }

            $code_unit = hexdec(substr($this->original, $raw_position + 2, 4));
            if ($code_unit >= 0xD800 && $code_unit <= 0xDBFF) {
                $raw_position += 12;
                $decoded_position += 4;
                continue;
            }

            $raw_position += 6;
            if ($code_unit <= 0x7F) {
                ++$decoded_position;
            } elseif ($code_unit <= 0x7FF) {
                $decoded_position += 2;
            } else {
                $decoded_position += 3;
            }
        }

        if ($decoded_position !== $target_offset) {
            throw new LogicException('A decoded JSON string offset did not align with a character boundary.');
        }

        return $raw_position;
    }

    /**
     * Return the byte length of the UTF-8 character at an offset.
     */
    private function get_utf8_character_length(string $value, int $position): int
    {
        $first_byte = ord($value[$position]);
        if ($first_byte < 0x80) {
            return 1;
        }
        if ($first_byte < 0xE0) {
            return 2;
        }
        if ($first_byte < 0xF0) {
            return 3;
        }
        return 4;
    }
}
