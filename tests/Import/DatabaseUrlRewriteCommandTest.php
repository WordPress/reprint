<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\State\DatabaseUrlRewriteCommandState;
use function Reprint\Importer\resolve_sqlite_integration_path;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/InterruptingDatabaseUrlRewriteClient.php';

class DatabaseUrlRewriteCommandTest extends TestCase {

    private string $temp_dir;
    private string $database_path;
    private string $remote_reprint_api_url = 'https://old.example/?reprint-api';

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->temp_dir = sys_get_temp_dir() . '/reprint-live-url-rewrite-' . uniqid('', true);
        $this->database_path = $this->temp_dir . '/database/wordpress.sqlite';
        mkdir($this->temp_dir . '/fs-root', 0755, true);
        mkdir(dirname($this->database_path), 0755, true);
        $this->create_database();

        write_current_pull_state(
            new \ImportClient(
                $this->remote_reprint_api_url,
                $this->temp_dir,
                $this->temp_dir . '/fs-root'
            ),
            [
                'preflight' => [
                    'data' => [
                        'database' => ['wp' => ['table_prefix' => 'wp_']],
                    ],
                ],
                'apply' => [
                    'target_engine' => 'sqlite',
                    'target_db' => 'wp_test',
                    'target_sqlite_path' => $this->database_path,
                ],
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->remove_directory($this->temp_dir);
        parent::tearDown();
    }

    public function testRewritesLiveRowsAndPersistsCompletionProgress(): void
    {
        $client = $this->new_client();
        $client->run($this->command_options());

        $database = new \PDO('sqlite:' . $this->database_path);
        $rows = $database->query('SELECT ID, post_content, post_title FROM wp_posts ORDER BY ID')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame('https://new.example/one', $rows[0]['post_content']);
        $this->assertSame(
            'a:1:{s:3:"url";s:31:"https://new.example/serialized";}',
            $rows[1]['post_content']
        );
        $this->assertSame('No URL here', $rows[2]['post_content']);
        $this->assertSame('Unchanged title', $rows[0]['post_title']);

        $state = $this->read_state($client);
        $this->assertSame('db-rewrite-urls', $state['active_resumable_command']['command_name']);
        $this->assertSame('complete', $state['active_resumable_command']['completion_state']);
        $this->assertSame(6, $state['database_url_rewrite']['records_processed']);
        $this->assertSame(2, $state['database_url_rewrite']['records_changed']);
        $this->assertNotEmpty($state['database_url_rewrite']['cursor']);
    }

    public function testInterruptedCommandResumesWithoutUpdatingARecordTwice(): void
    {
        $client = new InterruptingDatabaseUrlRewriteClient(
            $this->remote_reprint_api_url,
            $this->temp_dir,
            $this->temp_dir . '/fs-root',
            2
        );
        $client->run($this->command_options());

        $partial_state = $this->read_state($client);
        $this->assertSame('partial', $partial_state['active_resumable_command']['completion_state']);
        $this->assertSame(2, $partial_state['database_url_rewrite']['records_processed']);

        $resuming_client = $this->new_client();
        $resume_options = $this->command_options();
        unset(
            $resume_options['rewrite_url'],
            $resume_options['target_engine'],
            $resume_options['target_sqlite_path'],
            $resume_options['target_db']
        );
        $resuming_client->run($resume_options);

        $database = new \PDO('sqlite:' . $this->database_path);
        $update_counts = $database->query(
            'SELECT record_id, updates FROM z_rewrite_counts ORDER BY record_id'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->assertSame([1 => 1, 2 => 1, 3 => 0], $update_counts);

        $complete_state = $this->read_state($resuming_client);
        $this->assertSame('complete', $complete_state['active_resumable_command']['completion_state']);
        $this->assertSame(6, $complete_state['database_url_rewrite']['records_processed']);
        $this->assertSame(2, $complete_state['database_url_rewrite']['records_changed']);
    }

    public function testUsesTheDatabaseTargetRecordedByDbApply(): void
    {
        $options = $this->command_options();
        unset(
            $options['target_engine'],
            $options['target_sqlite_path'],
            $options['target_db']
        );

        $this->new_client()->run($options);

        $database = new \PDO('sqlite:' . $this->database_path);
        $this->assertSame(
            'https://new.example/one',
            $database->query('SELECT post_content FROM wp_posts WHERE ID = 1')->fetchColumn()
        );
    }

    public function testExplicitTargetOptionsOverrideTheDatabaseRecordedByDbApply(): void
    {
        $other_database_path = $this->temp_dir . '/database/other.sqlite';
        copy($this->database_path, $other_database_path);
        $options = $this->command_options();
        $options['target_sqlite_path'] = $other_database_path;

        $this->new_client()->run($options);

        $recorded_database = new \PDO('sqlite:' . $this->database_path);
        $other_database = new \PDO('sqlite:' . $other_database_path);
        $this->assertSame(
            'https://old.example/one',
            $recorded_database->query(
                'SELECT post_content FROM wp_posts WHERE ID = 1'
            )->fetchColumn()
        );
        $this->assertSame(
            'https://new.example/one',
            $other_database->query(
                'SELECT post_content FROM wp_posts WHERE ID = 1'
            )->fetchColumn()
        );
    }

    public function testCliUsesTheOnlySavedRemoteWithoutAUrlOrFilesystemRoot(): void
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entry),
            'db-rewrite-urls',
            '--state-dir=' . escapeshellarg($this->temp_dir),
            '--progress=jsonl',
            '--rewrite-url',
            escapeshellarg('https://old.example'),
            escapeshellarg('https://new.example'),
            '2>&1',
        ]);
        $output = [];
        $exit_code = null;
        exec($command, $output, $exit_code);

        $this->assertSame(0, $exit_code, implode("\n", $output));
        $database = new \PDO('sqlite:' . $this->database_path);
        $this->assertSame(
            'https://new.example/one',
            $database->query('SELECT post_content FROM wp_posts WHERE ID = 1')->fetchColumn()
        );
    }

    public function testNewSiteUrlWithoutARemoteUrlRequiresAnExplicitRewriteMapping(): void
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entry),
            'db-rewrite-urls',
            '--state-dir=' . escapeshellarg($this->temp_dir),
            '--progress=jsonl',
            '--new-site-url=' . escapeshellarg('https://new.example'),
            '2>&1',
        ]);
        $output = [];
        $exit_code = null;
        exec($command, $output, $exit_code);

        $this->assertSame(1, $exit_code);
        $this->assertStringContainsString(
            'Use --rewrite-url FROM TO when no remote URL is available.',
            implode("\n", $output)
        );
    }

    public function testCliCreatesCommandLocalStateWhenNoRemoteWasSaved(): void
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $state_directory = $this->temp_dir . '/standalone-state';
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entry),
            'db-rewrite-urls',
            '--state-dir=' . escapeshellarg($state_directory),
            '--progress=jsonl',
            '--target-engine=sqlite',
            '--target-sqlite-path=' . escapeshellarg($this->database_path),
            '--target-db=wp_test',
            '--rewrite-url',
            escapeshellarg('https://old.example'),
            escapeshellarg('https://new.example'),
            '2>&1',
        ]);
        $output = [];
        $exit_code = null;
        exec($command, $output, $exit_code);

        $this->assertSame(0, $exit_code, implode("\n", $output));
        $this->assertFileExists(
            $state_directory . '/db-rewrite-urls/pull/state.json'
        );
    }

    public function testIncompleteCommandRejectsAChangedUrlMapping(): void
    {
        $client = new InterruptingDatabaseUrlRewriteClient(
            $this->remote_reprint_api_url,
            $this->temp_dir,
            $this->temp_dir . '/fs-root',
            1
        );
        $client->run($this->command_options());

        $options = $this->command_options();
        $options['rewrite_url'] = [
            ['https://old.example', 'https://different.example'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot change --rewrite-url while db-rewrite-urls is incomplete.'
        );
        $this->new_client()->run($options);
    }

    public function testCommandResumesFromTheInitialSavedBoundary(): void
    {
        $client = new InterruptingDatabaseUrlRewriteClient(
            $this->remote_reprint_api_url,
            $this->temp_dir,
            $this->temp_dir . '/fs-root',
            0
        );
        $client->run($this->command_options());

        $partial_state = $this->read_state($client);
        $this->assertSame('partial', $partial_state['active_resumable_command']['completion_state']);
        $this->assertNull($partial_state['database_url_rewrite']['cursor']);

        $options = $this->command_options();
        unset($options['rewrite_url']);
        $resuming_client = $this->new_client();
        $resuming_client->run($options);

        $complete_state = $this->read_state($resuming_client);
        $this->assertSame('complete', $complete_state['active_resumable_command']['completion_state']);
        $this->assertSame(6, $complete_state['database_url_rewrite']['records_processed']);
    }

    public function testSkipsTablesWithoutAPrimaryKeyAndContinues(): void
    {
        $database = new \PDO('sqlite:' . $this->database_path);
        $database->exec('CREATE TABLE wp_unkeyed (content TEXT NOT NULL)');
        $database->exec("INSERT INTO wp_unkeyed VALUES ('https://old.example/unkeyed')");
        $database->exec(
            'CREATE TABLE zz_after_unkeyed (id INTEGER PRIMARY KEY, content TEXT NOT NULL)'
        );
        $database->exec(
            "INSERT INTO zz_after_unkeyed VALUES (1, 'https://old.example/after-unkeyed')"
        );

        $client = $this->new_client();
        $client->run($this->command_options());

        $this->assertSame(
            'https://old.example/unkeyed',
            $database->query('SELECT content FROM wp_unkeyed')->fetchColumn()
        );
        $this->assertSame(
            'https://new.example/after-unkeyed',
            $database->query('SELECT content FROM zz_after_unkeyed')->fetchColumn()
        );
        $this->assertSame(
            'complete',
            $this->read_state($client)['active_resumable_command']['completion_state']
        );
        $this->assertStringContainsString(
            'Skipping wp_unkeyed because it has no primary key.',
            (string) file_get_contents($this->temp_dir . '/audit.log')
        );
    }

    public function testResumesPastAPendingRecordFromAnUnkeyedTable(): void
    {
        $sqlite = new \PDO('sqlite:' . $this->database_path);
        $sqlite->exec('CREATE TABLE wp_unkeyed (content TEXT NOT NULL)');
        $sqlite->exec("INSERT INTO wp_unkeyed VALUES ('https://old.example/unkeyed')");
        $sqlite->exec(
            'CREATE TABLE zz_after_unkeyed (id INTEGER PRIMARY KEY, content TEXT NOT NULL)'
        );
        $sqlite->exec(
            "INSERT INTO zz_after_unkeyed VALUES (1, 'https://old.example/after-unkeyed')"
        );

        $database = $this->open_mysql_on_sqlite_database();
        $reader = new \WordPress\DataLiberation\DatabaseRowsReader(
            $database,
            ['batch_size' => 1]
        );
        $reader->initialize_tables_to_process();
        do {
            $this->assertTrue($reader->move_to_next_table());
        } while ($reader->get_current_table() !== 'wp_unkeyed');
        $this->assertTrue($reader->next_record());

        $processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $database,
            new \SqlStatementRewriter(
                new \StructuredDataUrlRewriter([
                    'https://old.example' => 'https://new.example',
                ]),
                'wp_'
            ),
            [
                'reader_cursor' => $reader->get_cursor_state(),
                'reader_phase' => 'process_record',
                'records_processed' => 3,
                'records_changed' => 2,
                'tables_started' => 1,
                'current_table' => 'wp_posts',
                'complete' => false,
            ]
        );

        $this->assertTrue($processor->next_step());
        $this->assertSame('wp_unkeyed', $processor->get_progress()['skipped_table']);
        do {
            $has_more_steps = $processor->next_step();
        } while ($has_more_steps);

        $this->assertSame(
            'https://old.example/unkeyed',
            $sqlite->query('SELECT content FROM wp_unkeyed')->fetchColumn()
        );
        $this->assertSame(
            'https://new.example/after-unkeyed',
            $sqlite->query('SELECT content FROM zz_after_unkeyed')->fetchColumn()
        );
    }

    public function testSqliteMetadataQuotesATableNameContainingSqlPunctuation(): void
    {
        $database = new \PDO('sqlite:' . $this->database_path);
        $database->exec(
            "CREATE TABLE \"wp_odd'?\" ("
            . '"record-id?" INTEGER PRIMARY KEY, "content-url?" TEXT NOT NULL)'
        );
        $database->exec(
            "INSERT INTO \"wp_odd'?\" VALUES (1, 'https://old.example/odd')"
        );

        $this->new_client()->run($this->command_options());

        $this->assertSame(
            'https://new.example/odd',
            $database->query("SELECT \"content-url?\" FROM \"wp_odd'?\"")->fetchColumn()
        );
    }

    public function testSqliteMetadataUsesPublicMysqlQueries(): void
    {
        $dsn = "mysql-on-sqlite:path={$this->database_path};dbname=wp_test";
        $driver = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $database = new class(
            $driver,
            $driver->get_connection()->get_pdo()
        ) extends \Reprint\Importer\Database\PdoDatabaseConnection {
            public array $queries = [];

            public function query(string $sql): \Reprint\Importer\Database\DatabaseResult
            {
                $this->queries[] = $sql;
                return parent::query($sql);
            }
        };
        $processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $database,
            new \SqlStatementRewriter(
                new \StructuredDataUrlRewriter([
                    'https://old.example' => 'https://new.example',
                ]),
                'wp_'
            )
        );

        do {
            $has_more_steps = $processor->next_step();
        } while ($has_more_steps && $processor->get_progress()['records_processed'] < 1);

        $this->assertContains('SHOW FULL TABLES', $database->queries);
        $this->assertNotEmpty(array_filter($database->queries, static function ($query) {
            return strpos($query, 'SHOW INDEX FROM ') === 0;
        }));
        $this->assertNotEmpty(array_filter($database->queries, static function ($query) {
            return strpos($query, 'SHOW FULL COLUMNS FROM ') === 0;
        }));
    }

    public function testProcessorDoesNotRetainASqliteReadLockBetweenRecords(): void
    {
        $database = $this->open_mysql_on_sqlite_database();
        $processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $database,
            new \SqlStatementRewriter(
                new \StructuredDataUrlRewriter([
                    'https://old.example' => 'https://new.example',
                ]),
                'wp_'
            )
        );

        do {
            $has_more_steps = $processor->next_step();
            $progress = $processor->get_progress();
        } while ($has_more_steps && $progress['records_processed'] < 1);

        $other_connection = new \PDO('sqlite:' . $this->database_path);
        $other_connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other_connection->setAttribute(\PDO::ATTR_TIMEOUT, 1);
        $this->assertSame(
            1,
            $other_connection->exec("UPDATE wp_posts SET post_title = 'Concurrent' WHERE ID = 3")
        );
    }

    public function testProcessorReplaysAnUncheckpointedUpdateWithoutRewritingItsResult(): void
    {
        $database = $this->open_mysql_on_sqlite_database();
        $statement_rewriter = new \SqlStatementRewriter(
            new \StructuredDataUrlRewriter([
                'https://old.example' => 'https://new.example',
                'https://new.example' => 'https://processed-twice.example',
            ]),
            'wp_'
        );
        $processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $database,
            $statement_rewriter
        );

        $processor->next_step();
        $processor->next_step();
        $cursor_before_update = $processor->get_cursor();

        $this->assertSame(0, $processor->get_progress()['records_processed']);

        $processor->next_step();
        $resumed_processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $this->open_mysql_on_sqlite_database(),
            $statement_rewriter,
            $cursor_before_update
        );
        $resumed_processor->next_step();

        $sqlite = new \PDO('sqlite:' . $this->database_path);
        $this->assertSame(
            'https://new.example/one',
            $sqlite->query('SELECT post_content FROM wp_posts WHERE ID = 1')->fetchColumn()
        );
        $this->assertSame(
            1,
            $sqlite->query('SELECT updates FROM z_rewrite_counts WHERE record_id = 1')->fetchColumn()
        );
        $this->assertSame(1, $resumed_processor->get_progress()['records_processed']);
        $this->assertSame(1, $resumed_processor->get_progress()['records_changed']);
    }

    public function testProcessorAdvancesPastADeletedPendingRecord(): void
    {
        $statement_rewriter = new \SqlStatementRewriter(
            new \StructuredDataUrlRewriter([
                'https://old.example' => 'https://new.example',
            ]),
            'wp_'
        );
        $processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $this->open_mysql_on_sqlite_database(),
            $statement_rewriter
        );
        $processor->next_step();
        $processor->next_step();
        $cursor_before_update = $processor->get_cursor();

        $sqlite = new \PDO('sqlite:' . $this->database_path);
        $sqlite->exec('DELETE FROM wp_posts WHERE ID = 1');

        $resumed_processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $this->open_mysql_on_sqlite_database(),
            $statement_rewriter,
            $cursor_before_update
        );
        $resumed_processor->next_step();
        $resumed_processor->next_step();
        $resumed_processor->next_step();

        $this->assertSame(2, $resumed_processor->get_progress()['records_processed']);
        $this->assertSame(
            'a:1:{s:3:"url";s:31:"https://new.example/serialized";}',
            $sqlite->query('SELECT post_content FROM wp_posts WHERE ID = 2')->fetchColumn()
        );
    }

    public function testProcessorAdvancesPastAChangedPendingRecord(): void
    {
        $statement_rewriter = new \SqlStatementRewriter(
            new \StructuredDataUrlRewriter([
                'https://old.example' => 'https://new.example',
            ]),
            'wp_'
        );
        $processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $this->open_mysql_on_sqlite_database(),
            $statement_rewriter
        );
        $processor->next_step();
        $processor->next_step();
        $cursor_before_update = $processor->get_cursor();

        $sqlite = new \PDO('sqlite:' . $this->database_path);
        $sqlite->exec(
            "UPDATE wp_posts SET post_content = 'https://concurrent.example https://old.example' WHERE ID = 1"
        );

        $resumed_processor = new \Reprint\Importer\DatabaseUrlRewriteProcessor(
            $this->open_mysql_on_sqlite_database(),
            $statement_rewriter,
            $cursor_before_update
        );
        $resumed_processor->next_step();
        $resumed_processor->next_step();
        $resumed_processor->next_step();

        $this->assertSame(2, $resumed_processor->get_progress()['records_processed']);
        $this->assertSame(
            'https://concurrent.example https://old.example',
            $sqlite->query('SELECT post_content FROM wp_posts WHERE ID = 1')->fetchColumn()
        );
        $this->assertSame(
            'a:1:{s:3:"url";s:31:"https://new.example/serialized";}',
            $sqlite->query('SELECT post_content FROM wp_posts WHERE ID = 2')->fetchColumn()
        );
    }

    public function testStateRoundTripsTheLiveRewriteCursorAndCounters(): void
    {
        $state = new DatabaseUrlRewriteCommandState();
        $state->cursor = '{"state":"emit_row"}';
        $state->records_processed = 12;
        $state->records_changed = 4;
        $state->tables_started = 3;
        $state->current_table = 'wp_posts';
        $state->rewrite_url = ['https://old.example' => 'https://new.example'];
        $state->target = [
            'engine' => 'sqlite',
            'db' => 'wp_test',
            'sqlite_path' => '/tmp/wordpress.sqlite',
        ];

        $this->assertSame(
            $state->to_array(),
            DatabaseUrlRewriteCommandState::from_array($state->to_array())->to_array()
        );
    }

    private function create_database(): void
    {
        $polyfills = resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/php-polyfills.php');
        $driver = resolve_sqlite_integration_path('/packages/mysql-on-sqlite/src/load.php');
        require_once $polyfills;
        require_once $driver;

        $database = new \PDO('sqlite:' . $this->database_path);
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $database->exec(
            'CREATE TABLE wp_posts ('
            . 'ID INTEGER PRIMARY KEY, '
            . 'post_content TEXT NOT NULL, '
            . 'post_title TEXT NOT NULL'
            . ')'
        );

        $serialized = 'a:1:{s:3:"url";s:31:"https://old.example/serialized";}';
        $insert = $database->prepare(
            'INSERT INTO wp_posts (ID, post_content, post_title) VALUES (?, ?, ?)'
        );
        $insert->execute([1, 'https://old.example/one', 'Unchanged title']);
        $insert->execute([2, $serialized, 'Serialized']);
        $insert->execute([3, 'No URL here', 'Plain']);

        $database->exec(
            'CREATE TABLE z_rewrite_counts ('
            . 'record_id INTEGER PRIMARY KEY, '
            . 'updates INTEGER NOT NULL DEFAULT 0'
            . ')'
        );
        $database->exec('INSERT INTO z_rewrite_counts (record_id) VALUES (1), (2), (3)');
        $database->exec(
            'CREATE TRIGGER count_post_content_rewrites '
            . 'AFTER UPDATE OF post_content ON wp_posts '
            . 'BEGIN '
            . 'UPDATE z_rewrite_counts SET updates = updates + 1 WHERE record_id = NEW.ID; '
            . 'END'
        );
    }

    private function open_mysql_on_sqlite_database(): \Reprint\Importer\Database\DatabaseConnection
    {
        $dsn = "mysql-on-sqlite:path={$this->database_path};dbname=wp_test";
        $database = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $sqlite_pdo = $database->get_connection()->get_pdo();
        \Reprint\Importer\register_sqlite_function($sqlite_pdo, 'FROM_BASE64', function ($data) {
            return $data === null ? null : base64_decode($data);
        });
        return new \Reprint\Importer\Database\PdoDatabaseConnection($database, $sqlite_pdo);
    }

    private function command_options(): array
    {
        return [
            'command' => 'db-rewrite-urls',
            'abort' => false,
            'verbose' => false,
            'progress' => 'jsonl',
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $this->database_path,
            'target_db' => 'wp_test',
            'rewrite_url' => [
                ['https://old.example', 'https://new.example'],
            ],
        ];
    }

    private function new_client(): \ImportClient
    {
        return new \ImportClient(
            $this->remote_reprint_api_url,
            $this->temp_dir,
            $this->temp_dir . '/fs-root'
        );
    }

    private function read_state(\ImportClient $client): array
    {
        return json_decode(
            file_get_contents($client->pull_state_directory . '/state.json'),
            true
        );
    }

    private function remove_directory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
