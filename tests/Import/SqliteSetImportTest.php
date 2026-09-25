<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\PdoDatabaseConnection;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class SqliteSetImportTest extends TestCase {
    private string $root;
    private $database;
    private $connection;
    private $client;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/reprint-set-import-' . bin2hex(random_bytes(5));
        mkdir($this->root);
        $this->database = new \WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=wordpress');
        $this->connection = new PdoDatabaseConnection($this->database, $this->database->get_connection()->get_pdo());
        $this->client = new \ImportClient('https://source.example/', $this->root, $this->root . '/files');
        (new \ReflectionMethod(\ImportClient::class, 'create_database_import_position_table'))->invoke($this->client, $this->connection);
    }

    protected function tearDown(): void {
        $this->connection->close();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testMasksBecomeLabelsWithoutChangingOrdinaryNumbers(): void {
        $members = "'', 'a'";
        for ($index = 2; $index < 64; ++$index) {
            $members .= ", 'member" . $index . "'";
        }
        $this->import("CREATE TABLE `wp_sets` (`id` INT PRIMARY KEY, `flags` SET($members), `amount` INT);"
            . "INSERT INTO `wp_sets` (`id`,`flags`,`amount`) VALUES (1,0,3),(2,1,3),(3,2,3),(4,3,3),(5,9223372036854775808,3),(6,18446744073709551615,3),(7,NULL,3);");
        $this->assertSame(['', '', 'a', 'a', 'member63', 'a,' . implode(',', array_map(static fn($index) => 'member' . $index, range(2, 63))), null], $this->database->query('SELECT flags FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([3,3,3,3,3,3,3], $this->database->query('SELECT amount FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testChunkUpdatesFindTheSetPrimaryKeyAfterGroupResume(): void {
        $this->import("CREATE TABLE `wp_sets` (`flags` SET('alpha','beta') PRIMARY KEY, `payload` LONGTEXT); INSERT INTO `wp_sets` (`flags`,`payload`) VALUES (3,'');");
        $this->import("UPDATE `wp_sets` SET `payload` = CONCAT(`payload`,FROM_BASE64('Ynl0ZXM=')) WHERE CAST(`wp_sets`.`flags` AS UNSIGNED) = 3;");
        $this->assertSame([['flags' => 'alpha,beta', 'payload' => 'bytes']], $this->database->query('SELECT * FROM wp_sets')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testLexerHandlesQuotedMembersAndDoesNotRewriteSqlInsideText(): void {
        $this->import("CREATE TABLE `wp_sets` (`id` INT PRIMARY KEY, `flags` SET('O''Reilly','雪','2'), `payload` LONGTEXT);"
            . "INSERT INTO `wp_sets` (`id`,`flags`,`payload`) VALUES (1,3,CONCAT('CAST(`wp_sets`.`flags` AS UNSIGNED) = 3',';')), (2,4,'');");
        $this->assertSame([['id' => 1, 'flags' => "O'Reilly,雪", 'payload' => 'CAST(`wp_sets`.`flags` AS UNSIGNED) = 3;'], ['id' => 2, 'flags' => '2', 'payload' => '']], $this->database->query('SELECT * FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBackslashesInMembersSurviveInsertAndResumedChunkUpdate(): void {
        $this->import(<<<'SQL'
CREATE TABLE `wp_sets` (`flags` SET('back\\slash','literal\\n','O''Reilly') PRIMARY KEY, `payload` LONGTEXT);
INSERT INTO `wp_sets` (`flags`,`payload`) VALUES (7,'');
SQL
        );
        $this->assertSame("back\\slash,literal\\n,O'Reilly", $this->database->query('SELECT flags FROM wp_sets')->fetchColumn());
        $this->import("UPDATE `wp_sets` SET `payload` = CONCAT(`payload`,FROM_BASE64('Ynl0ZXM=')) WHERE CAST(`wp_sets`.`flags` AS UNSIGNED) = 7;");
        $this->assertSame('bytes', $this->database->query('SELECT payload FROM wp_sets')->fetchColumn());
    }

    public function testTrailingBackslashDoesNotHideTheMemberClosingQuote(): void {
        $this->import(<<<'SQL'
CREATE TABLE `wp_sets` (`id` INT PRIMARY KEY, `flags` SET('trailing\\'));
INSERT INTO `wp_sets` (`id`,`flags`) VALUES (1,1);
SQL
        );
        $this->assertSame('trailing\\', $this->database->query('SELECT flags FROM wp_sets')->fetchColumn());
    }

    public function testExpandedSetBatchFitsIn128MiB(): void {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/fixtures/sqlite-set-import-worker.php', $this->root],
            [['pipe', 'r'], ['file', $this->root . '/worker.log', 'w'], ['file', $this->root . '/worker.log', 'a']],
            $pipes
        );
        fclose($pipes[0]);
        $exit_code = proc_close($process);
        $output = file_get_contents($this->root . '/worker.log');
        $this->assertSame(0, $exit_code, $output);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(250, $result['rows']);
        $this->assertLessThan(128 * 1024 * 1024, $result['peak_bytes']);
    }

    public function testLaterSqliteRowErrorRollsBackTheGroupAndCursorBeforeRetry(): void {
        $this->import("CREATE TABLE `wp_sets` (`id` INT PRIMARY KEY, `flags` SET('alpha','beta')); INSERT INTO `wp_sets` (`id`,`flags`) VALUES (0,1);", 'before');
        $native = $this->database->get_connection()->get_pdo();
        $attempted_rows = [];
        $native->sqliteCreateFunction('observe_set_insert', static function ($id) use (&$attempted_rows) {
            $attempted_rows[] = $id;
            return 1;
        });
        // Inject a real SQLite statement failure after earlier rows executed.
        // This tests rollback, not support for transferring source triggers.
        $native->exec("CREATE TRIGGER fail_set_insert BEFORE INSERT ON wp_sets BEGIN SELECT observe_set_insert(NEW.id); SELECT CASE WHEN NEW.id = 100 THEN RAISE(FAIL, 'injected SQLite insert failure') END; END");
        $rows = [];
        for ($id = 1; $id <= 250; ++$id) {
            $rows[] = '(' . $id . ',3)';
        }
        $sql = 'INSERT INTO `wp_sets` (`id`,`flags`) VALUES ' . implode(',', $rows) . ' ON DUPLICATE KEY UPDATE `id` = `id`;';
        try {
            $this->import($sql, 'after');
            $this->fail('The injected SQLite insert failure must end the group.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('injected SQLite insert failure', $error->getMessage());
        }
        $this->assertSame(range(1, 100), $attempted_rows);
        $this->assertSame([['id' => 0, 'flags' => 'alpha']], $this->database->query('SELECT * FROM wp_sets')->fetchAll(PDO::FETCH_ASSOC));
        $position = new \ReflectionMethod($this->client, 'read_database_import_position');
        $this->assertSame('before', $position->invoke($this->client, $this->connection, hash('sha256', 'sets'), 'db-apply')['source_cursor']);
        $native->exec('DROP TRIGGER fail_set_insert');
        $this->import($sql, 'after');
        $this->assertSame(250, (int) $this->database->query("SELECT COUNT(*) FROM wp_sets WHERE flags='alpha,beta'")->fetchColumn());
        $this->assertSame('after', $position->invoke($this->client, $this->connection, hash('sha256', 'sets'), 'db-apply')['source_cursor']);
    }

    public function testSetRowsKeepUrlRewritingNullsNumbersAndDuplicateNoOp(): void {
        $rewriter = new \SqlStatementRewriter(new \StructuredDataUrlRewriter(['https://source.example' => 'https://target.example']), 'wp_');
        $sql = "CREATE TABLE `wp_sets` (`id` INT PRIMARY KEY, `flags` SET('https://source.example','plain','3'), `amount` DOUBLE, `payload` LONGTEXT);"
            . "INSERT INTO `wp_sets` (`id`,`flags`,`amount`,`payload`) VALUES (1,1,1.2345678901234567,FROM_BASE64('aHR0cHM6Ly9zb3VyY2UuZXhhbXBsZS9h')), (2,NULL,NULL,''), (3,FROM_BASE64('cGxhaW4='),3e0,''), (1,2,99,''), (4,FROM_BASE64('Mw=='),4,'') ON DUPLICATE KEY UPDATE `id` = `id`;"
            . "INSERT INTO `wp_sets` (`id`,`flags`,`amount`,`payload`) VALUES (1,2,99,'') ON DUPLICATE KEY UPDATE `id` = `id`;";
        (new \ReflectionMethod($this->client, 'execute_database_import_group'))->invoke(
            $this->client, $this->connection, $sql, hash('sha256', 'sets'), 'after', null, 'sqlite', $rewriter
        );
        $this->assertSame([
            ['id' => 1, 'flags' => 'https://target.example', 'amount' => 1.2345678901234567, 'payload' => 'https://target.example/a'],
            ['id' => 2, 'flags' => null, 'amount' => null, 'payload' => ''],
            ['id' => 3, 'flags' => 'plain', 'amount' => 3.0, 'payload' => ''],
            ['id' => 4, 'flags' => '3', 'amount' => 4.0, 'payload' => ''],
        ], $this->database->query('SELECT * FROM wp_sets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    private function import(string $sql, string $cursor = 'next-cursor'): void {
        (new \ReflectionMethod(\ImportClient::class, 'execute_database_import_group'))->invoke(
            $this->client, $this->connection, $sql, hash('sha256', 'sets'), $cursor, null, 'sqlite'
        );
    }
}
