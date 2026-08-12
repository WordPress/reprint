<?php

/**
 * Parses PHP serialized data at the byte level, exposing string values
 * through a cursor-based API inspired by WordPress's WP_HTML_Tag_Processor.
 *
 * This avoids unserialize() entirely — no object instantiation, no __wakeup
 * side effects, no class dependency issues. The parser reads exactly N bytes
 * per s:N: prefix (never scans for a closing quote), so embedded quotes,
 * semicolons, and null bytes are handled correctly.
 *
 * Only string VALUES are exposed — not array keys or object property names.
 * This matches WordPress search-replace semantics.
 *
 * Usage:
 *
 *     $p = new PhpSerializationProcessor($serialized);
 *     while ($p->next_value()) {
 *         $p->set_value(str_replace('old', 'new', $p->get_value()));
 *     }
 *     if ($p->is_malformed()) {
 *         // handle error — input was not valid serialized PHP
 *     }
 *     $result = $p->get_updated_serialization();
 */
class PhpSerializationProcessor
{
    private const DIGITS = '0123456789';

    /**
     * Return whether the first $bytes could be the prefix of a serialized
     * value accepted by this processor.
     *
     * The prefix may stop inside a token, a byte-counted string, or an open
     * array/object. Bytes after the prefix are deliberately not inspected.
     */
    public static function is_valid_prefix(string $serialized, int $bytes = 100): bool
    {
        $length = min(strlen($serialized), $bytes);
        if ($length === 0) {
            return true;
        }

        $pos = 0;
        $parse_value = null;

        $expect = static function (string $expected) use (&$serialized, &$pos, $length): int {
            if ($pos === $length) {
                return 0;
            }

            $available = min(strlen($expected), $length - $pos);
            if (substr_compare($serialized, $expected, $pos, $available) !== 0) {
                return -1;
            }

            if ($available < strlen($expected)) {
                $pos = $length;
                return 0;
            }

            $pos += $available;
            return 1;
        };

        $parse_count = static function () use (&$serialized, &$pos, $length): array {
            if ($pos === $length) {
                return [0, 0];
            }

            $digits = strspn($serialized, self::DIGITS, $pos, $length - $pos);
            if ($digits === 0) {
                return [-1, 0];
            }

            $count = 0;
            for ($offset = 0; $offset < $digits; ++$offset) {
                $digit = ord($serialized[$pos + $offset]) - ord('0');
                if ($count > intdiv(PHP_INT_MAX - $digit, 10)) {
                    return [-1, 0];
                }
                $count = $count * 10 + $digit;
            }

            $pos += $digits;
            return [1, $count];
        };

        $parse_string = static function () use (&$serialized, &$pos, $length, $expect, $parse_count): int {
            $status = $expect('s:');
            if ($status !== 1) {
                return $status;
            }

            [$status, $byte_length] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            $status = $expect(':"');
            if ($status !== 1) {
                return $status;
            }

            if ($byte_length > $length - $pos) {
                $pos = $length;
                return 0;
            }

            $pos += $byte_length;
            return $expect('";');
        };

        $parse_integer = static function () use (&$serialized, &$pos, $length, $expect): int {
            $status = $expect('i:');
            if ($status !== 1) {
                return $status;
            }

            if ($pos === $length) {
                return 0;
            }

            if ($serialized[$pos] === '-') {
                ++$pos;
                if ($pos === $length) {
                    return 0;
                }
            }

            $digits = strspn($serialized, self::DIGITS, $pos, $length - $pos);
            if ($digits === 0) {
                return -1;
            }

            $pos += $digits;
            return $expect(';');
        };

        $parse_double = static function () use (&$serialized, &$pos, $length, $expect): int {
            $status = $expect('d:');
            if ($status !== 1) {
                return $status;
            }

            $span = strcspn($serialized, ';', $pos, $length - $pos);
            if ($span === 0) {
                return $pos === $length ? 0 : -1;
            }

            $pos += $span;
            return $expect(';');
        };

        $parse_boolean = static function () use (&$serialized, &$pos, $length, $expect): int {
            $status = $expect('b:');
            if ($status !== 1) {
                return $status;
            }

            if ($pos === $length) {
                return 0;
            }
            if ($serialized[$pos] !== '0' && $serialized[$pos] !== '1') {
                return -1;
            }

            ++$pos;
            return $expect(';');
        };

        $parse_reference = static function () use (&$serialized, &$pos, $length, $expect, $parse_count): int {
            ++$pos;
            $status = $expect(':');
            if ($status !== 1) {
                return $status;
            }

            [$status] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            return $expect(';');
        };

        $parse_array = null;
        $parse_object = null;
        $parse_custom = null;

        $parse_array = function () use (&$parse_value, &$serialized, &$pos, $length, $expect, $parse_count): int {
            $status = $expect('a:');
            if ($status !== 1) {
                return $status;
            }

            [$status, $count] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            $status = $expect(':{');
            if ($status !== 1) {
                return $status;
            }

            for ($entry = 0; $entry < $count; ++$entry) {
                $status = $parse_value();
                if ($status !== 1) {
                    return $status;
                }

                $status = $parse_value();
                if ($status !== 1) {
                    return $status;
                }
            }

            return $expect('}');
        };

        $parse_object = function () use (&$parse_value, &$serialized, &$pos, $length, $expect, $parse_count): int {
            $status = $expect('O:');
            if ($status !== 1) {
                return $status;
            }

            [$status, $class_name_length] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            $status = $expect(':"');
            if ($status !== 1) {
                return $status;
            }

            if ($class_name_length > $length - $pos) {
                $pos = $length;
                return 0;
            }

            $pos += $class_name_length;
            $status = $expect('":');
            if ($status !== 1) {
                return $status;
            }

            [$status, $property_count] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            $status = $expect(':{');
            if ($status !== 1) {
                return $status;
            }

            for ($property = 0; $property < $property_count; ++$property) {
                $status = $parse_value();
                if ($status !== 1) {
                    return $status;
                }

                $status = $parse_value();
                if ($status !== 1) {
                    return $status;
                }
            }

            return $expect('}');
        };

        $parse_custom = static function () use (&$serialized, &$pos, $length, $expect, $parse_count): int {
            $status = $expect('C:');
            if ($status !== 1) {
                return $status;
            }

            [$status, $class_name_length] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            $status = $expect(':"');
            if ($status !== 1) {
                return $status;
            }

            if ($class_name_length > $length - $pos) {
                $pos = $length;
                return 0;
            }

            $pos += $class_name_length;
            $status = $expect('":');
            if ($status !== 1) {
                return $status;
            }

            [$status, $payload_length] = $parse_count();
            if ($status !== 1) {
                return $status;
            }

            $status = $expect(':{');
            if ($status !== 1) {
                return $status;
            }

            if ($payload_length > $length - $pos) {
                $pos = $length;
                return 0;
            }

            $pos += $payload_length;
            return $expect('}');
        };

        $parse_value = function () use (&$serialized, &$pos, $length, $parse_string, $parse_integer, $parse_double, $parse_boolean, $expect, $parse_array, $parse_object, $parse_custom, $parse_reference): int {
            if ($pos === $length) {
                return 0;
            }

            switch ($serialized[$pos]) {
                case 's':
                    return $parse_string();
                case 'i':
                    return $parse_integer();
                case 'd':
                    return $parse_double();
                case 'b':
                    return $parse_boolean();
                case 'N':
                    return $expect('N;');
                case 'a':
                    return $parse_array();
                case 'O':
                    return $parse_object();
                case 'C':
                    return $parse_custom();
                case 'r':
                case 'R':
                    return $parse_reference();
                default:
                    return -1;
            }
        };

        $status = $parse_value();
        return $status !== -1 && ( $status === 0 || $pos === $length );
    }

    /** @var string The original serialized data. */
    private $data;

    /** @var int Cached length of the original data. */
    private $data_length;

    /** @var bool Whether the input was malformed. */
    private $malformed = false;

    /**
     * Bookmarks recording the position of each string value in the input.
     *
     * Each entry records the byte offsets needed to locate and replace the
     * original s:N:"value"; fragment:
     * - prefix_start: byte offset of the 's' in s:N:"..."
     * - value_start:  byte offset of the first byte of string content (after the opening ")
     * - value_length: original byte length of the string content
     *
     * The full s:N:"value"; fragment spans from prefix_start to value_start + value_length + 2,
     * where +2 accounts for the closing ";.
     *
     * @var array<int, array{prefix_start: int, value_start: int, value_length: int}>
     */
    private $bookmarks = [];

    /**
     * Replacement values indexed by bookmark index.
     *
     * Only populated for values where set_value() was called.
     *
     * @var array<int, string>
     */
    private $replacements = [];

    /** @var int Current cursor position. -1 means before the first value. */
    private $cursor = -1;

    /**
     * Parse the input upfront, recording the position of every string value.
     *
     * The constructor validates the entire serialized structure. If parsing
     * fails at any point, is_malformed() will return true and next_value()
     * will immediately return false.
     *
     * @param string $serialized The PHP serialized data.
     */
    public function __construct(string $serialized)
    {
        $this->data = $serialized;
        $this->data_length = strlen($serialized);

        if ($this->data_length === 0) {
            $this->malformed = true;
            return;
        }

        $pos = 0;
        if (!$this->parse_value($pos, true)) {
            $this->malformed = true;
            return;
        }

        // If there's trailing data, the input is malformed
        if ($pos !== $this->data_length) {
            $this->malformed = true;
        }
    }

    /**
     * Advance to the next string value in the serialized data.
     *
     * @return bool True if there is another value, false if iteration is
     *              complete or input was malformed.
     */
    public function next_value(): bool
    {
        if ($this->malformed) {
            return false;
        }
        $this->cursor++;
        return $this->cursor < count($this->bookmarks);
    }

    /**
     * Get the current string value.
     *
     * Must only be called after next_value() returns true.
     *
     * @return string The string value at the current cursor position.
     */
    public function get_value(): string
    {
        $bm = $this->bookmarks[$this->cursor];
        return substr($this->data, $bm['value_start'], $bm['value_length']);
    }

    /**
     * Replace the current string value. The s:N: byte-length prefix will
     * be recalculated automatically in get_updated_serialization().
     *
     * Must only be called after next_value() returns true.
     *
     * @param string $new_value The replacement string.
     * @return bool True if the replacement was recorded.
     */
    public function set_value(string $new_value): bool
    {
        $this->replacements[$this->cursor] = $new_value;
        return true;
    }

    /**
     * Whether the serialized input was malformed.
     *
     * @return bool True if the parser encountered invalid serialized data.
     */
    public function is_malformed(): bool
    {
        return $this->malformed;
    }

    /**
     * Reconstruct the serialized string with all replacements applied
     * and s:N: byte-length prefixes recalculated.
     *
     * Unchanged values are copied verbatim from the original input —
     * when no values are modified, the output is byte-identical to the input.
     *
     * @return string The updated serialized string.
     */
    public function get_updated_serialization(): string
    {
        if (empty($this->replacements)) {
            return $this->data;
        }

        $result = '';
        $last_end = 0;

        // Process only bookmarks that have replacements, in order.
        // Non-replaced regions are copied verbatim from the original.
        ksort($this->replacements);
        foreach ($this->replacements as $index => $new_value) {
            $bm = $this->bookmarks[$index];

            // Copy everything from last_end to the start of this s:N:"value";
            $result .= substr($this->data, $last_end, $bm['prefix_start'] - $last_end);

            // Write the new s:N:"value"; with recalculated byte-length prefix
            $result .= 's:' . strlen($new_value) . ':"' . $new_value . '";';

            // Advance past the original s:N:"value";
            // +2 accounts for the closing ";
            $last_end = $bm['value_start'] + $bm['value_length'] + 2;
        }

        // Copy any remaining data after the last replacement
        $result .= substr($this->data, $last_end);

        return $result;
    }

    // -----------------------------------------------------------------
    // Internal parsing methods
    //
    // These validate the serialized structure and record bookmark
    // positions for string values. They do NOT build output strings —
    // that's done by get_updated_serialization().
    // -----------------------------------------------------------------

    /**
     * Dispatch on the type character at the current position.
     *
     * @param int  &$pos     Current byte offset (updated in place).
     * @param bool $is_value Whether this position is a value (true) or
     *                       a key/property name (false).
     * @return bool True if parsing succeeded.
     */
    private function parse_value(int &$pos, bool $is_value): bool
    {
        if (!isset($this->data[$pos])) {
            return false;
        }

        switch ($this->data[$pos]) {
            case 's':
                return $this->parse_string($pos, $is_value);
            case 'i':
                return $this->parse_integer($pos);
            case 'd':
                return $this->parse_double($pos);
            case 'b':
                return $this->parse_boolean($pos);
            case 'N':
                return $this->parse_null($pos);
            case 'a':
                return $this->parse_array($pos);
            case 'O':
                return $this->parse_object($pos);
            case 'C':
                return $this->parse_custom($pos);
            case 'r':
            case 'R':
                return $this->parse_reference($pos);
            default:
                return false;
        }
    }

    /**
     * Parse a string value: s:N:"...";
     *
     * Reads exactly N bytes after the opening quote — never scans for a
     * closing quote. This correctly handles strings containing quotes,
     * semicolons, null bytes, and other special characters.
     *
     * When $is_value is true, records a bookmark so the string is exposed
     * through the cursor API. Keys and property names are not bookmarked.
     */
    private function parse_string(int &$pos, bool $is_value): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 's' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $prefix_start = $pos;
        $pos += 2; // skip 's:'

        // Read the byte-length number using strspn for fast digit scanning
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $byte_length = (int) substr($this->data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :" then exactly $byte_length bytes then ";
        // Minimum remaining: 2 (:" ) + $byte_length + 2 (";) = $byte_length + 4
        if ($pos + $byte_length + 4 > $this->data_length || $this->data[$pos] !== ':' || $this->data[$pos + 1] !== '"') {
            return false;
        }
        $pos += 2; // skip ':"'

        $value_start = $pos;
        $pos += $byte_length;

        if ($this->data[$pos] !== '"' || $this->data[$pos + 1] !== ';') {
            return false;
        }
        $pos += 2; // skip '";'

        // Record a bookmark for string values (not for keys/property names)
        if ($is_value) {
            $this->bookmarks[] = [
                'prefix_start' => $prefix_start,
                'value_start' => $value_start,
                'value_length' => $byte_length,
            ];
        }

        return true;
    }

    /**
     * Parse an integer: i:N;
     */
    private function parse_integer(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'i' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'i:'

        // Optional leading minus
        if (isset($this->data[$pos]) && $this->data[$pos] === '-') {
            $pos++;
        }
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $pos += $digit_len;

        if (!isset($this->data[$pos]) || $this->data[$pos] !== ';') {
            return false;
        }
        $pos++; // skip ';'

        return true;
    }

    /**
     * Parse a double/float: d:N;
     * Handles integers, decimals, scientific notation, INF, -INF, NAN.
     */
    private function parse_double(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'd' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'd:'

        // Read until semicolon — PHP serialize can produce various float representations
        $span = strcspn($this->data, ';', $pos);
        if ($span === 0 || $pos + $span >= $this->data_length) {
            return false;
        }
        $pos += $span + 1; // skip value + ';'

        return true;
    }

    /**
     * Parse a boolean: b:0; or b:1;
     */
    private function parse_boolean(int &$pos): bool
    {
        // b:V; — exactly 4 bytes
        if (!isset($this->data[$pos + 3])
            || $this->data[$pos] !== 'b'
            || $this->data[$pos + 1] !== ':'
            || ($this->data[$pos + 2] !== '0' && $this->data[$pos + 2] !== '1')
            || $this->data[$pos + 3] !== ';'
        ) {
            return false;
        }
        $pos += 4;
        return true;
    }

    /**
     * Parse null: N;
     */
    private function parse_null(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'N' || $this->data[$pos + 1] !== ';') {
            return false;
        }
        $pos += 2;
        return true;
    }

    /**
     * Parse an array: a:N:{key;value;...}
     *
     * Keys are parsed with is_value=false (they're structural, not content).
     * Values are parsed with is_value=true so they get bookmarked.
     */
    private function parse_array(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'a' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'a:'

        // Read element count
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $count = (int) substr($this->data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :{
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== ':' || $this->data[$pos + 1] !== '{') {
            return false;
        }
        $pos += 2; // skip ':{'

        for ($i = 0; $i < $count; $i++) {
            // Parse key (string or integer, not a value)
            if (!$this->parse_value($pos, false)) {
                return false;
            }

            // Parse value (with bookmark)
            if (!$this->parse_value($pos, true)) {
                return false;
            }
        }

        // Expect closing }
        if (!isset($this->data[$pos]) || $this->data[$pos] !== '}') {
            return false;
        }
        $pos++; // skip '}'

        return true;
    }

    /**
     * Parse an object: O:N:"classname":N:{propname;value;...}
     *
     * Property names are parsed with is_value=false (they're structural).
     * Property values are parsed with is_value=true so they get bookmarked.
     *
     * Private/protected properties use null-byte visibility markers in
     * property names (e.g., \0ClassName\0prop for private). These are
     * preserved correctly because we read by byte count, not by scanning
     * for delimiters.
     */
    private function parse_object(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'O' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'O:'

        // Read class name length
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $name_len = (int) substr($this->data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :" then exactly name_len bytes then ":
        // Minimum remaining: 2 (:" ) + name_len + 2 (":) = name_len + 4
        if ($pos + $name_len + 4 > $this->data_length || $this->data[$pos] !== ':' || $this->data[$pos + 1] !== '"') {
            return false;
        }
        $pos += 2; // skip ':"'
        $pos += $name_len; // skip class name

        if ($this->data[$pos] !== '"' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip '":'

        // Read property count
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $prop_count = (int) substr($this->data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :{
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== ':' || $this->data[$pos + 1] !== '{') {
            return false;
        }
        $pos += 2; // skip ':{'

        for ($i = 0; $i < $prop_count; $i++) {
            // Parse property name (not a value — structural)
            if (!$this->parse_value($pos, false)) {
                return false;
            }

            // Parse property value (with bookmark)
            if (!$this->parse_value($pos, true)) {
                return false;
            }
        }

        // Expect closing }
        if (!isset($this->data[$pos]) || $this->data[$pos] !== '}') {
            return false;
        }
        $pos++; // skip '}'

        return true;
    }

    /**
     * Parse a reference: r:N; or R:N;
     *
     * r:N; is a value reference, R:N; is a pointer reference.
     * Both are passed through unchanged.
     */
    private function parse_reference(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'r:' or 'R:'

        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $pos += $digit_len;

        if (!isset($this->data[$pos]) || $this->data[$pos] !== ';') {
            return false;
        }
        $pos++; // skip ';'

        return true;
    }

    /**
     * Parse a custom serializable object: C:N:"classname":N:{payload}
     *
     * The payload is opaque — we don't know its internal format, so we
     * pass it through unchanged. This handles classes that implement
     * the Serializable interface.
     */
    private function parse_custom(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'C' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'C:'

        // Read class name length
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $name_len = (int) substr($this->data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :" then exactly name_len bytes then ":
        if ($pos + $name_len + 4 > $this->data_length || $this->data[$pos] !== ':' || $this->data[$pos + 1] !== '"') {
            return false;
        }
        $pos += 2; // skip ':"'
        $pos += $name_len; // skip class name

        if ($this->data[$pos] !== '"' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip '":'

        // Read payload length
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $payload_len = (int) substr($this->data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :{ then exactly payload_len bytes then }
        if ($pos + $payload_len + 3 > $this->data_length || $this->data[$pos] !== ':' || $this->data[$pos + 1] !== '{') {
            return false;
        }
        $pos += 2; // skip ':{'
        $pos += $payload_len; // skip payload

        if ($this->data[$pos] !== '}') {
            return false;
        }
        $pos++; // skip '}'

        return true;
    }
}
