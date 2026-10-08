<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

/** Measure request arrival times at a real local HTTP endpoint. */
final class RequestPacingTest extends TestCase {
    private string $root;
    private string $remote_reprint_api_url;
    /** @var resource|null */
    private $server_process = null;

    public function testRateLimitSpacesRetriesCompletedEndpointsAndLaterCommands(): void {
        file_put_contents($this->root . '/reject-next-endpoint', 'file_index');
        $result = $this->run_command('files-pull');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $requests = $this->requests();
        $this->assertSame(['preflight', 'file_index', 'file_index', 'file_fetch'], array_column($requests, 'endpoint'));
        foreach ([2, 3] as $index) {
            $this->assertGreaterThanOrEqual(0.95, $requests[$index]['time'] - $requests[$index - 1]['time']);
        }
        $this->assertSame('Paced file contents.', file_get_contents($this->root . '/files' . $this->root . '/remote/example.txt'));
        $state_path = $this->root . '/state/remotes/' . md5($this->remote_reprint_api_url) . '/pull/state.json';
        $state = json_decode(file_get_contents($state_path), true);
        $this->assertSame(1, $state['tuning']['state']['request_interval_seconds']);

        // Another process must retain the gap, including for lightweight JSON.
        $result = $this->run_command('preflight');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $requests = $this->requests();
        $this->assertSame('preflight', $requests[4]['endpoint']);
        $this->assertGreaterThanOrEqual(0.95, $requests[4]['time'] - $requests[3]['time']);
    }

    public function testLightweightRateLimitIsLearnedAndPacesTheNextUserAgentAttempt(): void {
        file_put_contents($this->root . '/reject-next-endpoint', 'preflight');
        $result = $this->run_command('preflight');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $requests = $this->requests();
        $this->assertSame(['preflight', 'preflight', 'preflight'], array_column($requests, 'endpoint'));
        $this->assertGreaterThanOrEqual(0.95, $requests[2]['time'] - $requests[1]['time']);
    }

    public function testFinalFailedAttemptKeepsTheGapForTheNextProcess(): void {
        file_put_contents($this->root . '/reject-all-endpoint', 'file_index');
        $result = $this->run_command('files-index');
        $this->assertSame(3, $result['exit_code'], $result['output']);
        $requests = $this->requests();
        $this->assertSame(['preflight', 'file_index', 'file_index', 'file_index'], array_column($requests, 'endpoint'));
        $this->assertGreaterThanOrEqual(0.95, $requests[2]['time'] - $requests[1]['time']);
        $this->assertGreaterThanOrEqual(1.95, $requests[3]['time'] - $requests[2]['time']);
        $state_path = $this->root . '/state/remotes/' . md5($this->remote_reprint_api_url) . '/pull/state.json';
        $state = json_decode(file_get_contents($state_path), true);
        $this->assertSame(4, $state['tuning']['state']['request_interval_seconds']);

        $result = $this->run_command('preflight');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $requests = $this->requests();
        $this->assertGreaterThanOrEqual(3.95, $requests[4]['time'] - $requests[3]['time']);
    }

    public function testNoAdaptiveDoesNotLearnOrWaitAfterRateLimit(): void {
        file_put_contents($this->root . '/reject-next-endpoint', 'file_index');
        $result = $this->run_command('files-pull', '--no-adaptive');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringNotContainsString('REQUEST PACING', file_get_contents($this->root . '/state/audit.log'));
    }

    public function testAnotherRemoteApiUrlDoesNotInheritTheGap(): void {
        file_put_contents($this->root . '/reject-next-endpoint', 'preflight');
        $result = $this->run_command('preflight');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->remote_reprint_api_url .= '/?another-source';
        $result = $this->run_command('preflight');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $state_path = $this->root . '/state/remotes/' . md5($this->remote_reprint_api_url) . '/pull/state.json';
        $state = json_decode(file_get_contents($state_path), true);
        $this->assertSame(0, $state['tuning']['state']['request_interval_seconds']);
    }

    public function testRemotesWithoutRateLimitsKeepExistingPacing(): void {
        $result = $this->run_command('files-pull');
        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringNotContainsString('REQUEST PACING', file_get_contents($this->root . '/state/audit.log'));
    }

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/reprint-request-pacing-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $this->root = realpath($this->root);
        file_put_contents($this->root . '/remote/example.txt', 'Paced file contents.');
        file_put_contents($this->root . '/reject-next-endpoint', '');
        file_put_contents($this->root . '/reject-all-endpoint', '');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->remote_reprint_api_url = 'http://' . $address;
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/request-pacing-router.php'],
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

    protected function tearDown(): void {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        $this->remove_directory($this->root);
    }

    /**
     * @return array[] {
     *     @type string $endpoint Requested endpoint.
     *     @type float  $time     Request arrival time in Unix seconds.
     * }
     */
    private function requests(): array {
        return array_map(static function (string $line): array {
            return json_decode($line, true);
        }, file($this->root . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
    }

    /**
     * @return array {
     *     @type int    $exit_code Process exit status.
     *     @type string $output    CLI progress and errors.
     * }
     */
    private function run_command(string $command, string ...$options): array {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                $command, $this->remote_reprint_api_url, '--allow-unsafe-http',
                '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files',
                '--progress=jsonl', '--secret=test-secret', ...$options],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/client.log', 'w'], 2 => ['redirect', 1]],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return ['exit_code' => proc_close($process), 'output' => file_get_contents($this->root . '/client.log')];
    }

    private function remove_directory(string $directory): void {
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
