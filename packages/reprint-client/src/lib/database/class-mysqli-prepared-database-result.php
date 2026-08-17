<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use PDO;
use PDOException;
use RuntimeException;

/** Reads rows from a mysqli prepared statement without requiring mysqlnd. */
class MysqliPreparedDatabaseResult implements DatabaseResult {

    private ?mysqli_stmt $statement;

    /** @var string[] */
    private array $column_names = [];

    /** @var array<int,mixed> Values populated by mysqli_stmt::fetch(). */
    private array $column_values = [];

    public function __construct(mysqli_stmt $statement)
    {
        $metadata = $statement->result_metadata();
        if (!$metadata instanceof mysqli_result) {
            $error = $statement->error;
            $statement->close();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
            throw new PDOException('The target database prepared query returned no columns: ' . $error);
        }

        foreach ($metadata->fetch_fields() as $field) {
            $this->column_names[] = $field->name;
            $this->column_values[] = null;
        }
        $metadata->free();

        $bound_values = [];
        foreach ($this->column_values as $index => &$value) {
            $bound_values[$index] = &$value;
        }
        unset($value);
        if (!call_user_func_array([$statement, 'bind_result'], $bound_values)) {
            $error = $statement->error;
            $statement->close();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
            throw new PDOException('The target database could not read a prepared query: ' . $error);
        }

        $this->statement = $statement;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH)
    {
        if ($mode !== PDO::FETCH_ASSOC && $mode !== PDO::FETCH_NUM && $mode !== PDO::FETCH_BOTH) {
            throw new RuntimeException('The target database result supports FETCH_ASSOC, FETCH_NUM, and FETCH_BOTH.');
        }

        $statement = $this->get_statement();
        try {
            $fetched = $statement->fetch();
        } catch (mysqli_sql_exception $error) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
            throw new PDOException(
                'The target database could not fetch a prepared query row: ' . $error->getMessage(),
                $error->getCode(),
                $error,
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ($fetched === null) {
            return false;
        }
        if ($fetched === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
            throw new PDOException('The target database could not fetch a prepared query row: ' . $statement->error);
        }

        $row = [];
        foreach ($this->column_values as $index => $value) {
            if ($mode !== PDO::FETCH_ASSOC) {
                $row[$index] = $value;
            }
            if ($mode !== PDO::FETCH_NUM) {
                $row[$this->column_names[$index]] = $value;
            }
        }
        return $row;
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
        if ($this->statement === null) {
            return true;
        }
        $this->statement->free_result();
        $this->statement->close();
        $this->statement = null;
        return true;
    }

    private function get_statement(): mysqli_stmt
    {
        if ($this->statement === null) {
            throw new RuntimeException('The database result is already closed.');
        }
        return $this->statement;
    }
}
