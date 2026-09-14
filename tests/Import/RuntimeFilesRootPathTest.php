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

    /** @dataProvider invalid_runtime_paths */
    public function testPreflightStopsOnInvalidRuntimePaths(
        string $returned_path,
        string $requested_path,
        string $reason
    ): void {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('Runtime file fetching requires the curl extension.');
        }

        file_put_contents($this->root . '/outside.txt', 'keep this local file');
        $client = new \ImportClient(
            $this->target_url . '&' . http_build_query([
                'returned_path' => base64_encode($returned_path),
                'requested_path' => base64_encode($requested_path),
            ]),
            $this->state_directory,
            $this->filesystem_root,
        );

        try {
            $client->run_preflight();
            $this->fail('An invalid received path must stop preflight.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame(
            'keep this local file',
            file_get_contents($this->root . '/outside.txt'),
            'A source response must not overwrite files outside runtime_files.',
        );
        $runtime_directory = $this->state_directory . '/runtime_files';
        $this->assertSame(['.', '..'], scandir($runtime_directory));

        $requests = file($this->request_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $requests, 'No further directory may be fetched after an invalid path.');
        $request = json_decode($requests[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('file_fetch', $request['endpoint']);
        $this->assertSame([['path' => base64_encode($requested_path)]], $request['files']);
    }

    /**
     * @return array<string,array{string,string,string}> Returned path, requested path, and rejection reason.
     */
    public static function invalid_runtime_paths(): array
    {
        return [
            'relative traversal' => ['../../outside.txt', '/runtime.php', 'must be an absolute path'],
            'absolute traversal' => ['/../../outside.txt', '/runtime.php', 'must not contain dot-segments'],
            'traversal also listed in preflight' => ['/../../outside.txt', '/../../outside.txt', 'must not contain dot-segments'],
            'dot segment also listed in preflight' => ['/./runtime.php', '/./runtime.php', 'must not contain dot-segments'],
            'relative filename' => ['relative.php', '/runtime.php', 'must be an absolute path'],
            'NUL byte' => ["/runtime.php\0suffix", '/runtime.php', 'must not contain NUL bytes'],
        ];
    }

    public function testPreflightKeepsDownloadingAfterAnUnrequestedFile(): void
    {
        $client = new \ImportClient(
            $this->target_url . '&' . http_build_query([
                'returned_path' => base64_encode('/unexpected.php'),
                'requested_path' => base64_encode('/runtime.php'),
            ]),
            $this->state_directory,
            $this->filesystem_root,
        );

        $client->run_preflight();

        $runtime_directory = $this->state_directory . '/runtime_files';
        $this->assertSame(['.', '..', 'append'], scandir($runtime_directory));
        $this->assertSame(
            'runtime file at root',
            file_get_contents($runtime_directory . '/append/runtime.php'),
        );
        $this->assertStringContainsString(
            'unrequested runtime file',
            file_get_contents($this->state_directory . '/audit.log'),
        );
    }

    /** @dataProvider received_path_fields */
    public function testInvalidPathsStopEvenWhenTheRuntimeDownloaderIgnoresThePart(
        string $chunk_type,
        string $path_header
    ): void {
        $client = new \ImportClient(
            $this->target_url . '&' . http_build_query([
                'returned_path' => base64_encode('/../../outside.txt'),
                'requested_path' => base64_encode('/runtime.php'),
                'chunk_type' => $chunk_type,
                'path_header' => $path_header,
            ]),
            $this->state_directory,
            $this->filesystem_root,
        );

        try {
            $client->run_preflight();
            $this->fail('Every received entry path must be checked before dispatch.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('must not contain dot-segments', $exception->getMessage());
        }

        $this->assertSame(['.', '..'], scandir($this->state_directory . '/runtime_files'));
        $this->assertCount(2, file($this->request_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /** @return array<string,list<string>> Part type and path header for each case. */
    public static function received_path_fields(): array
    {
        return [
            'directory' => ['directory', 'X-Directory-Path'],
            'symlink entry' => ['symlink', 'X-Symlink-Path'],
            'index entry' => ['index', 'X-Index-Path'],
            'missing file' => ['missing', 'X-File-Path'],
            'filesystem root' => ['metadata', 'X-Filesystem-Root'],
            'error path' => ['error', ''],
        ];
    }

    private function start_server(): string
    {
        $router = $this->root . '/runtime-files-root-router.php';
        file_put_contents($router, sprintf(<<<'PHP'
<?php
$request = array(
    'endpoint' => $_POST['endpoint'] ?? null,
    'directory' => isset($_POST['directory'])
        ? (array) $_POST['directory']
        : null,
    'files' => isset($_FILES['file_list'])
        ? json_decode(file_get_contents($_FILES['file_list']['tmp_name']), true)
        : null,
);
file_put_contents(
    %s,
    json_encode($request, JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND
);

if ($request['endpoint'] === 'preflight') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'ok' => true,
        'protocol_version' => 2,
        'runtime' => array(
            'ini_get_all' => array(
                'auto_prepend_file' => base64_decode($_POST['requested_path'], true),
                'auto_append_file' => '/append/runtime.php',
            ),
        ),
    ));
    return;
}

if (
    $request['endpoint'] !== 'file_fetch'
    || !is_array($request['directory'])
    || !is_array($request['files'])
) {
    http_response_code(400);
    echo 'file_fetch must receive a directory and a file list';
    return;
}

$returned_path = $request['files'][0]['path'];
if ($request['directory'] !== array('/append') && isset($_POST['returned_path'])) {
    $returned_path = $_POST['returned_path'];
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
if (isset($_POST['chunk_type'])) {
    $headers = array('X-Chunk-Type' => $_POST['chunk_type']);
    $body = '';
    if ($_POST['chunk_type'] === 'error') {
        $body = json_encode(array('path' => $returned_path, 'error_type' => 'file_missing'));
    } else {
        $headers[$_POST['path_header']] = $returned_path;
    }
    $write_part($headers, $body);
    echo "--{$boundary}--\r\n";
    return;
}

$write_part(array(
    'X-Chunk-Type' => 'file',
    'X-File-Path' => $returned_path,
    'X-First-Chunk' => '1',
    'X-Last-Chunk' => '0',
), 'runtime file ');
$write_part(array(
    'X-Chunk-Type' => 'file',
    'X-File-Path' => $returned_path,
    'X-First-Chunk' => '0',
    'X-Last-Chunk' => '1',
), 'at root');
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
