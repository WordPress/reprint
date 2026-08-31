<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class RuntimeFilesRootPathTest extends TestCase
{
    private string $root;
    private string $state_directory;
    private string $filesystem_root;
    private string $request_log;

    /** @var resource|null */
    private $server_process = null;

    /** @var array<int,resource> */
    private array $server_pipes = [];

    private string $target_url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/runtime-files-root-path-'
            . bin2hex(random_bytes(6));
        $this->state_directory = $this->root . '/state';
        $this->filesystem_root = $this->root . '/files';
        $this->request_log = $this->root . '/requests.jsonl';
        mkdir($this->state_directory, 0700, true);
        mkdir($this->filesystem_root, 0700, true);
        $this->target_url = $this->start_server();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            foreach ($this->server_pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->server_process);
        }
        $this->remove_tree($this->root);
        parent::tearDown();
    }

    public function testRuntimeFileAtTheFilesystemRootUsesTheRootDirectory(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('Runtime file fetching requires the curl extension.');
        }

        $client = new \ImportClient(
            $this->target_url,
            $this->state_directory,
            $this->filesystem_root,
        );
        $download_directory = $this->state_directory . '/runtime-files';
        $reflection = new \ReflectionClass($client);
        $fetch_files_into = $reflection->getMethod('fetch_files_into');
        $downloaded = $fetch_files_into->invoke(
            $client,
            $download_directory,
            ['/runtime.php'],
        );

        $this->assertSame(1, $downloaded);
        $this->assertSame(
            'runtime file at root',
            file_get_contents($download_directory . '/runtime.php'),
        );

        $requests = file($this->request_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($requests);
        $this->assertCount(1, $requests);
        $request = json_decode($requests[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('file_fetch', $request['endpoint']);
        $this->assertSame(['/'], $request['directory']);
    }

    private function start_server(): string
    {
        $router = $this->root . '/runtime-files-root-router.php';
        file_put_contents($router, sprintf(<<<'PHP'
<?php
$request = array(
    'endpoint' => $_GET['endpoint'] ?? null,
    'directory' => isset($_GET['directory'])
        ? (array) $_GET['directory']
        : null,
);
file_put_contents(
    %s,
    json_encode($request, JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND
);

if (
    $request['endpoint'] !== 'file_fetch'
    || !is_array($request['directory'])
    || ($request['directory'][0] ?? null) !== '/'
) {
    http_response_code(400);
    echo 'file_fetch must receive the filesystem root as its directory';
    return;
}

$boundary = 'runtime-files-root-path-test';
$write_part = static function (array $headers, string $body = '') use ($boundary): void {
    echo "--{$boundary}\r\n";
    foreach ($headers as $name => $value) {
        echo "{$name}: {$value}\r\n";
    }
    echo 'Content-Length: ' . strlen($body) . "\r\n\r\n";
    echo $body . "\r\n";
};

header('Content-Type: multipart/mixed; boundary=' . $boundary);
$write_part(array(
    'X-Chunk-Type' => 'file',
    'X-File-Path' => base64_encode('/runtime.php'),
    'X-First-Chunk' => '1',
    'X-Last-Chunk' => '1',
), 'runtime file at root');
$write_part(array(
    'X-Chunk-Type' => 'completion',
    'X-Status' => 'complete',
));
echo "--{$boundary}--\r\n";
PHP, json_encode(
            $this->request_log,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )));

        $error_number = 0;
        $error_message = '';
        $socket = stream_socket_server(
            'tcp://127.0.0.1:0',
            $error_number,
            $error_message,
        );
        $this->assertIsResource($socket, $error_message);
        $socket_name = stream_socket_get_name($socket, false);
        $this->assertIsString($socket_name);
        fclose($socket);
        $port = (int) substr(strrchr($socket_name, ':'), 1);

        $this->server_process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $this->server_pipes,
            $this->root,
        );
        $this->assertIsResource($this->server_process);
        fclose($this->server_pipes[0]);

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $connection = @fsockopen(
                '127.0.0.1',
                $port,
                $error_number,
                $error_message,
                0.1,
            );
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://127.0.0.1:' . $port . '/export.php?reprint-api';
            }
            usleep(100000);
        }

        $this->fail('Runtime file test server did not start.');
    }

    private function remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
