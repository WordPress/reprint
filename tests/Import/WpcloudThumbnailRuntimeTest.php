<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

/** Exercises the generated thumbnail handler through PHP's real HTTP router. */
final class WpcloudThumbnailRuntimeTest extends TestCase {
    private string $root;
    private string $uploads_directory;
    private string $url;
    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('The thumbnail handler requires GD.');
        }
        $this->root = sys_get_temp_dir() . '/reprint-thumbnails-' . bin2hex(random_bytes(6));
        $this->uploads_directory = $this->root . '/site/wp-content/uploads';
        mkdir($this->uploads_directory . '/2026', 0755, true);
        mkdir($this->root . '/outside', 0755, true);
        file_put_contents($this->root . '/site/index.php', '<?php http_response_code(404); echo "not a thumbnail";');
        $manifest = new \RuntimeManifest('wpcloud');
        $manifest->constants = ['WP_CONTENT_DIR' => '{fs-root}/wp-content'];
        $manifest->routes = [['handler' => 'wpcloud-thumbnail-generator']];
        ( new \PhpBuiltinApplier() )->apply($manifest, $this->root . '/site', $this->root . '/runtime', [
            'wordpress_index_php' => $this->root . '/site/index.php',
        ]);

        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address;
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $this->root . '/site', $this->root . '/runtime/runtime.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes
        );
        $this->assertIsResource($this->server_process);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail('Runtime did not start: ' . file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @dataProvider uploadNames */
    public function testGeneratesNormalThumbnails(string $name): void
    {
        $this->write_image($this->uploads_directory . '/' . $name . '.png');
        $request_path = '/wp-content/uploads/' . str_replace('%2F', '/', rawurlencode($name)) . '-2x2.png?version=1';
        [$status, $body] = $this->request($request_path);
        $this->assertSame(200, $status);
        $this->assertSame([2, 1], array_slice(getimagesizefromstring($body), 0, 2));
        $thumbnail_path = $this->uploads_directory . '/' . $name . '-2x2.png';
        $this->assertFileExists($thumbnail_path);
        $this->assertSame(file_get_contents($thumbnail_path), $body);
    }

    /** @return array<string,array{string}> Upload names accepted by WordPress. */
    public static function uploadNames(): array
    {
        return ['nested' => ['2026/photo'], 'space' => ['2026/my photo'], 'Unicode' => ['2026/żółw']];
    }

    public function testExistingThumbnailIsServedWithoutRegeneration(): void
    {
        $this->write_image($this->uploads_directory . '/photo.png');
        $this->write_image($this->uploads_directory . '/photo-2x2.png');
        [$status, $body] = $this->request('/wp-content/uploads/photo-2x2.png');
        $this->assertSame(200, $status);
        $this->assertSame([8, 4], array_slice(getimagesizefromstring($body), 0, 2));
    }

    /** @dataProvider dotSegmentPaths */
    public function testDotSegmentsDoNotGenerateOutsideThumbnails(string $path): void
    {
        $this->write_image($this->root . '/outside/photo.png');
        [$status, $body] = $this->request($path);
        $this->assertSame(404, $status, bin2hex($body));
        $this->assertFileDoesNotExist($this->root . '/outside/photo-2x2.png');
    }

    /** @return array<string,array{string}> Raw request targets sent without client normalization. */
    public static function dotSegmentPaths(): array
    {
        return [
            'literal' => ['/wp-content/uploads/../../../outside/photo-2x2.png'],
            'encoded' => ['/wp-content/uploads/%2e%2e/%2e%2e/%2e%2e/outside/photo-2x2.png'],
            'backslashes' => ['/wp-content/uploads/..%5c..%5c..%5coutside/photo-2x2.png'],
            'NUL' => ['/wp-content/uploads/%00/photo-2x2.png'],
        ];
    }

    public function testOriginalImageLinkOutsideUploadsIsNotRead(): void
    {
        $this->write_image($this->root . '/outside/photo.png');
        symlink($this->root . '/outside/photo.png', $this->uploads_directory . '/photo.png');
        [$status, $body] = $this->request('/wp-content/uploads/photo-2x2.png');
        $this->assertSame(404, $status, bin2hex($body));
        $this->assertFileDoesNotExist($this->uploads_directory . '/photo-2x2.png');
    }

    public function testLinkedDirectoryWithSameRootPrefixIsNotWritten(): void
    {
        $outside_directory = $this->uploads_directory . '-other';
        mkdir($outside_directory);
        $this->write_image($outside_directory . '/photo.png');
        symlink($outside_directory, $this->uploads_directory . '/linked');
        [$status, $body] = $this->request('/wp-content/uploads/linked/photo-2x2.png');
        $this->assertSame(404, $status, bin2hex($body));
        $this->assertFileDoesNotExist($outside_directory . '/photo-2x2.png');
    }

    public function testDanglingThumbnailLinkIsNotWritten(): void
    {
        $this->write_image($this->uploads_directory . '/photo.png');
        symlink($this->root . '/outside/new.png', $this->uploads_directory . '/photo-2x2.png');
        [$status, $body] = $this->request('/wp-content/uploads/photo-2x2.png');
        $this->assertSame(404, $status, bin2hex($body));
        $this->assertFileDoesNotExist($this->root . '/outside/new.png');
    }

    public function testOriginalImageLinkInsideUploadsStillWorks(): void
    {
        $this->write_image($this->uploads_directory . '/2026/photo.png');
        symlink($this->uploads_directory . '/2026/photo.png', $this->uploads_directory . '/photo.png');
        [$status, $body] = $this->request('/wp-content/uploads/photo-2x2.png');
        $this->assertSame(200, $status);
        $this->assertSame([2, 1], array_slice(getimagesizefromstring($body), 0, 2));
    }

    public function testRepeatedWpContentSegmentCannotSelectAnotherDirectory(): void
    {
        mkdir($this->root . '/site/wp-content/private');
        $this->write_image($this->root . '/site/wp-content/private/photo.png');
        [$status, $body] = $this->request('/wp-content/uploads/a/wp-content/private/photo-2x2.png');
        $this->assertSame(404, $status, bin2hex($body));
        $this->assertFileDoesNotExist($this->root . '/site/wp-content/private/photo-2x2.png');
    }

    /** Writes a small real source image for the handler to decode. */
    private function write_image(string $path): void
    {
        $image = imagecreatetruecolor(8, 4);
        imagepng($image, $path);
        imagedestroy($image);
    }

    /** @return array{int,string} HTTP status and body from the generated runtime. */
    private function request(string $path): array
    {
        $handle = curl_init($this->url . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => 5]);
        try {
            $body = curl_exec($handle);
            $this->assertIsString($body, curl_error($handle));
            return [curl_getinfo($handle, CURLINFO_HTTP_CODE), $body];
        } finally {
            curl_close($handle);
        }
    }
}
