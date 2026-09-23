<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database-push/class-database-push-source.php';

use WordPress\Reprint\Server\DatabasePush;
use WordPress\Reprint\Server\MysqliDriverPDO;
use WordPress\Reprint\Server\MySQLDumpProducer;

/** Compare each result with the source, not one migration path with another. */
class DatabaseValueRoundTripTest extends MySQLDumpProducerTestBase {
    /** @dataProvider transferProvider */
    public function testFloatingPointValuesKeepTheirOriginalBits(string $operation, string $driver): void {
        $this->pdo->exec('CREATE TABLE wp_numbers (id DOUBLE PRIMARY KEY, amount DOUBLE, small FLOAT) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO wp_numbers VALUES (1.2345678901234567, 1.7976931348623157e308, 1.2345678), (1.2345678901234569, -2.2250738585072014e-308, -1.2345678), (2, NULL, NULL)');
        $expected = $this->pdo->query('SELECT id, amount, small+0e0 FROM wp_numbers ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $precision = ini_set('precision', '3');
        $serialize_precision = ini_set('serialize_precision', '3');
        try {
            $target = $this->transfer($operation, $driver, 'wp_numbers');
            self::assertSame($expected, $target->query('SELECT id, amount, small+0e0 FROM wp_numbers ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serialize_precision);
        }
    }

    /** @dataProvider transferProvider */
    public function testSetMasksKeepEmptyMembersAndAllUnsignedBits(string $operation, string $driver): void {
        $members = "''";
        for ($index = 1; $index < 64; ++$index) {
            $members .= ",'member" . $index . "'";
        }
        $this->pdo->exec('CREATE TABLE wp_sets (flags SET(' . $members . ') PRIMARY KEY, nullable_flags SET(\'\',\'a\'), payload TEXT) ENGINE=InnoDB');
        foreach (['0', '1', '2', '3', '9223372036854775808', '9223372036854775809', '18446744073709551615'] as $mask) {
            $this->pdo->exec('INSERT INTO wp_sets VALUES (' . $mask . ', ' . (in_array($mask, ['0', '1', '2', '3'], true) ? $mask : 'NULL') . ", REPEAT('x', 4000))");
        }
        $query = 'SELECT CAST(flags AS UNSIGNED), CAST(nullable_flags AS UNSIGNED), payload FROM wp_sets ORDER BY CAST(flags AS UNSIGNED)';
        $expected = $this->pdo->query($query)->fetchAll(PDO::FETCH_NUM);
        $target = $this->transfer($operation, $driver, 'wp_sets');
        self::assertSame($expected, $target->query($query)->fetchAll(PDO::FETCH_NUM));
    }

    /** @dataProvider transferProvider */
    public function testSpatialValuesKeepCoordinatesAndSrid(string $operation, string $driver): void {
        $this->pdo->exec('CREATE TABLE wp_places (id int PRIMARY KEY, shape GEOMETRY) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO wp_places VALUES (1, ST_GeomFromText('POINT(10 20)', 4326)), (2, ST_GeomFromText('GEOMETRYCOLLECTION(POINT(1 2),LINESTRING(3 4,5 6))', 0)), (3, NULL)");
        $query = 'SELECT id, ST_SRID(shape), HEX(ST_AsWKB(shape)), ST_AsText(shape) FROM wp_places ORDER BY id';
        $expected = $this->pdo->query($query)->fetchAll(PDO::FETCH_NUM);
        $target = $this->transfer($operation, $driver, 'wp_places');
        self::assertSame($expected, $target->query($query)->fetchAll(PDO::FETCH_NUM));
    }

    public static function transferProvider(): array {
        return [['pull', 'pdo'], ['push', 'pdo'], ['pull', 'mysqli'], ['push', 'mysqli']];
    }

    private function transfer(string $operation, string $driver, string $table): PDO {
        $dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName;
        $source = $driver === 'mysqli' ? new MysqliDriverPDO($dsn, getenv('DB_USER'), getenv('DB_PASS')) : $this->pdo;
        if ($operation === 'pull') {
            $options = ['tables_to_process' => [$table], 'batch_size' => 1, 'max_statement_size' => 2048];
            $producer = new MySQLDumpProducer($source, $options);
            $sql = '';
            for ($step = 0; $step < 300 && $producer->next_sql_fragment(); ++$step) {
                $sql .= $producer->get_sql_fragment() . "\n";
                $producer = new MySQLDumpProducer($source, $options + ['cursor' => $producer->get_reentrancy_cursor()]);
            }
            self::assertTrue($producer->is_finished(), 'Resume must not repeat a floating-point or SET primary key.');
            return $this->executeDumpInNewDatabase($sql);
        }
        $session = bin2hex(random_bytes(16));
        $target = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $push = new DatabasePush($target, 'wp_', $session);
        $reader = new DatabasePushSource($source, 'wp_', [], $session);
        try {
            $push->start();
            for ($step = 0; $step < 100 && $reader->next_step(); ++$step) {
                $record = $reader->get_record();
                if ($record !== null) {
                    $push->import_record($record);
                    $cursor = $record['cursor'];
                    $reader->close();
                    $reader = new DatabasePushSource($source, 'wp_', [], $session, $cursor, [$table]);
                }
            }
            self::assertSame('ready', $push->get_status()['phase']);
            $push->commit();
            return $target;
        } finally {
            $reader->close();
            $push->close();
        }
    }
}
