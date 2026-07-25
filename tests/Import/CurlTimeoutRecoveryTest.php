<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that cURL timeouts during download save state and set the
 * resumable command completion state to "partial" instead of crashing with
 * a fatal RuntimeException.
 *
 * Each download method (download_sql, download_file_fetch, download_remote_index,
 * download_db_index) is tested by injecting a CurlTimeoutException via a
 * subclass that overrides fetch_streaming.
 *
 * Also verifies the consecutive-timeout safety net: after
 * MAX_CONSECUTIVE_TIMEOUTS with no cursor progress, the importer gives up
 * with a RuntimeException instead of retrying forever.
 */
class CurlTimeoutRecoveryTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/curl-timeout-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fs_root, 0755, true);
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
        $defaults = [
            "active_resumable_command" => [
                "command_name" => null,
                "completion_state" => null,
                "current_stage" => null,
                "remote_cursor" => null,
            ],
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function readState(): array
    {
        $contents = file_get_contents($this->stateDir . '/.import-state.json');
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
            $this->fs_root,
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
    // download_sql: timeout saves state as "partial"
    // ---------------------------------------------------------------

    public function testSqlDownloadTimeoutSavesPartialState()
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

        $downloadSql = $reflection->getMethod('download_sql');
        $downloadSql->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["active_resumable_command"]["completion_state"],
            "After cURL timeout, resumable command completion state should be 'partial' not an exception"
        );
        $this->assertNotNull(
            $state["active_resumable_command"]["remote_cursor"],
            "Cursor should be preserved for resumption"
        );
        $this->assertNotNull(
            $state["sql_bytes"],
            "sql_bytes should be saved for crash recovery"
        );
    }

    // ---------------------------------------------------------------
    // download_file_fetch: timeout saves state and returns false
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

        $downloadFilesFetch = $reflection->getMethod('download_file_fetch');
        $result = $downloadFilesFetch->invoke(
            $client,
            null,
            base64_encode('{"path":"/photo.jpg","offset":4096}'),
            "fetch",
        );

        $this->assertFalse(
            $result,
            "download_file_fetch should return false (not complete) on timeout"
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
        $trackedPath = $this->fs_root . '/uploads/large.bin';
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
            InterruptedStreamedPartClient::class,
        );

        $downloadFilesFetch = $reflection->getMethod('download_file_fetch');

        try {
            $downloadFilesFetch->invoke(
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

    public function testFileFetchTimeoutKeepsThePreviousPartBoundary()
    {
        $trackedPath = $this->fs_root . '/uploads/large.bin';
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
            InterruptedStreamedPartClient::class,
        );
        $downloadFilesFetch = $reflection->getMethod('download_file_fetch');

        $this->assertFalse($downloadFilesFetch->invoke(
            $client,
            ["timeout_during_part" => true],
            self::fileCursorForBytes(256),
            "fetch",
        ));

        // The timeout leaves half of the next 256-byte part on disk after its
        // 512-byte cursor was advertised. Both saved boundaries must remain at
        // the previous completed part so resume truncates the extra 128 bytes
        // before replaying that part instead of skipping unconfirmed bytes.
        $state = $this->readState();
        $this->assertSame(
            256,
            self::fileCursorBytes($state["fetch"]["cursor"] ?? null),
        );
        $this->assertSame(256, $state["current_file_bytes"] ?? null);
        $this->assertSame(384, filesize($trackedPath));
    }

    // ---------------------------------------------------------------
    // download_remote_index: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testRemoteIndexTimeoutSavesPartialState()
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

        $downloadIndex = $reflection->getMethod('download_remote_index');
        $result = $downloadIndex->invoke($client);

        $this->assertFalse(
            $result,
            "download_remote_index should return false on timeout"
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
    // download_db_index: timeout saves state as "partial"
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
                "updated_at" => time(),
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $downloadDbIndex = $reflection->getMethod('download_db_index');
        $downloadDbIndex->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["active_resumable_command"]["completion_state"],
            "After cURL timeout during db-index, resumable command completion state should be 'partial'"
        );
    }

    // ---------------------------------------------------------------
    // run_db_sync: timeout propagates as "partial", not exception
    // ---------------------------------------------------------------

    public function testRunDbSyncExitsPartialOnSqlTimeout()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
                "current_stage" => "sql",
                "remote_cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            ],
            "sql_bytes" => 0,
            "db_index" => [
                "file" => $this->stateDir . "/db-tables.jsonl",
                "tables" => 5,
                "rows_estimated" => 10000,
                "bytes" => 100,
                "updated_at" => time(),
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        // run_db_sync should NOT throw — it should return with partial status
        $runDbSync = $reflection->getMethod('run_db_sync');
        $runDbSync->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["active_resumable_command"]["completion_state"],
            "run_db_sync should set resumable command completion state to 'partial' on timeout, not throw"
        );
    }

    // ---------------------------------------------------------------
    // Exception hierarchy
    // ---------------------------------------------------------------

    public function testCurlTimeoutExceptionExtendsRuntimeException()
    {
        $e = new \CurlTimeoutException("Operation timed out");
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // ---------------------------------------------------------------
    // Consecutive timeout counter
    // ---------------------------------------------------------------

    /**
     * assert_can_retry_consecutive_timeout increments the counter when cursor didn't
     * move. After MAX_CONSECUTIVE_TIMEOUTS it throws RuntimeException.
     */
    public function testTrackConsecutiveTimeoutIncrementsOnNoProgress()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
            ],
            "consecutive_timeouts" => 0,
        ]);

        [$client, $reflection] = $this->prepareClient();
        $state = $reflection->getProperty('state');

        $method = $reflection->getMethod('assert_can_retry_consecutive_timeout');

        // First call — no progress (same cursor before and after)
        $method->invoke($client, "sql_chunk", "abc", "abc");
        $this->assertEquals(1, $state->getValue($client)->consecutive_timeouts);

        // Second call — still no progress
        $method->invoke($client, "sql_chunk", "abc", "abc");
        $this->assertEquals(2, $state->getValue($client)->consecutive_timeouts);
    }

    public function testTrackConsecutiveTimeoutResetsOnProgress()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
            ],
            "consecutive_timeouts" => 2,
        ]);

        [$client, $reflection] = $this->prepareClient();
        $state = $reflection->getProperty('state');

        $method = $reflection->getMethod('assert_can_retry_consecutive_timeout');

        // Cursor advanced — should reset to 0
        $method->invoke($client, "sql_chunk", "abc", "def");
        $this->assertEquals(0, $state->getValue($client)->consecutive_timeouts);
    }

    public function testTrackConsecutiveTimeoutThrowsAtMax()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
            ],
            "consecutive_timeouts" => 2,
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('assert_can_retry_consecutive_timeout');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive');

        // 3rd no-progress timeout should throw
        $method->invoke($client, "sql_chunk", "abc", "abc");
    }

    /**
     * End-to-end: download_sql with counter already at MAX-1 and no
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
            "consecutive_timeouts" => 2,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive');

        $downloadSql = $reflection->getMethod('download_sql');
        $downloadSql->invoke($client);
    }

    public function testFirstTimeoutIncrementsCounterInState()
    {
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "db-pull",
                "completion_state" => "in_progress",
                "current_stage" => "sql",
                "remote_cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            ],
            "sql_bytes" => 1024,
            "consecutive_timeouts" => 0,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        $downloadSql = $reflection->getMethod('download_sql');
        $downloadSql->invoke($client);

        $state = $this->readState();
        $this->assertEquals("partial", $state["active_resumable_command"]["completion_state"]);
        $this->assertEquals(
            1,
            $state["consecutive_timeouts"],
            "First no-progress timeout should increment counter to 1"
        );
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
            "consecutive_timeouts" => 2,
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

        $downloadIndex = $reflection->getMethod('download_remote_index');
        $downloadIndex->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            0,
            $state["consecutive_timeouts"],
            "Successful request should reset consecutive_timeouts to 0"
        );
    }

}

/**
 * Test double that throws CurlTimeoutException from fetch_streaming,
 * simulating a cURL timeout without making real HTTP requests.
 * The cursor is NOT advanced — simulates a complete stall.
 */
class TimeoutTestClient extends \ImportClient
{
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        \StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        throw new \CurlTimeoutException(
            "cURL error: Operation timed out after 300001 milliseconds with 0 bytes received"
        );
    }
}

/**
 * Test double that interrupts a streamed file part before or after its close.
 */
class InterruptedStreamedPartClient extends \ImportClient
{
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        \StreamingContext $context,
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

        $timeout_during_part = !empty($post_data["timeout_during_part"]);
        ($context->on_chunk)([
            "headers" => $headers,
            "body" => str_repeat('b', $timeout_during_part ? 128 : 256),
            "is_streaming_body" => true,
        ]);
        if ($timeout_during_part) {
            throw new \CurlTimeoutException(
                "cURL error: Operation timed out while receiving a streamed file part"
            );
        }
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
        \StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        // Signal completion
        $context->saw_completion = true;
    }
}
