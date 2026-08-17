<?php

declare(strict_types=1);

namespace Reprint\Importer\Database;

/** The database operations used by Reprint's target-side commands. */
interface DatabaseConnection {

    /**
     * Executes a query which returns rows.
     *
     * @param array<int,mixed> $params Values for the query's question marks.
     */
    public function query(string $sql, array $params = []): DatabaseResult;

    /** Quotes one string for use as an SQL literal. */
    public function quote(string $value): string;

    /**
     * Executes SQL without bound parameters.
     *
     * The mysqli implementation also accepts a complete multi-statement SQL
     * group and drains every result before returning.
     */
    public function exec(string $sql): int;

    /**
     * Executes one statement with positional parameters.
     *
     * @param array<int,mixed> $params Values for the statement's question marks.
     */
    public function execute(string $sql, array $params = []): int;

    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;

    public function inTransaction(): bool;

    /** Closes the native connection. Calling this more than once is safe. */
    public function close(): void;
}
