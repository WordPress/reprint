<?php

namespace ImportTests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PushRequestSizer;
use RuntimeException;
use Site_Export_HMAC_Client;
use Site_Export_Staged_Push_Stream_Protocol;
use StagedPushStreamClient;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Exercises the streaming client against a small real HTTP peer. The peer
 * records the request body but deliberately knows nothing about target
 * staging, so these tests lock down the wire and client cursor behavior
 * without creating a second fake implementation of the apply session.
 */
class StagedPushStreamClientTest extends TestCase
{
    private const SECRET = 'staged-push-stream-client-test-secret';
    private const SESSION_ID = '0123456789abcdef0123456789abcdef';

    private static string $server_root;
    private static string $router_path;
    private static string $config_path;
    private static string $request_log_path;
    private static string $base_url;

    /** @var resource|null */
    private static $server_process = null;

    public static function setUpBeforeClass(): void
    {
        self::$server_root = sys_get_temp_dir() . '/direct-push-wire-' . bin2hex(random_bytes(8));
        self::$router_path = self::$server_root . '/router.php';
        self::$config_path = self::$server_root . '/config.json';
        self::$request_log_path = self::$server_root . '/requests.jsonl';
        mkdir(self::$server_root, 0700, true);
        self::writeRouter();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        if ($socket === false) {
            self::fail('Could not reserve a local test port: ' . $error_message);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr((string) $address, ':'), 1);
        self::$base_url = 'http://127.0.0.1:' . $port . '/?reprint-api=1';

        self::$server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', self::$server_root, self::$router_path],
            [
                0 => ['pipe', 'r'],
                1 => ['file', self::$server_root . '/server.log', 'a'],
                2 => ['file', self::$server_root . '/server.log', 'a'],
            ],
            $pipes,
            self::$server_root
        );
        if (!is_resource(self::$server_process)) {
            self::fail('Could not start the local staged push wire server.');
        }
        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:' . $port . '/__ping') === 'ok') {
                return;
            }
            usleep(100000);
        }
        self::tearDownAfterClass();
        self::fail('The local staged push wire server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server_process)) {
            proc_terminate(self::$server_process);
            proc_close(self::$server_process);
            self::$server_process = null;
        }
        if (isset(self::$server_root)) {
            self::removeDirectory(self::$server_root);
        }
    }

    protected function setUp(): void
    {
        @unlink(self::$request_log_path);
        $this->configureResponse([
            'status' => 'complete',
            'operation_count' => 0,
            'current_file' => null,
        ]);
    }

    public function testStreamsTypedOperationsAndFileChunksThroughOneRequest(): void
    {
        $this->configureResponse([
            'status' => 'complete',
            'operation_count' => 4,
            'current_file' => null,
        ]);
        $client = $this->makeClient(['chunk_bytes' => 4]);
        $client->set_session_request_generation(6);
        $this->assertTrue($client->start_push_request());

        $operations = [
            ['type' => 'directory', 'operation_index' => 0, 'path' => 'uploads'],
            [
                'type' => 'file',
                'operation_index' => 1,
                'path' => "uploads/image-\xff.bin",
                'revision' => 0,
                'offset' => 0,
                'total_bytes' => 6,
                'restart' => false,
                'payload' => 'abcd',
            ],
            [
                'type' => 'file',
                'operation_index' => 1,
                'path' => "uploads/image-\xff.bin",
                'revision' => 0,
                'offset' => 4,
                'total_bytes' => 6,
                'restart' => false,
                'payload' => 'ef',
            ],
            ['type' => 'symlink', 'operation_index' => 2, 'path' => 'latest', 'target' => 'uploads'],
            ['type' => 'delete', 'operation_index' => 3, 'path' => 'old-cache'],
        ];
        foreach ($operations as $operation) {
            $this->assertTrue($client->send_operation($operation));
        }

        $result = $client->finish_request();

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(4, $result['operation_count']);
        $this->assertNull($result['current_file']);
        $this->assertSame(7, $result['request_generation']);
        $this->assertSame(5, $result['frames_sent']);

        $request = $this->requestLogEntries()[0];
        $this->assertSame('staged_session_push', $request['endpoint']);
        $this->assertSame(self::SESSION_ID, $request['session_id']);
        $this->assertSame('6', $request['expected_request_generation']);
        $this->assertSame(Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE, $request['content_type']);
        $body = base64_decode($request['body_b64'], true);
        $this->assertIsString($body);
        $this->assertSame(strlen($body), $result['body_bytes_sent'], 'header lines and payloads are both charged');

        $frames = $this->decodeFrames($body);
        $this->assertSame(['directory', 'file', 'file', 'symlink', 'delete'], array_column($frames, 'type'));
        $this->assertSame(['', 'abcd', 'ef', '', ''], array_column($frames, 'payload'));
        $this->assertSame("uploads/image-\xff.bin", $frames[1]['path']);
        $this->assertSame(1, $frames[2]['operation_index'], 'file chunks keep one operation index');
    }

    public function testResponseReturnsOnlyTargetConfirmedOperationAndFileCursors(): void
    {
        $current_path = "uploads/partial-\xfe.bin";
        $this->configureResponse([
            'status' => 'complete',
            'operation_count' => 3,
            'current_file' => [
                'operation_index' => 3,
                'path_b64' => base64_encode($current_path),
                'revision' => 8,
                'committed_bytes' => 12,
                'total_bytes' => 20,
            ],
        ]);
        $client = $this->makeClient();
        $client->set_session_request_generation(2);
        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_operation([
            'type' => 'file',
            'operation_index' => 3,
            'path' => $current_path,
            'revision' => 8,
            'offset' => 8,
            'total_bytes' => 20,
            'restart' => false,
            'payload' => '1234',
        ]));

        $result = $client->finish_request();

        $this->assertSame(3, $result['operation_count']);
        $this->assertSame([
            'operation_index' => 3,
            'path' => $current_path,
            'revision' => 8,
            'committed_bytes' => 12,
            'total_bytes' => 20,
        ], $result['current_file']);
        $this->assertSame(3, $result['request_generation']);
    }

    public function testRecoverableStreamFailuresPreserveConfirmedState(): void
    {
        foreach (['operation_gap', 'offset_gap', 'body_read_failed'] as $reason) {
            @unlink(self::$request_log_path);
            $this->configureResponse([
                'http_code' => $reason === 'body_read_failed' ? 400 : 409,
                'status' => 'rejected',
                'reason' => $reason,
                'detail' => 'sender is ahead',
                'operation_count' => 5,
                'current_file' => null,
            ]);
            $client = $this->makeClient();
            $client->set_session_request_generation(0);
            $this->assertTrue($client->start_push_request());
            $this->assertTrue($client->send_operation([
                'type' => 'delete',
                'operation_index' => 8,
                'path' => 'ahead',
            ]));

            $result = $client->finish_request();
            $this->assertSame(['retry', $reason, 5], [
                $result['status'],
                $result['reason'],
                $result['operation_count'],
            ]);
        }
    }

    public function testFrameTooLargeCapsLaterFilePayloadsButFileSizeRejectionDoesNot(): void
    {
        $request_sizer = new PushRequestSizer([
            'floor_bytes' => 1,
            'start_bytes' => 64,
            'max_bytes' => 64,
        ]);
        $this->configureResponse([
            'http_code' => 413,
            'status' => 'rejected',
            'reason' => 'frame_too_large',
            'max_frame_bytes' => 2,
            'operation_count' => 0,
            'current_file' => null,
        ]);
        $client = $this->makeClient([
            'chunk_bytes' => 8,
            'request_sizer' => $request_sizer,
        ]);
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_operation([
            'type' => 'file',
            'operation_index' => 0,
            'path' => 'large.bin',
            'revision' => 0,
            'offset' => 0,
            'total_bytes' => 4,
            'restart' => false,
            'payload' => '1234',
        ]));
        $result = $client->finish_request();
        $this->assertSame(['retry', 'frame_too_large', 2], [
            $result['status'],
            $result['reason'],
            $result['max_frame_bytes'],
        ]);

        $this->configureResponse([
            'http_code' => 409,
            'status' => 'rejected',
            'reason' => 'size_exceeded',
            'operation_count' => 0,
            'current_file' => null,
        ]);
        $client->set_session_request_generation((int) $result['request_generation']);
        $this->assertTrue($client->start_push_request());
        $this->assertSame(2, $client->next_chunk_body_bytes());
        $this->assertTrue($client->send_operation([
            'type' => 'file',
            'operation_index' => 0,
            'path' => 'large.bin',
            'revision' => 0,
            'offset' => 0,
            'total_bytes' => 2,
            'restart' => false,
            'payload' => '12',
        ]));
        $result = $client->finish_request();
        $this->assertSame(['failed', 'size_exceeded'], [$result['status'], $result['reason']]);
        $this->assertSame(64, $request_sizer->request_body_bytes(), 'a file range error is not request-size evidence');
    }

    public function testRawHttp413PermanentlyLowersTheRequestBodyBudget(): void
    {
        $request_sizer = new PushRequestSizer([
            'floor_bytes' => 128,
            'start_bytes' => 1024,
            'max_bytes' => 1024,
        ]);
        $this->configureResponse(['raw_413' => true]);
        $client = $this->makeClient(['request_sizer' => $request_sizer]);
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_operation([
            'type' => 'delete',
            'operation_index' => 0,
            'path' => 'old',
        ]));

        $result = $client->finish_request();

        $this->assertSame(['retry', 'request_too_large'], [$result['status'], $result['reason']]);
        $this->assertSame(512, $request_sizer->request_body_bytes());
    }

    public function testMetadataOnlyStreamsRotateAtTheSharedFrameCap(): void
    {
        $this->configureResponse([
            'status' => 'complete',
            'operation_count' => Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST,
            'current_file' => null,
        ]);
        $client = $this->makeClient();
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());

        for ($index = 0; $index < Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST; $index++) {
            $this->assertTrue($client->send_operation([
                'type' => 'delete',
                'operation_index' => $index,
                'path' => 'path-' . $index,
            ]));
        }

        $this->assertTrue($client->should_finish_request());
        $result = $client->finish_request();
        $this->assertSame(Site_Export_Staged_Push_Stream_Protocol::MAX_FRAMES_PER_REQUEST, $result['frames_sent']);
        $this->assertLessThan(1024 * 1024, $result['body_bytes_sent']);
    }

    /**
     * @dataProvider invalidResponseProvider
     */
    public function testMalformedConfirmedStateIsTerminal(array $response, string $expected_detail): void
    {
        $this->configureResponse($response);
        $client = $this->makeClient();
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());

        $result = $client->finish_request();

        $this->assertSame(['failed', 'invalid_session_response'], [$result['status'], $result['reason']]);
        $this->assertStringContainsString($expected_detail, (string) $result['detail']);
    }

    public static function invalidResponseProvider(): array
    {
        return [
            'missing operation count' => [[
                'status' => 'complete',
                'current_file' => null,
            ], 'omitted its server-confirmed operation_count'],
            'string operation count' => [[
                'status' => 'complete',
                'operation_count' => '0',
                'current_file' => null,
            ], 'invalid operation_count "0"'],
            'non-base64 current path' => [[
                'status' => 'complete',
                'operation_count' => 0,
                'current_file' => [
                    'operation_index' => 0,
                    'path_b64' => '!!!',
                    'revision' => 0,
                    'committed_bytes' => 0,
                    'total_bytes' => 1,
                ],
            ], 'current_file.path_b64'],
            'cursor beyond file' => [[
                'status' => 'complete',
                'operation_count' => 0,
                'current_file' => [
                    'operation_index' => 0,
                    'path_b64' => base64_encode('file'),
                    'revision' => 0,
                    'committed_bytes' => 2,
                    'total_bytes' => 1,
                ],
            ], 'committed_bytes 2 beyond total_bytes 1'],
            'different session' => [[
                'status' => 'complete',
                'session_id' => str_repeat('b', 32),
                'operation_count' => 0,
                'current_file' => null,
            ], 'did not name the requested session'],
            'complete on server error' => [[
                'http_code' => 500,
                'status' => 'complete',
                'operation_count' => 0,
                'current_file' => null,
            ], 'reported complete with HTTP 500'],
        ];
    }

    public function testGenerationMustBeSetForEveryRequest(): void
    {
        $client = $this->makeClient();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires set_session_request_generation');

        $client->start_push_request();
    }

    public function testGenerationCannotChangeWhileARequestIsOpen(): void
    {
        $client = $this->makeClient();
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());

        try {
            $client->set_session_request_generation(1);
            $this->fail('Expected changing an open request generation to throw.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('while a push request is open', $error->getMessage());
        } finally {
            $client->abort_push_request();
        }
    }

    public function testSendOperationPerformsNetworkIoBeforeReturning(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertNotFalse($listener, (string) $error_message);
        $listener_address = stream_socket_get_name($listener, false);
        $client = $this->makeClient([
            'base_url' => 'http://' . $listener_address . '/?reprint-api=1',
        ]);

        $pending_connections = [$listener];
        $write_sockets = null;
        $except_sockets = null;
        $this->assertSame(0, stream_select($pending_connections, $write_sockets, $except_sockets, 0, 0));

        $client->set_session_request_generation(9);
        $this->assertTrue($client->start_push_request());
        $connection = stream_socket_accept($listener, 5);
        $this->assertNotFalse($connection);
        $received = $this->readAvailableBytes($connection);
        $this->assertStringContainsString('expected_request_generation=9 HTTP/1.1', $received);

        $this->assertTrue($client->send_operation([
            'type' => 'file',
            'operation_index' => 2,
            'path' => 'streamed.bin',
            'revision' => 3,
            'offset' => 0,
            'total_bytes' => 4,
            'restart' => true,
            'payload' => 'data',
        ]));
        $received .= $this->readAvailableBytes($connection);
        $this->assertStringContainsString('"type":"file","operation_index":2', $received);
        $this->assertStringContainsString('"revision":3,"offset":0,"bytes":4', $received);
        $this->assertStringContainsString('data', $received, 'the payload reached the socket before finish_request');
        $this->assertStringNotContainsString("0\r\n\r\n", $received, 'the request body remains open');

        fclose($connection);
        fclose($listener);
        $result = $client->finish_request();
        $this->assertSame(['retry', 'request_failed'], [$result['status'], $result['reason']]);
        $this->assertSame(1, $result['frames_sent']);
        $this->assertGreaterThan(4, $result['body_bytes_sent']);
    }

    public function testValidOperationRequiresAnOpenRequest(): void
    {
        $client = $this->makeClient();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No push request is open');

        $client->send_operation([
            'type' => 'directory',
            'operation_index' => 0,
            'path' => 'uploads',
        ]);
    }

    public function testInvalidOperationIsRejectedBeforeOpeningANetworkRequest(): void
    {
        $client = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zero payload bytes must be positioned at total_bytes');

        $client->send_operation([
            'type' => 'file',
            'operation_index' => 0,
            'path' => 'short.bin',
            'revision' => 0,
            'offset' => 0,
            'total_bytes' => 1,
            'restart' => false,
            'payload' => '',
        ]);
    }

    public function testPlainHttpRequiresAnExplicitOptOut(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to push over plain HTTP');

        $this->makeClient(['allow_http' => false]);
    }

    public function testPresentOverlargeChunkIsNotSilentlyAccepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 4194304 bytes');

        $this->makeClient(['chunk_bytes' => 4194305]);
    }

    public function testFilePayloadCannotExceedTheOpenRequestsBoundedChunkAllowance(): void
    {
        $client = $this->makeClient(['chunk_bytes' => 4]);
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());
        try {
            $client->send_operation([
                'type' => 'file',
                'operation_index' => 0,
                'path' => 'file',
                'revision' => 0,
                'offset' => 0,
                'total_bytes' => 5,
                'restart' => false,
                'payload' => 'abcde',
            ]);
            $this->fail('Expected oversized direct payload rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exceeding the next bounded chunk allowance of 4', $exception->getMessage());
        } finally {
            $client->abort_push_request();
        }
    }

    public function testOversizedRemoteResponseIsBoundedAndTerminal(): void
    {
        $this->configureResponse(['oversized_response_bytes' => 65537]);
        $client = $this->makeClient();
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());

        $result = $client->finish_request();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('response_too_large', $result['reason']);
        $this->assertStringContainsString('exceeded 65536 bytes', $result['detail']);
    }

    public function testEmptyRequestDoesNotTeachTheRequestSizerToGrow(): void
    {
        $request_sizer = new PushRequestSizer([
            'floor_bytes' => 64,
            'start_bytes' => 128,
            'max_bytes' => 1024,
        ]);
        $client = $this->makeClient(['request_sizer' => $request_sizer]);
        $client->set_session_request_generation(0);
        $this->assertTrue($client->start_push_request());

        $result = $client->finish_request();

        $this->assertSame('complete', $result['status']);
        $this->assertSame(0, $result['frames_sent']);
        $this->assertSame(128, $request_sizer->request_body_bytes());
    }

    private static function writeRouter(): void
    {
        $config_path = addslashes(self::$config_path);
        $request_log_path = addslashes(self::$request_log_path);
        $router = <<<'PHP_ROUTER'
<?php
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}

$config = json_decode((string) @file_get_contents('__CONFIG_PATH__'), true);
if (!is_array($config)) {
    http_response_code(500);
    echo 'missing config';
    return true;
}

$body = (string) file_get_contents('php://input');
file_put_contents(
    '__REQUEST_LOG_PATH__',
    json_encode([
        'endpoint' => (string) ( $_GET['endpoint'] ?? '' ),
        'session_id' => isset($_GET['session_id']) ? (string) $_GET['session_id'] : null,
        'expected_request_generation' => isset($_GET['expected_request_generation'])
            ? (string) $_GET['expected_request_generation']
            : null,
        'content_type' => (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ),
        'body_b64' => base64_encode($body),
    ]) . "\n",
    FILE_APPEND | LOCK_EX
);

if (($config['raw_413'] ?? false) === true) {
    http_response_code(413);
    header('Content-Type: text/html');
    echo '<html>too large</html>';
    return true;
}

if (isset($config['oversized_response_bytes'])) {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo str_repeat('x', (int) $config['oversized_response_bytes']);
    return true;
}

$response = $config;
unset($response['http_code'], $response['raw_413']);
$response['session_id'] = array_key_exists('session_id', $response)
    ? $response['session_id']
    : (string) ( $_GET['session_id'] ?? '' );
$response['request_generation'] = array_key_exists('request_generation', $response)
    ? $response['request_generation']
    : (int) ( $_GET['expected_request_generation'] ?? 0 ) + 1;
http_response_code((int) ( $config['http_code'] ?? 200 ));
header('Content-Type: application/json');
echo json_encode($response);
return true;
PHP_ROUTER
        ;
        file_put_contents(self::$router_path, str_replace(
            ['__CONFIG_PATH__', '__REQUEST_LOG_PATH__'],
            [$config_path, $request_log_path],
            $router
        ));
    }

    private function configureResponse(array $response): void
    {
        file_put_contents(self::$config_path, json_encode($response));
    }

    private function makeClient(array $overrides = []): StagedPushStreamClient
    {
        return new StagedPushStreamClient(array_merge([
            'base_url' => self::$base_url,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'session_id' => self::SESSION_ID,
            'chunk_bytes' => 8,
            'allow_http' => true,
        ], $overrides));
    }

    /** @return array<int,array<string,mixed>> */
    private function decodeFrames(string $body): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        $frames = [];
        while (($line = Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream)) !== null) {
            $frame = Site_Export_Staged_Push_Stream_Protocol::decode_operation_header($line);
            $payload = Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, (int) ($frame['bytes'] ?? 0));
            $this->assertNotNull($payload);
            $frame['payload'] = $payload;
            $frames[] = $frame;
        }
        fclose($stream);
        return $frames;
    }

    /** @return array<int,array<string,mixed>> */
    private function requestLogEntries(): array
    {
        $entries = [];
        foreach (file(self::$request_log_path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Read bytes that have already arrived, with a short settle for data still
     * moving from libcurl's upload buffer into the kernel socket buffer.
     *
     * @param resource $connection
     */
    private function readAvailableBytes($connection): string
    {
        stream_set_blocking($connection, false);
        $received = '';
        $last_data_at = microtime(true);
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $piece = fread($connection, 65536);
            if (is_string($piece) && $piece !== '') {
                $received .= $piece;
                $last_data_at = microtime(true);
                continue;
            }
            if ($received !== '' && microtime(true) - $last_data_at > 0.1) {
                break;
            }
            usleep(5000);
        }
        return $received;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? self::removeDirectory($entry) : @unlink($entry);
        }
        @rmdir($directory);
    }
}
