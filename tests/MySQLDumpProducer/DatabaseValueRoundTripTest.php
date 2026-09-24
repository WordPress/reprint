<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database-push/class-database-push-source.php';

use WordPress\Reprint\Server\DatabasePush;
use WordPress\Reprint\Server\DatabaseRowsReader;
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

    /** @dataProvider setValueFormatProvider */
    public function testMysqlSetDumpKeepsSourceLabelsWhenAppliedToSqlite(string $format): void {
        $this->pdo->exec("CREATE TABLE wp_sets (id INT PRIMARY KEY, flags SET('', 'a', 'O''Reilly', '雪', 'back\\\\slash', 'literal\\\\n'), payload LONGTEXT) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_sets VALUES (1,0,REPEAT('x',4000)), (2,1,''), (3,2,''), (4,3,''), (5,12,''), (6,NULL,''), (7,48,'')");
        $expected = $this->pdo->query('SELECT * FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $sql = $this->getDumpSQL(['set_value_format' => $format, 'batch_size' => 1, 'max_statement_size' => 2048]);
        $root = sys_get_temp_dir() . '/reprint-set-roundtrip-' . bin2hex(random_bytes(5));
        mkdir($root);
        $sqlite = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wordpress');
        $connection = new \Reprint\Importer\Database\PdoDatabaseConnection($sqlite, $sqlite->get_connection()->get_pdo());
        try {
            $client = new ImportClient('https://source.example/', $root, $root . '/files');
            (new ReflectionMethod(ImportClient::class, 'create_database_import_position_table'))->invoke($client, $connection);
            (new ReflectionMethod(ImportClient::class, 'execute_database_import_group'))->invoke(
                $client, $connection, $sql, hash('sha256', 'set-roundtrip'), 'next-cursor', null, 'sqlite'
            );
            self::assertSame($expected, $sqlite->query('SELECT * FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $connection->close();
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public static function setValueFormatProvider(): array {
        return [['label'], ['unsigned']];
    }

    public function testLegacySetLabelCursorRequiresANewTransfer(): void {
        $this->pdo->exec("CREATE TABLE wp_sets (flags SET('2', '1') PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec('INSERT INTO wp_sets VALUES (1), (2)');
        // Before unsigned SET reads, get_cursor_state() encoded the label '2'
        // after reading mask 1. Treating it as mask 2 would skip the second row.
        $legacy_cursor = [
            'current_table' => 'wp_sets',
            'current_pk_columns' => ['flags'],
            'last_pk_values' => ['flags' => ['__binary__' => base64_encode('2')]],
            'current_row' => null,
        ];
        $reader = new DatabaseRowsReader($this->pdo, ['set_value_format' => 'unsigned', 'tables_to_process' => ['wp_sets']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SET value format changed from label to unsigned');
        $reader->restore_cursor_state($legacy_cursor);
    }

    public function testDefaultSetLabelsResumeFromACursorWithoutAFormatMarker(): void {
        $this->pdo->exec("CREATE TABLE wp_sets (flags SET('2', '1') PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec('INSERT INTO wp_sets VALUES (1), (2)');
        $options = ['tables_to_process' => ['wp_sets'], 'batch_size' => 1];
        $reader = new DatabaseRowsReader($this->pdo, $options);
        $reader->move_to_next_table();
        self::assertTrue($reader->next_record());
        // Label order is '1', '2'; mask order would be 1, 2. An old cursor
        // must keep label comparisons rather than skip or repeat a row.
        self::assertSame('1', $reader->get_current_record()['flags']);
        $cursor = $reader->get_cursor_state();
        self::assertSame('label', $cursor['set_value_format']);
        unset($cursor['set_value_format']);
        $reader->close();
        $reader = new DatabaseRowsReader($this->pdo, $options);
        $reader->restore_cursor_state($cursor);
        self::assertTrue($reader->next_record());
        self::assertSame('2', $reader->get_current_record()['flags']);
        self::assertFalse($reader->next_record());
    }

    public function testUnsignedSetCursorCannotResumeAsLabels(): void {
        $this->pdo->exec("CREATE TABLE wp_sets (flags SET('2', '1') PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec('INSERT INTO wp_sets VALUES (1), (2)');
        $reader = new DatabaseRowsReader($this->pdo, ['set_value_format' => 'unsigned', 'tables_to_process' => ['wp_sets']]);
        $reader->move_to_next_table();
        self::assertTrue($reader->next_record());
        $cursor = $reader->get_cursor_state();
        $reader->close();
        $reader = new DatabaseRowsReader($this->pdo, ['set_value_format' => 'label', 'tables_to_process' => ['wp_sets']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SET value format changed from unsigned to label');
        $reader->restore_cursor_state($cursor);
    }

    /** @dataProvider invalidSetValueFormatProvider */
    public function testInvalidSetValueFormatsAreRejected($format): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('set_value_format must be label or unsigned');
        new DatabaseRowsReader($this->pdo, ['set_value_format' => $format]);
    }

    public static function invalidSetValueFormatProvider(): array {
        return [[null], [false], ['numeric'], [[]]];
    }

    public static function transferProvider(): array {
        return [['pull', 'pdo'], ['push', 'pdo'], ['pull', 'pdo-stringify'], ['push', 'pdo-stringify'], ['pull', 'mysqli'], ['push', 'mysqli']];
    }

    private function transfer(string $operation, string $driver, string $table): PDO {
        $dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->dbName;
        $source = $driver === 'mysqli' ? new MysqliDriverPDO($dsn, getenv('DB_USER'), getenv('DB_PASS')) : $this->pdo;
        if ($driver === 'pdo-stringify') {
            $source->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        }
        if ($operation === 'pull') {
            $options = ['set_value_format' => 'unsigned', 'tables_to_process' => [$table], 'batch_size' => 1, 'max_statement_size' => 2048];
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
