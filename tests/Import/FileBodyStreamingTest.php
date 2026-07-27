<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\MultipartStreamParser;

require_once __DIR__ . '/../../importer/import.php';

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
        $reflection->getProperty('state')->setValue(
            $client,
            $this->initialState()
        );

        $handleFileChunk = $reflection->getMethod('handle_file_chunk');
        $context = new \StreamingContext();
        $this->setPlannedPath($context, '/uploads/big.bin');
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

    public function testMidFileResumeTruncatesUnconfirmedStagingBytes(): void
    {
        $client = new \ImportClient(
            'http://fake.url',
            $this->tempDir . '/state',
            $this->tempDir . '/fs-root',
        );
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('is_tty')->setValue($client, true);
        $reflection->getProperty('state')->setValue(
            $client,
            $this->initialState()
        );

        $body = str_repeat('0123456789abcdef', 64 * 1024);
        $halfwayPoint = (int) (strlen($body) / 2);
        $firstHalf = substr($body, 0, $halfwayPoint);
        $secondHalf = substr($body, $halfwayPoint);
        $context1 = new \StreamingContext();
        $this->setPlannedPath($context1, '/uploads/resume.bin');
        $handleFileChunk = $reflection->getMethod('handle_file_chunk');
        $handleFileChunk->invoke($client, [
            'headers' => [
                'x-chunk-type' => 'file',
                'x-file-path' =>
                    base64_encode('/uploads/resume.bin'),
                'x-file-size' => (string) strlen($body),
                'x-file-ctime' => '1234567890',
                'x-first-chunk' => '1',
                'x-last-chunk' => '0',
            ],
            'body' => $firstHalf,
        ], $context1);
        $target = $this->tempDir . '/fs-root/uploads/resume.bin';
        $this->assertFileDoesNotExist($target);
        $fetchState = $client->get_import_state()->fetch;
        $this->assertIsArray($fetchState->staged_file);
        $recordBoundary = $reflection->getMethod(
            'record_fetched_file_staging_boundary'
        );
        $recordBoundary->invoke($client, $fetchState, $context1);
        $this->assertSame(
            $halfwayPoint,
            $fetchState->staged_file['staging_bytes']
        );
        $stagingPath = base64_decode(
            $fetchState->staged_file['staging_path_b64'],
            true
        );
        $this->assertIsString($stagingPath);
        $this->assertSame($firstHalf, file_get_contents($stagingPath));
        $stagingStat = lstat($stagingPath);
        $this->assertIsArray($stagingStat);
        $this->assertSame(
            0600,
            (int) $stagingStat['mode'] & 07777
        );
        fclose($context1->file_handle);
        $context1->file_handle = null;
        file_put_contents(
            $stagingPath,
            'unconfirmed',
            FILE_APPEND
        );

        $context2 = new \StreamingContext();
        $this->setPlannedPath($context2, '/uploads/resume.bin');
        $resumeStaging = $reflection->getMethod(
            'resume_fetched_file_staging'
        );
        $resumeStaging->invoke($client, $fetchState, $context2);
        clearstatcache(true, $stagingPath);
        $this->assertSame($halfwayPoint, filesize($stagingPath));
        $handleFileChunk->invoke($client, [
            'headers' => [
                'x-chunk-type' => 'file',
                'x-file-path' =>
                    base64_encode('/uploads/resume.bin'),
                'x-file-size' => (string) strlen($body),
                'x-file-ctime' => '1234567890',
                'x-cursor' => 'complete-file',
                'x-first-chunk' => '0',
                'x-last-chunk' => '1',
            ],
            'body' => $secondHalf,
        ], $context2);

        $this->assertSame($body, file_get_contents($target));
        $this->assertFileDoesNotExist($stagingPath);
    }

    /** @return array<string,mixed> */
    private function initialState(): array
    {
        return [
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '/',
                    ],
                ],
            ],
        ];
    }

    private function setPlannedPath(
        \StreamingContext $context,
        string $path
    ): void {
        $context->planned_local_state_checked_path = $path;
        $context->planned_local_state_checked_result = [
            'validate' => false,
            'expected' => null,
        ];
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
