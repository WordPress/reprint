<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class DatabaseCommandRestartTest extends TestCase
{
    private string $root;
    /** @var int[] */
    private array $childPids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/reprint-database-restart-' . uniqid('', true);
        mkdir($this->root . '/state', 0755, true);
        mkdir($this->root . '/files', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->childPids as $childPid) {
            $waitResult = pcntl_waitpid($childPid, $status, WNOHANG);
            if ($waitResult === 0 && function_exists('posix_kill') && defined('SIGKILL')) {
                posix_kill($childPid, SIGKILL);
                pcntl_waitpid($childPid, $status);
            }
        }
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testDbPullContinuesAfterExitCodeTwo(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('The local streaming endpoint requires pcntl.');
        }

        $sql = "SELECT 2;\n";
        [$remoteUrl, $serverPid] = $this->startOneResponseServer(function (array $query) use ($sql): string {
            $this->assertSame('sql_chunk', $query['endpoint'] ?? null);
            $this->assertSame('saved-sql-cursor', base64_decode($query['cursor'] ?? '', true));
            return $this->multipartResponse([
                [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('final-sql-cursor'),
                    ],
                    'body' => $sql,
                ],
                [
                    'headers' => [
                        'X-Chunk-Type' => 'completion',
                        'X-Status' => 'complete',
                    ],
                    'body' => '',
                ],
            ]);
        });

        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpIntent($client);
        $state = $client->get_state();
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'partial';
        $state->active_resumable_command->current_stage = 'sql';
        $state->active_resumable_command->remote_cursor = base64_encode('saved-sql-cursor');
        $state->sql_bytes = strlen("SELECT 1;\n");
        $state->sql_output = 'file';
        $client->save_state();
        file_put_contents($this->root . '/state/db.sql', "SELECT 1;\n");

        try {
            $result = $this->runCli([
                'db-pull',
                $remoteUrl,
                '--state-dir=' . $this->root . '/state',
                '--fs-root=' . $this->root . '/files',
                '--progress=jsonl',
            ]);
        } finally {
            pcntl_waitpid($serverPid, $serverStatus);
        }

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('"event":"resuming"', $result['output']);
        $this->assertSame("SELECT 1;\nSELECT 2;\n", file_get_contents($this->root . '/state/db.sql'));
        $dumpRecord = json_decode(
            (string) file_get_contents($client->pull_state_directory . '/database-dump.json'),
            true,
        );
        $this->assertIsArray($dumpRecord);
        $this->assertSame(
            hash_file('sha256', $this->root . '/state/db.sql'),
            $dumpRecord['sha256'] ?? null,
        );
        $this->assertTrue($dumpRecord['create_table_query'] ?? false);
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
    }

    public function testDbPullCrashKeepsThePartNamedByItsSavedCursor(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !defined('SIGKILL')) {
            $this->markTestSkipped('The process-death test requires pcntl and posix signals.');
        }

        $readyPath = $this->root . '/sql-stream-ready';
        $releasePath = $this->root . '/sql-stream-release';
        [$remoteUrl, $serverPid] = $this->startTwoResponseSqlServer($readyPath, $releasePath);
        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpIntent($client);
        $state = $client->get_state();
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'in_progress';
        $state->active_resumable_command->current_stage = 'sql';
        $state->sql_output = 'file';
        $client->save_state();

        [$process] = $this->startCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ], true);

        $pullStatePath = $client->pull_state_directory . '/state.json';
        $expectedFirstResponse = $this->sqlStatements(1, 50);
        $this->waitUntil(function () use ($readyPath, $pullStatePath, $expectedFirstResponse): bool {
            if (!is_file($readyPath) || !is_file($pullStatePath)) {
                return false;
            }
            $state = json_decode( (string) file_get_contents($pullStatePath), true );
            return base64_decode($state['active_resumable_command']['remote_cursor'] ?? '', true) === 'cursor-50'
                && is_file($this->root . '/state/db.sql')
                && filesize($this->root . '/state/db.sql') === strlen($expectedFirstResponse);
        }, 'The first process did not store the cursor for SQL part 50.');

        $status = proc_get_status($process);
        $this->assertTrue($status['running']);
        $this->assertTrue(posix_kill($status['pid'], SIGKILL));
        proc_close($process);
        file_put_contents($releasePath, '');

        $result = $this->runCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ]);
        pcntl_waitpid($serverPid, $serverStatus);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($this->sqlStatements(1, 60), file_get_contents($this->root . '/state/db.sql'));
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
    }

    public function testDbPullDropsCursorlessBytesBeforeSavingItsFirstBoundary(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !defined('SIGKILL')) {
            $this->markTestSkipped('The process-death test requires pcntl and posix signals.');
        }

        $firstReadyPath = $this->root . '/first-sql-stream-ready';
        $firstReleasePath = $this->root . '/first-sql-stream-release';
        $secondReadyPath = $this->root . '/second-sql-stream-ready';
        $secondReleasePath = $this->root . '/second-sql-stream-release';
        [$remoteUrl, $serverPid] = $this->startThreeResponseSqlServer(
            $firstReadyPath,
            $firstReleasePath,
            $secondReadyPath,
            $secondReleasePath,
        );
        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpIntent($client);
        $state = $client->get_state();
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'in_progress';
        $state->active_resumable_command->current_stage = 'sql';
        $state->sql_output = 'file';
        $client->save_state();

        [$firstProcess] = $this->startCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ], true);

        $pullStatePath = $client->pull_state_directory . '/state.json';
        $firstUnconfirmedBytes = $this->sqlStatements(1, 10);
        $this->waitUntil(function () use ($firstReadyPath, $pullStatePath, $firstUnconfirmedBytes): bool {
            if (!is_file($firstReadyPath) || !is_file($pullStatePath)) {
                return false;
            }
            $state = json_decode( (string) file_get_contents($pullStatePath), true );
            return empty($state['active_resumable_command']['remote_cursor'])
                && is_file($this->root . '/state/db.sql')
                && filesize($this->root . '/state/db.sql') === strlen($firstUnconfirmedBytes);
        }, 'The first process did not write bytes before its first saved cursor.');

        $firstStatus = proc_get_status($firstProcess);
        $this->assertTrue($firstStatus['running']);
        $this->assertTrue(posix_kill($firstStatus['pid'], SIGKILL));
        proc_close($firstProcess);
        file_put_contents($firstReleasePath, '');

        [$secondProcess] = $this->startCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ], true);

        $secondCheckpointState = null;
        $this->waitUntil(function () use (
            $secondProcess,
            $secondReadyPath,
            $pullStatePath,
            &$secondCheckpointState
        ): bool {
            if (!is_file($secondReadyPath) || !is_file($pullStatePath)) {
                return false;
            }
            if (!proc_get_status($secondProcess)['running']) {
                return false;
            }
            $state = json_decode( (string) file_get_contents($pullStatePath), true );
            if (base64_decode($state['active_resumable_command']['remote_cursor'] ?? '', true) !== 'cursor-50') {
                return false;
            }
            $secondCheckpointState = $state;
            return true;
        }, 'The second process did not save its first SQL cursor.');

        $secondStatus = proc_get_status($secondProcess);
        $this->assertTrue($secondStatus['running']);
        $this->assertTrue(posix_kill($secondStatus['pid'], SIGKILL));
        proc_close($secondProcess);
        file_put_contents($secondReleasePath, '');

        $expectedCheckpointBytes = $this->sqlStatements(1, 50);
        $this->assertSame(strlen($expectedCheckpointBytes), $secondCheckpointState['sql_bytes']);
        $this->assertSame(strlen($expectedCheckpointBytes), filesize($this->root . '/state/db.sql'));

        $result = $this->runCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ]);
        pcntl_waitpid($serverPid, $serverStatus);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($this->sqlStatements(1, 60), file_get_contents($this->root . '/state/db.sql'));
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
    }

    public function testStandaloneDbApplyDefersSignalsBetweenSqlChunks(): void
    {
        if (
            !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_sigprocmask')
        ) {
            $this->markTestSkipped('The signal policy test requires PCNTL signal APIs.');
        }

        $originalAsyncSignals = pcntl_async_signals();
        $originalSigintHandler = pcntl_signal_get_handler(SIGINT);
        $originalSigtermHandler = pcntl_signal_get_handler(SIGTERM);
        pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $originalSignalMask);
        pcntl_sigprocmask(SIG_SETMASK, $originalSignalMask);

        try {
            $client = new \ImportClient(
                'http://standalone-db-apply-signals.test/export',
                $this->root . '/state',
                $this->root . '/files',
                'db-apply',
            );

            $this->assertFalse(pcntl_async_signals());
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $databaseApplySignalMask);
            pcntl_sigprocmask(SIG_SETMASK, $databaseApplySignalMask);
            $this->assertContains(SIGINT, $databaseApplySignalMask);
            $this->assertContains(SIGTERM, $databaseApplySignalMask);

            $sigintHandler = pcntl_signal_get_handler(SIGINT);
            $sigtermHandler = pcntl_signal_get_handler(SIGTERM);
            $this->assertIsArray($sigintHandler);
            $this->assertSame($client, $sigintHandler[0]);
            $this->assertSame('handle_database_apply_shutdown', $sigintHandler[1]);
            $this->assertIsArray($sigtermHandler);
            $this->assertSame($client, $sigtermHandler[0]);
            $this->assertSame('handle_database_apply_shutdown', $sigtermHandler[1]);
        } finally {
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM]);
            pcntl_async_signals(false);
            pcntl_signal(SIGINT, $originalSigintHandler);
            pcntl_signal(SIGTERM, $originalSigtermHandler);
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM]);
            pcntl_async_signals($originalAsyncSignals);
            pcntl_sigprocmask(SIG_SETMASK, $originalSignalMask);
        }
    }

    public function testDbApplyContinuesAfterExitCodeTwo(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGTERM') || !defined('SIGSTOP') || !defined('SIGCONT')) {
            $this->markTestSkipped('The orderly interruption test requires posix signals.');
        }

        $remoteUrl = 'http://db-apply-partial.test/export';
        $sqlitePath = $this->root . '/partial.sqlite';
        $client = $this->newClient($remoteUrl);
        $this->writeItemDump(5000);
        $this->writeReplacementDumpRecord($client);
        $pullStatePath = $client->pull_state_directory . '/state.json';

        [$process, $pipes] = $this->startCli([
            'db-apply',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--target-engine=sqlite',
            '--target-sqlite-path=' . $sqlitePath,
            '--target-db=wordpress',
            '--progress=jsonl',
        ]);
        $this->waitUntil(function () use ($process, $pullStatePath): bool {
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                return false;
            }
            $state = json_decode( (string) @file_get_contents($pullStatePath), true );
            return (int) ( $state['apply']['statements_executed'] ?? 0 ) >= 100;
        }, 'db-apply completed before the test could request an orderly stop.');

        $status = proc_get_status($process);
        $this->assertTrue(posix_kill($status['pid'], SIGSTOP));
        $this->assertTrue(posix_kill($status['pid'], SIGTERM));
        $this->assertTrue(posix_kill($status['pid'], SIGCONT));
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(2, proc_close($process), (string) $stdout . (string) $stderr);

        $partialState = json_decode( (string) file_get_contents($pullStatePath), true );
        $this->assertSame('partial', $partialState['active_resumable_command']['completion_state']);
        $this->assertGreaterThan(0, $partialState['apply']['statements_executed']);
        $this->assertLessThan(5002, $partialState['apply']['statements_executed']);

        $result = $this->runCli([
            'db-apply',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--target-engine=sqlite',
            '--target-sqlite-path=' . $sqlitePath,
            '--target-db=wordpress',
            '--progress=jsonl',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('"event":"restarting"', $result['output']);
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $this->assertSame(5000, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testDbApplyRestartsTheDumpAfterProcessDeath(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGKILL') || !defined('SIGSTOP') || !defined('SIGCONT')) {
            $this->markTestSkipped('The process-death test requires posix signals.');
        }

        $remoteUrl = 'http://db-apply-crash.test/export';
        $sqlitePath = $this->root . '/crash.sqlite';
        $this->writeItemDump(5000);
        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpRecord($client);
        $pullStatePath = $client->pull_state_directory . '/state.json';

        [$process] = $this->startCli([
            'db-apply',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--target-engine=sqlite',
            '--target-sqlite-path=' . $sqlitePath,
            '--target-db=wordpress',
            '--progress=jsonl',
        ], true);

        $savedStatements = 0;
        $this->waitUntil(function () use ($process, $pullStatePath, &$savedStatements): bool {
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                return false;
            }
            $state = json_decode( (string) @file_get_contents($pullStatePath), true );
            $savedStatements = (int) ( $state['apply']['statements_executed'] ?? 0 );
            return $savedStatements >= 100;
        }, 'db-apply completed before the test could stop it at a saved boundary.');

        $status = proc_get_status($process);
        $this->assertTrue(posix_kill($status['pid'], SIGSTOP));
        $this->assertTrue(posix_kill($status['pid'], SIGCONT));
        usleep(20000);
        $this->assertTrue(posix_kill($status['pid'], SIGKILL));
        proc_close($process);

        $crashState = json_decode( (string) file_get_contents($pullStatePath), true );
        $savedStatements = (int) ( $crashState['apply']['statements_executed'] ?? 0 );
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $committedRows = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        $this->assertGreaterThan($savedStatements - 2, $committedRows);
        $this->assertLessThan(5000, $committedRows);
        $pdo = null;

        $result = $this->runCli([
            'db-apply',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--target-engine=sqlite',
            '--target-sqlite-path=' . $sqlitePath,
            '--target-db=wordpress',
            '--progress=jsonl',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('"event":"restarting"', $result['output']);
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $this->assertSame(5000, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testDbApplyRefusesToRestartAnUnconfirmedDump(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGKILL') || !defined('SIGSTOP')) {
            $this->markTestSkipped('The process-death test requires posix signals.');
        }

        $remoteUrl = 'http://db-apply-unconfirmed.test/export';
        $sqlitePath = $this->root . '/unconfirmed.sqlite';
        $this->createRestartProbe($sqlitePath);
        file_put_contents($this->root . '/state/db.sql', $this->restartProbeDump(5000));
        $client = $this->newClient($remoteUrl);
        $pullStatePath = $client->pull_state_directory . '/state.json';

        [$process] = $this->startCli(
            $this->databaseApplyArguments($remoteUrl, $sqlitePath),
            true,
        );
        $this->waitUntil(function () use ($process, $pullStatePath): bool {
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                return false;
            }
            $state = json_decode( (string) @file_get_contents($pullStatePath), true );
            return (int) ( $state['apply']['statements_executed'] ?? 0 ) >= 100;
        }, 'db-apply completed before the test could stop it at a saved boundary.');
        $status = proc_get_status($process);
        $this->assertTrue(posix_kill($status['pid'], SIGSTOP));
        $this->assertTrue(posix_kill($status['pid'], SIGKILL));
        proc_close($process);

        $this->assertSame(1, $this->restartAttempts($sqlitePath));
        $state = json_decode(
            (string) file_get_contents($pullStatePath),
            true,
        );
        $this->assertSame('in_progress', $state['active_resumable_command']['completion_state']);

        $secondResult = $this->runCli($this->databaseApplyArguments($remoteUrl, $sqlitePath));

        $this->assertNotSame(0, $secondResult['exit'], $secondResult['output']);
        $this->assertStringContainsString('db-apply stopped after it may have changed the target database', $secondResult['output']);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));
    }

    public function testFreshDbApplyConnectionFailureDoesNotStartAnAttempt(): void
    {
        $remoteUrl = 'http://fresh-db-apply-admission.test/export';
        $sqlitePath = $this->root . '/fresh-db-apply-admission.sqlite';
        $this->createRestartProbe($sqlitePath);
        file_put_contents($this->root . '/state/db.sql', $this->restartProbeDump(10));
        $client = new class(
            $remoteUrl,
            $this->root . '/state',
            $this->root . '/files',
            $sqlitePath,
        ) extends \ImportClient {
            private string $targetSqlitePath;
            public bool $observedAttemptBeforeTargetSql = false;

            public function __construct(
                string $remoteUrl,
                string $stateDirectory,
                string $filesystemRoot,
                string $targetSqlitePath
            ) {
                $this->targetSqlitePath = $targetSqlitePath;
                parent::__construct($remoteUrl, $stateDirectory, $filesystemRoot);
            }

            public function save_state(): void
            {
                $activeCommand = $this->get_state()->active_resumable_command;
                if (
                    !$this->observedAttemptBeforeTargetSql
                    && $activeCommand->command_name === 'db-apply'
                    && $activeCommand->completion_state === 'in_progress'
                ) {
                    $attempt = json_decode(
                        (string) @file_get_contents(
                            $this->pull_state_directory . '/database-apply.json',
                        ),
                        true,
                    );
                    if (( $attempt['status'] ?? null ) !== 'in_progress') {
                        throw new \LogicException(
                            'db-apply state was saved before its restart information.',
                        );
                    }
                    $pdo = new \PDO('sqlite:' . $this->targetSqlitePath);
                    $targetAttempts = (int) $pdo->query(
                        'SELECT attempts FROM restart_probe WHERE id = 1',
                    )->fetchColumn();
                    if ($targetAttempts !== 0) {
                        throw new \LogicException(
                            'db-apply executed target SQL before saving its restart information.',
                        );
                    }
                    $this->observedAttemptBeforeTargetSql = true;
                }

                parent::save_state();
            }
        };
        $client->get_state()->set_preflight_record([
            'http_code' => 200,
            'data' => ['ok' => true],
        ]);
        $client->save_state();

        try {
            $client->run_db_apply([
                'target_engine' => 'sqlite',
                'target_sqlite_path' => $this->root . '/files',
                'target_db' => 'wordpress',
            ]);
            $this->fail('The directory path unexpectedly opened as a SQLite database.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString(
                'Cannot connect to target SQLite database',
                $error->getMessage(),
            );
        }

        $attemptPath = $client->pull_state_directory . '/database-apply.json';
        $this->assertFileDoesNotExist($attemptPath);
        $failedState = json_decode(
            (string) file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertNull($failedState['active_resumable_command']['command_name']);
        $this->assertNull($failedState['active_resumable_command']['completion_state']);
        $this->assertFalse($client->observedAttemptBeforeTargetSql);

        $client->run_db_apply([
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $sqlitePath,
            'target_db' => 'wordpress',
        ]);

        $this->assertTrue($client->observedAttemptBeforeTargetSql);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));
    }

    public function testDbApplyAttemptSurvivesDbIndexCommandState(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGKILL') || !defined('SIGSTOP')) {
            $this->markTestSkipped('The process-death test requires posix signals.');
        }
        [$remoteUrl, $serverPid] = $this->startOneResponseServer(function (array $query): string {
            $this->assertSame('db_index', $query['endpoint'] ?? null);
            return $this->multipartResponse([
                [
                    'headers' => [
                        'X-Chunk-Type' => 'completion',
                        'X-Status' => 'complete',
                    ],
                    'body' => '',
                ],
            ]);
        }, 60);
        $sqlitePath = $this->root . '/db-index-overwrite.sqlite';
        $this->createRestartProbe($sqlitePath);
        file_put_contents($this->root . '/state/db.sql', $this->restartProbeDump(5000));
        $client = $this->newClient($remoteUrl);
        [$process] = $this->startCli(
            $this->databaseApplyArguments($remoteUrl, $sqlitePath),
            true,
        );
        $pullStatePath = $client->pull_state_directory . '/state.json';
        $this->waitUntil(function () use ($process, $pullStatePath): bool {
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                return false;
            }
            $state = json_decode( (string) @file_get_contents($pullStatePath), true );
            return (int) ( $state['apply']['statements_executed'] ?? 0 ) >= 100;
        }, 'db-apply completed before the test could stop it at a saved boundary.');

        $status = proc_get_status($process);
        $this->assertTrue(posix_kill($status['pid'], SIGSTOP));
        $this->assertTrue(posix_kill($status['pid'], SIGKILL));
        proc_close($process);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));

        try {
            $indexResult = $this->runCli([
                'db-index',
                $remoteUrl,
                '--state-dir=' . $this->root . '/state',
                '--fs-root=' . $this->root . '/files',
                '--progress=jsonl',
            ]);
        } finally {
            pcntl_waitpid($serverPid, $serverStatus);
        }

        $this->assertSame(0, $indexResult['exit'], $indexResult['output']);
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
        $state = json_decode(
            (string) file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertSame('db-index', $state['active_resumable_command']['command_name']);
        $this->assertSame('complete', $state['active_resumable_command']['completion_state']);

        $result = $this->runCli($this->databaseApplyArguments($remoteUrl, $sqlitePath));

        $this->assertNotSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('db-apply stopped after it may have changed the target database', $result['output']);
        $this->assertSame(
            1,
            $this->restartAttempts($sqlitePath),
            'db-apply executed the interrupted dump as a new attempt',
        );
    }

    public function testCompletedDbApplyAttemptRepairsTargetStateAfterDbIndexAbort(): void
    {
        $remoteUrl = 'http://completed-apply-repair.test/export';
        $sqlitePath = $this->root . '/completed-db-index-overwrite.sqlite';
        $this->createRestartProbe($sqlitePath);
        file_put_contents($this->root . '/state/db.sql', $this->restartProbeDump(10));
        $client = $this->newClient($remoteUrl);

        $firstApply = $this->runCli($this->databaseApplyArguments($remoteUrl, $sqlitePath));
        $this->assertSame(0, $firstApply['exit'], $firstApply['output']);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));

        $abortedIndex = $this->runCli([
            'db-index',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--abort',
            '--progress=jsonl',
        ]);
        $this->assertSame(0, $abortedIndex['exit'], $abortedIndex['output']);
        $clearedState = json_decode(
            (string) file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertNull($clearedState['apply']['target_engine']);

        $secondApply = $this->runCli($this->databaseApplyArguments($remoteUrl, $sqlitePath));

        $this->assertSame(0, $secondApply['exit'], $secondApply['output']);
        $this->assertSame(
            1,
            $this->restartAttempts($sqlitePath),
            'db-apply repeated target work after another command replaced its completed state',
        );
        $repairedState = json_decode(
            (string) file_get_contents($client->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertSame('sqlite', $repairedState['apply']['target_engine']);
        $this->assertSame('wordpress', $repairedState['apply']['target_db']);
        $this->assertSame(realpath($sqlitePath), $repairedState['apply']['target_sqlite_path']);
    }

    public function testCompletedDbApplyAttemptRepairsTheInProgressStateSaveBoundary(): void
    {
        $remoteUrl = 'http://completed-apply-state-boundary.test/export';
        $sqlitePath = $this->root . '/completed-state-boundary.sqlite';
        $this->createRestartProbe($sqlitePath);
        file_put_contents($this->root . '/state/db.sql', $this->restartProbeDump(10));
        $boundaryClient = new class(
            $remoteUrl,
            $this->root . '/state',
            $this->root . '/files',
        ) extends \ImportClient {
            private bool $hasFailedCompletionStateSave = false;

            public function save_state(): void
            {
                $attempt = json_decode(
                    (string) @file_get_contents(
                        $this->pull_state_directory . '/database-apply.json',
                    ),
                    true,
                );
                $activeCommand = $this->get_state()->active_resumable_command;
                if (
                    !$this->hasFailedCompletionStateSave
                    && ( $attempt['status'] ?? null ) === 'complete'
                    && $activeCommand->command_name === 'db-apply'
                    && $activeCommand->completion_state === 'complete'
                ) {
                    $this->hasFailedCompletionStateSave = true;
                    throw new \RuntimeException(
                        'Injected failure before saving completed db-apply state.',
                    );
                }

                parent::save_state();
            }
        };
        $boundaryClient->get_state()->set_preflight_record([
            'http_code' => 200,
            'data' => ['ok' => true],
        ]);
        $boundaryClient->save_state();
        $this->writeReplacementDumpRecord($boundaryClient);

        try {
            $boundaryClient->run_db_apply([
                'target_engine' => 'sqlite',
                'target_sqlite_path' => $sqlitePath,
                'target_db' => 'wordpress',
            ]);
            $this->fail('The test client did not interrupt the completion state save.');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'Injected failure before saving completed db-apply state.',
                $error->getMessage(),
            );
        }

        $this->assertSame(1, $this->restartAttempts($sqlitePath));
        $boundaryState = json_decode(
            (string) file_get_contents($boundaryClient->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertSame('db-apply', $boundaryState['active_resumable_command']['command_name']);
        $this->assertSame('in_progress', $boundaryState['active_resumable_command']['completion_state']);
        $boundaryAttempt = json_decode(
            (string) file_get_contents(
                $boundaryClient->pull_state_directory . '/database-apply.json',
            ),
            true,
        );
        $this->assertSame('complete', $boundaryAttempt['status']);

        $secondApply = $this->runCli($this->databaseApplyArguments($remoteUrl, $sqlitePath));

        $this->assertSame(0, $secondApply['exit'], $secondApply['output']);
        $this->assertSame(
            1,
            $this->restartAttempts($sqlitePath),
            'db-apply repeated target work instead of repairing its state',
        );
        $repairedState = json_decode(
            (string) file_get_contents($boundaryClient->pull_state_directory . '/state.json'),
            true,
        );
        $this->assertSame('complete', $repairedState['active_resumable_command']['completion_state']);
        $this->assertGreaterThan(0, $repairedState['apply']['statements_executed']);
    }

    public function testDbApplyRejectsADeliberatelyTamperedSqlFileOnRestart(): void
    {
        $this->requireOrderlyStopSignals();
        [$remoteUrl, $client] = $this->pullDatabaseDump($this->restartProbeDump(5000));
        $sqlitePath = $this->root . '/changed-dump.sqlite';
        $this->createRestartProbe($sqlitePath);
        $this->interruptDatabaseApply($client, $remoteUrl, $sqlitePath);
        file_put_contents(
            $this->root . '/state/db.sql',
            "-- changed after the first apply process stopped\n",
            FILE_APPEND,
        );

        $result = $this->runCli($this->databaseApplyArguments($remoteUrl, $sqlitePath));

        $this->assertNotSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('db-apply stopped after it may have changed the target database', $result['output']);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));
    }

    public function testDbApplyRefusesToRestartAgainstAnotherTarget(): void
    {
        $this->requireOrderlyStopSignals();
        [$remoteUrl, $client] = $this->pullDatabaseDump($this->restartProbeDump(5000));
        $firstSqlitePath = $this->root . '/first-target.sqlite';
        $secondSqlitePath = $this->root . '/second-target.sqlite';
        $this->createRestartProbe($firstSqlitePath);
        $this->createRestartProbe($secondSqlitePath);
        $this->interruptDatabaseApply($client, $remoteUrl, $firstSqlitePath);

        $result = $this->runCli($this->databaseApplyArguments($remoteUrl, $secondSqlitePath));

        $this->assertNotSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('db-apply stopped after it may have changed the target database', $result['output']);
        $this->assertSame(0, $this->restartAttempts($secondSqlitePath));
    }

    public function testDbApplyRefusesANewSiteUrlOnRestart(): void
    {
        $this->requireOrderlyStopSignals();
        [$remoteUrl, $client] = $this->pullDatabaseDump($this->restartProbeDump(5000));
        $sqlitePath = $this->root . '/changed-url-map.sqlite';
        $this->createRestartProbe($sqlitePath);
        $this->interruptDatabaseApply($client, $remoteUrl, $sqlitePath);

        $result = $this->runCli(array_merge(
            $this->databaseApplyArguments($remoteUrl, $sqlitePath),
            ['--new-site-url=http://replacement.test'],
        ));

        $this->assertNotSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('db-apply stopped after it may have changed the target database', $result['output']);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));
    }

    private function newClient(string $remoteUrl): \ImportClient
    {
        $client = new \ImportClient(
            $remoteUrl,
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'http_code' => 200,
            'data' => ['ok' => true],
        ]);
        $client->save_state();
        return $client;
    }

    private function writeReplacementDumpIntent(\ImportClient $client): void
    {
        file_put_contents(
            $client->pull_state_directory . '/database-dump.intent',
            json_encode(['create_table_query' => true], JSON_THROW_ON_ERROR),
        );
    }

    private function writeReplacementDumpRecord(\ImportClient $client): void
    {
        file_put_contents(
            $client->pull_state_directory . '/database-dump.json',
            json_encode([
                'sha256' => hash_file('sha256', $this->root . '/state/db.sql'),
                'create_table_query' => true,
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{exit:int,stdout:string,stderr:string,output:string} */
    private function runCli(array $arguments): array
    {
        [$process, $pipes] = $this->startCli($arguments);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [
            'exit' => $exit,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'output' => (string) $stdout . (string) $stderr,
        ];
    }

    /** @return array{0:resource,1:array<int,resource>} */
    private function startCli(array $arguments, bool $discardOutput = false): array
    {
        $descriptors = $discardOutput
            ? [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']]
            : [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client'], $arguments),
            $descriptors,
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @return array{0:string,1:\ImportClient} */
    private function pullDatabaseDump(string $sql): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('The local streaming endpoint requires pcntl.');
        }

        [$remoteUrl, $serverPid] = $this->startDatabasePullServer($sql);
        $client = $this->newClient($remoteUrl);
        try {
            $result = $this->runCli([
                'db-pull',
                $remoteUrl,
                '--state-dir=' . $this->root . '/state',
                '--fs-root=' . $this->root . '/files',
                '--progress=jsonl',
            ]);
        } finally {
            pcntl_waitpid($serverPid, $serverStatus);
        }

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
        return [$remoteUrl, $client];
    }

    private function interruptDatabaseApply(
        \ImportClient $client,
        string $remoteUrl,
        string $sqlitePath
    ): void {
        [$process, $pipes] = $this->startCli(
            $this->databaseApplyArguments($remoteUrl, $sqlitePath),
        );
        $pullStatePath = $client->pull_state_directory . '/state.json';
        $this->waitUntil(function () use ($process, $pullStatePath): bool {
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                return false;
            }
            $state = json_decode( (string) @file_get_contents($pullStatePath), true );
            return (int) ( $state['apply']['statements_executed'] ?? 0 ) >= 100;
        }, 'db-apply completed before the test could request an orderly stop.');

        $status = proc_get_status($process);
        $this->assertTrue(posix_kill($status['pid'], SIGSTOP));
        $this->assertTrue(posix_kill($status['pid'], SIGTERM));
        $this->assertTrue(posix_kill($status['pid'], SIGCONT));
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(2, proc_close($process), (string) $stdout . (string) $stderr);
        $this->assertSame(1, $this->restartAttempts($sqlitePath));
    }

    /** @return string[] */
    private function databaseApplyArguments(string $remoteUrl, string $sqlitePath): array
    {
        return [
            'db-apply',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--target-engine=sqlite',
            '--target-sqlite-path=' . $sqlitePath,
            '--target-db=wordpress',
            '--progress=jsonl',
        ];
    }

    private function createRestartProbe(string $sqlitePath): void
    {
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        $pdo->exec('CREATE TABLE restart_probe (id INTEGER PRIMARY KEY, attempts INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO restart_probe (id, attempts) VALUES (1, 0)');
    }

    private function restartAttempts(string $sqlitePath): int
    {
        $pdo = new \PDO('sqlite:' . $sqlitePath);
        return (int) $pdo->query('SELECT attempts FROM restart_probe WHERE id = 1')->fetchColumn();
    }

    private function restartProbeDump(int $rows): string
    {
        $sql = "UPDATE `restart_probe` SET `attempts` = `attempts` + 1 WHERE `id` = 1;\n"
            . "DROP TABLE IF EXISTS `items`;\n"
            . "CREATE TABLE `items` (`id` INTEGER PRIMARY KEY, `value` TEXT);\n";
        for ($id = 1; $id <= $rows; $id++) {
            $sql .= "INSERT INTO `items` (`id`, `value`) VALUES ({$id}, 'value-{$id}');\n";
        }
        return $sql;
    }

    private function requireOrderlyStopSignals(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGTERM') || !defined('SIGSTOP') || !defined('SIGCONT')) {
            $this->markTestSkipped('The orderly interruption test requires posix signals.');
        }
    }

    /** @return array{0:string,1:int} */
    private function startDatabasePullServer(string $sql): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $indexConnection = stream_socket_accept($listener, 10);
            if (!is_resource($indexConnection)) {
                exit(2);
            }
            $indexRequest = $this->readHttpRequest($indexConnection);
            parse_str( (string) parse_url($indexRequest['target'], PHP_URL_QUERY), $indexQuery );
            if ( ( $indexQuery['endpoint'] ?? null ) !== 'db_index' ) {
                exit(3);
            }
            fwrite($indexConnection, $this->multipartResponse([
                [
                    'headers' => [
                        'X-Chunk-Type' => 'completion',
                        'X-Status' => 'complete',
                    ],
                    'body' => '',
                ],
            ]));
            fclose($indexConnection);

            $sqlConnection = stream_socket_accept($listener, 10);
            if (!is_resource($sqlConnection)) {
                exit(4);
            }
            $sqlRequest = $this->readHttpRequest($sqlConnection);
            parse_str( (string) parse_url($sqlRequest['target'], PHP_URL_QUERY), $sqlQuery );
            if ( ( $sqlQuery['endpoint'] ?? null ) !== 'sql_chunk' ) {
                exit(5);
            }
            if ( ( $sqlQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(6);
            }
            fwrite($sqlConnection, $this->multipartResponse([
                [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('complete-dump'),
                    ],
                    'body' => $sql,
                ],
                [
                    'headers' => [
                        'X-Chunk-Type' => 'completion',
                        'X-Status' => 'complete',
                    ],
                    'body' => '',
                ],
            ]));
            fclose($sqlConnection);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{0:string,1:int} */
    private function startOneResponseServer(callable $response, int $acceptTimeout = 10): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($listener, $acceptTimeout);
            if (!is_resource($connection)) {
                exit(2);
            }
            $request = $this->readHttpRequest($connection);
            parse_str( (string) parse_url($request['target'], PHP_URL_QUERY), $query );
            fwrite($connection, $response($query));
            fclose($connection);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{0:string,1:int} */
    private function startTwoResponseSqlServer(string $readyPath, string $releasePath): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $first = stream_socket_accept($listener, 10);
            if (!is_resource($first)) {
                exit(2);
            }
            $firstRequest = $this->readHttpRequest($first);
            parse_str( (string) parse_url($firstRequest['target'], PHP_URL_QUERY), $firstQuery );
            if ( ( $firstQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(3);
            }
            fwrite($first, $this->multipartResponseHeaders('restart-boundary'));
            for ($part = 1; $part <= 50; $part++) {
                fwrite($first, $this->multipartPart('restart-boundary', [
                    'X-Chunk-Type' => 'sql',
                    'X-Query-Complete' => '1',
                    'X-Cursor' => base64_encode('cursor-' . $part),
                ], sprintf("SELECT %d;\n", $part)));
                fflush($first);
            }
            file_put_contents($readyPath, '');
            while (!is_file($releasePath)) {
                usleep(10000);
            }
            fclose($first);

            $second = stream_socket_accept($listener, 10);
            if (!is_resource($second)) {
                exit(4);
            }
            $request = $this->readHttpRequest($second);
            parse_str( (string) parse_url($request['target'], PHP_URL_QUERY), $query );
            if (base64_decode($query['cursor'] ?? '', true) !== 'cursor-50') {
                exit(5);
            }
            if ( ( $query['create_table_query'] ?? null ) !== '1' ) {
                exit(6);
            }
            $parts = [];
            for ($part = 51; $part <= 60; $part++) {
                $parts[] = [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('cursor-' . $part),
                    ],
                    'body' => sprintf("SELECT %d;\n", $part),
                ];
            }
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'completion',
                    'X-Status' => 'complete',
                ],
                'body' => '',
            ];
            fwrite($second, $this->multipartResponse($parts, 'restart-boundary'));
            fclose($second);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{0:string,1:int} */
    private function startThreeResponseSqlServer(
        string $firstReadyPath,
        string $firstReleasePath,
        string $secondReadyPath,
        string $secondReleasePath
    ): array {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $first = stream_socket_accept($listener, 10);
            if (!is_resource($first)) {
                exit(2);
            }
            $firstRequest = $this->readHttpRequest($first);
            parse_str( (string) parse_url($firstRequest['target'], PHP_URL_QUERY), $firstQuery );
            if (isset($firstQuery['cursor'])) {
                exit(3);
            }
            if ( ( $firstQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(4);
            }
            fwrite($first, $this->multipartResponseHeaders('cursorless-boundary'));
            for ($part = 1; $part <= 10; $part++) {
                fwrite($first, $this->multipartPart('cursorless-boundary', [
                    'X-Chunk-Type' => 'sql',
                    'X-Query-Complete' => '1',
                    'X-Cursor' => base64_encode('unconfirmed-' . $part),
                ], sprintf("SELECT %d;\n", $part)));
                fflush($first);
            }
            file_put_contents($firstReadyPath, '');
            while (!is_file($firstReleasePath)) {
                usleep(10000);
            }
            fclose($first);

            $second = stream_socket_accept($listener, 10);
            if (!is_resource($second)) {
                exit(5);
            }
            $secondRequest = $this->readHttpRequest($second);
            parse_str( (string) parse_url($secondRequest['target'], PHP_URL_QUERY), $secondQuery );
            if (isset($secondQuery['cursor'])) {
                exit(6);
            }
            if ( ( $secondQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(7);
            }
            fwrite($second, $this->multipartResponseHeaders('cursorless-boundary'));
            for ($part = 1; $part <= 50; $part++) {
                fwrite($second, $this->multipartPart('cursorless-boundary', [
                    'X-Chunk-Type' => 'sql',
                    'X-Query-Complete' => '1',
                    'X-Cursor' => base64_encode('cursor-' . $part),
                ], sprintf("SELECT %d;\n", $part)));
                fflush($second);
            }
            file_put_contents($secondReadyPath, '');
            while (!is_file($secondReleasePath)) {
                usleep(10000);
            }
            fclose($second);

            $third = stream_socket_accept($listener, 10);
            if (!is_resource($third)) {
                exit(8);
            }
            $thirdRequest = $this->readHttpRequest($third);
            parse_str( (string) parse_url($thirdRequest['target'], PHP_URL_QUERY), $thirdQuery );
            if (base64_decode($thirdQuery['cursor'] ?? '', true) !== 'cursor-50') {
                exit(9);
            }
            if ( ( $thirdQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(10);
            }
            $parts = [];
            for ($part = 51; $part <= 60; $part++) {
                $parts[] = [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('cursor-' . $part),
                    ],
                    'body' => sprintf("SELECT %d;\n", $part),
                ];
            }
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'completion',
                    'X-Status' => 'complete',
                ],
                'body' => '',
            ];
            fwrite($third, $this->multipartResponse($parts, 'cursorless-boundary'));
            fclose($third);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{target:string} */
    private function readHttpRequest($connection): array
    {
        stream_set_timeout($connection, 10);
        $request = '';
        while (strpos($request, "\r\n\r\n") === false) {
            $chunk = fread($connection, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }
        $requestLine = strtok($request, "\r\n");
        $parts = is_string($requestLine) ? explode(' ', $requestLine) : [];
        return ['target' => $parts[1] ?? ''];
    }

    private function multipartResponse(array $parts, string $boundary = 'database-restart'): string
    {
        $body = '';
        foreach ($parts as $part) {
            $body .= $this->multipartPart($boundary, $part['headers'], $part['body']);
        }
        $body .= "--{$boundary}--\r\n";
        return $this->multipartResponseHeaders($boundary, strlen($body)) . $body;
    }

    private function multipartResponseHeaders(string $boundary, ?int $contentLength = null): string
    {
        $headers = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: multipart/mixed; boundary={$boundary}\r\n"
            . "Connection: close\r\n";
        if ($contentLength !== null) {
            $headers .= "Content-Length: {$contentLength}\r\n";
        }
        return $headers . "\r\n";
    }

    private function multipartPart(string $boundary, array $headers, string $body): string
    {
        $part = "--{$boundary}\r\nContent-Length: " . strlen($body) . "\r\n";
        foreach ($headers as $name => $value) {
            $part .= "{$name}: {$value}\r\n";
        }
        return $part . "\r\n{$body}\r\n";
    }

    private function sqlStatements(int $first, int $last): string
    {
        $sql = '';
        for ($statement = $first; $statement <= $last; $statement++) {
            $sql .= sprintf("SELECT %d;\n", $statement);
        }
        return $sql;
    }

    private function writeItemDump(int $rows): void
    {
        $sql = "DROP TABLE IF EXISTS `items`;\n"
            . "CREATE TABLE `items` (`id` INTEGER PRIMARY KEY, `value` TEXT);\n";
        for ($id = 1; $id <= $rows; $id++) {
            $sql .= "INSERT INTO `items` (`id`, `value`) VALUES ({$id}, 'value-{$id}');\n";
        }
        file_put_contents($this->root . '/state/db.sql', $sql);
    }

    private function waitUntil(callable $condition, string $failure): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            if ($condition()) {
                return;
            }
            usleep(10000);
        }
        $this->fail($failure);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
