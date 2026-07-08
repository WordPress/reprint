<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../packages/reprint-exporter/src/utils.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-file-chunk-stream-writer.php';

final class FileChunkStreamWriterTest extends TestCase
{
    public function testWritesFilePartWithMultipartHeaders(): void
    {
        $stream = new TestFileChunkOutputStream();
        $writer = new Site_Export_File_Chunk_Stream_Writer($stream, 'boundary');

        $writer->write_file([
            'data' => 'hello',
            'path' => 'wp-content/uploads/a.txt',
            'size' => 12,
            'ctime' => 123,
            'offset' => 7,
            'is_first_chunk' => false,
            'is_last_chunk' => true,
        ], 'cursor-value');

        $this->assertSame(
            "--boundary\r\n" .
            "Content-Type: application/octet-stream\r\n" .
            "Content-Length: 5\r\n" .
            "X-Chunk-Type: file\r\n" .
            "X-Cursor: " . base64_encode('cursor-value') . "\r\n" .
            "X-File-Path: " . base64_encode('wp-content/uploads/a.txt') . "\r\n" .
            "X-File-Size: 12\r\n" .
            "X-File-Ctime: 123\r\n" .
            "X-Chunk-Offset: 7\r\n" .
            "X-Chunk-Size: 5\r\n" .
            "X-First-Chunk: 0\r\n" .
            "X-Last-Chunk: 1\r\n" .
            "\r\n" .
            "hello\r\n",
            $stream->body
        );
        $this->assertSame(1, $stream->syncs);
    }
}

final class TestFileChunkOutputStream
{
    public string $body = '';

    public int $syncs = 0;

    public function write(string $body): void
    {
        $this->body .= $body;
    }

    public function sync(): void
    {
        $this->syncs++;
    }
}
