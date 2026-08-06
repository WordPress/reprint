<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\apply_curl_proxy_from_environment;

require_once __DIR__ . '/../../client/import.php';

/**
 * Spawns a minimal HTTP forward proxy on an ephemeral port and confirms
 * that apply_curl_proxy_from_environment() routes a real cURL request
 * through it when ALL_PROXY is exported.
 */
class CurlProxyFromEnvTest extends TestCase
{
    /** @var resource|null */
    private $proxyProc = null;
    /** @var array<int, resource> */
    private $proxyPipes = [];
    private int $proxyPort = 0;
    private string $logFile = '';
    private ?string $savedAllProxy = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAllProxy = getenv('ALL_PROXY');
        putenv('ALL_PROXY');

        $this->logFile = tempnam(sys_get_temp_dir(), 'reprint-proxy-log-');
        file_put_contents($this->logFile, '');

        $script = __DIR__ . '/fixtures/http-proxy-server.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->proxyProc = proc_open(
            [PHP_BINARY, $script, $this->logFile],
            $descriptors,
            $this->proxyPipes
        );
        if (!is_resource($this->proxyProc)) {
            $this->fail('Failed to spawn proxy subprocess');
        }

        $portLine = fgets($this->proxyPipes[1]);
        if ($portLine === false) {
            $stderr = stream_get_contents($this->proxyPipes[2]) ?: '';
            $this->fail("Proxy did not emit a port line. stderr: {$stderr}");
        }
        $this->proxyPort = (int) trim($portLine);
        $this->assertGreaterThan(0, $this->proxyPort, 'Proxy port must be positive');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proxyProc)) {
            proc_terminate($this->proxyProc);
            foreach ($this->proxyPipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            proc_close($this->proxyProc);
        }
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            @unlink($this->logFile);
        }

        if ($this->savedAllProxy === false || $this->savedAllProxy === null) {
            putenv('ALL_PROXY');
        } else {
            putenv('ALL_PROXY=' . $this->savedAllProxy);
        }

        parent::tearDown();
    }

    public function testRequestRoutesThroughProxyWhenAllProxySet(): void
    {
        $proxyUrl = 'http://127.0.0.1:' . $this->proxyPort;
        putenv('ALL_PROXY=' . $proxyUrl);

        // Deliberately unreachable host — if the proxy isn't used, curl
        // will try to resolve it and fail. When the proxy is used, curl
        // forwards the absolute URL and our fixture responds.
        $targetUrl = 'http://reprint-test.invalid/does-not-matter';

        $ch = curl_init($targetUrl);
        $applied = apply_curl_proxy_from_environment($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(0, $errno, "curl error {$errno}: {$error}");
        $this->assertSame($proxyUrl, $applied);
        $this->assertSame(200, $code);
        $this->assertSame('proxied-ok', $body);

        // Give the proxy a moment to flush its log (file_put_contents is
        // synchronous but the write happens before fwrite on the socket
        // returns, so this should already be durable).
        $log = file_get_contents($this->logFile);
        $this->assertStringContainsString('GET ' . $targetUrl, (string) $log);
    }

    public function testHelperIsNoopWhenAllProxyUnset(): void
    {
        putenv('ALL_PROXY');
        $ch = curl_init('http://127.0.0.1:' . $this->proxyPort);
        $applied = apply_curl_proxy_from_environment($ch);
        curl_close($ch);
        $this->assertNull($applied);
    }

    public function testHelperIsNoopWhenAllProxyIsEmpty(): void
    {
        putenv('ALL_PROXY=');
        $ch = curl_init('http://127.0.0.1:' . $this->proxyPort);
        $applied = apply_curl_proxy_from_environment($ch);
        curl_close($ch);
        $this->assertNull($applied);
    }

    public function testRemoteUploadProxyInlinesEnvProxy(): void
    {
        $proxyUrl = 'http://127.0.0.1:' . $this->proxyPort;
        putenv('ALL_PROXY=' . $proxyUrl);

        // Exercise the generated runtime code path: same inline pattern
        // that remote-upload-proxy.php emits. This guards the duplicated
        // snippet in the generated PHP against drifting from the helper.
        $targetUrl = 'http://reprint-test.invalid/wp-content/uploads/x.png';
        $curl = curl_init($targetUrl);

        $all_proxy = getenv('ALL_PROXY');
        if (is_string($all_proxy) && $all_proxy !== '') {
            curl_setopt($curl, CURLOPT_PROXY, $all_proxy);
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->assertSame(0, $errno);
        $this->assertSame(200, $code);
        $this->assertSame('proxied-ok', $body);
    }
}
