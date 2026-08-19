<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\CurlTimeoutException;
use Reprint\Importer\InterruptedResponseException;
use Reprint\Importer\StreamingContext;
use Reprint\Importer\TransientInterruptionException;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify recovery from cURL timeouts during streaming fetches.
 *
 * Each fetch method (fetch_sql, fetch_file_batch, fetch_next_remote_index,
 * fetch_database_index) is tested by injecting a CurlTimeoutException. SQL retries
 * in the same invocation; the other phases save partial state for a later run.
 *
 * Also verifies the no-progress safety net: after repeated interrupted
 * responses with no cursor progress, the importer gives up.
 */
class CurlTimeoutRecoveryTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/curl-timeout-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->pullStateDirectory =
            $this->stateDir . '/remotes/' . md5('http://fake.url') . '/pull';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->pullStateDirectory, 0755, true);
        mkdir($this->filesystem_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), array_replace_recursive([
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
        ], $state));
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->filesystem_root);
    }

    private function readState(): array
    {
        $contents = file_get_contents($this->pullStateDirectory . '/state.json');
        return json_decode($contents, true);
    }

    public static function fileCursorForBytes(int $bytes): string
    {
        return base64_encode(json_encode([
            "phase" => "streaming",
            "root" => base64_encode('/srv/htdocs'),
            "path" => base64_encode('/uploads/large.bin'),
            "ctime" => 1234567890,
            "bytes" => $bytes,
        ]));
    }

    private static function fileCursorBytes(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $json = base64_decode($cursor, true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded["bytes"])) {
            return null;
        }
        return (int) $decoded["bytes"];
    }

    /**
     * Prepare a client test double with state loaded and TTY disabled.
     *
     * @param class-string $clientClass  Which test double to instantiate
     */
    private function prepareClient(string $clientClass = TimeoutTestClient::class): array
    {
        $client = new $clientClass(
            'http://fake.url',
            $this->stateDir,
            $this->filesystem_root,
        );
        $reflection = new \ReflectionClass(\ImportClient::class);

        $stateProperty = $reflection->getProperty('state');
        $loadState = $reflection->getMethod('load_state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $ttyProperty = $reflection->getProperty('is_tty');
        $ttyProperty->setValue($client, false);

        return [$client, $reflection];
    }

    // ---------------------------------------------------------------
    // fetch_sql: timeout retries in the same invocation
    // ---------------------------------------------------------------

    public function testSqlDownloadRetriesTimeoutUntilNoProgressLimit()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
                "current_stage" => "sql",
                "remote_cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            ],
            "sql_bytes" => 1024,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        $fetchSql = $reflection->getMethod('fetch_sql');
        try {
            $fetchSql->invoke($client);
            $this->fail("Expected the no-progress limit to stop SQL retries");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                "3 consecutive times without cursor progress",
                $e->getMessage(),
            );
        }

        $this->assertSame(
            3,
            $client->streaming_requests,
            "fetch_sql should retry timeouts until the no-progress limit",
        );
    }

    public function testSqlDownloadRetriesHttp400ThenCompletes()
    {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('HTTP retry coverage requires PHP curl and pcntl.');
        }

        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $http400 = $this->httpResponse(
            '400 Bad Request',
            'text/html',
            '<!doctype html><title>Temporary upstream response</title>',
        );
        $boundary = 'http-retry-test';
        $completion_body = "--{$boundary}\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: 0\r\n"
            . "X-Chunk-Type: completion\r\n"
            . "X-Status: complete\r\n"
            . "\r\n"
            . "\r\n"
            . "--{$boundary}--\r\n";
        $server = $this->startSqlResponseServer([
            $http400,
            $this->httpResponse(
                '200 OK',
                "multipart/mixed; boundary={$boundary}",
                $completion_body,
            ),
        ], $cursor);
        $wire_client = $this->prepareWireSqlClient(
            $server['url'],
            $cursor,
        );
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(
            0,
            $client->get_state()->consecutive_interrupted_responses,
        );
        $this->assertNull(
            $client->last_error_code,
            'A successful repeated request must clear the earlier HTTP error code.',
        );
    }

    public function testSqlDownloadPreservesHttp418AfterRetryLimit()
    {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('HTTP retry coverage requires PHP curl and pcntl.');
        }

        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $http418 = $this->httpResponse(
            "418 I'm a teapot",
            'text/html',
            '<!doctype html><title>Temporary bot response</title>',
        );
        $server = $this->startSqlResponseServer([
            $http418,
            $http418,
            $http418,
        ], $cursor);
        $wire_client = $this->prepareWireSqlClient(
            $server['url'],
            $cursor,
        );
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        $failure = null;
        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
            $this->fail('The third HTTP 418 response should stop the pull.');
        } catch (\RuntimeException $error) {
            $failure = $error;
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertNotNull($failure);
        $this->assertStringContainsString(
            '3 consecutive times without cursor progress',
            $failure->getMessage(),
        );
        $this->assertStringContainsString('HTTP 418', $failure->getMessage());
        $this->assertSame('HTML_RESPONSE', $client->last_error_code);
        $this->assertSame(
            3,
            $client->get_state()->consecutive_interrupted_responses,
        );
    }

    // ---------------------------------------------------------------
    // fetch_file_batch: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testFileFetchTimeoutSavesPartialState()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => [
                "offset" => 0,
                "next_offset" => 100,
                "batch_file" => null,
                "cursor" => base64_encode('{"path":"/wp-content/uploads/photo.jpg","offset":4096}'),
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $fetchFileBatch = $reflection->getMethod('fetch_file_batch');
        $result = $fetchFileBatch->invoke(
            $client,
            null,
            base64_encode('{"path":"/photo.jpg","offset":4096}'),
            "fetch",
        );

        $this->assertFalse(
            $result,
            "fetch_file_batch should return false (not complete) on timeout"
        );

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["active_resumable_command"]["completion_state"],
            "After cURL timeout during file fetch, resumable command completion state should be 'partial'"
        );
    }

    public function testFileFetchHardCrashCheckpointDoesNotPutCursorBehindBytes()
    {
        $trackedPath = $this->filesystem_root . '/uploads/large.bin';
        mkdir(dirname($trackedPath), 0755, true);
        file_put_contents($trackedPath, str_repeat('a', 256));

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => [
                "offset" => 0,
                "next_offset" => 100,
                "batch_file" => null,
                "cursor" => self::fileCursorForBytes(256),
            ],
            "current_file" => $trackedPath,
            "current_file_bytes" => 256,
        ]);

        [$client, $reflection] = $this->prepareClient(
            InterruptedAfterStreamedPartCloseClient::class,
        );

        $fetchFileBatch = $reflection->getMethod('fetch_file_batch');

        try {
            $fetchFileBatch->invoke(
                $client,
                null,
                self::fileCursorForBytes(256),
                "fetch",
            );
            $this->fail('Expected simulated hard crash during file fetch');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Simulated hard crash after streamed file part close',
                $e->getMessage(),
            );
        }

        $state = $this->readState();
        $savedBytes = $state["current_file_bytes"] ?? null;
        $savedCursorBytes = self::fileCursorBytes(
            $state["fetch"]["cursor"] ?? null,
        );

        $this->assertNotNull(
            $savedBytes,
            'The state should retain a crash-recovery file byte count',
        );
        $this->assertSame(
            $savedBytes,
            $savedCursorBytes,
            'A hard-crash checkpoint must not put the saved cursor behind the bytes retained on disk',
        );
    }

    // ---------------------------------------------------------------
    // fetch_next_remote_index: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testNextRemoteIndexTimeoutSavesPartialState()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "index",
            ],
            "index" => [
                "cursor" => base64_encode('{"dir":"/wp-content","offset":500}'),
            ],
            "preflight" => [
                "data" => [
                    "ok" => true,
                    "wp_detect" => [
                        "roots" => [
                            ["path" => "/srv/htdocs"],
                        ],
                    ],
                ],
                "http_code" => 200,
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $fetchNextRemoteIndex = $reflection->getMethod('fetch_next_remote_index');
        $result = $fetchNextRemoteIndex->invoke($client);

        $this->assertFalse(
            $result,
            "fetch_next_remote_index should return false on timeout"
        );

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["active_resumable_command"]["completion_state"],
            "After cURL timeout during index download, resumable command completion state should be 'partial'"
        );
        $this->assertNotNull(
            $state["index"]["cursor"] ?? null,
            "Index cursor should be preserved for resumption"
        );
    }

    // ---------------------------------------------------------------
    // fetch_database_index: timeout saves state as "partial"
    // ---------------------------------------------------------------

    public function testDbIndexTimeoutSavesPartialState()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
                "current_stage" => "db-index",
                "remote_cursor" => base64_encode('{"table_offset":5}'),
            ],
            "db_index" => [
                "file" => null,
                "tables" => 3,
                "rows_estimated" => 1000,
                "bytes" => 256,
                "updated_at" => (string) time(),
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $fetchDatabaseIndex = $reflection->getMethod('fetch_database_index');
        $fetchDatabaseIndex->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["active_resumable_command"]["completion_state"],
            "After cURL timeout during db-index, resumable command completion state should be 'partial'"
        );
    }

    // ---------------------------------------------------------------
    // Exception hierarchy
    // ---------------------------------------------------------------

    public function testInterruptionExceptionHierarchy()
    {
        $interrupted = new InterruptedResponseException("Response ended early");
        $this->assertInstanceOf(\RuntimeException::class, $interrupted);
        $this->assertNotInstanceOf(
            TransientInterruptionException::class,
            $interrupted,
        );

        $transient = new TransientInterruptionException("Connection reset");
        $this->assertInstanceOf(InterruptedResponseException::class, $transient);

        $timeout = new CurlTimeoutException("Operation timed out");
        $this->assertInstanceOf(TransientInterruptionException::class, $timeout);
    }

    // ---------------------------------------------------------------
    // cURL error number classification
    // ---------------------------------------------------------------

    /**
     * A real transfer cut short by the peer must raise
     * TransientInterruptionException so the caller can checkpoint and resume.
     *
     * Driven over a real socket rather than by injecting an error number: a
     * server that accepts and closes without replying makes cURL report either
     * CURLE_GOT_NOTHING (52) or CURLE_RECV_ERROR (56) depending on how far the
     * exchange got. Both are in TRANSIENT_CURL_ERROR_NUMBERS, so this proves
     * the classification on the wire instead of restating the constant.
     */
    public function testTransferCutShortByPeerIsTransient()
    {
        [$server, $url] = $this->startConnectionClosingServer();

        try {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_exec($curl);

            $errorNumber = curl_errno($curl);
            $this->assertContains(
                $errorNumber,
                [52, 56],
                'The stub server should make cURL report a cut-short transfer',
            );

            [$client, $reflection] = $this->prepareClient();
            $checkCurlError = $reflection->getMethod('check_curl_error');

            try {
                $checkCurlError->invoke($client, $curl);
                $this->fail('A transfer cut short by the peer should have thrown');
            } catch (TransientInterruptionException $e) {
                $this->assertStringContainsString("({$errorNumber})", $e->getMessage());
            }

            curl_close($curl);
        } finally {
            proc_close($server);
        }
    }

    /**
     * A failure that will reproduce on the next request must stay fatal.
     * Connecting to a closed port yields CURLE_COULDNT_CONNECT (7), which is
     * deliberately absent from TRANSIENT_CURL_ERROR_NUMBERS.
     */
    public function testUnreachableHostStaysFatal()
    {
        $curl = curl_init('http://127.0.0.1:1/');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_exec($curl);

        $this->assertSame(
            7,
            curl_errno($curl),
            'Connecting to a closed port should report CURLE_COULDNT_CONNECT',
        );

        [$client, $reflection] = $this->prepareClient();
        $checkCurlError = $reflection->getMethod('check_curl_error');

        try {
            $checkCurlError->invoke($client, $curl);
            $this->fail('An unreachable host should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(
                InterruptedResponseException::class,
                $e,
                'An unreachable host must not be treated as a resumable interruption',
            );
        }

        curl_close($curl);
    }

    /**
     * Start a server that accepts a connection then closes it without a
     * response. Returns the process handle and the URL to request.
     */
    private function startConnectionClosingServer(): array
    {
        $port = 8000 + (getmypid() % 20000);
        $script = $this->tempDir . '/close-immediately.php';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server("tcp://127.0.0.1:" . $argv[1], $errno, $errstr);
if (!$server) {
    exit(1);
}
echo "ready\n";
$connection = stream_socket_accept($server, 10);
if ($connection) {
    fclose($connection);
}
fclose($server);
PHP);

        $process = proc_open(
            sprintf('%s %s %d', escapeshellarg(PHP_BINARY), escapeshellarg($script), $port),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'The stub server should start');

        // Block until the listener is bound so the request cannot race it.
        $this->assertSame('ready', trim((string) fgets($pipes[1])));

        return [$process, "http://127.0.0.1:{$port}/"];
    }


    // ---------------------------------------------------------------
    // Consecutive interrupted-response counter
    // ---------------------------------------------------------------

    /**
     * assert_can_resume_after_interrupted_response increments the counter when the
     * cursor did not move.
     */
    public function testTrackInterruptedResponsesIncrementsOnNoProgress()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
            ],
            "consecutive_interrupted_responses" => 0,
        ]);

        [$client, $reflection] = $this->prepareClient();
        $state = $reflection->getProperty('state');

        $method = $reflection->getMethod('assert_can_resume_after_interrupted_response');

        // First call — no progress (same cursor before and after)
        $method->invoke(
            $client,
            "sql_chunk",
            "abc",
            "abc",
            new TransientInterruptionException("Response ended early"),
        );
        $this->assertEquals(
            1,
            $state->getValue($client)->consecutive_interrupted_responses,
        );

        // Second call — still no progress
        $method->invoke(
            $client,
            "sql_chunk",
            "abc",
            "abc",
            new TransientInterruptionException("Response ended early"),
        );
        $this->assertEquals(
            2,
            $state->getValue($client)->consecutive_interrupted_responses,
        );
    }

    public function testTrackInterruptedResponsesResetsOnProgress()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
            ],
            "consecutive_interrupted_responses" => 2,
        ]);

        [$client, $reflection] = $this->prepareClient();
        $state = $reflection->getProperty('state');

        $method = $reflection->getMethod('assert_can_resume_after_interrupted_response');

        // Cursor advanced — should reset to 0
        $method->invoke(
            $client,
            "sql_chunk",
            "abc",
            "def",
            new TransientInterruptionException("Response ended early"),
        );
        $this->assertEquals(
            0,
            $state->getValue($client)->consecutive_interrupted_responses,
        );
    }

    public function testTrackInterruptedResponsesThrowsAtMax()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
            ],
            "consecutive_interrupted_responses" => 2,
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('assert_can_resume_after_interrupted_response');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive');

        // The third response without progress should throw.
        $method->invoke(
            $client,
            "sql_chunk",
            "abc",
            "abc",
            new TransientInterruptionException("Response ended early"),
        );
    }

    /**
     * End-to-end: fetch_sql with counter already at MAX-1 and no
     * cursor progress should throw RuntimeException.
     */
    public function testSqlDownloadGivesUpAfterMaxConsecutiveTimeouts()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
                "current_stage" => "sql",
                "remote_cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            ],
            "sql_bytes" => 1024,
            "consecutive_interrupted_responses" => 2,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive');

        $fetchSql = $reflection->getMethod('fetch_sql');
        $fetchSql->invoke($client);
    }

    public function testSuccessfulRequestResetsCounter()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "index",
            ],
            "index" => [
                "cursor" => base64_encode('{"dir":"/wp-content","offset":500}'),
            ],
            "consecutive_interrupted_responses" => 2,
            "preflight" => [
                "data" => [
                    "ok" => true,
                    "wp_detect" => [
                        "roots" => [
                            ["path" => "/srv/htdocs"],
                        ],
                    ],
                ],
                "http_code" => 200,
            ],
        ]);

        [$client, $reflection] = $this->prepareClient(
            SuccessTestClient::class,
        );

        $fetchNextRemoteIndex = $reflection->getMethod('fetch_next_remote_index');
        $fetchNextRemoteIndex->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            0,
            $state["consecutive_interrupted_responses"],
            "Successful request should reset consecutive_interrupted_responses to 0"
        );
    }

    private function httpResponse(
        string $status_line,
        string $content_type,
        string $body
    ): string {
        return "HTTP/1.1 {$status_line}\r\n"
            . "Content-Type: {$content_type}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
    }

    /**
     * Start a child server which returns one supplied response per SQL request.
     *
     * @param string[] $responses Complete HTTP responses in request order.
     * @param string   $cursor    Expected SQL cursor on every request.
     * @return array {
     *     @type int    $child_pid Child process ID.
     *     @type string $url       Remote Reprint API URL.
     * }
     */
    private function startSqlResponseServer(array $responses, string $cursor): array
    {
        $listener = stream_socket_server(
            'tcp://127.0.0.1:0',
            $error_number,
            $error_message,
        );
        $this->assertNotFalse($listener, $error_message);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);

        $child_pid = pcntl_fork();
        $this->assertNotSame(-1, $child_pid);
        if ($child_pid === 0) {
            foreach ($responses as $response) {
                $connection = stream_socket_accept($listener, 5);
                if ($connection === false) {
                    exit(2);
                }
                stream_set_timeout($connection, 5);
                $request = '';
                while (strpos($request, "\r\n\r\n") === false) {
                    $piece = fread($connection, 8192);
                    if ($piece === false || $piece === '') {
                        fclose($connection);
                        fclose($listener);
                        exit(3);
                    }
                    $request .= $piece;
                }
                if (strpos($request, 'endpoint=sql_chunk') === false) {
                    fclose($connection);
                    fclose($listener);
                    exit(4);
                }
                if (stripos($request, "X-Export-Cursor: {$cursor}\r\n") === false) {
                    fclose($connection);
                    fclose($listener);
                    exit(5);
                }
                if (fwrite($connection, $response) !== strlen($response)) {
                    fclose($connection);
                    fclose($listener);
                    exit(6);
                }
                fclose($connection);
            }
            fclose($listener);
            exit(0);
        }

        fclose($listener);

        return [
            'child_pid' => $child_pid,
            'url' => 'http://' . $address . '/?reprint-api=1',
        ];
    }

    /**
     * Prepare a real ImportClient for an SQL wire retry test.
     *
     * @return array {
     *     @type \ImportClient     $client     Client with resumable SQL state.
     *     @type \ReflectionClass $reflection Reflection for invoking fetch_sql().
     * }
     */
    private function prepareWireSqlClient(
        string $url,
        string $cursor
    ): array {
        $client = new \ImportClient(
            $url,
            $this->stateDir,
            $this->filesystem_root,
        );
        \write_current_pull_state($client, [
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
                "current_stage" => "sql",
                "remote_cursor" => $cursor,
            ],
            "sql_bytes" => 0,
        ]);

        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('is_tty')->setValue($client, false);
        $reflection->getProperty('sql_output_mode')->setValue($client, 'file');

        return [
            'client' => $client,
            'reflection' => $reflection,
        ];
    }

}

/**
 * Test double that throws CurlTimeoutException from fetch_streaming,
 * simulating a cURL timeout without making real HTTP requests.
 * The cursor is NOT advanced — simulates a complete stall.
 */
class TimeoutTestClient extends \ImportClient
{
    public $streaming_requests = 0;

    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        ++$this->streaming_requests;
        throw new CurlTimeoutException(
            "cURL error: Operation timed out after 300001 milliseconds with 0 bytes received"
        );
    }
}

/**
 * Test double that simulates a process dying immediately after a streamed
 * file part-complete checkpoint. This is a hard crash, so fetch_file_batch()
 * must not get a chance to do its normal final save.
 */
class InterruptedAfterStreamedPartCloseClient extends \ImportClient
{
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $headers = [
            "x-chunk-type" => "file",
            "x-cursor" => CurlTimeoutRecoveryTest::fileCursorForBytes(512),
            "x-file-path" => base64_encode('/uploads/large.bin'),
            "x-file-size" => "1024",
            "x-file-ctime" => "1234567890",
            "x-chunk-offset" => "256",
            "x-chunk-size" => "256",
            "x-first-chunk" => "0",
            "x-last-chunk" => "0",
        ];

        ($context->on_chunk)([
            "headers" => $headers,
            "body" => str_repeat('b', 256),
            "is_streaming_body" => true,
        ]);
        ($context->on_chunk)([
            "headers" => $headers,
            "body" => "",
            "is_streaming_close" => true,
        ]);

        throw new \RuntimeException(
            'Simulated hard crash after streamed file part close',
        );
    }
}

/**
 * Test double that completes successfully without throwing,
 * simulating a normal request that finishes.
 */
class SuccessTestClient extends \ImportClient
{
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        // Signal completion
        $context->saw_completion = true;
    }
}
