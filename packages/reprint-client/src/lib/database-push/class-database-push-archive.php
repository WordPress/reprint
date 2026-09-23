<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These errors are protocol or CLI text, never HTML.


use WordPress\Reprint\Server\DatabasePush;
use WordPress\Reprint\Server\MysqliDriverPDO;
use WordPress\Reprint\Server\PdoConstants;

require_once __DIR__ . '/../url-rewrite/load.php';
require_once __DIR__ . '/../import/functions.php';

/**
 * Prepares an immutable archive locally, rewriting whole values before encoding.
 *
 * One step writes a table definition, one row, one deferred foreign key, or
 * the end record. InnoDB and SQLite use a read transaction; other source engines
 * hold read locks. An interrupted preparation must take a new snapshot; only
 * a sealed archive may be uploaded or resumed. Source DDL must remain unchanged
 * during preparation. No local source row is modified.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Client library class, not a WordPress plugin API.
class DatabasePushArchive {
    private const MAX_ROW_BYTES = 1048576;
    /** @var PDO|MysqliDriverPDO */
    private $database;
    /** @var SqlStatementRewriter */
    private $rewriter;
    /** @var string */
    private $local_absolute_path;
    /** @var resource|null */
    private $output;
    /** @var PDOStatement|\WordPress\Reprint\Server\MysqliDriverPDOStatement|null */
    private $rows;
    /** @var list<string> */
    private $tables;
    /** @var array<string,array<string,mixed>> */
    private $columns = [];
    /** @var bool Nontransactional sources stay read-locked until the archive is sealed. */
    private $source_tables_locked = false;
    /** @var bool */
    private $sqlite_source;
    /** @var string */
    private $spatial_function_prefix;
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
     * @param PDO|MysqliDriverPDO $database Dedicated local source connection; never the hosted database.
     * @param string $local_absolute_path Private archive filename.
     * @param string $table_prefix Site table prefix, identical on both sites.
     * @param array<string,string> $url_mapping Local URLs mapped to hosted URLs.
     * @param string $push_session_id Target session whose private table names receive this archive.
     */
    public function __construct($database, string $local_absolute_path, string $table_prefix, array $url_mapping, string $push_session_id) {
        $this->sqlite_source = $database instanceof WP_PDO_MySQL_On_SQLite;
        if (file_exists($local_absolute_path)) {
            throw new RuntimeException('A sealed database push archive already exists: ' . $local_absolute_path);
        }
        $this->database = $database;
        if (!$this->sqlite_source) {
            // TIMESTAMP text must use the target's UTC session. The escaped
            // prefix and the SHOW CREATE parser require default SQL quoting.
            $database->exec("SET SESSION time_zone='+00:00', sql_mode=''");
        }
        $this->database_name = $database->query('SELECT DATABASE()')->fetchColumn();
        $this->spatial_function_prefix = version_compare($database->query('SELECT VERSION()')->fetchColumn(), '5.6', '<') ? '' : 'ST_';
        $this->local_absolute_path = $local_absolute_path;
        $this->rewriter = new SqlStatementRewriter(new StructuredDataUrlRewriter($url_mapping), $table_prefix);
        // Source reads do not require the target's crash-safe RENAME support.
        // SHOW TABLE STATUS is also the discovery query used by pull's reader
        // and is implemented by the SQLite integration's MySQL interface.
        $this->tables = [];
        $needs_read_lock = false;
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
            $needs_read_lock = $needs_read_lock || ( !$this->sqlite_source && $status['Engine'] !== 'InnoDB' );
            $this->tables[] = $name;
            if (count($this->tables) > DatabasePush::MAX_TABLES) {
                throw new RuntimeException('Database push supports at most 256 source tables.');
            }
        }
        unset($table_status);
        sort($this->tables, SORT_STRING);
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
        if ($needs_read_lock) {
            // MyISAM cannot supply a transactional snapshot. Lock the whole
            // selection, including InnoDB tables, so related rows stay aligned.
            $database->exec('LOCK TABLES ' . implode(',', array_map(static function ($name) {
                return DatabasePush::identifier($name) . ' READ';
            }, $this->tables)));
            $this->source_tables_locked = true;
        } elseif ($this->sqlite_source) {
            $database->beginTransaction();
        } else {
            $database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $database->beginTransaction();
        }
        if (!$needs_read_lock) {
            $database->query('SELECT 1 FROM ' . DatabasePush::identifier($this->tables[0]) . ' LIMIT 1')->fetchColumn();
        }
    }

    public function next_step(): bool {
        if ($this->complete) {
            return false;
        }
        if ($this->closed) {
            throw new RuntimeException('Database archive preparation is closed.');
        }
        if ($this->rows !== null) {
            $row = $this->rows->fetch(PdoConstants::fetch_assoc());
            if ($row === false) {
                // The SQLite proxy has no closeCursor(); releasing it closes its native result.
                if (!$this->sqlite_source) {
                    $this->rows->closeCursor();
                }
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
                throw new RuntimeException('Rewritten database push row in ' . $this->current_table . ' exceeds 1 MiB. Nothing has been uploaded.');
            }
            $this->write_record(['values' => $values], $this->output);
            return true;
        }
        if ($this->tables !== []) {
            $this->current_table = array_shift($this->tables);
            $table = DatabasePush::identifier($this->current_table);
            $ddl = $this->database->query('SHOW CREATE TABLE ' . $table)->fetchColumn(1);
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
            $this->columns = [];
            $sizes = [];
            foreach ($this->database->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(PdoConstants::fetch_assoc()) as $column) {
                DatabasePush::validate_identifier($column['Field']);
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
                $sizes[] = 'COALESCE(LENGTH(CAST(' . $value . ' AS BINARY)),0)';
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
                $select[] = 'IF(' . $size . '>' . self::MAX_ROW_BYTES . ',NULL,' . $value . ') AS ' . $identifier;
            }
            $select[] = $size . ' AS __reprint_row_bytes';
            if ($this->database instanceof MysqliDriverPDO) {
                $this->database->set_buffered(false);
            } elseif (!$this->sqlite_source) {
                $this->database->setAttribute(( defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ), false);
            }
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
        if ($this->source_tables_locked) {
            $this->database->exec('UNLOCK TABLES');
            $this->source_tables_locked = false;
        } else {
            $this->database->commit();
        }
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
            if (!$this->sqlite_source) {
                $this->rows->closeCursor();
            }
            $this->rows = null;
        }
        if ($this->source_tables_locked) {
            $this->database->exec('UNLOCK TABLES');
            $this->source_tables_locked = false;
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
     *     @type array $values Column names mapped to base64, SQL NULL, ENUM zero, unsigned decimal values, or WKB/SRID pairs.
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
