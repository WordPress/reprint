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

    /** @var string The original serialized data. */
    private $data;

    /** @var int Cached length of the original data. */
    private $data_length;

    /** @var bool Whether the input was malformed. */
    private $malformed = false;

    /**
     * Byte length consumed by one valid serialized value at the start.
     *
     * This remains available when trailing ordinary text makes the complete
     * input malformed. It is null when the first serialized value is itself
     * malformed or unsupported.
     *
     * @var int|null
     */
    private $serialized_prefix_byte_length = null;

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

    /** @var int Number of value slots encountered while parsing. */
    private $parsed_value_count = 0;

    /** @var array<int, bool> Direct values and object aliases available to references. */
    private $referenceable_value_identifiers = [];

    /** @var array<int, bool> Value identifiers which contain objects. */
    private $object_value_identifiers = [];

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

        $this->serialized_prefix_byte_length = $pos;

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
        ++$this->cursor;
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
     * Whether input starts with a token prefix supported by PHP serialization.
     *
     * This is deliberately lexical. A true result claims serialization-shaped
     * input for validation, but does not say that the complete value is valid.
     *
     * @param string $data Input bytes.
     * @return bool True when the first bytes are a supported token prefix.
     */
    public static function has_serialization_token_prefix(string $data): bool
    {
        if (!isset($data[1])) {
            return false;
        }

        if ($data[0] === 'N') {
            return $data[1] === ';';
        }

        return strpos('sidbaOCrRE', $data[0]) !== false
            && $data[1] === ':';
    }

    /**
     * Return the bytes consumed by one valid serialized value at the start.
     *
     * Full-value validation remains available through is_malformed(). A
     * serialized prefix followed by ordinary text therefore has a byte length
     * here while is_malformed() still returns true.
     *
     * @return int|null Consumed bytes, or null when no valid prefix was parsed.
     */
    public function get_serialized_prefix_byte_length(): ?int
    {
        return $this->serialized_prefix_byte_length;
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
        $value_identifier = null;
        if ($is_value) {
            ++$this->parsed_value_count;
            $value_identifier = $this->parsed_value_count;
        }

        $type = $this->data[$pos];
        if (
            $value_identifier !== null
            && $type !== 'r'
            && $type !== 'R'
        ) {
            // Direct values are targetable immediately; r aliases are added after validation.
            $this->referenceable_value_identifiers[$value_identifier] = true;
        }
        if (
            $value_identifier !== null
            && ( $type === 'O' || $type === 'C' || $type === 'E' )
        ) {
            // Register before descending so an object may refer to itself.
            $this->object_value_identifiers[$value_identifier] = true;
        }

        switch ($type) {
            case 's':
                $parsed = $this->parse_string($pos, $is_value);
                break;
            case 'i':
                $parsed = $this->parse_integer($pos);
                break;
            case 'd':
                $parsed = $this->parse_double($pos);
                break;
            case 'b':
                $parsed = $this->parse_boolean($pos);
                break;
            case 'N':
                $parsed = $this->parse_null($pos);
                break;
            case 'a':
                $parsed = $this->parse_array($pos);
                break;
            case 'O':
                $parsed = $this->parse_object($pos);
                break;
            case 'C':
                $parsed = $this->parse_custom($pos);
                break;
            case 'E':
                $parsed = $this->parse_enum($pos);
                break;
            case 'r':
            case 'R':
                $parsed = $this->parse_reference($pos, $value_identifier);
                break;
            default:
                $parsed = false;
                break;
        }

        if (!$parsed && $value_identifier !== null) {
            unset($this->referenceable_value_identifiers[$value_identifier]);
            unset($this->object_value_identifiers[$value_identifier]);
        }

        return $parsed;
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
            ++$pos;
        }
        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $pos += $digit_len;

        if (!isset($this->data[$pos]) || $this->data[$pos] !== ';') {
            return false;
        }
        ++$pos; // skip ';'

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

        // Read and validate the exact float spellings emitted by serialize().
        $span = strcspn($this->data, ';', $pos);
        if ($span === 0 || $pos + $span >= $this->data_length) {
            return false;
        }
        $number = substr($this->data, $pos, $span);
        if ($number !== 'NAN'
            && $number !== 'INF'
            && $number !== '-INF'
            && preg_match('/\A-?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee][+-]?\d+)?\z/', $number) !== 1
        ) {
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
            $key_type = $this->data[$pos] ?? '';
            if (
                ( $key_type === 's' && !$this->parse_string($pos, false) )
                || ( $key_type === 'i' && !$this->parse_integer($pos) )
                || ( $key_type !== 's' && $key_type !== 'i' )
            ) {
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
        ++$pos; // skip '}'

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
        $class_name_start = $pos;
        $pos += $name_len; // skip class name

        if (
            !$this->is_valid_class_identifier($class_name_start, $name_len)
            || $this->data[$pos] !== '"'
            || $this->data[$pos + 1] !== ':'
        ) {
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
            if (( $this->data[$pos] ?? '' ) !== 's' || !$this->parse_string($pos, false)) {
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
        ++$pos; // skip '}'

        return true;
    }

    /**
     * Parse a reference: r:N; or R:N;
     *
     * r:N; is a value reference, R:N; is a pointer reference.
     * Both are passed through unchanged.
     */
    private function parse_reference(int &$pos, ?int $value_identifier): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $reference_type = $this->data[$pos];
        $pos += 2; // skip 'r:' or 'R:'

        $digit_len = strspn($this->data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $reference_identifier = (int) substr($this->data, $pos, $digit_len);
        if (
            !isset($this->referenceable_value_identifiers[$reference_identifier])
            || (
                $reference_type === 'r'
                && !isset($this->object_value_identifiers[$reference_identifier])
            )
        ) {
            return false;
        }
        $pos += $digit_len;

        if (!isset($this->data[$pos]) || $this->data[$pos] !== ';') {
            return false;
        }
        ++$pos; // skip ';'

        if ($reference_type === 'r' && $value_identifier !== null) {
            $this->referenceable_value_identifiers[$value_identifier] = true;
            $this->object_value_identifiers[$value_identifier] = true;
        }

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
        $class_name_start = $pos;
        $pos += $name_len; // skip class name

        if (
            !$this->is_valid_class_identifier($class_name_start, $name_len)
            || $this->data[$pos] !== '"'
            || $this->data[$pos + 1] !== ':'
        ) {
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
        ++$pos; // skip '}'

        return true;
    }

    /**
     * Validate the lexical class-name bytes accepted in serialized objects.
     *
     * Class lookup is deliberately excluded. Data for an unloaded class is
     * still valid serialization and must remain parseable.
     */
    private function is_valid_class_identifier(int $start, int $length): bool
    {
        if ($length === 0 || $this->data[$start] === '\\') {
            return false;
        }

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $this->data[$start + $offset];
            $byte = ord($character);
            if (
                $byte >= 128
                || ( $byte >= 48 && $byte <= 57 )
                || ( $byte >= 65 && $byte <= 90 )
                || ( $byte >= 97 && $byte <= 122 )
                || $character === '\\'
                || $character === '_'
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Parse an enum case: E:N:"EnumClass:Case";.
     *
     * The enum identifier is structural metadata. It is validated by its
     * declared byte length and preserved opaquely rather than exposed as a
     * rewritable string value.
     */
    private function parse_enum(int &$pos): bool
    {
        if (!isset($this->data[$pos + 1]) || $this->data[$pos] !== 'E' || $this->data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'E:'

        $digit_length = strspn($this->data, self::DIGITS, $pos);
        if ($digit_length === 0) {
            return false;
        }
        $identifier_length = (int) substr($this->data, $pos, $digit_length);
        $pos += $digit_length;

        if (
            $pos + $identifier_length + 4 > $this->data_length
            || $this->data[$pos] !== ':'
            || $this->data[$pos + 1] !== '"'
        ) {
            return false;
        }
        $pos += 2; // skip ':"'

        if (
            $identifier_length < 3
            || $this->data[$pos] === ':'
            || $this->data[$pos + $identifier_length - 1] === ':'
            || substr_count($this->data, ':', $pos, $identifier_length) !== 1
        ) {
            return false;
        }
        $pos += $identifier_length;

        if ($this->data[$pos] !== '"' || $this->data[$pos + 1] !== ';') {
            return false;
        }
        $pos += 2; // skip '";'

        return true;
    }
}
