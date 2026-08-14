<?php

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The generated router needs literal filesystem paths.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the next line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

/** Exercises file-index protocol rejection through the CLI and a real HTTP endpoint. */
final class FileIndexProtocolVersionTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystemRoot;
    private $remoteSite;
    private $requestLog;
    private $protocolVersionFile;
    private $remoteUrl;
    private $serverProcess;
    private $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-protocol-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystemRoot = $this->tempDir . '/local';
        $this->remoteSite = $this->tempDir . '/remote-site';
        $this->requestLog = $this->tempDir . '/requests.jsonl';
        $this->protocolVersionFile = $this->tempDir . '/protocol-version';
        foreach ([$this->stateDir, $this->filesystemRoot, $this->remoteSite] as $directory) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($this->remoteSite . '/index.php', '<?php');
        file_put_contents($this->protocolVersionFile, '1');
        $this->remoteUrl = $this->startExporter();
    }

    protected function tearDown(): void
    {
        $this->stopExporter();
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testDirectFileIndexCommandsRejectProtocolV1BeforeAFileIndexRequest(): void
    {
        $this->runPreflight();

        foreach (['files-index', 'files-pull'] as $command) {
            $result = $this->runCli([$command]);
            $this->assertSame(1, $result['exit'], $result['output']);
            $this->assertStringContainsString(
                'Remote protocol v1 does not match client protocol v2',
                $result['output']
            );
            $this->assertStringContainsString(
                'Update the export plugin',
                $result['output']
            );
            $this->assertSame([], $this->fileIndexRequests());
        }
        $this->assertNull(
            $this->readSavedState()['active_resumable_command']['completion_state']
        );
    }

    public function testPullFilesRetriesPreflightAfterProtocolV1IsUpgraded(): void
    {
        $result = $this->runCli([
            'pull-files',
            '--on-fs-root-nonempty=preserve-local',
        ]);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString(
            'Remote protocol v1 does not match client protocol v2',
            $result['output']
        );
        $this->assertStringContainsString(
            'Update the export plugin',
            $result['output']
        );
        $this->assertSame([], $this->fileIndexRequests());
        $savedState = $this->readSavedState();
        $this->assertSame(1, $savedState['remote_protocol_version']);
        $this->assertNull($savedState['pull_pipeline']['last_completed_stage']);
        $this->assertNull(
            $savedState['active_resumable_command']['completion_state']
        );

        file_put_contents($this->protocolVersionFile, '2');
        $result = $this->runCli([
            'pull-files',
            '--on-fs-root-nonempty=preserve-local',
        ]);

        $this->assertStringNotContainsString(
            'does not match client protocol',
            $result['output']
        );
        $this->assertNotSame([], $this->fileIndexRequests());
        $this->assertCount(2, $this->requestsForEndpoint('preflight'));
    }

    public function testProtocolV1DoesNotPreventLocalFileIndexAbort(): void
    {
        $this->runPreflight();
        $this->stopExporter();

        foreach (['files-pull', 'files-index'] as $command) {
            $result = $this->runCli([$command, '--abort']);
            $this->assertSame(0, $result['exit'], $result['output']);
            $this->assertStringContainsString(
                '"status":"aborted"',
                $result['output']
            );
        }

        $this->assertSame(
            1,
            $this->readSavedState()['remote_protocol_version']
        );
        $this->assertSame([], $this->fileIndexRequests());
    }

    private function runPreflight(): void
    {
        $result = $this->runCli(['preflight']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(1, $this->readSavedState()['remote_protocol_version']);
    }

    /** @return array{exit:int,output:string} */
    private function runCli(array $arguments): array
    {
        $command = array_merge(
            [
                PHP_BINARY,
                __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                $arguments[0],
                $this->remoteUrl,
            ],
            array_slice($arguments, 1),
            [
                '--state-dir=' . $this->stateDir,
                '--fs-root=' . $this->filesystemRoot,
            ]
        );
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->tempDir
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        return [
            'exit' => $exit,
            'output' => $stdout . $stderr,
        ];
    }

    private function readSavedState(): array
    {
        return json_decode(
            (string) file_get_contents(
                $this->stateDir
                    . '/remotes/'
                    . md5(rtrim($this->remoteUrl, '?&'))
                    . '/pull/state.json'
            ),
            true
        );
    }

    private function startExporter(): string
    {
        $router = $this->tempDir . '/router.php';
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        $serverClass = realpath(
            __DIR__ . '/../../packages/reprint-server/src/class-http-server.php'
        );
        $this->assertIsString($autoload);
        $this->assertIsString($serverClass);
        file_put_contents(
            $router,
            sprintf(
                <<<'PHP'
<?php
file_put_contents(
    %s,
    json_encode(['endpoint' => $_GET['endpoint'] ?? null]) . "\n",
    FILE_APPEND
);
if (($_GET['endpoint'] ?? null) === 'preflight') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'protocol_version' => (int) file_get_contents(%s),
        'runtime' => [
            'document_root' => %s,
            'ini_get_all' => [],
        ],
        'filesystem' => ['ok' => true],
        'wp_detect' => [
            'roots' => [['path' => %s]],
        ],
        'database' => [
            'wp' => [
                'paths_urls' => [
                    'content_dir' => %s,
                ],
            ],
        ],
    ]);
    return;
}
require_once %s;
require_once %s;
Site_Export_HTTP_Server::serve(['default_directory' => %s]);
PHP,
                var_export($this->requestLog, true),
                var_export($this->protocolVersionFile, true),
                var_export($this->remoteSite, true),
                var_export($this->remoteSite, true),
                var_export($this->remoteSite, true),
                var_export($autoload, true),
                var_export($serverClass, true),
                var_export($this->remoteSite, true)
            )
        );

        $socket = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorNumber,
            $errorMessage
        );
        $this->assertIsResource($socket, $errorMessage);
        $socketName = stream_socket_get_name($socket, false);
        $this->assertIsString($socketName);
        fclose($socket);
        $port = (int) substr(strrchr($socketName, ':'), 1);
        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->serverPipes,
            $this->tempDir
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
                return 'http://127.0.0.1:' . $port
                    . '/export.php?site-export-api';
            }
            usleep(100000);
        }
        $this->fail('Exporter did not start.');
    }

    private function stopExporter(): void
    {
        if (!is_resource($this->serverProcess)) {
            return;
        }
        proc_terminate($this->serverProcess, 9);
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($this->serverProcess);
        $this->serverProcess = null;
        $this->serverPipes = [];
    }

    private function fileIndexRequests(): array
    {
        return $this->requestsForEndpoint('file_index');
    }

    private function requestsForEndpoint(string $expectedEndpoint): array
    {
        $lines = is_file($this->requestLog)
            ? file($this->requestLog, FILE_IGNORE_NEW_LINES)
            : [];
        $requests = [];
        foreach ($lines ?: [] as $line) {
            $request = json_decode($line, true);
            $endpoint = $request['endpoint'] ?? null;
            if ($endpoint === $expectedEndpoint) {
                $requests[] = $request;
            }
        }
        return $requests;
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
