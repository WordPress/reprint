<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Site_Export_HMAC_Client;
use Site_Export_Staged_Artifacts;
use StagedPushStreamClient;
use StagedPushStreamProcessor;
use StagedPushStreamPusher;
use PushFrameSizer;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Drives the push stream client over curl against a local PHP server that
 * dispatches the real staged endpoint routes.
 */
class StagedPushStreamClientTest extends TestCase
{
    private const SECRET = 'staged-push-stream-client-test-secret';

    private static string $server_root;
    private static string $router_path;
    private static string $config_path;
    private static string $request_log_path;
    private static string $base_url;

    /** @var resource|null */
    private static $server_process = null;

    private string $staging_dir;
    private string $source_dir;

    /** @var int[] */
    private array $sleeps = [];

    public static function setUpBeforeClass(): void
    {
        $suffix = bin2hex(random_bytes(8));
        self::$server_root = sys_get_temp_dir() . '/staged-push-stream-site-' . $suffix;
        self::$router_path = self::$server_root . '/router.php';
        self::$config_path = self::$server_root . '/endpoint-config.json';
        self::$request_log_path = self::$server_root . '/requests.jsonl';
        mkdir(self::$server_root, 0700, true);
        self::writeRouter();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail('Could not reserve a local test port: ' . $errstr);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr((string) $address, ':'), 1);
        self::$base_url = 'http://127.0.0.1:' . $port . '/?reprint-api=1';

        self::$server_process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, '-t', self::$server_root, self::$router_path],
            [0 => ['pipe', 'r'], 1 => ['file', self::$server_root . '/server.log', 'a'], 2 => ['file', self::$server_root . '/server.log', 'a']],
            $pipes,
            self::$server_root
        );
        if (!is_resource(self::$server_process)) {
            self::fail('Could not start the local staged endpoint server.');
        }
        fclose($pipes[0]);

        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:' . $port . '/__ping') === 'ok') {
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            self::tearDownAfterClass();
            self::fail('The local staged endpoint server did not start.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server_process)) {
            proc_terminate(self::$server_process);
            proc_close(self::$server_process);
            self::$server_process = null;
        }
        if (isset(self::$server_root)) {
            self::removeDirectory(self::$server_root);
        }
    }

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->staging_dir = sys_get_temp_dir() . '/staged-push-stream-' . $suffix;
        $this->source_dir = sys_get_temp_dir() . '/staged-push-source-' . $suffix;
        mkdir($this->source_dir, 0700, true);
        $this->sleeps = [];
        @unlink(self::$request_log_path);
        $this->configureEndpoint();
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->staging_dir);
        self::removeDirectory($this->source_dir);
    }

    public function testStreamsManyFilesThroughOneRequest(): void
    {
        $this->writeSource('wp-content/uploads/first.bin', str_repeat('a', 10));
        $this->writeSource('wp-content/uploads/second.bin', str_repeat('bc', 7));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([
            'wp-content/uploads/first.bin',
            'wp-content/uploads/second.bin',
        ]);

        $result = $this->runPusher($client, new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push));

        $this->assertSame('complete', $result['status'], var_export($result, true));
        $this->assertSame(2, $result['files_verified']);
        $this->assertSame(str_repeat('a', 10), file_get_contents($this->staging_dir . '/files/wp-content/uploads/first.bin'));
        $this->assertSame(str_repeat('bc', 7), file_get_contents($this->staging_dir . '/files/wp-content/uploads/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen(), 'all file chunks travel through one request');
    }

    public function testCursorCanResumeMidFileInTheNextPushStream(): void
    {
        $this->writeSource('first.bin', str_repeat('a', 12));
        $this->writeSource('second.bin', str_repeat('b', 12));
        (new Site_Export_Staged_Artifacts($this->staging_dir))->append('second.bin', 0, str_repeat('b', 4));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush([
            'first.bin',
            'second.bin',
        ]);
        $cursor = ['artifact_id' => 'second.bin', 'committed_bytes' => 4];

        $result = $this->runPusher($client, new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push, $cursor));

        $this->assertSame('complete', $result['status'], var_export($result, true));
        $this->assertFileDoesNotExist($this->staging_dir . '/files/first.bin', 'cursor skips files before the resumed artifact');
        $this->assertSame(str_repeat('b', 12), file_get_contents($this->staging_dir . '/files/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    public function testCallerCanPauseAfterOneChunkAndResumeFromTheCursor(): void
    {
        $this->writeSource('chunked.bin', str_repeat('x', 12));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['chunked.bin']);
        $processor = new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push);
        $pusher = new StagedPushStreamPusher($client, $processor, [
            'sleeper' => function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
        ]);

        $this->assertTrue($pusher->next_request());
        $request = $pusher->get_request();
        $this->assertNotNull($request);
        $this->assertTrue($request->next_chunk());
        $request->finalize();
        $this->assertTrue($pusher->finalize_request());
        $first_result = $pusher->get_result();

        $this->assertSame('in_progress', $first_result['status'], var_export($first_result, true));
        $this->assertSame(1, $first_result['chunks_streamed']);
        $this->assertSame(['artifact_id' => 'chunked.bin', 'committed_bytes' => 4], $pusher->get_cursor());
        $this->assertSame(str_repeat('x', 4), file_get_contents($this->staging_dir . '/files/chunked.bin'));

        while ($pusher->next_request()) {
            $request = $pusher->get_request();
            $this->assertNotNull($request);
            while ($request->next_chunk()) {
                // Keep filling the current request until its configured budget is exhausted.
            }
            $this->assertTrue($pusher->finalize_request());
        }

        $this->assertSame('complete', $pusher->get_result()['status'], var_export($pusher->get_result(), true));
        $this->assertSame(str_repeat('x', 12), file_get_contents($this->staging_dir . '/files/chunked.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testPayloadByteLimitStartsNewRequestsAsNeeded(): void
    {
        $this->writeSource('byte-budget.bin', str_repeat('y', 10));
        $client = $this->makeClient();
        $local_paths_to_push = $this->writeLocalPathsToPush(['byte-budget.bin']);

        $result = $this->runPusher(
            $client,
            new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push),
            ['max_payload_bytes_per_request' => 5]
        );

        $this->assertSame('complete', $result['status'], var_export($result, true));
        $this->assertSame(str_repeat('y', 10), file_get_contents($this->staging_dir . '/files/byte-budget.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
    }

    public function testRetryFromTheBeginningSkipsAlreadyVerifiedFilesAndDuplicateBytes(): void
    {
        $this->writeSource('first.bin', str_repeat('a', 8));
        $this->writeSource('second.bin', str_repeat('b', 8));
        $store = new Site_Export_Staged_Artifacts($this->staging_dir);
        $store->append('first.bin', 0, str_repeat('a', 8));
        $store->finalize('first.bin', 8);
        $store->append('second.bin', 0, str_repeat('b', 4));
        $client = $this->makeClient([
            'frame_sizer' => new PushFrameSizer(['floor_bytes' => 4, 'start_bytes' => 8, 'max_bytes' => 8]),
        ]);
        $local_paths_to_push = $this->writeLocalPathsToPush([
            'first.bin',
            'second.bin',
        ]);

        $result = $this->runPusher($client, new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push));

        $this->assertSame('complete', $result['status'], var_export($result, true));
        $this->assertSame(str_repeat('a', 8), file_get_contents($this->staging_dir . '/files/first.bin'));
        $this->assertSame(str_repeat('b', 8), file_get_contents($this->staging_dir . '/files/second.bin'));
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    public function testFrameTooLargeShrinksAndRetriesTheStream(): void
    {
        $this->writeSource('large.bin', str_repeat('x', 20));
        $this->configureEndpoint(['max_request_bytes' => 6]);
        $sizer = new PushFrameSizer(['floor_bytes' => 4, 'start_bytes' => 12, 'max_bytes' => 12]);
        $client = $this->makeClient(['frame_sizer' => $sizer]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['large.bin']);

        $result = $this->runPusher($client, new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push));

        $this->assertSame('complete', $result['status'], var_export($result, true));
        $this->assertSame(str_repeat('x', 20), file_get_contents($this->staging_dir . '/files/large.bin'));
        $this->assertSame(['staged_push', 'staged_push'], $this->endpointsSeen());
        $this->assertLessThanOrEqual(5, $sizer->chunk_bytes());
    }

    public function testWrongSecretFailsBeforeReadingTheBody(): void
    {
        $this->writeSource('secret.bin', 'secret');
        $client = $this->makeClient([
            'hmac_client' => new Site_Export_HMAC_Client('wrong-secret'),
        ]);
        $local_paths_to_push = $this->writeLocalPathsToPush(['secret.bin']);

        $result = $this->runPusher($client, new StagedPushStreamProcessor($this->source_dir, $local_paths_to_push));

        $this->assertSame(['failed', 'auth_failed'], [$result['status'], $result['reason']], var_export($result, true));
        $this->assertFileDoesNotExist($this->staging_dir . '/files/secret.bin');
        $this->assertSame(['staged_push'], $this->endpointsSeen());
    }

    private static function writeRouter(): void
    {
        $import_path = addslashes(realpath(__DIR__ . '/../../packages/reprint-importer/src/import.php'));
        $config_path = addslashes(self::$config_path);
        $request_log_path = addslashes(self::$request_log_path);

        file_put_contents(self::$router_path, <<<PHP_ROUTER
<?php
// PHP 8.1 emits the required CLI script's shebang; keep test HTTP responses clean.
ob_start();
require_once '{$import_path}';
ob_end_clean();

if (parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ping') {
    echo 'ok';
    return true;
}

\$config = json_decode((string) file_get_contents('{$config_path}'), true);
if (!is_array(\$config)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing endpoint config']);
    return true;
}

file_put_contents(
    '{$request_log_path}',
    json_encode([
        'endpoint' => (string) ( \$_GET['endpoint'] ?? '' ),
        'method' => \$_SERVER['REQUEST_METHOD'] ?? '',
        'content_length' => \$_SERVER['CONTENT_LENGTH'] ?? null,
    ]) . "\n",
    FILE_APPEND
);

\$server = new Site_Export_HTTP_Server([
    'staged' => [
        'staging_dir' => (string) \$config['staging_dir'],
        'secret' => (string) \$config['secret'],
        'max_request_bytes' => (int) ( \$config['max_request_bytes'] ?? 1073741824 ),
    ],
]);
\$server->handle_request();
return true;
PHP_ROUTER);
    }

    private function configureEndpoint(array $overrides = []): void
    {
        file_put_contents(self::$config_path, json_encode(array_merge([
            'staging_dir' => $this->staging_dir,
            'secret' => self::SECRET,
            'max_request_bytes' => 1073741824,
        ], $overrides)));
    }

    private function makeClient(array $overrides = []): StagedPushStreamClient
    {
        return new StagedPushStreamClient(array_merge([
            'base_url' => self::$base_url,
            'hmac_client' => new Site_Export_HMAC_Client(self::SECRET),
            'frame_sizer' => new PushFrameSizer(['floor_bytes' => 4, 'start_bytes' => 4, 'max_bytes' => 4]),
        ], $overrides));
    }

    /**
     * @param array{max_chunks_per_request?:int,max_payload_bytes_per_request?:int} $options
     * @return array{status:string,reason:?string,detail:?string,cursor:?array,files_verified:int,bytes_streamed:int,chunks_streamed:int}
     */
    private function runPusher(StagedPushStreamClient $client, StagedPushStreamProcessor $processor, array $options = []): array
    {
        $pusher = new StagedPushStreamPusher($client, $processor, array_merge([
            'sleeper' => function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
        ], $options));
        while ($pusher->next_request()) {
            $request = $pusher->get_request();
            $this->assertNotNull($request);
            while ($request->next_chunk()) {
                // The caller owns this inner loop and may finalize after any chunk.
            }
            $this->assertTrue($pusher->finalize_request());
        }
        return $pusher->get_result() ?? [
            'status' => 'complete',
            'reason' => null,
            'detail' => null,
            'cursor' => null,
            'files_verified' => 0,
            'bytes_streamed' => 0,
            'chunks_streamed' => 0,
        ];
    }

    private function writeSource(string $name, string $body): string
    {
        $path = $this->source_dir . '/' . $name;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $body);
        return $path;
    }

    /** @param string[] $artifact_ids */
    private function writeLocalPathsToPush(array $artifact_ids): string
    {
        $path = $this->source_dir . '/local-paths-to-push.jsonl';
        $body = '';
        foreach ($artifact_ids as $artifact_id) {
            $body .= json_encode(['path' => base64_encode($artifact_id)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $body);
        return $path;
    }

    /** @return string[] */
    private function endpointsSeen(): array
    {
        if (!file_exists(self::$request_log_path)) {
            return [];
        }
        $endpoints = [];
        foreach (file(self::$request_log_path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $endpoints[] = (string) ($decoded['endpoint'] ?? '');
            }
        }
        return $endpoints;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $directory_entry) {
            is_dir($directory_entry) ? self::removeDirectory($directory_entry) : @unlink($directory_entry);
        }
        @rmdir($directory);
    }
}
