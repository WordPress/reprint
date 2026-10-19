<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared file-producer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match file-producer test style.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote paths in the local endpoint router.
namespace FileSyncProducerTests;

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\Utils;

/** Fetch real multipart replies from the production file-fetch endpoint. */
final class PathResolutionTest extends TestCase
{
    private string $root;
    private string $url;
    /** @var resource|null Local source process. */
    private $server = null;

    /** Serve the real endpoint over HTTP, with one source directory. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fetch-path-resolution-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/site', 0700, true);
        $this->root = Utils::normalize_path_separators(realpath($this->root), Utils::native_path_format());
        $repository = dirname(__DIR__, 2);
        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php require ' . var_export($repository . '/vendor/autoload.php', true)
            . '; require ' . var_export($repository . '/packages/reprint-server/src/export.php', true)
            . '; endpoint_file_fetch(["directory" => __DIR__ . "/site", "file_list_path" => $_FILES["file_list"]["tmp_name"]],'
            . ' new WordPress\\Reprint\\Server\\ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9));');
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open([PHP_BINARY, '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']], $pipes);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $number, $message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    /** Stop the source and remove only this fixture's paths. */
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->root)) {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,
                \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->root);
        }
    }

    /** A source deletion after path selection must not substitute a different file. */
    public function testDeletedAbsolutePathDoesNotFallBackToSourceDirectory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The duplicate absolute-path layout uses Unix file names.');
        }
        $requested = $this->root . '/site/photo.jpg';
        $substitute = Utils::wp_join_unix_paths($this->root . '/site', $requested);
        mkdir(dirname($substitute), 0700, true);
        file_put_contents($requested, 'original photo');
        file_put_contents($substitute, 'different photo');
        $paths = [$requested];
        // The source user removes the selected file before its contents are fetched.
        unlink($requested);

        $response = $this->fetch($paths);
        $this->assertStringContainsString("X-Chunk-Type: missing\r\n", $response);
        $this->assertStringContainsString('X-File-Path: ' . base64_encode($requested) . "\r\n", $response);
        $this->assertStringNotContainsString(base64_encode($substitute), $response);
        $this->assertStringNotContainsString('different photo', $response);
    }

    /**
     * Both full paths and paths relative to the source directory remain supported.
     *
     * @dataProvider existing_paths
     * @param bool $absolute Whether to request the file by its full path.
     */
    public function testExistingPathsStillReturnTheRequestedFile(bool $absolute): void
    {
        $path = $this->root . '/site/photo.jpg';
        file_put_contents($path, 'requested photo');
        $response = $this->fetch([$absolute ? $path : 'photo.jpg']);
        $this->assertStringContainsString("X-Chunk-Type: file\r\n", $response);
        $this->assertStringContainsString('X-File-Path: ' . base64_encode($path) . "\r\n", $response);
        $this->assertStringContainsString('requested photo', $response);
        $this->assertStringNotContainsString("X-Chunk-Type: missing\r\n", $response);
    }

    /** @return array<string,list<bool>> Full and source-relative request paths. */
    public static function existing_paths(): array
    {
        return ['absolute' => [true], 'relative' => [false]];
    }

    /** A dangling absolute link is still a link, not a missing path. */
    public function testDanglingAbsoluteLinkIsStillReturned(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('This fixture creates a Unix symlink.');
        }
        $path = $this->root . '/site/link.jpg';
        symlink('missing.jpg', $path);
        $response = $this->fetch([$path]);
        $this->assertStringContainsString("X-Chunk-Type: symlink\r\n", $response);
        $this->assertStringContainsString('X-Symlink-Path: ' . base64_encode($path) . "\r\n", $response);
        $this->assertStringContainsString('X-Symlink-Target: ' . base64_encode('missing.jpg') . "\r\n", $response);
        $this->assertStringNotContainsString("X-Chunk-Type: missing\r\n", $response);
    }

    /**
     * Upload a real file list and return the decoded multipart response.
     *
     * @param string[] $paths Requested source paths.
     * @return string Multipart response bytes.
     */
    private function fetch(array $paths): string
    {
        $list = $this->root . '/file-list.json';
        file_put_contents($list, json_encode(array_map(static function (string $path): array {
            return ['path' => base64_encode($path)];
        }, $paths)));
        $handle = curl_init($this->url);
        curl_setopt_array($handle, [CURLOPT_POSTFIELDS => ['file_list' => new \CURLFile($list)],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 10]);
        try {
            $response = curl_exec($handle);
            $this->assertIsString($response, curl_error($handle));
            $this->assertSame(200, curl_getinfo($handle, CURLINFO_HTTP_CODE), $response);
            return $response;
        } finally {
            curl_close($handle);
        }
    }
}
