<?php

/**
 * Tokenization-free scanner for the INSERT shape that MySQLDumpProducer emits.
 *
 * Recovers the same data SqlStatementRewriter needs from a normal lexer pass —
 * table name, byte-offset → column map, and FROM_BASE64() value entries —
 * using strpos/preg_match against the constrained producer shape:
 *
 *     INSERT INTO `table` (`c1`,`c2`, …) VALUES
 *       (v1, v2, v3, …),
 *       (…),
 *       …;
 *
 * Each value is one of:
 *     NULL
 *     '' (empty string literal)
 *     bare numeric literal (-?123, -?1.5, 1e-3, etc.)
 *     FROM_BASE64('<base64chars>')
 *     CONVERT(FROM_BASE64('<base64chars>') USING utf8mb4)
 *
 * Inside FROM_BASE64() the payload is [A-Za-z0-9+/=]*, so it cannot contain
 * the punctuation that would confuse top-level parsing (',', '(', ')', "'").
 * That's what lets us avoid the full SQL grammar.
 *
 * Anything that doesn't match this shape causes scan() to return null, and
 * the caller falls back to the lexer-based path. INSERT … SELECT, hex/binary
 * literals like x'…', escaped quotes, multi-table UPDATEs, etc. all end up
 * on the lexer path — they're rare in producer output and correctness wins
 * over coverage.
 */
class FastInsertScanner
{
    /**
     * Try to scan a producer-shape INSERT statement.
     *
     * @return array|null {
     *     Producer-shape INSERT scan result, or null when the SQL does not match
     *     the recognised shape.
     *
     *     @type string $table          Table name.
     *     @type array  $columns        Column names in statement order.
     *     @type array  $column_map     Value ranges mapped to column names.
     *     @type bool   $has_base64     Whether any FROM_BASE64() value was found.
     *     @type array  $base64_entries FROM_BASE64() entries captured by the
     *                                  fast path.
     *     @type array  $value_entries  Value entries captured by the fast path.
     * }
     * @phpstan-return array{
     *   table: string,
     *   columns: list<string>,
     *   column_map: list<array{int, int, string}>,
     *   has_base64: bool,
     *   base64_entries: list<array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>,
     *   value_entries: list<array{kind: string, column: string, start?: int, end?: int, raw?: string, expr_start?: int, expr_length?: int, quote_start?: int, quote_length?: int, encoded_value?: string}>
     * }|null
     */
    public static function scan(string $sql, bool $include_column_map = true, bool $include_base64_entries = true): ?array
    {
        // Header: optional leading whitespace, INSERT (no priority/IGNORE
        // modifiers — producer never emits those), INTO, backticked table,
        // backticked column list, VALUES.
        //
        // Captures: 1=table, 2=column-list-body
        if (!preg_match(
            '/\A\s*INSERT\s+INTO\s+`((?:[^`]|``)+)`\s*\(([^)]+)\)\s*VALUES\b/i',
            $sql,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $table = str_replace('``', '`', $m[1][0]);
        $columns_body = $m[2][0];
        $values_end = $m[0][1] + strlen($m[0][0]);

        // Column list: backtick-quoted identifiers separated by commas. Reject
        // anything that doesn't look like producer output — qualified names,
        // unquoted identifiers, comments, etc.
        $columns = [];
        if (
            !preg_match_all(
                '/\s*`((?:[^`]|``)+)`\s*(,|$)/A',
                $columns_body,
                $col_matches,
                PREG_SET_ORDER
            )
        ) {
            return null;
        }
        $offset = 0;
        foreach ($col_matches as $cm) {
            // PREG_SET_ORDER without /A doesn't enforce contiguous matching,
            // but with /A each match must start at $offset. Verify by
            // recomputing offset and confirming we consumed the whole body.
            $columns[] = str_replace('``', '`', $cm[1]);
            $offset += strlen($cm[0]);
        }
        if ($offset !== strlen($columns_body)) {
            return null;
        }
        $column_count = count($columns);
        if ($column_count === 0) {
            return null;
        }

        $column_map = [];
        $base64_entries = [];
        $value_entries = [];
        $has_base64 = false;

        $cursor = $values_end;
        $sql_len = strlen($sql);

        // Walk one tuple at a time. Each tuple is `(v, v, v, …)` followed by
        // either a comma (next tuple) or the statement terminator family
        // ({whitespace} ; | $).
        while (true) {
            // Skip whitespace.
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $sql_len) {
                break;
            }
            // Statement terminator: optional `;` then end-of-string. Anything
            // else (ON DUPLICATE KEY UPDATE, RETURNING, comments, …) drops
            // us out of the fast path.
            if ($sql[$cursor] === ';') {
                $cursor++;
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                if ($cursor !== $sql_len) {
                    return null;
                }
                break;
            }
            if ($sql[$cursor] !== '(') {
                return null;
            }
            $cursor++; // step past '('

            for ($col_idx = 0; $col_idx < $column_count; $col_idx++) {
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                $value_start = $cursor;
                $value_kind = self::scan_value($sql, $sql_len, $cursor, $include_base64_entries);
                if ($value_kind === null) {
                    return null;
                }
                $value_end = $cursor;
                if ($include_column_map) {
                    $column_map[] = [$value_start, $value_end, $columns[$col_idx]];
                    $value_kind['start'] = $value_start;
                    $value_kind['end'] = $value_end;
                }
                $value_kind['column'] = $columns[$col_idx];
                $value_entries[] = $value_kind;

                if ($value_kind['kind'] === 'base64') {
                    $has_base64 = true;
                    if ($include_base64_entries) {
                        $base64_entries[] = [
                            'expr_start' => $value_kind['expr_start'],
                            'expr_length' => $value_kind['expr_length'],
                            'quote_start' => $value_kind['quote_start'],
                            'quote_length' => $value_kind['quote_length'],
                            'encoded_value' => $value_kind['encoded_value'],
                            'value' => null,
                            'new_value' => null,
                        ];
                    }
                }

                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                if ($cursor >= $sql_len) {
                    return null;
                }
                if ($sql[$cursor] === ',') {
                    if ($col_idx === $column_count - 1) {
                        // Trailing comma before close — not producer shape.
                        return null;
                    }
                    $cursor++;
                    continue;
                }
                if ($sql[$cursor] === ')') {
                    if ($col_idx !== $column_count - 1) {
                        // Tuple closed before consuming all columns.
                        return null;
                    }
                    break;
                }
                return null;
            }

            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++; // step past ')'

            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor < $sql_len && $sql[$cursor] === ',') {
                $cursor++;
                continue; // next tuple
            }
            // Either ';' or end-of-string (or whitespace then either) loops back.
        }

        return [
            'table' => $table,
            'columns' => $columns,
            'column_map' => $column_map,
            'has_base64' => $has_base64,
            'base64_entries' => $base64_entries,
            'value_entries' => $value_entries,
        ];
    }

    /**
     * Scan one value in producer's tuple shape, advancing $cursor past it.
     *
     * @return array|null {
     *     Scanned value entry, or null for an unrecognized shape.
     *
     *     @type string $kind          Value kind.
     *     @type string $raw           Optional. Raw SQL value.
     *     @type int    $expr_start    Optional. Expression start byte offset.
     *     @type int    $expr_length   Optional. Expression length in bytes.
     *     @type int    $quote_start   Optional. Quoted payload start byte offset.
     *     @type int    $quote_length  Optional. Quoted payload length in bytes.
     *     @type string $encoded_value Optional. Base64 payload.
     * }
     * @phpstan-return null|array{
     *     kind: string,
     *     raw?: string,
     *     expr_start?: int,
     *     expr_length?: int,
     *     quote_start?: int,
     *     quote_length?: int,
     *     encoded_value?: string
     * }
     */
    private static function scan_value(string $sql, int $sql_len, int &$cursor, bool $include_base64_offsets = true)
    {
        if ($cursor >= $sql_len) {
            return null;
        }
        $c = $sql[$cursor];

        // NULL
        if (
            ($c === 'N' || $c === 'n')
            && $cursor + 4 <= $sql_len
            && substr_compare($sql, 'NULL', $cursor, 4, true) === 0
        ) {
            $cursor += 4;
            return ['kind' => 'null'];
        }

        // Empty string literal
        if ($c === "'" && $cursor + 1 < $sql_len && $sql[$cursor + 1] === "'") {
            $cursor += 2;
            return ['kind' => 'empty_string'];
        }

        // FROM_BASE64('…')
        if (
            ($c === 'F' || $c === 'f')
            && $cursor + 13 <= $sql_len
            && substr_compare($sql, 'FROM_BASE64(', $cursor, 12, true) === 0
        ) {
            $expr_start = $cursor;
            $cursor += 12; // step past "FROM_BASE64("
            $entry = self::consume_base64_call($sql, $sql_len, $cursor, $expr_start);
            if ($entry === null) {
                return null;
            }
            if ($include_base64_offsets) {
                return [
                    'kind' => 'base64',
                    'expr_start' => $entry[0],
                    'expr_length' => $entry[1],
                    'quote_start' => $entry[2],
                    'quote_length' => $entry[3],
                    'encoded_value' => $entry[4],
                ];
            }
            return [
                'kind' => 'base64',
                'encoded_value' => $entry[4],
            ];
        }

        // CONVERT(FROM_BASE64('…') USING utf8mb4)
        if (
            ($c === 'C' || $c === 'c')
            && $cursor + 8 <= $sql_len
            && substr_compare($sql, 'CONVERT(', $cursor, 8, true) === 0
        ) {
            $expr_start = $cursor;
            $cursor += 8;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if (
                $cursor + 12 > $sql_len
                || substr_compare($sql, 'FROM_BASE64(', $cursor, 12, true) !== 0
            ) {
                return null;
            }
            $cursor += 12;
            $entry = self::consume_base64_call($sql, $sql_len, $cursor, $expr_start);
            if ($entry === null) {
                return null;
            }
            // Expect: USING utf8mb4 )
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor + 5 > $sql_len || substr_compare($sql, 'USING', $cursor, 5, true) !== 0) {
                return null;
            }
            $cursor += 5;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor + 7 > $sql_len || substr_compare($sql, 'utf8mb4', $cursor, 7, true) !== 0) {
                return null;
            }
            $cursor += 7;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++;
            $entry[1] = $cursor - $expr_start;
            if ($include_base64_offsets) {
                return [
                    'kind' => 'base64',
                    'expr_start' => $entry[0],
                    'expr_length' => $entry[1],
                    'quote_start' => $entry[2],
                    'quote_length' => $entry[3],
                    'encoded_value' => $entry[4],
                ];
            }
            return [
                'kind' => 'base64',
                'encoded_value' => $entry[4],
            ];
        }

        // Numeric literal: optional sign, digits, optional fraction, optional exponent.
        $start = $cursor;
        if ($c === '+' || $c === '-') {
            $cursor++;
        }
        $digit_start = $cursor;
        while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
            $cursor++;
        }
        if ($cursor < $sql_len && $sql[$cursor] === '.') {
            $cursor++;
            while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
                $cursor++;
            }
        }
        if ($cursor < $sql_len && ($sql[$cursor] === 'e' || $sql[$cursor] === 'E')) {
            $exponent_start = $cursor;
            $cursor++;
            if ($cursor < $sql_len && ($sql[$cursor] === '+' || $sql[$cursor] === '-')) {
                $cursor++;
            }
            $exponent_digit_start = $cursor;
            while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
                $cursor++;
            }
            if ($cursor === $exponent_digit_start) {
                $cursor = $exponent_start;
            }
        }
        if ($cursor === $digit_start) {
            $cursor = $start;
            return null;
        }
        return [
            'kind' => 'numeric',
            'raw' => substr($sql, $start, $cursor - $start),
        ];
    }

    /**
     * Inside a FROM_BASE64( … ) call, with $cursor already past the opening
     * paren. Reads the quoted base64 string and the closing paren. Returns
     * the [expr_start, expr_length, quote_start, quote_length, encoded] tuple.
     *
     * @return array|null {
     *     FROM_BASE64() byte-offset tuple, or null when the call is malformed.
     *
     *     @type int    $0 Expression start byte offset.
     *     @type int    $1 Expression length in bytes.
     *     @type int    $2 Quoted payload start byte offset.
     *     @type int    $3 Quoted payload length in bytes.
     *     @type string $4 Base64 payload.
     * }
     * @phpstan-return array{int,int,int,int,string}|null
     */
    private static function consume_base64_call(string $sql, int $sql_len, int &$cursor, int $expr_start)
    {
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== "'") {
            return null;
        }
        $quote_start = $cursor;
        $cursor++;
        $payload_start = $cursor;
        // Producer payload is base64: [A-Za-z0-9+/=]. Find closing quote via
        // strpos. If anything outside that set appears before it, fall back
        // to the lexer.
        $close = strpos($sql, "'", $cursor);
        if ($close === false) {
            return null;
        }
        $payload = substr($sql, $payload_start, $close - $payload_start);
        // Validate the payload — strict base64 alphabet.
        if ($payload !== '' && !preg_match('/\A[A-Za-z0-9+\/=]*\z/', $payload)) {
            return null;
        }
        $cursor = $close + 1;
        $quote_length = $cursor - $quote_start;
        // The closing paren of FROM_BASE64( … ). CONVERT(...) callers still
        // need this consumed before they can verify the USING charset wrapper.
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
            return null;
        }
        $cursor++;
        return [$expr_start, $cursor - $expr_start, $quote_start, $quote_length, $payload];
    }

    private static function is_ws(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }
}
