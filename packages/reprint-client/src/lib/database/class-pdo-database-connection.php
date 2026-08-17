<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

use PDO;
use PDOException;
use RuntimeException;

/** Uses a PDO-compatible database as a Reprint target. */
class PdoDatabaseConnection implements DatabaseConnection {

    private const PREPARED_STATEMENT_CACHE_MAX = 128;

    private ?PDO $query_database;
    private ?PDO $prepared_database;

    /** @var array<string,\PDOStatement> */
    private array $prepared_statements = [];

    /** @var string[] Oldest prepared statement first. */
    private array $prepared_statement_order = [];

    /**
     * @param PDO      $query_database    Connection which accepts target SQL.
     * @param PDO|null $prepared_database Native connection used for prepared statements.
     */
    public function __construct(PDO $query_database, ?PDO $prepared_database = null)
    {
        $this->query_database = $query_database;
        $this->prepared_database = $prepared_database ?? $query_database;
    }

    public function query(string $sql, array $params = []): DatabaseResult
    {
        if ($params !== []) {
            $statement = $this->get_prepared_database()->prepare($sql);
            if ($statement === false) {
                throw new PDOException('The target database could not prepare a query.');
            }
            if (!$statement->execute($params)) {
                throw new PDOException('The target database prepared query failed.');
            }
            return new PdoDatabaseResult($statement);
        }

        $statement = $this->get_query_database()->query($sql);
        if ($statement === false) {
            throw new PDOException('The target database query failed.');
        }
        return new PdoDatabaseResult($statement);
    }

    public function quote(string $value): string
    {
        $quoted = $this->get_prepared_database()->quote($value);
        if ($quoted === false) {
            throw new PDOException('The target database could not quote an SQL value.');
        }
        return $quoted;
    }

    public function exec(string $sql): int
    {
        $affected_rows = $this->get_query_database()->exec($sql);
        if ($affected_rows === false) {
            throw new PDOException('The target database statement failed.');
        }
        return $affected_rows;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->prepared_statements[$sql] ?? null;
        if ($statement === null) {
            $statement = $this->get_prepared_database()->prepare($sql);
            if ($statement === false) {
                throw new PDOException('The target database could not prepare a statement.');
            }
            $this->prepared_statements[$sql] = $statement;
            $this->prepared_statement_order[] = $sql;
            if (count($this->prepared_statement_order) > self::PREPARED_STATEMENT_CACHE_MAX) {
                $oldest_sql = array_shift($this->prepared_statement_order);
                if ($oldest_sql !== null) {
                    unset($this->prepared_statements[$oldest_sql]);
                }
            }
        } else {
            $statement->closeCursor();
        }

        if (!$statement->execute($params)) {
            throw new PDOException('The target database prepared statement failed.');
        }
        $affected_rows = $statement->rowCount();
        $statement->closeCursor();
        return $affected_rows;
    }

    public function beginTransaction(): bool
    {
        return $this->get_query_database()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->get_query_database()->commit();
    }

    public function rollBack(): bool
    {
        return $this->get_query_database()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->get_query_database()->inTransaction();
    }

    public function close(): void
    {
        foreach ($this->prepared_statements as $statement) {
            $statement->closeCursor();
        }
        $this->prepared_statements = [];
        $this->prepared_statement_order = [];
        $this->query_database = null;
        $this->prepared_database = null;
    }

    private function get_query_database(): PDO
    {
        if ($this->query_database === null) {
            throw new RuntimeException('The target database connection is already closed.');
        }
        return $this->query_database;
    }

    private function get_prepared_database(): PDO
    {
        if ($this->prepared_database === null) {
            throw new RuntimeException('The target database connection is already closed.');
        }
        return $this->prepared_database;
    }
}
