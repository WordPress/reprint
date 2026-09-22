<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These errors are protocol or CLI text, never HTML.


use WordPress\Reprint\Server\DatabasePush;

require_once __DIR__ . '/../url-rewrite/load.php';
require_once __DIR__ . '/../import/functions.php';

/**
 * Prepares an immutable archive locally, rewriting whole values before encoding.
 *
 * One step writes a table definition, one row, one deferred foreign key, or
 * the end record. The source uses one consistent InnoDB snapshot. An interrupted
 * preparation must start a
 * new snapshot; only a sealed archive may be uploaded or resumed. Source DDL
 * must remain unchanged during preparation. No local source row is modified.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Client library class, not a WordPress plugin API.
class DatabasePushArchive {
    private const MAX_ROW_BYTES = 1048576;
    /** @var PDO */
    private $database;
    /** @var SqlStatementRewriter */
    private $rewriter;
    /** @var string */
    private $local_absolute_path;
    /** @var resource|null */
    private $output;
    /** @var PDOStatement|null */
    private $rows;
    /** @var list<string> */
    private $tables;
    /** @var array<string,array<string,mixed>> */
    private $columns = [];
    /** @var array<string,string> */
    private $incoming_tables = [];
    /** @var string */
    private $database_name;
    /** @var resource */
    private $foreign_keys;
    /** @var bool */
    private $writing_foreign_keys = false;
    /** @var string|null */
    private $current_table;
    /** @var bool */
    private $complete = false;
    /** @var bool */
    private $closed = false;

    /**
     * @param PDO $database Dedicated local source connection; never the hosted database.
     * @param string $local_absolute_path Private archive filename.
     * @param string $table_prefix Site table prefix, identical on both sites.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs.
     * @param string $push_session_id Target session whose private table names receive this archive.
     */
    public function __construct(PDO $database, string $local_absolute_path, string $table_prefix, array $url_mapping, string $push_session_id) {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Database push currently requires a MySQL source; SQLite source support is not implemented.');
        }
        if (file_exists($local_absolute_path)) {
            throw new RuntimeException('A sealed database push archive already exists: ' . $local_absolute_path);
        }
        $this->database = $database;
        $this->database_name = $database->query('SELECT DATABASE()')->fetchColumn();
        $this->local_absolute_path = $local_absolute_path;
        $this->rewriter = new SqlStatementRewriter(new StructuredDataUrlRewriter($url_mapping), $table_prefix);
        $inspection = new DatabasePush($database, $table_prefix, $push_session_id);
        try {
            $this->tables = $inspection->assert_supported_target();
        } finally {
            $inspection->close();
        }
        if ($this->tables === []) {
            throw new RuntimeException('The local source has no tables for prefix ' . $table_prefix . '.');
        }
        foreach ($this->tables as $index => $table) {
            $this->incoming_tables[$table] = '__reprint_db_' . $push_session_id . '_t' . $index;
        }
        $this->foreign_keys = tmpfile();
        if ($this->foreign_keys === false) {
            throw new RuntimeException('Cannot create the local foreign key archive.');
        }
        $this->output = fopen($local_absolute_path . '.building', 'wb');
        if ($this->output === false) {
            throw new RuntimeException('Cannot create the private database push archive.');
        }
        chmod($local_absolute_path . '.building', 0600);
        $database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $database->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    }

    public function next_step(): bool {
        if ($this->complete) {
            return false;
        }
        if ($this->closed) {
            throw new RuntimeException('Database archive preparation is closed.');
        }
        if ($this->rows !== null) {
            $row = $this->rows->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->rows->closeCursor();
                $this->rows = null;
                return true;
            }
            if ( (int) $row['__reprint_row_bytes'] > self::MAX_ROW_BYTES) {
                throw new RuntimeException('Database push row in ' . $this->current_table . ' exceeds the initial 1 MiB row limit. Nothing has been uploaded.');
            }
            unset($row['__reprint_row_bytes']);
            $values = [];
            $rewritten_bytes = 0;
            foreach ($row as $column => $value) {
                if (preg_match('/^enum\(/i', $this->columns[$column]['Type'])) {
                    [$value, $enum_index] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    if ($enum_index === 0) {
                        // A permissively stored invalid ENUM is index zero,
                        // not the empty label or a label containing "0".
                        $values[$column] = 0;
                        continue;
                    }
                }
                if ($value !== null && $this->columns[$column]['spatial']) {
                    [$srid, $hex] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
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
                throw new RuntimeException('Rewritten database push row in ' . $this->current_table . ' exceeds 1 MiB. Nothing has been uploaded.');
            }
            $this->write_record(['values' => $values], $this->output);
            return true;
        }
        if ($this->tables !== []) {
            $this->current_table = array_shift($this->tables);
            $table = DatabasePush::identifier($this->current_table);
            $ddl = $this->database->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
            if (strlen($ddl) > DatabasePush::MAX_RECORD_BYTES) {
                throw new RuntimeException('Source table definition exceeds the 2 MiB archive record limit: ' . $this->current_table . '.');
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
                $this->write_record(['foreign_key' => $constraint_name, 'table' => $this->current_table, 'definition' => $definition], $this->foreign_keys);
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
            $this->columns = [];
            $sizes = [];
            foreach ($this->database->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $column) {
                DatabasePush::identifier($column['Field']);
                if ($column['Field'] === '__reprint_row_bytes') {
                    throw new RuntimeException('Source column __reprint_row_bytes conflicts with the archive row-size check.');
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
                $sizes[] = 'COALESCE(OCTET_LENGTH(' . $value . '),0)';
            }
            if (count($this->columns) > 128) {
                throw new RuntimeException('Database push supports at most 128 columns per table: ' . $this->current_table . '.');
            }
            $size = $sizes === [] ? '0' : '(' . implode('+', $sizes) . ')';
            $select = [];
            foreach (array_keys($this->columns) as $column) {
                $identifier = DatabasePush::identifier($column);
                // Ask MySQL to withhold an oversized row before PHP receives it.
                // PDO decodes BIT result metadata as an integer. CAST keeps
                // these bytes unchanged through IF and native parameter binding.
                $value = preg_match('/^bit\(/i', $this->columns[$column]['Type'])
                    ? 'CAST(' . $identifier . ' AS BINARY)' : $identifier;
                if (preg_match('/^enum\(/i', $this->columns[$column]['Type'])) {
                    // Carry the index as well as the label to distinguish
                    // index zero from a declared empty-string member. MySQL
                    // emits +0 as a JSON float; CAST gives both engines integers.
                    $value = 'JSON_ARRAY(' . $identifier . ',CAST(' . $identifier . ' AS UNSIGNED))';
                }
                if ($this->columns[$column]['spatial']) {
                    $value = 'IF(' . $identifier . ' IS NULL,NULL,JSON_ARRAY(ST_SRID(' . $identifier . '),HEX(ST_AsWKB(' . $identifier . '))))';
                }
                $select[] = 'IF(' . $size . '>' . self::MAX_ROW_BYTES . ',NULL,' . $value . ') AS ' . $identifier;
            }
            $select[] = $size . ' AS __reprint_row_bytes';
            $this->database->setAttribute(( defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ), false);
            $this->rows = $this->database->query('SELECT ' . implode(',', $select) . ' FROM ' . $table);
            $this->write_record(['table' => $this->current_table, 'ddl' => $ddl], $this->output);
            return true;
        }
        if (!$this->writing_foreign_keys) {
            rewind($this->foreign_keys);
            $this->writing_foreign_keys = true;
            return true;
        }
        $line = fgets($this->foreign_keys, DatabasePush::MAX_RECORD_BYTES + 1);
        if ($line !== false) {
            if (fwrite($this->output, $line) !== strlen($line)) {
                throw new RuntimeException('Cannot append the next foreign key record to the database push archive.');
            }
            return true;
        }
        $this->write_record(['end' => true], $this->output);
        $this->database->commit();
        if (!fflush($this->output)) {
            throw new RuntimeException('Cannot flush the prepared database push archive.');
        }
        fclose($this->output);
        $this->output = null;
        if (!rename($this->local_absolute_path . '.building', $this->local_absolute_path)) {
            throw new RuntimeException('Cannot seal the prepared database push archive.');
        }
        $this->complete = true;
        return false;
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }
        if ($this->rows !== null) {
            $this->rows->closeCursor();
            $this->rows = null;
        }
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
        if (is_resource($this->output)) {
            fclose($this->output);
            $this->output = null;
        }
        if (is_resource($this->foreign_keys)) {
            fclose($this->foreign_keys);
        }
        $this->closed = true;
    }

    /**
     * @param array $record {
     *     One table definition, row, foreign key, or end record.
     *     @type string $table Site table name, for a table definition or foreign key.
     *     @type string $ddl Client-prepared CREATE TABLE statement, accompanying table.
     *     @type string $foreign_key Private constraint name for a deferred foreign key.
     *     @type string $definition FOREIGN KEY clause with incoming table references.
     *     @type array $values Column names mapped to base64, SQL NULL, ENUM zero, or WKB/SRID pairs.
     *     @type bool $end True only for the final record.
     * }
     * @param resource $output Main archive or deferred foreign key stream.
     */
    private function write_record(array $record, $output): void {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($line) > DatabasePush::MAX_RECORD_BYTES) {
            throw new RuntimeException('Prepared database record exceeds the 2 MiB archive record limit.');
        }
        if (fwrite($output, $line) !== strlen($line)) {
            throw new RuntimeException('Cannot write a complete database push archive record.');
        }
    }
}
