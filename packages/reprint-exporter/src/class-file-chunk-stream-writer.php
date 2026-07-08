<?php

use function WordPress\Reprint\Exporter\json_encode_or_throw;

/**
 * Writes file-transfer chunks into the multipart stream used by file_fetch.
 */
final class Site_Export_File_Chunk_Stream_Writer
{
    private $stream;

    private string $boundary;

    public function __construct($stream, string $boundary)
    {
        $this->stream = $stream;
        $this->boundary = $boundary;
    }

    public static function random_boundary(): string
    {
        return 'boundary-' . bin2hex(random_bytes(16));
    }

    public static function content_type(string $boundary): string
    {
        return 'multipart/mixed; boundary="' . $boundary . '"';
    }

    public function write_progress(array $progress, string $cursor): void
    {
        $this->write_json_part('progress', $progress, [
            'X-Cursor' => base64_encode($cursor),
        ]);
    }

    public function write_metadata(?string $filesystem_root): void
    {
        $metadata = [
            'filesystem_root' => base64_encode($filesystem_root ?? ''),
        ];
        $this->write_json_part('metadata', $metadata, [
            'X-Filesystem-Root' => base64_encode($filesystem_root ?? ''),
        ]);
    }

    public function write_directory(array $chunk, string $cursor): void
    {
        $headers = [
            'X-Cursor' => base64_encode($cursor),
            'X-Directory-Path' => base64_encode($chunk['path']),
        ];
        if (isset($chunk['ctime'])) {
            $headers['X-Directory-Ctime'] = (string) $chunk['ctime'];
        }
        $this->write_empty_part('directory', $headers);
    }

    public function write_symlink(array $chunk, string $cursor): void
    {
        $this->write_empty_part('symlink', [
            'X-Cursor' => base64_encode($cursor),
            'X-Symlink-Path' => base64_encode($chunk['path']),
            'X-Symlink-Target' => base64_encode($chunk['target']),
            'X-Symlink-Ctime' => (string) $chunk['ctime'],
        ]);
    }

    public function write_index(array $chunk, string $cursor): void
    {
        $this->write_empty_part('index', [
            'X-Cursor' => base64_encode($cursor),
            'X-Index-Path' => base64_encode($chunk['path']),
            'X-File-Ctime' => (string) $chunk['ctime'],
            'X-File-Size' => (string) $chunk['size'],
        ]);
    }

    public function write_missing(array $chunk, string $cursor): void
    {
        $this->write_empty_part('missing', [
            'X-Cursor' => base64_encode($cursor),
            'X-File-Path' => base64_encode($chunk['path']),
        ]);
    }

    public function write_error(array $payload, string $cursor): void
    {
        $this->write_json_part('error', $payload, [
            'X-Cursor' => base64_encode($cursor),
        ]);
    }

    public function write_file(array $chunk, string $cursor): void
    {
        $data = $chunk['data'];
        $this->begin_file($chunk, $cursor);
        if ($data !== '') {
            $this->write_file_body($data);
        }
        $this->finish_part();
    }

    public function begin_file(array $chunk, string $cursor): void
    {
        $chunk_size = isset($chunk['chunk_size']) ? (int) $chunk['chunk_size'] : strlen((string) ($chunk['data'] ?? ''));
        $headers = [
            'X-Cursor' => base64_encode($cursor),
            'X-File-Path' => base64_encode($chunk['path']),
            'X-File-Size' => (string) $chunk['size'],
        ];
        if (isset($chunk['ctime'])) {
            $headers['X-File-Ctime'] = (string) $chunk['ctime'];
        }
        $headers += [
            'X-Chunk-Offset' => (string) $chunk['offset'],
            'X-Chunk-Size' => (string) $chunk_size,
            'X-First-Chunk' => $chunk['is_first_chunk'] ? '1' : '0',
            'X-Last-Chunk' => $chunk['is_last_chunk'] ? '1' : '0',
        ];
        if (!empty($chunk['file_changed'])) {
            $headers['X-File-Changed'] = '1';
            if ($chunk['change_ctime'] !== null) {
                $headers['X-File-Change-Ctime'] = (string) $chunk['change_ctime'];
            }
            if ($chunk['change_size'] !== null) {
                $headers['X-File-Change-Size'] = (string) $chunk['change_size'];
            }
        }
        $this->write_part_header('file', 'application/octet-stream', $headers, $chunk_size);
    }

    public function write_file_body(string $body): void
    {
        if ($body !== '') {
            $this->stream->write($body);
        }
    }

    public function finish_part(): void
    {
        $this->stream->write("\r\n");
        $this->stream->sync();
    }

    public function write_completion(array $stats): void
    {
        $this->write_empty_part('completion', [
            'X-Status' => (string) $stats['status'],
            'X-Chunks-Processed' => (string) $stats['chunks_processed'],
            'X-Files-Completed' => (string) $stats['files_completed'],
            'X-Bytes-Processed' => (string) $stats['bytes_processed'],
            'X-Memory-Used' => (string) $stats['memory_used'],
            'X-Memory-Limit' => (string) $stats['memory_limit'],
            'X-Time-Elapsed' => (string) $stats['time_elapsed'],
        ]);
        $this->close();
    }

    public function close(): void
    {
        $this->stream->write("--{$this->boundary}--\r\n");
    }

    private function write_json_part(string $chunk_type, array $payload, array $headers): void
    {
        $json = json_encode_or_throw($payload);
        $this->write_part($chunk_type, 'application/json', $headers, $json);
    }

    private function write_empty_part(string $chunk_type, array $headers): void
    {
        $this->write_part($chunk_type, 'application/octet-stream', $headers, '');
    }

    private function write_part(string $chunk_type, string $content_type, array $headers, string $body): void
    {
        $this->write_part_header($chunk_type, $content_type, $headers, strlen($body));
        if ($body !== '') {
            $this->stream->write($body);
        }
        $this->finish_part();
    }

    private function write_part_header(string $chunk_type, string $content_type, array $headers, int $content_length): void
    {
        $this->stream->write(
            "--{$this->boundary}\r\n" .
            "Content-Type: {$content_type}\r\n" .
            "Content-Length: " . $content_length . "\r\n" .
            "X-Chunk-Type: {$chunk_type}\r\n" .
            $this->format_headers($headers) .
            "\r\n"
        );
    }

    private function format_headers(array $headers): string
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }
        return $lines;
    }
}
