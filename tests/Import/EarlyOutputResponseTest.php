<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Exercise responses after index.php has already sent a blank line. */
final class EarlyOutputResponseTest extends TestCase {
    private string $root;
    private string $url;
    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-early-output-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote', 0755, true);
        $this->root = realpath($this->root);
        file_put_contents($this->root . '/remote/sample.txt', "sample file\n");
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server_process = proc_open(
            [PHP_BINARY, '-d', 'output_buffering=0', '-d', 'display_errors=0', '-S', $address, __DIR__ . '/fixtures/early-output-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($this->server_process);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertNotFalse($connection, file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** JSON whitespace remains valid even when Content-Type cannot be sent. */
    public function testPreflightReturnsItsObjectAfterHeadersWereSent(): void
    {
        $client = $this->make_client();
        $response = (new \ReflectionMethod($client, 'fetch_json'))->invoke(
            $client, $this->url . '?endpoint=preflight', ['directory' => [$this->root . '/remote']]
        );
        $this->assertSame(200, $response['http_code']);
        $this->assertStringStartsWith("\n", $response['body']);
        $this->assertArrayHasKey('ok', $response['json']);
        $this->assertTrue($response['json']['database']['connected']);
        $this->assertStringNotContainsString('Cannot modify header', file_get_contents($this->root . '/server.log'));
    }

    /** CORS failure must not abort an otherwise usable non-browser response. */
    public function testCorsDoesNotWriteHeadersAfterOutput(): void
    {
        $body = file_get_contents($this->url . '?cors=1');
        $this->assertSame("\n{\"ok\":true}", $body);
    }

    /** OPTIONS must still stop before endpoint dispatch when headers cannot be set. */
    public function testCorsStillTerminatesOptionsAfterOutput(): void
    {
        $context = stream_context_create(['http' => ['method' => 'OPTIONS']]);
        $this->assertSame("\n", file_get_contents($this->url . '?cors=1', false, $context));
    }

    /** @dataProvider error_responses */
    public function testErrorsKeepTheirOriginalMessageAfterHeadersWereSent(string $query, string $message): void
    {
        $client = $this->make_client();
        $response = (new \ReflectionMethod($client, 'fetch_json'))->invoke(
            $client, $this->url . '?' . $query, []
        );
        $this->assertSame(200, $response['http_code']);
        $this->assertStringContainsString($message, $response['json']['error']);
        $this->assertStringNotContainsString('Cannot modify header', file_get_contents($this->root . '/server.log'));
        $this->assertStringNotContainsString('Cannot set response code', file_get_contents($this->root . '/server.log'));
    }

    /** @return array<string, array{string, string}> Request query and original error. */
    public static function error_responses(): array
    {
        return [
            'exception' => ['endpoint=unknown', "Invalid endpoint: 'unknown'"],
            'PHP warning' => ['warning=1', 'Source warning before streaming'],
        ];
    }

    /** @dataProvider streaming_endpoints */
    public function testExportStreamsWithoutResponseHeaders(string $endpoint, string $chunk_type): void
    {
        $chunks = $this->fetch_stream($endpoint, [
            'directory' => [$this->root . '/remote'],
            'list_dir' => $this->root . '/remote',
        ]);
        $types = array_column(array_column($chunks, 'headers'), 'x-chunk-type');
        $this->assertContains($chunk_type, $types);
        $this->assertNotContains('error', $types);
        $this->assertSame(1, count(array_filter($types, static function ($type) { return $type === 'completion'; })));
        $body = implode('', array_column($chunks, 'body'));
        if ($endpoint === 'file_fetch') {
            $this->assertSame(1, substr_count($body, "sample file\n"));
        } elseif ($endpoint === 'sql_chunk') {
            $this->assertSame(1, substr_count($body, base64_encode('sample database row')));
        }
    }

    /** @return array<string, array{string, string}> Endpoint and expected part type. */
    public static function streaming_endpoints(): array
    {
        return [
            'database dump' => ['sql_chunk', 'sql'],
            'database index' => ['db_index', 'table_stats'],
            'file index' => ['file_index', 'index_batch'],
            'file contents' => ['file_fetch', 'file'],
        ];
    }

    /** @dataProvider response_prefixes */
    public function testHeaderlessBodyIsConsumedOnceAcrossReads(string $prefix, bool $split): void
    {
        $payload = str_repeat("file bytes\0\n", 10000);
        $body = "--boundary-test\r\nX-Chunk-Type: file\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload .
            "\r\n--boundary-test\r\nX-Chunk-Type: completion\r\nContent-Length: 2\r\n\r\n{}\r\n--boundary-test--\r\n";
        $fragments = $split ? [$prefix, substr($body, 0, 7), substr($body, 7)] : [$prefix . $body];
        file_put_contents($this->root . '/fragments.json', json_encode($fragments));
        $chunks = $this->fetch_stream('file_fetch');
        $this->assertSame($payload . '{}', implode('', array_column($chunks, 'body')));
    }

    /** @return array<string, array{string, bool}> Prefix bytes and whether to split the boundary. */
    public static function response_prefixes(): array
    {
        return [
            'blank line' => ["\n", false],
            'split whitespace and boundary' => [str_repeat(" \t\r\n", 20000), true],
        ];
    }

    /** Ignore only whitespace, not an HTML error page before a boundary. */
    public function testNonWhitespaceBeforeBoundaryIsRejected(): void
    {
        file_put_contents($this->root . '/fragments.json', json_encode([
            "<html>Host error</html>\n--boundary-test\r\nX-Chunk-Type: completion\r\nContent-Length: 2\r\n\r\n{}\r\n--boundary-test--\r\n",
        ]));
        $this->expectException(\Reprint\Importer\TransientInterruptionException::class);
        $this->expectExceptionMessage('missing multipart boundary');
        $this->fetch_stream('file_fetch');
    }

    /**
     * Collect parts over HTTP and require a completion part.
     *
     * @param string $endpoint Requested export endpoint.
     * @param array $parameters { Form values passed to the exporter.
     *     @type string[] $directory Remote absolute directories to export.
     *     @type string   $list_dir  Remote absolute directory to index.
     * }
     * @return array[] { Streamed parts.
     *     @type array  $headers            Part headers keyed by lowercase name.
     *     @type string $body               Body bytes from this callback.
     *     @type bool   $is_streaming_body  Present on file body callbacks.
     *     @type bool   $is_streaming_close Present on file completion callbacks.
     * }
     */
    private function fetch_stream(string $endpoint, array $parameters = []): array
    {
        $client = $this->make_client();
        $context = new StreamingContext();
        $chunks = [];
        $context->on_chunk = static function (array $chunk) use (&$chunks, $context): void {
            $chunks[] = $chunk;
            if (($chunk['headers']['x-chunk-type'] ?? '') === 'completion') {
                $context->saw_completion = true;
            }
        };
        if ($endpoint === 'file_fetch' && !is_file($this->root . '/fragments.json')) {
            $list = $this->root . '/file-list.json';
            file_put_contents($list, json_encode([['path' => base64_encode($this->root . '/remote/sample.txt')]]));
            $parameters['file_list'] = new \CURLFile($list, 'application/json', 'file-list.json');
        }
        (new \ReflectionMethod($client, 'fetch_streaming'))->invoke(
            $client, $this->url . '?endpoint=' . $endpoint, $context, $parameters, $endpoint
        );
        $this->assertTrue($context->saw_completion);
        return $chunks;
    }

    /** Construct the real HTTP client without requiring source authentication in this fixture. */
    private function make_client(): \ImportClient
    {
        return new \ImportClient($this->url, $this->root . '/state', $this->root . '/local', ['allow_http' => true]);
    }
}
