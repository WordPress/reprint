<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;
use WordPress\Reprint\Server\MySQLDumpProducer;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class BufferedMultipartBodyTest extends TestCase
{
    private string $directory;
    private string $url;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/reprint-buffered-body-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open(
            [PHP_BINARY, '-d', 'output_buffering=0', '-S', $address, __DIR__ . '/fixtures/buffered-multipart-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/server.log', 'a'], 2 => ['file', $this->directory . '/server.log', 'a']],
            $pipes
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

    /** @dataProvider oversized_parts */
    public function testStopsBeforeDispatchingAnOversizedBufferedPart(string $type, bool $sized, bool $gzip): void
    {
        $completed = 0;
        $context = new StreamingContext();
        $context->on_chunk = static function () use (&$completed): void { ++$completed;
};
        try {
            $this->fetch($context, ['type' => $type, 'bytes' => MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES + 1,
                'sized' => (int) $sized, 'gzip' => (int) $gzip]);
            $this->fail('An oversized non-file part must stop the request.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('16777216-byte limit', $error->getMessage());
            $this->assertStringContainsString($type, $error->getMessage());
        }
        $this->assertSame(0, $completed);
    }

    /** @return array<string, array{string, bool, bool}> Part type, framing, and compression. */
    public static function oversized_parts(): array
    {
        $cases = [];
        foreach (['sql', 'sql_session_setup', 'index_batch', 'progress', 'error', 'unknown'] as $type) {
            $cases[$type . ' sized'] = [$type, true, false];
            $cases[$type . ' boundary terminated'] = [$type, false, false];
        }
        $cases['compressed sized'] = ['sql', true, true];
        $cases['compressed boundary terminated'] = ['progress', false, true];
        return $cases;
    }

    public function testEachPartCanUseTheFullExporterSqlPartLimit(): void
    {
        $sizes = [];
        $context = new StreamingContext();
        $context->on_chunk = static function (array $part) use (&$sizes): void {
            $sizes[] = strlen($part['body']);
        };
        $this->fetch($context, ['type' => 'sql', 'bytes' => MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES, 'parts' => 2]);
        $this->assertSame(array_fill(0, 2, MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES), $sizes);
    }

    public function testLargerFilePartsStillStreamWithoutTheBufferedPartLimit(): void
    {
        $sizes = [];
        $context = new StreamingContext();
        $context->on_chunk = static function (array $part) use (&$sizes): void { $sizes[] = strlen($part['body']);
};
        $bytes = MySQLDumpProducer::MAX_SQL_PART_BODY_BYTES + 65536;
        $this->fetch($context, ['type' => 'file', 'bytes' => $bytes]);
        $this->assertSame($bytes, array_sum($sizes));
        $this->assertLessThan(65536, max($sizes));
        $this->assertSame(0, end($sizes), 'The final callback closes the streamed file part.');
    }

    /**
     * @param array $parameters {
     *     Fixture response settings.
     *     @type string $type  Multipart part type.
     *     @type int    $bytes Decoded bytes in each part.
     *     @type int    $parts Number of parts; defaults to one.
     *     @type int    $sized Whether to send Content-Length; defaults to one.
     *     @type int    $gzip  Whether to gzip the response; defaults to zero.
     * }
     */
    private function fetch(StreamingContext $context, array $parameters): void
    {
        $on_chunk = $context->on_chunk;
        $context->on_chunk = static function (array $part) use ($context, $on_chunk): void {
            if ($part['headers']['x-chunk-type'] === 'completion') {
                $context->saw_completion = true;
                return;
            }
            $on_chunk($part);
        };
        $client = new \ImportClient($this->url, $this->directory . '/state', $this->directory . '/files', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'fetch_streaming') )->invoke($client, $this->url . '?' . http_build_query($parameters), $context);
    }
}
