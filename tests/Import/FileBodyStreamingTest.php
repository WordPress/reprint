<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\MultipartStreamParser;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../client/cli.php';

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

        $currentChunk = null;
        $makeHandler = $reflection->getMethod('make_chunk_handler');
        $handler = $makeHandler->invokeArgs($client, [$context, &$currentChunk]);
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
        $currentChunk1 = null;
        $makeHandler = $reflection->getMethod('make_chunk_handler');
        $handler1 = $makeHandler->invokeArgs($client, [$context1, &$currentChunk1]);
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
        $currentChunk2 = null;
        $handler2 = $makeHandler->invokeArgs($client, [$context2, &$currentChunk2]);
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
