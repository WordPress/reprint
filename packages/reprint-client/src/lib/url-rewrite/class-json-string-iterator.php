<?php

/**
 * Cursor-based iterator over raw JSON string-value spans.
 *
 * It validates JSON without constructing a decoded object tree, then walks
 * raw string-value spans. Object keys are skipped; string values are exposed
 * as decoded strings. Changed values are encoded individually, leaving every
 * untouched byte in the enclosing JSON document intact.
 */
class JsonStringIterator
{
    /** @var string The original JSON string. */
    private string $original;

    /** @var int Length of the original JSON string. */
    private int $length;

    /** @var bool Whether parsing succeeded. */
    private bool $valid = false;

    /**
     * Raw string-value spans for the PHP validation fallback, including their
     * surrounding quotes. The native-validation path scans values lazily.
     *
     * @var array<int, array{start: int, length: int, has_escapes: bool}>
     */
    private array $string_spans = [];

    /**
     * Changed string spans. The native-validation path retains only these.
     *
     * @var array<int, array{start: int, length: int, value: string}>
     */
    private array $replacements = [];

    /** @var int Current cursor position. -1 means before the first value. */
    private int $cursor = -1;

    /** @var bool Whether next_value() scans spans after native validation. */
    private bool $scans_string_spans = false;

    /** @var int Raw JSON offset for the next native-validation string scan. */
    private int $next_string_offset = 0;

    /** @var array{start: int, length: int, has_escapes: bool}|null Current native-validation string span. */
    private ?array $current_string_span = null;

    public function __construct(string $json)
    {
        $this->original = $json;
        $this->length = strlen($json);

        $pos = 0;
        $this->skip_whitespace($pos);
        if ($pos === $this->length || !str_contains('"[{', $json[$pos])) {
            return;
        }

        if (function_exists('json_validate')) {
            if (!json_validate($json)) {
                return;
            }

            $this->scans_string_spans = true;
            $this->next_string_offset = $pos;
            $this->valid = true;
            return;
        }

        if (preg_match('//u', $json) !== 1) {
            return;
        }

        if (!$this->parse_value($pos, true)) {
            return;
        }

        $this->skip_whitespace($pos);
        $this->valid = $pos === $this->length;
    }

    /**
     * Whether the input was malformed or was not a JSON container/string.
     */
    public function is_malformed(): bool
    {
        return !$this->valid;
    }

    /**
     * Advance to the next JSON string value.
     */
    public function next_value(): bool
    {
        if (!$this->valid) {
            return false;
        }

        ++$this->cursor;

        if ($this->scans_string_spans) {
            while (true) {
                $span = $this->next_string_span();
                if ($span === null) {
                    return false;
                }

                if ($span['is_key']) {
                    continue;
                }

                $this->current_string_span = [
                    'start'       => $span['start'],
                    'length'      => $span['length'],
                    'has_escapes' => $span['has_escapes'],
                ];
                return true;
            }
        }

        return $this->cursor < count($this->string_spans);
    }

    /**
     * Get the current decoded JSON string value.
     */
    public function get_value(): string
    {
        if (array_key_exists($this->cursor, $this->replacements)) {
            return $this->replacements[$this->cursor]['value'];
        }

        $span = $this->get_current_string_span();
        if (!$span['has_escapes']) {
            return substr($this->original, $span['start'] + 1, $span['length'] - 2);
        }

        $value = json_decode(substr($this->original, $span['start'], $span['length']));

        return is_string($value) ? $value : '';
    }

    /**
     * Replace the current decoded JSON string value.
     */
    public function set_value(string $new_value): void
    {
        $span = $this->get_current_string_span();
        $this->replacements[$this->cursor] = [
            'start'  => $span['start'],
            'length' => $span['length'],
            'value'  => $new_value,
        ];
    }

    /**
     * Return the original JSON with only changed string spans re-encoded.
     */
    public function get_result(): string
    {
        if (count($this->replacements) === 0) {
            return $this->original;
        }

        $result = '';
        $last_end = 0;

        foreach ($this->replacements as $replacement) {
            $result .= substr($this->original, $last_end, $replacement['start'] - $last_end);
            $result .= json_encode($replacement['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $last_end = $replacement['start'] + $replacement['length'];
        }

        return $result . substr($this->original, $last_end);
    }

    /**
     * Parse a JSON value at $pos and record it when it is a string value.
     */
    private function parse_value(int &$pos, bool $is_string_value): bool
    {
        $this->skip_whitespace($pos);
        if ($pos === $this->length) {
            return false;
        }

        switch ($this->original[$pos]) {
            case '"':
                return $this->parse_string($pos, $is_string_value);
            case '{':
                return $this->parse_object($pos);
            case '[':
                return $this->parse_array($pos);
            case 't':
                return $this->parse_literal($pos, 'true');
            case 'f':
                return $this->parse_literal($pos, 'false');
            case 'n':
                return $this->parse_literal($pos, 'null');
            default:
                return $this->parse_number($pos);
        }
    }

    /**
     * Parse a quoted JSON string and optionally record its raw span.
     */
    private function parse_string(int &$pos, bool $is_string_value): bool
    {
        $start = $pos++;

        while ($pos < $this->length) {
            $pos += strcspn($this->original, self::string_terminators(), $pos, $this->length - $pos);
            if ($pos === $this->length) {
                return false;
            }

            $char = $this->original[$pos++];

            if ($char === '"') {
                if ($is_string_value) {
                    $this->string_spans[] = [
                        'start'       => $start,
                        'length'      => $pos - $start,
                        'has_escapes' => true,
                    ];
                }

                return true;
            }

            if (ord($char) < 0x20) {
                return false;
            }

            if ($char !== '\\') {
                continue;
            }

            if ($pos === $this->length) {
                return false;
            }

            $escape = $this->original[$pos++];
            if (str_contains('"\\/bfnrt', $escape)) {
                continue;
            }

            if ($escape !== 'u' || !$this->parse_unicode_escape($pos)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Scan one string span after json_validate() has confirmed the document.
     *
     * A string followed by a colon is an object key; every other string is a
     * value. No structural parsing is needed after native validation.
     */
    private function next_string_span(): ?array
    {
        $pos = $this->next_string_offset;
        $pos += strcspn($this->original, '"', $pos, $this->length - $pos);
        if ($pos === $this->length) {
            return null;
        }

        $start = $pos++;
        $has_escapes = false;
        while (true) {
            $pos += strcspn($this->original, '"\\', $pos, $this->length - $pos);
            $char = $this->original[$pos++];
            if ($char === '"') {
                break;
            }

            $has_escapes = true;
            $escape = $this->original[$pos++];
            if ($escape === 'u') {
                $pos += 4;
            }
        }

        $string_end = $pos;
        $this->skip_whitespace($pos);
        $this->next_string_offset = $pos;

        return [
            'start'       => $start,
            'length'      => $string_end - $start,
            'has_escapes' => $has_escapes,
            'is_key'      => $pos < $this->length && $this->original[$pos] === ':',
        ];
    }

    /**
     * Parse one Unicode escape, including a required low-surrogate escape.
     */
    private function parse_unicode_escape(int &$pos): bool
    {
        $code_point = $this->read_unicode_code_point($pos);
        if ($code_point === -1) {
            return false;
        }

        if ($code_point >= 0xDC00 && $code_point <= 0xDFFF) {
            return false;
        }

        if ($code_point < 0xD800 || $code_point > 0xDBFF) {
            return true;
        }

        if ($this->length - $pos < 6 || $this->original[$pos] !== '\\' || $this->original[$pos + 1] !== 'u') {
            return false;
        }

        $pos += 2;
        $low_surrogate = $this->read_unicode_code_point($pos);

        return $low_surrogate >= 0xDC00 && $low_surrogate <= 0xDFFF;
    }

    /**
     * Read four hexadecimal digits from a JSON Unicode escape.
     */
    private function read_unicode_code_point(int &$pos): int
    {
        if ($this->length - $pos < 4) {
            return -1;
        }

        $code_point = 0;
        for ($offset = 0; $offset < 4; ++$offset) {
            $byte = ord($this->original[$pos + $offset]);
            if ($byte >= ord('0') && $byte <= ord('9')) {
                $digit = $byte - ord('0');
            } elseif ($byte >= ord('a') && $byte <= ord('f')) {
                $digit = $byte - ord('a') + 10;
            } elseif ($byte >= ord('A') && $byte <= ord('F')) {
                $digit = $byte - ord('A') + 10;
            } else {
                return -1;
            }

            $code_point = $code_point * 16 + $digit;
        }

        $pos += 4;
        return $code_point;
    }

    /**
     * Parse an object and skip its string keys.
     */
    private function parse_object(int &$pos): bool
    {
        ++$pos;
        $this->skip_whitespace($pos);

        if ($pos < $this->length && $this->original[$pos] === '}') {
            ++$pos;
            return true;
        }

        while (true) {
            if (!$this->parse_string($pos, false)) {
                return false;
            }

            $this->skip_whitespace($pos);
            if (!$this->consume($pos, ':') || !$this->parse_value($pos, true)) {
                return false;
            }

            $this->skip_whitespace($pos);
            if ($this->consume($pos, '}')) {
                return true;
            }
            if (!$this->consume($pos, ',')) {
                return false;
            }

            $this->skip_whitespace($pos);
            if ($pos === $this->length) {
                return false;
            }
        }
    }

    /**
     * Parse an array and record any string elements.
     */
    private function parse_array(int &$pos): bool
    {
        ++$pos;
        $this->skip_whitespace($pos);

        if ($pos < $this->length && $this->original[$pos] === ']') {
            ++$pos;
            return true;
        }

        while (true) {
            if (!$this->parse_value($pos, true)) {
                return false;
            }

            $this->skip_whitespace($pos);
            if ($this->consume($pos, ']')) {
                return true;
            }
            if (!$this->consume($pos, ',')) {
                return false;
            }

            $this->skip_whitespace($pos);
            if ($pos === $this->length) {
                return false;
            }
        }
    }

    /**
     * Parse a JSON literal.
     */
    private function parse_literal(int &$pos, string $literal): bool
    {
        $literal_length = strlen($literal);
        if (
            $this->length - $pos < $literal_length
            || substr_compare($this->original, $literal, $pos, $literal_length) !== 0
        ) {
            return false;
        }

        $pos += $literal_length;
        return true;
    }

    /**
     * Parse a JSON number.
     */
    private function parse_number(int &$pos): bool
    {
        if ($this->original[$pos] === '-') {
            ++$pos;
            if ($pos === $this->length) {
                return false;
            }
        }

        if ($this->original[$pos] === '0') {
            ++$pos;
        } elseif (strspn($this->original, '123456789', $pos, 1) === 1) {
            $pos += strspn($this->original, '0123456789', $pos, $this->length - $pos);
        } else {
            return false;
        }

        if ($pos < $this->length && $this->original[$pos] === '.') {
            ++$pos;
            $digits = strspn($this->original, '0123456789', $pos, $this->length - $pos);
            if ($digits === 0) {
                return false;
            }

            $pos += $digits;
        }

        if ($pos < $this->length && ( $this->original[$pos] === 'e' || $this->original[$pos] === 'E' )) {
            ++$pos;
            if ($pos < $this->length && str_contains('+-', $this->original[$pos])) {
                ++$pos;
            }

            $digits = strspn($this->original, '0123456789', $pos, $this->length - $pos);
            if ($digits === 0) {
                return false;
            }

            $pos += $digits;
        }

        return true;
    }

    /**
     * Skip JSON whitespace.
     */
    private function skip_whitespace(int &$pos): void
    {
        $pos += strspn($this->original, " \t\r\n", $pos, $this->length - $pos);
    }

    /**
     * Consume $expected at $pos.
     */
    private function consume(int &$pos, string $expected): bool
    {
        if ($pos === $this->length || $this->original[$pos] !== $expected) {
            return false;
        }

        ++$pos;
        return true;
    }

    /**
     * Return the current raw string span for either iterator path.
     *
     * @return array{start: int, length: int, has_escapes: bool}
     */
    private function get_current_string_span(): array
    {
        if ($this->scans_string_spans && $this->current_string_span !== null) {
            return $this->current_string_span;
        }

        return $this->string_spans[$this->cursor];
    }

    /**
     * Return bytes which terminate a raw JSON string run.
     */
    private static function string_terminators(): string
    {
        static $terminators = null;

        if ($terminators === null) {
            $terminators = '"\\' . implode('', array_map('chr', range(0, 31)));
        }

        return $terminators;
    }
}
