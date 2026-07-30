<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\MySQLDumpProducer;

/**
 * Base test class for MySQLDumpProducer tests.
 * Provides database setup/teardown and utility methods.
 */
abstract class MySQLDumpProducerTestBase extends TestCase
{
    protected $pdo;
    protected $dbName;

    protected function setUp(): void
    {
        $host = getenv("DB_HOST") ?: "localhost";
        $user = getenv("DB_USER") ?: "root";
        $pass = getenv("DB_PASS") ?: "";
        $this->dbName = getenv("DB_NAME") ?: "test_mysql_dump";

        // Connect without database selection
        $dsn = "mysql:host={$host};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Create test database
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        $this->pdo->exec(
            "CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        );
        $this->pdo->exec("USE `{$this->dbName}`");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (PDOException $e) {
                // Ignore cleanup errors
            }
        }
        $this->pdo = null;
    }

    /**
     * Creates a producer with given options.
     */
    protected function createProducer(array $options = []): MySQLDumpProducer
    {
        return new MySQLDumpProducer($this->pdo, $options);
    }

    /**
     * Collects all SQL fragments from a producer.
     */
    protected function collectAllFragments(MySQLDumpProducer $producer): array
    {
        $fragments = [];
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) {
                $fragments[] = $fragment;
            }
        }
        return $fragments;
    }

    /**
     * Gets the complete SQL dump as a single string.
     */
    protected function getDumpSQL(array $options = []): string
    {
        $producer = $this->createProducer($options);
        $fragments = $this->collectAllFragments($producer);
        return implode("\n", $fragments);
    }

    /**
     * Executes the dump SQL in a new database and returns the PDO connection.
     */
    protected function executeDumpInNewDatabase(string $sql): PDO
    {
        $host = getenv("DB_HOST") ?: "localhost";
        $user = getenv("DB_USER") ?: "root";
        $pass = getenv("DB_PASS") ?: "";
        $testDbName = $this->dbName . "_import";

        $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
        $pdo->exec(
            "CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        );
        $pdo->exec("USE `{$testDbName}`");

        // Disable FK checks during import
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

        // Execute the dump SQL using multi-query
        // Remove comments first
        $lines = explode("\n", $sql);
        $cleanedLines = array_filter($lines, function ($line) {
            $trimmed = trim($line);
            return $trimmed !== "" && strncmp($trimmed, "--", 2) !== 0;
        });
        $cleanedSQL = implode("\n", $cleanedLines);

        // Execute as single multi-statement query
        $pdo->exec($cleanedSQL);

        // Re-enable FK checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        return $pdo;
    }

    /**
     * Compares data between two databases.
     */
    protected function assertDatabasesEqual(
        PDO $pdo1,
        PDO $pdo2,
        array $tables,
    ): void {
        foreach ($tables as $table) {
            $quoted = $this->quoteIdentifier($table);

            // Compare row counts
            $count1 = $pdo1
                ->query("SELECT COUNT(*) FROM {$quoted}")
                ->fetchColumn();
            $count2 = $pdo2
                ->query("SELECT COUNT(*) FROM {$quoted}")
                ->fetchColumn();
            $this->assertEquals(
                $count1,
                $count2,
                "Row count mismatch for table {$table}",
            );

            // Compare checksums if available
            try {
                $checksum1 = $pdo1->query("CHECKSUM TABLE {$quoted}")->fetch();
                $checksum2 = $pdo2->query("CHECKSUM TABLE {$quoted}")->fetch();
                $this->assertEquals(
                    $checksum1["Checksum"],
                    $checksum2["Checksum"],
                    "Checksum mismatch for table {$table}",
                );
            } catch (PDOException $e) {
                // Checksum not available, skip
            }

            // Compare actual data row by row
            $rows1 = $pdo1
                ->query("SELECT * FROM {$quoted} ORDER BY 1")
                ->fetchAll();
            $rows2 = $pdo2
                ->query("SELECT * FROM {$quoted} ORDER BY 1")
                ->fetchAll();
            $this->assertEquals(
                $rows1,
                $rows2,
                "Data mismatch for table {$table}",
            );
        }
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Asserts that SQL contains a specific pattern.
     */
    protected function assertSQLContains(
        string $needle,
        string $sql,
        string $message = "",
    ): void {
        $this->assertStringContainsString($needle, $sql, $message);
    }

    /**
     * Asserts that SQL does not contain a specific pattern.
     */
    protected function assertSQLNotContains(
        string $needle,
        string $sql,
        string $message = "",
    ): void {
        $this->assertStringNotContainsString($needle, $sql, $message);
    }

    /**
     * Counts occurrences of INSERT statements in SQL.
     */
    protected function countInsertStatements(string $sql): int
    {
        return substr_count(strtolower($sql), "insert into");
    }

    /**
     * Extracts table names from CREATE TABLE statements.
     */
    protected function extractTableNames(string $sql): array
    {
        $names = [];
        $needle = "CREATE TABLE `";
        $offset = 0;
        $len = strlen($needle);
        while (true) {
            $pos = stripos($sql, $needle, $offset);
            if ($pos === false) {
                break;
            }
            $start = $pos + $len;
            $end = strpos($sql, "`", $start);
            if ($end === false) {
                break;
            }
            $names[] = substr($sql, $start, $end - $start);
            $offset = $end + 1;
        }
        return $names;
    }
}
