<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These errors are protocol or CLI text, never HTML.


use WordPress\Reprint\Server\DatabasePush;

require_once __DIR__ . '/../url-rewrite/load.php';

/**
 * Prepares an immutable archive locally, rewriting whole values before encoding.
 *
 * One step writes a table definition, one row, or the end record. The source
 * uses one consistent InnoDB snapshot. An interrupted preparation must start a
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
     */
    public function __construct(PDO $database, string $local_absolute_path, string $table_prefix, array $url_mapping) {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Database push currently requires a MySQL source; SQLite source support is not implemented.');
        }
        if (file_exists($local_absolute_path)) {
            throw new RuntimeException('A sealed database push archive already exists: ' . $local_absolute_path);
        }
        $this->database = $database;
        $this->local_absolute_path = $local_absolute_path;
        $this->rewriter = new SqlStatementRewriter(new StructuredDataUrlRewriter($url_mapping), $table_prefix);
        $inspection = new DatabasePush($database, $table_prefix, bin2hex(random_bytes(16)));
        try {
            $this->tables = $inspection->assert_supported_target();
        } finally {
            $inspection->close();
        }
        if ($this->tables === []) {
            throw new RuntimeException('The local source has no tables for prefix ' . $table_prefix . '.');
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
            $this->write_record(['values' => $values]);
            return true;
        }
        if ($this->tables !== []) {
            $this->current_table = array_shift($this->tables);
            $table = DatabasePush::identifier($this->current_table);
            $ddl = $this->database->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
            DatabasePush::assert_supported_ddl($ddl, $this->current_table);
            $this->columns = [];
            $sizes = [];
            foreach ($this->database->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $column) {
                DatabasePush::identifier($column['Field']);
                if ($column['Field'] === '__reprint_row_bytes') {
                    throw new RuntimeException('Source column __reprint_row_bytes conflicts with the archive row-size check.');
                }
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
            $size = '(' . implode('+', $sizes) . ')';
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
                $select[] = 'IF(' . $size . '>' . self::MAX_ROW_BYTES . ',NULL,' . $value . ') AS ' . $identifier;
            }
            $select[] = $size . ' AS __reprint_row_bytes';
            $this->database->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $this->rows = $this->database->query('SELECT ' . implode(',', $select) . ' FROM ' . $table);
            $this->write_record(['table' => $this->current_table, 'ddl' => $ddl]);
            return true;
        }
        $this->write_record(['end' => true]);
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
        $this->closed = true;
    }

    /**
     * @param array $record {
     *     One table definition, row, or end record. These variants are mutually exclusive.
     *     @type string $table Site table name, for a table definition.
     *     @type string $ddl SHOW CREATE TABLE result, accompanying table.
     *     @type array<string,string|null> $values Column names mapped to base64 values or SQL NULL.
     *     @type bool $end True only for the final record.
     * }
     */
    private function write_record(array $record): void {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($line) > DatabasePush::MAX_RECORD_BYTES) {
            throw new RuntimeException('Prepared database record exceeds the 2 MiB archive record limit.');
        }
        if (fwrite($this->output, $line) !== strlen($line)) {
            throw new RuntimeException('Cannot write a complete database push archive record.');
        }
    }
}
