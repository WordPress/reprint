<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use PDOException;
use RuntimeException;

/** Presents a PDO-shaped target database API while using mysqli internally. */
class MysqliDatabaseConnection implements DatabaseConnection {

    private ?mysqli $database;
    private bool $transaction_open = false;

    public function __construct(mysqli $database)
    {
        $this->database = $database;
    }

    public function query(string $sql): DatabaseResult
    {
        return $this->run_mysqli_operation('query', function () use ($sql): DatabaseResult {
            $result = $this->get_database()->query($sql);
            if (!$result instanceof mysqli_result) {
                throw $this->new_query_exception('query');
            }
            return new MysqliDatabaseResult($result);
        });
    }

    public function quote(string $value): string
    {
        return "'" . $this->get_database()->real_escape_string($value) . "'";
    }

    public function exec(string $sql): int
    {
        return $this->run_mysqli_operation('statement', function () use ($sql): int {
            $database = $this->get_database();
            if (!$database->multi_query($sql)) {
                throw $this->new_query_exception('statement');
            }

            $affected_rows = 0;
            while (true) {
                $result = $database->store_result();
                if ($result instanceof mysqli_result) {
                    $result->free();
                } elseif ($database->affected_rows > 0) {
                    $affected_rows += $database->affected_rows;
                }
                if ($database->errno !== 0) {
                    throw $this->new_query_exception('statement');
                }
                if (!$database->more_results()) {
                    break;
                }
                if (!$database->next_result()) {
                    throw $this->new_query_exception('statement');
                }
            }
            return $affected_rows;
        });
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run_mysqli_operation('prepared statement', function () use ($sql, $params): int {
            $statement = $this->get_database()->prepare($sql);
            if ($statement === false) {
                throw $this->new_query_exception('prepared statement');
            }

            if ($params !== []) {
                $values = array_values($params);
                $types = '';
                foreach ($values as $value) {
                    if (is_int($value) || is_bool($value)) {
                        $types .= 'i';
                    } elseif (is_float($value)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                }
                $arguments = [$types];
                foreach ($values as $index => &$value) {
                    $arguments[] = &$values[$index];
                }
                unset($value);
                if (!call_user_func_array([$statement, 'bind_param'], $arguments)) {
                    $error = $statement->error;
                    $statement->close();
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
                    throw new PDOException('The target database could not bind a prepared statement: ' . $error);
                }
            }

            if (!$statement->execute()) {
                $error = $statement->error;
                $statement->close();
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
                throw new PDOException('The target database prepared statement failed: ' . $error);
            }
            $affected_rows = max(0, $statement->affected_rows);
            $statement->close();
            return $affected_rows;
        });
    }

    public function beginTransaction(): bool
    {
        $this->run_mysqli_operation('transaction', function (): void {
            if (!$this->get_database()->begin_transaction()) {
                throw $this->new_query_exception('transaction');
            }
        });
        $this->transaction_open = true;
        return true;
    }

    public function commit(): bool
    {
        $this->run_mysqli_operation('commit', function (): void {
            if (!$this->get_database()->commit()) {
                throw $this->new_query_exception('commit');
            }
        });
        $this->transaction_open = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->run_mysqli_operation('rollback', function (): void {
            if (!$this->get_database()->rollback()) {
                throw $this->new_query_exception('rollback');
            }
        });
        $this->transaction_open = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction_open;
    }

    public function close(): void
    {
        if ($this->database === null) {
            return;
        }
        $this->database->close();
        $this->database = null;
        $this->transaction_open = false;
    }

    private function get_database(): mysqli
    {
        if ($this->database === null) {
            throw new RuntimeException('The target database connection is already closed.');
        }
        return $this->database;
    }

    private function new_query_exception(string $operation): PDOException
    {
        $database = $this->get_database();
        return new PDOException(
            "The target database {$operation} failed: {$database->error}",
            $database->errno,
        );
    }

    /**
     * @template T
     * @param callable():T $callback Native mysqli call.
     * @return T
     */
    private function run_mysqli_operation(string $operation, callable $callback)
    {
        try {
            return $callback();
        } catch (mysqli_sql_exception $error) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database errors are CLI text.
            throw new PDOException(
                "The target database {$operation} failed: {$error->getMessage()}",
                $error->getCode(),
                $error,
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }
}
