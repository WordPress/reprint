<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\DatabaseConnection;
use Reprint\Importer\Database\MysqliDatabaseConnection;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class FulltextParserCompatibilityTest extends TestCase {

    private string $temp_dir;
    private \ImportClient $client;
    /** @var resource */
    private $progress;
    private ?\mysqli $mysql = null;
    private ?string $database_name = null;

    protected function setUp(): void
    {
        $this->temp_dir = sys_get_temp_dir() . '/reprint-fulltext-' . uniqid();
        $this->client = new \ImportClient(
            'https://source.example/?reprint-api',
            $this->temp_dir,
            $this->temp_dir . '/files'
        );
        $this->progress = fopen('php://temp', 'w+');
        $property = new \ReflectionProperty(\ImportClient::class, 'progress_fd');
        $property->setValue($this->client, $this->progress);
    }

    protected function tearDown(): void
    {
        if ($this->mysql !== null) {
            $this->mysql->query('DROP DATABASE `' . $this->database_name . '`');
            $this->mysql->close();
        }
        fclose($this->progress);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temp_dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temp_dir);
    }

    public function testCliDbApplyKeepsIndexesAndRowsAndEmitsStructuredWarnings(): void
    {
        $database = $this->open_mysql();
        $schema = "CREATE TABLE `articles` (id INT PRIMARY KEY, title TEXT, body TEXT,\n"
            . "FULLTEXT KEY `title_search` (title) /*!50100 WITH PARSER `reprint_missing_parser` */,\n"
            . "FULLTEXT KEY `body_search` (body) WITH PARSER `reprint_other_parser`) ENGINE=InnoDB;\n";
        $dump = $schema . $this->group_marker(['current_table' => 'articles', 'state' => 'rows']);
        $dump .= "INSERT INTO articles VALUES (1, 'Hello world', 'WITH PARSER reprint_missing_parser');\n";
        $dump .= $this->group_marker(['state' => 'done']);
        file_put_contents($this->temp_dir . '/db.sql', $dump);

        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
            'db-apply',
            'https://source.example/?reprint-api',
            '--state-dir=' . $this->temp_dir,
            '--fs-root=' . $this->temp_dir . '/files',
            '--target-host=' . ( getenv('DB_HOST') ?: '127.0.0.1' ),
            '--target-port=' . ( getenv('DB_PORT') ?: '3306' ),
            '--target-user=' . ( getenv('DB_USER') ?: 'root' ),
            '--target-pass=' . ( getenv('DB_PASS') ?: '' ),
            '--target-db=' . $this->database_name,
            '--progress=jsonl',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors . $output);
        fwrite($this->progress, $output);

        $this->assertSame(
            'WITH PARSER reprint_missing_parser',
            $database->query('SELECT body FROM articles WHERE id = 1')->fetchColumn()
        );
        $indexes = $database->query("SHOW INDEX FROM articles WHERE Index_type = 'FULLTEXT'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['title_search', 'body_search'], array_column($indexes, 'Key_name'));
        $this->assertSame($dump, file_get_contents($this->temp_dir . '/db.sql'));
        $state = json_decode(file_get_contents($this->client->pull_state_directory . '/state.json'), true);
        $this->assertSame('complete', $state['active_resumable_command']['completion_state']);

        $warnings = $this->read_warnings();
        $this->assertCount(2, $warnings);
        $this->assertSame('unsupported_fulltext_parser', $warnings[0]['reason']);
        $this->assertSame('sql', $warnings[0]['phase']);
        $this->assertSame('articles', $warnings[0]['table']);
        $this->assertSame('title_search', $warnings[0]['index']);
        $this->assertSame('reprint_missing_parser', $warnings[0]['parser']);
        $this->assertSame('body_search', $warnings[1]['index']);
        $this->assertSame('reprint_other_parser', $warnings[1]['parser']);
        $this->assertStringContainsString('search matches and ranking may change', $warnings[0]['message']);
        $this->assertStringContainsString(
            $warnings[0]['message'],
            file_get_contents($this->temp_dir . '/audit.log')
        );
    }

    public function testOmitsNgramOnlyWhenTheTargetDoesNotProvideIt(): void
    {
        $database = $this->open_mysql();
        $parser = $database->query(
            "SELECT PLUGIN_NAME FROM INFORMATION_SCHEMA.PLUGINS "
            . "WHERE PLUGIN_NAME = 'ngram' AND PLUGIN_TYPE = 'FTPARSER' AND PLUGIN_STATUS = 'ACTIVE'"
        )->fetchColumn();
        $sql = 'CREATE TABLE articles (body TEXT, FULLTEXT KEY body_search (body) WITH PARSER `ngram`);';

        [$schema] = $this->import_sql($sql, $database, 'articles');

        if ($parser === false) {
            $this->assertStringNotContainsString('WITH PARSER', $schema);
            $this->assertSame('ngram', $this->read_warnings()[0]['parser']);
        } else {
            $this->assertStringContainsString('WITH PARSER `ngram`', $schema);
            $this->assertSame([], $this->read_warnings());
        }
    }

    public function testKeepsAvailableParsersBesideAnUnavailableParser(): void
    {
        $database = $this->open_mysql();
        $parser = $database->query(
            "SELECT PLUGIN_NAME FROM INFORMATION_SCHEMA.PLUGINS "
            . "WHERE PLUGIN_TYPE = 'FTPARSER' AND PLUGIN_STATUS = 'ACTIVE' LIMIT 1"
        )->fetchColumn();
        if ($parser === false) {
            $this->markTestSkipped('The target has no active full-text parser plugin.');
        }
        $quoted_parser = '`' . str_replace('`', '``', $parser) . '`';
        $sql = 'CREATE TABLE `article``notes` (title TEXT, body TEXT, '
            . 'FULLTEXT KEY `title_search` (title) WITH PARSER ' . $quoted_parser . ', '
            . 'FULLTEXT KEY `body``search` (body) with /* parser clause */ parser reprint_missing_parser);';

        [$schema] = $this->import_sql($sql, $database, 'article`notes');

        $this->assertStringContainsString('WITH PARSER ' . $quoted_parser, $schema);
        $this->assertStringNotContainsString('reprint_missing_parser', $schema);
        $warnings = $this->read_warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('article`notes', $warnings[0]['table']);
        $this->assertSame('body`search', $warnings[0]['index']);
    }

    public function testAnActiveStorageEngineIsNotAFulltextParser(): void
    {
        $database = $this->open_mysql();
        [$schema] = $this->import_sql(
            'CREATE TABLE articles (body TEXT, FULLTEXT KEY body_search (body) WITH PARSER InnoDB);',
            $database,
            'articles'
        );

        $this->assertStringNotContainsString('WITH PARSER', $schema);
        $this->assertSame('InnoDB', $this->read_warnings()[0]['parser']);
    }

    public function testLeavesParserTextInValuesIdentifiersAndCommentsAlone(): void
    {
        $database = $this->open_mysql();
        $sql = "CREATE TABLE `WITH PARSER missing` (body TEXT COMMENT 'WITH PARSER missing', "
            . "FULLTEXT KEY `WITH PARSER missing` (body)) /* WITH PARSER missing */;\n"
            . "INSERT INTO `WITH PARSER missing` VALUES ('WITH PARSER missing');";

        [$schema, $count] = $this->import_sql($sql, $database, 'WITH PARSER missing');

        $this->assertStringContainsString("COMMENT 'WITH PARSER missing'", $schema);
        $this->assertStringContainsString('FULLTEXT KEY `WITH PARSER missing`', $schema);
        $this->assertSame(
            'WITH PARSER missing',
            $database->query('SELECT body FROM `WITH PARSER missing`')->fetchColumn()
        );
        $this->assertSame(2, $count);
        $this->assertSame([], $this->read_warnings());
    }

    public function testShowsTheWarningInTerminalMode(): void
    {
        $database = $this->open_mysql();
        $property = new \ReflectionProperty(\ImportClient::class, 'progress_output_mode');
        $property->setValue($this->client, 'tty');
        $property = new \ReflectionProperty(\ImportClient::class, 'progress');
        $property->setValue($this->client, new \TerminalProgress(true, $this->progress));

        $this->import_sql(
            'CREATE TABLE articles (body TEXT, FULLTEXT KEY body_search (body) WITH PARSER reprint_missing_parser);',
            $database,
            'articles'
        );

        rewind($this->progress);
        $output = stream_get_contents($this->progress);
        $this->assertStringContainsString("Omitting it from index 'body_search' on table 'articles'", $output);
        $this->assertStringContainsString('search matches and ranking may change', $output);
        $this->assertSame([], $this->read_warnings());
    }

    private function open_mysql(): DatabaseConnection
    {
        try {
            $mysql = new \mysqli(
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_USER') ?: 'root',
                getenv('DB_PASS') ?: '',
                '',
                (int) ( getenv('DB_PORT') ?: 3306 )
            );
        } catch (\mysqli_sql_exception $error) {
            $this->markTestSkipped('MySQL not reachable: ' . $error->getMessage());
        }
        $this->database_name = 'test_fulltext_' . bin2hex(random_bytes(4));
        $mysql->query('CREATE DATABASE `' . $this->database_name . '` CHARACTER SET utf8mb4');
        $mysql->select_db($this->database_name);
        $mysql->set_charset('utf8mb4');
        $this->mysql = $mysql;
        return new MysqliDatabaseConnection($mysql);
    }

    /**
     * @return array {
     *     Imported schema and statement count.
     *
     *     @type string $0 Table definition stored by the target.
     *     @type int    $1 Number of statements in the group.
     * }
     */
    private function import_sql(string $sql, DatabaseConnection $database, string $table_name): array
    {
        ( new \ReflectionMethod(\ImportClient::class, 'create_database_import_position_table') )
            ->invoke($this->client, $database);
        $method = new \ReflectionMethod(\ImportClient::class, 'execute_database_import_group');
        $count = $method->invoke(
            $this->client,
            $database,
            $sql,
            hash('sha256', 'fulltext-parser-test'),
            base64_encode(json_encode(['state' => 'done'])),
            null,
            'mysql'
        );
        $quoted_table = '`' . str_replace('`', '``', $table_name) . '`';
        $schema = $database->query('SHOW CREATE TABLE ' . $quoted_table)->fetch(PDO::FETCH_NUM)[1];
        return [$schema, $count];
    }

    /**
     * @return array<int, array<string, mixed>> Warning records emitted during the import.
     */
    private function read_warnings(): array
    {
        rewind($this->progress);
        $warnings = [];
        // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Read one JSONL record at a time.
        while (( $line = fgets($this->progress) ) !== false) {
            $record = json_decode($line, true);
            if (( $record['type'] ?? null ) === 'warning') {
                $warnings[] = $record;
            }
        }
        return $warnings;
    }

    /**
     * @param array $cursor {
     *     Exporter position after this SQL group.
     *
     *     @type string $state         Next exporter phase.
     *     @type string $current_table Table being imported, when a table remains.
     * }
     */
    private function group_marker(array $cursor): string
    {
        return '-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 '
            . base64_encode(json_encode($cursor)) . "\n";
    }
}
