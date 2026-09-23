<?php

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\DatabaseRowsReader;
use WordPress\Reprint\Server\MySQLDumpProducer;

require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
final class DatabaseRowsReaderSqliteTest extends TestCase {
    /** @var string */
    private $database_path;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->database_path = tempnam(sys_get_temp_dir(), 'reprint-reader-');
        $database = new PDO('sqlite:' . $this->database_path);
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec(
            'CREATE TABLE wp_posts (' .
            'ID INTEGER PRIMARY KEY, post_content TEXT NOT NULL)'
        );
        $database->exec(
            "INSERT INTO wp_posts VALUES " .
            "(1, 'https://old.example/one'), (2, 'https://old.example/two')"
        );
    }

    protected function tearDown(): void
    {
        if (is_string($this->database_path) && file_exists($this->database_path)) {
            unlink($this->database_path);
        }
    }

    public function testDoubleCursorAndReloadKeepAllDigitsWithLowPhpPrecision(): void
    {
        $database = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wordpress');
        $database->exec('CREATE TABLE wp_numbers (id DOUBLE PRIMARY KEY, flags SET(\'\',\'a\'))');
        $database->exec("INSERT INTO wp_numbers VALUES (1.2345678901234567, 'a'), (1.2345678901234569, '')");
        $precision = ini_set('precision', '3');
        $serialize_precision = ini_set('serialize_precision', '3');
        try {
            $options = ['tables_to_process' => ['wp_numbers'], 'batch_size' => 1];
            $reader = new DatabaseRowsReader($database, $options);
            $this->assertTrue($reader->move_to_next_table());
            $this->assertTrue($reader->next_record());
            $this->assertSame('1.2345678901234567', (string) $reader->get_current_record()['id']);
            // SQLite stores SET labels as text, not MySQL's numeric masks.
            $this->assertSame('a', $reader->get_current_record()['flags']);
            $cursor = json_decode(json_encode($reader->get_cursor_state()), true);
            $reader->close();
            $reader = new DatabaseRowsReader($database, $options);
            $reader->restore_cursor_state($cursor);
            $reader->reload_current_record(['id' => '1.2345678901234567']);
            $this->assertSame('1.2345678901234567', (string) $reader->get_current_record()['id']);
            $this->assertTrue($reader->next_record());
            $this->assertSame('1.2345678901234569', (string) $reader->get_current_record()['id']);
            $this->assertFalse($reader->next_record());
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serialize_precision);
        }
    }

    public function testPushReadsSqliteNumbersWithoutRoundingOrCastingSetLabels(): void
    {
        require_once __DIR__ . '/../../packages/reprint-client/src/lib/database-push/class-database-push-source.php';
        $database = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wordpress');
        $database->exec("CREATE TABLE wp_numbers (id DOUBLE PRIMARY KEY, flags SET('', 'a'), bits BIT(16))");
        $database->exec("INSERT INTO wp_numbers VALUES (1.2345678901234567, 'a', 257), (1.2345678901234569, '', NULL)");
        $precision = ini_set('precision', '3');
        $locale = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE.utf8');
        $session = str_repeat('a', 32);
        $reader = new DatabasePushSource($database, 'wp_', [], $session);
        $rows = [];
        try {
            for ($step = 0; $step < 30 && $reader->next_step(); ++$step) {
                $record = $reader->get_record();
                if ($record !== null) {
                    if (isset($record['values'])) {
                        $rows[] = $record['values'];
                    }
                    $reader->close();
                    $reader = new DatabasePushSource($database, 'wp_', [], $session, $record['cursor'], ['wp_numbers']);
                }
            }
            $this->assertSame([
                ['id' => base64_encode('1.2345678901234567'), 'flags' => base64_encode('a'), 'bits' => ['unsigned' => '257']],
                ['id' => base64_encode('1.2345678901234569'), 'flags' => '', 'bits' => null],
            ], $rows);
        } finally {
            $reader->close();
            ini_set('precision', $precision);
            setlocale(LC_NUMERIC, $locale);
        }
    }

    public function testReadsThroughTheSqliteIntegrationPublicQueryApi(): void
    {
        $database = $this->open_database();
        $reader = new DatabaseRowsReader($database, ['batch_size' => 1]);

        $reader->initialize_tables_to_process();

        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());
        $this->assertSame('wp_posts', $reader->get_current_table());
        $this->assertSame(['ID'], $reader->get_current_primary_key_columns());
        $this->assertSame(
            ['ID' => 1, 'post_content' => 'https://old.example/one'],
            $reader->get_current_record()
        );
    }

    public function testCursorCountsRowsAndTablesAcrossResume(): void
    {
        $database = $this->open_database();
        $reader = new DatabaseRowsReader($database, ['batch_size' => 1]);
        $reader->initialize_tables_to_process();
        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());

        $cursor = $reader->get_cursor_state();
        $this->assertSame(1, $cursor['tables_total']);
        $this->assertSame(1, $cursor['current_table_number']);
        $this->assertSame(1, $cursor['current_table_rows_processed']);

        $resumed = new DatabaseRowsReader($database, ['batch_size' => 1]);
        $this->assertTrue($resumed->restore_cursor_state($cursor));
        $this->assertTrue($resumed->next_record());
        $this->assertSame(
            2,
            $resumed->get_cursor_state()['current_table_rows_processed']
        );
    }

    public function testProducerCursorReportsCurrentAndCompletedTableProgress(): void
    {
        $producer = new MySQLDumpProducer($this->open_database(), ['batch_size' => 1]);
        $active_table_progress = null;
        while ($producer->next_sql_fragment()) {
            $cursor = json_decode($producer->get_reentrancy_cursor(), true);
            if ($cursor['progress']['current_table'] !== null) {
                $active_table_progress = $cursor['progress'];
            }
        }

        $this->assertNotNull($active_table_progress);
        $this->assertSame(
            ['done' => 0, 'total' => 1],
            $active_table_progress['tables']
        );
        $this->assertSame('wp_posts', $active_table_progress['current_table']['name']);
        $this->assertSame(0, $active_table_progress['current_table']['rows_total']);
        $this->assertGreaterThanOrEqual(
            1,
            $active_table_progress['current_table']['rows_done']
        );

        $complete_cursor = json_decode($producer->get_reentrancy_cursor(), true);
        $this->assertSame(
            ['done' => 1, 'total' => 1],
            $complete_cursor['progress']['tables']
        );
        $this->assertNull($complete_cursor['progress']['current_table']);
    }

    public function testReleasesTheReadResultAtTheBatchBoundary(): void
    {
        $database = $this->open_database();
        $reader = new DatabaseRowsReader($database, ['batch_size' => 1]);
        $reader->initialize_tables_to_process();
        $this->assertTrue($reader->move_to_next_table());
        $this->assertTrue($reader->next_record());

        $other_connection = new PDO('sqlite:' . $this->database_path);
        $other_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other_connection->setAttribute(PDO::ATTR_TIMEOUT, 1);
        $this->assertSame(
            1,
            $other_connection->exec("UPDATE wp_posts SET post_content = 'changed' WHERE ID = 2")
        );
    }

    private function open_database(): WP_PDO_MySQL_On_SQLite
    {
        $database = new WP_PDO_MySQL_On_SQLite(
            "mysql-on-sqlite:path={$this->database_path};dbname=wp_test",
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $database->get_connection()->get_pdo()->sqliteCreateFunction(
            'FROM_BASE64',
            static function ($data) {
                return $data === null ? null : base64_decode($data);
            }
        );
        return $database;
    }
}
