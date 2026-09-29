<?php

namespace WordPress\Reprint\Server;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Protocol errors are JSON text, never HTML.

use RuntimeException;
use Throwable;

/**
 * Applies one reviewed stream in one transaction, including its commit receipt.
 *
 * The multipart closing boundary and the complete review hash must both arrive
 * before commit. A disconnected PHP process loses its transaction, not half a
 * push. A lost success response can be resolved through the receipt. Uploads
 * never resume midway through a transaction.
 */
final class DatabaseChangesPush {
    private const RECEIPTS = '__reprint_db_change_receipts';
    /** @var \PDO|MysqliDriverPDO */
    private $database;
    /** @var string */
    private $push_session_id;
    /** @var string|null */
    private $lock_name;
    /** @var string */
    private $partial_record = '';
    /** @var int */
    private $record_number = 0;
    /** @var int|null */
    private $record_bytes;
    /** @var string|null */
    private $review;
    /** @var resource|object */
    private $hash;
    /** @var bool */
    private $ended = false;
    /** @var bool */
    private $replay = false;
    /** @var int */
    private $changes = 0;
    /** @var string|null */
    private $table;
    /** @var array<string,array<string,mixed>> */
    private $columns = [];
    /** @var list<string> */
    private $primary_key = [];
    /** @var array<string,string> */
    private $select = [];

    /** @param \PDO|MysqliDriverPDO $database Dedicated connection, never WordPress's shared connection. */
    public function __construct($database, string $push_session_id) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $push_session_id)) {
            throw new RuntimeException('Database changes push requires a 32-character hexadecimal push session ID.');
        }
        $this->database = $database;
        $this->push_session_id = $push_session_id;
        $lock_name = 'reprint-db-' . substr(hash('sha256', $database->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $lock = $database->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lock_name]);
        if ( (int) $lock->fetchColumn() !== 1) {
            throw new PushException('busy', 'Another database push holds the target database lock.');
        }
        $this->lock_name = $lock_name;
    }

    /**
     * @return array {
     *     Durable outcome, including after a lost response.
     *     @type string      $phase   complete when a receipt exists; otherwise not_committed.
     *     @type string|null $review  Complete reviewed-stream hash.
     *     @type int         $changes Committed row operations.
     * }
     */
    public function get_status(): array {
        $exists = $this->database->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $exists->execute([self::RECEIPTS]);
        if ( (int) $exists->fetchColumn() === 0) {
            return ['phase' => 'not_committed', 'review' => null, 'changes' => 0];
        }
        $statement = $this->database->prepare('SELECT review, changes FROM `' . self::RECEIPTS . '` WHERE push_session_id=?');
        $statement->execute([$this->push_session_id]);
        $row = $statement->fetch(PdoConstants::fetch_assoc());
        return $row === false ? ['phase' => 'not_committed', 'review' => null, 'changes' => 0]
            : ['phase' => 'complete', 'review' => $row['review'], 'changes' => (int) $row['changes']];
    }

    public function accept_record_chunk(int $record_number, int $total_bytes, int $offset, string $piece): void {
        if ($this->ended || $record_number !== $this->record_number || $offset !== strlen($this->partial_record)
            || $total_bytes < 1 || $total_bytes > DatabasePush::MAX_RECORD_BYTES || $offset + strlen($piece) > $total_bytes
            || ( $this->record_bytes !== null && $this->record_bytes !== $total_bytes )) {
            throw new RuntimeException('Database changes record ' . $record_number . ' has an unexpected size or offset.');
        }
        $this->record_bytes = $total_bytes;
        $this->partial_record .= $piece;
        if (strlen($this->partial_record) !== $total_bytes) {
            return;
        }
        $record = json_decode($this->partial_record, true);
        if (!is_array($record)) {
            throw new RuntimeException('Database changes record ' . $record_number . ' is not a JSON object.');
        }
        if ($record_number === 0) {
            $this->begin($record);
        } else {
            hash_update($this->hash, $this->partial_record);
            if (( $record['type'] ?? '' ) === 'end') {
                $this->ended = true;
            } elseif (!$this->replay) {
                if (( $record['type'] ?? '' ) === 'database_table') {
                    $this->open_table($record);
                } elseif (( $record['type'] ?? '' ) === 'database_change') {
                    $this->apply_change($record);
                } else {
                    throw new RuntimeException('Unknown database changes record type at record ' . $record_number . '.');
                }
            }
        }
        $this->partial_record = '';
        $this->record_bytes = null;
        ++$this->record_number;
    }

    /** Called only after MultipartProcessor has accepted the closing boundary. */
    public function finish_record_request(): void {
        if (!$this->ended || $this->partial_record !== '' || !hash_equals($this->review, hash_final($this->hash))) {
            throw new RuntimeException('The database changes stream is incomplete or differs from the confirmed review.');
        }
        if ($this->replay) {
            return;
        }
        $receipt = $this->database->prepare('INSERT INTO `' . self::RECEIPTS . '` (push_session_id, review, changes) VALUES (?, ?, ?)');
        $receipt->execute([$this->push_session_id, $this->review, $this->changes]);
        $this->database->commit();
    }

    /** Roll back before releasing the same database-wide lock used by full overwrite. */
    public function close(): void {
        try {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
        } finally {
            if ($this->lock_name !== null) {
                $statement = $this->database->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$this->lock_name]);
                $this->lock_name = null;
            }
        }
    }

    /**
     * @param array $record {
     *     Confirmed review sent before any row data.
     *     @type string $type   Must be begin.
     *     @type string $review SHA-256 of every following record, including end.
     * }
     */
    private function begin(array $record): void {
        if (( $record['type'] ?? '' ) !== 'begin' || !is_string($record['review'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $record['review']) || substr($record['review'], 0, 32) !== $this->push_session_id) {
            throw new RuntimeException('The first database changes record must contain the confirmed review hash.');
        }
        $this->review = $record['review'];
        $this->hash = hash_init('sha256');
        $status = $this->get_status();
        if ($status['phase'] === 'complete') {
            if ($status['review'] !== $this->review) {
                throw new RuntimeException('This push session ID has already committed a different review.');
            }
            $this->replay = true;
            return;
        }
        // DDL commits implicitly. Create only the private receipt table here,
        // before opening the transaction that will touch live rows.
        $this->database->exec('CREATE TABLE IF NOT EXISTS `' . self::RECEIPTS . '` (push_session_id char(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, review char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, changes bigint unsigned NOT NULL) ENGINE=InnoDB');
        $engine = $this->database->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $engine->execute([self::RECEIPTS]);
        if ($engine->fetchColumn() !== 'InnoDB') {
            throw new RuntimeException('The database changes receipt table must use InnoDB.');
        }
        $this->database->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION', time_zone='+00:00', foreign_key_checks=1, unique_checks=1, innodb_lock_wait_timeout=5, lock_wait_timeout=5");
        $this->database->beginTransaction();
    }

    /**
     * @param array $record {
     *     Table boundary in the reviewed stream.
     *     @type string $table  Exact target table name.
     *     @type array  $schema Baseline primary_key and base64-encoded SHOW FULL COLUMNS metadata.
     * }
     */
    private function open_table(array $record): void {
        $table = $record['table'] ?? '';
        if (!is_string($table) || MultisiteDatabaseSelection::is_internal_table($table)) {
            throw new RuntimeException('Database changes cannot target Reprint internal tables.');
        }
        $identifier = DatabasePush::identifier($table);
        // Retain a metadata lock until commit. An ALTER TABLE or CREATE TRIGGER
        // must not slip between these checks and the subsequent row mutations.
        $this->database->query('SELECT 1 FROM ' . $identifier . ' WHERE 1=0 FOR UPDATE')->fetchAll();
        $engine = $this->database->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $engine->execute([$table]);
        if ($engine->fetchColumn() !== 'InnoDB') {
            throw new RuntimeException('Selective database push requires an InnoDB base table so failed pushes can roll back: ' . $table . '.');
        }
        // Without TRIGGER privilege MySQL can hide triggers from metadata.
        // Require a visible direct grant rather than interpreting hidden rows
        // as proof that no trigger can write outside this transaction.
        $account = $this->database->query('SELECT CURRENT_USER()')->fetchColumn();
        $separator = strrpos($account, '@');
        $grantee = "'" . substr($account, 0, $separator) . "'@'" . substr($account, $separator + 1) . "'";
        $privilege = $this->database->prepare("SELECT COUNT(*) FROM (SELECT GRANTEE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER' UNION ALL SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER' AND TABLE_SCHEMA=DATABASE() UNION ALL SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES WHERE PRIVILEGE_TYPE='TRIGGER' AND TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?) grants_with_trigger WHERE GRANTEE=?");
        $privilege->execute([$table, $grantee]);
        if ( (int) $privilege->fetchColumn() === 0) {
            throw new RuntimeException('Selective database push needs a direct TRIGGER privilege grant to check triggers on ' . $table . '.');
        }
        $triggers = $this->database->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
        $triggers->execute([$table]);
        if ( (int) $triggers->fetchColumn() !== 0) {
            throw new RuntimeException('Selective database push cannot safely roll back arbitrary trigger side effects on ' . $table . '.');
        }
        // A child in another database can cascade into unselected rows while
        // remaining invisible to an account granted only this database. A
        // global REFERENCES grant exposes those constraints without granting
        // read access to all their data. Do not silently trust an incomplete list.
        $visibility = $this->database->prepare("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=? AND PRIVILEGE_TYPE='REFERENCES'");
        $visibility->execute([$grantee]);
        if ( (int) $visibility->fetchColumn() === 0) {
            throw new RuntimeException('Selective database push requires a direct global REFERENCES grant to rule out hidden cross-database cascades. Ask the host to configure its dedicated push account.');
        }
        $cascades = $this->database->prepare("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=? AND (DELETE_RULE IN ('CASCADE','SET NULL') OR UPDATE_RULE IN ('CASCADE','SET NULL'))");
        $cascades->execute([$table]);
        if ( (int) $cascades->fetchColumn() !== 0) {
            throw new RuntimeException('Selective database push cannot change unselected rows through cascading foreign keys on ' . $table . '.');
        }
        $reader = new DatabaseRowsReader($this->database, ['tables_to_process' => [$table], 'set_value_format' => 'unsigned']);
        try {
            $reader->move_to_next_table();
            $this->primary_key = $reader->get_current_primary_key_columns();
            $this->columns = [];
            $this->select = [];
            $schema = ['primary_key' => $this->primary_key, 'columns' => []];
            foreach ($this->database->query('SHOW FULL COLUMNS FROM ' . $identifier)->fetchAll(PdoConstants::fetch_assoc()) as $column) {
                unset($column['Privileges']);
                $name = $column['Field'];
                $schema['columns'][$name] = array_map(static function ($value) { return $value === null ? null : base64_encode( (string) $value);
}, $column);
                $this->columns[$name] = $column;
                $this->select[$name] = DatabaseRowFormat::select_expression($reader, $name);
            }
            if ($this->primary_key === [] || ( $record['schema'] ?? null ) !== $schema) {
                throw new PushException('conflict', 'The production columns or primary key differ from the baseline for ' . $table . '.');
            }
        } finally {
            $reader->close();
        }
        $this->table = $table;
    }

    /**
     * @param array $record {
     *     One explicitly selected row operation.
     *     @type string     $table  Current table.
     *     @type string     $action insert, update, or delete.
     *     @type array      $key    Base64 primary-key values.
     *     @type array|null $before Expected old values.
     *     @type array|null $after  Requested new values.
     * }
     */
    private function apply_change(array $record): void {
        $action = $record['action'] ?? '';
        try {
            if ($this->table === null || ( $record['table'] ?? null ) !== $this->table || !in_array($action, ['insert', 'update', 'delete'], true)) {
                throw new RuntimeException('The change requires a preceding table definition and an insert, update, or delete action.');
            }
            $key = $record['key'] ?? null;
            if (!is_array($key) || array_keys($key) !== $this->primary_key || in_array(null, $key, true)) {
                throw new RuntimeException('The change must supply every primary-key column, in baseline order, with non-null values.');
            }
            $before = $record['before'] ?? null;
            $after = $record['after'] ?? null;
            if (( $action === 'insert' && ( $before !== null || !is_array($after) || array_keys($after) !== array_keys($this->columns) ) )
                || ( $action === 'delete' && ( $after !== null || !is_array($before) || array_keys($before) !== array_keys($this->columns) ) )
                || ( $action === 'update' && ( !is_array($before) || !is_array($after) || $before === [] || array_keys($before) !== array_keys($after) ) )) {
                throw new RuntimeException('Insert and delete require a complete row; update requires matching nonempty before and after column lists.');
            }
            $where = [];
            foreach ($key as $column => $value) {
                $where[] = DatabasePush::identifier($column) . '=' . $this->value_sql($column, $value);
            }
            $where = implode(' AND ', $where);
            $current = $this->read_row($where);
            if (( $action === 'insert' && $current !== false ) || ( $action !== 'insert' && $current === false )) {
                throw new RuntimeException($action === 'insert' ? 'The primary key already exists.' : 'The primary key does not exist.');
            }
            foreach ($before ?? [] as $column => $value) {
                if (!array_key_exists($column, $this->columns) || !array_key_exists($column, $current) || $current[$column] !== $value) {
                    throw new RuntimeException('The production value differs from before in column ' . $column . '.');
                }
            }
            foreach ($key as $column => $value) {
                if (( $current !== false && $current[$column] !== $value ) || ( $action === 'insert' && $after[$column] !== $value )
                    || ( $action === 'update' && array_key_exists($column, $after) && $after[$column] !== $value )) {
                    throw new RuntimeException('The row does not have the exact designated primary key.');
                }
            }
            $table = DatabasePush::identifier($this->table);
            if ($action === 'delete') {
                $this->database->exec('DELETE FROM ' . $table . ' WHERE ' . $where);
            } else {
                $assignments = [];
                foreach ($after as $column => $value) {
                    if (!isset($this->columns[$column])) {
                        throw new RuntimeException('Unknown column ' . $column . '.');
                    }
                    if (stripos($this->columns[$column]['Extra'], 'GENERATED') !== false && stripos($this->columns[$column]['Extra'], 'DEFAULT_GENERATED') === false) {
                        continue;
                    }
                    $assignments[] = DatabasePush::identifier($column) . '=' . $this->value_sql($column, $value);
                }
                if ($action === 'update') {
                    // Do not let an automatic timestamp assignment modify a
                    // production column absent from the selected local diff.
                    foreach ($this->columns as $column => $metadata) {
                        if (!array_key_exists($column, $after) && stripos($metadata['Extra'], 'on update') !== false) {
                            $assignments[] = DatabasePush::identifier($column) . '=' . DatabasePush::identifier($column);
                        }
                    }
                }
                if ($assignments !== []) {
                    $this->database->exec(( $action === 'insert' ? 'INSERT INTO ' : 'UPDATE ' ) . $table . ' SET ' . implode(', ', $assignments) . ( $action === 'update' ? ' WHERE ' . $where : '' ));
                }
                // Catch coercion, generated-value mismatches, and numeric ENUM
                // labels without trusting affected_rows (zero for a no-op).
                $stored = $this->read_row($where);
                foreach ($after as $column => $value) {
                    if ($stored === false || $stored[$column] !== $value) {
                        throw new RuntimeException('MySQL did not store the requested value in column ' . $column . '.');
                    }
                }
            }
            ++$this->changes;
        } catch (Throwable $exception) {
            throw new PushException('conflict', 'Cannot apply ' . $action . ' to ' . ( $this->table ?? '(no table)' ) . ' at record ' . $this->record_number . ': ' . substr($exception->getMessage(), 0, 1024));
        }
    }

    /** Values stay SQL data: byte strings use hex literals, never string escaping. */
    private function value_sql(string $column, $encoded): string {
        if (!isset($this->columns[$column]) || ( $encoded !== null && !is_string($encoded) )) {
            throw new RuntimeException('Invalid column or encoded value for ' . $column . '.');
        }
        if ($encoded === null) {
            return 'NULL';
        }
        $value = base64_decode($encoded, true);
        if ($value === false || base64_encode($value) !== $encoded) {
            throw new RuntimeException('Column ' . $column . ' requires canonical base64 or null.');
        }
        $type = strtoupper(strtok($this->columns[$column]['Type'], '( '));
        if (in_array($type, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'REAL', 'BIT', 'SET', 'YEAR'], true)) {
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value)) {
                throw new RuntimeException('Column ' . $column . ' requires a decimal numeric value.');
            }
            return $value;
        }
        if ($type === 'ENUM') {
            if (!preg_match('/^([1-9][0-9]*):/D', $value, $match)) {
                throw new RuntimeException('Writing legacy ENUM index zero is not supported in selective database push.');
            }
            return $match[1];
        }
        if (in_array($type, ['GEOMETRY', 'POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT', 'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION'], true)) {
            throw new RuntimeException('Selective database push does not yet write spatial columns.');
        }
        // CONVERT preserves the source column's bytes even when the connection
        // is utf8mb4 and this particular column uses latin1 or another charset.
        $literal = "X'" . bin2hex($value) . "'";
        $collation = $this->columns[$column]['Collation'];
        if ($collation !== null) {
            $charset = explode('_', $collation)[0];
            if (!preg_match('/^[a-zA-Z0-9]+$/D', $charset)) {
                throw new RuntimeException('Unexpected MySQL character set for column ' . $column . '.');
            }
            return 'CONVERT(' . $literal . ' USING ' . $charset . ')';
        }
        return $literal;
    }

    /** @return array<string,string|null>|false Locked row, in the baseline's base64 format, or false when absent. */
    private function read_row(string $where) {
        $lengths = [];
        $select = [];
        foreach ($this->select as $column => $expression) {
            $lengths[] = 'COALESCE(OCTET_LENGTH(' . $expression . '),0)';
            $select[] = $expression . ' AS ' . DatabasePush::identifier($column);
        }
        $table = DatabasePush::identifier($this->table);
        $size = $this->database->query('SELECT ' . implode('+', $lengths) . ' FROM ' . $table . ' WHERE ' . $where . ' FOR UPDATE')->fetchColumn();
        if ($size === false) {
            return false;
        }
        if ( (int) $size > DatabasePush::MAX_RECORD_BYTES) {
            throw new RuntimeException('Production row exceeds the 2 MiB conflict-check limit.');
        }
        $row = $this->database->query('SELECT ' . implode(', ', $select) . ' FROM ' . $table . ' WHERE ' . $where . ' FOR UPDATE')->fetch(PdoConstants::fetch_assoc());
        return array_map(static function ($value) { return $value === null ? null : base64_encode( (string) $value);
}, $row);
    }
}
