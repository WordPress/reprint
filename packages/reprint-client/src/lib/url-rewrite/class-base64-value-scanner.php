<?php

/**
 * Cursor-based processor that iterates over FROM_BASE64('...') values in a SQL
 * statement, letting the caller read and replace each decoded value.
 *
 * Uses WP_MySQL_Lexer for proper tokenization instead of string scanning.
 * Detects CONVERT(FROM_BASE64('...') USING utf8mb4) wrappers automatically —
 * set_value() only replaces the base64 payload inside the quotes, so wrappers
 * are preserved without the caller needing to know about them.
 *
 * Values are decoded lazily. The scanner keeps the original encoded payload
 * until get_value() is called, so callers can skip obviously irrelevant values
 * without paying base64_decode() for every FROM_BASE64() expression in a large
 * INSERT batch.
 *
 * Usage:
 *     $scanner = new Base64ValueScanner($sql);
 *     while ($scanner->next_value()) {
 *         $decoded = $scanner->get_value();
 *         $rewritten = do_something($decoded);
 *         if ($rewritten !== $decoded) {
 *             $scanner->set_value($rewritten);
 *         }
 *     }
 *     $new_sql = $scanner->get_result();
 */
class Base64ValueScanner
{
    private string $sql;

    /**
     * Each entry tracks one FROM_BASE64() value found in the SQL:
     *   'expr_start'   => int    Offset of the outermost expression (CONVERT or FROM_BASE64)
     *   'quote_start'  => int    Offset of the quoted string token (including quotes)
     *   'quote_length' => int    Length of the quoted string token
     *   'encoded_value'=> string The base64 payload
     *   'value'        => ?string The base64-decoded value, cached on demand
     *   'new_value'    => ?string Non-null when set_value() has been called
     *
     * @var array<int, array{expr_start: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>
     */
    private array $entries = [];

    private int $cursor = -1;
    private bool $dirty = false;

    /**
     * @param string $sql The SQL statement.
     * @param WP_MySQL_Token[]|null $tokens Optional pre-lexed token list. When
     *   provided, the scanner walks these tokens instead of running its own
     *   WP_MySQL_Lexer pass. Callers that already lex the statement for
     *   another reason (column-map extraction, etc.) can hand the same token
     *   stream in to avoid a redundant tokenization. When null, behavior is
     *   unchanged: the scanner lexes internally.
     */
    public function __construct(string $sql, ?array $tokens = null)
    {
        $this->sql = $sql;
        if ($tokens === null) {
            $this->scan();
        } else {
            $this->scan_tokens($tokens);
        }
    }

    /**
     * Build a scanner from a pre-built entry list. Used by the
     * tokenization-free FastInsertScanner path — when the caller has
     * already located every FROM_BASE64() expression and captured its payload,
     * the scanner skips the lexer and still decodes values lazily.
     *
     * @param array $entries {
     *     FROM_BASE64() entries captured by the fast path.
     *
     *     @type int         $expr_start    Byte offset where the expression starts.
     *     @type int         $quote_start   Byte offset where the quoted payload starts.
     *     @type int         $quote_length  Quoted payload length in bytes.
     *     @type string      $encoded_value Base64 payload.
     *     @type string|null $value         Decoded value, when already decoded.
     *     @type string|null $new_value     Rewritten value, when already set.
     * }
     * @phpstan-param list<array{
     *     expr_start: int,
     *     quote_start: int,
     *     quote_length: int,
     *     encoded_value: string,
     *     value: ?string,
     *     new_value: ?string
     * }> $entries
     */
    public static function from_entries(string $sql, array $entries): self
    {
        $instance = new self($sql, []); // empty tokens = no scanning
        $instance->entries = $entries;
        return $instance;
    }

    /**
     * Advance to the next FROM_BASE64() value.
     */
    public function next_value(): bool
    {
        $this->cursor++;
        return $this->cursor < count($this->entries);
    }

    /**
     * Get the decoded value at the current cursor position.
     */
    public function get_value(): string
    {
        if ($this->entries[$this->cursor]['value'] === null) {
            $decoded = base64_decode($this->entries[$this->cursor]['encoded_value'], true);
            $this->entries[$this->cursor]['value'] = $decoded !== false ? $decoded : '';
        }

        return $this->entries[$this->cursor]['value'];
    }

    /**
     * Return whether the current encoded payload could decode to a value
     * containing an http:// or https:// scheme.
     *
     * This is a conservative base64 prefilter over the encoded text, not proof
     * that the decoded value contains a URL. false means the payload cannot
     * decode to a lowercase http/https scheme under the checked alignments.
     * true only means it could, so the caller still needs to decode and inspect
     * the value. It mirrors the statement-level prefilter in SqlStatementRewriter,
     * but applies it per payload so one URL-bearing column does not force every
     * neighboring FROM_BASE64() value in the same INSERT batch through
     * base64_decode().
     */
    public function encoded_payload_could_contain_http_scheme(): bool
    {
        $value = $this->entries[$this->cursor]['value'];
        if ($value !== null) {
            return strpos($value, 'http') !== false;
        }

        return self::encoded_payload_could_decode_to_http_scheme($this->entries[$this->cursor]['encoded_value']);
    }

    /**
     * Replace the decoded value at the current cursor position.
     * The new value will be base64-encoded when get_result() rebuilds the SQL.
     */
    public function set_value(string $new_value): void
    {
        $this->entries[$this->cursor]['new_value'] = $new_value;
        $this->dirty = true;
    }

    /**
     * Get the byte offset of the outermost expression for the current value.
     * This is the start of CONVERT(...) if present, otherwise FROM_BASE64(...).
     *
     * SqlStatementRewriter uses this to determine which column a value belongs
     * to by scanning backward through the SQL from this position.
     */
    public function get_match_offset(): int
    {
        return $this->entries[$this->cursor]['expr_start'];
    }

    /**
     * Return the SQL with all set_value() replacements applied.
     * Values that were not modified via set_value() are left unchanged.
     */
    public function get_result(): string
    {
        if (!$this->dirty) {
            return $this->sql;
        }

        $parts = [];
        $cursor = 0;
        foreach ($this->entries as $entry) {
            if ($entry['new_value'] !== null) {
                $replacement = "'" . base64_encode($entry['new_value']) . "'";
                $parts[] = substr($this->sql, $cursor, $entry['quote_start'] - $cursor);
                $parts[] = $replacement;
                $cursor = $entry['quote_start'] + $entry['quote_length'];
            }
        }
        $parts[] = substr($this->sql, $cursor);

        return implode('', $parts);
    }

    /**
     * Tokenize the SQL and find all FROM_BASE64('...') expressions.
     * Tracks whether each is wrapped in CONVERT(...) for correct expr_start offset.
     */
    private function scan(): void
    {
        $lexer = new WP_MySQL_Lexer($this->sql);

        // Track the last two tokens so we can detect CONVERT( before FROM_BASE64.
        // next_token() skips whitespace/comments, so CONVERT ( FROM_BASE64 appears
        // as three consecutive tokens.
        $prev = [null, null];

        while ($lexer->next_token()) {
            $token = $lexer->get_token();

            if (
                $token->id === WP_MySQL_Lexer::IDENTIFIER
                && strtoupper($token->get_value()) === 'FROM_BASE64'
            ) {
                $expr_start = $token->start;

                // If the previous tokens are CONVERT + (, the outer expression
                // starts at CONVERT, not at FROM_BASE64.
                if (
                    $prev[1] !== null
                    && $prev[1]->id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL
                    && $prev[0] !== null
                    && $prev[0]->id === WP_MySQL_Lexer::CONVERT_SYMBOL
                ) {
                    $expr_start = $prev[0]->start;
                }

                // Advance past ( to find the quoted base64 string
                while ($lexer->next_token()) {
                    $inner = $lexer->get_token();
                    if (
                        $inner->id === WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                        || $inner->id === WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
                    ) {
                        $this->entries[] = [
                            'expr_start' => $expr_start,
                            'quote_start' => $inner->start,
                            'quote_length' => $inner->length,
                            'encoded_value' => $inner->get_value(),
                            'value' => null,
                            'new_value' => null,
                        ];
                        break;
                    }
                    // Skip the opening parenthesis of FROM_BASE64(
                    if ($inner->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                        break;
                    }
                }
            }

            // Shift the two-token window
            $prev[0] = $prev[1];
            $prev[1] = $token;
        }
    }

    /**
     * Walk a pre-lexed token list to find FROM_BASE64('…') expressions.
     *
     * Equivalent to scan() but reuses tokens already produced by another
     * pass (typically SqlStatementRewriter, which lexes for the column
     * map). Avoids re-running WP_MySQL_Lexer on the same statement.
     *
     * The buffered token array from WP_MySQL_Lexer::remaining_tokens()
     * has already been stripped of whitespace and comments, so the
     * CONVERT + ( + FROM_BASE64 pattern still appears as three
     * consecutive tokens.
     *
     * @param WP_MySQL_Token[] $tokens
     */
    private function scan_tokens(array $tokens): void
    {
        $token_count = count($tokens);
        $prev = [null, null];

        for ($i = 0; $i < $token_count; $i++) {
            $token = $tokens[$i];

            if (
                $token->id === WP_MySQL_Lexer::IDENTIFIER
                && strtoupper($token->get_value()) === 'FROM_BASE64'
            ) {
                $expr_start = $token->start;

                if (
                    $prev[1] !== null
                    && $prev[1]->id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL
                    && $prev[0] !== null
                    && $prev[0]->id === WP_MySQL_Lexer::CONVERT_SYMBOL
                ) {
                    $expr_start = $prev[0]->start;
                }

                // Walk forward to find the quoted base64 payload, mirroring
                // scan(): step past the opening parenthesis, accept either
                // SINGLE_ or DOUBLE_QUOTED_TEXT, abort on anything else.
                for ($j = $i + 1; $j < $token_count; $j++) {
                    $inner = $tokens[$j];
                    if (
                        $inner->id === WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                        || $inner->id === WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
                    ) {
                        $this->entries[] = [
                            'expr_start' => $expr_start,
                            'quote_start' => $inner->start,
                            'quote_length' => $inner->length,
                            'encoded_value' => $inner->get_value(),
                            'value' => null,
                            'new_value' => null,
                        ];
                        break;
                    }
                    if ($inner->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                        break;
                    }
                }
            }

            $prev[0] = $prev[1];
            $prev[1] = $token;
        }
    }

    private static function encoded_payload_could_decode_to_http_scheme(string $payload): bool
    {
        return strpos($payload, 'aHR0') !== false
            || strpos($payload, 'dHA6') !== false
            || strpos($payload, 'dHBz') !== false
            || strpos($payload, 'dHRw') !== false;
    }
}
