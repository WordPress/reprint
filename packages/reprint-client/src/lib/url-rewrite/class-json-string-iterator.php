<?php

/**
 * Cursor-based iterator over string values in a complete JSON document.
 *
 * The complete document is validated before iteration. String value tokens
 * are bookmarked by byte offset while object member names remain structural.
 * Changed tokens are spliced into the original bytes, so unrelated JSON
 * representation is never rebuilt.
 *
 * Usage:
 *
 *     $iterator = new JsonStringIterator($json);
 *     while ($iterator->next_value()) {
 *         $iterator->set_value(str_replace('old', 'new', $iterator->get_value()));
 *     }
 *     $result = $iterator->get_result();
 */
class JsonStringIterator
{
    private const MAXIMUM_DEPTH = 512;

    /** @var string The original JSON document. */
    private string $original;

    /** @var int Cached byte length of the original JSON document. */
    private int $original_length;

    /** @var bool Whether the complete input is valid JSON. */
    private bool $valid = false;

    /** @var bool Whether changed bytes must be safe in an HTML comment. */
    private bool $escape_html_characters;

    /**
     * Byte spans for JSON string values, excluding object member names.
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

    /**
     * Exact decoded edit spans supplied with a replacement.
     *
     * @var array<int, array<int, array{start: int, length: int, text: string}>>
     */
    private array $replacement_spans = [];

    /** @var int Current cursor position. -1 means before the first value. */
    private int $cursor = -1;

    /**
     * Validate and index a complete JSON document.
     *
     * @param string $json                   The complete JSON document.
     * @param bool   $escape_html_characters Whether changed angle brackets
     *                                       and ampersands must be escaped.
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
     * Get the current value's decoded object member name, when applicable.
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
            unset($this->replacement_spans[$this->cursor]);
            return;
        }

        if (json_encode($new_value) === false) {
            throw new InvalidArgumentException('The replacement JSON string must contain valid UTF-8.');
        }

        $this->replacements[$this->cursor] = $new_value;
        unset($this->replacement_spans[$this->cursor]);
    }

    /**
     * Replace the current value using exact non-overlapping decoded spans.
     *
     * @param array<int, array{start: int, length: int, text: string}> $spans
     *     Ascending edit spans in the original decoded value.
     */
    public function set_value_with_spans(array $spans): void
    {
        $original_value = $this->get_original_value($this->cursor);
        $original_length = strlen($original_value);
        $new_value = '';
        $position = 0;

        foreach ($spans as $span) {
            if (
                $span['start'] < $position
                || $span['length'] < 0
                || $span['start'] + $span['length'] > $original_length
            ) {
                throw new InvalidArgumentException(
                    'JSON replacement spans must be ordered, non-overlapping, and in bounds.'
                );
            }
            $new_value .= substr($original_value, $position, $span['start'] - $position)
                . $span['text'];
            $position = $span['start'] + $span['length'];
        }
        $new_value .= substr($original_value, $position);
        if (json_encode($new_value) === false) {
            throw new InvalidArgumentException('The replacement JSON string must contain valid UTF-8.');
        }
        if ($new_value === $original_value) {
            unset($this->replacements[$this->cursor], $this->replacement_spans[$this->cursor]);
            return;
        }

        $this->replacements[$this->cursor] = $new_value;
        $this->replacement_spans[$this->cursor] = array_values($spans);
    }

    /**
     * Return the JSON document with changed string tokens spliced in.
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
     *
     * json_decode() has already validated the complete grammar, escape
     * sequences, nesting depth, and UTF-8. This scan only records raw spans.
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

            $is_member_name = (
                $next_position < $this->original_length
                && $this->original[$next_position] === ':'
            );
            if ($is_member_name) {
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
     * Encode changed decoded spans while retaining every matched raw span.
     */
    private function encode_replacement_token(int $index, string $new_value): string
    {
        $bookmark = $this->bookmarks[$index];
        $original_value = $this->get_original_value($index);
        $equal_spans = isset($this->replacement_spans[$index])
            ? $this->get_equal_decoded_spans_from_edits(
                $original_value,
                $this->replacement_spans[$index]
            )
            : $this->get_equal_decoded_spans($original_value, $new_value);
        $original_token = substr(
            $this->original,
            $bookmark['token_start'],
            $bookmark['token_end'] - $bookmark['token_start']
        );

        $encoding_flags = 0;
        if (strpos($original_token, '\\/') === false) {
            $encoding_flags |= JSON_UNESCAPED_SLASHES;
        }
        if (strpos($original_token, '\\u') === false) {
            $encoding_flags |= JSON_UNESCAPED_UNICODE;
        }
        if ($this->escape_html_characters) {
            $encoding_flags |= JSON_HEX_TAG | JSON_HEX_AMP;
        }

        $result = '"';
        $new_position = 0;
        foreach ($equal_spans as $span) {
            $result .= $this->encode_json_string_fragment(
                substr($new_value, $new_position, $span['new_start'] - $new_position),
                $encoding_flags
            );
            $raw_start = $this->get_raw_offset_for_decoded_offset(
                $bookmark,
                $span['original_start']
            );
            $raw_end = $this->get_raw_offset_for_decoded_offset(
                $bookmark,
                $span['original_start'] + $span['length']
            );
            $result .= substr($this->original, $raw_start, $raw_end - $raw_start);
            $new_position = $span['new_start'] + $span['length'];
        }
        $result .= $this->encode_json_string_fragment(
            substr($new_value, $new_position),
            $encoding_flags
        );

        return $result . '"';
    }

    /**
     * Convert exact edits into the unchanged decoded spans between them.
     *
     * @param array<int, array{start: int, length: int, text: string}> $edits Exact edits.
     * @return array<int, array{original_start: int, new_start: int, length: int}>
     */
    private function get_equal_decoded_spans_from_edits(string $original, array $edits): array
    {
        $spans = [];
        $original_position = 0;
        $new_position = 0;
        foreach ($edits as $edit) {
            $unchanged_length = $edit['start'] - $original_position;
            if ($unchanged_length > 0) {
                $spans[] = [
                    'original_start' => $original_position,
                    'new_start' => $new_position,
                    'length' => $unchanged_length,
                ];
            }

            $edited_original = substr($original, $edit['start'], $edit['length']);
            foreach ($this->get_equal_decoded_spans($edited_original, $edit['text']) as $span) {
                $spans[] = [
                    'original_start' => $edit['start'] + $span['original_start'],
                    'new_start' => $new_position + $unchanged_length + $span['new_start'],
                    'length' => $span['length'],
                ];
            }

            $original_position = $edit['start'] + $edit['length'];
            $new_position += $unchanged_length + strlen($edit['text']);
        }
        $unchanged_length = strlen($original) - $original_position;
        if ($unchanged_length > 0) {
            $spans[] = [
                'original_start' => $original_position,
                'new_start' => $new_position,
                'length' => $unchanged_length,
            ];
        }

        return $spans;
    }

    /**
     * Encode one decoded JSON string fragment without surrounding quotes.
     */
    private function encode_json_string_fragment(string $value, int $encoding_flags): string
    {
        $encoded = json_encode($value, $encoding_flags);
        if (!is_string($encoded)) {
            throw new LogicException('A validated replacement JSON string could not be encoded.');
        }

        return substr($encoded, 1, -1);
    }

    /**
     * Locate decoded spans shared by the original and replacement strings.
     *
     * @return array<int, array{original_start: int, new_start: int, length: int}>
     *     Shared decoded byte spans.
     */
    private function get_equal_decoded_spans(string $original, string $replacement): array
    {
        [$original_characters, $original_offsets] = $this->split_utf8_characters($original);
        [$replacement_characters, $replacement_offsets] = $this->split_utf8_characters(
            $replacement
        );
        $original_count = count($original_characters);
        $replacement_count = count($replacement_characters);

        $prefix_count = 0;
        while (
            $prefix_count < $original_count
            && $prefix_count < $replacement_count
            && $original_characters[$prefix_count] === $replacement_characters[$prefix_count]
        ) {
            ++$prefix_count;
        }

        $original_suffix_start = $original_count;
        $replacement_suffix_start = $replacement_count;
        while (
            $original_suffix_start > $prefix_count
            && $replacement_suffix_start > $prefix_count
            && $original_characters[$original_suffix_start - 1]
                === $replacement_characters[$replacement_suffix_start - 1]
        ) {
            --$original_suffix_start;
            --$replacement_suffix_start;
        }

        $character_spans = [];
        if ($prefix_count > 0) {
            $character_spans[] = [0, 0, $prefix_count];
        }
        $middle_spans = $this->get_myers_equal_character_spans(
            $original_characters,
            $replacement_characters,
            $prefix_count,
            $original_suffix_start,
            $prefix_count,
            $replacement_suffix_start
        );
        foreach ($middle_spans as $span) {
            $character_spans[] = $span;
        }
        if ($original_suffix_start < $original_count) {
            $character_spans[] = [
                $original_suffix_start,
                $replacement_suffix_start,
                $original_count - $original_suffix_start,
            ];
        }

        $spans = [];
        foreach ($character_spans as $span) {
            $original_start = $original_offsets[$span[0]];
            $new_start = $replacement_offsets[$span[1]];
            $length = $original_offsets[$span[0] + $span[2]] - $original_start;
            $last_index = count($spans) - 1;
            if (
                $last_index >= 0
                && $spans[$last_index]['original_start'] + $spans[$last_index]['length']
                    === $original_start
                && $spans[$last_index]['new_start'] + $spans[$last_index]['length']
                    === $new_start
            ) {
                $spans[$last_index]['length'] += $length;
                continue;
            }
            $spans[] = [
                'original_start' => $original_start,
                'new_start' => $new_start,
                'length' => $length,
            ];
        }

        return $spans;
    }

    /**
     * Find unchanged character spans with a linear-space Myers diff.
     *
     * @param string[] $original Original UTF-8 characters.
     * @param string[] $replacement Replacement UTF-8 characters.
     * @return array<int, array{int, int, int}> Character spans.
     */
    private function get_myers_equal_character_spans(
        array $original,
        array $replacement,
        int $original_start,
        int $original_end,
        int $replacement_start,
        int $replacement_end
    ): array {
        $spans = [];
        $this->append_myers_equal_character_spans(
            $original,
            $replacement,
            $original_start,
            $original_end,
            $replacement_start,
            $replacement_end,
            $spans
        );

        return $spans;
    }

    /**
     * Append one range's unchanged spans without retaining an edit trace.
     *
     * @param string[] $original Original UTF-8 characters.
     * @param string[] $replacement Replacement UTF-8 characters.
     * @param array<int, array{int, int, int}> $spans Accumulated character spans.
     */
    private function append_myers_equal_character_spans(
        array $original,
        array $replacement,
        int $original_start,
        int $original_end,
        int $replacement_start,
        int $replacement_end,
        array &$spans
    ): void {
        $prefix_length = 0;
        while (
            $original_start + $prefix_length < $original_end
            && $replacement_start + $prefix_length < $replacement_end
            && $original[$original_start + $prefix_length]
                === $replacement[$replacement_start + $prefix_length]
        ) {
            ++$prefix_length;
        }
        if ($prefix_length > 0) {
            $spans[] = [$original_start, $replacement_start, $prefix_length];
            $original_start += $prefix_length;
            $replacement_start += $prefix_length;
        }

        $suffix_length = 0;
        while (
            $original_end - $suffix_length > $original_start
            && $replacement_end - $suffix_length > $replacement_start
            && $original[$original_end - $suffix_length - 1]
                === $replacement[$replacement_end - $suffix_length - 1]
        ) {
            ++$suffix_length;
        }
        $original_middle_end = $original_end - $suffix_length;
        $replacement_middle_end = $replacement_end - $suffix_length;
        $original_length = $original_middle_end - $original_start;
        $replacement_length = $replacement_middle_end - $replacement_start;

        if ($original_length === 1) {
            for ($position = $replacement_start; $position < $replacement_middle_end; ++$position) {
                if ($original[$original_start] === $replacement[$position]) {
                    $spans[] = [$original_start, $position, 1];
                    break;
                }
            }
        } elseif ($replacement_length === 1) {
            for ($position = $original_start; $position < $original_middle_end; ++$position) {
                if ($original[$position] === $replacement[$replacement_start]) {
                    $spans[] = [$position, $replacement_start, 1];
                    break;
                }
            }
        } elseif ($original_length > 0 && $replacement_length > 0) {
            $split = $this->find_myers_bisect(
                $original,
                $replacement,
                $original_start,
                $original_middle_end,
                $replacement_start,
                $replacement_middle_end
            );
            if (
                $split === null
                || ( $split[0] === $original_start && $split[1] === $replacement_start )
                || ( $split[0] === $original_middle_end && $split[1] === $replacement_middle_end )
            ) {
                $split = $this->find_lcs_midpoint(
                    $original,
                    $replacement,
                    $original_start,
                    $original_middle_end,
                    $replacement_start,
                    $replacement_middle_end
                );
            }

            $this->append_myers_equal_character_spans(
                $original,
                $replacement,
                $original_start,
                $split[0],
                $replacement_start,
                $split[1],
                $spans
            );
            $this->append_myers_equal_character_spans(
                $original,
                $replacement,
                $split[0],
                $original_middle_end,
                $split[1],
                $replacement_middle_end,
                $spans
            );
        }

        if ($suffix_length > 0) {
            $spans[] = [
                $original_middle_end,
                $replacement_middle_end,
                $suffix_length,
            ];
        }
    }

    /**
     * Find a shortest-edit-path midpoint with forward and reverse frontiers.
     *
     * @param string[] $original Original UTF-8 characters.
     * @param string[] $replacement Replacement UTF-8 characters.
     * @return array{int, int}|null Global original and replacement split positions.
     */
    private function find_myers_bisect(
        array $original,
        array $replacement,
        int $original_start,
        int $original_end,
        int $replacement_start,
        int $replacement_end
    ): ?array {
        $original_length = $original_end - $original_start;
        $replacement_length = $replacement_end - $replacement_start;
        $maximum_distance = intdiv($original_length + $replacement_length + 1, 2);
        $frontier_offset = $maximum_distance + 1;
        $frontier_length = 2 * $maximum_distance + 3;
        $forward = array_fill(0, $frontier_length, -1);
        $reverse = array_fill(0, $frontier_length, -1);
        $forward[$frontier_offset + 1] = 0;
        $reverse[$frontier_offset + 1] = 0;
        $length_delta = $original_length - $replacement_length;
        $check_forward_overlap = ( $length_delta % 2 ) !== 0;
        $forward_start = 0;
        $forward_end = 0;
        $reverse_start = 0;
        $reverse_end = 0;

        for ($distance = 0; $distance <= $maximum_distance; ++$distance) {
            for (
                $diagonal = -$distance + $forward_start;
                $diagonal <= $distance - $forward_end;
                $diagonal += 2
            ) {
                $frontier_index = $frontier_offset + $diagonal;
                if (
                    $diagonal === -$distance
                    || (
                        $diagonal !== $distance
                        && $forward[$frontier_index - 1] < $forward[$frontier_index + 1]
                    )
                ) {
                    $x = $forward[$frontier_index + 1];
                } else {
                    $x = $forward[$frontier_index - 1] + 1;
                }
                $y = $x - $diagonal;
                while (
                    $x < $original_length
                    && $y < $replacement_length
                    && $original[$original_start + $x]
                        === $replacement[$replacement_start + $y]
                ) {
                    ++$x;
                    ++$y;
                }
                $forward[$frontier_index] = $x;

                if ($x > $original_length) {
                    $forward_end += 2;
                } elseif ($y > $replacement_length) {
                    $forward_start += 2;
                } elseif ($check_forward_overlap) {
                    $reverse_index = $frontier_offset + $length_delta - $diagonal;
                    if (
                        $reverse_index >= 0
                        && $reverse_index < $frontier_length
                        && $reverse[$reverse_index] !== -1
                        && $x >= $original_length - $reverse[$reverse_index]
                    ) {
                        return [$original_start + $x, $replacement_start + $y];
                    }
                }
            }

            for (
                $diagonal = -$distance + $reverse_start;
                $diagonal <= $distance - $reverse_end;
                $diagonal += 2
            ) {
                $frontier_index = $frontier_offset + $diagonal;
                if (
                    $diagonal === -$distance
                    || (
                        $diagonal !== $distance
                        && $reverse[$frontier_index - 1] < $reverse[$frontier_index + 1]
                    )
                ) {
                    $x = $reverse[$frontier_index + 1];
                } else {
                    $x = $reverse[$frontier_index - 1] + 1;
                }
                $y = $x - $diagonal;
                while (
                    $x < $original_length
                    && $y < $replacement_length
                    && $original[$original_end - $x - 1]
                        === $replacement[$replacement_end - $y - 1]
                ) {
                    ++$x;
                    ++$y;
                }
                $reverse[$frontier_index] = $x;

                if ($x > $original_length) {
                    $reverse_end += 2;
                } elseif ($y > $replacement_length) {
                    $reverse_start += 2;
                } elseif (!$check_forward_overlap) {
                    $forward_index = $frontier_offset + $length_delta - $diagonal;
                    if (
                        $forward_index >= 0
                        && $forward_index < $frontier_length
                        && $forward[$forward_index] !== -1
                        && $forward[$forward_index] >= $original_length - $x
                    ) {
                        $forward_x = $forward[$forward_index];
                        $forward_diagonal = $forward_index - $frontier_offset;
                        return [
                            $original_start + $forward_x,
                            $replacement_start + $forward_x - $forward_diagonal,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find an optimal progressing split if a Myers path meets at a boundary.
     *
     * @param string[] $original Original UTF-8 characters.
     * @param string[] $replacement Replacement UTF-8 characters.
     * @return array{int, int} Global original and replacement split positions.
     */
    private function find_lcs_midpoint(
        array $original,
        array $replacement,
        int $original_start,
        int $original_end,
        int $replacement_start,
        int $replacement_end
    ): array {
        $original_midpoint = $original_start + intdiv($original_end - $original_start, 2);
        $forward = $this->get_lcs_lengths(
            $original,
            $replacement,
            $original_start,
            $original_midpoint,
            $replacement_start,
            $replacement_end,
            false
        );
        $reverse = $this->get_lcs_lengths(
            $original,
            $replacement,
            $original_midpoint,
            $original_end,
            $replacement_start,
            $replacement_end,
            true
        );
        $replacement_length = $replacement_end - $replacement_start;
        $best_length = -1;
        $replacement_split = 0;
        for ($position = 0; $position <= $replacement_length; ++$position) {
            $length = $forward[$position] + $reverse[$replacement_length - $position];
            if ($length > $best_length) {
                $best_length = $length;
                $replacement_split = $position;
            }
        }

        return [$original_midpoint, $replacement_start + $replacement_split];
    }

    /**
     * Calculate one linear-space LCS row in forward or reverse order.
     *
     * @param string[] $original Original UTF-8 characters.
     * @param string[] $replacement Replacement UTF-8 characters.
     * @return int[] LCS lengths for each replacement prefix.
     */
    private function get_lcs_lengths(
        array $original,
        array $replacement,
        int $original_start,
        int $original_end,
        int $replacement_start,
        int $replacement_end,
        bool $reverse
    ): array {
        $replacement_length = $replacement_end - $replacement_start;
        $previous = array_fill(0, $replacement_length + 1, 0);
        $original_position = $reverse ? $original_end - 1 : $original_start;
        $original_limit = $reverse ? $original_start - 1 : $original_end;
        $original_step = $reverse ? -1 : 1;

        for (
            ;
            $original_position !== $original_limit;
            $original_position += $original_step
        ) {
            $current = [0];
            for ($position = 1; $position <= $replacement_length; ++$position) {
                $replacement_position = $reverse
                    ? $replacement_end - $position
                    : $replacement_start + $position - 1;
                if ($original[$original_position] === $replacement[$replacement_position]) {
                    $current[$position] = $previous[$position - 1] + 1;
                } else {
                    $current[$position] = max($previous[$position], $current[$position - 1]);
                }
            }
            $previous = $current;
        }

        return $previous;
    }

    /**
     * Split valid UTF-8 into characters and byte boundaries.
     *
     * @return array{array<int, string>, array<int, int>}
     */
    private function split_utf8_characters(string $value): array
    {
        $characters = [];
        $offsets = [0];
        $length = strlen($value);
        for ($position = 0; $position < $length;) {
            $character_length = $this->get_utf8_character_length($value, $position);
            $characters[] = substr($value, $position, $character_length);
            $position += $character_length;
            $offsets[] = $position;
        }

        return [$characters, $offsets];
    }

    /**
     * Translate a decoded string offset to its original token byte offset.
     *
     * @param array{
     *     token_start: int,
     *     token_end: int,
     *     nesting_depth: int,
     *     object_key: string|null
     * } $bookmark String token span and context.
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
