<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

use mysqli_result;
use PDO;
use RuntimeException;

/** Adapts a mysqli result to Reprint's target database result API. */
class MysqliDatabaseResult implements DatabaseResult {

    private ?mysqli_result $result;

    public function __construct(mysqli_result $result)
    {
        $this->result = $result;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH)
    {
        $row = $this->get_result()->fetch_array($this->get_mysqli_fetch_mode($mode));
        return $row === null ? false : $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_BOTH): array
    {
        $rows = [];
        // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Read one row per iteration.
        while (( $row = $this->fetch($mode) ) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetchColumn(int $column = 0)
    {
        $row = $this->fetch(PDO::FETCH_NUM);
        return $row === false || !array_key_exists($column, $row)
            ? false
            : $row[$column];
    }

    public function closeCursor(): bool
    {
        if ($this->result === null) {
            return true;
        }
        $this->result->free();
        $this->result = null;
        return true;
    }

    private function get_mysqli_fetch_mode(int $mode): int
    {
        if ($mode === PDO::FETCH_ASSOC) {
            return MYSQLI_ASSOC;
        }
        if ($mode === PDO::FETCH_NUM) {
            return MYSQLI_NUM;
        }
        if ($mode === PDO::FETCH_BOTH) {
            return MYSQLI_BOTH;
        }
        throw new RuntimeException('The target database result supports FETCH_ASSOC, FETCH_NUM, and FETCH_BOTH.');
    }

    private function get_result(): mysqli_result
    {
        if ($this->result === null) {
            throw new RuntimeException('The database result is already closed.');
        }
        return $this->result;
    }
}
