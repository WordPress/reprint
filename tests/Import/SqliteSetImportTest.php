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

    private function import(string $sql): void {
        (new \ReflectionMethod(\ImportClient::class, 'execute_database_import_group'))->invoke(
            $this->client, $this->connection, $sql, hash('sha256', 'sets'), 'next-cursor', null, 'sqlite'
        );
    }
}
