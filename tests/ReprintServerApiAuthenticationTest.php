<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\PublicKeyClient;

/**
 * Authenticates real HTTP requests through the plugin entry point.
 *
 * Each test stores credentials, starts `php -S` on the router fixture, and
 * reads the response. A request that passes authentication, and for push the
 * push grant, reaches the dispatcher, which rejects the unknown probe endpoint
 * with "Invalid endpoint". No failure before those gates answers that way.
 */
final class ReprintServerApiAuthenticationTest extends TestCase {

    private const TOKEN = 'connection-token';
    private const PULL_PROBE_ENDPOINT = 'authentication_probe';
    private const PUSH_PROBE_ENDPOINT = 'push_authentication_probe';

    /** @var string */
    private $root;

    /** @var string */
    private $credentials_directory;

    /** @var string */
    private $configuration_path;

    /** @var resource|null */
    private $server_process = null;

    /** @var string */
    private $server_log_path;

    /** @var string */
    private $base_url = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/reprint-api-authentication-' . bin2hex(random_bytes(6));
        $this->credentials_directory = $this->root . '/credentials';
        $this->configuration_path = $this->root . '/configuration.json';
        $this->server_log_path = $this->root . '/server.log';
        mkdir($this->credentials_directory, 0755, true);
        mkdir($this->root . '/docroot', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        foreach (['/credentials/secret.php', '/configuration.json', '/server.log'] as $file) {
            if (file_exists($this->root . $file)) {
                unlink($this->root . $file);
            }
        }
        rmdir($this->credentials_directory);
        rmdir($this->root . '/docroot');
        rmdir($this->root);
        parent::tearDown();
    }

    // ── OpenSSL host (the test runtime's real state) ──

    public function testKeyHostWithOnlyATokenIsNotConfigured(): void
    {
        $this->startServer(['options' => $this->tokenOptions()]);

        $response = $this->pullWithToken();

        $this->assertSame(503, $response['status']);
        $this->assertSame('not_configured', $response['body']['reason']);
    }

    public function testKeyHostWithOnlyATokenIsNotConfiguredForAKeyRequest(): void
    {
        $this->startServer(['options' => $this->tokenOptions()]);

        $response = $this->pullWithKey($this->newKeyClient());

        $this->assertSame(503, $response['status']);
        $this->assertSame('not_configured', $response['body']['reason']);
    }

    public function testKeyHostRejectsAValidTokenWhenAKeyIsEnrolled(): void
    {
        $key_client = $this->newKeyClient();
        $this->startServer(['options' => $this->tokenOptions() + $this->keyOptions($key_client, false)]);

        $response = $this->pullWithToken();

        $this->assertSame(403, $response['status']);
        $this->assertSame('requires_key_auth', $response['body']['reason']);
    }

    public function testKeyHostAcceptsAValidKeySignature(): void
    {
        $key_client = $this->newKeyClient();
        $this->startServer(['options' => $this->keyOptions($key_client, false)]);

        $this->assertReachedDispatcher($this->pullWithKey($key_client));
    }

    /**
     * A key signature never covers the body, so verifying one must not buffer
     * it. A body larger than the memory limit still reaches the dispatcher.
     */
    public function testKeyHostVerifiesAKeySignatureWithoutBufferingALargeBody(): void
    {
        $key_client = $this->newKeyClient();
        $this->startServer(['options' => $this->keyOptions($key_client, false)], ['memory_limit' => '24M']);

        $url = $this->endpointUrl(self::PULL_PROBE_ENDPOINT);
        $headers = $key_client->get_auth_headers('POST', $url) + ['Content-Type' => 'application/octet-stream'];
        $this->assertReachedDispatcher($this->request($headers, $url, 'POST', str_repeat('x', 32 * 1024 * 1024)));
    }

    public function testKeyHostReportsUnknownKey(): void
    {
        $this->startServer(['options' => $this->keyOptions($this->newKeyClient(), false)]);

        $response = $this->pullWithKey($this->newKeyClient());

        $this->assertSame(403, $response['status']);
        $this->assertSame('unknown_key', $response['body']['reason']);
    }

    public function testEmptySecretFileDoesNotBlockAKeySignedRequestOnAKeyHost(): void
    {
        file_put_contents($this->credentials_directory . '/secret.php', "<?php return '';\n");
        $key_client = $this->newKeyClient();
        $this->startServer(['key_auth_required' => true, 'options' => $this->keyOptions($key_client, false)]);

        // A key host never accepts the token, so a broken secret.php is irrelevant there.
        $this->assertReachedDispatcher($this->pullWithKey($key_client));
    }

    public function testKeySignedPushIsRefusedWhenOnlyTheTokenMayPush(): void
    {
        $key_client = $this->newKeyClient();
        $this->startServer(['options' => $this->tokenOptions(true) + $this->keyOptions($key_client, false)]);

        $response = $this->pushWithKey($key_client);

        $this->assertSame(403, $response['status']);
        $this->assertSame('push_disabled', $response['body']['reason']);
        $this->assertSame('Push access is disabled for the current key.', $response['body']['detail']);
    }

    public function testKeySignedPushPassesTheGateWhenTheKeyMayPush(): void
    {
        $key_client = $this->newKeyClient();
        $this->startServer(['options' => $this->tokenOptions(false) + $this->keyOptions($key_client, true)]);

        $response = $this->pushWithKey($key_client);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_request', $response['body']['reason']);
        $this->assertStringStartsWith("Invalid endpoint: '" . self::PUSH_PROBE_ENDPOINT . "'", $response['body']['detail']);
    }

    // ── HMAC-only host (forced through the Utils override) ──

    public function testHmacHostReturnsNotConfiguredWhenNoTokenIsStored(): void
    {
        $this->startServer(['key_auth_required' => false]);

        $response = $this->request([]);

        $this->assertSame(503, $response['status']);
        $this->assertSame('not_configured', $response['body']['reason']);
        $this->assertStringEndsWith(
            'Set up the connection in WordPress admin under Tools > Reprint Server.',
            $response['body']['error'],
            'released clients print this message as they receive it'
        );
    }

    public function testHmacHostRejectsAKeyIdHeaderWithRequiresTokenAuth(): void
    {
        $this->startServer(['key_auth_required' => false, 'options' => $this->tokenOptions()]);

        $response = $this->request(['X-Auth-Key-Id' => '0123456789abcdef']);

        $this->assertSame(403, $response['status']);
        $this->assertSame('requires_token_auth', $response['body']['reason']);
    }

    public function testHmacHostAcceptsAValidTokenSignature(): void
    {
        $this->startServer(['key_auth_required' => false, 'options' => $this->tokenOptions()]);

        $this->assertReachedDispatcher($this->pullWithToken());
    }

    public function testEmptySecretFileAnswersNotConfiguredBeforeAnyCredentialIsRead(): void
    {
        file_put_contents($this->credentials_directory . '/secret.php', "<?php return '';\n");
        $this->startServer(['key_auth_required' => false, 'options' => $this->tokenOptions()]);

        $response = $this->pullWithToken();

        $this->assertSame(503, $response['status']);
        $this->assertSame('not_configured', $response['body']['reason']);
        $this->assertSame(
            'Invalid secret.php configuration. Remove it or replace it with a valid connection token.',
            $response['body']['error']
        );
        $this->assertArrayNotHasKey('status', $response['body'], 'a pull endpoint keeps the pull error shape');
    }

    /** @param array{status:int,body:array<string,mixed>} $response */
    private function assertReachedDispatcher(array $response): void
    {
        $this->assertSame(400, $response['status']);
        $this->assertArrayNotHasKey('reason', $response['body']);
        $this->assertStringStartsWith("Invalid endpoint: '" . self::PULL_PROBE_ENDPOINT . "'", $response['body']['error']);
    }

    /** @return array<string,mixed> Options storing the token, granted push when requested. */
    private function tokenOptions(bool $push = false): array
    {
        return [
            'reprint_server_connection_token' => self::TOKEN,
            'reprint_server_push_authorized_token_fingerprint' => $push ? hash('sha256', self::TOKEN) : '',
        ];
    }

    /** @return array<string,mixed> Options enrolling the client's key with the given push grant. */
    private function keyOptions(PublicKeyClient $key_client, bool $push): array
    {
        return [
            'reprint_server_public_keys' => [[
                'public_key' => $key_client->get_public_key(),
                'added_at' => 1700000000,
                'push' => $push,
            ]],
        ];
    }

    private function newKeyClient(): PublicKeyClient
    {
        [$private_pem, ] = PublicKeyClient::generate_keypair();
        return new PublicKeyClient($private_pem);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function pullWithToken(): array
    {
        return $this->request(( new Site_Export_HMAC_Client(self::TOKEN) )->get_auth_headers(''));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function pullWithKey(PublicKeyClient $key_client): array
    {
        $url = $this->endpointUrl(self::PULL_PROBE_ENDPOINT);
        return $this->request($key_client->get_auth_headers('GET', $url), $url);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function pushWithKey(PublicKeyClient $key_client): array
    {
        $url = $this->endpointUrl(self::PUSH_PROBE_ENDPOINT);
        return $this->request($key_client->get_envelope_auth_headers('GET', $url), $url);
    }

    private function endpointUrl(string $endpoint): string
    {
        return $this->base_url . '&endpoint=' . rawurlencode($endpoint);
    }

    /**
     * @param array<string,string> $headers Name => value.
     * @return array{status:int,body:array<string,mixed>}
     */
    private function request(array $headers, ?string $url = null, string $http_method = 'GET', string $body = ''): array
    {
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $http_method,
                'header' => $header_lines,
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $response_body = file_get_contents($url ?? $this->endpointUrl(self::PULL_PROBE_ENDPOINT), false, $context);
        $this->assertIsString($response_body, (string) file_get_contents($this->server_log_path));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_http_response_header -- Set by the HTTP stream wrapper above.
        $status_line = $http_response_header[0] ?? '';
        $this->assertSame(1, preg_match('#^HTTP/\S+ (\d{3})#', $status_line, $matches), $status_line);
        $decoded = json_decode($response_body, true);
        $this->assertIsArray($decoded, $response_body);
        return ['status' => (int) $matches[1], 'body' => $decoded];
    }

    /**
     * @param array<string,mixed>  $configuration Router configuration; see the fixture.
     * @param array<string,string> $php_settings  INI settings for the server process, name => value.
     */
    private function startServer(array $configuration, array $php_settings = []): void
    {
        $configuration['credentials_directory'] = $this->credentials_directory;
        file_put_contents($this->configuration_path, json_encode($configuration, JSON_THROW_ON_ERROR));

        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        fclose($listener);
        $router = realpath(__DIR__ . '/fixtures/api-authentication-router.php');
        $this->assertNotFalse($router);

        $command = [PHP_BINARY, '-d', 'display_errors=0'];
        foreach ($php_settings as $name => $value) {
            array_push($command, '-d', $name . '=' . $value);
        }
        array_push($command, '-S', $address, '-t', $this->root . '/docroot', $router);
        $this->server_process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $this->server_log_path, 'a'], 2 => ['file', $this->server_log_path, 'a']],
            $pipes,
            dirname($router),
            array_merge($_ENV, ['REPRINT_AUTH_TEST_CONFIG' => $this->configuration_path])
        );
        $this->assertIsResource($this->server_process);
        fclose($pipes[0]);

        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $connect_error, $connect_error_message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $this->base_url = 'http://' . $address . '/?reprint-api=1';
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Authentication test server did not start: ' . file_get_contents($this->server_log_path));
    }
}
