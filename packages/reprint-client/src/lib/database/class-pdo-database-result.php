<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

use PDO;
use PDOStatement;
use RuntimeException;

/** Adapts a PDO statement to Reprint's target database result API. */
class PdoDatabaseResult implements DatabaseResult {

    private ?PDOStatement $statement;

    public function __construct(PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH)
    {
        return $this->get_statement()->fetch($mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_BOTH): array
    {
        return $this->get_statement()->fetchAll($mode);
    }

    public function fetchColumn(int $column = 0)
    {
        $row = $this->get_statement()->fetch(PDO::FETCH_NUM);
        return $row === false || !array_key_exists($column, $row)
            ? false
            : $row[$column];
    }

    public function closeCursor(): bool
    {
        if ($this->statement === null) {
            return true;
        }
        try {
            $closed = $this->statement->closeCursor();
        } catch (RuntimeException $error) {
            // WP_PDO_MySQL_On_SQLite result sets are already detached from
            // their SQLite cursor and report closeCursor() as not implemented.
            if ($error->getMessage() !== 'Not implemented') {
                throw $error;
            }
            $closed = true;
        }
        $this->statement = null;
        return $closed;
    }

    private function get_statement(): PDOStatement
    {
        if ($this->statement === null) {
            throw new RuntimeException('The database result is already closed.');
        }
        return $this->statement;
    }
}
