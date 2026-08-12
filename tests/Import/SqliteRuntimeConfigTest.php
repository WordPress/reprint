<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class SqliteRuntimeConfigTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Canonical temp root, so paths the command resolves with realpath()
        // match the ones the assertions build (macOS symlinks /var to
        // /private/var).
        $this->tempDir = realpath(sys_get_temp_dir()) . '/sqlite-runtime-config-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot . '/wp-content/database', 0755, true);
        file_put_contents($this->fsRoot . '/index.php', "<?php echo 'ok';\n");
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }

        rmdir($dir);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            'preflight' => [
                'http_code' => 200,
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                    ],
                    'database' => [
                        'wp' => [
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => $this->fsRoot,
                                'home_url' => 'https://source.example',
                                'site_url' => 'https://source.example',
                            ],
                        ],
                    ],
                ],
            ],
            'apply' => [
                'target_engine' => 'sqlite',
                'target_db' => 'wp_runtime',
                'target_sqlite_path' => $this->fsRoot . '/wp-content/database/.ht.sqlite',
            ],
            'webhost' => 'other',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'error',
            'filter' => 'none',
            'max_allowed_packet' => null,
        ];

        \write_current_pull_state(
            new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot),
            array_replace_recursive($defaults, $state)
        );
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $method_reflection = $reflection->getMethod($method);
        return $method_reflection->invoke($client, ...$args);
    }

    private function setPrivate(\ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setValue($client, $value);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    /**
     * Run apply-runtime with the given options and return runtime.php.
     */
    private function applyRuntime(array $options): string
    {
        $client = new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
        $this->loadClientState($client);

        ob_start();
        try {
            $this->callPrivate($client, 'run_apply_runtime', [$options + [
                'runtime' => 'php-builtin',
                'output_dir' => $this->outputDir,
                'flat_document_root' => $this->fsRoot,
            ]]);
        } finally {
            ob_end_clean();
        }

        return file_get_contents($this->outputDir . '/runtime.php');
    }

    /**
     * Run apply-runtime expecting it to reject the options, and return the message.
     */
    private function applyRuntimeExpectingRejection(array $options): string
    {
        try {
            $this->applyRuntime($options);
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected apply-runtime to reject the database target options');
    }

    /** State with no database target, as left by a pull that skipped db-apply. */
    private function emptyApplyState(): array
    {
        return [
            'apply' => [
                'target_engine' => null,
                'target_db' => null,
                'target_sqlite_path' => null,
            ],
        ];
    }

    public function testApplyRuntimeDefinesDbNameForSqliteTarget(): void
    {
        $this->writeState([]);

        $runtime = $this->applyRuntime([]);

        $this->assertStringContainsString("define('DB_NAME', 'wp_runtime');", $runtime);
        $this->assertStringContainsString("define('DB_DIR'", $runtime);
        $this->assertStringContainsString("define('DB_FILE'", $runtime);
        $this->assertStringContainsString("Constant already defined", $runtime);
    }

    public function testSqliteTargetOptionsConfigureTheRuntimeWithoutDbApplyState(): void
    {
        $this->writeState($this->emptyApplyState());

        $sqlite_path = $this->fsRoot . '/wp-content/database/.ht.sqlite';
        $runtime = $this->applyRuntime([
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $sqlite_path,
        ]);

        $this->assertStringContainsString(
            "define('DB_DIR',  '" . $this->fsRoot . "/wp-content/database/');",
            $runtime
        );
        $this->assertStringContainsString("define('DB_FILE', '.ht.sqlite');", $runtime);
        $this->assertStringContainsString("define('DB_NAME', 'sqlite_database');", $runtime);
        $this->assertDirectoryExists($this->outputDir . '/sqlite-database-integration');
    }

    public function testSqlitePathOptionOverridesThePathInState(): void
    {
        $this->writeState([]);

        mkdir($this->fsRoot . '/other-database', 0755, true);
        $runtime = $this->applyRuntime([
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $this->fsRoot . '/other-database/chosen.sqlite',
        ]);

        $this->assertStringContainsString(
            "define('DB_DIR',  '" . $this->fsRoot . "/other-database/');",
            $runtime
        );
        $this->assertStringContainsString("define('DB_FILE', 'chosen.sqlite');", $runtime);
    }

    public function testSqliteEngineOptionWithoutAPathKeepsThePathFromState(): void
    {
        $this->writeState([]);

        $runtime = $this->applyRuntime(['target_engine' => 'sqlite']);

        $this->assertStringContainsString(
            "define('DB_DIR',  '" . $this->fsRoot . "/wp-content/database/');",
            $runtime
        );
        $this->assertStringContainsString("define('DB_FILE', '.ht.sqlite');", $runtime);
        $this->assertStringContainsString("define('DB_NAME', 'wp_runtime');", $runtime);
    }

    public function testSqliteEngineOptionKeepsARecordedPathWhoseDirectoryIsGone(): void
    {
        // flat-docroot moves the tree after db-apply records the path, so a
        // recorded path need not exist. Naming the engine must not turn a run
        // that works without options into an error.
        $this->writeState([
            'apply' => [
                'target_sqlite_path' => $this->tempDir . '/moved-away/database/.ht.sqlite',
            ],
        ]);

        $runtime = $this->applyRuntime(['target_engine' => 'sqlite']);

        $this->assertStringContainsString(
            "define('DB_DIR',  '" . $this->tempDir . "/moved-away/database/');",
            $runtime
        );
    }

    public function testRelativeSqlitePathBecomesAbsoluteInTheRuntime(): void
    {
        $this->writeState($this->emptyApplyState());

        $previous_directory = getcwd();
        chdir($this->fsRoot);
        try {
            $runtime = $this->applyRuntime([
                'target_engine' => 'sqlite',
                'target_sqlite_path' => './wp-content/database/.ht.sqlite',
            ]);
        } finally {
            chdir($previous_directory);
        }

        $this->assertStringContainsString(
            "define('DB_DIR',  '" . $this->fsRoot . "/wp-content/database/');",
            $runtime
        );
        $this->assertStringContainsString("define('DB_FILE', '.ht.sqlite');", $runtime);
    }

    public function testMysqlTargetOptionsConfigureTheRuntimeWithoutDbApplyState(): void
    {
        $this->writeState($this->emptyApplyState());

        $runtime = $this->applyRuntime([
            'target_engine' => 'mysql',
            'target_host' => 'db.local',
            'target_port' => 3307,
            'target_user' => 'wp_user',
            'target_pass' => 'wp_password',
            'target_db' => 'wp_target',
        ]);

        $this->assertStringContainsString("define('DB_HOST', 'db.local:3307');", $runtime);
        $this->assertStringContainsString("define('DB_NAME', 'wp_target');", $runtime);
        $this->assertStringContainsString("define('DB_USER', 'wp_user');", $runtime);
        $this->assertStringContainsString("define('DB_PASSWORD', 'wp_password');", $runtime);
    }

    public function testSqlitePathWithoutAnEngineIsRejected(): void
    {
        $this->writeState($this->emptyApplyState());

        $message = $this->applyRuntimeExpectingRejection([
            'target_sqlite_path' => $this->fsRoot . '/wp-content/database/.ht.sqlite',
        ]);

        $this->assertStringContainsString('--target-sqlite-path', $message);
        $this->assertStringContainsString('--target-engine', $message);
    }

    public function testUnknownTargetEngineIsRejected(): void
    {
        $this->writeState($this->emptyApplyState());

        $message = $this->applyRuntimeExpectingRejection(['target_engine' => 'postgres']);

        $this->assertStringContainsString('postgres', $message);
        $this->assertStringContainsString('mysql, sqlite', $message);
    }

    public function testSqlitePathInAMissingDirectoryIsRejected(): void
    {
        $this->writeState($this->emptyApplyState());

        $sqlite_path = $this->fsRoot . '/absent-directory/.ht.sqlite';
        $message = $this->applyRuntimeExpectingRejection([
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $sqlite_path,
        ]);

        $this->assertStringContainsString($sqlite_path, $message);
        $this->assertStringContainsString($this->fsRoot . '/absent-directory', $message);
    }

    public function testApplyRuntimeMasksTheTargetDatabasePasswordInItsAuditLog(): void
    {
        $password = 'database-password-' . bin2hex(random_bytes(4));
        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->stateDir,
            $this->fsRoot,
        );

        $client->audit_log_argv('apply-runtime', [
            'reprint',
            'apply-runtime',
            'https://source.example/export.php',
            '--target-pass=' . $password,
        ]);

        $audit_log = (string) file_get_contents($this->stateDir . '/audit.log');
        $this->assertStringNotContainsString($password, $audit_log);
        $this->assertStringContainsString('--target-pass=***', $audit_log);
    }

    public function testDefaultSqlitePathKeepsTheRootContentDirectory(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'paths_urls' => [
                                'content_dir' => '/',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->stateDir,
            $this->fsRoot,
        );
        $this->loadClientState($client);
        [$connection] = $this->callPrivate(
            $client,
            'create_target_db_apply_connection',
            [['target_engine' => 'sqlite']],
        );
        $resolved_filesystem_root = realpath($this->fsRoot);
        $this->assertIsString($resolved_filesystem_root);

        $this->assertIsObject($connection);
        $this->assertSame(
            $resolved_filesystem_root . '/database/.ht.sqlite',
            $this->callPrivate($client, 'get_state')->apply->target_sqlite_path,
        );
    }

    public function testApplyRuntimeUsesTheSelectedProgressOutput(): void
    {
        $this->writeState([]);

        $ttyResult = $this->runApplyRuntimeCli('tty', $this->outputDir . '-tty');
        $this->assertSame(0, $ttyResult['exit'], $ttyResult['stderr']);
        $this->assertStringContainsString("Runtime: php-builtin\n", $ttyResult['stdout']);
        $this->assertStringContainsString("Source host: other\n", $ttyResult['stdout']);
        $this->assertStringNotContainsString('{"status":', $ttyResult['stdout']);
        $this->assertSame('', $ttyResult['stderr']);

        $jsonlResult = $this->runApplyRuntimeCli('jsonl', $this->outputDir . '-jsonl');
        $this->assertSame(0, $jsonlResult['exit'], $jsonlResult['stderr']);
        $this->assertSame('', $jsonlResult['stderr']);
        $record = json_decode(trim($jsonlResult['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('complete', $record['status'] ?? null);
        $this->assertSame('apply-runtime', $record['command'] ?? null);
        $this->assertSame('php-builtin', $record['runtime'] ?? null);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runApplyRuntimeCli(string $progressMode, string $outputDir): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                'apply-runtime',
                'https://source.example/export.php',
                '--state-dir=' . $this->stateDir,
                '--flat-document-root=' . $this->fsRoot,
                '--output-dir=' . $outputDir,
                '--runtime=php-builtin',
                '--progress=' . $progressMode,
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
