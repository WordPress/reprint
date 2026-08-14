<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the next line.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Boundary clients live with the lifecycle tests which use them.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The generated local router needs literal PHP values.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Exercises the durable remote-index sort phase through the real exporter. */
final class RemoteIndexSortResumeTest extends TestCase
{
    private $tempDirectory;
    private $stateDirectory;
    private $filesystemRoot;
    private $remoteSiteDirectory;
    private $requestLog;
    private $remoteUrl;
    private $serverProcess;
    private $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory = sys_get_temp_dir()
            . '/remote-index-sort-' . uniqid();
        $this->stateDirectory = $this->tempDirectory . '/state';
        $this->filesystemRoot = $this->tempDirectory . '/local';
        $this->remoteSiteDirectory = $this->tempDirectory . '/remote-site';
        $this->requestLog = $this->tempDirectory . '/requests.jsonl';
        foreach (
            [
                $this->stateDirectory,
                $this->filesystemRoot,
                $this->remoteSiteDirectory,
            ] as $directory
        ) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($this->remoteSiteDirectory . '/z-last.txt', 'z');
        file_put_contents($this->remoteSiteDirectory . '/a-first.txt', 'a');
        $this->remoteUrl = $this->startRealExporter();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess, 9);
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->serverProcess);
        }
        $this->removeTree($this->tempDirectory);
        parent::tearDown();
    }

    public function testFilesIndexResumesSortWithoutAnotherIndexRequest(): void
    {
        $this->writePreflightState();

        try {
            $this->runClient(
                $this->newClient(StopAfterFilesIndexSortClient::class),
                'files-index'
            );
            $this->fail('Expected the post-sort state save to stop files-index.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stop after sorting the files-index output.',
                $exception->getMessage()
            );
        }

        $stateAfterSort = $this->readState();
        $indexAfterSort = file_get_contents($this->nextRemoteIndexPath());
        $requestsAfterSort = $this->fileIndexRequestCount();
        $this->assertSame(
            'sort',
            $stateAfterSort['active_resumable_command']['current_stage']
        );
        $this->assertGreaterThan(0, $requestsAfterSort);
        $this->assertIsString($indexAfterSort);

        $this->runClient($this->newClient(), 'files-index');

        $this->assertSame($requestsAfterSort, $this->fileIndexRequestCount());
        $this->assertSame($indexAfterSort, file_get_contents($this->nextRemoteIndexPath()));
        $this->assertSame(
            'complete',
            $this->readState()['active_resumable_command']['completion_state']
        );
    }

    public function testFilesPullResumesSortWithoutAnotherIndexRequest(): void
    {
        $this->writePreflightState();

        $stoppedAfterSort = false;
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            try {
                $this->runClient(
                    $this->newClient(StopAfterFilesPullSortClient::class),
                    'files-pull'
                );
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Stop after sorting the files-pull index.',
                    $exception->getMessage()
                );
                $stoppedAfterSort = true;
                break;
            }
        }
        $this->assertTrue(
            $stoppedAfterSort,
            'Expected the post-sort state save to stop files-pull.'
        );

        $stateAfterSort = $this->readState();
        $requestsAfterSort = $this->fileIndexRequestCount();
        $this->assertSame(
            'sort',
            $stateAfterSort['active_resumable_command']['current_stage']
        );
        $this->assertGreaterThan(0, $requestsAfterSort);

        $this->runClient($this->newClient(), 'files-pull');

        $this->assertSame($requestsAfterSort, $this->fileIndexRequestCount());
        $this->assertSame(
            'complete',
            $this->readState()['active_resumable_command']['completion_state']
        );
    }

    /** @param class-string<\ImportClient> $clientClass */
    private function newClient(string $clientClass = \ImportClient::class): \ImportClient
    {
        $client = new $clientClass(
            $this->remoteUrl,
            $this->stateDirectory,
            $this->filesystemRoot
        );
        $output = fopen('php://temp', 'w+');
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('progress_fd')->setValue($client, $output);
        $progress = $reflection->getProperty('progress')->getValue($client);
        ( new \ReflectionClass($progress) )->getProperty('progress_fd')->setValue(
            $progress,
            $output
        );
        return $client;
    }

    private function runClient(\ImportClient $client, string $command): void
    {
        $client->run([
            'command' => $command,
            'follow_symlinks' => false,
            'progress' => 'jsonl',
        ]);
    }

    private function writePreflightState(): void
    {
        \write_current_pull_state($this->newClient(), [
            'preflight' => [
                'data' => [
                    'ok' => true,
                    'runtime' => [
                        'document_root' => $this->remoteSiteDirectory,
                    ],
                    'wp_detect' => [
                        'roots' => [
                            ['path' => $this->remoteSiteDirectory],
                        ],
                    ],
                ],
                'http_code' => 200,
            ],
            'remote_protocol_version' => PULL_PROTOCOL_VERSION,
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
        ]);
    }

    private function readState(): array
    {
        $state = json_decode(
            file_get_contents($this->pullStateDirectory() . '/state.json'),
            true
        );
        $this->assertIsArray($state);
        return $state;
    }

    private function fileIndexRequestCount(): int
    {
        $requests = is_file($this->requestLog)
            ? file($this->requestLog, FILE_IGNORE_NEW_LINES)
            : [];
        $count = 0;
        foreach ($requests ?: [] as $requestJson) {
            $request = json_decode($requestJson, true);
            if (is_array($request) && ( $request['endpoint'] ?? null ) === 'file_index') {
                ++$count;
            }
        }
        return $count;
    }

    private function pullStateDirectory(): string
    {
        return $this->stateDirectory . '/remotes/'
            . md5(rtrim($this->remoteUrl, '?&')) . '/pull';
    }

    private function nextRemoteIndexPath(): string
    {
        return $this->pullStateDirectory() . '/remote-index.next.jsonl';
    }

    private function startRealExporter(): string
    {
        $router = $this->tempDirectory . '/router.php';
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
require_once %s;
require_once %s;
Site_Export_HTTP_Server::serve(['default_directory' => %s]);
PHP,
                var_export($this->requestLog, true),
                var_export($autoload, true),
                var_export($serverClass, true),
                var_export($this->remoteSiteDirectory, true)
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
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['SITE_EXPORT_TEST_MODE'] = '1';
        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->serverPipes,
            $this->tempDirectory,
            $environment
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
        $this->fail('The real exporter did not start.');
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

/** Stops files-index after the atomic sort and before its completion save. */
final class StopAfterFilesIndexSortClient extends \ImportClient
{
    private $stopped = false;

    public function save_state(): void
    {
        $command = $this->get_state()->active_resumable_command;
        if (
            !$this->stopped
            && $command->command_name === 'files-index'
            && $command->completion_state === 'complete'
            && $command->current_stage === null
        ) {
            $this->stopped = true;
            throw new \RuntimeException(
                'Stop after sorting the files-index output.'
            );
        }
        parent::save_state();
    }
}

/** Stops files-pull after the atomic sort and before its diff-phase save. */
final class StopAfterFilesPullSortClient extends \ImportClient
{
    private $stopped = false;

    public function save_state(): void
    {
        $command = $this->get_state()->active_resumable_command;
        if (
            !$this->stopped
            && $command->command_name === 'files-pull'
            && $command->current_stage === 'diff'
        ) {
            $this->stopped = true;
            throw new \RuntimeException(
                'Stop after sorting the files-pull index.'
            );
        }
        parent::save_state();
    }
}
