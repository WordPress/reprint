<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class PreflightErrorOutputTest extends TestCase {
    private string $root;
    private string $remote_url;
    /** @var resource|null */
    private $server_process;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-preflight-errors-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->remote_url = 'http://' . $address . '/?reprint-api';
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/preflight-errors-router.php'],
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
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        $this->remove_directory($this->root);
    }

    /** @dataProvider failed_responses */
    public function testFailureSurvivesStandalonePreflightAndSavedAssertion(
        int $http_code,
        string $body,
        string $error_code,
        string $detail
    ): void {
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => $http_code,
            'body' => $body,
        ]));
        $preflight = $this->run_command('preflight', 1);
        $this->assertSame($http_code, $preflight['http_code']);
        $this->assertSame($error_code, $preflight['error_code'] ?? null);
        $this->assertStringContainsString($detail, $preflight['error']);
        $this->assert_error_output($preflight);

        $assertion = $this->run_command('preflight-assert', 1);
        $this->assertSame('preflight_assertion', $assertion['type'] ?? null);
        $this->assertSame($preflight['error'], $assertion['error']);
        $this->assertSame($error_code, $assertion['error_code']);
        $this->assertNotEmpty($assertion['checks']);
        $this->assert_error_output($assertion);

        $pull = $this->run_command('pull-files', 1);
        $this->assertSame('preflight', $pull['failed_stage']);
        $this->assertSame($preflight['error'], $pull['error']);
        $this->assertSame($error_code, $pull['error_code']);
        $this->assert_error_output($pull);
    }

    public static function failed_responses(): array
    {
        return [
            'gateway error' => [520, '<html>Unknown error</html>', 'SERVER_ERROR', 'HTTP 520'],
            'authentication' => [401, '{"error":"Invalid signature"}', 'AUTH_FAILED', 'Invalid signature'],
            'redirect' => [302, '', 'REDIRECT', 'https://example.test/reprint-api'],
            'HTML instead of JSON' => [200, '<html>Sign in</html>', 'HTML_RESPONSE', 'HTML page'],
            'malformed JSON' => [200, '{', 'INVALID_JSON', 'Invalid JSON'],
            'empty response' => [200, '', 'INVALID_PREFLIGHT_RESPONSE', "'ok' field"],
            'JSON null' => [200, 'null', 'INVALID_PREFLIGHT_RESPONSE', "'ok' field"],
            'JSON scalar' => [200, '42', 'INVALID_PREFLIGHT_RESPONSE', "'ok' field"],
            'server preflight failure' => [200, '{"ok":false,"error":"WordPress was not found."}', 'PREFLIGHT_FAILED', 'WordPress was not found.'],
            'database failure' => [200, '{"ok":false,"error":null,"database":{"connected":false,"error":"Access denied for migration user."}}', 'PREFLIGHT_FAILED', 'Access denied for migration user.'],
            'filesystem failure' => [200, '{"ok":false,"filesystem":{"ok":false,"error":"The directory /site is not readable."}}', 'PREFLIGHT_FAILED', 'The directory /site is not readable.'],
            'rewritten domain' => [200, '{"ok":true,"database":{"wp":{"home":"https://preview.test","home_domain_b64":"ZXhhbXBsZS50ZXN0"}}}', 'PREFLIGHT_FAILED', "changed the site domain from 'example.test' to 'preview.test'"],
        ];
    }

    public function testConnectionFailureKeepsItsCodeAndDetail(): void
    {
        proc_terminate($this->server_process);
        proc_close($this->server_process);
        $this->server_process = null;

        $preflight = $this->run_command('preflight', 1);
        $this->assertSame('CURL_ERROR', $preflight['error_code'] ?? null);
        $this->assertStringContainsString('cURL error', $preflight['error']);
        $this->assert_error_output($preflight);
        $assertion = $this->run_command('preflight-assert', 1);
        $this->assertSame($preflight['error'], $assertion['error']);
        $this->assertSame('CURL_ERROR', $assertion['error_code']);
        $this->assert_error_output($assertion);

        $pull = $this->run_command('pull-files', 1);
        $this->assertSame('preflight', $pull['failed_stage']);
        $this->assertSame('CURL_ERROR', $pull['error_code']);
        $this->assertStringContainsString('cURL error', $pull['error']);
        $this->assert_error_output($pull);
    }

    /** @dataProvider download_commands */
    public function testDownloadFailuresUseTheSameErrorFields(string $command, bool $connection_failure): void
    {
        $preflight_body = json_encode([
            'ok' => true,
            'protocol_version' => PULL_PROTOCOL_VERSION,
            'filesystem' => ['ok' => true],
            'database' => ['connected' => true],
            'wp_detect' => ['roots' => [['path' => '/site']]],
        ]);
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 200,
            'body' => $preflight_body,
        ]));
        $this->run_command('preflight', 0);
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 401,
            'body' => '{"error":"Invalid signature"}',
            'preflight_body' => $preflight_body,
        ]));
        if ($connection_failure) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
            $this->server_process = null;
        }
        // A signed request's unmarked HTTP 401 can come from a gateway; it is
        // retryable. A refused connection still exits with code 1.
        $download = $this->run_command($command, $connection_failure ? 1 : 3);
        $this->assertSame($connection_failure ? 'CURL_ERROR' : 'AUTH_FAILED', $download['error_code']);
        $this->assertStringContainsString($connection_failure ? 'cURL error' : 'Invalid signature', $download['error']);
        $this->assert_error_output($download);
    }

    public static function download_commands(): array
    {
        return [
            'files-pull HTTP error' => ['files-pull', false],
            'db-pull HTTP error' => ['db-pull', false],
            'pull-files HTTP error' => ['pull-files', false],
            'pull-db HTTP error' => ['pull-db', false],
            'files-pull connection error' => ['files-pull', true],
            'db-pull connection error' => ['db-pull', true],
            'pull-files connection error' => ['pull-files', true],
            'pull-db connection error' => ['pull-db', true],
        ];
    }

    public function testRealEndpointFailureReportsItsReason(): void
    {
        $preflight = $this->run_command('preflight', 1);
        $this->assertSame('PREFLIGHT_FAILED', $preflight['error_code'] ?? null);
        $this->assertSame($preflight['data']['database']['error'], $preflight['error']);
        $this->assertNotEmpty($preflight['error']);
        $this->assert_error_output($preflight);
    }

    public function testReportingDatabaseFailureDoesNotRejectLowLevelFileCommands(): void
    {
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 200,
            'body' => '{"ok":false,"filesystem":{"ok":true},"database":{"connected":false,"error":"Access denied."}}',
        ]));
        $this->run_command('preflight', 1);
        $client = new \ImportClient($this->remote_url, $this->root . '/state', $this->root . '/files');
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('state')->setValue($client, $reflection->getMethod('load_state')->invoke($client));
        $reflection->getMethod('require_preflight')->invoke($client);
        $this->assertNull($client->get_state()->preflight_record()['error']);
        $this->assertSame('Access denied.', $client->get_state()->preflight_record()['data']['database']['error']);
    }

    public function testAssertionReadsAnOlderSavedHttpFailureWithoutAnErrorCode(): void
    {
        $client = new \ImportClient($this->remote_url, $this->root . '/state', $this->root . '/files');
        \write_current_pull_state($client, [
            'preflight' => [
                'http_code' => 520,
                'data' => null,
                'error' => 'The remote server returned HTTP 520.',
                'response_body_preview' => '<html>Unknown error</html>',
            ],
        ]);
        $assertion = $this->run_command('preflight-assert', 1);
        $this->assertSame('SERVER_ERROR', $assertion['error_code']);
        $this->assertSame('The remote server returned HTTP 520.', $assertion['error']);
        $this->assert_error_output($assertion);
    }

    public function testAssertionReportsFailedChecksAndSuccessClearsTheError(): void
    {
        $payload = [
            'ok' => true,
            'protocol_version' => PULL_PROTOCOL_VERSION + 1,
            'filesystem' => ['ok' => true],
            'database' => ['connected' => true],
        ];
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 200,
            'body' => json_encode($payload),
        ]));
        $this->run_command('preflight', 0);
        $assertion = $this->run_command('preflight-assert', 1);
        $this->assertSame('PREFLIGHT_FAILED', $assertion['error_code'] ?? null);
        $this->assertStringContainsString('Update the Reprint client.', $assertion['error']);
        $this->assert_error_output($assertion);

        $payload['protocol_version'] = PULL_PROTOCOL_VERSION;
        file_put_contents($this->root . '/response.json', json_encode([
            'http_code' => 200,
            'body' => json_encode($payload),
        ]));
        foreach (['preflight', 'preflight-assert'] as $command) {
            $result = $this->run_command($command, 0);
            $this->assertSame('complete', $result['status']);
            $this->assertNull($result['error']);
            $this->assertNull($result['error_code']);
            $progress = json_decode(file_get_contents($this->root . '/state/progress.json'), true);
            $this->assertNull($progress['error']);
            $this->assertNull($progress['error_code']);
        }
    }

    public function testAssertionWithoutSavedPreflightExplainsWhatToRun(): void
    {
        $assertion = $this->run_command('preflight-assert', 1);
        $this->assertSame('PREFLIGHT_REQUIRED', $assertion['error_code'] ?? null);
        $this->assertStringContainsString("Run 'preflight' first.", $assertion['error']);
        $this->assert_error_output($assertion);
    }

    /**
     * @param array $result {
     *     Command result.
     *     @type string $status     Command status.
     *     @type string $error      Failure detail.
     *     @type string $message    Display message.
     *     @type string $error_code   Machine-readable failure code.
     *     @type string $failed_stage Optional failed pipeline stage.
     * }
     */
    private function assert_error_output(array $result): void
    {
        $this->assertSame('error', $result['status']);
        if (isset($result['failed_stage'])) {
            $this->assertStringContainsString($result['error'], $result['message']);
        } else {
            $this->assertSame('Error: ' . $result['error'], $result['message']);
        }
        $progress = json_decode(file_get_contents($this->root . '/state/progress.json'), true);
        $this->assertSame('error', $progress['status']);
        $this->assertSame($result['error'], $progress['error']);
        $this->assertSame($result['error_code'], $progress['error_code']);
    }

    private function run_command(string $command, int $exit_code): array
    {
        // Continue healthy partial work according to the exit-code-2 contract.
        // Request failures return to the caller without retrying here.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                    $command, $this->remote_url, '--secret=preflight-test-secret',
                    '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files',
                    '--progress=jsonl'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $actual_exit_code = proc_close($process);
            if ($actual_exit_code !== 2) {
                break;
            }
        }
        $this->assertSame($exit_code, $actual_exit_code, $stdout . $stderr);
        $records = array_map(static function (string $line): array {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, array_filter(explode("\n", trim($stdout))));
        $this->assertNotEmpty($records, $stderr);
        return end($records);
    }

    private function remove_directory(string $directory): void
    {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            is_dir($path) ? $this->remove_directory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
