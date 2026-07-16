<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-importer/src/import.php';
require_once __DIR__ . '/../packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php';

final class PushEndpointsTest extends TestCase {

    private const SECRET = 'real-push-endpoint-test-secret';
    private const POST_MAX_BYTES = 8192;

    /** @var resource|null */
    private $server_process;

    /** @var resource[] */
    private array $server_pipes = [];

    private string $root;
    private string $docroot;
    private string $docroot_link;
    private string $reprint_directory;
    private string $reprint_configuration_path;
    private string $excluded_paths_configuration_path;
    private string $base_url;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('curl_init') || PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Push endpoint E2E requires PHP curl with CURL_READFUNC_PAUSE support.');
        }
        $this->root = sys_get_temp_dir() . '/push-endpoints-' . bin2hex(random_bytes(6));
        $this->docroot = $this->root . '/docroot';
        $this->docroot_link = $this->root . '/docroot-link';
        $this->reprint_directory = $this->root . '/reprint';
        $this->reprint_configuration_path = $this->root . '/reprint-directory';
        $this->excluded_paths_configuration_path = $this->root . '/excluded-paths.json';
        mkdir($this->docroot, 0700, true);
        symlink($this->docroot, $this->docroot_link);
        mkdir($this->reprint_directory, 0700, true);
        file_put_contents($this->reprint_configuration_path, $this->reprint_directory);
        file_put_contents($this->excluded_paths_configuration_path, json_encode(['preserved']));
        file_put_contents($this->docroot . '/remove.txt', 'old');
        mkdir($this->docroot . '/preserved');
        file_put_contents($this->docroot . '/preserved/value.txt', 'keep');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = realpath(__DIR__ . '/fixtures/push-endpoint-router.php');
        $this->assertNotFalse($router);
        $environment = array_merge($_ENV, [
            'REPRINT_PUSH_TEST_SECRET' => self::SECRET,
            'REPRINT_PUSH_TEST_DOCROOT' => $this->docroot_link,
            'REPRINT_PUSH_TEST_DIRECTORY_CONFIG' => $this->reprint_configuration_path,
            'REPRINT_PUSH_TEST_EXCLUDED_PATHS_CONFIG' => $this->excluded_paths_configuration_path,
        ]);
        $server_log_path = $this->root . '/server.log';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $server_log_path, 'a'],
            2 => ['file', $server_log_path, 'a'],
        ];
        // PHP enforces post_max_size before the router can disable display_errors,
        // so disable it at startup to keep the endpoint's 413 body valid JSON.
        // Send process output to a file so repeated suppressed filesystem
        // notices cannot fill a pipe and block the server response.
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'post_max_size=' . self::POST_MAX_BYTES, '-S', $address, $router], $descriptors, $pipes, dirname($router), $environment);
        $this->assertIsResource($process);
        $this->server_process = $process;
        $this->server_pipes = $pipes;
        fclose($this->server_pipes[0]);
        unset($this->server_pipes[0]);
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
        $server_log = file_get_contents($server_log_path);
        $this->fail('Push endpoint test server did not start: ' . $server_log);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            foreach ($this->server_pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->server_process);
        }
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testSignedEndpointsReceiveManyChangesCommitAndRemove(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('a', 32);

        $create = $client->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $this->assertSame([
            'status' => 'created',
            'push_session_id' => $push_session_id,
            'max_part_bytes' => 4,
            'post_max_bytes' => self::POST_MAX_BYTES,
            'http_code' => 200,
        ], $create['response']);
        $client->set_max_part_bytes($create['response']['max_part_bytes']);
        $client->apply_reported_limits([$create['response']['post_max_bytes']]);

        $this->assertTrue($client->start_upload_request($push_session_id));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'nested/file.bin',
            'total_bytes' => 8,
            'offset' => 0,
            'payload' => "ab\0c",
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'file',
            'path' => 'nested/file.bin',
            'total_bytes' => 8,
            'offset' => 4,
            'payload' => 'defg',
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'directory',
            'path' => 'empty-directory',
            'payload' => '',
        ]));
        $this->assertTrue($client->send_part([
            'type' => 'symlink',
            'path' => 'file-link',
            'target' => 'nested/file.bin',
            'payload' => '',
        ]));
        $delete_payload = "remove.txt\0";
        $delete_offset = 0;
        foreach (str_split($delete_payload, 4) as $delete_piece) {
            $this->assertTrue($client->send_part([
                'type' => 'delete-list',
                'offset' => $delete_offset,
                'payload' => $delete_piece,
            ]));
            $delete_offset += strlen($delete_piece);
        }
        $this->assertTrue($client->send_part([
            'type' => 'delete-list',
            'offset' => $delete_offset,
            'complete' => true,
            'payload' => '',
        ]));
        $upload = $client->finish_request();
        $this->assertSame('complete', $upload['status'], (string) json_encode($upload));
        $this->assertSame(8, $upload['response']['changes_accepted']);
        $this->assertSame([
            'state' => 'complete',
            'type' => 'delete-list',
            'accepted_bytes' => strlen($delete_payload),
        ], $upload['response']['last_change']);

        $status = $client->control_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
            'path_b64' => base64_encode('nested/file.bin'),
        ], ['accepted']);
        $this->assertSame('complete', $status['status'], (string) json_encode($status));
        $this->assertSame('receiving_work', $status['response']['phase']);
        $this->assertSame(strlen($delete_payload), $status['response']['work_deletes_bytes']);
        $this->assertTrue($status['response']['work_deletes_complete']);
        $this->assertSame([
            'path_b64' => base64_encode('nested/file.bin'),
            'state' => 'complete',
            'type' => 'file',
            'accepted_bytes' => 8,
        ], $status['response']['path']);

        $commit_requests = 0;
        do {
            $commit = $client->control_request('POST', 'push_commit', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $commit['status'], (string) json_encode($commit));
            ++$commit_requests;
        } while ($commit['response']['send_next_request']);

        $this->assertGreaterThan(1, $commit_requests, 'The one-entry endpoint budget must require repeated commit calls.');
        $this->assertSame('complete', $commit['response']['phase']);
        $this->assertFileDoesNotExist($this->docroot . '/remove.txt');
        $this->assertSame("ab\0cdefg", file_get_contents($this->docroot . '/nested/file.bin'));
        $this->assertDirectoryExists($this->docroot . '/empty-directory');
        $this->assertSame([], array_values(array_diff(scandir($this->docroot . '/empty-directory') ?: [], ['.', '..'])));
        $this->assertTrue(is_link($this->docroot . '/file-link'));
        $this->assertSame('nested/file.bin', readlink($this->docroot . '/file-link'));
        $this->assertSame('keep', file_get_contents($this->docroot . '/preserved/value.txt'));

        do {
            $remove = $client->control_request('POST', 'push_remove', [
                'push_session_id' => $push_session_id,
            ], ['accepted']);
            $this->assertSame('complete', $remove['status'], (string) json_encode($remove));
        } while (!$remove['response']['removed']);
        $this->assertDirectoryDoesNotExist($this->reprint_directory . '/.reprint/push/' . $push_session_id);
    }

    public function testUploadReportsDeclaredRequestBodyLimitOn413(): void
    {
        $push_session_id = str_repeat('d', 32);
        $url = $this->base_url . '&endpoint=push_upload&push_session_id=' . $push_session_id;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: multipart/mixed; boundary=oversized-endpoint-test'];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => str_repeat('x', self::POST_MAX_BYTES + 1),
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(413, curl_getinfo($handle, CURLINFO_HTTP_CODE), $body);
        curl_close($handle);

        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'request_too_large',
            'detail' => 'The decoded request body declares 8193 bytes, exceeding the target post_max_size of 8192 bytes.',
            'post_max_bytes' => self::POST_MAX_BYTES,
        ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testChunkedUploadEnforcesDecodedRequestBodyLimitWhileStreaming(): void
    {
        $client = $this->newClient(self::SECRET);
        $push_session_id = str_repeat('f', 32);
        $create = $client->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status'], (string) json_encode($create));
        $this->assertSame(self::POST_MAX_BYTES, $create['response']['post_max_bytes']);

        // MultipartPushStreamClient uses CURLOPT_UPLOAD without a known body
        // length, so this reaches the production router as chunked transport.
        $this->assertTrue($client->start_upload_request($push_session_id));
        for ($part = 0; $part < 200; ++$part) {
            if (!$client->send_part([
                'type' => 'directory',
                'path' => 'request-limit-' . $part,
                'payload' => '',
            ])) {
                break;
            }
        }

        $upload = $client->finish_request();
        $this->assertGreaterThan(self::POST_MAX_BYTES, $upload['body_bytes_sent']);
        $this->assertSame('request_too_large', $upload['reason'], (string) json_encode($upload));
        $this->assertSame('The decoded request body reached 8193 bytes, exceeding the target post_max_size of 8192 bytes.', $upload['detail']);
        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'request_too_large',
            'detail' => 'The decoded request body reached 8193 bytes, exceeding the target post_max_size of 8192 bytes.',
            'post_max_bytes' => self::POST_MAX_BYTES,
        ], $upload['response']);
    }

    public function testLogicExceptionUsesGenericServerFailureResponse(): void
    {
        $endpoints = new Site_Export_Push_Endpoints([
            'reprint_directory' => $this->reprint_directory,
            'docroot' => $this->docroot,
            'excluded_paths' => [],
        ]);
        $respond_to_failure = new ReflectionMethod($endpoints, 'respond_to_failure');
        $respond_to_failure->setAccessible(true);
        http_response_code(200);
        ob_start();
        $respond_to_failure->invoke($endpoints, new LogicException('Internal multipart invariant failed.'));
        $body = (string) ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertSame([
            'status' => 'rejected',
            'reason' => 'filesystem_error',
            'detail' => 'The push endpoint failed while processing the request.',
        ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('invariant', $body);
    }

    public function testMalformedPredispatchCursorUsesInvalidRequestResponse(): void
    {
        $response = $this->newClient(self::SECRET)->control_request('POST', 'push_create', [
            'push_session_id' => str_repeat('9', 32),
            'cursor' => 'not-base64',
        ], ['created']);

        $this->assertSame('failed', $response['status']);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertSame('Cursor must be base64-encoded. Received invalid base64: not-base64', $response['detail']);
        $this->assertSame(400, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testEndpointConfigurationRejectsReprintDirectoryInsideDocumentRoot(): void
    {
        $symlink_parent = $this->root . '/symlink-parent';
        mkdir($symlink_parent, 0700);
        symlink($this->docroot, $symlink_parent . '/docroot-link');
        $reprint_directories = [
            $this->docroot,
            $this->docroot . '/missing-reprint-directory',
            $symlink_parent . '/docroot-link/missing-reprint-directory',
        ];

        foreach ($reprint_directories as $reprint_directory) {
            try {
                new Site_Export_Push_Endpoints([
                    'reprint_directory' => $reprint_directory,
                    'docroot' => $this->docroot,
                    'excluded_paths' => [],
                ]);
                $this->fail('Push endpoints accepted reprint_directory ' . json_encode($reprint_directory) . ' inside the document root.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Push endpoints require reprint_directory ' . json_encode($reprint_directory)
                    . ' to be outside docroot ' . json_encode($this->docroot) . '; observed it inside that document root.',
                    $exception->getMessage()
                );
            }
        }

        $configured_reprint_directory = $this->docroot . '/router-reprint-directory';
        file_put_contents($this->reprint_configuration_path, $configured_reprint_directory);
        $response = $this->newClient(self::SECRET)->control_request('POST', 'push_create', [
            'push_session_id' => str_repeat('e', 32),
        ], ['created']);
        $this->assertSame('failed', $response['status']);
        $this->assertSame('not_configured', $response['reason']);
        $canonical_docroot = realpath($this->docroot);
        $this->assertIsString($canonical_docroot);
        $this->assertSame(
            'Push endpoints require reprint_directory ' . json_encode($configured_reprint_directory)
            . ' to be outside docroot ' . json_encode($canonical_docroot) . '; observed it inside that document root.',
            $response['detail']
        );
        $this->assertSame(503, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testDefaultPushDirectoryIsSiblingOfCanonicalDocumentRoot(): void
    {
        file_put_contents($this->reprint_configuration_path, '');
        $push_session_id = str_repeat('7', 32);
        $response = $this->newClient(self::SECRET)->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);

        $this->assertSame('complete', $response['status'], (string) json_encode($response));
        $canonical_docroot = realpath($this->docroot);
        $this->assertIsString($canonical_docroot);
        $default_reprint_directory = dirname($canonical_docroot)
            . '/.reprint-' . substr(hash('sha256', $canonical_docroot), 0, 12);
        $this->assertDirectoryExists($default_reprint_directory . '/.reprint/push/' . $push_session_id);
    }

    public function testInvalidExcludedPathsConfigurationUsesNotConfiguredResponse(): void
    {
        file_put_contents($this->excluded_paths_configuration_path, json_encode(['../bad']));
        $response = $this->newClient(self::SECRET)->control_request('POST', 'push_create', [
            'push_session_id' => str_repeat('8', 32),
        ], ['created']);

        $this->assertSame('failed', $response['status']);
        $this->assertSame('not_configured', $response['reason']);
        $this->assertSame('Excluded path is unsafe: Li4vYmFk.', $response['detail']);
        $this->assertSame(503, $response['response']['http_code']);
        $this->assertArrayNotHasKey('trace', $response['response']);
    }

    public function testPullEndpointDoesNotValidatePushConfiguration(): void
    {
        file_put_contents($this->reprint_configuration_path, $this->docroot . '/invalid-push-directory');
        $url = $this->base_url . '&endpoint=preflight';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_curl_headers();
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(200, curl_getinfo($handle, CURLINFO_HTTP_CODE), $body);
        curl_close($handle);

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('ok', $response);
        $this->assertStringNotContainsString('Push endpoints require', $body);
    }

    public function testEndpointGuardsRejectWrongMethodContentTypeAndAuthentication(): void
    {
        $push_session_id = str_repeat('b', 32);
        $client = $this->newClient(self::SECRET);

        $wrong_method = $client->control_request('GET', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('failed', $wrong_method['status']);
        $this->assertSame('invalid_request', $wrong_method['reason']);
        $this->assertSame('Push endpoint requires POST; observed GET.', $wrong_method['detail']);

        $wrong_secret = $this->newClient('not-the-server-secret');
        $authentication = $wrong_secret->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('failed', $authentication['status']);
        $this->assertSame('auth_failed', $authentication['reason']);
        $this->assertStringContainsString('HMAC signature verification failed', $authentication['detail']);

        $create = $client->control_request('POST', 'push_create', [
            'push_session_id' => $push_session_id,
        ], ['created']);
        $this->assertSame('complete', $create['status']);
        $push_lock = fopen($this->reprint_directory . '/.reprint/push/' . $push_session_id . '/push.lock', 'c+b');
        $this->assertIsResource($push_lock);
        $this->assertTrue(flock($push_lock, LOCK_EX | LOCK_NB));
        $lock_contention = $client->control_request('GET', 'push_status', [
            'push_session_id' => $push_session_id,
        ], ['accepted']);
        $this->assertSame('retry', $lock_contention['status']);
        $this->assertSame('lock_acquisition_failure', $lock_contention['reason']);
        flock($push_lock, LOCK_UN);
        fclose($push_lock);

        $url = $this->base_url . '&endpoint=push_upload&push_session_id=' . $push_session_id;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertStringContainsString('multipart/mixed', $response['detail']);

        $boundary = 'truncated-endpoint-test';
        $truncated_body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . 'X-File-Path: ' . base64_encode('truncated.txt') . "\r\n"
            . "X-File-Size: 1\r\n"
            . "X-Chunk-Offset: 0\r\n"
            . "Content-Length: 1\r\n\r\n"
            . 'x';
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $curl_headers = ['Content-Type: multipart/mixed; boundary=' . $boundary];
        foreach ($headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $truncated_body,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($handle);
        $this->assertIsString($body);
        $this->assertSame(400, curl_getinfo($handle, CURLINFO_HTTP_CODE));
        curl_close($handle);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $response['reason']);
        $this->assertStringContainsString('multipart body ended before', $response['detail']);
    }

    private function newClient(string $secret): MultipartPushStreamClient
    {
        return new MultipartPushStreamClient([
            'base_url' => $this->base_url,
            'allow_http' => true,
            'hmac_client' => new Site_Export_HMAC_Client($secret),
            'chunk_bytes' => 4,
            'connect_timeout' => 3,
            'stall_timeout' => 3,
            'response_timeout' => 5,
        ]);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
