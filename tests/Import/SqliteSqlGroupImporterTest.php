<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class SqliteSqlGroupImporterTest extends TestCase
{
    private const MARKER = '-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 ';

    private string $tempDir;
    private string $sqlitePath;
    private string $databaseName = 'wp_test';
    private string $remoteUrl = 'https://source.example.com/?reprint-api';

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->tempDir = sys_get_temp_dir() . '/sqlite-sql-group-importer-' . uniqid();
        $this->sqlitePath = $this->tempDir . '/database/wordpress.sqlite';
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);

        $this->writeState();
        $this->openDatabase()->exec(
            'CREATE TABLE `import_probe` (' .
            '`id` bigint NOT NULL, `attempts` bigint NOT NULL, `finished` bigint NOT NULL, ' .
            'PRIMARY KEY (`id`))'
        );
        $this->openDatabase()->exec(
            'INSERT INTO `import_probe` (`id`, `attempts`, `finished`) VALUES (1, 0, 0)'
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testRollsBackACompleteSqlGroupWhenOneStatementFails(): void
    {
        $this->writeGroups([
            [
                'sql' => "UPDATE `import_probe` SET `attempts` = `attempts` + 1 WHERE `id` = 1;\n" .
                    "INSERT INTO `missing_table` (`id`) VALUES (1);\n",
                'cursor' => ['current_table' => 'missing_table'],
            ],
        ]);

        try {
            $this->runApply(new \ImportClient(
                $this->remoteUrl,
                $this->tempDir,
                $this->tempDir . '/fs-root'
            ));
            $this->fail('The invalid statement should stop db-apply.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('missing_table', $error->getMessage());
        }

        $this->assertSame(0, $this->readProbe()['attempts']);
    }

    public function testResumesFromTheSqlGroupSavedInsideSqlite(): void
    {
        $updates = str_repeat(
            "UPDATE `import_probe` SET `attempts` = `attempts` + 1 WHERE `id` = 1;\n",
            100
        );
        $this->writeGroups([
            [
                'sql' => $updates,
                'cursor' => ['current_table' => 'import_probe'],
            ],
            [
                'sql' => "UPDATE `import_probe` SET `finished` = 1 WHERE `id` = 1;\n",
                'cursor' => ['current_table' => null],
            ],
        ]);

        $sqlitePath = $this->sqlitePath;
        $firstApply = new class(
            $this->remoteUrl,
            $this->tempDir,
            $this->tempDir . '/fs-root',
            $sqlitePath
        ) extends \ImportClient {
            private string $sqlitePath;
            private bool $stopped = false;

            public function __construct(
                string $remoteUrl,
                string $stateDirectory,
                string $filesystemRoot,
                string $sqlitePath
            ) {
                parent::__construct($remoteUrl, $stateDirectory, $filesystemRoot);
                $this->sqlitePath = $sqlitePath;
            }

            public function save_state(): void
            {
                if (!$this->stopped && file_exists($this->sqlitePath)) {
                    $database = new \PDO('sqlite:' . $this->sqlitePath);
                    $statement = $database->query(
                        'SELECT "attempts", "finished" FROM "import_probe" WHERE "id" = 1'
                    );
                    $row = $statement->fetch(\PDO::FETCH_ASSOC);
                    $statement->closeCursor();
                    unset($statement, $database);
                    if ( (int) $row['attempts'] === 100 && (int) $row['finished'] === 0 ) {
                        $this->stopped = true;
                        throw new \RuntimeException('Stop after the first committed SQL group.');
                    }
                }

                parent::save_state();
            }
        };
        $pullStateDirectory = $firstApply->pull_state_directory;

        try {
            $this->runApply($firstApply);
            $this->fail('The first db-apply should stop after its first SQL group.');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'Stop after the first committed SQL group.',
                $error->getMessage()
            );
        }
        unset($firstApply, $error);
        gc_collect_cycles();

        $this->assertSame(
            ['attempts' => 100, 'finished' => 0],
            $this->readProbe()
        );
        $sqlite = new \PDO('sqlite:' . $this->sqlitePath);
        $position = $sqlite->query(
            "SELECT `source_cursor`, `file_byte_offset` " .
            "FROM `__reprint_db_pull_progress_49acb118-a97a-45c7-814d-8e670db7f6b4`"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($position);
        $this->assertGreaterThan(0, (int) $position['file_byte_offset']);
        unset($sqlite);

        $localState = json_decode(
            file_get_contents($pullStateDirectory . '/state.json'),
            true
        );
        $this->assertSame(0, $localState['apply']['bytes_read']);
        $this->assertNull($localState['active_resumable_command']['remote_cursor']);

        $this->runApply(new \ImportClient(
            $this->remoteUrl,
            $this->tempDir,
            $this->tempDir . '/fs-root'
        ));

        $this->assertSame(
            ['attempts' => 100, 'finished' => 1],
            $this->readProbe()
        );
        $sqlite = new \PDO('sqlite:' . $this->sqlitePath);
        $positionTables = $sqlite->query(
            "SELECT COUNT(*) FROM sqlite_master " .
            "WHERE type = 'table' AND name LIKE '__reprint_db_pull_progress_%'"
        )->fetchColumn();
        $this->assertSame(0, (int) $positionTables);
    }

    public function testRefusesATamperedUnfinishedStageBeforeExecutingSql(): void
    {
        $this->writeGroups([
            [
                'sql' => "UPDATE `import_probe` SET `attempts` = `attempts` + 1 WHERE `id` = 1;\n",
                'cursor' => ['current_table' => 'import_probe'],
            ],
        ]);

        $client = new \ImportClient(
            $this->remoteUrl,
            $this->tempDir,
            $this->tempDir . '/fs-root'
        );
        $state = $client->get_state()->active_resumable_command;
        $state->command_name = 'db-apply';
        $state->completion_state = 'partial';
        $state->current_stage = null;
        $client->save_state();

        $error = null;
        try {
            $this->runApply($client);
        } catch (\RuntimeException $caughtError) {
            $error = $caughtError;
        }

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('saved stage is not supported', $error->getMessage());
        $this->assertSame(0, $this->readProbe()['attempts']);
    }

    private function runApply(\ImportClient $client): void
    {
        $client->run([
            'command' => 'db-apply',
            'abort' => false,
            'verbose' => false,
            'secret' => null,
            'tuning_config' => [],
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $this->sqlitePath,
            'target_db' => $this->databaseName,
        ]);
    }

    private function writeState(): void
    {
        \write_current_pull_state(
            new \ImportClient(
                $this->remoteUrl,
                $this->tempDir,
                $this->tempDir . '/fs-root'
            ),
            []
        );
    }

    /**
     * @param array<int, array{sql:string,cursor:array<string,mixed>}> $groups
     */
    private function writeGroups(array $groups): void
    {
        $dump = '';
        foreach ($groups as $group) {
            $dump .= $group['sql'];
            $dump .= self::MARKER . base64_encode(json_encode($group['cursor'])) . "\n";
        }
        file_put_contents($this->tempDir . '/db.sql', $dump);
    }

    private function readProbe(): array
    {
        $database = new \PDO('sqlite:' . $this->sqlitePath);
        $statement = $database->query(
            'SELECT "attempts", "finished" FROM "import_probe" WHERE "id" = 1'
        );
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        unset($statement, $database);

        return [
            'attempts' => (int) $row['attempts'],
            'finished' => (int) $row['finished'],
        ];
    }

    private function openDatabase(): \WP_PDO_MySQL_On_SQLite
    {
        $driver = \Reprint\Importer\resolve_sqlite_integration_path(
            '/packages/mysql-on-sqlite/src/load.php'
        );
        require_once $driver;

        $directory = dirname($this->sqlitePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $dsn = "mysql-on-sqlite:path={$this->sqlitePath};dbname={$this->databaseName}";
        return new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
