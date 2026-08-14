<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class DatabaseCommandRestartTest extends TestCase
{
    private string $root;
    /** @var int[] */
    private array $childPids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/reprint-database-restart-' . uniqid('', true);
        mkdir($this->root . '/state', 0755, true);
        mkdir($this->root . '/files', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->childPids as $childPid) {
            $waitResult = pcntl_waitpid($childPid, $status, WNOHANG);
            if ($waitResult === 0 && function_exists('posix_kill') && defined('SIGKILL')) {
                posix_kill($childPid, SIGKILL);
                pcntl_waitpid($childPid, $status);
            }
        }
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testDbPullContinuesAfterExitCodeTwo(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('The local streaming endpoint requires pcntl.');
        }

        $sql = "SELECT 2;\n";
        [$remoteUrl, $serverPid] = $this->startOneResponseServer(function (array $query) use ($sql): string {
            $this->assertSame('sql_chunk', $query['endpoint'] ?? null);
            $this->assertSame('saved-sql-cursor', base64_decode($query['cursor'] ?? '', true));
            return $this->multipartResponse([
                [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('final-sql-cursor'),
                    ],
                    'body' => $sql,
                ],
                [
                    'headers' => [
                        'X-Chunk-Type' => 'completion',
                        'X-Status' => 'complete',
                    ],
                    'body' => '',
                ],
            ]);
        });

        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpIntent($client);
        $state = $client->get_state();
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'partial';
        $state->active_resumable_command->current_stage = 'sql';
        $state->active_resumable_command->remote_cursor = base64_encode('saved-sql-cursor');
        $state->sql_bytes = strlen("SELECT 1;\n");
        $state->sql_output = 'file';
        $client->save_state();
        file_put_contents($this->root . '/state/db.sql', "SELECT 1;\n");

        try {
            $result = $this->runCli([
                'db-pull',
                $remoteUrl,
                '--state-dir=' . $this->root . '/state',
                '--fs-root=' . $this->root . '/files',
                '--progress=jsonl',
            ]);
        } finally {
            pcntl_waitpid($serverPid, $serverStatus);
        }

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('"event":"resuming"', $result['output']);
        $this->assertSame("SELECT 1;\nSELECT 2;\n", file_get_contents($this->root . '/state/db.sql'));
        $dumpRecord = json_decode(
            (string) file_get_contents($client->pull_state_directory . '/database-dump.json'),
            true,
        );
        $this->assertIsArray($dumpRecord);
        $this->assertSame(
            hash_file('sha256', $this->root . '/state/db.sql'),
            $dumpRecord['sha256'] ?? null,
        );
        $this->assertTrue($dumpRecord['create_table_query'] ?? false);
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
    }

    public function testDbPullCrashKeepsThePartNamedByItsSavedCursor(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !defined('SIGKILL')) {
            $this->markTestSkipped('The process-death test requires pcntl and posix signals.');
        }

        $readyPath = $this->root . '/sql-stream-ready';
        $releasePath = $this->root . '/sql-stream-release';
        [$remoteUrl, $serverPid] = $this->startTwoResponseSqlServer($readyPath, $releasePath);
        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpIntent($client);
        $state = $client->get_state();
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'in_progress';
        $state->active_resumable_command->current_stage = 'sql';
        $state->sql_output = 'file';
        $client->save_state();

        [$process] = $this->startCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ], true);

        $pullStatePath = $client->pull_state_directory . '/state.json';
        $expectedFirstResponse = $this->sqlStatements(1, 50);
        $this->waitUntil(function () use ($readyPath, $pullStatePath, $expectedFirstResponse): bool {
            if (!is_file($readyPath) || !is_file($pullStatePath)) {
                return false;
            }
            $state = json_decode( (string) file_get_contents($pullStatePath), true );
            return base64_decode($state['active_resumable_command']['remote_cursor'] ?? '', true) === 'cursor-50'
                && is_file($this->root . '/state/db.sql')
                && filesize($this->root . '/state/db.sql') === strlen($expectedFirstResponse);
        }, 'The first process did not store the cursor for SQL part 50.');

        $status = proc_get_status($process);
        $this->assertTrue($status['running']);
        $this->assertTrue(posix_kill($status['pid'], SIGKILL));
        proc_close($process);
        file_put_contents($releasePath, '');

        $result = $this->runCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ]);
        pcntl_waitpid($serverPid, $serverStatus);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($this->sqlStatements(1, 60), file_get_contents($this->root . '/state/db.sql'));
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
    }

    public function testDbPullDropsCursorlessBytesBeforeSavingItsFirstBoundary(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !defined('SIGKILL')) {
            $this->markTestSkipped('The process-death test requires pcntl and posix signals.');
        }

        $firstReadyPath = $this->root . '/first-sql-stream-ready';
        $firstReleasePath = $this->root . '/first-sql-stream-release';
        $secondReadyPath = $this->root . '/second-sql-stream-ready';
        $secondReleasePath = $this->root . '/second-sql-stream-release';
        [$remoteUrl, $serverPid] = $this->startThreeResponseSqlServer(
            $firstReadyPath,
            $firstReleasePath,
            $secondReadyPath,
            $secondReleasePath,
        );
        $client = $this->newClient($remoteUrl);
        $this->writeReplacementDumpIntent($client);
        $state = $client->get_state();
        $state->active_resumable_command->command_name = 'db-pull';
        $state->active_resumable_command->completion_state = 'in_progress';
        $state->active_resumable_command->current_stage = 'sql';
        $state->sql_output = 'file';
        $client->save_state();

        [$firstProcess] = $this->startCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ], true);

        $pullStatePath = $client->pull_state_directory . '/state.json';
        $firstUnconfirmedBytes = $this->sqlStatements(1, 10);
        $this->waitUntil(function () use ($firstReadyPath, $pullStatePath, $firstUnconfirmedBytes): bool {
            if (!is_file($firstReadyPath) || !is_file($pullStatePath)) {
                return false;
            }
            $state = json_decode( (string) file_get_contents($pullStatePath), true );
            return empty($state['active_resumable_command']['remote_cursor'])
                && is_file($this->root . '/state/db.sql')
                && filesize($this->root . '/state/db.sql') === strlen($firstUnconfirmedBytes);
        }, 'The first process did not write bytes before its first saved cursor.');

        $firstStatus = proc_get_status($firstProcess);
        $this->assertTrue($firstStatus['running']);
        $this->assertTrue(posix_kill($firstStatus['pid'], SIGKILL));
        proc_close($firstProcess);
        file_put_contents($firstReleasePath, '');

        [$secondProcess] = $this->startCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ], true);

        $secondCheckpointState = null;
        $this->waitUntil(function () use (
            $secondProcess,
            $secondReadyPath,
            $pullStatePath,
            &$secondCheckpointState
        ): bool {
            if (!is_file($secondReadyPath) || !is_file($pullStatePath)) {
                return false;
            }
            if (!proc_get_status($secondProcess)['running']) {
                return false;
            }
            $state = json_decode( (string) file_get_contents($pullStatePath), true );
            if (base64_decode($state['active_resumable_command']['remote_cursor'] ?? '', true) !== 'cursor-50') {
                return false;
            }
            $secondCheckpointState = $state;
            return true;
        }, 'The second process did not save its first SQL cursor.');

        $secondStatus = proc_get_status($secondProcess);
        $this->assertTrue($secondStatus['running']);
        $this->assertTrue(posix_kill($secondStatus['pid'], SIGKILL));
        proc_close($secondProcess);
        file_put_contents($secondReleasePath, '');

        $expectedCheckpointBytes = $this->sqlStatements(1, 50);
        $this->assertSame(strlen($expectedCheckpointBytes), $secondCheckpointState['sql_bytes']);
        $this->assertSame(strlen($expectedCheckpointBytes), filesize($this->root . '/state/db.sql'));

        $result = $this->runCli([
            'db-pull',
            $remoteUrl,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/files',
            '--progress=jsonl',
        ]);
        pcntl_waitpid($serverPid, $serverStatus);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($this->sqlStatements(1, 60), file_get_contents($this->root . '/state/db.sql'));
        $this->assertTrue(pcntl_wifexited($serverStatus));
        $this->assertSame(0, pcntl_wexitstatus($serverStatus));
    }

    private function newClient(string $remoteUrl): \ImportClient
    {
        $client = new \ImportClient(
            $remoteUrl,
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'http_code' => 200,
            'data' => ['ok' => true],
        ]);
        $client->save_state();
        return $client;
    }

    private function writeReplacementDumpIntent(\ImportClient $client): void
    {
        file_put_contents(
            $client->pull_state_directory . '/database-dump.intent',
            json_encode(['create_table_query' => true], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{exit:int,stdout:string,stderr:string,output:string} */
    private function runCli(array $arguments): array
    {
        [$process, $pipes] = $this->startCli($arguments);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [
            'exit' => $exit,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'output' => (string) $stdout . (string) $stderr,
        ];
    }

    /** @return array{0:resource,1:array<int,resource>} */
    private function startCli(array $arguments, bool $discardOutput = false): array
    {
        $descriptors = $discardOutput
            ? [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']]
            : [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client'], $arguments),
            $descriptors,
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @return array{0:string,1:int} */
    private function startOneResponseServer(callable $response, int $acceptTimeout = 10): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($listener, $acceptTimeout);
            if (!is_resource($connection)) {
                exit(2);
            }
            $request = $this->readHttpRequest($connection);
            parse_str( (string) parse_url($request['target'], PHP_URL_QUERY), $query );
            fwrite($connection, $response($query));
            fclose($connection);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{0:string,1:int} */
    private function startTwoResponseSqlServer(string $readyPath, string $releasePath): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $first = stream_socket_accept($listener, 10);
            if (!is_resource($first)) {
                exit(2);
            }
            $firstRequest = $this->readHttpRequest($first);
            parse_str( (string) parse_url($firstRequest['target'], PHP_URL_QUERY), $firstQuery );
            if ( ( $firstQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(3);
            }
            fwrite($first, $this->multipartResponseHeaders('restart-boundary'));
            for ($part = 1; $part <= 50; $part++) {
                fwrite($first, $this->multipartPart('restart-boundary', [
                    'X-Chunk-Type' => 'sql',
                    'X-Query-Complete' => '1',
                    'X-Cursor' => base64_encode('cursor-' . $part),
                ], sprintf("SELECT %d;\n", $part)));
                fflush($first);
            }
            file_put_contents($readyPath, '');
            while (!is_file($releasePath)) {
                usleep(10000);
            }
            fclose($first);

            $second = stream_socket_accept($listener, 10);
            if (!is_resource($second)) {
                exit(4);
            }
            $request = $this->readHttpRequest($second);
            parse_str( (string) parse_url($request['target'], PHP_URL_QUERY), $query );
            if (base64_decode($query['cursor'] ?? '', true) !== 'cursor-50') {
                exit(5);
            }
            if ( ( $query['create_table_query'] ?? null ) !== '1' ) {
                exit(6);
            }
            $parts = [];
            for ($part = 51; $part <= 60; $part++) {
                $parts[] = [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('cursor-' . $part),
                    ],
                    'body' => sprintf("SELECT %d;\n", $part),
                ];
            }
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'completion',
                    'X-Status' => 'complete',
                ],
                'body' => '',
            ];
            fwrite($second, $this->multipartResponse($parts, 'restart-boundary'));
            fclose($second);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{0:string,1:int} */
    private function startThreeResponseSqlServer(
        string $firstReadyPath,
        string $firstReleasePath,
        string $secondReadyPath,
        string $secondReleasePath
    ): array {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $first = stream_socket_accept($listener, 10);
            if (!is_resource($first)) {
                exit(2);
            }
            $firstRequest = $this->readHttpRequest($first);
            parse_str( (string) parse_url($firstRequest['target'], PHP_URL_QUERY), $firstQuery );
            if (isset($firstQuery['cursor'])) {
                exit(3);
            }
            if ( ( $firstQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(4);
            }
            fwrite($first, $this->multipartResponseHeaders('cursorless-boundary'));
            for ($part = 1; $part <= 10; $part++) {
                fwrite($first, $this->multipartPart('cursorless-boundary', [
                    'X-Chunk-Type' => 'sql',
                    'X-Query-Complete' => '1',
                    'X-Cursor' => base64_encode('unconfirmed-' . $part),
                ], sprintf("SELECT %d;\n", $part)));
                fflush($first);
            }
            file_put_contents($firstReadyPath, '');
            while (!is_file($firstReleasePath)) {
                usleep(10000);
            }
            fclose($first);

            $second = stream_socket_accept($listener, 10);
            if (!is_resource($second)) {
                exit(5);
            }
            $secondRequest = $this->readHttpRequest($second);
            parse_str( (string) parse_url($secondRequest['target'], PHP_URL_QUERY), $secondQuery );
            if (isset($secondQuery['cursor'])) {
                exit(6);
            }
            if ( ( $secondQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(7);
            }
            fwrite($second, $this->multipartResponseHeaders('cursorless-boundary'));
            for ($part = 1; $part <= 50; $part++) {
                fwrite($second, $this->multipartPart('cursorless-boundary', [
                    'X-Chunk-Type' => 'sql',
                    'X-Query-Complete' => '1',
                    'X-Cursor' => base64_encode('cursor-' . $part),
                ], sprintf("SELECT %d;\n", $part)));
                fflush($second);
            }
            file_put_contents($secondReadyPath, '');
            while (!is_file($secondReleasePath)) {
                usleep(10000);
            }
            fclose($second);

            $third = stream_socket_accept($listener, 10);
            if (!is_resource($third)) {
                exit(8);
            }
            $thirdRequest = $this->readHttpRequest($third);
            parse_str( (string) parse_url($thirdRequest['target'], PHP_URL_QUERY), $thirdQuery );
            if (base64_decode($thirdQuery['cursor'] ?? '', true) !== 'cursor-50') {
                exit(9);
            }
            if ( ( $thirdQuery['create_table_query'] ?? null ) !== '1' ) {
                exit(10);
            }
            $parts = [];
            for ($part = 51; $part <= 60; $part++) {
                $parts[] = [
                    'headers' => [
                        'X-Chunk-Type' => 'sql',
                        'X-Query-Complete' => '1',
                        'X-Cursor' => base64_encode('cursor-' . $part),
                    ],
                    'body' => sprintf("SELECT %d;\n", $part),
                ];
            }
            $parts[] = [
                'headers' => [
                    'X-Chunk-Type' => 'completion',
                    'X-Status' => 'complete',
                ],
                'body' => '',
            ];
            fwrite($third, $this->multipartResponse($parts, 'cursorless-boundary'));
            fclose($third);
            fclose($listener);
            exit(0);
        }
        fclose($listener);
        $this->childPids[] = $pid;
        return ['http://' . $address . '/export', $pid];
    }

    /** @return array{target:string} */
    private function readHttpRequest($connection): array
    {
        stream_set_timeout($connection, 10);
        $request = '';
        while (strpos($request, "\r\n\r\n") === false) {
            $chunk = fread($connection, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }
        $requestLine = strtok($request, "\r\n");
        $parts = is_string($requestLine) ? explode(' ', $requestLine) : [];
        return ['target' => $parts[1] ?? ''];
    }

    private function multipartResponse(array $parts, string $boundary = 'database-restart'): string
    {
        $body = '';
        foreach ($parts as $part) {
            $body .= $this->multipartPart($boundary, $part['headers'], $part['body']);
        }
        $body .= "--{$boundary}--\r\n";
        return $this->multipartResponseHeaders($boundary, strlen($body)) . $body;
    }

    private function multipartResponseHeaders(string $boundary, ?int $contentLength = null): string
    {
        $headers = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: multipart/mixed; boundary={$boundary}\r\n"
            . "Connection: close\r\n";
        if ($contentLength !== null) {
            $headers .= "Content-Length: {$contentLength}\r\n";
        }
        return $headers . "\r\n";
    }

    private function multipartPart(string $boundary, array $headers, string $body): string
    {
        $part = "--{$boundary}\r\nContent-Length: " . strlen($body) . "\r\n";
        foreach ($headers as $name => $value) {
            $part .= "{$name}: {$value}\r\n";
        }
        return $part . "\r\n{$body}\r\n";
    }

    private function sqlStatements(int $first, int $last): string
    {
        $sql = '';
        for ($statement = $first; $statement <= $last; $statement++) {
            $sql .= sprintf("SELECT %d;\n", $statement);
        }
        return $sql;
    }

    private function waitUntil(callable $condition, string $failure): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            if ($condition()) {
                return;
            }
            usleep(10000);
        }
        $this->fail($failure);
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
