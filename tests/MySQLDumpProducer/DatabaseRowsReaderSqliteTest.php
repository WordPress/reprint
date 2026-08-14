<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DatabaseRowsReader;

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
