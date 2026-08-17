<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\DatabaseConnection;
use Reprint\Importer\Database\MysqliDatabaseConnection;
use Reprint\Importer\Database\PdoDatabaseConnection;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';
require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';

class PreparedDatabaseQueryTest extends TestCase {

    private ?DatabaseConnection $database = null;
    private ?\mysqli $mysql_root = null;
    private ?string $mysql_database_name = null;

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->close();
            $this->database = null;
        }
        if ($this->mysql_root !== null && $this->mysql_database_name !== null) {
            $database_name = str_replace('`', '``', $this->mysql_database_name);
            $this->mysql_root->query("DROP DATABASE IF EXISTS `{$database_name}`");
            $this->mysql_root->close();
        }
        $this->mysql_root = null;
        $this->mysql_database_name = null;
        parent::tearDown();
    }

    public static function targetProvider(): array
    {
        return [
            'MySQL through mysqli' => ['mysql'],
            'SQLite through the MySQL-on-SQLite PDO driver' => ['sqlite'],
        ];
    }

    /**
     * @dataProvider targetProvider
     */
    public function testBindsEverySupportedParameterType(string $target): void
    {
        $database = $this->createDatabase($target);
        $result = $database->query(
            'SELECT ? AS integer_value, ? AS true_value, ? AS false_value, ? AS decimal_value, ' .
            '? AS empty_value, ? AS string_value, ? AS binary_value, ? AS null_value',
            [42, true, false, 1.25, '', "quote ' question ? emoji 🚀", "nul\0byte", null]
        );
        $row = $result->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(42, (int) $row['integer_value']);
        $this->assertSame(1, (int) $row['true_value']);
        $this->assertSame(0, (int) $row['false_value']);
        $this->assertEqualsWithDelta(1.25, (float) $row['decimal_value'], 0.000001);
        $this->assertSame('', $row['empty_value']);
        $this->assertSame("quote ' question ? emoji 🚀", $row['string_value']);
        $this->assertSame("nul\0byte", $row['binary_value']);
        $this->assertNull($row['null_value']);
        $this->assertFalse($result->fetch(PDO::FETCH_ASSOC));
        $this->assertTrue($result->closeCursor());
        $this->assertTrue($result->closeCursor());
    }

    /**
     * @dataProvider targetProvider
     */
    public function testTreatsParameterTextOnlyAsData(string $target): void
    {
        $database = $this->createDatabase($target);
        $this->createRecordsTable($database);
        $database->execute(
            'INSERT INTO prepared_query_records (id, value) VALUES (?, ?)',
            [1, 'first']
        );
        $database->execute(
            'INSERT INTO prepared_query_records (id, value) VALUES (?, ?)',
            [2, "x' OR 1 = 1 --"]
        );

        $result = $database->query(
            'SELECT id, value FROM prepared_query_records WHERE value = ?',
            ["x' OR 1 = 1 --"]
        );
        $this->assertSame(2, (int) $result->fetchColumn());
        $this->assertFalse($result->fetchColumn());
        $result->closeCursor();
    }

    /**
     * @dataProvider targetProvider
     */
    public function testCanReuseSqlWithDifferentParameters(string $target): void
    {
        $database = $this->createDatabase($target);
        $this->createRecordsTable($database);
        $database->execute(
            'INSERT INTO prepared_query_records (id, value) VALUES (?, ?)',
            [1, 'first']
        );
        $database->execute(
            'INSERT INTO prepared_query_records (id, value) VALUES (?, ?)',
            [2, 'second']
        );

        $sql = 'SELECT value FROM prepared_query_records WHERE id = ?';
        $first = $database->query($sql, [1]);
        $this->assertSame('first', $first->fetchColumn());
        $first->closeCursor();

        $second = $database->query($sql, [2]);
        $this->assertSame('second', $second->fetchColumn());
        $second->closeCursor();
    }

    /**
     * @dataProvider targetProvider
     */
    public function testSupportsFetchModesAndDuplicateColumnNames(string $target): void
    {
        $database = $this->createDatabase($target);
        $result = $database->query(
            'SELECT ? AS duplicate_value, ? AS duplicate_value, ? AS named_value',
            [1, 2, 'third']
        );
        $row = $result->fetch(PDO::FETCH_BOTH);

        $this->assertSame(1, (int) $row[0]);
        $this->assertSame(2, (int) $row[1]);
        $this->assertSame('third', $row[2]);
        $this->assertSame(2, (int) $row['duplicate_value']);
        $this->assertSame('third', $row['named_value']);
        $result->closeCursor();

        $numeric_result = $database->query(
            'SELECT ? AS duplicate_value, ? AS duplicate_value',
            [3, 4]
        );
        $rows = $numeric_result->fetchAll(PDO::FETCH_NUM);
        $this->assertCount(1, $rows);
        $this->assertSame([3, 4], array_map('intval', $rows[0]));
        $numeric_result->closeCursor();
    }

    /**
     * @dataProvider targetProvider
     */
    public function testDistinguishesNullMissingColumnsAndNoRows(string $target): void
    {
        $database = $this->createDatabase($target);
        $this->createRecordsTable($database);
        $database->execute(
            'INSERT INTO prepared_query_records (id, value) VALUES (?, ?)',
            [1, null]
        );

        $null_result = $database->query(
            'SELECT value FROM prepared_query_records WHERE id = ?',
            [1]
        );
        $this->assertNull($null_result->fetchColumn());
        $null_result->closeCursor();

        $missing_column = $database->query(
            'SELECT value FROM prepared_query_records WHERE id = ?',
            [1]
        );
        $this->assertFalse($missing_column->fetchColumn(1));
        $missing_column->closeCursor();

        $no_rows = $database->query(
            'SELECT value FROM prepared_query_records WHERE id = ?',
            [999]
        );
        $this->assertFalse($no_rows->fetch(PDO::FETCH_ASSOC));
        $this->assertSame([], $no_rows->fetchAll(PDO::FETCH_ASSOC));
        $no_rows->closeCursor();
    }

    /**
     * @dataProvider targetProvider
     */
    public function testClosesPreparedResultsAndRecoversAfterInvalidSql(string $target): void
    {
        $database = $this->createDatabase($target);
        $result = $database->query('SELECT ? AS value', [1]);
        $this->assertTrue($result->closeCursor());

        try {
            $result->fetch(PDO::FETCH_ASSOC);
            $this->fail('A closed prepared result accepted another fetch.');
        } catch (RuntimeException $error) {
            $this->assertSame('The database result is already closed.', $error->getMessage());
        }

        try {
            $database->query('SELECT ? FROM reprint_missing_prepared_query_table', [1]);
            $this->fail('An invalid prepared query did not fail.');
        } catch (PDOException $error) {
            $this->assertNotSame('', $error->getMessage());
        }

        $next_result = $database->query('SELECT ? AS value', [2]);
        $this->assertSame(2, (int) $next_result->fetchColumn());
        $next_result->closeCursor();
    }

    /**
     * @dataProvider targetProvider
     */
    public function testRejectsExtraParameters(string $target): void
    {
        $database = $this->createDatabase($target);
        try {
            $database->query('SELECT ? AS first_value', [1, 2]);
            $this->fail('A prepared query accepted an extra parameter.');
        } catch (PDOException $error) {
            $this->assertNotSame('', $error->getMessage());
        }

        $result = $database->query('SELECT ? AS value', [3]);
        $this->assertSame(3, (int) $result->fetchColumn());
        $result->closeCursor();
    }

    private function createDatabase(string $target): DatabaseConnection
    {
        if ($target === 'mysql') {
            if (!extension_loaded('mysqli')) {
                $this->markTestSkipped('mysqli extension required');
            }
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $user = getenv('DB_USER') ?: 'root';
            $password = getenv('DB_PASS') ?: '';
            try {
                $root = new \mysqli($host, $user, $password);
            } catch (\Throwable $error) {
                $this->markTestSkipped('MySQL not reachable: ' . $error->getMessage());
            }
            if ($root->connect_errno !== 0) {
                $this->markTestSkipped('MySQL not reachable: ' . $root->connect_error);
            }

            $this->mysql_database_name = 'test_prepared_queries_' . bin2hex(random_bytes(4));
            $database_name = str_replace('`', '``', $this->mysql_database_name);
            $root->query(
                "CREATE DATABASE `{$database_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            $this->mysql_root = $root;

            $mysqli = new \mysqli($host, $user, $password, $this->mysql_database_name);
            $mysqli->set_charset('utf8mb4');
            $this->database = new MysqliDatabaseConnection($mysqli);
            return $this->database;
        }

        if ($target === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                $this->markTestSkipped('pdo_sqlite extension required');
            }
            $driver = new \WP_PDO_MySQL_On_SQLite(
                'mysql-on-sqlite:path=:memory:;dbname=prepared_queries',
                null,
                null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->database = new PdoDatabaseConnection(
                $driver,
                $driver->get_connection()->get_pdo()
            );
            return $this->database;
        }

        $this->fail("Unknown target database: {$target}");
    }

    private function createRecordsTable(DatabaseConnection $database): void
    {
        $database->exec(
            'CREATE TABLE prepared_query_records (' .
            'id BIGINT NOT NULL PRIMARY KEY, value TEXT NULL)'
        );
    }
}
