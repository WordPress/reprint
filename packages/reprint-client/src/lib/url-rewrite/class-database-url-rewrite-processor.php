<?php
declare(strict_types=1);

namespace Reprint\Importer;

use RuntimeException;
use SqlStatementRewriter;
use WordPress\DataLiberation\DatabaseRowsReader;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI errors contain database identifiers, never HTML.

/**
 * Rewrites one live database record per step through a structured row reader.
 *
 * The reader supplies primary-key-ordered, byte-preserving records. This
 * processor applies only changed non-key columns with a primary-key-scoped
 * UPDATE. It never opens a transaction across steps or requests a table lock.
 */
class DatabaseUrlRewriteProcessor {

    private const READER_PHASE_NEXT_TABLE = 'next_table';
    private const READER_PHASE_NEXT_RECORD = 'next_record';
    private const READER_PHASE_PROCESS_RECORD = 'process_record';

    /** Native PDO connection used for bound updates. */
    private $update_database;

    private SqlStatementRewriter $statement_rewriter;
    private DatabaseRowsReader $row_reader;
    private string $reader_phase = self::READER_PHASE_NEXT_TABLE;
    private int $records_processed;
    private int $records_changed;
    private int $tables_started;
    private ?string $current_table;
    private bool $complete = false;

    /**
     * @param mixed        $database           PDO or a PDO-compatible adapter.
     * @param array|null   $cursor             Cursor returned by get_cursor().
     */
    public function __construct(
        $database,
        SqlStatementRewriter $statement_rewriter,
        ?array $cursor = null
    ) {
        $this->update_database = $database;
        if (method_exists($database, 'get_connection')) {
            $this->update_database = $database->get_connection()->get_pdo();
        }
        $this->statement_rewriter = $statement_rewriter;
        if (
            $cursor !== null &&
            ( ! isset( $cursor['reader_cursor'] ) || ! is_array( $cursor['reader_cursor'] ) )
        ) {
            throw new RuntimeException(
                'The saved db-rewrite-urls reader cursor is missing. Use --abort.'
            );
        }
        $this->row_reader = new DatabaseRowsReader($database, [
            'batch_size' => 1,
        ]);
        if ($cursor !== null) {
            $this->reader_phase = $cursor['reader_phase'] ?? '';
            if (!in_array($this->reader_phase, [
                self::READER_PHASE_NEXT_TABLE,
                self::READER_PHASE_NEXT_RECORD,
                self::READER_PHASE_PROCESS_RECORD,
            ], true)) {
                throw new RuntimeException(
                    'The saved db-rewrite-urls reader phase is invalid. Use --abort.'
                );
            }
            $reader_cursor = $cursor['reader_cursor'];
            $current_table = $reader_cursor['current_table'] ?? null;
            if (!$this->row_reader->restore_cursor_state($reader_cursor)) {
                throw new RuntimeException(
                    'Cannot resume db-rewrite-urls because table ' .
                    $this->row_reader->quote_identifier($current_table) . ' no longer exists.'
                );
            }
        }
        $this->records_processed = (int) ( $cursor['records_processed'] ?? 0 );
        $this->records_changed = (int) ( $cursor['records_changed'] ?? 0 );
        $this->tables_started = (int) ( $cursor['tables_started'] ?? 0 );
        $this->current_table = isset($cursor['current_table'])
            ? (string) $cursor['current_table']
            : null;
        $this->complete = (bool) ( $cursor['complete'] ?? false );
    }

    /**
     * Performs one producer phase transition or one database-record rewrite.
     *
     * @return bool True when another step may be attempted; false after completion.
     */
    public function next_step(): bool
    {
        if ($this->complete) {
            return false;
        }

        if ($this->reader_phase === self::READER_PHASE_NEXT_TABLE) {
            if (!$this->row_reader->has_initialized_tables()) {
                $this->row_reader->initialize_tables_to_process();
            }
            if (!$this->row_reader->move_to_next_table()) {
                $this->complete = true;
                return false;
            }
            $this->reader_phase = self::READER_PHASE_NEXT_RECORD;
            return true;
        }

        if ($this->reader_phase === self::READER_PHASE_PROCESS_RECORD) {
            $record = $this->row_reader->get_current_record();
            if (!is_array($record)) {
                throw new RuntimeException(
                    'The saved db-rewrite-urls record is missing. Use --abort.'
                );
            }
            $this->rewrite_record(
                $this->row_reader->get_current_table(),
                $record,
                $this->row_reader->get_current_primary_key_columns()
            );
            $this->row_reader->clear_current_record();
            $this->reader_phase = self::READER_PHASE_NEXT_RECORD;
            return true;
        }

        if ($this->row_reader->next_record()) {
            $this->reader_phase = self::READER_PHASE_PROCESS_RECORD;
            return true;
        }

        $this->reader_phase = self::READER_PHASE_NEXT_TABLE;
        return true;
    }

    /**
     * @return array {
     *     Durable cursor after the latest completed step.
     *
     *     @type array       $reader_cursor   Structured database row cursor.
     *     @type string      $reader_phase    Next reader action.
     *     @type int         $records_processed Records whose rewrite decision is complete.
     *     @type int         $records_changed  Records changed by this lifecycle.
     *     @type int         $tables_started   Tables in which a record was processed.
     *     @type string|null $current_table    Table containing the last processed record.
     *     @type bool        $complete         Whether the processor is terminal.
     * }
     */
    public function get_cursor(): array
    {
        return [
            'reader_cursor' => $this->row_reader->get_cursor_state(),
            'reader_phase' => $this->reader_phase,
            'records_processed' => $this->records_processed,
            'records_changed' => $this->records_changed,
            'tables_started' => $this->tables_started,
            'current_table' => $this->current_table,
            'complete' => $this->complete,
        ];
    }

    /**
     * @return array {
     *     Current live database rewrite progress.
     *
     *     @type int         $records_processed Records whose rewrite decision is complete.
     *     @type int         $records_changed  Records changed by this lifecycle.
     *     @type int         $tables_started   Tables in which a record was processed.
     *     @type string|null $current_table    Table containing the last processed record.
     * }
     */
    public function get_progress(): array
    {
        return [
            'records_processed' => $this->records_processed,
            'records_changed' => $this->records_changed,
            'tables_started' => $this->tables_started,
            'current_table' => $this->current_table,
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @param list<string>        $primary_key_columns
     */
    private function rewrite_record(
        string $table,
        array $record,
        array $primary_key_columns
    ): void
    {
        if ($primary_key_columns === []) {
            throw new RuntimeException(
                "Cannot rewrite URLs in {$table} because it has records but no primary key."
            );
        }

        $changes = [];
        foreach ($record as $column => $value) {
            if (!is_string($value)) {
                continue;
            }
            $rewritten = $this->statement_rewriter->rewrite_value($value, $table, $column);
            if ($rewritten === $value) {
                continue;
            }
            if (in_array($column, $primary_key_columns, true)) {
                throw new RuntimeException(
                    "Cannot rewrite {$table}.{$column} because it is part of the record cursor's primary key."
                );
            }
            $changes[$column] = $rewritten;
        }

        if ($changes !== []) {
            $this->update_record($table, $record, $primary_key_columns, $changes);
            ++$this->records_changed;
        }
        ++$this->records_processed;
        if ($this->current_table !== $table) {
            $this->current_table = $table;
            ++$this->tables_started;
        }
    }

    /**
     * @param array<string,mixed>  $record
     * @param list<string>         $primary_key_columns
     * @param array<string,string> $changes
     */
    private function update_record(
        string $table,
        array $record,
        array $primary_key_columns,
        array $changes
    ): void {
        $set_parts = [];
        $where_parts = [];
        $params = [];

        foreach ($changes as $column => $rewritten_value) {
            $set_parts[] = "{$this->quote_identifier($column)} = ?";
            $params[] = $rewritten_value;
        }
        foreach ($primary_key_columns as $column) {
            $this->add_primary_key_condition(
                $where_parts,
                $params,
                $column,
                $record[$column]
            );
        }
        // If another connection changes a selected value before this bounded
        // UPDATE, do not overwrite it with a rewrite of the older bytes.
        foreach (array_keys($changes) as $column) {
            $this->add_original_value_condition(
                $where_parts,
                $params,
                $column,
                $record[$column]
            );
        }

        $sql = "UPDATE {$this->quote_identifier($table)} "
            . 'SET ' . implode(', ', $set_parts)
            . ' WHERE ' . implode(' AND ', $where_parts);
        $statement = $this->update_database->prepare($sql);
        if ($statement === false || $statement->execute($params) === false) {
            throw new RuntimeException("Failed to update the selected {$table} record.");
        }
        // Zero rows means either that an earlier attempt committed before its
        // cursor was saved, or that the selected values changed or disappeared.
        // These cases cannot be distinguished for overlapping URL mappings.
        // Complete the pending decision without rewriting the current bytes.
    }

    /**
     * @param list<string> $where_parts
     * @param list<mixed>  $params
     * @param mixed        $value
     */
    private function add_original_value_condition(
        array &$where_parts,
        array &$params,
        string $column,
        $value
    ): void {
        $quoted_column = $this->quote_identifier($column);
        if ($value === null) {
            $where_parts[] = $quoted_column . ' IS NULL';
            return;
        }
        $where_parts[] = "HEX({$quoted_column}) = ?";
        $params[] = strtoupper( bin2hex( (string) $value ) );
    }

    /**
     * @param list<string> $where_parts
     * @param list<mixed>  $params
     * @param mixed        $value
     */
    private function add_primary_key_condition(
        array &$where_parts,
        array &$params,
        string $column,
        $value
    ): void {
        $quoted_column = $this->quote_identifier($column);
        if ($value === null) {
            $where_parts[] = $quoted_column . ' IS NULL';
            return;
        }
        $where_parts[] = "{$quoted_column} = ?";
        $params[] = $value;
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
