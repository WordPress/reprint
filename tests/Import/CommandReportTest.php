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

    /**
     * @dataProvider progress_modes
     * @param string[] $options CLI progress options.
     */
    public function testPreflightKeepsItsDataAndReportsOnlyInJsonOutput(array $options, bool $stdout_is_tty, bool $expects_report): void
    {
        $result = $this->run_command('preflight', $options, $stdout_is_tty);
        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $records = preg_split('/\R/', trim($result['stdout']));
        $this->assertTrue(json_decode($records[0], true, 512, JSON_THROW_ON_ERROR)['data']['ok']);
        $this->assertCount($expects_report ? 2 : 1, $records);
        if ($expects_report) {
            $report = $this->read_report($result);
            $this->assertSame('preflight', $report['command']);
            $this->assertSame('complete', $report['status']);
            $this->assertNull($report['error']);
            $this->assertNull($report['error_code']);
            $this->assertArrayNotHasKey('http_code', $report);
        } else {
            $this->assertStringNotContainsString('reprint_report', $result['stdout'] . $result['stderr']);
        }
        $this->assertFileDoesNotExist($this->root . '/state/progress.jsonl');
    }

    public static function progress_modes(): array
    {
        return [
            'default pipe' => [[], false, true],
            'auto pipe' => [['--progress=auto'], false, true],
            'jsonl pipe' => [['--progress=jsonl'], false, true],
            'compact pipe' => [['--progress=compact'], false, true],
            'forced tty pipe' => [['--progress=tty'], false, false],
            'default terminal' => [[], true, false],
            'auto terminal' => [['--progress=auto'], true, false],
            'jsonl terminal' => [['--progress=jsonl'], true, true],
            'compact terminal' => [['--progress=compact'], true, true],
        ];
    }

    /** @dataProvider report_modes */
    public function testPipelineFailureProducesOnlyTheOuterReport(string $mode): void
    {
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 401,
            'body' => '{"error":"Invalid signature"}',
        ]));
        $result = $this->run_command('pull-files', ['--progress=' . $mode]);
        $report = $this->read_report($result);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('pull-files', $report['command']);
        $this->assertSame('preflight', $report['failed_stage']);
        $this->assertSame('error', $report['status']);
        $this->assertSame('AUTH_FAILED', $report['error_code']);
        $this->assertStringContainsString('Invalid signature', $report['error']);
    }

    public static function report_modes(): array
    {
        return [['jsonl'], ['compact']];
    }

    /** @dataProvider preflight_outcomes */
    public function testPreflightAndSavedAssertionKeepChecksOutOfFinalReport(string $mode, bool $fails): void
    {
        if ($fails) {
            file_put_contents($this->root . '/response.json', json_encode([
                'http_code' => 520,
                'body' => '<html>Unknown error</html>',
            ]));
        }
        $preflight = $this->read_report($this->run_command('preflight', ['--progress=' . $mode]));
        $result = $this->run_command('preflight-assert', ['--progress=' . $mode]);
        $assertion = $this->read_report($result);
        $this->assertSame($fails ? 'error' : 'complete', $assertion['status']);
        $this->assertSame($fails ? 'SERVER_ERROR' : null, $preflight['error_code']);
        $this->assertSame($preflight['error'], $assertion['error']);
        $this->assertSame($preflight['error_code'], $assertion['error_code']);
        $records = array_map(static function (string $line): array {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, explode("\n", trim($result['stdout'])));
        $this->assertCount(2, $records);
        $this->assertSame('preflight_assertion', $records[0]['type']);
        $this->assertNotEmpty($records[0]['checks']);
        foreach ($records[0]['checks'] as $check) {
            $this->assertEqualsCanonicalizing(['label', 'pass', 'detail'], array_keys($check));
        }
    }

    public static function preflight_outcomes(): array
    {
        return [
            'jsonl success' => ['jsonl', false],
            'compact success' => ['compact', false],
            'jsonl error' => ['jsonl', true],
            'compact error' => ['compact', true],
        ];
    }

    public function testRealFileDownloadReportsCompleteAndAbortIsNotSuccess(): void
    {
        file_put_contents($this->root . '/remote/example.txt', 'download me');
        $this->run_command('preflight');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $this->run_command('files-pull');
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
        $aborted = $this->read_report($this->run_command('files-pull', ['--abort']));
        $this->assertSame('aborted', $aborted['status']);
    }

    /** @dataProvider retryable_commands */
    public function testRetryableReportKeepsExitCodeAndStalledRequestCount(string $command): void
    {
        $preflight = $this->run_command('preflight');
        $preflight_data = json_decode(explode("\n", $preflight['stdout'])[0], true)['data'];
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 520,
            'body' => 'Upstream unavailable',
            'preflight_body' => json_encode($preflight_data),
        ]));
        // Each invocation exhausts its own three-request retry allowance.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $report = $this->read_report($this->run_command($command));
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
        $script = 'require $argv[1]; reprint_write_command_report("db-pull", 2, ["progress" => "jsonl"], null); exit(2);';
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

    /** @dataProvider sql_progress_modes */
    public function testSqlStdoutRemainsReservedForSqlOnFailure(string $mode, bool $stdout_is_tty, bool $stderr_is_tty, bool $expects_report): void
    {
        $this->run_command('preflight');
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 401,
            'body' => '{"error":"Invalid signature"}',
        ]));
        // The second invocation must honor the SQL stream saved by the first.
        foreach ([['--sql-output=stdout'], []] as $options) {
            $result = $this->run_command('db-pull', array_merge(['--progress=' . $mode], $options), $stdout_is_tty, $stderr_is_tty);
            $this->assertNotSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
            $this->assertSame('', $result['stdout']);
            if ($expects_report) {
                $report = $this->read_report(array_replace($result, ['stdout' => $result['stderr']]));
                $this->assertSame('AUTH_FAILED', $report['error_code'], $result['stderr']);
            } else {
                $this->assertStringNotContainsString('reprint_report', $result['stderr']);
            }
        }
    }

    public static function sql_progress_modes(): array
    {
        return [
            'auto uses stderr pipe' => ['auto', true, false, true],
            'auto uses stderr terminal' => ['auto', false, true, false],
            'jsonl overrides stderr terminal' => ['jsonl', false, true, true],
            'compact overrides stderr terminal' => ['compact', false, true, true],
        ];
    }

    public function testReportIsNotACliOption(): void
    {
        $result = $this->run_command('preflight', ['--report']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('Unknown option: --report', $result['stderr']);
        $this->assertStringNotContainsString('reprint_report', $result['stdout']);
    }

    public function testLockFailureStillProducesAReport(): void
    {
        mkdir($this->root . '/state');
        $lock = new \ReprintProcessLock($this->root . '/state');
        try {
            $result = $this->run_command('preflight');
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
    private function run_command(string $command, array $options = [], bool $stdout_is_tty = false, bool $stderr_is_tty = false): array
    {
        if (( $stdout_is_tty || $stderr_is_tty ) && ( !function_exists('posix_isatty') || PHP_OS_FAMILY === 'Windows' )) {
            $this->markTestSkipped('This test requires POSIX pseudoterminal support.');
        }
        $process = proc_open(
            array_merge([
                PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                $command, $this->remote_url, '--secret=preflight-test-secret',
                '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files',
            ], $options),
            [
                0 => ['pipe', 'r'],
                1 => $stdout_is_tty ? ['pty'] : ['file', $this->root . '/stdout', 'w'],
                2 => $stderr_is_tty ? ['pty'] : ['file', $this->root . '/stderr', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = $stdout_is_tty ? stream_get_contents($pipes[1]) : null;
        $stderr = $stderr_is_tty ? stream_get_contents($pipes[2]) : null;
        if ($stdout_is_tty) {
            fclose($pipes[1]);
        }
        if ($stderr_is_tty) {
            fclose($pipes[2]);
        }
        return [
            'exit_code' => proc_close($process),
            'stdout' => $stdout ?? file_get_contents($this->root . '/stdout'),
            'stderr' => $stderr ?? file_get_contents($this->root . '/stderr'),
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
        $this->assertArrayNotHasKey('checks', $reports[0]);
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
