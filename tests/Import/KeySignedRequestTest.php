<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;
use WordPress\Reprint\Server\PublicKeyClient;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * Sends key-signed importer requests to the production plugin entry point.
 *
 * The server is `php -S` on tests/fixtures/api-authentication-router.php,
 * which authenticates through index.php as a live site does. The importer
 * signs a form-encoded preflight and a multipart file_fetch. A key signature
 * covers the method and request target, never the body, so both must pass
 * whatever encoding cURL chose for the body.
 */
final class KeySignedRequestTest extends TestCase {

    private string $root;
    private string $url;
    private string $private_key_path;
    /** @var resource|null */
    private $server_process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-key-signed-request-' . bin2hex(random_bytes(6));
        foreach (['/credentials', '/docroot', '/state', '/local', '/remote'] as $directory) {
            mkdir($this->root . $directory, 0700, true);
        }
        $this->root = realpath($this->root);

        [$private_key_pem, $public_key] = PublicKeyClient::generate_keypair();
        $this->private_key_path = $this->root . '/enrolled.pem';
        file_put_contents($this->private_key_path, $private_key_pem);
        chmod($this->private_key_path, 0600);
        file_put_contents($this->root . '/configuration.json', json_encode([
            'credentials_directory' => $this->root . '/credentials',
            'options' => [
                'reprint_server_public_keys' => [[
                    'public_key' => $public_key,
                    'added_at' => 1700000000,
                    'push' => false,
                ]],
            ],
        ], JSON_THROW_ON_ERROR));

        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error);
        $this->assertIsResource($listener, (string) $error);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/?reprint-api';
        $router = realpath(__DIR__ . '/../fixtures/api-authentication-router.php');
        $this->assertIsString($router);
        $this->server_process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $this->root . '/docroot', $router],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            dirname($router),
            array_merge($_ENV, ['REPRINT_AUTH_TEST_CONFIG' => $this->root . '/configuration.json'])
        );
        $this->assertIsResource($this->server_process);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Server did not start: ' . file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testPreflightSignedWithTheEnrolledKeyPassesAuthentication(): void
    {
        $report = $this->runPreflight($this->private_key_path);

        // The fixture site has no database, so preflight fails after the
        // server authenticated the request and answered with its report.
        $this->assertSame('PREFLIGHT_FAILED', $report['error_code'], json_encode($report));
        $this->assertSame(200, $report['http_code']);
    }

    public function testPreflightSignedWithAnotherKeyIsRefused(): void
    {
        [$other_private_key_pem, ] = PublicKeyClient::generate_keypair();
        $other_private_key_path = $this->root . '/other.pem';
        file_put_contents($other_private_key_path, $other_private_key_pem);
        chmod($other_private_key_path, 0600);

        $report = $this->runPreflight($other_private_key_path);

        $this->assertSame('AUTH_UNKNOWN_KEY', $report['error_code'], json_encode($report));
        $this->assertSame(403, $report['http_code']);
    }

    public function testMultipartFileFetchSignedWithTheEnrolledKeyStreamsTheFile(): void
    {
        $remote_path = $this->root . '/remote/pulled.txt';
        file_put_contents($remote_path, "pulled\0contents\n");
        $client = new \ImportClient($this->url, $this->root . '/state', $this->root . '/local', ['allow_http' => true]);
        ( new \ReflectionMethod($client, 'initialize_credential') )->invoke(
            $client, true, ['private_key_path' => $this->private_key_path]
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $file_list_path = $this->root . '/file-list.json';
        file_put_contents($file_list_path, json_encode([['path' => base64_encode($remote_path)]]));
        $request = ( new \ReflectionMethod($client, 'build_request') )->invoke(
            $client, 'file_fetch', null, ['directory' => [$this->root . '/remote']]
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

        ( new \ReflectionMethod($client, 'fetch_streaming') )->invoke(
            $client, $request['url'], null, $context,
            $request['params'] + [
                'file_list' => new \CURLFile($file_list_path, 'application/json', 'file-list.json'),
            ],
            'file_fetch'
        );

        $this->assertTrue($context->saw_completion, file_get_contents($this->root . '/server.log'));
        $this->assertSame("pulled\0contents\n", $received);
    }

    /** @return array<string,mixed> The command report preflight printed last. */
    private function runPreflight(string $private_key_path): array
    {
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
            'preflight',
            $this->url,
            '--state-dir=' . $this->root . '/state',
            '--fs-root=' . $this->root . '/local',
            '--private-key-path=' . $private_key_path,
            '--allow-unsafe-http',
            '--progress=jsonl',
        ]));
        exec($command . ' 2>/dev/null', $output_lines);
        $report = json_decode( (string) end($output_lines), true);
        $this->assertIsArray($report, implode("\n", $output_lines));
        $this->assertSame('reprint_report', $report['type']);
        return $report;
    }
}
