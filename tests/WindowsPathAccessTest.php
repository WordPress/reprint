<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\HTTPServer;
use WordPress\Reprint\Server\WindowsFilesystem;

final class WindowsPathAccessTest extends TestCase
{
    public function testNativeReaderIsUnavailableOnOtherOperatingSystems(): void
    {
        if (PHP_OS === 'WINNT') {
            $this->markTestSkipped('Native Windows checks run in the Windows migration job.');
        }
        require_once __DIR__ . '/../packages/reprint-server/src/class-windows-filesystem.php';
        $this->assertFalse(WindowsFilesystem::available());
        $this->assertNotContains('reprint-windows', stream_get_wrappers());
        $this->assertSame(__FILE__, \WordPress\Reprint\Server\source_io_path(__FILE__));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exact Windows paths require');
        WindowsFilesystem::resolve_input('D:/example');
    }

    public function testRestrictedMultisiteCannotResolveAnOutsidePath(): void
    {
        $server = new HTTPServer([
            'multisite' => ['site_id' => 7, 'network_id' => 1, 'base_prefix' => 'wp_'],
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This endpoint does not support a selected multisite site.');
        $server->normalize_config([
            'endpoint' => 'resolve_windows_path',
            'multisite_mode' => 'one-site-network-v1',
            'source_path_b64' => base64_encode('D:/outside'),
        ]);
    }

    /** The real plugin authenticates before the path-resolution handler runs. */
    public function testPathLookupUsesTheExistingPluginAuthentication(): void
    {
        $directory = sys_get_temp_dir() . '/reprint-path-auth-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $router = $directory . '/router.php';
        $repository = dirname(__DIR__);
        file_put_contents($router, '<?php' . "\n"
            . 'define("ABSPATH", __DIR__ . "/");' . "\n"
            . 'define("WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\PLUGIN_DIR", '
            . var_export($repository . '/reprint-server-wp/', true) . ');' . "\n"
            . 'define("WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\CONNECTION_TOKEN_FILE", __DIR__ . "/absent-secret.php");' . "\n"
            . 'function get_option($name, $default = "") { return $name === "reprint_server_connection_token" ? "path-test-token" : $default; }' . "\n"
            . 'require ' . var_export($repository . '/vendor/autoload.php', true) . ';' . "\n"
            . 'require ' . var_export($repository . '/reprint-server-wp/lib.php', true) . ';' . "\n"
            . '\WordPress\Reprint\Server\Plugin\handle_api_request();' . "\n");
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [
            0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a'],
        ], $pipes);
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($ready, file_get_contents($directory . '/server.log'));
            foreach ([null, 'wrong-token', 'path-test-token'] as $token) {
                $request = curl_init('http://' . $address . '/?reprint-api&endpoint=resolve_windows_path&source_path_b64=' . rawurlencode(base64_encode('D:/')));
                curl_setopt_array($request, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_HTTPHEADER => $token === null ? [] : (new Site_Export_HMAC_Client($token))->get_curl_headers(),
                ]);
                $body = curl_exec($request);
                $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
                curl_close($request);
                $this->assertNotFalse($body);
                if ($token !== 'path-test-token') {
                    $this->assertSame(403, $status, $body);
                    $this->assertStringNotContainsString('Windows path resolution requires', $body);
                } elseif (PHP_OS !== 'WINNT') {
                    $this->assertSame(400, $status, $body);
                    $this->assertStringContainsString('Windows path resolution requires a Windows source', $body);
                } else {
                    $this->assertSame(200, $status, $body);
                }
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($router);
            unlink($directory . '/server.log');
            rmdir($directory);
        }
    }
}
