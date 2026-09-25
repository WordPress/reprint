<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class InsecureTlsTest extends TestCase {
    private string $root;
    private string $http_url;
    private string $https_url;
    /** @var resource[] */
    private array $processes = [];
    /** @var array<string, string|false> */
    private array $saved_environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-tls-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0700, true);
        $this->root = realpath($this->root);
        file_put_contents($this->root . '/remote/example.txt', str_repeat('remote contents\n', 1024));
        foreach (['REPRINT_INSECURE_TLS', 'ALL_PROXY', 'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $name) {
            $this->saved_environment[$name] = getenv($name);
            putenv($name);
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'wrong-host.example'], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        $this->assertTrue(openssl_x509_export_to_file($certificate, $this->root . '/certificate.pem'));
        $this->assertTrue(openssl_pkey_export_to_file($key, $this->root . '/key.pem'));

        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        fclose($this->start_process([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/insecure-tls-router.php']));
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
        $this->http_url = 'http://' . $address . '/?reprint-api';
        $output = $this->start_process([
            PHP_BINARY, __DIR__ . '/fixtures/tls-relay.php',
            $this->root . '/certificate.pem', $this->root . '/key.pem', $address,
        ]);
        stream_set_timeout($output, 5);
        $tls_address = trim( (string) fgets($output));
        fclose($output);
        $this->assertNotSame('', $tls_address, file_get_contents($this->root . '/server.log'));
        $this->https_url = 'https://' . $tls_address . '/?reprint-api';
    }

    protected function tearDown(): void
    {
        foreach ($this->saved_environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        foreach (array_reverse($this->processes) as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->remove_directory($this->root);
    }

    /** @dataProvider cli_options */
    public function testCliKeepsHttpAndCertificateBypassesExplicit(?string $flag, string $environment, bool $allows_http, bool $allows_bad_certificate): void
    {
        putenv('REPRINT_INSECURE_TLS=' . $environment);
        foreach ([$this->http_url => $allows_http, $this->https_url => $allows_bad_certificate] as $url => $accepted) {
            $arguments = [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                'preflight', $url, '--secret=tls-test-secret',
                '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/local', '--progress=jsonl'];
            if ($flag !== null) {
                $arguments[] = $flag;
            }
            $result = $this->run_cli($arguments);
            $stdout = $result['stdout'];
            $stderr = $result['stderr'];
            $records = array_filter(array_map(static fn($line) => json_decode($line, true), explode("\n", $stdout)));
            $preflight = array_values(array_filter($records, static fn($record) => isset($record['http_code'])));
            $this->assertSame($accepted, ( $preflight[0]['http_code'] ?? 0 ) === 200, $url . "\n" . $stdout . $stderr);
            if (!$accepted) {
                $this->assertStringContainsString($url === $this->http_url ? '--insecure' : 'SSL', $stdout . $stderr);
            }
        }
    }

    public static function cli_options(): array
    {
        return [
            'secure default' => [null, '', false, false],
            'long flag' => ['--insecure', '', true, true],
            'short flag' => ['-k', '', true, true],
            'flag overrides disabled environment' => ['--insecure', '0', true, true],
            'environment' => [null, '1', true, true],
            'disabled environment' => [null, '0', false, false],
            'legacy force-http' => ['--force-http', '', true, false],
            'legacy allow-unsafe-http' => ['--allow-unsafe-http', '', true, false],
        ];
    }

    public function testStreamingDownloadsAndLaterSecureClientUseSeparateTlsSettings(): void
    {
        $client = new \ImportClient($this->https_url, $this->root . '/state', $this->root . '/local', ['insecure' => true]);
        ( new \ReflectionProperty($client, 'hmac_client') )->setValue($client, new \Site_Export_HMAC_Client('tls-test-secret'));
        $file_list = $this->root . '/file-list.json';
        file_put_contents($file_list, json_encode([['path' => base64_encode($this->root . '/remote/example.txt')]]));
        for ($request = 0; $request < 2; ++$request) {
            $context = new StreamingContext();
            $received = '';
            $context->on_chunk = static function (array $chunk) use (&$received, $context): void {
                if (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'completion') {
                    $context->saw_completion = true;
                } elseif (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'file') {
                    $received .= $chunk['body'];
                }
            };
            ( new \ReflectionMethod($client, 'fetch_streaming') )->invoke($client, $this->https_url, null, $context, [
                'endpoint' => 'file_fetch', 'file_list' => new \CURLFile($file_list, 'application/json', 'file-list.json'),
            ], 'file_fetch');
            $this->assertSame(file_get_contents($this->root . '/remote/example.txt'), $received);
        }
        $secure_client = new \ImportClient($this->https_url, $this->root . '/secure-state', $this->root . '/local');
        $result = ( new \ReflectionMethod($secure_client, 'fetch_json') )->invoke($secure_client, $this->https_url, ['endpoint' => 'preflight']);
        $this->assertFalse($result['ok']);
        $this->assertSame(CURLE_SSL_CACERT, $result['curl_errno']);
        $this->assertFalse(getenv('REPRINT_INSECURE_TLS'));
    }

    /** @dataProvider insecure_settings */
    public function testPushControlAndUploadRequestsUseTheSameTlsSetting(bool $insecure, string $environment): void
    {
        putenv('REPRINT_INSECURE_TLS=' . $environment);
        $client = new \MultipartPushStreamClient([
            'remote_reprint_api_url' => $this->https_url,
            'request_context_headers' => ['User-Agent' => 'Reprint/1.0'],
            'hmac_client' => new \Site_Export_HMAC_Client('tls-test-secret'),
            'insecure' => $insecure,
            'connect_timeout' => 2, 'stall_timeout' => 2, 'response_timeout' => 2,
        ]);
        try {
            $push_session_id = str_repeat('a', 32);
            $result = $client->send_push_request('POST', 'push_create', ['push_session_id' => $push_session_id], ['created']);
            $this->assertSame('complete', $result['status'], json_encode($result));
            $this->assertTrue($client->start_upload_request($push_session_id), (string) $client->get_last_error());
            $this->assertTrue($client->send_part([
                'type' => 'file', 'path' => 'uploaded.txt', 'total_bytes' => 5, 'offset' => 0, 'payload' => 'hello',
            ]));
            $result = $client->finish_request();
            $this->assertSame('complete', $result['status'], json_encode($result));
            $result = $client->send_push_request('GET', 'push_status', [
                'push_session_id' => $push_session_id, 'path_b64' => base64_encode('uploaded.txt'),
            ], ['accepted']);
            $this->assertSame(5, $result['response']['path']['accepted_bytes'], json_encode($result));
        } finally {
            $client->close();
        }
    }

    /** @dataProvider insecure_cli_settings */
    public function testFilesPushCliPassesTlsSettingsThroughTheSender(?string $flag, string $environment): void
    {
        putenv('REPRINT_INSECURE_TLS=' . $environment);
        $document_root = $this->root . '/remote';
        $filesystem_root = $this->root . '/local';
        mkdir($filesystem_root . $document_root, 0700, true);
        file_put_contents($filesystem_root . $document_root . '/uploaded.txt', 'pushed through TLS');
        $remote_state_directory = $this->root . '/state/remotes/' . md5($this->https_url);
        mkdir($remote_state_directory . '/pull', 0700, true);
        $state = new \PullState();
        $state->set_preflight_record([
            'http_code' => 200,
            'data' => ['runtime' => ['document_root' => 'base64:' . base64_encode($document_root)]],
        ]);
        file_put_contents($remote_state_directory . '/pull/state.json', json_encode($state->to_array()));
        file_put_contents($remote_state_directory . '/local_index.jsonl', '');
        $arguments = [PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
            'files-push', $this->https_url, '--secret=tls-test-secret',
            '--state-dir=' . $this->root . '/state', '--fs-root=' . $filesystem_root, '--progress=jsonl'];
        if ($flag !== null) {
            $arguments[] = $flag;
        }
        $result = $this->run_cli($arguments);
        $this->assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
        $this->assertSame('pushed through TLS', file_get_contents($document_root . '/uploaded.txt'));
        $this->assertArrayNotHasKey('insecure', json_decode(file_get_contents($remote_state_directory . '/pull/state.json'), true));
    }

    public function testDatabasePushAcceptsNewAndLegacyTransportFlags(): void
    {
        foreach (['--insecure', '-k', '--force-http', '--allow-unsafe-http'] as $flag) {
            $result = $this->run_cli([
                PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                'db-push', $this->http_url, '--secret=tls-test-secret', '--cleanup', $flag,
                '--state-dir=' . $this->root . '/state',
            ]);
            $this->assertSame(1, $result['exit_code']);
            $this->assertStringContainsString('Stage a database with db-push before committing or cleaning it.', $result['stdout'] . $result['stderr']);
        }
    }

    public static function insecure_cli_settings(): array
    {
        return ['long flag' => ['--insecure', ''], 'short flag' => ['-k', ''], 'environment' => [null, '1']];
    }

    public static function insecure_settings(): array
    {
        return ['option' => [true, ''], 'environment' => [false, '1']];
    }

    /**
     * @param string[] $arguments CLI arguments including PHP and the entry point.
     * @return array {
     *     CLI result.
     *     @type int    $exit_code Process exit code.
     *     @type string $stdout    Command output.
     *     @type string $stderr    Command errors.
     * }
     */
    private function run_cli(array $arguments): array
    {
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param string[] $arguments Child process arguments.
     * @return resource Child stdout.
     */
    private function start_process(array $arguments)
    {
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->root . '/server.log', 'a']], $pipes, $this->root);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->processes[] = $process;
        return $pipes[1];
    }

    private function remove_directory(string $directory): void
    {
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
