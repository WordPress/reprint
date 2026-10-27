<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote fixture paths in the local HTTP router.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

class FetchBatchPathTest extends TestCase {
    private string $root;
    /** @var resource|null Local source endpoint. */
    private $server;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/fetch-batch-path-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->remove_directory($this->root);
    }

    /** @dataProvider tampered_batch_paths */
    public function test_tampered_batch_does_not_upload_or_delete_an_unrelated_file(string $variant): void {
        $client = new \ImportClient($this->start_source(), $this->root . '/state', $this->root . '/local', ['allow_http' => true]);
        $unrelated_file = $variant === 'wrong-name'
            ? $client->pull_state_directory . '/unrelated.txt'
            : $this->root . '/file-fetch-unrelated';
        $contents = str_repeat('private contents', $variant === 'oversized' ? 100000 : 1);
        file_put_contents($unrelated_file, $contents);
        $batch_file = $unrelated_file;
        if ($variant === 'symlink') {
            mkdir($client->pull_state_directory . '/fetch-batches', 0700);
            $batch_file = $client->pull_state_directory . '/fetch-batches/file-fetch-link';
            symlink($unrelated_file, $batch_file);
        }
        $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
        $entry = ['path' => base64_encode('/var/www/html/sample.txt')];
        file_put_contents($list_file, json_encode($entry) . "\n");
        \write_current_pull_state($client, [
            'preflight' => ['data' => ['ok' => true], 'http_code' => 200],
            'fetch' => ['batch_file' => $batch_file, 'cursor' => 'previous-batch-cursor'],
            'current_file' => $this->root . '/local/partial.txt',
            'current_file_bytes' => 0,
        ]);
        file_put_contents($this->root . '/local/partial.txt', 'keep these bytes');
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('state')->setValue($client, $reflection->getMethod('load_state')->invoke($client));

        $complete = $reflection->getMethod('fetch_files_from_list')->invoke($client, $list_file);
        $received = json_decode(file_get_contents($this->root . '/received.json'), true);
        $this->assertSame([$entry], json_decode($received['file_list'], true));
        $this->assertNull($received['cursor']);
        $this->assertFileExists($unrelated_file);
        $this->assertSame($contents, file_get_contents($unrelated_file));
        $this->assertSame('keep these bytes', file_get_contents($this->root . '/local/partial.txt'));
        if ($variant === 'symlink') {
            $this->assertTrue(is_link($batch_file));
        }
        $this->assertTrue($complete);
    }

    public static function tampered_batch_paths(): array {
        return [
            'external file with batch prefix' => ['external'],
            'external file larger than the request budget' => ['oversized'],
            'different file in the pull directory' => ['wrong-name'],
            'batch symlink to an external file' => ['symlink'],
        ];
    }

    public function test_new_batches_stay_in_the_private_pull_directory(): void {
        $client = new \ImportClient('https://source.example', $this->root . '/state', $this->root . '/local');
        $list_file = $client->pull_state_directory . '/fetch-list.jsonl';
        file_put_contents($list_file, json_encode(['path' => base64_encode('/sample.txt')]) . "\n");
        $batch = ( new \ReflectionClass($client) )->getMethod('prepare_fetch_batch')->invoke($client, $list_file, 0);
        $this->assertSame(realpath($client->pull_state_directory . '/fetch-batches'), realpath(dirname($batch['file'])));
        $this->assertFalse(is_link($batch['file']));
    }

    private function start_source(): string {
        // A hostile source can record any upload and report the batch complete.
        $router = $this->root . '/source.php';
        file_put_contents($router, '<?php '
            . 'file_put_contents(' . var_export($this->root . '/received.json', true) . ', json_encode(['
            . '"file_list" => file_get_contents($_FILES["file_list"]["tmp_name"]),'
            . '"cursor" => $_GET["cursor"] ?? $_SERVER["HTTP_X_EXPORT_CURSOR"] ?? null]));'
            . 'header("Content-Type: multipart/mixed; boundary=batch-path-test");'
            . 'echo "--batch-path-test\r\nX-Chunk-Type: completion\r\nX-Status: complete\r\nContent-Length: 0\r\n\r\n\r\n--batch-path-test--\r\n";');
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->server = proc_open([PHP_BINARY, '-S', $address, $router], [
            0 => ['pipe', 'r'],
            1 => ['file', $this->root . '/server.log', 'a'],
            2 => ['file', $this->root . '/server.log', 'a'],
        ], $pipes);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://' . $address;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    private function remove_directory(string $directory): void {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
