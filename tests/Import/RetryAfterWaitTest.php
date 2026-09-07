<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify that a potentially transient streaming HTTP error waits out the
 * server's Retry-After delay before the next request, instead of spending
 * the three-failure no-progress budget immediately.
 *
 */
class RetryAfterWaitTest extends TestCase {
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Retry-After wire coverage requires PHP curl and pcntl.');
        }
        $this->tempDir = sys_get_temp_dir() . '/retry-after-test-' . uniqid();
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function completionBody(string $boundary): string
    {
        return "--{$boundary}\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: 0\r\n"
            . "X-Chunk-Type: completion\r\n"
            . "X-Status: complete\r\n"
            . "\r\n"
            . "\r\n"
            . "--{$boundary}--\r\n";
    }

    /**
     * @param string[] $additional_headers Additional response header lines.
     */
    private function httpResponse(
        string $status_line,
        string $content_type,
        string $body,
        array $additional_headers = []
    ): string {
        $response = "HTTP/1.1 {$status_line}\r\n"
            . "Content-Type: {$content_type}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";
        foreach ($additional_headers as $header) {
            $response .= $header . "\r\n";
        }
        return $response . "Connection: close\r\n\r\n" . $body;
    }

    /**
     * Start a child server which returns one supplied response per SQL
     * request, recording the wall-clock arrival time of each request to
     * $timestamp_log.
     *
     * @param string[] $responses Complete HTTP responses in request order.
     */
    private function startTimedSqlResponseServer(
        array $responses,
        string $cursor,
        string $timestamp_log
    ): array {
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
                file_put_contents(
                    $timestamp_log,
                    sprintf("%.6f\n", microtime(true)),
                    FILE_APPEND | LOCK_EX,
                );
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

    private function prepareWireSqlClient(string $url, string $cursor): array
    {
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

        return ['client' => $client, 'reflection' => $reflection];
    }

    /** @return float[] Recorded request arrival times, in request order. */
    private function readRequestTimestamps(string $timestamp_log): array
    {
        $contents = file_exists($timestamp_log) ? file_get_contents($timestamp_log) : '';
        $lines = array_values(array_filter(explode("\n", trim( (string) $contents))));
        return array_map( 'floatval', $lines );
    }

    /** @return array<int,array<string,mixed>> */
    private function readProgressRecords($progress_stream): array
    {
        rewind($progress_stream);
        $contents = stream_get_contents($progress_stream);
        $lines = array_filter(explode("\n", trim( (string) $contents)));

        return array_values( array_filter( array_map(
            static function (string $line): ?array {
                $record = json_decode($line, true);
                return is_array($record) ? $record : null;
            },
            $lines,
        ) ) );
    }

    public function testRetryAfterSecondsFormWaitsBeforeNextRequest()
    {
        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $timestamp_log = $this->tempDir . '/requests.log';
        $boundary = 'retry-after-seconds';
        $server = $this->startTimedSqlResponseServer(
            [
                $this->httpResponse(
                    '429 Too Many Requests',
                    'text/html',
                    '<!doctype html><title>Rate limited</title>',
                    ['Retry-After: 2'],
                ),
                $this->httpResponse(
                    '200 OK',
                    "multipart/mixed; boundary={$boundary}",
                    $this->completionBody($boundary),
                ),
            ],
            $cursor,
            $timestamp_log,
        );
        $wire_client = $this->prepareWireSqlClient($server['url'], $cursor);
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];
        $progress_stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($progress_stream);
        $reflection->getProperty('progress_fd')->setValue($client, $progress_stream);

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

        $timestamps = $this->readRequestTimestamps($timestamp_log);
        $this->assertCount(2, $timestamps, 'Both the 429 and the retry request should have arrived');
        $gap = $timestamps[1] - $timestamps[0];
        $this->assertGreaterThanOrEqual(1.7, $gap, 'The retry should wait out roughly the requested 2s delay');
        $this->assertLessThan(6.0, $gap, 'The retry should not wait dramatically longer than requested');

        $audit_log = file_get_contents($this->stateDir . '/audit.log');
        $this->assertStringContainsString('RETRY-AFTER WAIT', $audit_log);
        $this->assertStringContainsString('sql_chunk', $audit_log);
        $progress_records = $this->readProgressRecords($progress_stream);
        fclose($progress_stream);
        $this->assertNotEmpty( array_filter(
            $progress_records,
            static function (array $record): bool {
                return ( $record['type'] ?? null ) === 'retry_after_wait'
                    && ( $record['phase'] ?? null ) === 'sql_chunk'
                    && ( $record['wait_seconds_remaining'] ?? 0 ) >= 1.0;
            },
        ) );
    }

    public function testRetryAfterHttpDateFormWaitsBeforeNextRequest()
    {
        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $timestamp_log = $this->tempDir . '/requests.log';
        $boundary = 'retry-after-date';
        $retry_after_timestamp = time() + 4;
        $retry_after_date = gmdate('D, d M Y H:i:s', $retry_after_timestamp) . ' GMT';
        $server = $this->startTimedSqlResponseServer(
            [
                $this->httpResponse(
                    '503 Service Unavailable',
                    'text/html',
                    '<!doctype html><title>Maintenance</title>',
                    ["Retry-After: {$retry_after_date}"],
                ),
                $this->httpResponse(
                    '200 OK',
                    "multipart/mixed; boundary={$boundary}",
                    $this->completionBody($boundary),
                ),
            ],
            $cursor,
            $timestamp_log,
        );
        $wire_client = $this->prepareWireSqlClient($server['url'], $cursor);
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        $timestamps = $this->readRequestTimestamps($timestamp_log);
        $this->assertCount(2, $timestamps);
        $this->assertGreaterThanOrEqual(
            $retry_after_timestamp - 0.2,
            $timestamps[1],
            'The retry must not arrive before the Retry-After HTTP-date.',
        );
    }

    public function testRetryAfterInvalidValueDoesNotWait()
    {
        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $timestamp_log = $this->tempDir . '/requests.log';
        $boundary = 'retry-after-invalid';
        $server = $this->startTimedSqlResponseServer(
            [
                $this->httpResponse(
                    '503 Service Unavailable',
                    'text/html',
                    '<!doctype html><title>Maintenance</title>',
                    ['Retry-After: not-a-real-value'],
                ),
                $this->httpResponse(
                    '200 OK',
                    "multipart/mixed; boundary={$boundary}",
                    $this->completionBody($boundary),
                ),
            ],
            $cursor,
            $timestamp_log,
        );
        $wire_client = $this->prepareWireSqlClient($server['url'], $cursor);
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        $timestamps = $this->readRequestTimestamps($timestamp_log);
        $this->assertCount(2, $timestamps);
        $gap = $timestamps[1] - $timestamps[0];
        $this->assertLessThan(1.0, $gap, 'An unparseable Retry-After value must not delay the retry');
    }

    public function testRetryAfterPastDateDoesNotWait()
    {
        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $timestamp_log = $this->tempDir . '/requests.log';
        $boundary = 'retry-after-past';
        $past_date = gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT';
        $server = $this->startTimedSqlResponseServer(
            [
                $this->httpResponse(
                    '503 Service Unavailable',
                    'text/html',
                    '<!doctype html><title>Maintenance</title>',
                    ["Retry-After: {$past_date}"],
                ),
                $this->httpResponse(
                    '200 OK',
                    "multipart/mixed; boundary={$boundary}",
                    $this->completionBody($boundary),
                ),
            ],
            $cursor,
            $timestamp_log,
        );
        $wire_client = $this->prepareWireSqlClient($server['url'], $cursor);
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        $timestamps = $this->readRequestTimestamps($timestamp_log);
        $this->assertCount(2, $timestamps);
        $gap = $timestamps[1] - $timestamps[0];
        $this->assertLessThan(1.0, $gap, 'A Retry-After date already in the past must not delay the retry');
    }

    public function testRetryAfterNotAppliedToDeliberateReprintJsonFailure()
    {
        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $timestamp_log = $this->tempDir . '/requests.log';
        $reprint_error_body = json_encode([
            'code' => 429,
            'message' => 'Rate limited by Reprint itself',
        ]);
        $server = $this->startTimedSqlResponseServer(
            [
                $this->httpResponse(
                    '429 Too Many Requests',
                    'application/json',
                    $reprint_error_body,
                    ['Retry-After: 2'],
                ),
            ],
            $cursor,
            $timestamp_log,
        );
        $wire_client = $this->prepareWireSqlClient($server['url'], $cursor);
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
            $this->fail('A deliberate Reprint JSON failure must not be retried');
        } catch (\RuntimeException $e) {
            // Expected: permanent failure, not the no-progress RuntimeException.
            $this->assertStringNotContainsString('consecutive times', $e->getMessage());
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        $timestamps = $this->readRequestTimestamps($timestamp_log);
        $this->assertCount(1, $timestamps, 'A deliberate Reprint failure must not trigger a retry request');
        $this->assertSame(
            0,
            $client->get_state()->consecutive_interrupted_responses,
            'A permanent failure must not consume the no-progress retry budget',
        );
    }

    public function testRetryAfterExceedingMaximumStopsWithClearMessage()
    {
        $cursor = base64_encode('{"table":"wp_posts","pk":42}');
        $timestamp_log = $this->tempDir . '/requests.log';
        $server = $this->startTimedSqlResponseServer(
            [
                $this->httpResponse(
                    '503 Service Unavailable',
                    'text/html',
                    '<!doctype html><title>Maintenance</title>',
                    ['Retry-After: 301'],
                ),
            ],
            $cursor,
            $timestamp_log,
        );
        $wire_client = $this->prepareWireSqlClient($server['url'], $cursor);
        $client = $wire_client['client'];
        $reflection = $wire_client['reflection'];

        $started_at = microtime(true);
        try {
            $reflection->getMethod('fetch_sql')->invoke($client);
            $this->fail('A Retry-After beyond the supported maximum must stop instead of retrying');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retry-After', $e->getMessage());
            $this->assertStringContainsString('exceeds', $e->getMessage());
        } finally {
            pcntl_waitpid($server['child_pid'], $status);
        }
        $elapsed = microtime(true) - $started_at;

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertLessThan(5.0, $elapsed, 'The importer must stop immediately, not wait out the oversized delay');

        $timestamps = $this->readRequestTimestamps($timestamp_log);
        $this->assertCount(1, $timestamps, 'An oversized Retry-After must not trigger a retry request');
    }
}
