<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

/** End-to-end CLI runs against the real PHP endpoint behind a failing proxy. */
final class RetryLaterExitCodeTest extends TestCase {
    private string $root;
    private string $remote_reprint_api_url;
    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-retry-later-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $this->root = realpath($this->root);
        file_put_contents($this->root . '/remote/example.txt', 'Resume this file after the outage.');
        file_put_contents($this->root . '/proxy-status', '200');
        file_put_contents($this->root . '/proxy-endpoint', 'file_index');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->remote_reprint_api_url = 'http://' . $address;
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/retry-later-router.php'],
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
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertNotFalse($connection, file_get_contents($this->root . '/server.log'));
        $result = $this->run_command('preflight');
        $this->assertSame(0, $result['exit_code'], $result['output']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        $this->remove_directory($this->root);
    }

    /** @dataProvider temporary_failures */
    public function testTemporaryOutageExitsThreeThenResumes(string $command, int $http_status, string $endpoint): void
    {
        file_put_contents($this->root . '/proxy-status', (string) $http_status);
        file_put_contents($this->root . '/proxy-endpoint', $endpoint);
        $result = $this->run_command($command);
        $this->assertSame(3, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('3 consecutive times', $result['output']);
        $this->assertSame(3, substr_count(file_get_contents($this->root . '/requests.log'), $endpoint . "\n"));

        // A fresh process must also stop if the outage continues.
        $result = $this->run_command($command);
        $this->assertSame(3, $result['exit_code'], $result['output']);

        file_put_contents($this->root . '/proxy-status', '200');
        $result = $this->run_command($command);
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assert_retry_delay_in_json($result, null);
        $this->assertSame(
            file_get_contents($this->root . '/remote/example.txt'),
            file_get_contents($this->root . '/files' . $this->root . '/remote/example.txt')
        );
    }

    public static function temporary_failures(): array
    {
        return [
            ['files-pull', 520, 'file_index'],
            ['pull-files', 520, 'file_index'],
            ['files-pull', 521, 'file_fetch'],
            ['files-pull', 522, 'file_fetch'],
            ['files-pull', 523, 'file_fetch'],
            ['files-pull', 524, 'file_fetch'],
            ['files-pull', 503, 'file_index'],
            ['files-pull', 429, 'file_index'],
            ['files-pull', 0, 'file_fetch'],
        ];
    }

    /** @dataProvider temporary_failures */
    public function testRepeatedOutageStopsAfterTwoDelayedRetries(string $command, int $http_status, string $endpoint): void
    {
        file_put_contents($this->root . '/proxy-status', (string) $http_status);
        file_put_contents($this->root . '/proxy-endpoint', $endpoint);
        foreach ([3, 3, 1, 1] as $run => $expected_exit_code) {
            $result = $this->run_command($command);
            $this->assertSame($expected_exit_code, $result['exit_code'], $result['output']);
            $this->assert_retry_delay_in_json($result, [900, 2700, null, null][$run]);
            $this->assertSame(
                3 + $run,
                substr_count(file_get_contents($this->root . '/requests.log'), $endpoint . "\n"),
                'After the immediate retry limit, each later process makes only one failed request.'
            );
        }
        $this->assertStringContainsString('delayed retries', $result['output']);
    }

    public function testRepeatedPartialDatabaseIndexRunsBecomeDelayedThenPermanentFailure(): void
    {
        file_put_contents($this->root . '/proxy-status', '503');
        file_put_contents($this->root . '/proxy-endpoint', 'db_index');
        foreach ([2, 2, 3, 3, 1] as $run => $expected_exit_code) {
            $result = $this->run_command('db-index');
            $this->assertSame($expected_exit_code, $result['exit_code'], $result['output']);
            $this->assert_retry_delay_in_json($result, [null, null, 900, 2700, null][$run]);
            $this->assertSame($run + 1, substr_count(file_get_contents($this->root . '/requests.log'), "db_index\n"));
        }
    }

    public function testChangingTemporaryErrorsDoesNotResetTheDelayedRetryLimit(): void
    {
        foreach ([520, 503, 0] as $run => $http_status) {
            file_put_contents($this->root . '/proxy-status', (string) $http_status);
            $result = $this->run_command('files-pull');
            $this->assertSame($run === 2 ? 1 : 3, $result['exit_code'], $result['output']);
        }
    }

    public function testSuccessfulIndexResetsDelayedRetriesBeforeFileDownload(): void
    {
        file_put_contents($this->root . '/proxy-status', '520');
        foreach ([3, 3] as $expected_exit_code) {
            $result = $this->run_command('files-pull');
            $this->assertSame($expected_exit_code, $result['exit_code'], $result['output']);
        }

        // Let the real index complete, then fail at the next transfer phase.
        file_put_contents($this->root . '/proxy-endpoint', 'file_fetch');
        foreach ([3, 3, 1] as $run => $expected_exit_code) {
            $result = $this->run_command('files-pull');
            $this->assertSame($expected_exit_code, $result['exit_code'], $result['output']);
            $this->assert_retry_delay_in_json($result, [900, 2700, null][$run]);
        }
    }

    public function testPermanentHttpFailureStillExitsOne(): void
    {
        file_put_contents($this->root . '/proxy-status', '404');
        $result = $this->run_command('files-pull');
        $this->assertSame(1, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('NOT_FOUND', $result['output']);
        $this->assertSame(1, substr_count(file_get_contents($this->root . '/requests.log'), "file_index\n"));
    }

    public function testPermanentFailureAfterDelayedRetryHasNoSuggestedWait(): void
    {
        file_put_contents($this->root . '/proxy-status', '520');
        $result = $this->run_command('files-pull');
        $this->assertSame(3, $result['exit_code'], $result['output']);

        file_put_contents($this->root . '/proxy-status', '404');
        $result = $this->run_command('files-pull');
        $this->assertSame(1, $result['exit_code'], $result['output']);
        $this->assert_retry_delay_in_json($result, null);
    }

    /**
     * @param array $result {
     *     @type int    $exit_code Process exit status.
     *     @type string $output    CLI progress and errors.
     * }
     * @param int|null $expected_seconds Expected delay, or null when no retry is suggested.
     */
    private function assert_retry_delay_in_json(array $result, ?int $expected_seconds): void
    {
        $error_records = 0;
        foreach (explode("\n", trim($result['output'])) as $line) {
            $record = json_decode($line, true);
            $this->assertIsArray($record, $line);
            if ($expected_seconds === null) {
                $this->assertArrayNotHasKey('retry_after_seconds', $record);
            } elseif (( $record['status'] ?? null ) === 'error' || isset($record['exception'])) {
                $this->assertSame($expected_seconds, $record['retry_after_seconds'] ?? null, $line);
                ++$error_records;
            }
        }
        if ($expected_seconds !== null) {
            // Both the progress error on stdout and the final error on stderr
            // must carry the same suggestion.
            $this->assertSame(2, $error_records);
        }
    }

    /**
     * @return array {
     *     @type int    $exit_code Process exit status.
     *     @type string $output    CLI progress and errors.
     * }
     */
    private function run_command(string $command): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                $command, $this->remote_reprint_api_url,
                '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files',
                '--progress=jsonl'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/client.log', 'w'], 2 => ['redirect', 1]],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return ['exit_code' => proc_close($process), 'output' => file_get_contents($this->root . '/client.log')];
    }

    private function remove_directory(string $directory): void
    {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
