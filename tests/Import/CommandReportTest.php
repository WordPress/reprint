<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class CommandReportTest extends TestCase {
    private string $root;
    private string $remote_url;
    /** @var resource */
    private $server_process;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-command-report-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->remote_url = 'http://' . $address . '/?reprint-api&directory[]=' . rawurlencode($this->root . '/remote');
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/command-report-router.php'],
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

    public function testPreflightKeepsItsDataAndAppendsOneReportOnlyWhenRequested(): void
    {
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 200,
            'body' => '{"ok":true}',
        ]));
        $plain = $this->run_command('preflight');
        $this->assertSame(0, $plain['exit_code'], $plain['stderr']);
        $plain_data = json_decode($plain['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($plain_data['data']['ok']);

        $reported = $this->run_command('preflight', ['--report']);
        $report = $this->read_report($reported);
        $this->assertSame('preflight', $report['command']);
        $this->assertSame('complete', $report['status']);
        $this->assertNull($report['error']);
        $this->assertNull($report['error_code']);
        $records = explode("\n", trim($reported['stdout']));
        $this->assertTrue(json_decode($records[0], true)['data']['ok']);
    }

    public function testCompactAddsAReportOnlyWhenRequestedWithoutCreatingAProgressLog(): void
    {
        $plain = $this->run_command('preflight', ['--progress=compact']);
        $this->assertSame(0, $plain['exit_code'], $plain['stderr']);
        $this->assertTrue(json_decode($plain['stdout'], true, 512, JSON_THROW_ON_ERROR)['data']['ok']);
        $result = $this->run_command('preflight', ['--progress=compact', '--report']);
        $this->read_report($result);
        $this->assertCount(2, explode("\n", trim($result['stdout'])));
        $this->assertFileDoesNotExist($this->root . '/state/progress.jsonl');
    }

    public function testPipelineFailureProducesOnlyTheOuterReport(): void
    {
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 401,
            'body' => '{"error":"Invalid signature"}',
        ]));
        $result = $this->run_command('pull-files', ['--report']);
        $report = $this->read_report($result);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('pull-files', $report['command']);
        $this->assertSame('preflight', $report['failed_stage']);
        $this->assertSame('error', $report['status']);
        $this->assertSame('AUTH_FAILED', $report['error_code']);
        $this->assertStringContainsString('Invalid signature', $report['error']);
    }

    public function testPreflightFailureAndSavedAssertionHaveTheSameReportError(): void
    {
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 520,
            'body' => '<html>Unknown error</html>',
        ]));
        $preflight = $this->read_report($this->run_command('preflight', ['--report']));
        $assertion = $this->read_report($this->run_command('preflight-assert', ['--report']));
        $this->assertSame('SERVER_ERROR', $preflight['error_code']);
        $this->assertSame($preflight['error'], $assertion['error']);
        $this->assertSame($preflight['error_code'], $assertion['error_code']);
        $this->assertNotEmpty($assertion['checks']);
        $this->assertSame('SERVER_RESPONDED', $assertion['checks'][0]['code']);
    }

    public function testRealFileDownloadReportsCompleteAndAbortIsNotSuccess(): void
    {
        file_put_contents($this->root . '/remote/example.txt', 'download me');
        $this->run_command('preflight');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $this->run_command('files-pull', ['--report']);
            $report = $this->read_report($result);
            $this->assertSame($result['exit_code'] === 2 ? 'partial' : 'complete', $report['status'], $result['stdout'] . $result['stderr']);
            if ($result['exit_code'] !== 2) {
                break;
            }
        }
        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $downloaded_file = $this->root . '/files' . realpath($this->root . '/remote') . '/example.txt';
        $this->assertFileExists($downloaded_file, $result['stdout']);
        $this->assertSame('download me', file_get_contents($downloaded_file));
        $aborted = $this->read_report($this->run_command('files-pull', ['--report', '--abort']));
        $this->assertSame('aborted', $aborted['status']);
    }

    /** @dataProvider retryable_commands */
    public function testRetryableReportKeepsExitCodeAndStalledRequestCount(string $command): void
    {
        $preflight = $this->run_command('preflight');
        $preflight_data = json_decode($preflight['stdout'], true)['data'];
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 520,
            'body' => 'Upstream unavailable',
            'preflight_body' => json_encode($preflight_data),
        ]));
        // Each invocation exhausts its own three-request retry allowance.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $report = $this->read_report($this->run_command($command, ['--report']));
            $this->assertSame(3, $report['exit_code']);
            $this->assertSame('error', $report['status']);
            $this->assertSame('SERVER_ERROR', $report['error_code']);
            $this->assertSame(520, $report['http_code']);
            $this->assertSame(3, $report['consecutive_failures_without_progress']);
            $this->assertSame($command === 'pull-files' ? 'files-pull' : null, $report['failed_stage']);
            $this->assertArrayNotHasKey('retry_after_seconds', $report);
        }
    }

    public static function retryable_commands(): array
    {
        return [['files-pull'], ['pull-files']];
    }

    public function testPartialReportIsNotReportedAsComplete(): void
    {
        // Check the report's exit-code mapping directly. Low-level pulls now
        // continue through healthy phase boundaries in the same process.
        $script = 'require $argv[1]; reprint_write_command_report("db-pull", 2, ["report" => true], null); exit(2);';
        exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' '
                . escapeshellarg(__DIR__ . '/../../packages/reprint-client/src/import.php'),
            $output,
            $exit_code
        );
        $report = $this->read_report(['exit_code' => $exit_code, 'stdout' => implode("\n", $output), 'stderr' => '']);
        $this->assertSame(2, $report['exit_code']);
        $this->assertSame('partial', $report['status']);
        $this->assertNull($report['error']);
    }

    public function testSqlStdoutRemainsReservedForSqlOnFailure(): void
    {
        $this->run_command('preflight');
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 401,
            'body' => '{"error":"Invalid signature"}',
        ]));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $this->run_command('db-pull', ['--report', '--sql-output=stdout']);
            if ($result['exit_code'] !== 2) {
                break;
            }
        }
        $this->assertSame('', $result['stdout']);
        $report = $this->read_report(array_replace($result, ['stdout' => $result['stderr']]));
        $this->assertSame('AUTH_FAILED', $report['error_code'], $result['stderr']);
    }

    public function testLockFailureStillProducesAReport(): void
    {
        mkdir($this->root . '/state');
        $lock = new \ReprintProcessLock($this->root . '/state');
        try {
            $result = $this->run_command('preflight', ['--report']);
            $report = $this->read_report($result);
            $this->assertSame(1, $report['exit_code']);
            $this->assertNotEmpty($report['error']);
        } finally {
            $lock->close();
        }
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
        return [
            'exit_code' => proc_close($process),
            'stdout' => file_get_contents($this->root . '/stdout'),
            'stderr' => file_get_contents($this->root . '/stderr'),
        ];
    }

    /** @param array $result Process output from run_command(). */
    private function read_report(array $result): array
    {
        $reports = [];
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ( $record['type'] ?? null ) === 'reprint_report') {
                $reports[] = $record;
            }
        }
        $this->assertCount(1, $reports, $result['stdout'] . $result['stderr']);
        $this->assertSame(1, $reports[0]['schema_version']);
        $this->assertSame($result['exit_code'], $reports[0]['exit_code']);
        $lines = explode("\n", trim($result['stdout']));
        $this->assertSame($reports[0], json_decode(end($lines), true));
        return $reports[0];
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
