<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Local CLI errors, never HTML.

use WordPress\Reprint\Server\DatabasePush;
use WordPress\Reprint\Server\DatabaseRowsReader;
use WordPress\Reprint\Server\MysqliDriverPDO;
use WordPress\Reprint\Server\MultisiteDatabaseSelection;
use WordPress\Reprint\Server\PdoConstants;

require_once __DIR__ . '/external-merge-sort.php';

/**
 * Retains local rows for review without accepting later edits as the baseline.
 *
 * Capture after pull has finished rewriting URLs and preparing the local site.
 * A missing production order then has no baseline row and cannot become a
 * deletion. These rows describe the local site, not old production values.
 *
 * The read locks cannot survive process death. An interrupted scan restarts;
 * only a complete snapshot is published, with a same-directory rename.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Client workflow, not a WordPress plugin API.
class LocalDatabaseBaseline {
    /** @var PDO|MysqliDriverPDO Dedicated connection; never shared with a writer. */
    private $database;
    /** @var string */
    private $directory;
    /** @var string */
    private $source_identity;

    /**
     * @param PDO|MysqliDriverPDO $database Dedicated local MySQL connection.
     * @param string $directory Private baseline directory, inside remote state.
     * @param string $source_identity Fingerprint of the local connection address.
     */
    public function __construct($database, string $directory, string $source_identity) {
        $this->database = $database;
        $this->directory = $directory;
        $this->source_identity = $source_identity;
    }

    /** @param list<string> $tables Exact table names, including non-prefixed plugin tables. */
    public function capture(array $tables): void {
        if (file_exists($this->directory)) {
            throw new RuntimeException('A local database baseline already exists. Capturing again would accept pending edits. Use a separate state directory for a new baseline.');
        }
        if ($tables === []) {
            throw new InvalidArgumentException('db-baseline requires at least one --table=NAME.');
        }
        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $snapshot_directory = $this->directory . '.scan';
        try {
            $this->scan($tables, $algorithm, null);
            if (!rename($snapshot_directory, $this->directory)) {
                throw new RuntimeException('Cannot publish the completed local database baseline.');
            }
        } finally {
            $this->remove_scan();
        }
    }

    /**
     * @return Generator<int,array> Changes; all column values are base64 text or null.
     *     Each record contains type=database_change, table, action, key, before,
     *     and after. Updates contain only changed columns. Inserts have a null
     *     before row; deletes have a null after row. Nothing advances the baseline.
     */
    public function changes(): Generator {
        if (!is_file($this->directory . '/manifest.json')) {
            throw new RuntimeException('No local database baseline exists. Run db-baseline after preparing the local site and before editing it.');
        }
        $manifest = json_decode(file_get_contents($this->directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($manifest['source_identity'] !== $this->source_identity) {
            throw new RuntimeException('This baseline was captured from a different local database connection. Use the original --source-dsn.');
        }
        if (!in_array($manifest['algorithm'], hash_algos(), true)) {
            throw new RuntimeException('This baseline requires the unavailable hash algorithm ' . $manifest['algorithm'] . '. Use a PHP runtime that provides it.');
        }
        try {
            // Finish every table before emitting changes. A missing table or
            // changed column layout must not look like a list of row deletions.
            $this->scan(array_keys($manifest['tables']), $manifest['algorithm'], $manifest);
            foreach (array_keys($manifest['tables']) as $table) {
                foreach ($this->compare_table($table) as $change) {
                    yield $change;
                }
            }
        } finally {
            $this->remove_scan();
        }
    }

    /**
     * @param list<string> $tables Tables selected at baseline capture.
     * @param string $algorithm Saved fingerprint algorithm; never silently changed.
     * @param array|null $baseline {
     *     Previous manifest, or null for the initial capture.
     *     @type string $source_identity Local connection fingerprint.
     *     @type string $algorithm Row fingerprint algorithm.
     *     @type array $tables Table names mapped to column and primary-key metadata.
     * }
     */
    private function scan(array $tables, string $algorithm, ?array $baseline): void {
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        $locks = [];
        foreach ($tables as $table) {
            if (MultisiteDatabaseSelection::is_internal_table($table)) {
                throw new InvalidArgumentException('Cannot capture Reprint internal table ' . $table . '.');
            }
            $locks[] = DatabasePush::identifier($table) . ' READ';
        }
        $this->remove_scan();
        $snapshot_directory = $this->directory . '.scan';
        if (!mkdir($snapshot_directory, 0700, true)) {
            throw new RuntimeException('Cannot create local database scan directory ' . $snapshot_directory . '.');
        }
        $manifest = ['source_identity' => $this->source_identity, 'algorithm' => $algorithm, 'tables' => []];
        $this->database->exec("SET SESSION time_zone='+00:00', sql_mode=''");
        $this->database->exec('SET NAMES utf8mb4');
        if ($this->database instanceof MysqliDriverPDO) {
            $this->database->set_buffered(false);
        } else {
            $this->database->setAttribute(( defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ), false);
        }
        // One statement locks the selected local tables together, including
        // MyISAM tables. Only SELECT and SHOW queries run while locks are held.
        // Do not fall back to an unlocked scan if LOCK TABLES is not permitted.
        $this->database->exec('LOCK TABLES ' . implode(', ', $locks));
        try {
            foreach ($tables as $table) {
                $reader = new DatabaseRowsReader($this->database, ['tables_to_process' => [$table], 'set_value_format' => 'unsigned']);
                try {
                    if (!$reader->move_to_next_table()) {
                        throw new RuntimeException('Cannot read selected local table ' . $table . '.');
                    }
                    $primary_key = $reader->get_current_primary_key_columns();
                    if ($primary_key === []) {
                        throw new RuntimeException('Local database diff requires a primary key in table ' . $table . '.');
                    }
                    $columns = $this->database->query('SHOW FULL COLUMNS FROM ' . DatabasePush::identifier($table))->fetchAll(PdoConstants::fetch_assoc());
                    $schema = ['primary_key' => $primary_key, 'columns' => []];
                    $select = [];
                    foreach ($columns as $column) {
                        $name = $column['Field'];
                        // Privileges depend on the connecting user, not row layout.
                        unset($column['Privileges']);
                        $schema['columns'][$name] = self::encode_values($column);
                        $select[$name] = \WordPress\Reprint\Server\DatabaseRowFormat::select_expression($reader, $name);
                    }
                    if ($baseline !== null && $baseline['tables'][$table] !== $schema) {
                        throw new RuntimeException('The column layout or primary key changed in local table ' . $table . '. Schema changes are not supported by db-diff.');
                    }
                    $manifest['tables'][$table] = $schema;
                    $this->write_table($reader, $select, $primary_key, $snapshot_directory . '/' . hash('sha256', $table), $algorithm);
                } finally {
                    $reader->close();
                }
            }
        } finally {
            $this->database->exec('UNLOCK TABLES');
        }

        // Database collations need not sort like PHP byte strings. Sort just
        // the small key/hash/offset entries by their encoded keys on both runs;
        // full old values stay on disk and are fetched only for changed rows.
        $sorter = new ExternalMergeSort(static function (string $line): string {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR)[0];
        }, 4 * 1024 * 1024, false, $snapshot_directory);
        foreach ($tables as $table) {
            $index_file = $snapshot_directory . '/' . hash('sha256', $table) . '.index';
            $unsorted_bytes = filesize($index_file);
            $sorter->sort($index_file);
            clearstatcache(true, $index_file);
            // No deduplication or filtering is allowed here. The shared sorter
            // does not check every write; a full disk must not publish a shorter
            // index and later turn missing entries into apparent deletions.
            if ($unsorted_bytes === false || filesize($index_file) !== $unsorted_bytes) {
                throw new RuntimeException('Sorting changed the byte length of local database index ' . $index_file . '. Check available disk space.');
            }
            if (!chmod($index_file, 0600)) {
                throw new RuntimeException('Cannot restrict permissions on sorted local database index ' . $index_file . '.');
            }
        }
        $output = $this->open_private_file($snapshot_directory . '/manifest.json');
        try {
            self::write_line($output, $manifest);
        } finally {
            fclose($output);
        }
    }

    /**
     * @param DatabaseRowsReader $reader Open reader for one selected table.
     * @param array<string,string> $select Column names mapped to SQL expressions.
     * @param list<string> $primary_key Ordered primary-key columns.
     * @param string $file_prefix Snapshot file prefix for this table.
     * @param string $algorithm Fingerprint algorithm from the manifest.
     */
    private function write_table(DatabaseRowsReader $reader, array $select, array $primary_key, string $file_prefix, string $algorithm): void {
        $rows = $this->open_private_file($file_prefix . '.rows');
        try {
            $index = $this->open_private_file($file_prefix . '.index');
            try {
                while ($reader->next_record($select)) {
                    $row = self::encode_values($reader->get_current_record());
                    $key = [];
                    foreach ($primary_key as $column) {
                        $key[$column] = $row[$column];
                    }
                    $line = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
                    self::write_line($index, [json_encode($key, JSON_THROW_ON_ERROR), hash($algorithm, $line), ftell($rows)]);
                    if (fwrite($rows, $line) !== strlen($line)) {
                        throw new RuntimeException('Cannot write a complete local database baseline row.');
                    }
                    $reader->clear_current_record();
                }
            } finally {
                fclose($index);
            }
        } finally {
            fclose($rows);
        }
    }

    /** @return Generator<int,array> One changed row per yield; see changes(). */
    private function compare_table(string $table): Generator {
        $files = [];
        try {
            foreach ([$this->directory, $this->directory . '.scan'] as $directory) {
                foreach (['index', 'rows'] as $extension) {
                    $file = $directory . '/' . hash('sha256', $table) . '.' . $extension;
                    $handle = fopen($file, 'rb');
                    if ($handle === false) {
                        throw new RuntimeException('Cannot read local database snapshot file ' . $file . '.');
                    }
                    $files[] = $handle;
                }
            }
            [$before_index, $before_rows, $after_index, $after_rows] = $files;
            $before = self::read_line($before_index);
            $after = self::read_line($after_index);
            while ($before !== null || $after !== null) {
                $order = $before === null ? 1 : ( $after === null ? -1 : strcmp($before[0], $after[0]) );
                if ($order !== 0 || $before[1] !== $after[1]) {
                    $old_row = $order > 0 ? null : self::read_row($before_rows, $before[2]);
                    $new_row = $order < 0 ? null : self::read_row($after_rows, $after[2]);
                    if ($order === 0) {
                        foreach ($old_row as $column => $value) {
                            if ($value === $new_row[$column]) {
                                unset($old_row[$column], $new_row[$column]);
                            }
                        }
                    }
                    yield [
                        'type' => 'database_change',
                        'table' => $table,
                        'action' => $order < 0 ? 'delete' : ( $order > 0 ? 'insert' : 'update' ),
                        'key' => json_decode($order > 0 ? $after[0] : $before[0], true, 512, JSON_THROW_ON_ERROR),
                        'before' => $old_row,
                        'after' => $new_row,
                    ];
                }
                if ($order <= 0) {
                    $before = self::read_line($before_index);
                }
                if ($order >= 0) {
                    $after = self::read_line($after_index);
                }
            }
        } finally {
            foreach ($files as $handle) {
                fclose($handle);
            }
        }
    }

    /** @param array<string,mixed> $values Row or column-metadata fields, already normalized before encoding.
     *  @return array<string,string|null> Base64 bytes; NULL stays distinct from an empty string.
     */
    private static function encode_values(array $values): array {
        foreach ($values as $column => $value) {
            $values[$column] = $value === null ? null : base64_encode( (string) $value);
        }
        return $values;
    }

    /** @return resource Newly created file containing private database values. */
    private function open_private_file(string $file) {
        $handle = fopen($file, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create local database snapshot file ' . $file . '.');
        }
        if (!chmod($file, 0600)) {
            fclose($handle);
            throw new RuntimeException('Cannot restrict permissions on local database snapshot file ' . $file . '.');
        }
        return $handle;
    }

    /** @param resource $output Snapshot file handle.
     *  @param array $record JSON record: row values, index tuple, or manifest.
     */
    private static function write_line($output, array $record): void {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($output, $line) !== strlen($line)) {
            throw new RuntimeException('Cannot write a complete local database snapshot record.');
        }
    }

    /** @param resource $input Snapshot file handle.
     *  @return array|null Next JSON record, or null at the end of the file.
     */
    private static function read_line($input): ?array {
        $line = fgets($input);
        if ($line === false) {
            if (!feof($input)) {
                throw new RuntimeException('Cannot read local database snapshot record.');
            }
            return null;
        }
        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param resource $input Row file handle.
     *  @param int $offset Byte offset stored in the corresponding index entry.
     *  @return array<string,string|null> Full row, with base64 bytes or NULL per column.
     */
    private static function read_row($input, int $offset): array {
        if (fseek($input, $offset) !== 0) {
            throw new RuntimeException('Cannot seek to local database snapshot row at byte ' . $offset . '.');
        }
        $row = self::read_line($input);
        if ($row === null) {
            throw new RuntimeException('Missing local database snapshot row at byte ' . $offset . '.');
        }
        return $row;
    }

    private function remove_scan(): void {
        $directory = $this->directory . '.scan';
        if (!is_dir($directory)) {
            return;
        }
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isDot() && !unlink($entry->getPathname())) {
                throw new RuntimeException('Cannot remove interrupted local database scan file ' . $entry->getPathname() . '.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Cannot remove local database scan directory ' . $directory . '.');
        }
    }
}
