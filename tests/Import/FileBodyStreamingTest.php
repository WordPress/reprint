<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\MultipartStreamParser;
use Reprint\Importer\Remote\RemoteExportApiClient;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class FileBodyStreamingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-file-body-streaming-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/state', 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testFilePartBodiesAreWrittenIncrementally(): void
    {
        $client = new \ImportClient(
            'http://fake.url',
            $this->tempDir . '/state',
            $this->tempDir . '/fs-root',
        );
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('is_tty')->setValue($client, true);

        $handleFileChunk = $reflection->getMethod('handle_file_chunk');
        $context = new StreamingContext();
        $bodyLengths = [];
        $context->on_chunk = function (array $chunk) use ($client, $handleFileChunk, $context, &$bodyLengths): void {
            if (($chunk['headers']['x-chunk-type'] ?? '') === 'file') {
                $bodyLengths[] = strlen($chunk['body'] ?? '');
            }
            $handleFileChunk->invoke($client, $chunk, $context);
        };

        $handler = $this->makeTransportHandler($context->on_chunk);
        $parser = new MultipartStreamParser('BOUNDARY', $handler);

        $body = str_repeat('0123456789abcdef', 64 * 1024);
        $multipart = $this->buildMultipart('BOUNDARY', [
            [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) strlen($body),
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('/uploads/big.bin'),
                    'X-File-Size' => (string) strlen($body),
                    'X-File-Ctime' => '1234567890',
                    'X-Chunk-Offset' => '0',
                    'X-Chunk-Size' => (string) strlen($body),
                    'X-First-Chunk' => '1',
                    'X-Last-Chunk' => '1',
                ],
                'body' => $body,
            ],
        ]);

        for ($offset = 0; $offset < strlen($multipart); $offset += 8192) {
            $parser->feed(substr($multipart, $offset, 8192));
        }

        $target = $this->tempDir . '/fs-root/uploads/big.bin';
        $this->assertSame($body, file_get_contents($target));

        $nonEmptyBodies = array_values(array_filter(
            $bodyLengths,
            static fn(int $length): bool => $length > 0,
        ));
        $this->assertGreaterThan(1, count($nonEmptyBodies));
        $this->assertLessThan(strlen($body), max($nonEmptyBodies));
    }

    /**
     * The PR's whole point is partial on-disk writes before a part completes.
     * That means a request cut mid-body now leaves bytes already written —
     * the previous behaviour discarded an in-flight buffer instead. The risk
     * surface is double-counting (server resends bytes already on disk) or
     * truncation (resume re-opens with "wb" and wipes the partial file). This
     * test pins the contract: feed a part's first half, simulate a crash,
     * reopen the file in append mode the way fetch_file_batch() does on
     * resume, then feed the second half as a continuation part with
     * x-first-chunk=0. The result must be byte-identical to the source —
     * no gap, no duplication.
     */
    public function testMidFileResumeAppendsRemainingBytesWithoutDuplication(): void
    {
        $client = new \ImportClient(
            'http://fake.url',
            $this->tempDir . '/state',
            $this->tempDir . '/fs-root',
        );
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('is_tty')->setValue($client, true);

        $body = str_repeat('0123456789abcdef', 64 * 1024); // 1 MiB
        $halfwayPoint = (int) (strlen($body) / 2);
        $firstHalf = substr($body, 0, $halfwayPoint);
        $secondHalf = substr($body, $halfwayPoint);

        // Pass 1: stream the first half. The remote-side cursor would still
        // point at the start of this part because the server never finished
        // sending it — so on resume we re-receive the whole part body. To
        // mimic the *intended* behaviour (server cooperates and skips the
        // already-written prefix), pass 2 sends only the missing tail.
        $context1 = new StreamingContext();
        $handleFileChunk = $reflection->getMethod('handle_file_chunk');
        $context1->on_chunk = function (array $chunk) use ($client, $handleFileChunk, $context1): void {
            $handleFileChunk->invoke($client, $chunk, $context1);
        };
        $handler1 = $this->makeTransportHandler($context1->on_chunk);
        $parser1 = new MultipartStreamParser('BOUNDARY', $handler1);

        $multipart1 = $this->buildMultipart('BOUNDARY', [
            [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) strlen($firstHalf),
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('/uploads/resume.bin'),
                    'X-File-Size' => (string) strlen($body),
                    'X-File-Ctime' => '1234567890',
                    'X-Chunk-Offset' => '0',
                    'X-Chunk-Size' => (string) strlen($firstHalf),
                    'X-First-Chunk' => '1',
                    // No x-last-chunk: the part finishes (parser emits
                    // complete) but the file is still mid-stream.
                    'X-Last-Chunk' => '0',
                ],
                'body' => $firstHalf,
            ],
        ]);
        for ($offset = 0; $offset < strlen($multipart1); $offset += 8192) {
            $parser1->feed(substr($multipart1, $offset, 8192));
        }

        $target = $this->tempDir . '/fs-root/uploads/resume.bin';
        $this->assertFileExists($target);
        $this->assertSame($firstHalf, file_get_contents($target),
            'After pass 1 the on-disk file should hold exactly the first half — no padding, no buffering past the body.');
        $this->assertSame($halfwayPoint, $context1->file_bytes_written,
            'file_bytes_written must reflect actual on-disk bytes; that is the value we will save into state for resume.');

        // Mimic the crash + reopen path from fetch_file_batch():
        // close the in-flight handle, then on the next request reopen the
        // tracked file in append mode using the previously-saved byte count.
        if ($context1->file_handle) {
            fclose($context1->file_handle);
            $context1->file_handle = null;
        }
        $trackedBytes = $context1->file_bytes_written;

        $context2 = new StreamingContext();
        $context2->file_handle = fopen($target, 'ab');
        $context2->file_path = $target;
        $context2->file_ctime = 1234567890;
        $context2->file_bytes_written = $trackedBytes;
        $context2->on_chunk = function (array $chunk) use ($client, $handleFileChunk, $context2): void {
            $handleFileChunk->invoke($client, $chunk, $context2);
        };
        $handler2 = $this->makeTransportHandler($context2->on_chunk);
        $parser2 = new MultipartStreamParser('BOUNDARY', $handler2);

        // Pass 2: continuation part for the same file. x-first-chunk=0 is the
        // signal that this is a resume, not a fresh open — handle_file_chunk
        // must NOT re-truncate via fopen("wb"), and must NOT skip the body.
        $multipart2 = $this->buildMultipart('BOUNDARY', [
            [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) strlen($secondHalf),
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('/uploads/resume.bin'),
                    'X-File-Size' => (string) strlen($body),
                    'X-File-Ctime' => '1234567890',
                    'X-Chunk-Offset' => (string) $halfwayPoint,
                    'X-Chunk-Size' => (string) strlen($secondHalf),
                    'X-First-Chunk' => '0',
                    'X-Last-Chunk' => '1',
                ],
                'body' => $secondHalf,
            ],
        ]);
        for ($offset = 0; $offset < strlen($multipart2); $offset += 8192) {
            $parser2->feed(substr($multipart2, $offset, 8192));
        }

        $finalContents = file_get_contents($target);
        $this->assertSame(strlen($body), strlen($finalContents),
            'Final file size must equal source size — anything else means duplicated bytes (overlap) or missing bytes (gap).');
        $this->assertSame($body, $finalContents,
            'Final file must be byte-identical to source after mid-file resume.');
    }

    public function testStreamingHeartbeatsUseTheSelectedProgressOutput(): void
    {
        if (!function_exists('curl_init') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Streaming progress coverage requires PHP curl and pcntl.');
        }

        $ttyOutput = $this->fetchSlowResponseWithProgressMode('tty', false);
        $this->assertSame('', $ttyOutput);

        $jsonlOutput = $this->fetchSlowResponseWithProgressMode('jsonl', true);
        $records = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(preg_split('/\R/', trim($jsonlOutput)) ?: []))
        );
        $heartbeatRecords = array_values(array_filter(
            $records,
            static fn(array $record): bool => ( $record['heartbeat'] ?? false ) === true
        ));
        $this->assertNotEmpty($heartbeatRecords, $jsonlOutput);
    }

    private function fetchSlowResponseWithProgressMode(
        string $progressMode,
        bool $progressStreamIsTty
    ): string {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertNotFalse($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        $this->assertIsString($address);

        $child = pcntl_fork();
        $this->assertNotSame(-1, $child);
        if ($child === 0) {
            $connection = stream_socket_accept($listener, 5);
            if ($connection === false) {
                exit(2);
            }
            stream_set_timeout($connection, 5);
            $request = '';
            while (strpos($request, "\r\n\r\n") === false) {
                $requestChunk = fread($connection, 8192);
                if ($requestChunk === false || $requestChunk === '') {
                    fclose($connection);
                    fclose($listener);
                    exit(3);
                }
                $request .= $requestChunk;
            }

            $boundary = 'progress-output-test';
            $body = "--{$boundary}\r\n"
                . "X-Chunk-Type: completion\r\n"
                . "Content-Length: 0\r\n\r\n\r\n"
                . "--{$boundary}--\r\n";
            $headers = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: multipart/mixed; boundary={$boundary}\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n";
            $splitAt = (int) floor(strlen($body) / 2);
            fwrite($connection, $headers . substr($body, 0, $splitAt));
            fflush($connection);
            usleep(1200000);
            fwrite($connection, substr($body, $splitAt));
            fclose($connection);
            fclose($listener);
            exit(0);
        }

        fclose($listener);
        $output = fopen('php://temp', 'w+');
        $this->assertIsResource($output);
        $client = new \ImportClient(
            'http://' . $address,
            $this->tempDir . '/state',
            $this->tempDir . '/fs-root'
        );
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('is_tty')->setValue($client, $progressStreamIsTty);
        $reflection->getProperty('progress_output_mode')->setValue($client, $progressMode);
        $reflection->getProperty('progress_fd')->setValue($client, $output);
        $progress = $reflection->getProperty('progress')->getValue($client);
        $progress->set_progress_fd($output);
        $progress->set_terminal_output_enabled($progressMode === 'tty');

        $context = new StreamingContext();
        $context->on_chunk = static function (array $chunk) use ($context): void {
            if (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'completion') {
                $context->saw_completion = true;
            }
        };

        try {
            $reflection->getMethod('fetch_streaming')->invoke(
                $client,
                'http://' . $address . '/stream',
                null,
                $context
            );
        } finally {
            pcntl_waitpid($child, $status);
        }
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);
        $this->assertIsString($contents);
        return $contents;
    }

    private function buildMultipart(string $boundary, array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= "--{$boundary}\r\n";
            foreach ($part['headers'] as $name => $value) {
                $out .= "{$name}: {$value}\r\n";
            }
            $out .= "\r\n" . $part['body'] . "\r\n";
        }
        $out .= "--{$boundary}--\r\n";
        return $out;
    }

    private function makeTransportHandler(callable $onChunk): callable
    {
        $remoteApiClient = new RemoteExportApiClient(
            'http://fake.url',
            null,
            static function (): void {},
            static function (): void {},
        );
        $reflection = new \ReflectionClass($remoteApiClient);
        $makeHandler = $reflection->getMethod('make_chunk_handler');
        $currentChunk = null;
        $sawCompletion = false;
        return $makeHandler->invokeArgs(
            $remoteApiClient,
            [$onChunk, &$currentChunk, &$sawCompletion],
        );
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
}
