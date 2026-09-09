<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/database/load.php';

use Reprint\Importer\Database\PdoDatabaseConnection;
use WordPress\Reprint\Server\DatabaseRowsReader;
use WordPress\Reprint\Server\MySQLDumpProducer;
use WordPress\Reprint\Server\SqliteDriverPDO;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class BitColumnsTest extends MySQLDumpProducerTestBase {
    public function testBitResultsHaveAnIntegerWireType(): void
    {
        $this->pdo->exec('CREATE TABLE bits (id INT PRIMARY KEY, flags BIT(64))');
        $this->pdo->exec('INSERT INTO bits VALUES (1, 18446744073709551615)');
        $reader = new DatabaseRowsReader($this->pdo, ['tables_to_process' => ['bits']]);
        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());
        $this->assertSame('18446744073709551615', (string) $reader->get_current_record()['flags']);

        // mysqlnd already decodes native BIT results as numbers. Check the real
        // result metadata so the test also protects drivers which return BIT bytes.
        $property = new ReflectionProperty(DatabaseRowsReader::class, 'current_result_set');
        $result = $property->getValue($reader);
        $this->assertInstanceOf(PDOStatement::class, $result);
        $this->assertSame('LONGLONG', $result->getColumnMeta(1)['native_type']);
    }

    /** @dataProvider stringifyFetchesProvider */
    public function testBitWidthsRoundTripWithoutLosingUnsignedDigits(bool $stringify_fetches): void
    {
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify_fetches);
        $this->pdo->exec(
            'CREATE TABLE bits (id INT PRIMARY KEY, single_bit BIT(1), seven_bits BIT(7), ' .
            'byte_flags BIT(8), nine_bits BIT(9), word_flags BIT(32), ' .
            'signed_range BIT(63), full_range BIT(64), boolean_alias BOOLEAN)'
        );
        $this->pdo->exec(
            'INSERT INTO bits VALUES ' .
            '(1,0,0,0,0,0,0,0,2),' .
            '(2,1,127,255,511,4294967295,9223372036854775807,18446744073709551615,-1),' .
            '(3,1,49,49,256,2147483648,4611686018427387904,9223372036854775808,0),' .
            '(4,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)'
        );

        $sql = $this->getDumpSQL(['batch_size' => 1]);
        $this->assertStringNotContainsString('FROM_BASE64(', $sql);
        $imported = $this->executeDumpInNewDatabase($sql);
        $imported->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify_fetches);
        $this->assertDatabasesEqual($this->pdo, $imported, ['bits']);
    }

    public function testCompositeBitKeysResumeBetweenOversizedValueChunks(): void
    {
        $this->pdo->exec(
            'CREATE TABLE bits (flags BIT(16), part INT, data LONGTEXT, PRIMARY KEY (flags, part))'
        );
        $this->pdo->exec(
            "INSERT INTO bits VALUES (0,1,REPEAT('first',1000)), (0,2,REPEAT('second',1000)), " .
            "(1,1,REPEAT('third',1000)), (256,1,REPEAT('fourth',1000)), (65535,1,REPEAT('last',1000))"
        );
        $options = ['batch_size' => 1, 'max_statement_size' => 2048];
        $producer = $this->createProducer($options);
        $sql = '';
        for ($step = 0; $step < 200; ++$step) {
            if (!$producer->next_sql_fragment()) {
                break;
            }
            $fragment = $producer->get_sql_fragment();
            $this->assertLessThanOrEqual(2048, strlen($fragment));
            $sql .= $fragment . "\n";
            $producer = $this->createProducer($options + ['cursor' => $producer->get_reentrancy_cursor()]);
        }
        $this->assertTrue($producer->is_finished(), 'The resumed export must finish without repeating a key.');
        $this->assertStringContainsString('UPDATE `bits` SET `data` = CONCAT(', $sql);
        $this->assertStringContainsString('`bits`.`flags` = 256', $sql);
        $this->assertStringNotContainsString('CAST(`bits`.`flags` AS BINARY)', $sql);
        $imported = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $imported, ['bits']);
    }

    public function testResumesAnExistingNumericBitCursor(): void
    {
        $this->pdo->exec('CREATE TABLE bits (flags BIT(16) PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO bits VALUES (0),(1),(2),(49),(255),(256),(257),(511),(32768),(65535)');
        // Saved after trunk 248dd565 emitted the complete batch containing key 0.
        // This is an actual producer cursor, not an edited current-version cursor.
        $cursor = file_get_contents(__DIR__ . '/fixtures/bit-numeric-cursor.json');
        $producer = $this->createProducer(['batch_size' => 1, 'cursor' => $cursor]);
        $sql = '';
        for ($step = 0; $step < 30; ++$step) {
            if (!$producer->next_sql_fragment()) {
                break;
            }
            $sql .= $producer->get_sql_fragment() . "\n";
        }
        $this->assertTrue($producer->is_finished());
        $imported = $this->executeDumpInNewDatabase(
            MySQLDumpProducer::get_session_setup_sql() .
            'CREATE TABLE bits (flags BIT(16) PRIMARY KEY); INSERT INTO bits VALUES (0);' . $sql
        );
        $this->assertSame(
            ['0', '1', '2', '49', '255', '256', '257', '511', '32768', '65535'],
            array_map('strval', $imported->query('SELECT flags+0 FROM bits ORDER BY flags')->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    /** @dataProvider sqliteSourceProvider */
    public function testBitValuesRoundTripBetweenMysqlAndSqlite(bool $sqlite_source): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        $sqlite = new WP_PDO_MySQL_On_SQLite(
            'mysql-on-sqlite:path=:memory:;dbname=bit_tests', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $raw_sqlite = $sqlite->get_connection()->get_pdo();
        $raw_sqlite->sqliteCreateFunction('FROM_BASE64', static function ($value) {
            return $value === null ? null : base64_decode($value);
        });
        $source = $sqlite_source ? $sqlite : $this->pdo;
        $source->exec(
            'CREATE TABLE bits (id INT PRIMARY KEY, flags BIT(8)) ' .
            'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $source->exec('INSERT INTO bits VALUES (1,0),(2,1),(3,49),(4,255),(5,NULL)');
        $producer = new MySQLDumpProducer(
            $sqlite_source ? new SqliteDriverPDO($sqlite, $raw_sqlite) : $this->pdo,
            ['tables_to_process' => ['bits'], 'batch_size' => 2]
        );
        $sql = implode("\n", $this->collectAllFragments($producer));
        if ($sqlite_source) {
            $target = $this->executeDumpInNewDatabase($sql);
        } else {
            $target = $sqlite;
            $connection = new PdoDatabaseConnection($sqlite, $raw_sqlite);
            // Exercise the same prepared INSERT fast path as db-apply when the
            // dump contains base64 values; otherwise use the SQL adapter directly.
            $queries = new WP_MySQL_Naive_Query_Stream();
            $queries->append_sql($sql);
            $queries->mark_input_complete();
            while ($queries->next_query()) {
                $query = $queries->get_query();
                $prepared = SQLitePreparedInsertBuilder::build($query);
                if ($prepared !== null) {
                    $connection->execute($prepared['sql'], $prepared['params']);
                } else {
                    $connection->exec($query);
                }
            }
        }
        $values = $target->query('SELECT flags+0 FROM bits ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['0', '1', '49', '255', null], array_map(static function ($value) {
            return $value === null ? null : (string) $value;
        }, $values));
    }

    public function testBitPatternsAreNotRewrittenAsUrls(): void
    {
        $this->pdo->exec('CREATE TABLE bits (flags BIT(64) PRIMARY KEY)');
        // Eight bytes spelling http://a are also a valid BIT(64) flag pattern.
        $this->pdo->exec('INSERT INTO bits VALUES (0x687474703a2f2f61)');
        $sql = $this->getDumpSQL();
        $rewriter = new SqlStatementRewriter(new StructuredDataUrlRewriter(['http://a' => 'http://b']));
        $rewritten = $rewriter->rewrite($sql);
        $this->assertSame($sql, $rewritten);
        $imported = $this->executeDumpInNewDatabase($rewritten);
        $this->assertSame('687474703A2F2F61', $imported->query('SELECT HEX(flags) FROM bits')->fetchColumn());
    }

    public static function stringifyFetchesProvider(): array
    {
        return ['native values' => [false], 'numeric strings' => [true]];
    }

    public static function sqliteSourceProvider(): array
    {
        return ['MySQL to SQLite' => [false], 'SQLite to MySQL' => [true]];
    }
}
