<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\MysqliDatabaseResult;
use Reprint\Importer\Database\PdoDatabaseConnection;

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

        $result = $database->query('SELECT id, value FROM records');
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
}
