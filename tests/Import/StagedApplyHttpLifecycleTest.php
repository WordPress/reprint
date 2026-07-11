<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test diagnostics are never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The test namespace matches the existing ImportTests suite.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use Site_Export_Staged_Push_Stream_Protocol;

final class StagedApplyHttpLifecycleTest extends TestCase {
    private const SECRET = 'staged-apply-http-lifecycle-secret';

    private string $server_root;
    private string $target_root;
    private string $staging_dir;
    private string $router_path;
    private string $base_url;

    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $this->server_root = sys_get_temp_dir() . '/staged-apply-http-' . bin2hex(random_bytes(8));
        $this->target_root = $this->server_root . '/target';
        $this->staging_dir = $this->server_root . '/staging';
        $this->router_path = $this->server_root . '/router.php';
        mkdir($this->target_root, 0700, true);
        $this->write_router();
        $this->start_server();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
            $this->server_process = null;
        }
        $this->remove_tree($this->server_root);
    }

    public function testEnabledGenericServerAppliesASessionOverRealHttp(): void
    {
        $create_token = bin2hex(random_bytes(16));
        $created = $this->request_json(
            'staged_session_create&create_token=' . $create_token,
            ''
        );
        $this->assertSame(201, $created['http_code']);
        $this->assertSame('created', $created['body']['status']);
        $session_id = $created['body']['session_id'];
        $request_generation = $created['body']['request_generation'];

        file_put_contents($this->target_root . '/old.txt', 'old');
        $content = str_repeat('streamed-session-content-', 200);
        $frames = [
            ['type' => 'directory', 'operation_index' => 0, 'path' => 'content'],
            [
                'type' => 'file',
                'operation_index' => 1,
                'path' => 'content/generic-http-session.txt',
                'revision' => 1,
                'offset' => 0,
                'total_bytes' => strlen($content),
                'restart' => false,
                'payload' => $content,
            ],
            ['type' => 'delete', 'operation_index' => 2, 'path' => 'old.txt'],
        ];
        $stream_body = '';
        foreach ($frames as $frame) {
            $stream_body .= Site_Export_Staged_Push_Stream_Protocol::encode_operation_header($frame);
            if ($frame['type'] === 'file') {
                $stream_body .= $frame['payload'];
            }
        }

        $pushed = $this->request_json(
            'staged_session_push&session_id=' . $session_id . '&expected_request_generation=' . $request_generation,
            $stream_body,
            Site_Export_Staged_Push_Stream_Protocol::CONTENT_TYPE
        );
        $this->assertSame(200, $pushed['http_code']);
        $this->assertSame('complete', $pushed['body']['status']);
        $this->assertSame(3, $pushed['body']['operation_count']);
        $request_generation = $pushed['body']['request_generation'];

        $phase = 'uploading';
        $observed_phases = [];
        for ($attempt = 0; $attempt < 100 && $phase !== 'complete'; $attempt++) {
            $advanced = $this->request_json(
                'staged_session_advance&session_id=' . $session_id . '&expected_request_generation=' . $request_generation,
                ''
            );
            $this->assertSame(200, $advanced['http_code']);
            $phase = $advanced['body']['phase'];
            $observed_phases[] = $phase;
            $request_generation = $advanced['body']['request_generation'];
        }

        $this->assertSame('complete', $phase);
        $this->assertNotContains('sealing', $observed_phases);
        $this->assertNotContains('preparing', $observed_phases);
        $this->assertNotContains('verifying', $observed_phases);
        $this->assertSame($content, file_get_contents($this->target_root . '/content/generic-http-session.txt'));
        $this->assertFileDoesNotExist($this->target_root . '/old.txt');
        $this->assertFileDoesNotExist($this->target_root . '/.maintenance');
    }

    /** @return array{http_code:int,body:array<string,mixed>} */
    private function request_json(string $query, string $body, string $content_type = 'application/json'): array
    {
        $url = $this->base_url . '?endpoint=' . $query;
        $headers = ( new Site_Export_HMAC_Client(self::SECRET) )->get_envelope_auth_headers('POST', $url);
        $headers['Content-Type'] = $content_type;
        $response = $this->http_response($url, $body, $headers);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Generic staged server returned invalid JSON: ' . $response['body']);
        }
        return ['http_code' => $response['status'], 'body' => $decoded];
    }

    /** @return array{status:int,body:string} */
    private function http_response(string $url, string $body, array $headers): array
    {
        $handle = curl_init($url);
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response)) {
            throw new \RuntimeException('Generic staged server request failed: ' . $error);
        }
        return ['status' => $status, 'body' => $response];
    }

    private function write_router(): void
    {
        $autoload_path = realpath(__DIR__ . '/../../vendor/autoload.php');
        if (!is_string($autoload_path)) {
            self::fail('Could not locate Composer autoload.php for the generic staged server.');
        }
        $autoload_path = addslashes($autoload_path);
        $target_root = addslashes($this->target_root);
        $staging_dir = addslashes($this->staging_dir);
        file_put_contents($this->router_path, <<<PHP_ROUTER
<?php
if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}
require '{$autoload_path}';
Site_Export_HTTP_Server::serve([
    'default_directory' => '{$target_root}',
    'staged' => [
        'staging_dir' => '{$staging_dir}',
        'secret' => 'staged-apply-http-lifecycle-secret',
        'apply_target_root' => '{$target_root}',
        'apply_sessions_enabled' => true,
    ],
]);
return true;
PHP_ROUTER
        );
    }

    private function start_server(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error_message);
        if ($socket === false) {
            self::fail('Could not reserve generic staged server port: ' . $error_message);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr( (string) $address, ':'), 1);
        $this->base_url = 'http://127.0.0.1:' . $port . '/';
        $this->server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', $this->server_root, $this->router_path],
            [0 => ['pipe', 'r'], 1 => ['file', $this->server_root . '/server.log', 'a'], 2 => ['file', $this->server_root . '/server.log', 'a']],
            $pipes,
            $this->server_root
        );
        if (!is_resource($this->server_process)) {
            self::fail('Could not start generic staged endpoint test server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@file_get_contents($this->base_url . '__ping') === 'ok') {
                return;
            }
            usleep(100000);
        }
        self::fail('Generic staged endpoint test server did not start.');
    }

    private function remove_tree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (( file_exists($path) || is_link($path) ) && !@unlink($path)) {
                throw new \RuntimeException('Could not remove generic staged test path: ' . $path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->remove_tree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
