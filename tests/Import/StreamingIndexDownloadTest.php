<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

/** Large filesystem indexes use the real exporter and a separate importer process. */
final class StreamingIndexDownloadTest extends TestCase
{
    private string $directory;
    private string $url;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/reprint-stream-index-' . bin2hex(random_bytes(6));
        $source = $this->directory . '/remote';
        mkdir($source, 0700, true);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=512M', '-S', $address, __DIR__ . '/fixtures/stream-index-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/server.log', 'a'], 2 => ['file', $this->directory . '/server.log', 'a']],
            $pipes, $this->directory
        );
        $this->assertIsResource($this->server);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $number, $message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->directory . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testLargeBatchCompletesWith64MiBOfImporterMemory(): void
    {
        $this->create_large_source_tree();
        $process = $this->start_importer('64M');
        $this->assertSame(0, proc_close($process), $this->importer_log());
        $this->assert_index_complete();
        $result = json_decode(file_get_contents($this->directory . '/result.json'), true);
        $this->assertLessThan(64 * 1048576, $result['peak_memory']);
    }

    public function testProcessDeathDuringABatchResumesWithoutDuplicateEntries(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('Process death coverage requires POSIX signals.');
        }
        $this->create_large_source_tree();
        $process = $this->start_importer('256M');
        $index = $this->index_path();
        $deadline = microtime(true) + 30;
        $killed = false;
        do {
            clearstatcache(true, $index);
            if (is_file($index) && filesize($index) > 1048576) {
                $status = proc_get_status($process);
                $this->assertTrue($status['running']);
                posix_kill($status['pid'], SIGKILL);
                $killed = true;
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline && proc_get_status($process)['running']);
        proc_close($process);
        $this->assertTrue($killed, 'The importer must write entries before the complete batch arrives. ' . $this->importer_log());
        $process = $this->start_importer('256M');
        $this->assertSame(0, proc_close($process), $this->importer_log());
        $this->assert_index_complete();
    }

    /** @dataProvider part_framing */
    public function testInterruptedPartKeepsEarlierPartsAndReplaysOnlyItsOwnEntries(bool $sized): void
    {
        $first = ['path' => base64_encode($this->directory . '/remote/first'), 'ctime' => 1, 'size' => 0, 'type' => 'file'];
        $second = $first;
        $second['path'] = base64_encode($this->directory . '/remote/second');
        $first_body = json_encode([$first]);
        $unfinished_body = '[' . json_encode($second) . ',' . str_repeat(' ', 150);
        $unfinished_part = $this->part($unfinished_body, 'after-second', $sized, strlen($unfinished_body) + 1000);
        file_put_contents($this->directory . '/response.json', json_encode(['body' =>
            $this->part($first_body, 'after-first', $sized) .
            $unfinished_part, 'body_on_resume' => $unfinished_part,
        ]));
        $process = $this->start_importer('64M');
        $this->assertNotSame(0, proc_close($process), 'The response ends inside the second part.');
        $lines = file($this->index_path(), FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines);
        $this->assertSame($first, json_decode($lines[0], true));
        $state = json_decode(file_get_contents(dirname($this->index_path()) . '/state.json'), true);
        $this->assertSame(base64_encode('after-first'), $state['index']['cursor']);

        file_put_contents($this->directory . '/response.json', json_encode(['http_code' => 200, 'body' =>
            $this->part(json_encode([$second]), 'after-second', $sized) .
            "--boundary-index\r\nX-Chunk-Type: completion\r\nX-Status: complete\r\nContent-Length: 0\r\n\r\n\r\n--boundary-index--\r\n",
        ]));
        $process = $this->start_importer('64M');
        $this->assertSame(0, proc_close($process), $this->importer_log());
        $lines = file($this->index_path(), FILE_IGNORE_NEW_LINES);
        $this->assertSame([$first, $second], array_map(static function ($line): array { return json_decode($line, true);
}, $lines));
    }

    /** @return array<string, array{bool}> Sized and boundary-terminated part bodies. */
    public static function part_framing(): array
    {
        return ['Content-Length' => [true], 'boundary terminated' => [false]];
    }

    public function testMalformedArrayDoesNotCommitItsEmittedEntriesOrCursor(): void
    {
        $entry = ['path' => base64_encode($this->directory . '/remote/first'), 'ctime' => 1, 'size' => 0, 'type' => 'file'];
        file_put_contents($this->directory . '/response.json', json_encode(['http_code' => 200, 'body' =>
            $this->part('[' . json_encode($entry) . ',not-json]', 'invalid-part', true) . "--boundary-index--\r\n",
        ]));
        $process = $this->start_importer('64M');
        $this->assertNotSame(0, proc_close($process));
        $this->assertSame('', file_get_contents($this->index_path()));
        $state = json_decode(file_get_contents(dirname($this->index_path()) . '/state.json'), true);
        $this->assertNull($state['index']['cursor']);
        $this->assertSame(0, $state['index']['bytes_written']);
    }

    private function part(string $body, string $cursor, bool $sized, ?int $declared_bytes = null): string
    {
        return "--boundary-index\r\nX-Chunk-Type: index_batch\r\nX-Cursor: " . base64_encode($cursor) . "\r\n" .
            ( $sized ? 'Content-Length: ' . ( $declared_bytes ?? strlen($body) ) . "\r\n" : '' ) . "\r\n" . $body . "\r\n";
    }

    private function create_large_source_tree(): void
    {
        $source = $this->directory . '/remote';
        for ($depth = 0; $depth < 3; ++$depth) {
            $source .= '/' . str_repeat('directory', 22);
            mkdir($source);
        }
        for ($file = 0; $file < 40000; ++$file) {
            touch($source . '/' . sprintf('file-%05d.txt', $file));
        }
    }

    /** @return resource Importer process writing only this test's state. */
    private function start_importer(string $memory_limit)
    {
        return proc_open(
            [PHP_BINARY, '-d', 'memory_limit=' . $memory_limit, __DIR__ . '/fixtures/stream-index-worker.php', $this->url, $this->directory],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/importer.log', 'a'], 2 => ['file', $this->directory . '/importer.log', 'a']],
            $pipes
        );
    }

    private function index_path(): string
    {
        return $this->directory . '/state/remotes/' . md5($this->url) . '/pull/remote-index.next.jsonl';
    }

    private function importer_log(): string
    {
        return file_get_contents($this->directory . '/importer.log');
    }

    private function assert_index_complete(): void
    {
        $handle = fopen($this->index_path(), 'rb');
        $seen = [];
        $files = 0;
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue(!isset($seen[$entry['path']]), 'A resumed batch must not duplicate entries.');
            $seen[$entry['path']] = true;
            $files += $entry['type'] === 'file' ? 1 : 0;
        }
        fclose($handle);
        $this->assertSame(40000, $files);
        $this->assertGreaterThan(16 * 1048576, filesize($this->index_path()));
    }
}
