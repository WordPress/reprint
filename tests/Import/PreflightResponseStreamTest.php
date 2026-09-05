<?php

namespace ReprintTests\Import;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class PreflightResponseStreamTest extends TestCase {
    /** @var resource|null */
    private $serverProcess;

    /** @var resource[] */
    private array $serverPipes = [];

    private string $root;
    private string $remoteReprintApiUrl;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('Preflight response tests require PHP curl.');
        }

        $this->root = sys_get_temp_dir() . '/preflight-response-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->remoteReprintApiUrl = $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->serverProcess);
        }
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testClientRequestsAndDecodesMultipartPreflightResponse(): void
    {
        $client = $this->newClient('multipart');

        $client->run_preflight();

        $preflight = $client->get_state()->preflight_record()['data'];
        $this->assertSame(
            '/home/users/example/mausool.com/public_html',
            $preflight['runtime']['document_root']
        );
        $this->assertSame('https://mausool.com', $preflight['database']['wp']['home']);
    }

    public function testClientStillAcceptsPlainPreflightResponse(): void
    {
        $client = $this->newClient('plain');

        $client->run_preflight();

        $preflight = $client->get_state()->preflight_record()['data'];
        $this->assertSame(
            '/home/users/example/mausool.com/public_html',
            $preflight['runtime']['document_root']
        );
        $this->assertSame('https://mausool.com', $preflight['database']['wp']['home']);
    }

    private function newClient(string $response): \ImportClient
    {
        return new \ImportClient(
            $this->remoteReprintApiUrl . '&response=' . $response,
            $this->root . '/state-' . $response,
            $this->root . '/files-' . $response
        );
    }

    private function startServer(): string
    {
        $router = $this->root . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php

$preflight = [
    'ok' => true,
    'protocol_version' => 2,
    'runtime' => [
        'document_root' => '/home/users/example/mausool.com/public_html',
        'ini_get_all' => [],
    ],
    'wp_detect' => [
        'roots' => [
            ['path' => '/home/users/example/mausool.com/public_html'],
        ],
    ],
    'filesystem' => ['ok' => true],
    'database' => [
        'connected' => true,
        'wp' => [
            'home' => 'https://mausool.com',
            'wp_version' => '6.0-test',
        ],
    ],
];

if (($_GET['response'] ?? null) === 'plain') {
    header('Content-Type: application/json');
    echo json_encode($preflight);
    return;
}
if (($_GET['preflight_response_format'] ?? null) !== 'multipart') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Client did not request the multipart preflight response.']);
    return;
}
$boundary = 'boundary-preflight-test';
$preflight_json = json_encode($preflight);
$preflight_base64 = base64_encode($preflight_json);
header('Content-Type: multipart/mixed; boundary="' . $boundary . '"');
echo '--' . $boundary . "\r\n";
echo "Content-Type: application/octet-stream\r\n";
echo 'Content-Length: ' . strlen($preflight_base64) . "\r\n";
echo "Content-Transfer-Encoding: base64\r\n";
echo "X-Chunk-Type: preflight\r\n\r\n";
echo $preflight_base64;
echo "\r\n--" . $boundary . "\r\n";
echo "Content-Type: application/octet-stream\r\n";
echo "Content-Length: 0\r\n";
echo "X-Chunk-Type: completion\r\n";
echo "X-Status: complete\r\n\r\n";
echo "\r\n--" . $boundary . "--\r\n";
PHP
        );

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $socketName = stream_socket_get_name($socket, false);
        $this->assertIsString($socketName);
        fclose($socket);
        $port = (int) substr(strrchr($socketName, ':'), 1);

        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $this->serverPipes,
            $this->root
        );
        $this->assertIsResource($this->serverProcess);
        fclose($this->serverPipes[0]);

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $connection = @fsockopen(
                '127.0.0.1',
                $port,
                $errorNumber,
                $errorMessage,
                0.1
            );
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://127.0.0.1:' . $port . '/router.php?site-export-api';
            }
            usleep(100000);
        }

        $this->fail('Preflight response server did not start.');
    }

    private function removeTree(string $path): void
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
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
