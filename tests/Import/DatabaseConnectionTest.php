<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\MysqliDatabaseConnection;
use Reprint\Importer\Database\MysqliDatabaseResult;
use Reprint\Importer\Database\MysqliPreparedDatabaseResult;
use Reprint\Importer\Database\PdoDatabaseConnection;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';

class DatabaseConnectionTest extends TestCase {

    public function testPdoConnectionSupportsTheTargetDatabaseContract(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $database = new PdoDatabaseConnection(new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]));
        $database->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT)');
        $this->assertSame("'a''b'", $database->quote("a'b"));
        $this->assertSame(
            1,
            $database->execute('INSERT INTO records (id, value) VALUES (?, ?)', [1, 'first'])
        );

        $result = $database->query(
            'SELECT id, value FROM records WHERE value = ?',
            ['first']
        );
        $this->assertSame(['id' => 1, 'value' => 'first'], $result->fetch(PDO::FETCH_ASSOC));
        $this->assertFalse($result->fetch(PDO::FETCH_ASSOC));
        $this->assertTrue($result->closeCursor());
        $this->assertTrue($result->closeCursor());

        $database->beginTransaction();
        $database->execute('UPDATE records SET value = ? WHERE id = ?', ['changed', 1]);
        $this->assertTrue($database->inTransaction());
        $database->rollBack();
        $this->assertFalse($database->inTransaction());
        $this->assertSame(
            'first',
            $database->query('SELECT value FROM records')->fetchColumn()
        );

        $database->execute('UPDATE records SET value = ? WHERE id = ?', ['second', 1]);
        $this->assertSame(
            'second',
            $database->query('SELECT value FROM records')->fetchColumn()
        );

        $database->execute('INSERT INTO records (id, value) VALUES (?, ?)', [2, null]);
        $this->assertNull(
            $database->query('SELECT value FROM records WHERE id = 2')->fetchColumn()
        );
        $this->assertFalse(
            $database->query('SELECT value FROM records WHERE id = 2')->fetchColumn(1)
        );

        $database->close();
        $database->close();
    }

    public function testMysqliResultUsesTheSameFetchModes(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli extension required');
        }

        $nativeResult = new class() extends \mysqli_result {
            private array $rows = [
                ['id' => 1, 'value' => 'first'],
                ['id' => 2, 'value' => 'second'],
            ];

            public function __construct()
            {
            }

            public function fetch_array(int $mode = MYSQLI_BOTH): array|null|false
            {
                $row = array_shift($this->rows);
                if ($row === null) {
                    return null;
                }
                if ($mode === MYSQLI_ASSOC) {
                    return $row;
                }
                if ($mode === MYSQLI_NUM) {
                    return array_values($row);
                }
                return $row + array_values($row);
            }

            public function free(): void
            {
                $this->rows = [];
            }
        };
        $result = new MysqliDatabaseResult($nativeResult);

        $this->assertSame(['id' => 1, 'value' => 'first'], $result->fetch(PDO::FETCH_ASSOC));
        $this->assertSame([2, 'second'], $result->fetch(PDO::FETCH_NUM));
        $this->assertFalse($result->fetch(PDO::FETCH_ASSOC));
        $this->assertTrue($result->closeCursor());
        $this->assertTrue($result->closeCursor());
    }

    public function testMysqliPreparedResultUsesTheSameFetchModes(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli extension required');
        }

        $metadata = new class() extends \mysqli_result {
            public function __construct()
            {
            }

            public function fetch_fields(): array
            {
                return [
                    (object) ['name' => 'duplicate_value'],
                    (object) ['name' => 'duplicate_value'],
                ];
            }

            public function free(): void
            {
            }
        };
        $statement = new class($metadata) extends \mysqli_stmt {
            private \mysqli_result $metadata;
            private array $rows = [[1, 2], [3, 4], [5, 6]];
            private array $bound_values = [];

            public function __construct(\mysqli_result $metadata)
            {
                $this->metadata = $metadata;
            }

            public function result_metadata(): \mysqli_result|false
            {
                return $this->metadata;
            }

            public function bind_result(mixed &...$vars): bool
            {
                foreach ($vars as $index => &$value) {
                    $this->bound_values[$index] = &$value;
                }
                unset($value);
                return true;
            }

            public function fetch(): ?bool
            {
                $row = array_shift($this->rows);
                if ($row === null) {
                    return null;
                }
                foreach ($row as $index => $value) {
                    $this->bound_values[$index] = $value;
                }
                return true;
            }

            public function free_result(): void
            {
            }

            #[\ReturnTypeWillChange]
            public function close()
            {
                return true;
            }
        };
        $result = new MysqliPreparedDatabaseResult($statement);

        try {
            $result->fetch(PDO::FETCH_OBJ);
            $this->fail('The mysqli prepared result accepted an unsupported fetch mode.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('FETCH_ASSOC, FETCH_NUM, and FETCH_BOTH', $error->getMessage());
        }

        $this->assertSame([1, 2], $result->fetch(PDO::FETCH_NUM));
        $this->assertSame(['duplicate_value' => 4], $result->fetch(PDO::FETCH_ASSOC));
        $this->assertSame(
            [[0 => 5, 'duplicate_value' => 6, 1 => 6]],
            $result->fetchAll(PDO::FETCH_BOTH)
        );
        $this->assertFalse($result->fetch(PDO::FETCH_ASSOC));
        $this->assertTrue($result->closeCursor());
        $this->assertTrue($result->closeCursor());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database result is already closed.');
        $result->fetch(PDO::FETCH_ASSOC);
    }

    public function testMysqliPreparedQueryReportsExtraParametersAsADatabaseError(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli extension required');
        }

        $statement = new class() extends \mysqli_stmt {
            public function __construct()
            {
            }

            public function bind_param(string $types, mixed &...$vars): bool
            {
                throw new \ArgumentCountError('The number of variables must match the number of parameters.');
            }

            #[\ReturnTypeWillChange]
            public function close()
            {
                return true;
            }
        };
        $mysqli = new class($statement) extends \mysqli {
            private \mysqli_stmt $statement;

            public function __construct(\mysqli_stmt $statement)
            {
                $this->statement = $statement;
            }

            public function prepare(string $query): \mysqli_stmt|false
            {
                return $this->statement;
            }
        };
        $database = new MysqliDatabaseConnection($mysqli);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('The target database prepared query failed');
        $database->query('SELECT ? AS value', [1, 2]);
    }
}
