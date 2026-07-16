<?php

namespace UploadsProxyTests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for reprint-ui/lib/playground-uploads-proxy-handler.php.
 *
 * Spawns a scripted HTTP origin in one subprocess and the proxy handler
 * in another (via proxy-runner.php), then asserts the handler's stdout —
 * which encodes the status code, forwarded headers, and body — matches
 * the contract regardless of which response shape the source returns.
 */
class PlaygroundUploadsProxyHandlerTest extends TestCase
{
    /** @var resource|null */
    private $sourceProc = null;
    /** @var array<int, resource> */
    private $sourcePipes = [];
    private int $sourcePort = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $script = __DIR__ . '/fixtures/scripted-source-server.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->sourceProc = proc_open(
            [PHP_BINARY, $script],
            $descriptors,
            $this->sourcePipes
        );
        if (!is_resource($this->sourceProc)) {
            $this->fail('Failed to spawn scripted source subprocess');
        }
        $portLine = fgets($this->sourcePipes[1]);
        if ($portLine === false) {
            $stderr = stream_get_contents($this->sourcePipes[2]) ?: '';
            $this->fail("source did not emit port. stderr: {$stderr}");
        }
        $this->sourcePort = (int) trim($portLine);
        $this->assertGreaterThan(0, $this->sourcePort);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->sourceProc)) {
            proc_terminate($this->sourceProc);
            foreach ($this->sourcePipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->sourceProc);
        }
        parent::tearDown();
    }

    /**
     * Run the proxy against the scripted origin and return
     * [statusCode, headerLines[], body].
     *
     * The runner subprocess writes status_header / header calls to a
     * sidecar JSON-lines file (in invocation order), and streams the
     * body to stdout via curl's WRITEFUNCTION. We parse both.
     *
     * @return array {
     *     Proxy response tuple.
     *
     *     @type int|null $0 HTTP status code.
     *     @type array    $1 Header lines.
     *     @type string   $2 Response body.
     * }
     * @phpstan-return array{0:?int,1:array<int,string>,2:string}
     */
    private function runProxy(string $sourcePath): array
    {
        $runner = __DIR__ . '/fixtures/proxy-runner.php';
        $sourceOrigin = "http://127.0.0.1:{$this->sourcePort}";
        $requestUri = '/wp-content/uploads' . $sourcePath;
        $sidecar = tempnam(sys_get_temp_dir(), 'reprint-uploads-proxy-sidecar-');
        file_put_contents($sidecar, '');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $runner, $sourceOrigin, $requestUri, $sidecar],
            $descriptors,
            $pipes
        );
        if (!is_resource($proc)) {
            @unlink($sidecar);
            $this->fail('Failed to spawn proxy runner');
        }
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertIsString($body, "proxy runner stdout (stderr: {$stderr})");
        $sidecarRaw = (string) file_get_contents($sidecar);
        @unlink($sidecar);

        return $this->parseSidecar($sidecarRaw, (string) $body);
    }

    /**
     * @return array {
     *     Proxy response tuple.
     *
     *     @type int|null $0 HTTP status code.
     *     @type array    $1 Header lines.
     *     @type string   $2 Response body.
     * }
     * @phpstan-return array{0:?int,1:array<int,string>,2:string}
     */
    private function parseSidecar(string $sidecar, string $body): array
    {
        $status = null;
        $headers = [];
        foreach (explode("\n", $sidecar) as $line) {
            if ($line === '') continue;
            $event = json_decode($line, true);
            if (!is_array($event)) continue;
            if (($event['kind'] ?? '') === 'status') {
                $status = (int) ($event['code'] ?? 0);
            } elseif (($event['kind'] ?? '') === 'header') {
                $headers[] = (string) ($event['line'] ?? '');
            }
        }
        return [$status, $headers, $body];
    }

    public function testForwardsStatusFor200WithContentType(): void
    {
        [$status, $headers, $body] = $this->runProxy('/200-with-content-type');

        $this->assertSame(200, $status, 'should forward 200 status from source');
        $this->assertContains('Content-Type: image/jpeg', $headers);
        $this->assertSame('JPEG-BYTES-HERE', $body);
    }

    public function testEmitsStatusFor200WithNoForwardableHeaders(): void
    {
        // Source returns 200 OK with only non-allowlisted headers
        // (Server, Connection). The proxy still streams the body via
        // CURLOPT_WRITEFUNCTION — it must emit status_header(200)
        // before the first byte hits the wire, otherwise PHP's default
        // 200 OK is correct here but the fallback "Upload not available"
        // text gets appended after the binary body when the post-
        // curl_exec branch sees $status_emitted === false.
        [$status, $headers, $body] = $this->runProxy('/200-no-forwardable');

        $this->assertSame(200, $status, 'should emit a 200 even when no allowlisted headers arrived');
        $this->assertSame('OPAQUE-BYTES', $body, 'body should not have a fallback 404 message appended');
        $this->assertStringNotContainsString(
            'Upload not available',
            $body,
            'body must not be polluted by the fallback message'
        );
    }

    public function testForwards404FromSource(): void
    {
        // Source returns 404 with Content-Type: text/html. The handler
        // must forward the 404 so WP doesn't render its own 404
        // template on top — and must NOT default to PHP's 200 OK.
        [$status, $headers, $body] = $this->runProxy('/404-with-html');

        $this->assertSame(404, $status, 'should forward 404 status from source verbatim');
        $this->assertContains('Content-Type: text/html', $headers);
        $this->assertSame('<html>not found</html>', $body);
    }

    public function testForwards502FromSource(): void
    {
        // No body, no forwardable headers — but 502 is what the source
        // said. Forward it; emit status before any output goes out.
        [$status, $headers, $body] = $this->runProxy('/5xx-no-body');

        $this->assertSame(
            502,
            $status,
            'should forward the source 5xx status, not silently fall back to a fabricated 404'
        );
    }
}
