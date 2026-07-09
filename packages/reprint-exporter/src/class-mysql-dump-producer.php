<?php

namespace WordPress\DataLiberation;

use PDO;
use PDOStatement;

/**
 * Generates a MySQL dump as a sequence of SQL fragments, one per call to next_sql_fragment().
 *
 * This class exists because shared hosting environments kill long-running PHP processes.
 * A traditional mysqldump would time out on large databases. Instead, this producer
 * yields one SQL fragment at a time — a CREATE TABLE, a batched INSERT, or an UPDATE —
 * and exposes a JSON cursor that captures the full internal state. The caller can
 * serialize that cursor, end the HTTP request, and resume from exactly where it left
 * off in a subsequent request.
 *
 * The producer is a finite state machine that walks through tables sequentially:
 *
 *   INIT → EMIT_HEADER → NEXT_TABLE → CREATE_TABLE → TABLE_HEADER →
 *   START_INSERT ⇄ EMIT_ROW → (EMIT_OVERSIZED_UPDATE) → … → EMIT_FOOTER → FINISHED
 *
 * All values are base64-encoded in the SQL output (via FROM_BASE64('...')). This avoids
 * charset-related corruption: MySQL interprets string literals according to the
 * connection charset, but base64 is pure ASCII and the decoded bytes are assigned
 * directly to the column's declared charset. JSON columns are a special case — MySQL
 * rejects binary charset input for JSON, so those get an extra CONVERT(... USING utf8mb4).
 *
 * Rows that would exceed MySQL's max_allowed_packet are handled by inserting the row
 * with large columns set to empty strings, then appending the real data via a series
 * of UPDATE ... SET col = CONCAT(col, chunk) statements.
 *
 * Known limitations:
 * 
 * - Rows too large to be SELECTed. If a row is larger than max_allowed_packet or the
 *   PHP memory_limit, it won't be exported. The underlying assumption is that WordPress
 *   wouldn't be able to use that data anyway. If that turns out to be wrong, and there
 *   are plugins that use huge blobs with byte offset queries, we'll need to add measures
 *   to detect those situations and export that data in chunks.
 * - Tables without a primary key can't use the oversized row handling as there's no
 *   stable row identifier for the UPDATE ... SET col = CONCAT(col, chunk) WHERE ... query.
 */
class MySQLDumpProducer
{
    const STATE_INIT = "init";
    const STATE_EMIT_HEADER = "emit_header";
    const STATE_NEXT_TABLE = "next_table";
    const STATE_CREATE_TABLE = "create_table";
    const STATE_TABLE_HEADER = "table_header";
    const STATE_START_INSERT = "start_insert";
    const STATE_EMIT_ROW = "emit_row";
    const STATE_EMIT_OVERSIZED_UPDATE = "emit_oversized_update";
    const STATE_EMIT_FOOTER = "emit_footer";
    const STATE_FINISHED = "finished";

    /** @var PDO */
    private $db;

    /** @var string|null */
    private $current_sql_fragment = null;

    /** @var array|null */
    private $current_pk_columns = null;

    /**
     * Cursor bookmark: the PK values of the last emitted row. The next SELECT
     * uses a WHERE clause like `(pk1, pk2) > (last1, last2)` to resume without
     * re-reading earlier rows. Null before the first row of a table.
     *
     * @var array|null
     */
    private $last_pk_values = null;

    /**
     * Fallback cursor for tables without a primary key. Unlike PK-based cursors,
     * OFFSET pagination re-scans earlier rows on every query, so it's slower
     * and vulnerable to drift if rows are inserted or deleted mid-export.
     *
     * @var int
     */
    private $current_offset = 0;

    /** @var string|null */
    private $current_table = null;

    /** @var PDOStatement|null */
    private $current_result_set = null;

    /**
     * Distinguishes "query returned zero rows because the table is exhausted"
     * from "query returned zero rows because we just opened a fresh cursor."
     * Without this, the producer would stop after every batch_size rows.
     *
     * @var int
     */
    private $rows_fetched_from_current_query = 0;

    /** @var array */
    private $tables_to_process;

    /** @var string */
    private $state = self::STATE_INIT;

    /**
     * INFORMATION_SCHEMA column metadata, cached per table to avoid repeated
     * queries. Keyed by table name, then column name. Each entry contains
     * 'data_type' (e.g. 'varchar') and 'column_type' (e.g. 'varchar(255)').
     *
     * @var array
     */
    private $column_type_cache = [];

    /** @var array|null */
    private $current_row = null;

    /** @var int */
    private $rows_in_batch = 0;

    /** @var array|null */
    private $current_column_types = null;

    /** @var array|null */
    private $current_column_names = null;

    /** @var int */
    private $batch_size;

    /** @var bool */
    private $emit_create_table;

    /**
     * Derived from MySQL's max_allowed_packet (at 80% to leave headroom for
     * protocol framing). Rows whose formatted SQL exceeds this limit are split
     * into an INSERT with empty placeholders followed by UPDATE ... CONCAT()
     * statements that append the real data in chunks.
     *
     * @var int
     */
    private $max_statement_size;

    /** @var int|null */
    private $query_time_limit_ms = null;

    /**
     * Row exclusion rules keyed by table name. Each rule is an array with
     * 'column' and 'value' keys.
     *
     * @var array<string, list<array{column: string, value: string}>>
     */
    private $exclude_rows_by_table = [];

    /**
     * When a row is too large for a single INSERT, its big columns are split
     * into chunks and queued here. Each entry tracks the column name, its
     * data type, the current byte offset into the value, and the total value
     * length. The actual data is re-fetched from the database on demand via
     * SUBSTRING queries, keeping cursors small (a few hundred bytes rather
     * than megabytes of raw data).
     *
     * @var array Array of {column: string, data_type: string, byte_offset: int, total_length: int}
     */
    private $oversized_queue = [];

    /** @var array|null */
    private $oversized_pk_values = null;

    /** @var string|null */
    private $state_after_oversized = null;

    /** @var int */
    private $current_statement_size = 0;

    /**
     * @param PDO $db Database connection — either a real PDO (MySQL) or a
     *        PDO-compatible adapter (SQLite sites). No type hint because the
     *        adapter isn't a PDO subclass and PHP 7.4 lacks union types.
     */
    public function __construct($db, $options = [])
    {
        $this->db = $db;
        $this->tables_to_process = $options["tables_to_process"] ?? null;
        $this->batch_size = max(1, (int)($options["batch_size"] ?? 250));
        $this->emit_create_table = (bool)($options["create_table_query"] ?? true);

        if (isset($options["max_statement_size"])) {
            $this->max_statement_size = (int)$options["max_statement_size"];
        } else {
            $this->max_statement_size = $this->detect_max_statement_size();
        }

        if (isset($options["query_time_limit_ms"])) {
            $limit = (int) $options["query_time_limit_ms"];
            $this->query_time_limit_ms = $limit > 0 ? $limit : null;
        }

        if (isset($options["exclude_rows"]) && is_array($options["exclude_rows"])) {
            foreach ($options["exclude_rows"] as $rule) {
                if (
                    !is_array($rule) ||
                    !isset($rule["table"], $rule["column"], $rule["value"]) ||
                    !is_string($rule["table"]) ||
                    !is_string($rule["column"]) ||
                    !is_string($rule["value"])
                ) {
                    continue;
                }
                $this->exclude_rows_by_table[$rule["table"]][] = [
                    "column" => $rule["column"],
                    "value" => $rule["value"],
                ];
            }
        }

        if (isset($options["cursor"])) {
            $this->initialize_from_cursor($options["cursor"]);
        }
    }

    public function get_sql_fragment(): ?string
    {
        return $this->current_sql_fragment;
    }

    public function is_finished(): bool
    {
        return self::STATE_FINISHED === $this->state;
    }

    /**
     * Advances the state machine and populates the next SQL fragment.
     *
     * Call get_sql_fragment() after this returns true to retrieve the SQL.
     * Returns false only when the dump is complete (state = FINISHED).
     */
    public function next_sql_fragment()
    {
        if ($this->is_finished()) {
            return false;
        }

        if (self::STATE_INIT === $this->state) {
            if (null === $this->tables_to_process) {
                $this->initialize_tables_to_process();
            }
            $this->state = self::STATE_EMIT_HEADER;
        }

        while (true) {
            switch ($this->state) {
                case self::STATE_EMIT_HEADER:
                    $this->emit_sql_header();
                    $this->state = self::STATE_NEXT_TABLE;
                    return true;

                case self::STATE_NEXT_TABLE:
                    if ($this->move_to_next_table()) {
                        $this->state = $this->emit_create_table
                            ? self::STATE_CREATE_TABLE
                            : self::STATE_TABLE_HEADER;
                    } else {
                        $this->state = self::STATE_EMIT_FOOTER;
                    }
                    break;

                case self::STATE_EMIT_FOOTER:
                    $this->emit_sql_footer();
                    $this->state = self::STATE_FINISHED;
                    return true;

                case self::STATE_CREATE_TABLE:
                    $this->emit_create_table_statement();
                    $this->state = self::STATE_TABLE_HEADER;
                    return true;

                case self::STATE_TABLE_HEADER:
                    $this->emit_table_header_comment();
                    $this->state = self::STATE_START_INSERT;
                    return true;

                case self::STATE_START_INSERT:
                    if ($this->emit_insert_header()) {
                        return true;
                    }
                    // Empty table — emit_insert_header set state to NEXT_TABLE
                    break;

                case self::STATE_EMIT_ROW:
                    return $this->emit_row();

                case self::STATE_EMIT_OVERSIZED_UPDATE:
                    if ($this->emit_oversized_update()) {
                        return true;
                    }
                    break;

                case self::STATE_FINISHED:
                    return false;
            }
        }

        return false;
    }

    /**
     * Fetches the next row from the current result set into $this->current_row.
     *
     * When the result set is exhausted, checks whether the table has more rows
     * by opening a new query from the current cursor position. Returns false
     * only when a fresh query comes back empty, meaning the table is done.
     */
    private function fetch_and_store_row()
    {
        if (!$this->current_result_set) {
            $query = $this->build_select_query();
            try {
                $this->current_result_set = $this->db->query($query);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    "Database query `{$query}` failed for table " . $this->quote_identifier($this->current_table) . ": " . $e->getMessage()
                );
            }
            $this->rows_fetched_from_current_query = 0;
        }

        $record = $this->current_result_set->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $this->current_result_set = null;

            if ($this->rows_fetched_from_current_query === 0) {
                return false;
            }

            // This batch is exhausted but returned rows earlier, so the table
            // may have more. Open a new query starting after the last PK.
            if ($this->last_pk_values !== null || $this->current_offset > 0) {
                return $this->fetch_and_store_row();
            }

            return false;
        }

        $this->rows_fetched_from_current_query++;

        if ($this->current_column_names === null) {
            $this->current_column_names = array_keys($record);
        }

        if ($this->current_pk_columns && count($this->current_pk_columns) > 0) {
            $this->last_pk_values = [];
            foreach ($this->current_pk_columns as $col) {
                if (!array_key_exists($col, $record)) {
                    throw new \RuntimeException(
                        "Primary key column '{$col}' missing from SELECT result for table " .
                        $this->quote_identifier($this->current_table)
                    );
                }
                $this->last_pk_values[$col] = $record[$col];
            }
        } else {
            $this->current_offset++;
        }

        $this->current_row = $record;
        return true;
    }
    /**
     * Emits "INSERT INTO ... VALUES (first_row)" as a single fragment.
     *
     * The first row is always bundled with the INSERT header to prevent
     * emitting a dangling "INSERT INTO ... VALUES" with no rows — which
     * would happen if the caller saves the cursor right after the header
     * and the data changes before the next request.
     */
    private function emit_insert_header()
    {
        if ($this->current_row === null) {
            if (!$this->fetch_and_store_row()) {
                $this->state = self::STATE_NEXT_TABLE;
                return false;
            }
        }

        $column_list = implode(
            ",",
            array_map(function ($col) {
                return $this->quote_identifier($col);
            }, $this->current_column_names)
        );

        $header = "INSERT INTO " . $this->quote_identifier($this->current_table) . " ({$column_list}) VALUES\n";
        $this->current_statement_size = strlen($header);

        $first_row_sql = $this->format_row_for_insert($this->current_row);
        $this->current_statement_size += strlen($first_row_sql) + 1;

        $this->current_row = null;
        $this->rows_in_batch = 1;

        $has_next_row = $this->fetch_and_store_row();

        // Oversized updates require closing this INSERT with a semicolon so the
        // subsequent UPDATE statements are syntactically separate.
        $has_oversized = $this->has_pending_oversized_updates();

        if (!$has_next_row) {
            $sql = $header . $first_row_sql . ";";
            $this->current_sql_fragment = $sql;
            $this->current_statement_size = 0;
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_NEXT_TABLE;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_NEXT_TABLE;
            }
        } elseif ($this->rows_in_batch >= $this->batch_size || $has_oversized) {
            $sql = $header . $first_row_sql . ";";
            $this->current_sql_fragment = $sql;
            $this->current_statement_size = 0;
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_START_INSERT;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_START_INSERT;
            }
        } else {
            $sql = $header . $first_row_sql . ",";
            $this->current_sql_fragment = $sql;
            $this->state = self::STATE_EMIT_ROW;
        }

        return true;
    }

    /**
     * Emits one row as a SQL fragment, terminated with "," (more rows follow)
     * or ";" (INSERT statement complete).
     */
    private function emit_row()
    {
        if ($this->current_row === null) {
            $this->state = self::STATE_NEXT_TABLE;
            return false;
        }

        $row_sql = $this->format_row_for_insert($this->current_row);
        $this->current_statement_size += strlen($row_sql) + 2;
        $this->current_row = null;
        $this->rows_in_batch++;

        $has_next_row = $this->fetch_and_store_row();
        $has_oversized = $this->has_pending_oversized_updates();

        if (!$has_next_row) {
            $this->current_sql_fragment = $row_sql . ";";
            $this->current_statement_size = 0;
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_NEXT_TABLE;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_NEXT_TABLE;
            }
        } elseif ($this->rows_in_batch >= $this->batch_size || $has_oversized) {
            $this->current_sql_fragment = $row_sql . ";";
            $this->current_statement_size = 0;
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_START_INSERT;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_START_INSERT;
            }
        } else {
            $this->current_sql_fragment = $row_sql . ",";
        }

        return true;
    }

    /**
     * Emits DROP TABLE IF EXISTS followed by the CREATE TABLE from SHOW CREATE TABLE.
     * Also handles views (SHOW CREATE TABLE returns 'Create View' for those).
     */
    private function emit_create_table_statement()
    {
        $quoted_table = $this->quote_identifier($this->current_table);
        try {
            $query = "SHOW CREATE TABLE {$quoted_table}";
            $result = $this->db->query($query);
            $row = $result->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to get CREATE TABLE for {$quoted_table}: " . $e->getMessage() . " Query: {$query}"
            );
        }

        $sql = null;
        if ($row) {
            if (isset($row["Create Table"])) {
                $sql = $row["Create Table"];
            } elseif (isset($row["Create View"])) {
                $sql = $row["Create View"];
            }
        }

        if ($sql) {
            // Prevent breaking the line by identifiers with a newline byte in them.
            $header = "--\n-- Table structure for table ".str_replace("\n",'\n',$quoted_table)."\n--\n\n";
            $drop = "DROP TABLE IF EXISTS {$quoted_table};\n";
            $this->current_sql_fragment = $header . $drop . $sql . ";";
        } else {
            $keys = $row ? implode(", ", array_keys($row)) : "(no row returned)";
            throw new \RuntimeException(
                "SHOW CREATE TABLE {$quoted_table} returned no usable SQL. " .
                "Available keys: {$keys}"
            );
        }
    }

    /**
     * Emits SET statements that disable constraint checks and set a strict SQL mode.
     * These are restored in emit_sql_footer(). Without disabling FK checks, tables
     * that reference each other would need to be imported in dependency order.
     *
     * The SQL_MODE explicitly omits NO_ZERO_DATE and NO_ZERO_IN_DATE. This is
     * intentional: many WordPress databases contain zero dates like '0000-00-00'
     * or '0000-00-00 00:00:00' (e.g. in wp_posts.post_date for drafts). The
     * source server may have been running without those restrictions, and the
     * dump must be importable regardless of the target server's default sql_mode.
     *
     * From the MySQL 8.0 Reference Manual (§5.1.11 "Server SQL Modes"):
     *
     *   NO_ZERO_DATE — [...] The server requires dates to have nonzero month
     *   and day values. If NO_ZERO_DATE is enabled and strict mode is enabled,
     *   '0000-00-00' is not permitted and inserts produce an error. [...]
     *   If NO_ZERO_DATE is disabled, '0000-00-00' is permitted and inserts
     *   produce no warning.
     *
     *   NO_ZERO_IN_DATE — [...] Affects whether the server permits dates in
     *   which the year part is nonzero but the month or day part is 0.
     *   [...] If this mode is disabled, dates with zero parts are permitted
     *   and inserts produce no warning.
     *
     * By omitting both flags while keeping STRICT_TRANS_TABLES, the dump
     * preserves MySQL's permissive behavior toward zero dates during import.
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html#sqlmode_no_zero_date
     * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html#sqlmode_no_zero_in_date
     */
    private function emit_sql_header()
    {
        $header =
            "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n" .
            "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n" .
            // @TODO: Restore STRICT_TRANS_TABLES
            "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';\n" .
            "SET AUTOCOMMIT=0;\n";
        $this->current_sql_fragment = $header;
    }

    /** Emits COMMIT and restores the session variables saved in the header. */
    private function emit_sql_footer()
    {
        $footer =
            "\nCOMMIT;\n" .
            "SET SQL_MODE=@OLD_SQL_MODE;\n" .
            "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n" .
            "SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n";
        $this->current_sql_fragment = $footer;
    }

    /** Emits a SQL comment marking the start of data for the current table. */
    private function emit_table_header_comment()
    {
        $comment = "\n--\n-- Dumping data for table " . str_replace("\n",'\n',$this->quote_identifier($this->current_table)) . "\n--\n";
        $this->current_sql_fragment = $comment;
    }

    /**
     * Builds a SELECT query for the current table's next batch of rows.
     *
     * Non-numeric, non-binary columns are wrapped in CAST(... AS BINARY) so
     * MySQL returns raw bytes rather than re-encoding through the connection
     * charset. This is critical: without it, a latin1 column read over a utf8mb4
     * connection would silently transcode the bytes, and our base64 encoding
     * would capture the transcoded version instead of the original.
     */
    private function build_select_query()
    {
        $table = $this->current_table;
        $select = "SELECT";

        if ($this->query_time_limit_ms !== null) {
            // MySQL optimizer hint — caps this query's wall-clock time so a
            // single slow table can't consume the entire PHP execution budget.
            $select .= " /*+ MAX_EXECUTION_TIME(" .
                $this->query_time_limit_ms .
                ") */";
        }

        if ($this->current_column_types) {
            $select_parts = [];
            foreach ($this->current_column_types as $col_name => $col_info) {
                $quoted = $this->quote_identifier($col_name);
                // Don't cast numeric or already-binary types
                if (
                    $this->is_numeric_type($col_info["data_type"]) ||
                    $this->is_binary_type($col_info["data_type"])
                ) {
                    $select_parts[] = $quoted;
                } else {
                    $select_parts[] = "CAST({$quoted} AS BINARY) AS {$quoted}";
                }
            }
            $query =
                $select .
                " " .
                implode(", ", $select_parts) .
                " FROM " . $this->quote_identifier($table);
        } else {
            $query = $select . " * FROM " . $this->quote_identifier($table);
        }

        $where_conditions = $this->build_row_exclusion_where_conditions();
        if ($this->current_pk_columns && count($this->current_pk_columns) > 0) {
            if ($this->last_pk_values) {
                $where_conditions[] = $this->build_pk_where_clause();
            }

            if ($where_conditions) {
                $query .= " WHERE " . implode(" AND ", array_map(function ($condition) {
                    return "({$condition})";
                }, $where_conditions));
            }

            $order_cols = array_map(function ($col) {
                return $this->quote_identifier($col) . " ASC";
            }, $this->current_pk_columns);
            $query .= " ORDER BY " . implode(", ", $order_cols);
            $query .= " LIMIT {$this->batch_size}";
        } else {
            if ($where_conditions) {
                $query .= " WHERE " . implode(" AND ", array_map(function ($condition) {
                    return "({$condition})";
                }, $where_conditions));
            }

            // Best effort pagination for tables without a primary key.
            if ($this->current_offset > 0) {
                $query .= " LIMIT {$this->batch_size} OFFSET {$this->current_offset}";
            } else {
                $query .= " LIMIT {$this->batch_size}";
            }
        }

        return $query;
    }

    /** Builds WHERE fragments for configured row exclusions on the current table. */
    private function build_row_exclusion_where_conditions(): array
    {
        if (!$this->current_table || empty($this->exclude_rows_by_table[$this->current_table])) {
            return [];
        }

        $conditions = [];
        foreach ($this->exclude_rows_by_table[$this->current_table] as $rule) {
            $column = $rule["column"];
            if (!isset($this->current_column_types[$column])) {
                continue;
            }
            $quoted_col = $this->quote_identifier($column);
            $encoded_value = base64_encode($rule["value"]);
            // Preserve rows with NULL in the filtered column. In SQL, NULL <> value
            // evaluates to UNKNOWN, so without the explicit IS NULL branch those rows
            // would be filtered out even though they do not match the excluded value.
            $conditions[] = "{$quoted_col} IS NULL OR {$quoted_col} <> FROM_BASE64('{$encoded_value}')";
        }
        return $conditions;
    }

    /**
     * Builds a WHERE clause that selects rows strictly after the last emitted PK.
     *
     * For composite primary keys (a, b, c), this produces the lexicographic
     * "greater than" condition:
     *
     *   (a > last_a) OR (a = last_a AND b > last_b) OR (a = last_a AND b = last_b AND c > last_c)
     *
     * This is equivalent to `(a, b, c) > (last_a, last_b, last_c)` but written
     * in expanded form for compatibility with MySQL versions that don't optimize
     * row-value comparisons well.
     */
    private function build_pk_where_clause()
    {
        if (!$this->last_pk_values || count($this->current_pk_columns) === 0) {
            /**
             * When we haven't seen any PK values yet, or when the table doesn't have a primary key,
             * we return a dummy condition that will always be true.
             */
            return "1=1";
        }

        $pk_cols = $this->current_pk_columns;

        if (count($pk_cols) === 1) {
            $col = $pk_cols[0];
            $value = $this->last_pk_values[$col];
            return $this->build_comparison($col, $value, ">");
        }
        $conditions = [];
        $prefix_conditions = [];

        foreach ($pk_cols as $col) {
            $value = $this->last_pk_values[$col];

            $current_condition_parts = $prefix_conditions;
            $current_condition_parts[] = $this->build_comparison(
                $col,
                $value,
                ">"
            );
            $conditions[] =
                "(" . implode(" AND ", $current_condition_parts) . ")";

            $prefix_conditions[] = $this->build_comparison($col, $value, "=");
        }

        return "(" . implode(" OR ", $conditions) . ")";
    }

    /** Builds a single "column op value" SQL expression, handling NULL and quoting. */
    private function build_comparison($column, $value, $operator)
    {
        $quoted_col = $this->quote_identifier($column);
        if ($value === null) {
            return $operator === "="
                ? "{$quoted_col} IS NULL"
                : "{$quoted_col} IS NOT NULL";
        }

        if (is_numeric($value)) {
            return "{$quoted_col} {$operator} {$value}";
        } else {
            $quoted = $this->db->quote($value);
            return "{$quoted_col} {$operator} {$quoted}";
        }
    }

    /** Returns primary key column names in ordinal order, or empty array if none. */
    private function get_primary_key_columns($table)
    {
        $pk_columns = [];

        $query = "SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = 'PRIMARY'
            ORDER BY ORDINAL_POSITION";
        try {
            $db_name = $this->db->query("SELECT DATABASE()")->fetchColumn();
            $stmt = $this->db->prepare($query);
            $stmt->execute([$db_name, $table]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to get primary key columns for " . $this->quote_identifier($table) . ": " . $e->getMessage() . " Query: {$query}"
            );
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pk_columns[] = $row["COLUMN_NAME"];
        }

        return $pk_columns;
    }

    /** Advances to the next table and resets all per-table state. */
    private function move_to_next_table()
    {
        if ($this->tables_to_process === null) {
            return false;
        }

        if (!$this->current_table) {
            $this->current_table = reset($this->tables_to_process) ?: null;
        } else {
            $this->current_table = next($this->tables_to_process) ?: null;
        }

        if ($this->current_table) {
            $this->current_pk_columns = $this->get_primary_key_columns(
                $this->current_table
            );
            $this->last_pk_values = null;
            $this->current_offset = 0;
            $this->current_column_types = $this->get_column_types(
                $this->current_table
            );
            $this->current_column_names = null;
            $this->current_row = null;
            $this->rows_in_batch = 0;

            $this->oversized_queue = [];
            $this->oversized_pk_values = null;
            $this->state_after_oversized = null;
            $this->current_statement_size = 0;
        }

        return (bool) $this->current_table;
    }

    /**
     * Discovers all BASE TABLEs in the current database (excludes views).
     * 
     * @TODO: Use pagination or approach to support large databases with millions of tables.
     */
    private function initialize_tables_to_process()
    {
        $this->tables_to_process = [];

        $db_name = $this->db->query("SELECT DATABASE()")->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        );
        $stmt->execute([$db_name]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->tables_to_process[] = $row["TABLE_NAME"];
        }
    }

    /**
     * Returns a JSON string that captures the producer's complete internal state.
     *
     * The caller can pass this string back as the "cursor" option to a new
     * MySQLDumpProducer to resume exactly where this one left off. The JSON is
     * NOT base64-encoded — that's the HTTP layer's concern (export.php).
     *
     * String and binary values in the in-flight row and oversized chunk queue
     * are wrapped in {"__binary__": "<base64>"} markers because raw binary
     * bytes can't survive JSON encoding.
     */
    public function get_reentrancy_cursor()
    {
        $encoded_current_row = $this->encode_row_for_cursor($this->current_row);
        $encoded_oversized_queue = $this->encode_oversized_queue_for_cursor($this->oversized_queue);

        $json = json_encode([
            "current_table" => $this->current_table,
            "current_pk_columns" => $this->current_pk_columns,
            "last_pk_values" => $this->last_pk_values,
            "current_offset" => $this->current_offset,
            "state" => $this->state,
            "current_row" => $encoded_current_row,
            "rows_in_batch" => $this->rows_in_batch,
            "current_column_names" => $this->current_column_names,
            /**
             * Tracking for rows that are larger than max_allowed_packet or 
             * max_statement_size.
             */
            "oversized_queue" => $encoded_oversized_queue,
            "oversized_pk_values" => $this->oversized_pk_values,
            "state_after_oversized" => $this->state_after_oversized,
            "current_statement_size" => $this->current_statement_size,
        ]);
        if ($json === false) {
            throw new \RuntimeException(
                "Failed to encode reentrancy cursor: " . json_last_error_msg()
            );
        }
        return $json;
    }

    /**
     * Wraps all string values in {"__binary__": base64} for JSON safety.
     * JSON is UTF-8-encoded and cannot express arbitrary binary data. The
     * "__binary__" "type brand" makes it easy to detect and decode binary data
     * when restoring the cursor.
     */
    private function encode_row_for_cursor($row)
    {
        if ($row === null) {
            return null;
        }

        $encoded = [];
        foreach ($row as $col => $value) {
            if ($value !== null && is_string($value)) {
                $encoded[$col] = ['__binary__' => base64_encode($value)];
            } else {
                $encoded[$col] = $value;
            }
        }
        return $encoded;
    }

    /** Reverses encode_row_for_cursor(). */
    private function decode_row_from_cursor($row)
    {
        if ($row === null) {
            return null;
        }

        $decoded = [];
        foreach ($row as $col => $value) {
            if (is_array($value) && isset($value['__binary__'])) {
                $decoded[$col] = base64_decode($value['__binary__']);
            } else {
                $decoded[$col] = $value;
            }
        }
        return $decoded;
    }

    /** Base64-encodes all chunk payloads in the oversized queue for JSON safety. */
    /**
     * The oversized queue entries are already cursor-safe (just column names,
     * data types, and integer offsets), so encoding is a no-op.
     */
    private function encode_oversized_queue_for_cursor($queue)
    {
        return $queue;
    }

    /** Reverses encode_oversized_queue_for_cursor(). */
    private function decode_oversized_queue_from_cursor($queue)
    {
        if (!is_array($queue)) {
            return [];
        }
        $decoded = [];
        foreach ($queue as $item) {
            if (
                !is_array($item) ||
                !isset($item['column'], $item['data_type'], $item['byte_offset'], $item['total_length'])
            ) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: oversized_queue item must contain " .
                    "'column', 'data_type', 'byte_offset', and 'total_length' keys"
                );
            }
            $decoded[] = [
                'column' => $item['column'],
                'data_type' => $item['data_type'],
                'byte_offset' => (int) $item['byte_offset'],
                'total_length' => (int) $item['total_length'],
            ];
        }
        return $decoded;
    }

    /**
     * Restores internal state from a previously-serialized cursor.
     *
     * Re-queries INFORMATION_SCHEMA for column types (the cursor doesn't store
     * them because schema can change between requests). If the current table
     * was dropped between requests, resets to STATE_INIT so the producer
     * gracefully skips forward rather than crashing.
     */
    private function initialize_from_cursor($cursor)
    {
        $cursor_data = json_decode($cursor, true);
        if ($cursor_data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Invalid cursor format: cursor must be valid JSON. ' .
                'JSON error: ' . json_last_error_msg() . '. ' .
                'Received: ' . substr($cursor, 0, 100)
            );
        }
        if (is_array($cursor_data)) {
            $this->current_table = $cursor_data["current_table"] ?? null;
            if ($this->current_table !== null && !is_string($this->current_table)) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: current_table must be string or null, got " . gettype($this->current_table)
                );
            }
            $this->current_pk_columns =
                $cursor_data["current_pk_columns"] ?? null;
            $this->last_pk_values = $cursor_data["last_pk_values"] ?? null;
            $this->current_offset = $cursor_data["current_offset"] ?? 0;
            if (!is_int($this->current_offset) && !is_float($this->current_offset)) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: current_offset must be numeric, got " . gettype($this->current_offset)
                );
            }
            $this->current_offset = (int) $this->current_offset;
            $this->state = $cursor_data["state"] ?? self::STATE_INIT;
            $encoded_row = $cursor_data["current_row"] ?? null;
            $this->current_row = $this->decode_row_from_cursor($encoded_row);
            $this->rows_in_batch = $cursor_data["rows_in_batch"] ?? 0;
            if (!is_int($this->rows_in_batch) && !is_float($this->rows_in_batch)) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: rows_in_batch must be numeric, got " . gettype($this->rows_in_batch)
                );
            }
            $this->rows_in_batch = (int) $this->rows_in_batch;
            $this->current_column_names =
                $cursor_data["current_column_names"] ?? null;

            $encoded_queue = $cursor_data["oversized_queue"] ?? [];
            $this->oversized_queue = $this->decode_oversized_queue_from_cursor($encoded_queue);
            $this->oversized_pk_values = $cursor_data["oversized_pk_values"] ?? null;
            $this->state_after_oversized = $cursor_data["state_after_oversized"] ?? null;

            $this->current_statement_size = $cursor_data["current_statement_size"] ?? 0;

            if ($this->tables_to_process === null) {
                $this->initialize_tables_to_process();

                if ($this->current_table) {
                    $found = false;
                    reset($this->tables_to_process);
                    while (
                        ($table = current($this->tables_to_process)) !== false
                    ) {
                        if ($table === $this->current_table) {
                            $found = true;
                            break;
                        }
                        next($this->tables_to_process);
                    }
                    // Table was dropped between requests — advance to next
                    if (!$found) {
                        $this->current_table = null;
                        $this->state = self::STATE_INIT;
                    }
                }
            }

            if ($this->current_table) {
                $this->current_column_types = $this->get_column_types(
                    $this->current_table
                );
                if (empty($this->current_column_types)) {
                    throw new \RuntimeException(
                        "Table " . $this->quote_identifier($this->current_table) . " was dropped between export requests " .
                        "(no columns found in INFORMATION_SCHEMA)"
                    );
                }
            }
        }
    }

    /** Returns cached INFORMATION_SCHEMA column metadata for a table. */
    private function get_column_types($table_name)
    {
        if (isset($this->column_type_cache[$table_name])) {
            return $this->column_type_cache[$table_name];
        }

        try {
            $database_name = $this->db->query("SELECT DATABASE()")->fetchColumn();

            $stmt = $this->db->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION'
            );
            $stmt->execute([$database_name, $table_name]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to get column types for " . $this->quote_identifier($table_name) . ": " . $e->getMessage()
            );
        }

        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row["COLUMN_NAME"]] = [
                "data_type" => $row["DATA_TYPE"],
                "column_type" => $row["COLUMN_TYPE"],
            ];
        }

        $this->column_type_cache[$table_name] = $columns;

        return $columns;
    }

    /**
     * Formats a single column value as a SQL literal.
     *
     * Numeric types are emitted as bare literals. Everything else — strings,
     * binary, dates, enums — goes through FROM_BASE64(). JSON is special:
     * MySQL rejects binary-charset input for JSON columns, so we wrap with
     * CONVERT(... USING utf8mb4) to decode the base64 into a utf8mb4 string.
     * JSON can only be encoded as UTF-8 or UTF-16, and it's typically UTF-8.
     * As of this version, we do not support UTF-16-encoded JSON data strings.
     *
     * @TODO: Support UTF-16-encoded JSON data strings.
     */
    private function format_value($value, $data_type)
    {
        if ($value === null) {
            return "NULL";
        }

        if ($this->is_numeric_type($data_type)) {
            return (string) $value;
        }

        if (strtoupper($data_type) === "JSON") {
            if ($value === "") {
                return "''";
            }
            $base64 = base64_encode($value);
            return "CONVERT(FROM_BASE64('" . $base64 . "') USING utf8mb4)";
        }

        // Treat all other data types as strings and encode them as base64. This
        // allows us to express all possible text encodings and arbitrary binary values.
        if ($value === "") {
            return "''";
        }
        return "FROM_BASE64('" . base64_encode($value) . "')";
    }

    /**
     * Estimates the byte length of format_value()'s output without actually
     * encoding. Used by format_row_for_insert() to decide whether a row
     * would exceed max_statement_size before doing the expensive encoding.
     */
    private function estimate_formatted_size($value, $data_type)
    {
        if ($value === null) {
            return 4; // NULL
        }

        if ($this->is_numeric_type($data_type)) {
            return strlen((string) $value);
        }

        $len = strlen((string) $value);
        if ($len === 0) {
            return 2; // ''
        }

        /** Base64 output is always ceil(n/3)*4 bytes. */
        $estimated_base64_length = 4 * intdiv($len + 2, 3);
        // FROM_BASE64('<data>') => 15 bytes overhead + base64 length
        return 15 + $estimated_base64_length;
    }

    /** Numeric types are emitted as bare literals (no quoting, no base64). */
    private function is_numeric_type($data_type)
    {
        $data_type = strtoupper($data_type);
        $numeric_types = [
            "TINYINT",
            "SMALLINT",
            "MEDIUMINT",
            "INTEGER",
            "INT",
            "BIGINT",
            "DECIMAL",
            "NUMERIC",
            "FLOAT",
            "DOUBLE",
            "REAL",
            "BIT",
            "YEAR",
        ];

        foreach ($numeric_types as $type) {
            if (strpos($data_type, $type) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Binary columns are not CAST to BINARY in the SELECT (they already are),
     * but they follow the same base64 encoding path as strings.
     */
    private function is_binary_type($data_type)
    {
        $data_type = strtoupper($data_type);
        $binary_types = [
            "BINARY",
            "VARBINARY",
            "TINYBLOB",
            "BLOB",
            "MEDIUMBLOB",
            "LONGBLOB",
        ];

        foreach ($binary_types as $type) {
            if (strpos($data_type, $type) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Returns the DATA_TYPE string for a column, or throws if unknown. */
    private function get_data_type(string $col): string
    {
        if (!isset($this->current_column_types[$col]["data_type"])) {
            throw new \RuntimeException(
                "No column type info for '{$col}' in table " .
                $this->quote_identifier($this->current_table) .
                ". This is a bug — INFORMATION_SCHEMA should have returned it."
            );
        }
        return $this->current_column_types[$col]["data_type"];
    }

    /** Escapes backticks by doubling them: tricky`table → `tricky``table`. */
    private function quote_identifier($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** Auto-detects max_allowed_packet and uses 80% of it. Falls back to 1MB. */
    private function detect_max_statement_size()
    {
        try {
            $result = $this->db->query("SELECT @@max_allowed_packet as max_allowed_packet");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['max_allowed_packet'])) {
                return (int)($row['max_allowed_packet'] * 0.8);
            }
        } catch (\PDOException $e) {
        }

        return 1024 * 1024;
    }

    /**
     * Formats a row as a VALUES tuple, splitting oversized columns if needed.
     *
     * The approach is estimate-first: compute the approximate encoded size of
     * each column before doing the actual (expensive) base64 encoding. If the
     * row fits within max_statement_size, encode everything. If it doesn't,
     * replace the largest non-PK columns with '' and queue their real values
     * as UPDATE ... CONCAT() chunks in $this->oversized_queue.
     *
     * Tables without a primary key can't use the UPDATE fallback (there's no
     * stable row identifier for the WHERE clause), so oversized rows in
     * PK-less tables are emitted as-is — the import may fail, but that's
     * better than silently dropping data.
     */
    private function format_row_for_insert($row)
    {
        $estimated_sizes = [];
        $raw_values = [];

        foreach ($this->current_column_names as $col) {
            $value = $row[$col] ?? null;
            $raw_values[$col] = $value;
            $data_type = $this->get_data_type($col);
            $estimated_sizes[$col] = $this->estimate_formatted_size($value, $data_type);
        }

        // Estimate the size of "(val1,val2,val3)," — values + commas between them + parens + terminator
        $row_size_est = array_sum($estimated_sizes) + count($estimated_sizes) + 3;
        $projected_size = $this->current_statement_size + $row_size_est;

        if ($projected_size <= $this->max_statement_size) {
            $formatted_values = [];
            foreach ($this->current_column_names as $col) {
                $data_type = $this->get_data_type($col);
                $formatted_values[$col] = $this->format_value($raw_values[$col], $data_type);
            }
            return "(" . implode(",", array_values($formatted_values)) . ")";
        }

        // The rest of this method deals with rows that are too large to fit into a single INSERT on
        // the receiving end.

        if (!$this->current_pk_columns || count($this->current_pk_columns) === 0) {
            throw new \RuntimeException(
                "Row in table " . $this->quote_identifier($this->current_table) .
                " exceeds max_statement_size ({$this->max_statement_size} bytes)" .
                " but the table has no primary key, so the oversized row" .
                " cannot be split into UPDATE ... CONCAT() chunks."
            );
        }

        $this->oversized_pk_values = [];
        foreach ($this->current_pk_columns as $pk_col) {
            if (!array_key_exists($pk_col, $row)) {
                throw new \RuntimeException(
                    "Primary key column '{$pk_col}' missing from row for table " .
                    $this->quote_identifier($this->current_table)
                );
            }
            $this->oversized_pk_values[$pk_col] = $row[$pk_col];
        }

        // Split the largest columns first to bring the row under the limit
        $sorted_sizes = $estimated_sizes;
        arsort($sorted_sizes);

        $this->oversized_queue = [];
        $chunked_columns = [];

        $excess = $projected_size - $this->max_statement_size;

        foreach ($sorted_sizes as $col => $size) {
            if (in_array($col, $this->current_pk_columns)) {
                continue;
            }

            if ($size < 1000) {
                continue;
            }

            if ($excess <= 0) {
                break;
            }

            $raw_value = $raw_values[$col];
            if ($raw_value === null || $raw_value === '') {
                continue;
            }

            $data_type = $this->get_data_type($col);
            $value_length = strlen($raw_value);
            $chunk_size = $this->compute_chunk_size($col);

            if ($value_length > $chunk_size) {
                $chunked_columns[$col] = true;
                $excess -= ($size - 2); // Saved bytes (size minus the '' replacement)

                $this->oversized_queue[] = [
                    'column' => $col,
                    'data_type' => $data_type,
                    'byte_offset' => 0,
                    'total_length' => $value_length,
                ];
            }
        }

        if (empty($chunked_columns)) {
            $this->oversized_pk_values = null;
        }

        $formatted_values = [];
        foreach ($this->current_column_names as $col) {
            if (isset($chunked_columns[$col])) {
                $formatted_values[$col] = "''";
                continue;
            }
            $data_type = $this->get_data_type($col);
            $formatted_values[$col] = $this->format_value($raw_values[$col], $data_type);
        }

        return "(" . implode(",", array_values($formatted_values)) . ")";
    }

    /**
     * Computes the maximum raw byte size of each chunk for the given column,
     * such that an UPDATE ... SET col = CONCAT(col, FROM_BASE64('...'))
     * statement stays within max_statement_size.
     */
    private function compute_chunk_size($column)
    {
        $quoted_table = $this->quote_identifier($this->current_table);
        $quoted_column = $this->quote_identifier($column);
        $update_overhead = strlen("UPDATE {$quoted_table} SET {$quoted_column} = CONCAT({$quoted_column}, ) WHERE ;");
        $where_clause_size = $this->estimate_pk_where_size();
        $total_overhead = $update_overhead + $where_clause_size + 100; // Extra margin

        $max_chunk_raw_size = ($this->max_statement_size - $total_overhead);

        // Base64 inflates by ~1.33x, plus FROM_BASE64('') wrapper overhead
        $max_chunk_raw_size = (int)(($max_chunk_raw_size - 20) / 1.34);
        return max($max_chunk_raw_size, 1000);
    }

    /** Rough strlen() estimate for the WHERE pk1 = v1 AND pk2 = v2 clause. */
    private function estimate_pk_where_size()
    {
        if (!$this->oversized_pk_values) {
            /** 
             * A wild guess. 1KB is probably more than necessary, but we're trying to stay
             * on the safe side.
             */
            return 1024;
        }

        $size = 0;
        foreach ($this->oversized_pk_values as $col => $value) {
            $size += strlen($col) + 10; // `col` =
            if ($value === null) {
                $size += 10; // IS NULL
            } elseif (is_numeric($value)) {
                $size += strlen((string)$value);
            } else {
                $size += strlen((string)$value) * 1.1 + 2; // Quoted
            }
            $size += 5; // AND
        }

        return (int)$size;
    }

    /**
     * Emits one UPDATE ... SET col = CONCAT(col, chunk) statement.
     *
     * Instead of storing the entire column value in memory, this method
     * re-reads just the needed chunk from the database using SUBSTRING().
     * This keeps the cursor tiny (byte offsets only) while still producing
     * the correct UPDATE statements.
     *
     * Returns false when the queue is drained, which signals the state machine
     * to transition back to the state saved in $state_after_oversized.
     */
    private function emit_oversized_update()
    {
        if (empty($this->oversized_queue)) {
            if ($this->state_after_oversized === null) {
                throw new \RuntimeException(
                    "State machine bug: state_after_oversized is null when " .
                    "exiting oversized update loop for table " .
                    $this->quote_identifier($this->current_table)
                );
            }
            $this->state = $this->state_after_oversized;
            $this->state_after_oversized = null;
            $this->oversized_pk_values = null;
            return false;
        }

        $current = $this->oversized_queue[0];
        $column = $current['column'];
        $data_type = $current['data_type'];
        $byte_offset = $current['byte_offset'];
        $total_length = $current['total_length'];

        $chunk_size = $this->compute_chunk_size($column);

        // Fetch just the chunk we need from the database using SUBSTRING.
        // MySQL's SUBSTRING is 1-indexed, so add 1 to our 0-based offset.
        $chunk = $this->fetch_value_substring_from_the_current_oversized_row(
            $column,
            $byte_offset + 1,
            $chunk_size
        );

        $formatted_chunk = $this->format_value($chunk, $data_type);

        $where_parts = [];
        foreach ($this->oversized_pk_values as $pk_col => $pk_value) {
            $quoted_pk = $this->quote_identifier($pk_col);
            if ($pk_value === null) {
                $where_parts[] = "{$quoted_pk} IS NULL";
            } elseif (is_numeric($pk_value)) {
                $where_parts[] = "{$quoted_pk} = {$pk_value}";
            } else {
                // Use FROM_BASE64() to avoid having to quote() the emitted value.
                $where_parts[] = "{$quoted_pk} = FROM_BASE64('" . base64_encode($pk_value) . "')";
            }
        }
        $where_clause = implode(" AND ", $where_parts);

        $quoted_table = $this->quote_identifier($this->current_table);
        $quoted_column = $this->quote_identifier($column);
        $sql = "UPDATE {$quoted_table} SET {$quoted_column} = CONCAT({$quoted_column}, {$formatted_chunk}) WHERE {$where_clause};";

        $this->current_sql_fragment = $sql;

        $this->oversized_queue[0]['byte_offset'] += strlen($chunk);
        if ($this->oversized_queue[0]['byte_offset'] >= $total_length) {
            array_shift($this->oversized_queue);
        }

        return true;
    }

    /**
     * Fetches a substring of a column value from the current table using
     * the oversized row's primary key values.
     *
     * Uses CAST(SUBSTRING(...) AS BINARY) to get raw bytes without charset
     * re-encoding — matching the same CAST approach used in the main SELECT.
     */
    private function fetch_value_substring_from_the_current_oversized_row(string $column, int $start, int $length): string
    {
        $quoted_table = $this->quote_identifier($this->current_table);
        $quoted_column = $this->quote_identifier($column);

        $where_parts = [];
        $params = [];
        foreach ($this->oversized_pk_values as $pk_col => $pk_value) {
            $quoted_pk = $this->quote_identifier($pk_col);
            if ($pk_value === null) {
                $where_parts[] = "{$quoted_pk} IS NULL";
            } else {
                $where_parts[] = "{$quoted_pk} = ?";
                $params[] = $pk_value;
            }
        }
        $where_clause = implode(" AND ", $where_parts);

        $sql = "SELECT CAST(SUBSTRING({$quoted_column}, {$start}, {$length}) AS BINARY)"
             . " FROM {$quoted_table} WHERE {$where_clause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();

        if ($result === false) {
            throw new \RuntimeException(
                "Failed to fetch column substring for oversized row: {$column}"
            );
        }

        return $result;
    }

    /** @return bool */
    private function has_pending_oversized_updates()
    {
        return !empty($this->oversized_queue);
    }
}
