<?php

/**
 * Builds SQLite prepared INSERT statements for MySQLDumpProducer-shaped SQL.
 *
 * This is deliberately narrower than SqlStatementRewriter: it only accepts
 * INSERT statements that FastInsertScanner fully recognises and that contain
 * at least one FROM_BASE64(...) expression. Every tuple value is replaced with
 * a positional placeholder (integer-looking numeric literals use
 * CAST(? AS NUMERIC), dotted/exponent literals use CAST(? AS REAL)), so repeated
 * dump batches for the same table/column/row-count shape compile to the same
 * SQLite SQL template. Values that do not match the producer shape return null
 * so db-apply can fall back to the normal MySQL-on-SQLite execution path.
 *
 * The important property is that decoded payload bytes never become SQL text.
 * That avoids quoting, escaping, UTF-8, and "garbage codepoint" questions: the
 * SQL parser sees only `?`, and SQLite receives the exact PHP string through
 * PDO binding. Numeric literals stay numeric through SQLite's NUMERIC cast,
 * including large values that cannot be represented as PHP integers.
 */
class SQLitePreparedInsertBuilder
{
    private const TEMPLATE_CACHE_MAX = 256;

    /** @var array<string, string> */
    private static array $template_sql_cache = [];

    /** @var string[] */
    private static array $template_sql_cache_order = [];

    /**
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array|null {
     *     SQLite prepared statement, or null for an unsupported statement shape.
     *
     *     @type string $sql         SQL with placeholders.
     *     @type array  $params      Decoded parameter values.
     *     @type array  $param_types SQLite parameter types.
     * }
     * @phpstan-return array{sql: string, params: list<mixed>, param_types: list<int>}|null
     */
    public static function build(string $sql, ?callable $rewrite_value = null): ?array
    {
        if (strpos($sql, 'FROM_BASE64(') === false) {
            return null;
        }

        return self::build_with_fast_insert_scanner($sql, $rewrite_value);
    }

    /**
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array|null {
     *     SQLite prepared statement, or null for an unsupported statement shape.
     *
     *     @type string $sql         SQL with placeholders.
     *     @type array  $params      Decoded parameter values.
     *     @type array  $param_types SQLite parameter types.
     * }
     * @phpstan-return array{sql: string, params: list<mixed>, param_types: list<int>}|null
     */
    private static function build_with_fast_insert_scanner(string $sql, ?callable $rewrite_value): ?array
    {
        $fast = FastInsertScanner::scan($sql, false, false);
        if ($fast === null || empty($fast['has_base64']) || empty($fast['value_entries'])) {
            return null;
        }

        $column_count = count($fast['columns']);
        $value_count = count($fast['value_entries']);
        if ($column_count === 0 || $value_count === 0 || $value_count % $column_count !== 0) {
            return null;
        }

        // Describe the prepared SQL template, not the concrete bound values.
        // The cache key must change when the generated SQL text changes, but
        // must stay stable across batches that only differ by decoded payloads
        // or numeric literal values.
        $shape_value_codes = '';
        $params = [];
        $param_types = [];
        foreach ($fast['value_entries'] as $entry) {
            switch ($entry['kind']) {
                case 'null':
                    // NULL, empty strings, and decoded base64 payloads all compile
                    // to the same bare placeholder; their values are supplied later.
                    $shape_value_codes .= 's';
                    $params[] = null;
                    $param_types[] = PDO::PARAM_NULL;
                    break;

                case 'empty_string':
                    $shape_value_codes .= 's';
                    $params[] = '';
                    $param_types[] = PDO::PARAM_STR;
                    break;

                case 'numeric':
                    // Numeric values are bound, not embedded. Only SQLite's target
                    // storage class affects the template: dotted/exponent literals
                    // need REAL while integer-looking literals use NUMERIC.
                    $shape_value_codes .= strpbrk($entry['raw'], '.eE') === false ? 'i' : 'r';
                    $params[] = $entry['raw'];
                    $param_types[] = PDO::PARAM_STR;
                    break;

                case 'base64':
                    $shape_value_codes .= 's';
                    $decoded = base64_decode($entry['encoded_value'], true);
                    if ($decoded === false) {
                        return null;
                    }
                    $value = $decoded;
                    if ($rewrite_value !== null) {
                        $value = $rewrite_value($value, $fast['table'], $entry['column']);
                    }
                    $params[] = $value;
                    $param_types[] = PDO::PARAM_STR;
                    break;

                default:
                    return null;
            }
        }

        $shape_key = self::compact_shape_key($fast['table'], $fast['columns'], $shape_value_codes);
        $template_sql = self::$template_sql_cache[$shape_key] ?? null;
        if ($template_sql === null) {
            // Cache misses are the only time we build SQL text. Payload bytes
            // and numeric literals still never become SQL; this only emits
            // identifiers and placeholders for the producer-recognised shape.
            // Preserve one reusable SQL string per table/column/value-shape so
            // PDO and SQLite can reuse the compiled statement across dump rows.
            $template_sql = self::template_sql_from_shape($fast['table'], $fast['columns'], $shape_value_codes);

            self::$template_sql_cache[$shape_key] = $template_sql;
            self::$template_sql_cache_order[] = $shape_key;
            if (count(self::$template_sql_cache_order) > self::TEMPLATE_CACHE_MAX) {
                // Bound cache growth: large imports can touch many plugin
                // tables, but old shapes are cheap to rebuild if seen again.
                $oldest_key = array_shift(self::$template_sql_cache_order);
                if (is_string($oldest_key)) {
                    unset(self::$template_sql_cache[$oldest_key]);
                }
            }
        }

        return [
            'sql' => $template_sql,
            'params' => $params,
            'param_types' => $param_types,
        ];
    }

    /**
     * @param list<string> $columns
     */
    private static function compact_shape_key(string $table, array $columns, string $shape_value_codes): string
    {
        $key = 'compact|' . strlen($table) . ':' . $table . '|' . count($columns);
        foreach ($columns as $column) {
            $key .= '|' . strlen($column) . ':' . $column;
        }
        return $key . '|' . $shape_value_codes;
    }

    /**
     * @param list<string> $columns
     */
    private static function template_sql_from_shape(string $table, array $columns, string $shape_value_codes): string
    {
        $quoted_columns = [];
        foreach ($columns as $column) {
            $quoted_columns[] = self::quote_identifier($column);
        }

        $column_count = count($columns);
        $value_count = strlen($shape_value_codes);
        $rows = [];
        for ($offset = 0; $offset < $value_count; $offset += $column_count) {
            $placeholders = [];
            for ($i = 0; $i < $column_count; $i++) {
                $code = $shape_value_codes[$offset + $i];
                if ($code === 'i') {
                    $placeholders[] = 'CAST(? AS NUMERIC)';
                } elseif ($code === 'r') {
                    $placeholders[] = 'CAST(? AS REAL)';
                } else {
                    $placeholders[] = '?';
                }
            }
            $rows[] = '(' . implode(', ', $placeholders) . ')';
        }

        return 'INSERT INTO '
            . self::quote_identifier($table)
            . ' (' . implode(', ', $quoted_columns) . ') VALUES'
            . implode(',', $rows)
            . ';';
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

}
