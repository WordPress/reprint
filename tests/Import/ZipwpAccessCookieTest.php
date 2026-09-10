<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class ZipwpAccessCookieTest extends TestCase {

    private string $root;
    /** @var resource|null */
    private $server_process = null;
    /** @var array<string, string|false> */
    private array $saved_environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-zipwp-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $this->root = realpath($this->root);
        file_put_contents($this->root . '/remote/example.txt', 'temporary site contents');

        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/zipwp-access-router.php'],
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

        // Send the real clients to our local PHP router without changing DNS
        // or adding a transport override to production code. PHP accepts the
        // absolute request target used by an HTTP proxy.
        foreach (['ALL_PROXY', 'NO_PROXY', 'no_proxy', 'http_proxy', 'HTTP_PROXY'] as $name) {
            $this->saved_environment[$name] = getenv($name);
            putenv($name);
        }
        putenv('ALL_PROXY=http://' . $address);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved_environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        $this->remove_directory($this->root);
    }

    /** @dataProvider remote_urls */
    public function testPreflightSendsCookieOnlyToZipwpSubdomains(string $remote_reprint_api_url, bool $expects_cookie): void
    {
        $client = $this->create_client($remote_reprint_api_url);
        $result = ( new \ReflectionMethod($client, 'fetch_json') )->invoke($client, $remote_reprint_api_url);
        $cookie = trim(file_get_contents($this->root . '/cookie.log'));

        if ($expects_cookie) {
            $this->assertMatchesRegularExpression('/^zipwp_access=[a-z0-9]{12}$/', $cookie);
            $this->assertTrue($result['ok'], (string) $result['error']);
            $this->assertIsArray($result['json']);
        } else {
            $this->assertSame('', $cookie);
            $this->assertSame('HTML_RESPONSE', $result['error_code']);
        }
    }

    public static function remote_urls(): array
    {
        return [
            'temporary site' => ['http://demo.zipwp.to/?endpoint=preflight', true],
            'nested subdomain and port' => ['http://one.demo.zipwp.to:8080/?endpoint=preflight', true],
            'mixed case' => ['http://Demo.ZIPWP.TO/?endpoint=preflight', true],
            'trailing DNS dot' => ['http://demo.zipwp.to./?endpoint=preflight', true],
            'bare domain' => ['http://zipwp.to/?endpoint=preflight', false],
            'unrelated domain' => ['http://example.test/?endpoint=preflight', false],
            'missing label boundary' => ['http://notzipwp.to/?endpoint=preflight', false],
            'suffix in another domain' => ['http://demo.zipwp.to.example.test/?endpoint=preflight', false],
            'suffix in path' => ['http://example.test/demo.zipwp.to?endpoint=preflight', false],
            'suffix in query' => ['http://example.test/?endpoint=preflight&site=demo.zipwp.to', false],
            'suffix in user info' => ['http://demo.zipwp.to@example.test/?endpoint=preflight', false],
        ];
    }

    public function testStreamingDownloadsPassTheTemporarySitePage(): void
    {
        $remote_reprint_api_url = 'http://demo.zipwp.to/?endpoint=file_fetch';
        $client = $this->create_client($remote_reprint_api_url);
        $fetch = new \ReflectionMethod($client, 'fetch_streaming');
        $file_list_path = $this->root . '/file-list.json';
        file_put_contents($file_list_path, json_encode([
            ['path' => base64_encode($this->root . '/remote/example.txt')],
        ]));
        // Each fetch opens a new cURL handle and must pass the page again.
        for ($request_number = 0; $request_number < 2; ++$request_number) {
            $context = new StreamingContext();
            $received = '';
            $context->on_chunk = static function (array $chunk) use (&$received, $context): void {
                if (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'completion') {
                    $context->saw_completion = true;
                } elseif (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'file') {
                    $received .= $chunk['body'];
                }
            };
            $fetch->invoke($client, $remote_reprint_api_url, null, $context, [
                'file_list' => new \CURLFile($file_list_path, 'application/json', 'file-list.json'),
            ], 'file_fetch');
            $this->assertSame('temporary site contents', $received);
        }
    }

    public function testCookieDoesNotReplaceTheReprintConnectionToken(): void
    {
        $remote_reprint_api_url = 'http://demo.zipwp.to/?endpoint=preflight';
        $client = $this->create_client($remote_reprint_api_url, 'wrong-token');
        $result = ( new \ReflectionMethod($client, 'fetch_json') )->invoke($client, $remote_reprint_api_url);
        $this->assertSame(401, $result['http_code']);
        $this->assertSame('AUTH_SECRET_MISMATCH', $result['error_code']);
    }

    public function testPushControlAndUploadRequestsPassTheTemporarySitePage(): void
    {
        $client = new \MultipartPushStreamClient([
            'remote_reprint_api_url' => 'http://demo.zipwp.to/',
            'request_context_headers' => [
                'User-Agent' => 'Reprint/1.0',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'http://demo.zipwp.to/wp-admin/upload.php',
            ],
            'allow_http' => true,
            'hmac_client' => new \Site_Export_HMAC_Client('zipwp-test-secret'),
            'connect_timeout' => 2,
            'stall_timeout' => 2,
            'response_timeout' => 2,
        ]);
        try {
            $push_session_id = str_repeat('a', 32);
            $parameters = ['push_session_id' => $push_session_id];
            $result = $client->send_push_request('POST', 'push_create', $parameters, ['created']);
            $this->assertSame('complete', $result['status'], json_encode($result));
            $this->assertTrue($client->start_upload_request($push_session_id), (string) $client->get_last_error());
            $this->assertTrue($client->send_part([
                'type' => 'file',
                'path' => 'uploaded.txt',
                'total_bytes' => 5,
                'offset' => 0,
                'payload' => 'hello',
            ]));
            $result = $client->finish_request();
            $this->assertSame('complete', $result['status'], json_encode($result));
            $parameters['path_b64'] = base64_encode('uploaded.txt');
            $result = $client->send_push_request('GET', 'push_status', $parameters, ['accepted']);
            $this->assertSame('complete', $result['status'], json_encode($result));
            $this->assertSame(5, $result['response']['path']['accepted_bytes']);
        } finally {
            $client->close();
        }
    }

    private function create_client(string $remote_reprint_api_url, string $secret = 'zipwp-test-secret'): \ImportClient
    {
        $client = new \ImportClient($remote_reprint_api_url, $this->root . '/state', $this->root . '/local');
        ( new \ReflectionProperty($client, 'hmac_client') )->setValue($client, new \Site_Export_HMAC_Client($secret));
        return $client;
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
