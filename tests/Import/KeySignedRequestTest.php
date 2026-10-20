<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;
use WordPress\Reprint\Server\PublicKeyClient;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * Sends key-signed importer requests to the production plugin entry point.
 *
 * The server is `php -S` on tests/fixtures/api-authentication-router.php,
 * which authenticates through index.php as a live site does. The importer
 * signs a form-encoded preflight and a multipart file_fetch. A key signature
 * covers the method and request target, never the body, so both must pass
 * whatever encoding cURL chose for the body.
 */
final class KeySignedRequestTest extends TestCase {

    private string $root;
    private string $url;
    private string $private_key_path;
    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-key-signed-request-' . bin2hex(random_bytes(6));
        foreach (['/credentials', '/docroot', '/state', '/local', '/remote'] as $directory) {
            mkdir($this->root . $directory, 0700, true);
        }
        $this->root = realpath($this->root);

        [$private_key_pem, $public_key] = PublicKeyClient::generate_keypair();
        $this->private_key_path = $this->root . '/enrolled.pem';
        file_put_contents($this->private_key_path, $private_key_pem);
        chmod($this->private_key_path, 0600);


        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/?reprint-api';
        file_put_contents($this->root . '/configuration.json', json_encode([
            'credentials_directory' => $this->root . '/credentials',
            'options' => [
                'home' => $this->url,
                'reprint_server_public_keys' => [[
                    'public_key' => $public_key,
                    'added_at' => 1700000000,
                    'push' => false,
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
        $router = realpath(__DIR__ . '/../fixtures/api-authentication-router.php');
        $this->assertIsString($router);
        $this->server_process = proc_open(
            // Bound an unexpected file-list URL read: the observing listener sends no response.
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'default_socket_timeout=1', '-S', $address, '-t', $this->root . '/docroot', $router],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            dirname($router),
            array_merge($_ENV, ['REPRINT_AUTH_TEST_CONFIG' => $this->root . '/configuration.json'])
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
        $this->fail('Server did not start: ' . file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testFileFetchCursorDoesNotOpenStreamUrls(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        try {
            $remote_path = 'ftp://' . stream_socket_get_name($listener, false) . '/example.jpg';
            $file_list = $this->root . '/file-list.json';
            file_put_contents($file_list, '[]');
            $cursor = ['phase' => 'streaming', 'path' => base64_encode($remote_path), 'ctime' => -1, 'bytes' => 1];
            $url = \WordPress\Reprint\Server\Utils::endpoint_url($this->url, 'file_fetch');
            $client = new PublicKeyClient(file_get_contents($this->private_key_path));
            $headers = [];
            foreach ($client->get_auth_headers('POST', $url) as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_POSTFIELDS => [
                'directory' => $this->root . '/remote', 'cursor' => base64_encode(json_encode($cursor)),
                'file_list' => new \CURLFile($file_list, 'application/json', 'file-list.json'),
            ], CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 5]);
            try {
                $response = curl_exec($handle);
                $connection = @stream_socket_accept($listener, 0);
                if (is_resource($connection)) {
                    fclose($connection);
                }
                $this->assertFalse($connection, 'Restoring the cursor connected to its stream URL.');
                $this->assertIsString($response, curl_error($handle));
                $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE), $response);
                $this->assertStringContainsString('File cursor path', json_decode($response, true)['error']);
            } finally {
                curl_close($handle);
            }
        } finally {
            fclose($listener);
        }
    }

    public function testPreflightSignedWithTheEnrolledKeyPassesAuthentication(): void
    {
        $report = $this->runPreflight($this->private_key_path);

        // The fixture site has no database, so preflight fails after the
        // server authenticated the request and answered with its report.
        $this->assertSame('PREFLIGHT_FAILED', $report['error_code'], json_encode($report));
        $this->assertSame(200, $report['http_code']);
    }

    public function testPreflightSignedWithAnotherKeyIsRefused(): void
    {
        [$other_private_key_pem, ] = PublicKeyClient::generate_keypair();
        $other_private_key_path = $this->root . '/other.pem';
        file_put_contents($other_private_key_path, $other_private_key_pem);
        chmod($other_private_key_path, 0600);

        $report = $this->runPreflight($other_private_key_path);

        $this->assertSame('AUTH_UNKNOWN_KEY', $report['error_code'], json_encode($report));
        $this->assertSame(403, $report['http_code']);
    }

    public function testMultipartFileFetchSignedWithTheEnrolledKeyStreamsTheFile(): void
    {
        $remote_path = $this->root . '/remote/pulled.txt';
        file_put_contents($remote_path, "pulled\0contents\n");
        $url = str_replace('http://', 'http://http-user:http-password@', $this->url);
        $client = new \ImportClient($url, $this->root . '/state', $this->root . '/local', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke(
            $client, true, ['private_key_path' => $this->private_key_path]
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $preflight = ( new \ReflectionMethod($client, 'fetch_json') )->invoke(
            $client, \WordPress\Reprint\Server\Utils::endpoint_url($url, 'preflight'), []
        );
        $this->assertSame(200, $preflight['http_code']);
        $file_list_path = $this->root . '/file-list.json';
        file_put_contents($file_list_path, json_encode([['path' => base64_encode($remote_path)]]));
        $request = ( new \ReflectionMethod($client, 'build_request') )->invoke(
            $client, 'file_fetch', null, ['directory' => [$this->root . '/remote']]
        );
        $context = new StreamingContext();
        $received = '';
        $context->on_chunk = static function (array $chunk) use (&$received, $context): void {
            if (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'completion') {
                $context->saw_completion = true;
            } elseif (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'file') {
                $received .= $chunk['body'];
            }
        };

        ( new \ReflectionMethod($client, 'fetch_streaming') )->invoke(
            $client, $request['url'], $context,
            $request['params'] + [
                'file_list' => new \CURLFile($file_list_path, 'application/json', 'file-list.json'),
            ],
            'file_fetch'
        );

        $this->assertTrue($context->saw_completion, file_get_contents($this->root . '/server.log'));
        $this->assertSame("pulled\0contents\n", $received);
        $audit_log = (string) file_get_contents($this->root . '/state/audit.log');
        $this->assertStringNotContainsString('http-user', $audit_log);
        $this->assertStringNotContainsString('http-password', $audit_log);
        $masked_url = str_replace('http://', 'http://***@', $this->url);
        $this->assertStringContainsString($masked_url . '&endpoint=preflight', $audit_log);
        $this->assertStringContainsString($masked_url . '&endpoint=file_fetch', $audit_log);
    }

    /** A cursor after wp_posts must not include it in the next batch. */
    public function testDbIndexTreatsTheLastTableCursorAsAValue(): void
    {
        require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
        $database_path = $this->root . '/source.sqlite';
        $database = new \WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=' . $database_path . ';dbname=wp_test');
        $database->exec('CREATE TABLE wp_posts (ID bigint PRIMARY KEY)');
        $configuration = json_decode(file_get_contents($this->root . '/configuration.json'), true);
        $configuration['sqlite_database_path'] = $database_path;
        file_put_contents($this->root . '/configuration.json', json_encode($configuration));
        $url = \WordPress\Reprint\Server\Utils::endpoint_url($this->url, 'db_index');
        $client = new PublicKeyClient(file_get_contents($this->private_key_path));
        $headers = [];
        foreach ($client->get_auth_headers('POST', $url) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_POSTFIELDS => [
            'cursor' => base64_encode(json_encode(['last_table' => "zzzz\\' OR 1=1 -- "])),
        ], CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 5]);
        try {
            $response = curl_exec($handle);
            $this->assertIsString($response, curl_error($handle));
            $this->assertSame(200, curl_getinfo($handle, CURLINFO_HTTP_CODE), $response);
            $this->assertStringContainsString('X-Chunk-Type: completion', $response);
            $this->assertStringNotContainsString('X-Chunk-Type: error', $response);
            $this->assertStringNotContainsString('"name":"wp_posts"', $response, 'wp_posts sorts before the supplied last-table value.');
        } finally {
            curl_close($handle);
        }
    }

    /**
     * @dataProvider invalid_sql_states
     * @param mixed $state State received in the client's SQL cursor.
     */
    public function testSqlCursorReportsAnUnsupportedState($state): void
    {
        require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
        $database_path = $this->root . '/source.sqlite';
        $database = new \WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=' . $database_path . ';dbname=wp_test');
        $database->exec('CREATE TABLE wp_posts (ID bigint PRIMARY KEY, post_content longtext)');
        $database->exec("INSERT INTO wp_posts VALUES (1, 'example')");
        $configuration = json_decode(file_get_contents($this->root . '/configuration.json'), true);
        $configuration['sqlite_database_path'] = $database_path;
        file_put_contents($this->root . '/configuration.json', json_encode($configuration));
        $url = \WordPress\Reprint\Server\Utils::endpoint_url($this->url, 'sql_chunk');
        $client = new PublicKeyClient(file_get_contents($this->private_key_path));
        $headers = [];
        foreach ($client->get_auth_headers('POST', $url) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_POSTFIELDS => [
            'cursor' => base64_encode(json_encode(['state' => $state])),
        ], CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 5]);
        try {
            $response = curl_exec($handle);
            $this->assertIsString($response, curl_error($handle));
            $this->assertSame(is_string($state) ? 200 : 400, curl_getinfo($handle, CURLINFO_HTTP_CODE), $response);
            $this->assertStringContainsString(is_string($state) ? 'X-Chunk-Type: error' : '"error":', $response);
            $this->assertStringContainsString(is_string($state)
                ? 'Unknown SQL dump cursor state' : 'SQL dump cursor state must be a string', $response, file_get_contents($this->root . '/server.log'));
            $this->assertStringNotContainsString('Maximum execution time', $response);
        } finally {
            curl_close($handle);
        }
    }

    /** @return array<string,array{mixed}> Unsupported SQL cursor states. */
    public static function invalid_sql_states(): array
    {
        return [
            'unknown state' => ['unknown_state'],
            'empty state' => [''],
            'integer state' => [0],
            'true state' => [true],
            'false state' => [false],
            'array state' => [[]],
        ];
    }

    /**
     * @dataProvider file_list_requests
     * @param string $location Where the extra file-list path is sent.
     * @param string $parameter HTTP parameter spelling.
     * @param bool   $upload Whether the request includes an uploaded file list.
     */
    public function testFileFetchUsesOnlyTheUploadedList(string $location, string $parameter, bool $upload): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        try {
            $extra_file_list_url = 'http://' . stream_socket_get_name($listener, false) . '/file-list.json';
            $remote_path = $this->root . '/remote/pulled.txt';
            file_put_contents($remote_path, 'file selected by the upload');
            $uploaded_list = $this->root . '/uploaded-list.json';
            file_put_contents($uploaded_list, json_encode([['path' => base64_encode($remote_path)]]));
            $url = \WordPress\Reprint\Server\Utils::endpoint_url($this->url, 'file_fetch');
            $fields = ['directory' => $this->root . '/remote'];
            if ($location === 'query') {
                $url .= '&' . http_build_query([$parameter => $extra_file_list_url]);
            } else {
                $fields[$parameter] = $extra_file_list_url;
            }
            if ($upload) {
                $fields['file_list'] = new \CURLFile($uploaded_list, 'application/json', 'file-list.json');
            }
            $client = new PublicKeyClient(file_get_contents($this->private_key_path));
            $headers = [];
            foreach ($client->get_auth_headers('POST', $url) as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $body = $fields;
            if ($location === 'json') {
                $headers[] = 'Content-Type: application/json';
                $body = json_encode($fields);
            } elseif ($location === 'form') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $body = http_build_query($fields);
            }
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 5]);
            try {
                $response = curl_exec($handle);
                $connection = @stream_socket_accept($listener, 0);
                if (is_resource($connection)) {
                    fclose($connection);
                }
                $this->assertFalse($connection, 'The source connected to the request-supplied file-list URL.');
                $this->assertIsString($response, curl_error($handle));
                $this->assertSame($upload ? 200 : 400, curl_getinfo($handle, CURLINFO_HTTP_CODE), $response);
                if ($upload) {
                    $this->assertStringContainsString('X-File-Path: ' . base64_encode($remote_path), $response);
                    $this->assertStringContainsString('file selected by the upload', $response);
                    $this->assertStringContainsString('X-Chunk-Type: completion', $response);
                } else {
                    $this->assertSame('file_list is required for file_fetch endpoint', json_decode($response, true)['error']);
                }
            } finally {
                curl_close($handle);
            }
        } finally {
            fclose($listener);
        }
    }

    /** @return array<string,array{string,string,bool}> Parameter location, spelling, and upload presence. */
    public static function file_list_requests(): array
    {
        return [
            'query without upload' => ['query', 'file_list_path', false],
            'form without upload' => ['form', 'file-list-path', false],
            'json without upload' => ['json', 'file_list_path', false],
            'query with upload' => ['query', 'file-list-path', true],
            'multipart with upload' => ['multipart', 'file_list_path', true],
        ];
    }

    public function testFileCompressionProbeDoesNotConnectToFtpUrl(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        try {
            $remote_path = 'ftp://' . stream_socket_get_name($listener, false) . '/example.neon';
            $file_list = $this->root . '/file-list.json';
            file_put_contents($file_list, json_encode([['path' => base64_encode($remote_path)]]));
            $url = \WordPress\Reprint\Server\Utils::endpoint_url($this->url, 'file_fetch');
            $client = new PublicKeyClient(file_get_contents($this->private_key_path));
            $headers = [];
            foreach ($client->get_auth_headers('POST', $url) as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_POSTFIELDS => [
                'directory' => $this->root . '/remote',
                'file_list' => new \CURLFile($file_list, 'application/json', 'file-list.json'),
            ], CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 5]);
            try {
                $response = curl_exec($handle);
                $connection = @stream_socket_accept($listener, 0);
                if (is_resource($connection)) {
                    fclose($connection);
                }
                $this->assertFalse($connection, 'Compression probing connected to the file-list URL.');
                $this->assertIsString($response, curl_error($handle));
                $this->assertSame(200, curl_getinfo($handle, CURLINFO_HTTP_CODE), $response);
                $this->assertStringContainsString('X-Chunk-Type: missing', $response);
                $this->assertStringContainsString('X-Chunk-Type: completion', $response);
            } finally {
                curl_close($handle);
            }
        } finally {
            fclose($listener);
        }
    }

    /** @return array<string,mixed> The command report preflight printed last. */
    private function runPreflight(string $private_key_path): array
    {
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
            'preflight',
            $this->url,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/local',
            '--private-key-path=' . $private_key_path,
            '--allow-unsafe-http',
            '--progress=jsonl',
        ]));
        exec($command . ' 2>/dev/null', $output_lines);
        $report = json_decode( (string) end($output_lines), true);
        $this->assertIsArray($report, implode("\n", $output_lines));
        $this->assertSame('reprint_report', $report['type']);
        return $report;
    }
}
