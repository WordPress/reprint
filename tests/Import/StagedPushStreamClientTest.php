<?php

namespace ImportTests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use Site_Export_Staged_Artifacts;
use StagedPushStreamClient;
use PushRequestSizer;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Drives the push stream client against a local PHP server that dispatches
 * the real staged endpoint routes. The pushAll()/pushOnce() helpers are the
 * reference caller loop: stream the local-paths journal line by line, read
 * each file in budget-sized pieces, rotate requests when the client says so,
 * and retry from the server-confirmed cursor.
 */
class StagedPushStreamClientTest extends TestCase
{
    private const SECRET = 'staged-push-stream-client-test-secret';

    private static string $server_root;
    private static string $router_path;
    private static string $config_path;
    private static string $request_log_path;
    private static string $base_url;

    /** @var resource|null */
    private static $server_process = null;

    private string $staging_dir;
    private string $source_dir;

    public static function setUpBeforeClass(): void
    {
        $suffix = bin2hex(random_bytes(8));
        self::$server_root = sys_get_temp_dir() . '/staged-push-stream-site-' . $suffix;
        self::$router_path = self::$server_root . '/router.php';
        self::$config_path = self::$server_root . '/endpoint-config.json';
        self::$request_log_path = self::$server_root . '/requests.jsonl';
        mkdir(self::$server_root, 0700, true);
        self::writeRouter();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail('Could not reserve a local test port: ' . $errstr);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr((string) $address, ':'), 1);
        self::$base_url = 'http://127.0.0.1:' . $port . '/?reprint-api=1';

        self::$server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', self::$server_root, self::$router_path],
            [0 => ['pipe', 'r'], 1 => ['file', self::$server_root . '/server.log', 'a'], 2 => ['file', self::$server_root . '/server.log', 'a']],
            $pipes,
            self::$server_root
        );
        if (!is_resource(self::$server_process)) {
            self::fail('Could not start the local staged endpoint server.');
        }
        fclose($pipes[0]);

        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:' . $port . '/__ping') === 'ok') {
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            self::tearDownAfterClass();
            self::fail('The local staged endpoint server did not start.');
        }
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
        $suffix = bin2hex(random_bytes(8));
        $this->staging_dir = sys_get_temp_dir() . '/staged-push-stream-' . $suffix;
        $this->source_dir = sys_get_temp_dir() . '/staged-push-source-' . $suffix;
        mkdir($this->source_dir, 0700, true);
        @unlink(self::$request_log_path);
        $this->configureEndpoint();
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->staging_dir);
        self::removeDirectory($this->source_dir);
    }

    public function testStreamsManyFilesThroughOneRequest(): void
    {
        $this->writeSource('wp-content/uploads/first.bin', str_repeat('a', 10));
        $this->writeSource('wp-content/uploads/second.bin', str_repeat('bc', 7));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([
            'wp-content/uploads/first.bin',
            'wp-content/uploads/second.bin',
        ]);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(2, $result['files_verified']);
        $this->assertSame(str_repeat('a', 10), file_get_contents($this->staging_dir . '/files/wp-content/uploads/first.bin'));
        $this->assertSame(str_repeat('bc', 7), file_get_contents($this->staging_dir . '/files/wp-content/uploads/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen(), 'all file chunks travel through one request');
    }

    public function testCursorCanResumeMidFileInTheNextPushStream(): void
    {
        $this->writeSource('first.bin', str_repeat('a', 12));
        $this->writeSource('second.bin', str_repeat('b', 12));
        (new Site_Export_Staged_Artifacts($this->staging_dir))->append('second.bin', 0, str_repeat('b', 4));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([
            'first.bin',
            'second.bin',
        ]);

        $result = $this->pushAll($client, $local_paths_to_push, ['artifact_id' => 'second.bin', 'committed_bytes' => 4]);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertFileDoesNotExist($this->staging_dir . '/files/first.bin', 'cursor skips files before the resumed artifact');
        $this->assertSame(str_repeat('b', 12), file_get_contents($this->staging_dir . '/files/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    public function testCallerCanPauseAfterOneChunkAndResumeFromTheCursor(): void
    {
        $this->writeSource('chunked.bin', str_repeat('x', 12));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['chunked.bin']);

        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'chunked.bin',
            'offset' => 0,
            'total_bytes' => 12,
            'final' => false,
            'payload' => str_repeat('x', 4),
        ]));
        $first_result = $client->finish_request();

        $this->assertSame('complete', $first_result['status'], (string) json_encode($first_result));
        $this->assertSame(1, $first_result['chunks_sent']);
        $this->assertSame(['artifact_id' => 'chunked.bin', 'committed_bytes' => 4], $first_result['cursor']);
        $this->assertSame(str_repeat('x', 4), file_get_contents($this->staging_dir . '/files/chunked.bin'));

        $result = $this->pushAll($client, $local_paths_to_push, $first_result['cursor']);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(str_repeat('x', 12), file_get_contents($this->staging_dir . '/files/chunked.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testBodyBudgetCountsFrameHeadersWhenRotatingRequests(): void
    {
        $this->writeSource('budget.bin', str_repeat('y', 10));
        $first_frame_header = json_encode([
            'type' => 'chunk',
            'artifact_id' => 'budget.bin',
            'offset' => 0,
            'bytes' => 4,
            'total_bytes' => 10,
            'final' => false,
        ], JSON_UNESCAPED_SLASHES) . "\n";
        // Budget for one full frame plus 3 spare bytes, so the value of
        // next_chunk_bytes() after the first chunk reveals whether the frame
        // header was charged against the budget alongside the payload.
        $request_body_budget = strlen($first_frame_header) + 4 + 3;
        $client = $this->makeClient([
            'request_sizer' => new PushRequestSizer([
                'floor_bytes' => 4,
                'start_bytes' => $request_body_budget,
                'max_bytes' => $request_body_budget,
            ]),
        ]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['budget.bin']);

        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'budget.bin',
            'offset' => 0,
            'total_bytes' => 10,
            'final' => false,
            'payload' => 'yyyy',
        ]));

        $this->assertSame(3, $client->next_chunk_bytes(), 'the frame header was charged against the budget, not only the payload');
        $this->assertFalse($client->should_finish_request());

        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'budget.bin',
            'offset' => 4,
            'total_bytes' => 10,
            'final' => false,
            'payload' => 'yyy',
        ]));
        $this->assertSame(0, $client->next_chunk_bytes(), 'the second frame header spends the rest of the budget');
        $this->assertTrue($client->should_finish_request());

        $rotation_result = $client->finish_request();
        $this->assertSame('complete', $rotation_result['status'], (string) json_encode($rotation_result));
        $this->assertSame(['artifact_id' => 'budget.bin', 'committed_bytes' => 7], $rotation_result['cursor']);

        $result = $this->pushAll($client, $local_paths_to_push, $rotation_result['cursor']);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(str_repeat('y', 10), file_get_contents($this->staging_dir . '/files/budget.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testTimeBudgetRotatesRequests(): void
    {
        $this->writeSource('timed.bin', str_repeat('t', 8));
        $client = $this->makeClient(['max_request_seconds' => 0.05]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['timed.bin']);

        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'timed.bin',
            'offset' => 0,
            'total_bytes' => 8,
            'final' => false,
            'payload' => 'tttt',
        ]));
        usleep(80000);
        $this->assertTrue($client->should_finish_request(), 'an open request older than max_request_seconds asks to be finished');

        $rotation_result = $client->finish_request();
        $this->assertSame('complete', $rotation_result['status'], (string) json_encode($rotation_result));

        $result = $this->pushAll($client, $local_paths_to_push, $rotation_result['cursor']);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(str_repeat('t', 8), file_get_contents($this->staging_dir . '/files/timed.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testRetryFromTheBeginningSkipsAlreadyVerifiedFilesAndDuplicateBytes(): void
    {
        $this->writeSource('first.bin', str_repeat('a', 8));
        $this->writeSource('second.bin', str_repeat('b', 8));
        $store = new Site_Export_Staged_Artifacts($this->staging_dir);
        $store->append('first.bin', 0, str_repeat('a', 8));
        $store->finalize('first.bin', 8);
        $store->append('second.bin', 0, str_repeat('b', 4));
        $client = $this->makeClient([
            'chunk_bytes' => 8,
        ]);
        $local_paths_to_push = $this->writeLocalPathsToPush([
            'first.bin',
            'second.bin',
        ]);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(str_repeat('a', 8), file_get_contents($this->staging_dir . '/files/first.bin'));
        $this->assertSame(str_repeat('b', 8), file_get_contents($this->staging_dir . '/files/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    public function testFrameTooLargeShrinksTheBodyBudgetAndRetries(): void
    {
        $this->writeSource('large.bin', str_repeat('x', 20));
        $this->configureEndpoint(['max_request_bytes' => 6]);
        $sizer = new PushRequestSizer(['floor_bytes' => 4, 'start_bytes' => 12, 'max_bytes' => 12]);
        $client = $this->makeClient(['request_sizer' => $sizer, 'chunk_bytes' => 12]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['large.bin']);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result));
        $this->assertSame(str_repeat('x', 20), file_get_contents($this->staging_dir . '/files/large.bin'));
        // The 413 caps the body budget at the reported 6 * 0.9 = 5 bytes, so
        // after the rejected first request the remaining 20 bytes travel as
        // four one-frame requests, chunks sized down to the capacity.
        $this->assertSame(array_fill(0, 5, 'staged_push'), $this->endpointsSeen());
        $this->assertLessThanOrEqual(5, $sizer->request_body_bytes());
    }

    public function testWrongSecretFailsBeforeReadingTheBody(): void
    {
        $this->writeSource('secret.bin', 'secret');
        $client = $this->makeClient([
            'hmac_client' => new Site_Export_HMAC_Client('wrong-secret'),
        ]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['secret.bin']);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame(['failed', 'auth_failed'], [$result['status'], $result['reason']], (string) json_encode($result));
        $this->assertFileDoesNotExist($this->staging_dir . '/files/secret.bin');
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    public function testSendChunkWritesBytesToTheNetworkBeforeTheRequestIsFinalized(): void
    {
        // A raw TCP listener instead of the shared endpoint server, so the
        // test can observe exactly when request bytes reach the network.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, (string) $errstr);
        $listener_address = stream_socket_get_name($listener, false);

        $client = new StagedPushStreamClient([
            'base_url' => 'http://' . $listener_address . '/?reprint-api=1',
            'chunk_bytes' => 4,
        ]);

        $pending_connections = [$listener];
        $write_sockets = null;
        $except_sockets = null;
        $this->assertSame(0, stream_select($pending_connections, $write_sockets, $except_sockets, 0, 0), 'constructing a client must not open a connection');

        $this->assertTrue($client->start_push_request());
        $connection = stream_socket_accept($listener, 5);
        $this->assertNotFalse($connection);
        $received = $this->readAvailableBytes($connection);
        $this->assertStringContainsString('POST /?reprint-api=1&endpoint=staged_push HTTP/1.1', $received, 'the request head is on the wire right after start_push_request()');

        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'streamed.bin',
            'offset' => 0,
            'total_bytes' => 8,
            'final' => false,
            'payload' => 'ssss',
        ]));
        $received .= $this->readAvailableBytes($connection);
        $this->assertStringContainsString('"artifact_id":"streamed.bin","offset":0,"bytes":4', $received);
        $this->assertStringContainsString('ssss', $received, 'the first frame payload is on the wire before the request is finalized');
        $this->assertStringNotContainsString("0\r\n\r\n", $received, 'the request body is still open');

        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'streamed.bin',
            'offset' => 4,
            'total_bytes' => 8,
            'final' => true,
            'payload' => 'ssss',
        ]));
        $received .= $this->readAvailableBytes($connection);
        $this->assertStringContainsString('"artifact_id":"streamed.bin","offset":4,"bytes":4', $received);
        $this->assertSame(2, substr_count($received, 'ssss'), 'the second frame payload followed without finalizing');

        // Drop the connection without responding: the failure must surface as
        // a retryable request-level result, not an exception.
        fclose($connection);
        fclose($listener);
        $result = $client->finish_request();

        $this->assertSame(['retry', 'request_failed'], [$result['status'], $result['reason']], (string) json_encode($result));
        $this->assertSame(2, $result['chunks_sent']);
        $this->assertGreaterThan(8, $result['body_bytes_sent'], 'body accounting includes the frame headers');
    }

    public function testInvalidChunksThrowSpecificErrors(): void
    {
        $client = $this->makeClient();
        $invalid_chunks = [
            [
                ['artifact_id' => 'a.bin', 'offset' => 8, 'total_bytes' => 10, 'final' => false, 'payload' => 'zzzz'],
                'Chunk for "a.bin" spans bytes 8-12, which exceeds total_bytes 10.',
            ],
            [
                ['artifact_id' => 'a.bin', 'offset' => 4, 'total_bytes' => 10, 'final' => false, 'payload' => ''],
                'Refusing a zero-byte non-final chunk for "a.bin" — the source file is shorter than its declared total_bytes 10.',
            ],
            [
                ['artifact_id' => 'a.bin', 'offset' => 4, 'total_bytes' => 10, 'final' => true, 'payload' => 'zz'],
                'Chunk for "a.bin" is marked final at byte 6 but total_bytes is 10.',
            ],
        ];

        foreach ($invalid_chunks as [$chunk, $expected_message]) {
            try {
                $client->send_chunk($chunk);
                $this->fail('Expected an InvalidArgumentException for: ' . (string) json_encode($chunk));
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($expected_message, $exception->getMessage());
            }
        }
    }

    private static function writeRouter(): void
    {
        $import_path = addslashes(realpath(__DIR__ . '/../../packages/reprint-importer/src/import.php'));
        $config_path = addslashes(self::$config_path);
        $request_log_path = addslashes(self::$request_log_path);

        file_put_contents(self::$router_path, <<<PHP_ROUTER
<?php
// PHP 8.1 emits the required CLI script's shebang; keep test HTTP responses clean.
ob_start();
require_once '{$import_path}';
ob_end_clean();

if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}

\$config = json_decode((string) file_get_contents('{$config_path}'), true);
if (!is_array(\$config)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing endpoint config']);
    return true;
}

file_put_contents(
    '{$request_log_path}',
    json_encode([
        'endpoint' => (string) ( \$_GET['endpoint'] ?? '' ),
        'method' => \$_SERVER['REQUEST_METHOD'] ?? '',
        'content_length' => \$_SERVER['CONTENT_LENGTH'] ?? null,
    ]) . "\n",
    FILE_APPEND
);

\$server = new Site_Export_HTTP_Server([
    'staged' => [
        'staging_dir' => (string) \$config['staging_dir'],
        'secret' => (string) \$config['secret'],
        'max_request_bytes' => (int) ( \$config['max_request_bytes'] ?? 1073741824 ),
    ],
]);
\$server->handle_request();
return true;
PHP_ROUTER);
    }

    private function configureEndpoint(array $overrides = []): void
    {
        file_put_contents(self::$config_path, json_encode(array_merge([
            'staging_dir' => $this->staging_dir,
            'secret' => self::SECRET,
            'max_request_bytes' => 1073741824,
        ], $overrides)));
    }

    private function makeClient(array $overrides = []): StagedPushStreamClient
    {
        return new StagedPushStreamClient(array_merge([
            'base_url' => self::$base_url,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 4,
        ], $overrides));
    }

    /**
     * Push everything, retrying failed requests from the server-confirmed
     * cursor the way the push command will.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int}
     */
    private function pushAll(StagedPushStreamClient $client, string $local_paths_to_push, ?array $cursor = null): array
    {
        $result = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $this->pushOnce($client, $local_paths_to_push, $cursor);
            if ($result['status'] !== 'retry') {
                return $result;
            }
            $cursor = $result['cursor'];
        }
        return $result;
    }

    /**
     * One pass over the journal from $cursor: the caller loop the client is
     * designed for. Streams journal lines, reads each file in pieces sized by
     * next_chunk_bytes(), and rotates requests when the client says to.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int}
     */
    private function pushOnce(StagedPushStreamClient $client, string $local_paths_to_push, ?array $cursor): array
    {
        $journal_handle = fopen($local_paths_to_push, 'r');
        $this->assertNotFalse($journal_handle);
        if (false === $client->start_push_request()) {
            fclose($journal_handle);
            return $this->startFailureResult($client, $cursor);
        }

        $skipping_to_cursor = is_array($cursor) && isset($cursor['artifact_id']);
        while (($journal_line = fgets($journal_handle)) !== false) {
            $journal_line = trim($journal_line);
            if ($journal_line === '') {
                continue;
            }
            $decoded_line = json_decode($journal_line, true, 512, JSON_THROW_ON_ERROR);
            $artifact_id = (string) base64_decode((string) $decoded_line['path'], true);

            if ($skipping_to_cursor && $artifact_id !== $cursor['artifact_id']) {
                continue;
            }
            $offset = $skipping_to_cursor ? (int) $cursor['committed_bytes'] : 0;
            $skipping_to_cursor = false;

            $source_handle = fopen($this->source_dir . '/' . $artifact_id, 'rb');
            $this->assertNotFalse($source_handle);
            $total_bytes = fstat($source_handle)['size'];
            if ($total_bytes > 0 && $offset >= $total_bytes) {
                fclose($source_handle);
                continue;
            }
            if ($offset > 0) {
                fseek($source_handle, $offset);
            }

            while (true) {
                if ($client->should_finish_request()) {
                    $result = $client->finish_request();
                    if ($result['status'] !== 'complete') {
                        fclose($source_handle);
                        fclose($journal_handle);
                        return $result;
                    }
                    if (false === $client->start_push_request()) {
                        fclose($source_handle);
                        fclose($journal_handle);
                        return $this->startFailureResult($client, $result['cursor']);
                    }
                }

                $payload = $total_bytes === 0
                    ? ''
                    : (string) fread($source_handle, $client->next_chunk_bytes());
                $final = $offset + strlen($payload) >= $total_bytes;

                if (!$client->send_chunk([
                    'artifact_id' => $artifact_id,
                    'offset' => $offset,
                    'total_bytes' => $total_bytes,
                    'final' => $final,
                    'payload' => $payload,
                ])) {
                    fclose($source_handle);
                    fclose($journal_handle);
                    return $client->finish_request();
                }

                $offset += strlen($payload);
                if ($final) {
                    break;
                }
            }
            fclose($source_handle);
        }
        fclose($journal_handle);

        return $client->finish_request();
    }

    /** @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int} */
    private function startFailureResult(StagedPushStreamClient $client, ?array $cursor): array
    {
        return [
            'status' => 'retry',
            'reason' => 'request_failed',
            'detail' => $client->get_last_error(),
            'cursor' => $cursor,
            'files_verified' => 0,
            'chunks_sent' => 0,
            'body_bytes_sent' => 0,
        ];
    }

    /**
     * Read whatever bytes have already arrived on the connection, allowing a
     * brief settle so kernel-buffered writes from the client become visible.
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

    private function writeSource(string $name, string $body): string
    {
        $path = $this->source_dir . '/' . $name;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $body);
        return $path;
    }

    /** @param string[] $artifact_ids */
    private function writeLocalPathsToPush(array $artifact_ids): string
    {
        $path = $this->source_dir . '/local-paths-to-push.jsonl';
        $body = '';
        foreach ($artifact_ids as $artifact_id) {
            $body .= json_encode(['path' => base64_encode($artifact_id)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $body);
        return $path;
    }

    /** @return string[] */
    private function endpointsSeen(): array
    {
        if (!file_exists(self::$request_log_path)) {
            return [];
        }
        $endpoints = [];
        foreach (file(self::$request_log_path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $endpoints[] = (string) ($decoded['endpoint'] ?? '');
            }
        }
        return $endpoints;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $directory_entry) {
            is_dir($directory_entry) ? self::removeDirectory($directory_entry) : @unlink($directory_entry);
        }
        @rmdir($directory);
    }
}
