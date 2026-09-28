<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These errors are protocol or CLI text, never HTML.


use WordPress\Reprint\Server\DatabasePush;
use WordPress\Reprint\Server\MysqliDriverPDO;
use WordPress\Reprint\Server\PdoConstants;

require_once __DIR__ . '/../url-rewrite/load.php';
require_once __DIR__ . '/../import/functions.php';

/**
 * Reads one table definition, row, or deferred foreign key at a time.
 *
 * Like pull, this uses primary-key cursors, or OFFSET for unkeyed tables.
 * It does not freeze the source: confirmed rows keep their copied values,
 * while later reads may see edits. Keep source tables and columns unchanged
 * until ready. URL rewriting never changes the local source.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Client library class, not a WordPress plugin API.
class DatabasePushSource {
    private const MAX_ROW_BYTES = 1048576;
    /** @var PDO|MysqliDriverPDO */
    private $database;
    /** @var SqlStatementRewriter */
    private $rewriter;
    /** @var \WordPress\Reprint\Server\DatabaseRowsReader|null */
    private $rows;
    /** @var list<string> */
    private $tables;
    /** @var array<string,array<string,mixed>> */
    private $columns = [];
    /** @var array<string,string> */
    private $select = [];
    /** @var bool */
    private $sqlite_source;
    /** @var string */
    private $spatial_function_prefix;
    /** @var array<string,string> */
    private $incoming_tables = [];
    /** @var string */
    private $database_name;
    /** @var list<array<string,mixed>>|null Foreign key metadata for the current table only. */
    private $foreign_keys;
    /** @var string|null */
    private $current_table;
    /** @var array<string,mixed> */
    private $cursor;
    /** @var array<string,mixed>|null */
    private $record;
    /** @var bool */
    private $closed = false;

    /**
     * @param PDO|MysqliDriverPDO $database Dedicated local source connection.
     * @param string $table_prefix Site table prefix, identical on both sites.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs.
     * @param string $push_session_id Target session receiving these records.
     * @param array|null $cursor {
     *     Target-confirmed position, or null before the first record.
     *     @type string $phase table, rows, foreign_keys, or complete.
     *     @type int $table Index in the saved source table list.
     *     @type array $reader DatabaseRowsReader cursor, present during rows.
     *     @type int $foreign_key Next constraint in the current table, during foreign_keys.
     * }
     * @param list<string>|null $tables Saved source table list; null discovers it once.
     * @param list<string> $extra_tables Explicit extra tables to include at discovery.
     */
    public function __construct($database, string $table_prefix, array $url_mapping, string $push_session_id, ?array $cursor = null, ?array $tables = null, array $extra_tables = []) {
        $this->sqlite_source = $database instanceof WP_PDO_MySQL_On_SQLite;
        $this->database = $database;
        if (!$this->sqlite_source) {
            // TIMESTAMP text must use the target's UTC session. The escaped
            // prefix and the SHOW CREATE parser require default SQL quoting.
            $database->exec("SET SESSION time_zone='+00:00', sql_mode=''");
        }
        $this->database_name = $database->query('SELECT DATABASE()')->fetchColumn();
        $this->spatial_function_prefix = version_compare($database->query('SELECT VERSION()')->fetchColumn(), '5.6', '<') ? '' : 'ST_';
        $this->rewriter = new SqlStatementRewriter(new StructuredDataUrlRewriter($url_mapping), $table_prefix);
        if ($tables === null) {
            // Source reads do not require the target's crash-safe RENAME support.
            // SHOW TABLE STATUS is also the discovery query used by pull's reader
            // and is implemented by the SQLite integration's MySQL interface.
            $this->tables = [];
            $quoting_database = $database instanceof WP_PDO_MySQL_On_SQLite ? $database->get_connection()->get_pdo() : $database;
            $table_status = $database->query('SHOW TABLE STATUS LIKE ' . $quoting_database->quote(addcslashes($table_prefix, '_%\\') . '%'));
            // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Read only the bounded selected table metadata.
            while (( $status = $table_status->fetch(PdoConstants::fetch_assoc()) ) !== false) {
                $name = $status['Name'];
                if (strpos($name, $table_prefix) !== 0 || \WordPress\Reprint\Server\MultisiteDatabaseSelection::is_internal_table($name)) {
                    continue;
                }
                DatabasePush::validate_identifier($name);
                if (!isset($status['Engine'])) {
                    throw new RuntimeException('Database push does not yet export views: ' . $name . '.');
                }
                $this->tables[] = $name;
                if (count($this->tables) > DatabasePush::MAX_TABLES) {
                    throw new RuntimeException('Database push supports at most 256 source tables.');
                }
            }
            unset($table_status);
            if ($this->tables === []) {
                throw new RuntimeException('The local source has no tables for prefix ' . $table_prefix . '.');
            }
            foreach (DatabasePush::normalize_extra_tables($extra_tables, $table_prefix) as $name) {
                $status = $database->query('SHOW TABLE STATUS LIKE ' . $quoting_database->quote(addcslashes($name, '_%\\')))->fetch(PdoConstants::fetch_assoc());
                if ($status === false || $status['Name'] !== $name) {
                    throw new RuntimeException('Explicitly included source table does not exist: ' . $name . '.');
                }
                if (!isset($status['Engine'])) {
                    throw new RuntimeException('Database push does not yet export views: ' . $name . '.');
                }
                $this->tables[] = $name;
            }
            if (count($this->tables) > DatabasePush::MAX_TABLES) {
                throw new RuntimeException('Database push supports at most 256 source tables, including explicitly selected tables.');
            }
            sort($this->tables, SORT_STRING);
        } else {
            $this->tables = $tables;
        }
        foreach ($this->tables as $index => $table) {
            $this->incoming_tables[$table] = '__reprint_db_' . $push_session_id . '_t' . $index;
        }
        $this->cursor = $cursor ?? ['phase' => 'table', 'table' => 0];
    }

    public function next_step(): bool {
        $this->record = null;
        if ($this->cursor['phase'] === 'complete') {
            return false;
        }
        if ($this->closed) {
            throw new RuntimeException('Database push source is closed.');
        }
        $this->current_table = $this->tables[$this->cursor['table']] ?? null;
        if ($this->cursor['phase'] === 'table') {
            if ($this->current_table === null) {
                $this->cursor = ['phase' => 'foreign_keys', 'table' => 0, 'foreign_key' => 0];
                return true;
            }
            [$ddl] = $this->prepare_schema();
            $this->open_rows();
            $this->cursor['phase'] = 'rows';
            $this->cursor['reader'] = $this->row_cursor();
            $this->record = ['table' => $this->current_table, 'ddl' => $ddl];
            return true;
        }
        if ($this->cursor['phase'] === 'rows') {
            if ($this->rows === null) {
                $this->open_rows();
            }
            if (!$this->rows->next_record($this->select)) {
                $this->rows->close();
                $this->rows = null;
                $this->cursor = ['phase' => 'table', 'table' => $this->cursor['table'] + 1];
                return true;
            }
            $row = $this->rows->get_current_record();
            if ( (int) $row['__reprint_row_bytes'] > self::MAX_ROW_BYTES) {
                throw new RuntimeException('Database push row in ' . $this->current_table . ' exceeds the initial 1 MiB row limit. Live tables have not been changed.');
            }
            unset($row['__reprint_row_bytes']);
            $values = [];
            $rewritten_bytes = 0;
            foreach ($row as $column => $value) {
                if (!$this->sqlite_source && preg_match('/^enum\(/i', $this->columns[$column]['Type'])) {
                    [$enum_index, $value] = $value === null ? [null, null] : explode(':', $value, 2);
                    if ($enum_index !== null && (int) $enum_index === 0) {
                        // A permissively stored invalid ENUM is index zero,
                        // not the empty label or a label containing "0".
                        $values[$column] = 0;
                        continue;
                    }
                }
                if ($value !== null && $this->sqlite_source && preg_match('/^bit\(/i', $this->columns[$column]['Type'])) {
                    // SQLite stores BIT as a number, not MySQL's packed bytes.
                    $values[$column] = ['unsigned' => (string) $value];
                    $rewritten_bytes += strlen( (string) $value);
                    continue;
                }
                if ($value !== null && $this->columns[$column]['spatial']) {
                    [$srid, $hex] = explode(':', $value, 2);
                    $srid = (int) $srid;
                    $bytes = hex2bin($hex);
                    $rewritten_bytes += strlen($bytes);
                    $values[$column] = ['wkb' => base64_encode($bytes), 'srid' => $srid];
                    continue;
                }
                $rewritten = $value;
                if ($value !== null && !preg_match('/^(tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/i', $this->columns[$column]['Type'])) {
                    $rewritten = $this->rewriter->rewrite_value( (string) $value, $this->current_table, $column);
                    if ($rewritten !== (string) $value && $this->columns[$column]['Key'] === 'PRI') {
                        throw new RuntimeException('URL rewriting would change a primary key in ' . $this->current_table . '.' . $column . '.');
                    }
                }
                $rewritten_bytes += $rewritten === null ? 0 : strlen( (string) $rewritten);
                $values[$column] = $rewritten === null ? null : base64_encode( (string) $rewritten);
            }
            if ($rewritten_bytes > self::MAX_ROW_BYTES) {
                throw new RuntimeException('Rewritten database push row in ' . $this->current_table . ' exceeds 1 MiB. Live tables have not been changed.');
            }
            $this->rows->clear_current_record();
            $this->cursor['reader'] = $this->row_cursor();
            $this->record = ['values' => $values];
            return true;
        }
        if ($this->current_table === null) {
            $this->cursor = ['phase' => 'complete', 'table' => count($this->tables)];
            $this->record = ['end' => true];
            return true;
        }
        if ($this->foreign_keys === null) {
            // Revisit schema metadata, never completed row data. This avoids a
            // deferred SQL file and retains only one table's bounded definition.
            [, $this->foreign_keys] = $this->prepare_schema();
        }
        if (isset($this->foreign_keys[$this->cursor['foreign_key']])) {
            $this->record = $this->foreign_keys[$this->cursor['foreign_key']++];
            return true;
        }
        $this->foreign_keys = null;
        ++$this->cursor['table'];
        $this->cursor['foreign_key'] = 0;
        return true;
    }

    /**
     * @return array|null {
     *     One record, or null after a phase transition with no record to send.
     *     @type array $cursor Source position after this record.
     *     @type string $table Site table name, for a definition or foreign key.
     *     @type string $ddl Client-prepared CREATE statement, for a definition.
     *     @type array $values Column names mapped to base64, SQL NULL, ENUM zero, unsigned decimal values, or WKB/SRID pairs.
     *     @type string $foreign_key Private constraint name, for a deferred foreign key.
     *     @type string $definition FOREIGN KEY clause with incoming table references.
     *     @type bool $end True only for the final record.
     * }
     */
    public function get_record(): ?array {
        return $this->record === null ? null : $this->record + ['cursor' => $this->cursor];
    }

    /** @return list<string> Bounded table metadata saved once before any upload. */
    public function get_tables(): array {
        return $this->tables;
    }

    public function close(): void {
        if ($this->rows !== null) {
            // The shared reader drains and releases its bounded result. This
            // also supports the SQLite proxy, which has no closeCursor().
            $this->rows->close();
            $this->rows = null;
        }
        $this->record = null;
        $this->closed = true;
    }

    private function open_rows(): void {
        $table = DatabasePush::identifier($this->current_table);
        $this->columns = [];
        $sizes = [];
        foreach ($this->database->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(PdoConstants::fetch_assoc()) as $column) {
            DatabasePush::validate_identifier($column['Field']);
            if ($column['Field'] === '__reprint_row_bytes') {
                throw new RuntimeException('Source column __reprint_row_bytes conflicts with the stream row-size check.');
            }
            if (in_array(explode(' ', $column['Extra'])[0], ['VIRTUAL', 'STORED', 'PERSISTENT'], true)) {
                // The target computes generated values from rewritten inputs.
                continue;
            }
            $column['spatial'] = in_array(strtolower($column['Type']), ['geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'], true);
            $this->columns[$column['Field']] = $column;
            $value = DatabasePush::identifier($column['Field']);
            if ($column['Collation'] !== null) {
                // Bound the UTF-8 result bytes, not the source storage
                // charset (latin1 text can grow on the connection).
                $value = 'CONVERT(' . $value . ' USING utf8mb4)';
            }
            $sizes[] = 'COALESCE(LENGTH(CAST(' . $value . ' AS BINARY)),0)';
        }
        if (count($this->columns) > 128) {
            throw new RuntimeException('Database push supports at most 128 columns per table: ' . $this->current_table . '.');
        }
        $size = $sizes === [] ? '0' : '(' . implode('+', $sizes) . ')';
        $this->select = [];
        foreach (array_keys($this->columns) as $column) {
            $identifier = DatabasePush::identifier($column);
            // Ask MySQL to withhold an oversized row before PHP receives it.
            // PDO decodes BIT result metadata as an integer. CAST keeps
            // these bytes unchanged through IF and native parameter binding.
            $value = !$this->sqlite_source && preg_match('/^bit\(/i', $this->columns[$column]['Type'])
                ? 'CAST(' . $identifier . ' AS BINARY)' : $identifier;
            if (!$this->sqlite_source && preg_match('/^enum\(/i', $this->columns[$column]['Type'])) {
                // Carry the index as well as the label to distinguish
                // index zero from a declared empty-string member. A numeric
                // prefix works on old MySQL versions without JSON functions.
                $value = "CONCAT(CAST(" . $identifier . " AS UNSIGNED),':'," . $identifier . ')';
            }
            if ($this->columns[$column]['spatial']) {
                // Use the engine's WKB conversion: MySQL's raw geographic
                // bytes use a different axis order for some SRIDs.
                $value = 'CONCAT(' . $this->spatial_function_prefix . 'SRID(' . $identifier . "),':',HEX(" . $this->spatial_function_prefix . 'AsWKB(' . $identifier . ')))';
            }
            $this->select[$column] = 'IF(' . $size . '>' . self::MAX_ROW_BYTES . ',NULL,' . $value . ')';
        }
        $this->select['__reprint_row_bytes'] = $size;
        $this->rows = new \WordPress\Reprint\Server\DatabaseRowsReader($this->database, ['tables_to_process' => [$this->current_table]]);
        if (isset($this->cursor['reader'])) {
            $this->rows->restore_cursor_state($this->cursor['reader']);
        } else {
            $this->rows->move_to_next_table();
        }
        if ($this->database instanceof MysqliDriverPDO) {
            $this->database->set_buffered(false);
        } elseif (!$this->sqlite_source) {
            $this->database->setAttribute(( defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ), false);
        }
    }

    /** @return array<string,mixed> Pagination fields only; no row data or column list. */
    private function row_cursor(): array {
        $cursor = $this->rows->get_cursor_state();
        unset($cursor['current_row'], $cursor['current_column_names']);
        return $cursor;
    }

    /** @return array{string,list<array<string,mixed>>} CREATE and deferred constraints for one table. */
    private function prepare_schema(): array {
        $table = DatabasePush::identifier($this->current_table);
        $foreign_keys = [];
        $ddl = $this->database->query('SHOW CREATE TABLE ' . $table)->fetchColumn(1);
        if (strlen($ddl) > DatabasePush::MAX_RECORD_BYTES) {
            throw new RuntimeException('Source table definition exceeds the 2 MiB record limit: ' . $this->current_table . '.');
        }
        static $grammar = null;
        if ($grammar === null) {
            $grammar = new WP_Parser_Grammar(require \Reprint\Importer\resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/mysql/mysql-grammar.php'));
        }
        $parser = new WP_MySQL_Parser($grammar, ( new WP_MySQL_Lexer($ddl) )->remaining_tokens());
        $statement = $parser->parse();
        $create = $statement === null ? null : $statement->get_first_descendant_node('createTable');
        if ($create === null || $create->get_first_child_node('tableElementList') === null) {
            throw new RuntimeException('Cannot parse the source CREATE TABLE definition for ' . $this->current_table . '.');
        }
        // The receiver expects this exact envelope, even if the source
        // has sql_quote_show_create disabled. SQL content stays in the client.
        $edits = [[0, $create->get_first_child_token(WP_MySQL_Lexer::OPEN_PAR_SYMBOL)->start, 'CREATE TABLE ' . $table . ' ']];
        $foreign_key_number = 0;
        $check_number = 0;
        foreach ($create->get_first_child_node('tableElementList')->get_child_nodes('tableElement') as $element) {
            $constraint = $element->get_first_child_node('tableConstraintDef');
            if ($constraint === null) {
                continue;
            }
            $name = $constraint->get_first_child_node('constraintName');
            $foreign_key = $constraint->get_first_child_token(WP_MySQL_Lexer::FOREIGN_SYMBOL);
            if ($foreign_key === null) {
                if ($name !== null) {
                    // Keep constraint names distinct from the live schema.
                    // Avoid MySQL's table_chk_N pattern: RENAME could expand
                    // it beyond 64 bytes for a long final table name.
                    $constraint_name = $this->incoming_tables[$this->current_table] . '_ck_' . ( ++$check_number );
                    $edits[] = [$name->get_start(), $name->get_length(), 'CONSTRAINT ' . DatabasePush::identifier($constraint_name)];
                }
                continue;
            }
            $reference = $constraint->get_first_child_node('references')->get_first_child_node('tableRef');
            $identifiers = $reference->get_descendant_nodes('identifier');
            $referenced_table = end($identifiers)->get_first_descendant_token()->get_value();
            if (!isset($this->incoming_tables[$referenced_table]) || ( count($identifiers) > 1 && $identifiers[0]->get_first_descendant_token()->get_value() !== $this->database_name )) {
                throw new RuntimeException('Foreign key in ' . $this->current_table . ' references a table outside this push: ' . substr($ddl, $reference->get_start(), $reference->get_length()) . '.');
            }
            $definition = substr($ddl, $foreign_key->start, $constraint->get_start() + $constraint->get_length() - $foreign_key->start);
            $definition = substr_replace($definition, DatabasePush::identifier($this->incoming_tables[$referenced_table]), $reference->get_start() - $foreign_key->start, $reference->get_length());
            $constraint_name = $this->incoming_tables[$this->current_table] . '_fk_' . ( ++$foreign_key_number );
            // Add foreign keys after every row is present. ALTER validates
            // rewritten values, including cycles, without disabling checks.
            $foreign_keys[] = ['foreign_key' => $constraint_name, 'table' => $this->current_table, 'definition' => $definition];
            // SHOW CREATE emits table constraints after column definitions;
            // remove the preceding comma as well as this constraint.
            $comma = null;
            foreach ($create->get_first_child_node('tableElementList')->get_child_tokens(WP_MySQL_Lexer::COMMA_SYMBOL) as $token) {
                if ($token->start < $element->get_start()) {
                    $comma = $token->start;
                }
            }
            if ($comma === null) {
                throw new RuntimeException('Expected a column before the foreign key in ' . $this->current_table . '.');
            }
            $edits[] = [$comma, $element->get_start() + $element->get_length() - $comma, ''];
        }
        foreach (array_merge($create->get_descendant_nodes('createTableOption'), $create->get_descendant_nodes('partitionOption')) as $option) {
            $first = $option->get_first_descendant_token()->id;
            if ($first === WP_MySQL_Lexer::ENGINE_SYMBOL) {
                // Incoming rows and progress must commit together, regardless
                // of the engine from which the client reads them.
                $edits[] = [$option->get_start(), $option->get_length(), 'ENGINE=InnoDB'];
            }
            if (in_array($first, [WP_MySQL_Lexer::TABLESPACE_SYMBOL, WP_MySQL_Lexer::DATA_SYMBOL, WP_MySQL_Lexer::INDEX_SYMBOL, WP_MySQL_Lexer::CONNECTION_SYMBOL], true)) {
                // Storage paths and named tablespaces belong to the source
                // host. Let the target place its own InnoDB tables.
                $edits[] = [$option->get_start(), $option->get_length(), ''];
            }
        }
        usort($edits, static function ($left, $right) {
            return $right[0] <=> $left[0];
        });
        foreach ($edits as [$start, $length, $replacement]) {
            $ddl = substr_replace($ddl, $replacement, $start, $length);
        }
        return [$ddl, $foreign_keys];
    }
}
