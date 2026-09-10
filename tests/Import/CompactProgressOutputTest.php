<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/FailingProgressLogStream.php';

final class CompactProgressOutputTest extends TestCase {
    private string $root;
    private string $remote_url;
    /** @var resource */
    private $server_process;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-compact-progress-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->remote_url = 'http://' . $address . '/?reprint-api&directory[]=' . rawurlencode($this->root . '/remote');
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/compact-progress-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($this->server_process);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        proc_terminate($this->server_process);
        proc_close($this->server_process);
        $this->remove_directory($this->root);
    }

    public function testPreflightKeepsItsDataAndAppendsTheFullLogAcrossInvocations(): void
    {
        $plain = $this->run_command('preflight');
        $compact = $this->run_command('preflight', ['--progress=compact']);
        $this->assertSame(0, $compact['exit_code'], $compact['stderr']);
        $plain_record = json_decode($plain['stdout'], true);
        $compact_record = json_decode($compact['stdout'], true);
        $this->assertSame(array_keys($plain_record), array_keys($compact_record));
        $this->assertSame($plain_record['data'], $compact_record['data']);
        $this->assertSame([json_decode($compact['stdout'], true)], $this->read_progress_log());
        $this->assertSame(0600, fileperms($this->root . '/state/progress.jsonl') & 0777);
        $this->run_command('preflight', ['--progress=compact']);
        $this->assertCount(2, $this->read_progress_log());
    }

    public function testNextInvocationSeparatesAnInterruptedLogRecord(): void
    {
        $this->run_command('preflight', ['--progress=compact']);
        file_put_contents($this->root . '/state/progress.jsonl', '{"unfinished":', FILE_APPEND);
        $result = $this->run_command('preflight', ['--progress=compact']);
        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $lines = explode("\n", trim(file_get_contents($this->root . '/state/progress.jsonl')));
        $this->assertSame('{"unfinished":', $lines[1]);
        $this->assertTrue(json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR)['data']['ok']);
    }

    public function testCompactKeepsFilePullStagesAndLeavesAllSkipsInTheLog(): void
    {
        $local_directory = $this->root . '/files' . realpath($this->root . '/remote');
        mkdir($local_directory, 0755, true);
        for ($index = 0; $index < 25; $index++) {
            file_put_contents($this->root . '/remote/file-' . $index, 'remote');
            file_put_contents($local_directory . '/file-' . $index, 'local');
        }
        $this->run_command('preflight');
        $result = $this->run_command('files-pull', ['--progress=compact', '--on-fs-root-nonempty=preserve-local']);
        $this->assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
        $skips = array_filter($this->read_progress_log(), static function (array $record): bool {
            return ( $record['type'] ?? null ) === 'skip';
        });
        $this->assertGreaterThanOrEqual(25, count($skips));
        $this->assertStringNotContainsString('"type":"skip"', $result['stdout']);
        $this->assertStringNotContainsString('"type":"file_progress"', $result['stdout']);
        $this->assertStringNotContainsString('reprint_report', $result['stdout']);
        $records = array_map(static function (string $line): array {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, explode("\n", trim($result['stdout'])));
        $this->assertSame(['starting', 'stage', 'stage', 'complete'], array_column($records, 'event'));
        $this->assertSame(['diff', 'fetch'], array_column($records, 'stage'));
        $this->assertSame('local', file_get_contents($local_directory . '/file-0'));
    }

    public function testFullJsonlStillPrintsIndividualSkipsWithoutCreatingALog(): void
    {
        $local_directory = $this->root . '/files' . realpath($this->root . '/remote');
        mkdir($local_directory, 0755, true);
        file_put_contents($this->root . '/remote/keep.txt', 'remote');
        file_put_contents($local_directory . '/keep.txt', 'local');
        $this->run_command('preflight');
        $result = $this->run_command('files-pull', ['--on-fs-root-nonempty=preserve-local']);
        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertStringContainsString('"type":"skip"', $result['stdout']);
        $this->assertFileDoesNotExist($this->root . '/state/progress.jsonl');
    }

    public function testSqlStdoutRemainsReservedForSql(): void
    {
        $this->run_command('preflight');
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 401, 'body' => '{"error":"Invalid signature"}',
        ]));
        $result = $this->run_command('db-pull', ['--progress=compact', '--sql-output=stdout']);
        $this->assertNotSame(0, $result['exit_code']);
        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('"event":"starting"', $result['stderr']);
        $this->assertNotEmpty($this->read_progress_log());
    }

    public function testStageChangesAndWarningsBypassTheCounterThrottle(): void
    {
        $client = new \ImportClient($this->remote_url, $this->root . '/state', $this->root . '/files');
        $command = $client->get_state()->active_resumable_command;
        $command->command_name = 'db-pull';
        $command->completion_state = 'in_progress';
        $command->current_stage = 'db-index';
        $stream = fopen('php://memory', 'w+b');
        ( new \ReflectionProperty($client, 'progress_fd') )->setValue($client, $stream);
        ( new \ReflectionProperty($client, 'progress_output_mode') )->setValue($client, 'compact');
        ( new \ReflectionProperty($client, 'progress_log_handle') )->setValue($client, fopen($this->root . '/state/progress.jsonl', 'ab'));
        $start = ['status' => 'starting', 'phase' => 'db-index', 'message' => 'Downloading table metadata'];
        $client->output_progress($start);
        $client->output_progress($start);
        $client->output_progress(['type' => 'lifecycle', 'command' => 'db-pull', 'event' => 'resuming', 'stage' => 'db-index'], true);
        $client->output_progress(['phase' => 'db-index', 'status' => 'complete', 'bytes_processed' => 100], true);
        $client->output_progress(['debug' => 'Waiting for server response'], true);
        $command->current_stage = 'sql';
        $client->output_progress(['status' => 'starting', 'phase' => 'sql', 'message' => 'Downloading SQL dump']);
        $client->output_progress(['phase' => 'sql', 'message' => '100 bytes', 'progress' => ['bytes' => ['done' => 100]]], true);
        $warnings = [
            ['type' => 'warning', 'reason' => 'missing_primary_key', 'message' => 'Skipped table without a primary key'],
            ['type' => 'symlink_error', 'error' => 'Could not create symlink'],
            ['type' => 'error', 'phase' => 'files', 'error_message' => 'Remote file changed'],
            ['type' => 'volatile_files', 'count' => 1, 'message' => 'One file needs re-syncing'],
        ];
        foreach ($warnings as $warning) {
            $client->output_progress($warning);
        }
        $complete = ['status' => 'complete', 'phase' => 'db-apply', 'message' => 'db-apply complete (3 statements executed)'];
        $client->output_progress($complete);
        rewind($stream);
        $records = array_map(static function (string $line): array {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, explode("\n", trim(stream_get_contents($stream))));
        fclose($stream);
        $this->assertCount(7, $records);
        $this->assertSame(['db-index', 'sql'], array_column(array_slice($records, 0, 2), 'stage'));
        $this->assertSame($warnings, array_slice($records, 2, 4));
        $this->assertSame($complete, $records[6]);
        $this->assertCount(12, $this->read_progress_log());
    }

    public function testPushProgressPrintsEachStageOnceWithoutItsCounters(): void
    {
        $client = new \ImportClient($this->remote_url, $this->root . '/state', $this->root . '/files');
        $stream = fopen('php://memory', 'w+b');
        ( new \ReflectionProperty($client, 'progress_fd') )->setValue($client, $stream);
        ( new \ReflectionProperty($client, 'progress_output_mode') )->setValue($client, 'compact');
        foreach (['planning', 'planning', 'pushing_paths', 'pushing_paths', 'committing'] as $phase) {
            $client->output_progress([
                'type' => 'push_progress', 'command' => 'files-push', 'status' => 'in_progress',
                'phase' => $phase, 'message' => 'Working', 'progress' => ['bytes' => ['done' => 100]],
            ]);
        }
        rewind($stream);
        $records = array_map(static function (string $line): array {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, explode("\n", trim(stream_get_contents($stream))));
        fclose($stream);
        $this->assertSame(['planning', 'pushing_paths', 'committing'], array_column($records, 'stage'));
        foreach ($records as $record) {
            $this->assertSame('stage', $record['event']);
            $this->assertArrayNotHasKey('progress', $record);
        }
    }

    public function testAZeroByteLogWriteThrowsInsteadOfDiscardingTheRecord(): void
    {
        stream_wrapper_register('reprint-log-full', FailingProgressLogStream::class);
        try {
            $client = new \ImportClient($this->remote_url, $this->root . '/state', $this->root . '/files');
            ( new \ReflectionProperty($client, 'progress_output_mode') )->setValue($client, 'compact');
            ( new \ReflectionProperty($client, 'progress_log_handle') )->setValue($client, fopen('reprint-log-full://log', 'w'));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not append the full progress record');
            $client->output_progress(['type' => 'lifecycle', 'event' => 'starting'], true);
        } finally {
            stream_wrapper_unregister('reprint-log-full');
        }
    }

    /** @return array[] Decoded full progress records in append order. */
    private function read_progress_log(): array
    {
        return array_map(static function (string $line): array {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, explode("\n", trim(file_get_contents($this->root . '/state/progress.jsonl'))));
    }

    /**
     * @param array $options Extra CLI arguments.
     * @return array { Captured process result.
     *     @type int    $exit_code Process exit code.
     *     @type string $stdout    Standard output.
     *     @type string $stderr    Standard error.
     * }
     */
    private function run_command(string $command, array $options = []): array
    {
        $process = $this->start_command($command, $options);
        return [
            'exit_code' => proc_close($process),
            'stdout' => file_get_contents($this->root . '/stdout'),
            'stderr' => file_get_contents($this->root . '/stderr'),
        ];
    }

    /**
     * @param string[] $options Extra CLI arguments.
     * @return resource Running CLI process.
     */
    private function start_command(string $command, array $options)
    {
        $process = proc_open(
            array_merge([
                PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                $command, $this->remote_url, '--secret=preflight-test-secret',
                '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files',
                '--progress=jsonl',
            ], $options),
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/stdout', 'w'], 2 => ['file', $this->root . '/stderr', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return $process;
    }

    private function remove_directory(string $directory): void
    {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->remove_directory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
