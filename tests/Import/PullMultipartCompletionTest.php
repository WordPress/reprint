<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Matches the established PHPUnit namespace.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test setup failures are CLI diagnostics, never HTML output.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The small client subclass exposes one protected method to the tests.
// phpcs:disable Generic.WhiteSpace.ArbitraryParenthesesSpacing -- Match the established compact style in importer PHPUnit tests.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/** Exercises pull completion semantics through a real local HTTP endpoint. */
final class PullMultipartCompletionTest extends TestCase {

    private static string $server_root;
    private static string $base_url;

    /** @var resource|null */
    private static $server_process;

    private string $case_root;
    private PullMultipartCompletionClient $client;

    public static function setUpBeforeClass(): void {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('Pull streaming requires the curl extension.');
        }
        self::$server_root = sys_get_temp_dir() . '/pull-multipart-http-' . bin2hex(random_bytes(8));
        mkdir(self::$server_root, 0700, true);
        $router_path = self::$server_root . '/router.php';
        file_put_contents($router_path, <<<'PHP'
<?php
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return;
}
$case = $_GET['case'] ?? '';
$boundary = 'pull-completion';
header('Content-Type: multipart/mixed; boundary=' . $boundary);
$completion = "--{$boundary}\r\nContent-Type: application/octet-stream\r\nContent-Length: 0\r\nX-Chunk-Type: completion\r\nX-Status: complete\r\n\r\n\r\n";
if ($case === 'truncated-before-completion') {
    $body = "--{$boundary}\r\nContent-Type: application/octet-stream\r\nContent-Length: 4\r\nX-Chunk-Type: data\r\n\r\ndata\r\n";
    header('Content-Length: ' . strlen($body));
    echo $body;
    return;
}
if ($case === 'truncated-file') {
    $body = "--{$boundary}\r\nContent-Type: application/octet-stream\r\nContent-Length: 8\r\nX-Chunk-Type: file\r\nX-Cursor: next-cursor\r\nX-File-Path: " . base64_encode('/cursor.bin') . "\r\nX-File-Size: 8\r\nX-File-Ctime: 1234567890\r\nX-Chunk-Offset: 0\r\nX-Chunk-Size: 8\r\nX-First-Chunk: 1\r\nX-Last-Chunk: 1\r\n\r\nabcd";
    header('Content-Length: ' . strlen($body));
    echo $body;
    return;
}
$body = $completion;
if ($case === 'closed' || $case === 'closed-curl-error') {
    $body .= "--{$boundary}--\r\n";
}
header('Content-Length: ' . (strlen($body) + (substr($case, -11) === '-curl-error' ? 64 : 0)));
echo $body;
PHP
        );

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Could not reserve a local HTTP port: ' . $error . ' (' . $errno . ').');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr( strrchr( (string) $address, ':' ), 1 );
        self::$base_url = 'http://127.0.0.1:' . $port;
        self::$server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', self::$server_root, $router_path],
            [0 => ['pipe', 'r'], 1 => ['file', self::$server_root . '/server.log', 'a'], 2 => ['file', self::$server_root . '/server.log', 'a']],
            $pipes,
            self::$server_root
        );
        if (!is_resource(self::$server_process)) {
            throw new \RuntimeException('Could not start the local pull endpoint server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            try {
                $ping = @file_get_contents(self::$base_url . '/__ping');
            } catch ( \Throwable $error ) {
                // import.php converts PHP warnings into exceptions. A refused
                // connection is expected while the real server starts.
                $ping = false;
            }
            if ($ping === 'ok') {
                return;
            }
            usleep(100000);
        }
        self::tearDownAfterClass();
        throw new \RuntimeException('The local pull endpoint server did not start.');
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$server_process)) {
            proc_terminate(self::$server_process);
            proc_close(self::$server_process);
            self::$server_process = null;
        }
        if (isset(self::$server_root)) {
            self::remove_tree(self::$server_root);
        }
    }

    protected function setUp(): void {
        $this->case_root = self::$server_root . '/case-' . bin2hex(random_bytes(8));
        $state = $this->case_root . '/state';
        $files = $this->case_root . '/files';
        $this->client = new PullMultipartCompletionClient(self::$base_url, $state, $files);
    }

    protected function tearDown(): void {
        self::remove_tree($this->case_root);
    }

    public function testResponseWithoutCompletionRetainsTheRetryableMissingCompletionError(): void {
        $context = $this->completion_context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing completion chunk');
        $this->client->fetch_test_response(self::$base_url . '/?case=truncated-before-completion', $context);
    }

    public function testCompletionWithoutMimeCloseIsTerminal(): void {
        $context = $this->completion_context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closing boundary');
        $this->client->fetch_test_response(self::$base_url . '/?case=unclosed', $context);
    }

    public function testCompletionWithoutMimeCloseTakesPrecedenceOverACurlError(): void {
        $context = $this->completion_context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closing boundary');
        $this->client->fetch_test_response(self::$base_url . '/?case=unclosed-curl-error', $context);
    }

    public function testProperlyClosedCompletionSucceeds(): void {
        $context = $this->completion_context();

        $this->client->fetch_test_response(self::$base_url . '/?case=closed', $context);

        $this->assertTrue($context->saw_completion);
    }

    public function testProperlyClosedCompletionRetainsTheCurlError(): void {
        $context = $this->completion_context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cURL error (18)');
        $this->client->fetch_test_response(self::$base_url . '/?case=closed-curl-error', $context);
    }

    public function testTruncatedFileKeepsTheLastConfirmedCursor(): void {
        $client = new PullMultipartCompletionClient(
            self::$base_url . '/?case=truncated-file',
            $this->case_root . '/cursor-state',
            $this->case_root . '/cursor-files'
        );
        $client->get_import_state()->fetch->cursor = 'confirmed-cursor';

        $complete = $client->download_test_file_response('confirmed-cursor');

        $this->assertFalse($complete);
        $this->assertSame('confirmed-cursor', $client->get_import_state()->fetch->cursor);
        $this->assertSame('abcd', file_get_contents($this->case_root . '/cursor-files/cursor.bin'));
    }

    private function completion_context(): \StreamingContext {
        $context = new \StreamingContext();
        $context->on_chunk = static function (array $chunk) use ($context): void {
            if (($chunk['headers']['x-chunk-type'] ?? '') === 'completion') {
                $context->saw_completion = true;
            }
        };
        return $context;
    }

    private static function remove_tree(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove_tree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

final class PullMultipartCompletionClient extends \ImportClient {

    public function fetch_test_response(string $url, \StreamingContext $context): void {
        $this->fetch_streaming($url, null, $context);
    }

    public function download_test_file_response(?string $cursor): bool {
        $method = new \ReflectionMethod(\ImportClient::class, 'download_file_fetch');
        return $method->invoke($this, null, $cursor, 'fetch');
    }
}
