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

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
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

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
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

        $this->assertSame('complete', $first_result['status'], (string) json_encode($first_result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(1, $first_result['chunks_sent']);
        $this->assertSame(['artifact_id' => 'chunked.bin', 'committed_bytes' => 4], $first_result['cursor']);
        $this->assertSame(str_repeat('x', 4), file_get_contents($this->staging_dir . '/files/chunked.bin'));

        $result = $this->pushAll($client, $local_paths_to_push, $first_result['cursor']);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(str_repeat('x', 12), file_get_contents($this->staging_dir . '/files/chunked.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testBodyBudgetCountsFrameHeadersWhenRotatingRequests(): void
    {
        $this->writeSource('budget.bin', str_repeat('y', 10));
        $first_frame_header = json_encode([
            'type' => 'chunk',
            'artifact_id' => base64_encode('budget.bin'),
            'offset' => 0,
            'bytes' => 4,
            'total_bytes' => 10,
            'final' => false,
        ], JSON_UNESCAPED_SLASHES) . "\n";
        // Budget for one full frame plus 3 spare bytes, so the value of
        // next_chunk_body_bytes() after the first chunk reveals whether the
        // frame header was charged against the budget alongside the payload.
        // The budget is denominated in entity-body bytes; the transfer
        // framing around them is libcurl's business.
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

        $this->assertSame(3, $client->next_chunk_body_bytes(), 'the frame header was charged against the budget, not only the payload');
        $this->assertFalse($client->should_finish_request());

        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'budget.bin',
            'offset' => 4,
            'total_bytes' => 10,
            'final' => false,
            'payload' => 'yyy',
        ]));
        $this->assertSame(0, $client->next_chunk_body_bytes(), 'the second frame header spends the rest of the budget');
        $this->assertTrue($client->should_finish_request());

        $rotation_result = $client->finish_request();
        $this->assertSame('complete', $rotation_result['status'], (string) json_encode($rotation_result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(['artifact_id' => 'budget.bin', 'committed_bytes' => 7], $rotation_result['cursor']);

        $result = $this->pushAll($client, $local_paths_to_push, $rotation_result['cursor']);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
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
        $this->assertSame('complete', $rotation_result['status'], (string) json_encode($rotation_result, JSON_INVALID_UTF8_SUBSTITUTE));

        $result = $this->pushAll($client, $local_paths_to_push, $rotation_result['cursor']);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(str_repeat('t', 8), file_get_contents($this->staging_dir . '/files/timed.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testRetryFromTheBeginningSkipsVerifiedFilesAndRestartsPartialOnes(): void
    {
        // first.bin is verified and gets skipped on replay; second.bin holds
        // 4 unvouched-for bytes, so the replay restarts it from zero.
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

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(str_repeat('a', 8), file_get_contents($this->staging_dir . '/files/first.bin'));
        $this->assertSame(str_repeat('b', 8), file_get_contents($this->staging_dir . '/files/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    public function testFrameTooLargeShrinksTheBodyBudgetAndRetries(): void
    {
        $this->writeSource('large.bin', str_repeat('x', 20));
        $this->configureEndpoint(['max_frame_bytes' => 6]);
        $sizer = new PushRequestSizer(['floor_bytes' => 4, 'start_bytes' => 12, 'max_bytes' => 12]);
        $client = $this->makeClient(['request_sizer' => $sizer, 'chunk_bytes' => 12]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['large.bin']);

        $push_started_at = microtime(true);
        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertGreaterThan(0.25, microtime(true) - $push_started_at, 'the retry after the 413 backed off before re-hitting the host');
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

        $this->assertSame(['failed', 'auth_failed'], [$result['status'], $result['reason']], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
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
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
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
        $this->assertStringContainsString('"artifact_id":"c3RyZWFtZWQuYmlu","offset":0,"bytes":4', $received);
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
        $this->assertStringContainsString('"artifact_id":"c3RyZWFtZWQuYmlu","offset":4,"bytes":4', $received);
        $this->assertSame(2, substr_count($received, 'ssss'), 'the second frame payload followed without finalizing');

        // Drop the connection without responding: the failure must surface as
        // a retryable request-level result, not an exception.
        fclose($connection);
        fclose($listener);
        $result = $client->finish_request();

        $this->assertSame(['retry', 'request_failed'], [$result['status'], $result['reason']], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(2, $result['chunks_sent']);
        $this->assertGreaterThan(8, $result['body_bytes_sent'], 'body accounting includes the frame headers');
    }

    public function testStalledConnectionFailsAfterStallTimeoutWhileSlowProgressDoesNot(): void
    {
        // A listener that accepts the connection and then never reads: the
        // kernel and libcurl buffers absorb a few hundred KiB, after which
        // zero bytes move. The stall watch must fail the request; a total
        // timeout would instead have killed any slow-but-healthy transfer.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, (string) $errstr);
        $listener_address = stream_socket_get_name($listener, false);

        $client = new StagedPushStreamClient([
            'base_url' => 'http://' . $listener_address . '/?reprint-api=1',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 8 * 1024 * 1024,
            'stall_timeout' => 1,
        ]);

        $this->assertTrue($client->start_push_request());
        $connection = stream_socket_accept($listener, 5);
        $this->assertNotFalse($connection);
        // Do not read from $connection: the pipe backs up and stalls.

        $stalled_send_started_at = microtime(true);
        $sent = $client->send_chunk([
            'artifact_id' => 'stalled.bin',
            'offset' => 0,
            'total_bytes' => 8 * 1024 * 1024,
            'final' => true,
            'payload' => str_repeat('s', 8 * 1024 * 1024),
        ]);

        $this->assertFalse($sent, 'a stalled connection must fail the chunk');
        $this->assertGreaterThan(1.0, microtime(true) - $stalled_send_started_at, 'the stall watch waited out its window first');

        $result = $client->finish_request();
        fclose($connection);
        fclose($listener);

        $this->assertSame(['retry', 'request_failed'], [$result['status'], $result['reason']], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertStringContainsString('no bytes moved for 1s', (string) $result['detail']);
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

    public function testGivingUpOnRequestSizeReportsRequestSizeExhausted(): void
    {
        $this->writeSource('giveup.bin', str_repeat('g', 8));
        // The target caps frames at 3 bytes; the sizer cannot shrink the
        // 4-byte body budget below its own floor, so the push must give up —
        // and the reason names the request-size dimension, not the chunk.
        $this->configureEndpoint(['max_frame_bytes' => 3]);
        $client = $this->makeClient([
            'request_sizer' => new PushRequestSizer(['floor_bytes' => 4, 'start_bytes' => 4, 'max_bytes' => 4]),
        ]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['giveup.bin']);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame(['failed', 'request_size_exhausted'], [$result['status'], $result['reason']], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function testEmptyRequestsDoNotGrowTheBodyBudget(): void
    {
        $sizer = new PushRequestSizer([
            'floor_bytes' => 4,
            'start_bytes' => 8,
            'max_bytes' => 32,
            'growth_holdoff_successes' => 0,
        ]);
        $client = $this->makeClient(['request_sizer' => $sizer]);

        // Requests that carried nothing teach the sizer nothing.
        $this->assertTrue($client->start_push_request());
        $this->assertSame('complete', $client->finish_request()['status']);
        $this->assertTrue($client->start_push_request());
        $this->assertSame('complete', $client->finish_request()['status']);
        $this->assertSame(8, $sizer->request_body_bytes(), 'accepting an empty body is no evidence the size is safe to grow');

        // A request that carried bytes grows the budget as before.
        $this->writeSource('grow.bin', 'gggg');
        $this->assertTrue($client->start_push_request());
        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'grow.bin',
            'offset' => 0,
            'total_bytes' => 4,
            'final' => true,
            'payload' => 'gggg',
        ]));
        $this->assertSame('complete', $client->finish_request()['status']);
        $this->assertSame(16, $sizer->request_body_bytes());
    }

    public function testInvalidOptionsThrowSpecificErrors(): void
    {
        $invalid_options = [
            [['chunk_bytes' => 0], 'Expected option "chunk_bytes" to be a positive integer; received 0.'],
            [['stall_timeout' => 'soon'], 'Expected option "stall_timeout" to be a positive integer; received "soon".'],
            [['max_request_seconds' => -1], 'Expected option "max_request_seconds" to be a positive number; received -1.'],
        ];

        foreach ($invalid_options as [$options, $expected_message]) {
            try {
                $this->makeClient($options);
                $this->fail('Expected an InvalidArgumentException for: ' . (string) json_encode($options));
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($expected_message, $exception->getMessage());
            }
        }
    }

    public function testEveryRequestMethodThrowsWithoutAnOpenRequest(): void
    {
        $client = $this->makeClient();
        $method_calls = [
            'next_chunk_body_bytes' => static fn (StagedPushStreamClient $client) => $client->next_chunk_body_bytes(),
            'should_finish_request' => static fn (StagedPushStreamClient $client) => $client->should_finish_request(),
            'finish_request' => static fn (StagedPushStreamClient $client) => $client->finish_request(),
            'send_chunk' => static fn (StagedPushStreamClient $client) => $client->send_chunk([
                'artifact_id' => 'a.bin',
                'offset' => 0,
                'total_bytes' => 4,
                'final' => true,
                'payload' => 'aaaa',
            ]),
        ];

        foreach ($method_calls as $method_name => $method_call) {
            try {
                $method_call($client);
                $this->fail('Expected a RuntimeException from ' . $method_name . '() without an open request');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'No push request is open; call start_push_request() before ' . $method_name . '().',
                    $exception->getMessage()
                );
            }
        }
    }

    public function testResumeCursorForADeletedFileReplaysFromTheTop(): void
    {
        $this->writeSource('kept-one.bin', str_repeat('k', 6));
        $this->writeSource('kept-two.bin', str_repeat('K', 6));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['kept-one.bin', 'kept-two.bin']);

        // The cursor points at a file that was deleted locally after the
        // last push, so a rebuilt journal no longer lists it. The push must
        // replay from the top, not skim past everything and report success.
        $result = $this->pushAll($client, $local_paths_to_push, ['artifact_id' => 'deleted-since.bin', 'committed_bytes' => 4]);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(2, $result['files_verified']);
        $this->assertSame(str_repeat('k', 6), file_get_contents($this->staging_dir . '/files/kept-one.bin'));
        $this->assertSame(str_repeat('K', 6), file_get_contents($this->staging_dir . '/files/kept-two.bin'));
    }

    public function testEmptyJournalPushesNothingWithoutANetworkRequest(): void
    {
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([]);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(0, $result['chunks_sent']);
        $this->assertSame([], $this->endpointsSeen(), 'an empty journal must not cost a network exchange');
    }

    public function testRedirectResponseNamesTheTargetInsteadOfRetrying(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, (string) $errstr);
        $listener_address = stream_socket_get_name($listener, false);

        $client = new StagedPushStreamClient([
            'base_url' => 'http://' . $listener_address . '/?reprint-api=1',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 4,
        ]);

        $this->assertTrue($client->start_push_request());
        $connection = stream_socket_accept($listener, 5);
        $this->assertNotFalse($connection);
        // The usual misconfiguration: an http:// base_url on a site that
        // forces https. Answer like such a site would.
        fwrite(
            $connection,
            "HTTP/1.1 301 Moved Permanently\r\n"
            . "Location: https://example.com/?reprint-api=1&endpoint=staged_push\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n"
        );
        fclose($connection);
        fclose($listener);

        $result = $client->finish_request();

        $this->assertSame(['failed', 'redirected'], [$result['status'], $result['reason']], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertStringContainsString('https://example.com/?reprint-api=1&endpoint=staged_push', (string) $result['detail']);
        $this->assertStringContainsString('Use that address as the push base_url', (string) $result['detail']);
    }

    public function testResumeAfterTheSourceGrewRestartsTheFile(): void
    {
        $old_content = str_repeat('o', 12);
        $source_path = $this->writeSource('drift-grow.bin', $old_content);
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['drift-grow.bin']);
        $resume_cursor = $this->pushFirstBytes($client, 'drift-grow.bin', $old_content, 4, $source_path);

        // The file changes before the next session: longer and different —
        // the size alone betrays it.
        $new_content = str_repeat('n', 16);
        file_put_contents($source_path, $new_content);
        clearstatcache();

        $result = $this->pushAll($client, $local_paths_to_push, $resume_cursor);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame($new_content, file_get_contents($this->staging_dir . '/files/drift-grow.bin'), 'no byte of the old version may survive under the new one');
    }

    public function testResumeAfterASameSizeEditRestartsTheFile(): void
    {
        $old_content = str_repeat('o', 12);
        $source_path = $this->writeSource('drift-edit.bin', $old_content);
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['drift-edit.bin']);
        $resume_cursor = $this->pushFirstBytes($client, 'drift-edit.bin', $old_content, 4, $source_path);

        // Same byte count, different bytes — only the ctime betrays the
        // edit, so the rewrite must land in a later timestamp second (ctime
        // cannot be set the way touch() sets mtime).
        usleep(1100000);
        $new_content = str_repeat('n', 12);
        file_put_contents($source_path, $new_content);
        clearstatcache();

        $result = $this->pushAll($client, $local_paths_to_push, $resume_cursor);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame($new_content, file_get_contents($this->staging_dir . '/files/drift-edit.bin'), 'no byte of the old version may survive under the new one');
    }

    public function testResumeCursorBeyondAShrunkenFileRestartsTheFile(): void
    {
        $old_content = str_repeat('o', 12);
        $source_path = $this->writeSource('drift-shrink.bin', $old_content);
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['drift-shrink.bin']);
        $resume_cursor = $this->pushFirstBytes($client, 'drift-shrink.bin', $old_content, 8, $source_path);

        // The file shrank below the committed offset. Skipping it as "done"
        // would leave the target holding 8 stale bytes and this push lying.
        $new_content = str_repeat('n', 6);
        file_put_contents($source_path, $new_content);
        clearstatcache();

        $result = $this->pushAll($client, $local_paths_to_push, $resume_cursor);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(1, $result['files_verified']);
        $this->assertSame($new_content, file_get_contents($this->staging_dir . '/files/drift-shrink.bin'));
    }

    public function testFullReplayAfterAPartialCommitRewritesChangedBytes(): void
    {
        // 4 bytes of an earlier version are staged; the retry has no cursor
        // (a transport failure lost it) and replays from the top with the
        // file's current content. The staged prefix must not survive.
        (new Site_Export_Staged_Artifacts($this->staging_dir))->append('replayed.bin', 0, 'OOOO');
        $new_content = 'NNNN' . str_repeat('n', 8);
        $this->writeSource('replayed.bin', $new_content);
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['replayed.bin']);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame($new_content, file_get_contents($this->staging_dir . '/files/replayed.bin'));
    }

    /**
     * Session one: push the first $bytes_to_push bytes of $content, then
     * stop, returning the resume cursor the reference loop would persist —
     * the server cursor plus the source token (size and ctime) it saves
     * alongside.
     *
     * @return array{artifact_id:string,committed_bytes:int,total_bytes:int,source_ctime:int}
     */
    private function pushFirstBytes(StagedPushStreamClient $client, string $artifact_id, string $content, int $bytes_to_push, string $source_path): array
    {
        $this->assertTrue($client->start_push_request());
        for ($offset = 0; $offset < $bytes_to_push; $offset += 4) {
            $this->assertTrue($client->send_chunk([
                'artifact_id' => $artifact_id,
                'offset' => $offset,
                'total_bytes' => strlen($content),
                'final' => false,
                'payload' => substr($content, $offset, 4),
            ]));
        }
        $first_result = $client->finish_request();
        $this->assertSame('complete', $first_result['status'], (string) json_encode($first_result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame($bytes_to_push, $first_result['cursor']['committed_bytes']);

        clearstatcache();
        return $first_result['cursor'] + [
            'total_bytes' => strlen($content),
            'source_ctime' => (int) stat($source_path)['ctime'],
        ];
    }

    public function testAStoreLockCollisionIsRetryableNotFatal(): void
    {
        $this->writeSource('locked.bin', str_repeat('l', 4));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['locked.bin']);

        // Create the store scaffolding, then hold its lock the way a
        // concurrent writer would. The store's answer is its documented
        // retry-until-free contract, not a push-ending failure.
        (new Site_Export_Staged_Artifacts($this->staging_dir))->append('scaffold.bin', 0, 'x');
        $lock_holder = fopen($this->staging_dir . '/lock', 'r+b');
        $this->assertNotFalse($lock_holder);
        $this->assertTrue(flock($lock_holder, LOCK_EX));

        $held_result = $this->pushOnce($client, $local_paths_to_push, null);
        $this->assertSame(['retry', 'busy'], [$held_result['status'], $held_result['reason']], (string) json_encode($held_result, JSON_INVALID_UTF8_SUBSTITUTE));

        flock($lock_holder, LOCK_UN);
        fclose($lock_holder);

        $result = $this->pushAll($client, $local_paths_to_push);
        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(str_repeat('l', 4), file_get_contents($this->staging_dir . '/files/locked.bin'));
    }

    public function testAStaleCursorBeyondTheStoreFrontierRetriesFromTheStoreCursor(): void
    {
        $content = str_repeat('g', 8);
        $source_path = $this->writeSource('gapped.bin', $content);
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['gapped.bin']);
        clearstatcache();
        $source_stat = stat($source_path);
        $this->assertNotFalse($source_stat);

        // The cursor claims 4 committed bytes and its source token matches
        // the file, but the staging directory was wiped: the store holds
        // nothing. The server answers offset_gap with its own cursor — the
        // push must resume from that cursor, not die.
        $stale_cursor = [
            'artifact_id' => 'gapped.bin',
            'committed_bytes' => 4,
            'total_bytes' => 8,
            'source_ctime' => (int) $source_stat['ctime'],
        ];

        $result = $this->pushAll($client, $local_paths_to_push, $stale_cursor);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame($content, file_get_contents($this->staging_dir . '/files/gapped.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen(), 'one gap rejection, one clean resume from the store cursor');
    }

    public function testBackToBackRequestsReuseTheConnection(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, (string) $errstr);
        $listener_address = stream_socket_get_name($listener, false);

        $client = new StagedPushStreamClient([
            'base_url' => 'http://' . $listener_address . '/?reprint-api=1',
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'chunk_bytes' => 4,
        ]);
        $keep_alive_response = static function (): string {
            $response_body = json_encode(['status' => 'complete', 'cursor' => null, 'files_verified' => 1]);
            return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response_body) . "\r\n\r\n" . $response_body;
        };

        $this->assertTrue($client->start_push_request());
        $connection = stream_socket_accept($listener, 5);
        $this->assertNotFalse($connection);
        $this->readAvailableBytes($connection);
        $this->assertTrue($client->send_chunk([
            'artifact_id' => 'reuse.bin',
            'offset' => 0,
            'total_bytes' => 4,
            'final' => true,
            'payload' => 'rrrr',
        ]));
        $this->readAvailableBytes($connection);
        fwrite($connection, $keep_alive_response());
        $this->assertSame('complete', $client->finish_request()['status']);

        // The second request must ride the connection libcurl cached on the
        // client's long-lived multi handle, not open a new one.
        $this->assertTrue($client->start_push_request());
        $pending_connections = [$listener];
        $write_sockets = null;
        $except_sockets = null;
        $this->assertSame(0, stream_select($pending_connections, $write_sockets, $except_sockets, 0, 200000), 'the second request must not open a new connection');
        $second_request_head = $this->readAvailableBytes($connection);
        $this->assertStringContainsString('POST /?reprint-api=1&endpoint=staged_push HTTP/1.1', $second_request_head, 'the second request head travels on the reused connection');

        fwrite($connection, $keep_alive_response());
        $this->assertSame('complete', $client->finish_request()['status']);
        fclose($connection);
        fclose($listener);
    }

    public function testEmojiFileNameRoundTrips(): void
    {
        $emoji_artifact_id = "wp-content/uploads/\u{1F4F7} photo.bin";
        $this->writeSource($emoji_artifact_id, str_repeat('e', 10));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([$emoji_artifact_id]);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(1, $result['files_verified']);
        $this->assertSame(str_repeat('e', 10), file_get_contents($this->staging_dir . '/files/' . $emoji_artifact_id));
    }

    public function testNonUtf8FileNameRoundTrips(): void
    {
        // Latin-1 "café" plus a stray 0xFF: bytes json_encode cannot carry
        // raw, which is why artifact ids travel base64 on the wire.
        $non_utf8_artifact_id = "caf\xE9-\xFF.bin";
        if (@file_put_contents($this->source_dir . '/' . $non_utf8_artifact_id, str_repeat('n', 6)) === false) {
            $this->markTestSkipped('This filesystem rejects non-UTF-8 file names (APFS does; ext4 allows them).');
        }
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([$non_utf8_artifact_id]);

        $result = $this->pushAll($client, $local_paths_to_push);

        $this->assertSame('complete', $result['status'], (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame(1, $result['files_verified']);
        $this->assertSame(str_repeat('n', 6), file_get_contents($this->staging_dir . '/files/' . $non_utf8_artifact_id));
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
        'max_frame_bytes' => (int) ( \$config['max_frame_bytes'] ?? 1073741824 ),
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
            'max_frame_bytes' => 1073741824,
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
            if ($attempt > 0) {
                // Back off before retrying — the command must too; a
                // struggling host should not be re-hit at full speed.
                usleep(min(5000000, 250000 * (2 ** ($attempt - 1))));
            }
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
     * next_chunk_body_bytes(), rotates requests when the client says to, and
     * persists a source token (size and mtime) with every cursor so a resume
     * restarts any file that changed since its bytes were staged.
     *
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,chunks_sent:int,body_bytes_sent:int}
     */
    private function pushOnce(StagedPushStreamClient $client, string $local_paths_to_push, ?array $cursor): array
    {
        $journal_handle = fopen($local_paths_to_push, 'r');
        $this->assertNotFalse($journal_handle);

        // A journal rebuilt since the cursor was saved may no longer contain
        // the cursor's file (deleted locally). Skimming for a line that never
        // comes would push nothing and report success, so check first —
        // replaying from the top is safe, the target absorbs committed frames.
        $skipping_to_cursor = is_array($cursor) && isset($cursor['artifact_id']);
        if ($skipping_to_cursor) {
            $cursor_artifact_in_journal = false;
            while (($journal_line = fgets($journal_handle)) !== false) {
                $journal_line = trim($journal_line);
                if ($journal_line === '') {
                    continue;
                }
                $decoded_line = json_decode($journal_line, true, 512, JSON_THROW_ON_ERROR);
                if (base64_decode((string) ($decoded_line['path'] ?? ''), true) === $cursor['artifact_id']) {
                    $cursor_artifact_in_journal = true;
                    break;
                }
            }
            rewind($journal_handle);
            $skipping_to_cursor = $cursor_artifact_in_journal;
        }

        $request_open = false;
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
            $resuming_this_file = $skipping_to_cursor;
            $offset = $resuming_this_file ? (int) $cursor['committed_bytes'] : 0;
            $skipping_to_cursor = false;

            $source_handle = fopen($this->source_dir . '/' . $artifact_id, 'rb');
            $this->assertNotFalse($source_handle);
            $source_stat = fstat($source_handle);
            $total_bytes = (int) $source_stat['size'];
            $source_ctime = (int) $source_stat['ctime'];

            // The cursor's source token remembers the file the staged bytes
            // came from. Any change — size or ctime, the same signals the
            // journal's diff keys on — means those bytes are another version:
            // restart the file at zero so the target re-stages it instead of
            // appending one version behind another. A same-size edit within
            // the same timestamp second escapes this token; the diff layer's
            // own change detection is the deeper net.
            if (
                $resuming_this_file
                && (
                    (isset($cursor['total_bytes']) && (int) $cursor['total_bytes'] !== $total_bytes)
                    || (isset($cursor['source_ctime']) && (int) $cursor['source_ctime'] !== $source_ctime)
                )
            ) {
                $offset = 0;
            }

            if ($total_bytes > 0 && $offset >= $total_bytes) {
                fclose($source_handle);
                continue;
            }
            if ($offset > 0) {
                fseek($source_handle, $offset);
            }

            while (true) {
                // Open the request only once there is a chunk to push, so an
                // empty journal never costs a network exchange.
                if (!$request_open) {
                    if (false === $client->start_push_request()) {
                        fclose($source_handle);
                        fclose($journal_handle);
                        return $this->startFailureResult($client, $cursor);
                    }
                    $request_open = true;
                } elseif ($client->should_finish_request()) {
                    $result = $client->finish_request();
                    if ($result['status'] !== 'complete') {
                        fclose($source_handle);
                        fclose($journal_handle);
                        return $this->withSourceToken($result, $artifact_id, $total_bytes, $source_ctime);
                    }
                    if (false === $client->start_push_request()) {
                        fclose($source_handle);
                        fclose($journal_handle);
                        return $this->startFailureResult($client, $result['cursor']);
                    }
                }

                $payload = $total_bytes === 0
                    ? ''
                    : (string) fread($source_handle, $client->next_chunk_body_bytes());
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
                    return $this->withSourceToken($client->finish_request(), $artifact_id, $total_bytes, $source_ctime);
                }

                $offset += strlen($payload);
                if ($final) {
                    break;
                }
            }
            fclose($source_handle);
        }
        fclose($journal_handle);

        if (!$request_open) {
            // Nothing needed pushing; report completion without having
            // touched the network.
            return [
                'status' => 'complete',
                'reason' => null,
                'detail' => null,
                'cursor' => $cursor,
                'files_verified' => 0,
                'chunks_sent' => 0,
                'body_bytes_sent' => 0,
            ];
        }
        return $this->withSourceToken($client->finish_request(), $artifact_id, $total_bytes, $source_ctime);
    }

    /**
     * Attach the source token the reference loop persists alongside a server
     * cursor: the size and ctime of the file whose bytes the cursor counts.
     * A later resume compares them against the file on disk and restarts the
     * file when they differ.
     */
    private function withSourceToken(array $result, string $artifact_id, int $total_bytes, int $source_ctime): array
    {
        if (is_array($result['cursor'] ?? null) && ($result['cursor']['artifact_id'] ?? null) === $artifact_id) {
            $result['cursor'] += ['total_bytes' => $total_bytes, 'source_ctime' => $source_ctime];
        }
        return $result;
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
