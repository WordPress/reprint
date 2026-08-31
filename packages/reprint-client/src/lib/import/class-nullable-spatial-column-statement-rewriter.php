<?php

declare(strict_types=1);

namespace Reprint\Importer;

use PDO;
use Reprint\Importer\Database\DatabaseConnection;
use RuntimeException;
use WordPress\Reprint\Server\MySQLDumpProducer;

/** Rebuilds exporter-marked spatial ALTER statements from the target table definition. */
class NullableSpatialColumnStatementRewriter {

    private DatabaseConnection $database;

    private ?bool $target_is_mariadb = null;

    public function __construct(DatabaseConnection $database)
    {
        $this->database = $database;
    }

    /**
     * Returns a complete target-side ALTER, or null for an ordinary statement.
     *
     * MODIFY COLUMN replaces the complete column definition. The exporter
     * marks its narrow fallback ALTER so this importer can instead reuse the
     * target's definition and remove only NOT NULL.
     */
    public function rewrite(string $sql): ?string
    {
        $marker_prefix = MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX;
        if (strpos($sql, $marker_prefix) !== false) {
            return $this->rewrite_nullable_spatial_alter($sql, $marker_prefix);
        }
        if (
            strpos($sql, MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT) !== false
            && $this->is_mariadb_target()
        ) {
            return $this->replace_marked_zero_byte_values_with_omitted_columns($sql);
        }
        return null;
    }

    /** Rewrites an exporter-marked fallback ALTER. */
    private function rewrite_nullable_spatial_alter(string $sql, string $marker_prefix): ?string
    {
        $tokens = self::significant_tokens($sql, 'marked spatial ALTER TABLE');
        if (
            count($tokens) < 4
            || $tokens[0]->id !== \WP_MySQL_Lexer::ALTER_SYMBOL
            || $tokens[1]->id !== \WP_MySQL_Lexer::TABLE_SYMBOL
            || $tokens[2]->id !== \WP_MySQL_Lexer::BACK_TICK_QUOTED_ID
            || end($tokens)->id !== \WP_MySQL_Lexer::SEMICOLON_SYMBOL
        ) {
            return null;
        }

        $marker_position = strrpos(
            substr($sql, 0, $tokens[0]->start),
            $marker_prefix
        );
        if ($marker_position === false) {
            return null;
        }
        [$table, $columns] = $this->parse_marker($sql, $marker_position);
        if ($tokens[2]->get_value() !== $table) {
            throw new RuntimeException(
                'The marked spatial ALTER TABLE names a different table than its marker.'
            );
        }
        if ($this->is_mariadb_target()) {
            // MariaDB creates the source placeholder when marked values are
            // omitted from the INSERT, so keep the source definition unchanged.
            return 'DO 0;';
        }
        $quoted_table = self::quote_identifier($table);
        $this->database->exec('SET SESSION sql_quote_show_create = 1');
        $result = $this->database->query("SHOW CREATE TABLE {$quoted_table}");
        try {
            $row = $result->fetch(PDO::FETCH_ASSOC);
        } finally {
            $result->closeCursor();
        }
        $create_table_sql = is_array($row) ? ( $row['Create Table'] ?? null ) : null;
        if (!is_string($create_table_sql) || $create_table_sql === '') {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Target schema errors are CLI text.
            throw new RuntimeException(
                "SHOW CREATE TABLE {$quoted_table} returned no usable table definition."
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $column_definitions = $this->get_nullable_column_definitions(
            $create_table_sql,
            $columns
        );
        $this->assert_no_spatial_indexes($quoted_table, $table, $columns);
        return "ALTER TABLE {$quoted_table}\nMODIFY COLUMN " .
            implode(",\nMODIFY COLUMN ", $column_definitions) . ";";
    }

    /** Rewrites a one-row marked INSERT to omit its zero-byte spatial columns. */
    private function replace_marked_zero_byte_values_with_omitted_columns(string $sql): string
    {
        $tokens = self::significant_tokens($sql, 'marked zero-byte spatial value');
        $token_count = count($tokens);
        if (
            $token_count < 7
            || $tokens[0]->id !== \WP_MySQL_Lexer::INSERT_SYMBOL
            || $tokens[1]->id !== \WP_MySQL_Lexer::INTO_SYMBOL
        ) {
            throw new RuntimeException(
                'Cannot rewrite a marked zero-byte spatial value outside an INSERT statement.'
            );
        }
        $cursor = 2;
        $table_token = $tokens[$cursor++];
        if (
            !in_array(
                $table_token->id,
                [\WP_MySQL_Lexer::BACK_TICK_QUOTED_ID, \WP_MySQL_Lexer::IDENTIFIER],
                true
            )
            || $tokens[$cursor++]->id !== \WP_MySQL_Lexer::OPEN_PAR_SYMBOL
        ) {
            throw new RuntimeException('Cannot parse the marked INSERT table and column list.');
        }

        $columns = [];
        while ($cursor < $token_count && $tokens[$cursor]->id !== \WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            $column_token = $tokens[$cursor++];
            if (!in_array(
                $column_token->id,
                [\WP_MySQL_Lexer::BACK_TICK_QUOTED_ID, \WP_MySQL_Lexer::IDENTIFIER],
                true
            )) {
                throw new RuntimeException('Cannot parse the marked INSERT column list.');
            }
            $columns[] = substr($sql, $column_token->start, $column_token->length);
            if ($cursor < $token_count && $tokens[$cursor]->id === \WP_MySQL_Lexer::COMMA_SYMBOL) {
                ++$cursor;
            }
        }
        if ($columns === [] || $cursor >= $token_count) {
            throw new RuntimeException('Cannot parse the marked INSERT values.');
        }
        if ($tokens[$cursor]->id !== \WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            throw new RuntimeException('Cannot parse the marked INSERT column list ending.');
        }
        ++$cursor;
        if (
            $cursor >= $token_count ||
            !in_array(
                $tokens[$cursor]->id,
                [\WP_MySQL_Lexer::VALUES_SYMBOL, \WP_MySQL_Lexer::VALUE_SYMBOL],
                true
            )
        ) {
            throw new RuntimeException('Cannot parse the marked INSERT values.');
        }
        ++$cursor;
        if (
            $cursor >= $token_count ||
            $tokens[$cursor]->id !== \WP_MySQL_Lexer::OPEN_PAR_SYMBOL
        ) {
            throw new RuntimeException('Cannot parse the marked INSERT value tuple.');
        }
        ++$cursor;

        $values = [];
        $keep_value = [];
        $expression_start = $cursor;
        $parenthesis_depth = 0;
        $tuple_end = null;
        while ($cursor < $token_count) {
            $token_id = $tokens[$cursor]->id;
            if ($token_id === \WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                ++$parenthesis_depth;
            } elseif ($token_id === \WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                if ($parenthesis_depth === 0) {
                    $tuple_end = $tokens[$cursor]->start + $tokens[$cursor]->length;
                    break;
                }
                --$parenthesis_depth;
            }
            if ($token_id === \WP_MySQL_Lexer::COMMA_SYMBOL && $parenthesis_depth === 0) {
                $last_token = $tokens[$cursor - 1] ?? null;
                if ($expression_start >= $cursor || $last_token === null) {
                    throw new RuntimeException('The marked INSERT has an empty value expression.');
                }
                $first_token = $tokens[$expression_start];
                $length = $last_token->start + $last_token->length - $first_token->start;
                $value = substr($sql, $first_token->start, $length);
                $values[] = $value;
                $keep_value[] = strpos(
                    $value,
                    MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT
                ) === false;
                $expression_start = $cursor + 1;
            }
            ++$cursor;
        }
        $last_token = $tokens[$cursor - 1] ?? null;
        if ($tuple_end === null || $expression_start >= $cursor || $last_token === null) {
            throw new RuntimeException('Cannot parse the marked INSERT value tuple.');
        }
        $first_token = $tokens[$expression_start];
        $length = $last_token->start + $last_token->length - $first_token->start;
        $value = substr($sql, $first_token->start, $length);
        $values[] = $value;
        $keep_value[] = strpos($value, MySQLDumpProducer::ZERO_BYTE_SPATIAL_VALUE_COMMENT) === false;

        if (count($columns) !== count($values)) {
            throw new RuntimeException('The marked INSERT tuple has the wrong number of values.');
        }
        if (!in_array(false, $keep_value, true)) {
            throw new RuntimeException('The marked INSERT has no zero-byte spatial value.');
        }
        if (
            isset($tokens[$cursor + 1]) &&
            $tokens[$cursor + 1]->id === \WP_MySQL_Lexer::COMMA_SYMBOL
        ) {
            throw new RuntimeException('The marked INSERT must contain exactly one row.');
        }

        $kept_columns = [];
        $kept_values = [];
        foreach ($columns as $index => $column) {
            if ($keep_value[$index]) {
                $kept_columns[] = $column;
                $kept_values[] = $values[$index];
            }
        }
        return substr($sql, 0, $tokens[0]->start) .
            'INSERT INTO ' . substr($sql, $table_token->start, $table_token->length) .
            ' (' . implode(',', $kept_columns) . ') VALUES (' . implode(',', $kept_values) . ')' .
            substr($sql, $tuple_end);
    }

    private function is_mariadb_target(): bool
    {
        if ($this->target_is_mariadb !== null) {
            return $this->target_is_mariadb;
        }
        $result = $this->database->query('SELECT VERSION() AS version');
        try {
            $row = $result->fetch(PDO::FETCH_ASSOC);
        } finally {
            $result->closeCursor();
        }
        $version = is_array($row) ? ( $row['version'] ?? null ) : null;
        if (!is_string($version) || $version === '') {
            throw new RuntimeException('The target database returned no usable version string.');
        }
        $this->target_is_mariadb = stripos($version, 'mariadb') !== false;
        return $this->target_is_mariadb;
    }

    /**
     * Returns the table and columns encoded in one exporter marker.
     *
     * @return array{0: string, 1: string[]} Table name followed by column names.
     */
    private function parse_marker(string $sql, int $marker_position): array
    {
        $payload_start = $marker_position + strlen(
            MySQLDumpProducer::NULLABLE_SPATIAL_COLUMNS_COMMENT_PREFIX
        );
        $payload_end = strpos($sql, ' */', $payload_start);
        if ($payload_end === false) {
            throw new RuntimeException('The spatial column marker has no closing comment token.');
        }

        $encoded_identifiers = explode(' ', substr(
            $sql,
            $payload_start,
            $payload_end - $payload_start
        ));
        if (count($encoded_identifiers) < 2) {
            throw new RuntimeException('The spatial column marker must name a table and a column.');
        }

        $identifiers = [];
        foreach ($encoded_identifiers as $encoded_identifier) {
            $identifier = base64_decode($encoded_identifier, true);
            if (
                $identifier === false
                || $identifier === ''
                || base64_encode($identifier) !== $encoded_identifier
            ) {
                throw new RuntimeException(
                    'The spatial column marker contains an invalid base64 identifier.'
                );
            }
            $identifiers[] = $identifier;
        }

        $table = array_shift($identifiers);
        return [$table, $identifiers];
    }

    /** @param string[] $columns Columns which must become nullable. */
    private function assert_no_spatial_indexes(
        string $quoted_table,
        string $table,
        array $columns
    ): void {
        $requested_columns = array_fill_keys($columns, true);
        $result = $this->database->query("SHOW INDEX FROM {$quoted_table}");
        try {
            while (true) {
                $row = $result->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    break;
                }
                if (!is_array($row)) {
                    continue;
                }
                $index_type = $row['Index_type'] ?? null;
                $column = $row['Column_name'] ?? null;
                if (
                    !is_string($index_type) ||
                    !in_array(strtoupper($index_type), ['SPATIAL', 'RTREE'], true) ||
                    !is_string($column) ||
                    !isset($requested_columns[$column])
                ) {
                    continue;
                }
                $index = $row['Key_name'] ?? '(unnamed)';
                throw new RuntimeException(
                    'Cannot make target column ' . self::quote_identifier($table) . '.' .
                    self::quote_identifier($column) . ' nullable because spatial index ' .
                    self::quote_identifier(is_string($index) ? $index : '(unnamed)') .
                    ' requires the column to remain NOT NULL. Remove the spatial index and migrate this table separately.'
                );
            }
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * Returns complete column definitions with only top-level NOT NULL removed.
     *
     * @param string[] $columns Column names to change.
     * @return string[] Complete nullable column definitions.
     */
    private function get_nullable_column_definitions(string $create_table_sql, array $columns): array
    {
        $tokens = self::significant_tokens($create_table_sql, 'target CREATE TABLE');
        if (
            count($tokens) < 5
            || $tokens[0]->id !== \WP_MySQL_Lexer::CREATE_SYMBOL
            || $tokens[1]->id !== \WP_MySQL_Lexer::TABLE_SYMBOL
            || $tokens[2]->id !== \WP_MySQL_Lexer::BACK_TICK_QUOTED_ID
            || $tokens[3]->id !== \WP_MySQL_Lexer::OPEN_PAR_SYMBOL
        ) {
            throw new RuntimeException('The target CREATE TABLE has an invalid table declaration.');
        }

        $requested_columns = array_fill_keys($columns, true);
        $definitions = [];
        $definition_start = 4;
        $parenthesis_depth = 0;
        $saw_table_close = false;
        $token_count = count($tokens);
        for ($cursor = $definition_start; $cursor < $token_count; ++$cursor) {
            $token_id = $tokens[$cursor]->id;
            if ($token_id === \WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                ++$parenthesis_depth;
                continue;
            }
            if ($token_id === \WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                if ($parenthesis_depth > 0) {
                    --$parenthesis_depth;
                    continue;
                }
            } elseif (
                $parenthesis_depth > 0
                || $token_id !== \WP_MySQL_Lexer::COMMA_SYMBOL
            ) {
                continue;
            }

            if ($definition_start < $cursor) {
                $column = $tokens[$definition_start]->get_value();
                if (
                    $tokens[$definition_start]->id === \WP_MySQL_Lexer::BACK_TICK_QUOTED_ID
                    && isset($requested_columns[$column])
                ) {
                    $definitions[$column] = $this->make_definition_nullable(
                        $create_table_sql,
                        $tokens,
                        $definition_start,
                        $cursor
                    );
                }
            }
            $definition_start = $cursor + 1;

            if ($token_id === \WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                $saw_table_close = true;
                break;
            }
        }

        if (!$saw_table_close) {
            throw new RuntimeException('The target CREATE TABLE has no closing table parenthesis.');
        }

        $ordered_definitions = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $definitions)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Target schema errors are CLI text.
                throw new RuntimeException(
                    'The target CREATE TABLE has no definition for column ' .
                    self::quote_identifier($column) . '.'
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $ordered_definitions[] = $definitions[$column];
        }
        return $ordered_definitions;
    }

    /**
     * Removes one top-level NOT token while keeping the rest of the definition byte-for-byte.
     *
     * @param \WP_MySQL_Token[] $tokens CREATE TABLE tokens.
     */
    private function make_definition_nullable(
        string $create_table_sql,
        array $tokens,
        int $start,
        int $end
    ): string {
        $definition_start = $tokens[$start]->start;
        $definition_end = $tokens[$end]->start;
        $parenthesis_depth = 0;
        for ($cursor = $start; $cursor + 1 < $end; ++$cursor) {
            $token_id = $tokens[$cursor]->id;
            if ($token_id === \WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                ++$parenthesis_depth;
                continue;
            }
            if ($token_id === \WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                --$parenthesis_depth;
                continue;
            }
            if (
                $parenthesis_depth === 0
                && $token_id === \WP_MySQL_Lexer::NOT_SYMBOL
                && $tokens[$cursor + 1]->id === \WP_MySQL_Lexer::NULL_SYMBOL
            ) {
                return rtrim(
                    substr(
                        $create_table_sql,
                        $definition_start,
                        $tokens[$cursor]->start - $definition_start
                    ) .
                    substr(
                        $create_table_sql,
                        $tokens[$cursor + 1]->start,
                        $definition_end - $tokens[$cursor + 1]->start
                    )
                );
            }
        }

        // A replay sees the already-nullable target definition and can reuse it unchanged.
        return rtrim(substr(
            $create_table_sql,
            $definition_start,
            $definition_end - $definition_start
        ));
    }

    /**
     * Returns all non-comment tokens and rejects input the lexer could not finish.
     *
     * @return \WP_MySQL_Token[] Statement tokens without the final EOF token.
     */
    private static function significant_tokens(string $sql, string $description): array
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        $tokens = $lexer->remaining_tokens();
        if ($tokens === [] || end($tokens)->id !== \WP_MySQL_Lexer::EOF) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Import errors are CLI text.
            throw new RuntimeException("Cannot parse the {$description}.");
        }
        array_pop($tokens);
        return $tokens;
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
