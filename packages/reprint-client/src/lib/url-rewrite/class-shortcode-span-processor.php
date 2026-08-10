<?php

/**
 * Finds complete generic shortcode tokens and their attribute value spans.
 *
 * Tag names follow WordPress's shortcode-name restrictions: any non-empty
 * sequence without bytes through ASCII space or shortcode delimiters. Once
 * a token is claimed, incomplete attribute syntax or a missing closing bracket
 * makes the input malformed so callers can leave it untouched.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Internal class loaded explicitly.
class ShortcodeSpanProcessor {
    /** @var string Input bytes. */
    private string $input;

    /** @var bool Whether a claimed shortcode token is incomplete. */
    private bool $malformed = false;

    /** @var bool Whether the first non-whitespace bytes claim a shortcode. */
    private bool $has_shortcode_prefix = false;

    /**
     * @var array<int, array{
     *     start: int,
     *     length: int,
     *     values: array<int, array{start: int, length: int, quoted: bool, named: bool}>
     * }>
     */
    private array $tokens = [];

    public function __construct(string $input)
    {
        $this->input = $input;
        $this->scan();
    }

    public function is_malformed(): bool
    {
        return $this->malformed;
    }

    /**
     * Return whether the input begins with a claimed shortcode token.
     */
    public function has_shortcode_prefix(): bool
    {
        return $this->has_shortcode_prefix;
    }

    /**
     * Return complete shortcode tokens and attribute value spans.
     *
     * @return array<int, array{
     *     start: int,
     *     length: int,
     *     values: array<int, array{start: int, length: int, quoted: bool, named: bool}>
     * }>
     */
    public function get_tokens(): array
    {
        return $this->tokens;
    }

    private function scan(): void
    {
        $input_length = strlen($this->input);
        $first_non_whitespace = strspn($this->input, " \t\f\r\n");
        $search_position = 0;

        while ($search_position < $input_length) {
            $token_start = strpos($this->input, '[', $search_position);
            if ($token_start === false) {
                return;
            }

            $position = $token_start + 1;
            if (( $this->input[$position] ?? '' ) === '[') {
                $search_position = $position + 1;
                continue;
            }

            $is_closing = ( $this->input[$position] ?? '' ) === '/';
            if ($is_closing) {
                ++$position;
            }

            $name_end = $this->find_shortcode_name_end($position);
            if ($name_end === null) {
                $search_position = $token_start + 1;
                continue;
            }

            $position = $name_end;
            if ($token_start === $first_non_whitespace) {
                $this->has_shortcode_prefix = true;
            }

            $token = $this->scan_claimed_token($token_start, $position, $is_closing);
            if ($token === null) {
                $this->malformed = true;
                return;
            }

            $this->tokens[] = $token;
            $search_position = $token['start'] + $token['length'];
        }
    }

    /**
     * Return the first byte after a valid shortcode name.
     */
    private function find_shortcode_name_end(int $position): ?int
    {
        $name_start = $position;
        $input_length = strlen($this->input);

        while ($position < $input_length) {
            $byte = $this->input[$position];
            if ($byte === ']' || $byte === '/' || strpos(" \t\f\r\n", $byte) !== false) {
                break;
            }
            if (ord($byte) <= 0x20 || strpos('<>&[]=', $byte) !== false) {
                return null;
            }
            ++$position;
        }

        if ($position === $name_start || $position >= $input_length) {
            return null;
        }

        return $position;
    }

    /**
     * Scan one token after its name has been claimed.
     *
     * @return array{
     *     start: int,
     *     length: int,
     *     values: array<int, array{start: int, length: int, quoted: bool, named: bool}>
     * }|null
     */
    private function scan_claimed_token(
        int $token_start,
        int $position,
        bool $is_closing
    ): ?array {
        $input_length = strlen($this->input);
        $values = [];

        if ($is_closing) {
            $position += strspn($this->input, " \t\f\r\n", $position);
            if (( $this->input[$position] ?? '' ) !== ']') {
                return null;
            }

            return [
                'start' => $token_start,
                'length' => $position + 1 - $token_start,
                'values' => [],
            ];
        }

        while ($position < $input_length) {
            $position += strspn($this->input, " \t\f\r\n", $position);
            $byte = $this->input[$position] ?? '';
            if ($byte === ']') {
                return [
                    'start' => $token_start,
                    'length' => $position + 1 - $token_start,
                    'values' => $values,
                ];
            }
            if ($byte === '/' && ( $this->input[$position + 1] ?? '' ) === ']') {
                return [
                    'start' => $token_start,
                    'length' => $position + 2 - $token_start,
                    'values' => $values,
                ];
            }
            if ($byte === '' || $byte === '[') {
                return null;
            }

            $attribute_name_length = strspn(
                $this->input,
                'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-',
                $position
            );
            $value_position = $position + $attribute_name_length;
            $value_position += strspn($this->input, " \t\f\r\n", $value_position);
            $is_named_attribute = $attribute_name_length > 0
                && ( $this->input[$value_position] ?? '' ) === '=';
            if ($is_named_attribute) {
                ++$value_position;
                $value_position += strspn($this->input, " \t\f\r\n", $value_position);
            } else {
                $value_position = $position;
            }

            $value = $this->scan_attribute_value($value_position, $is_named_attribute);
            if ($value === null) {
                return null;
            }
            $values[] = $value['span'];
            $position = $value['next'];

            if (
                $value['span']['quoted']
                && ( $this->input[$position] ?? '' ) !== ']'
                && !( ( $this->input[$position] ?? '' ) === '/'
                    && ( $this->input[$position + 1] ?? '' ) === ']' )
                && strpos(" \t\f\r\n", $this->input[$position] ?? '') === false
            ) {
                return null;
            }
        }

        return null;
    }

    /**
     * Scan a quoted or unquoted attribute value from its actual start.
     *
     * @return array{
     *     span: array{start: int, length: int, quoted: bool, named: bool},
     *     next: int
     * }|null
     */
    private function scan_attribute_value(int $position, bool $is_named_attribute): ?array
    {
        $input_length = strlen($this->input);
        if ($position >= $input_length) {
            return null;
        }

        $quote = $this->input[$position];
        if ($quote === '"' || $quote === "'") {
            $value_start = $position + 1;
            $value_end = strpos($this->input, $quote, $value_start);
            if ($value_end === false) {
                return null;
            }

            return [
                'span' => [
                    'start' => $value_start,
                    'length' => $value_end - $value_start,
                    'quoted' => true,
                    'named' => $is_named_attribute,
                ],
                'next' => $value_end + 1,
            ];
        }

        $value_start = $position;
        while ($position < $input_length) {
            $byte = $this->input[$position];
            if ($byte === ']' || strpos(" \t\f\r\n", $byte) !== false) {
                break;
            }
            if ($byte === '[') {
                return null;
            }
            ++$position;
        }

        $value_end = $position;
        if (
            ( $this->input[$position] ?? '' ) === ']'
            && $value_end > $value_start
            && $this->input[$value_end - 1] === '/'
        ) {
            --$value_end;
            $position = $value_end;
        }
        if ($value_end === $value_start) {
            return null;
        }

        return [
            'span' => [
                'start' => $value_start,
                'length' => $value_end - $value_start,
                'quoted' => false,
                'named' => $is_named_attribute,
            ],
            'next' => $position,
        ];
    }
}
