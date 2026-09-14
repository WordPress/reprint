<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class MultipartRequestParametersTest extends TestCase {

    private string $root;
    private string $url;
    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-multipart-parameters-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/remote/one', 0755, true);
        mkdir($this->root . '/remote/two', 0755, true);
        $this->root = realpath($this->root);

        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/?reprint-api';
        $this->server_process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/multipart-parameters-router.php'],
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
        $this->remove_directory($this->root);
    }

    /** @dataProvider query_separators */
    public function testMultipartFieldsAndFileUploadDoNotDependOnQuerySeparator(string $separator): void
    {
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/local');
        ( new \ReflectionProperty($client, 'hmac_client') )->setValue(
            $client, new \Site_Export_HMAC_Client('multipart-test-secret')
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $paths = [
            $this->root . '/remote/one/space & plus+ percent% equals=.txt',
            $this->root . '/remote/two/unicode-ł.txt',
        ];
        $contents = ["first\0file\xff\r\n", "second file &+=%\n"];
        $file_list = [];
        foreach ($paths as $index => $path) {
            file_put_contents($path, $contents[$index]);
            $file_list[] = ['path' => base64_encode($path)];
        }
        $file_list_json = json_encode($file_list);
        $file_list_path = $this->root . '/file-list.json';
        file_put_contents($file_list_path, $file_list_json);
        $params = [
            'directory' => [$this->root . '/remote/one', $this->root . '/remote/two'],
            'skip_rows' => [[
                'table_name_without_prefix' => 'postmeta',
                'column' => 'meta_key',
                'value_base64' => base64_encode('_edit_lock'),
            ]],
            // Extra fields are ignored by file_fetch but still cross the real
            // PHP form parser, including sparse indexes and literal bytes.
            'form_values' => [
                'a&b+c=d%' => "space & plus+ percent% equals= ł\r\n",
                'flags' => [true, null, false],
                'zero' => 0,
                'empty' => [],
            ],
        ];
        $request = ( new \ReflectionMethod($client, 'build_request') )->invoke(
            $client, 'file_fetch', null, $params
        );
        $context = new StreamingContext();
        $received = '';
        $context->on_chunk = static function (array $chunk) use (&$received, $context): void {
            if (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'completion') {
                $context->saw_completion = true;
            } elseif (( $chunk['headers']['x-chunk-type'] ?? '' ) === 'file') {
                $received .= $chunk['body'];
            }
        };

        $previous_separator = ini_set('arg_separator.output', $separator);
        try {
            ( new \ReflectionMethod($client, 'fetch_streaming') )->invoke(
                $client, $request['url'], null, $context,
                $request['params'] + [
                    'file_list' => new \CURLFile($file_list_path, 'application/json', 'file-list.json'),
                ],
                'file_fetch'
            );
        } finally {
            ini_set('arg_separator.output', $previous_separator);
        }

        $expected_parameters = $request['params'];
        $expected_parameters['form_values'] = [
            'a&b+c=d%' => "space & plus+ percent% equals= ł\r\n",
            'flags' => [0 => '1', 2 => '0'],
            'zero' => '0',
        ];
        $this->assertSame($expected_parameters, json_decode(file_get_contents($this->root . '/parameters.json'), true));
        $this->assertSame($file_list_json, file_get_contents($this->root . '/uploaded-file-list.json'));
        $this->assertTrue($context->saw_completion);
        $this->assertSame(implode('', $contents), $received);
    }

    public static function query_separators(): array
    {
        return ['ampersand' => ['&'], 'HTML entity' => ['&amp;'], 'semicolon' => [';']];
    }

    private function remove_directory(string $directory): void
    {
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
