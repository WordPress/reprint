<?php
/**
 * PDO-compatible adapter for WP_SQLite_Driver.
 *
 * MySQLDumpProducer expects a PDO connection — prepare(), query(), and the
 * statement methods fetch(), fetchAll(), fetchColumn(), execute().
 * On SQLite sites, the WP_SQLite_Driver is already loaded by WordPress
 * (via the sqlite-database-integration plugin's db.php drop-in) and is
 * available at $wpdb->dbh. This adapter wraps that driver so the dump
 * producer can use it without knowing it's talking to SQLite.
 *
 * Every MySQL query goes through the driver's AST-based translator, which
 * converts it to SQLite on the fly. The result is transparent: the dump
 * producer sends MySQL queries, gets rows back, and produces valid MySQL
 * SQL output.
 */

/**
 * Wraps a WP_SQLite_Driver instance to look like a PDO connection.
 *
 * Only the methods that MySQLDumpProducer and the export endpoints actually
 * use are implemented. Anything else will trigger a clear PHP error rather
 * than silently misbehaving.
 */
class SqliteDriverPDO
{
    /** @var WP_SQLite_Driver */
    private $driver;

    /** @var PDO The raw SQLite PDO for quote() delegation. */
    private $raw_pdo;

    public function __construct(WP_SQLite_Driver $driver, PDO $raw_pdo)
    {
        $this->driver = $driver;
        $this->raw_pdo = $raw_pdo;
    }

    /**
     * Prepares a statement for execution.
     *
     * Returns a SqliteDriverPDOStatement that will substitute parameters
     * and execute through the driver when execute() is called.
     */
    public function prepare(string $sql): SqliteDriverPDOStatement
    {
        return new SqliteDriverPDOStatement($this->driver, $this->raw_pdo, $sql);
    }

    /**
     * Executes a query immediately and returns the result set.
     */
    public function query(string $sql): SqliteDriverPDOStatement
    {
        $stmt = new SqliteDriverPDOStatement($this->driver, $this->raw_pdo, $sql);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Quotes a string for safe inclusion in a query.
     * Delegates to the underlying raw SQLite PDO.
     */
    public function quote(string $value, int $type = PDO::PARAM_STR): string
    {
        return $this->raw_pdo->quote($value, $type);
    }
}

/**
 * PDOStatement-compatible wrapper for WP_SQLite_Driver query results.
 *
 * Collects all result rows eagerly after execution and serves them
 * through fetch/fetchAll/fetchColumn.
 */
class SqliteDriverPDOStatement
{
    /** @var WP_SQLite_Driver */
    private $driver;

    /** @var PDO The raw SQLite PDO for quote() delegation. */
    private $raw_pdo;

    /** @var string */
    private $sql;

    /** @var array Stored result rows after execution. */
    private $rows = [];

    /** @var int Current position for fetch(). */
    private $position = 0;

    /** @var array|null Parameters bound via bindValue(). */
    private $bound_params = null;

    public function __construct(WP_SQLite_Driver $driver, PDO $raw_pdo, string $sql)
    {
        $this->driver = $driver;
        $this->raw_pdo = $raw_pdo;
        $this->sql = $sql;
    }

    /**
     * Executes the prepared statement.
     *
     * Substitutes bound parameters into the query, sends it through
     * WP_SQLite_Driver::query(), and stores the result rows.
     *
     * @param array|null $params Positional or named parameters.
     * @return bool True on success.
     */
    public function execute($params = null): bool
    {
        // Merge in any parameters set via bindValue().
        if ($params === null && $this->bound_params !== null) {
            $params = $this->bound_params;
        }

        $sql = $this->sql;

        if ($params !== null && count($params) > 0) {
            // Find ? placeholder positions outside of string literals.
            $positions = [];
            $len = strlen($sql);
            $in_single = false;
            $in_double = false;
            for ($i = 0; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($ch === "'" && !$in_double) {
                    $in_single = !$in_single;
                } elseif ($ch === '"' && !$in_single) {
                    $in_double = !$in_double;
                } elseif ($ch === '?' && !$in_single && !$in_double) {
                    $positions[] = $i;
                }
            }

            // Replace positional placeholders from right to left so
            // earlier offsets stay valid.
            for ($i = count($positions) - 1; $i >= 0; $i--) {
                if (!array_key_exists($i, $params)) {
                    continue;
                }
                $quoted = $this->raw_pdo->quote($params[$i]);
                $sql = substr_replace($sql, $quoted, $positions[$i], 1);
            }

            // Named parameters (:name style).
            foreach ($params as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $sql = str_replace($key, $this->raw_pdo->quote($value), $sql);
            }
        }

        $this->driver->query($sql);
        $result = $this->driver->get_query_results();
        $this->rows = is_array($result) ? $result : [];

        // WP_SQLite_Driver returns results as arrays of objects (PDO::FETCH_OBJ
        // by default). Convert to associative arrays for PDO compatibility.
        foreach ($this->rows as $i => $row) {
            if (is_object($row)) {
                $this->rows[$i] = (array) $row;
            }
        }

        $this->position = 0;
        return true;
    }

    /**
     * Fetches the next row from the result set.
     *
     * @param int $mode Fetch mode (ignored — always returns associative array).
     * @return array|false Associative array or false when exhausted.
     */
    public function fetch($mode = PDO::FETCH_ASSOC)
    {
        if ($this->position >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->position++];
    }

    /**
     * Returns all remaining rows from the result set.
     *
     * @param int $mode Fetch mode. Supports FETCH_ASSOC (default) and FETCH_COLUMN.
     * @return array
     */
    public function fetchAll($mode = PDO::FETCH_ASSOC)
    {
        $remaining = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);

        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(function ($row) {
                return reset($row);
            }, $remaining);
        }

        return $remaining;
    }

    /**
     * Returns a single column from the next row.
     *
     * @param int $column_number 0-indexed column number.
     * @return mixed|false The column value, or false if no more rows.
     */
    public function fetchColumn(int $column_number = 0)
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        $values = array_values($row);
        return $values[$column_number] ?? false;
    }

    /**
     * Binds a value to a named or positional parameter.
     */
    public function bindValue($parameter, $value, int $type = PDO::PARAM_STR): bool
    {
        if ($this->bound_params === null) {
            $this->bound_params = [];
        }
        $this->bound_params[$parameter] = $value;
        return true;
    }

}
