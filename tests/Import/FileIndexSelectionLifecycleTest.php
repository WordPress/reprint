<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the next line.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generated child and router scripts require literal PHP values.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Exercises file-index selection checkpoints at a real preflight request. */
final class FileIndexSelectionLifecycleTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystemRoot;
    private $remoteSite;
    private $extraDirectory;
    private $controlFile;
    private $preflightReadyFile;
    private $remoteUrl;
    private $serverProcess;
    private $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-selection-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystemRoot = $this->tempDir . '/local';
        $this->remoteSite = $this->tempDir . '/remote-site';
        $this->extraDirectory = $this->tempDir . '/remote-extra';
        $this->controlFile = $this->tempDir . '/control.json';
        $this->preflightReadyFile = $this->tempDir . '/preflight-ready.log';
        foreach (
            [
                $this->stateDir,
                $this->filesystemRoot,
                $this->remoteSite,
                $this->extraDirectory,
            ] as $directory
        ) {
            mkdir($directory, 0755, true);
        }
        $this->writeControl([]);
        $this->remoteUrl = $this->startExporter();
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
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testPullFilesCheckpointsSelectionBeforeInterruptedPreflight(): void
    {
        $this->writeControl(['block_preflight' => true]);
        [$firstProcess, $firstPipes] = $this->startPullFilesProcess(
            true,
            $this->extraDirectory
        );
        $this->waitForPreflightReadyCount(1, $firstProcess, $firstPipes);

        $state = $this->readState();
        $this->assertTrue($state['include_caches']);
        $this->assertSame(
            'base64:' . base64_encode($this->extraDirectory),
            $state['extra_directory']
        );
        $this->assertSame(
            ['preflight', 'files-pull'],
            $state['pull_pipeline']['stage_sequence']
        );
        $this->assertNull($state['pull_pipeline']['last_completed_stage']);
        $this->stopPullFilesProcess($firstProcess, $firstPipes);

        $this->writeControl(['block_preflight' => true]);
        [$secondProcess, $secondPipes] = $this->startPullFilesProcess(null);
        $this->waitForPreflightReadyCount(2, $secondProcess, $secondPipes);
        $state = $this->readState();
        $this->assertTrue($state['include_caches']);
        $this->assertSame(
            'base64:' . base64_encode($this->extraDirectory),
            $state['extra_directory']
        );
        $this->stopPullFilesProcess($secondProcess, $secondPipes);
    }

    public function testPullFilesAdoptsDirectFilesPullSelectionBeforePreflight(): void
    {
        \write_current_pull_state(
            new \ImportClient(
                $this->remoteUrl,
                $this->stateDir,
                $this->filesystemRoot
            ),
            [
                'active_resumable_command' => [
                    'command_name' => 'files-pull',
                    'completion_state' => 'in_progress',
                    'current_stage' => 'index',
                    'remote_cursor' => null,
                ],
                'include_caches' => true,
                'extra_directory' => $this->extraDirectory,
            ]
        );
        $this->writeControl(['block_preflight' => true]);

        [$process, $pipes] = $this->startPullFilesProcess(null);
        $this->waitForPreflightReadyCount(1, $process, $pipes);
        $state = $this->readState();
        $this->assertTrue($state['include_caches']);
        $this->assertSame(
            'base64:' . base64_encode($this->extraDirectory),
            $state['extra_directory']
        );
        $this->assertSame(
            'pull-files',
            $state['pull_pipeline']['started_by_command']
        );
        $this->stopPullFilesProcess($process, $pipes);
    }

    /** @return array{0:resource,1:array<int,resource>} */
    private function startPullFilesProcess(
        ?bool $includeCaches,
        ?string $extraDirectory = null
    ): array {
        $script = $this->tempDir . '/pull-files-' . uniqid() . '.php';
        $importer = realpath(
            __DIR__ . '/../../packages/reprint-client/src/import.php'
        );
        $this->assertIsString($importer);
        $options = [
            'command' => 'pull-files',
            'fs_root_nonempty_behavior' => 'preserve-local',
            'progress' => 'jsonl',
        ];
        if ($includeCaches !== null) {
            $options['include_caches'] = $includeCaches;
        }
        if ($extraDirectory !== null) {
            $options['extra_directory'] = $extraDirectory;
        }
        file_put_contents(
            $script,
            sprintf(
                <<<'PHP'
<?php
require_once %s;
$client = new ImportClient(%s, %s, %s, 'pull-files');
$client->run(%s);
PHP,
                var_export($importer, true),
                var_export($this->remoteUrl, true),
                var_export($this->stateDir, true),
                var_export($this->filesystemRoot, true),
                var_export($options, true)
            )
        );
        $process = proc_open(
            [PHP_BINARY, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->tempDir
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        unset($pipes[0]);
        return [$process, $pipes];
    }

    /**
     * @param resource            $process Child process.
     * @param array<int,resource> $pipes   Child output pipes.
     */
    private function stopPullFilesProcess($process, array $pipes): void
    {
        proc_terminate($process, 9);
        $this->writeControl([]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        usleep(100000);
    }

    /**
     * @param resource            $process Child process.
     * @param array<int,resource> $pipes   Child output pipes.
     */
    private function waitForPreflightReadyCount(
        int $expectedCount,
        $process,
        array $pipes
    ): void {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $lines = is_file($this->preflightReadyFile)
                ? file($this->preflightReadyFile, FILE_IGNORE_NEW_LINES)
                : [];
            if (count($lines ?: []) >= $expectedCount) {
                return;
            }
            usleep(50000);
        }
        $status = proc_get_status($process);
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->fail(
            'The preflight request did not reach its blocking boundary. '
            . 'Process running: ' . ( !empty($status['running']) ? 'yes' : 'no' )
            . '; stdout: ' . stream_get_contents($pipes[1])
            . '; stderr: ' . stream_get_contents($pipes[2])
        );
    }

    private function startExporter(): string
    {
        $router = $this->tempDir . '/router.php';
        $serverClass = realpath(
            __DIR__ . '/../../packages/reprint-server/src/class-http-server.php'
        );
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        $this->assertIsString($serverClass);
        $this->assertIsString($autoload);
        file_put_contents(
            $router,
            sprintf(
                <<<'PHP'
<?php
$control = json_decode((string) file_get_contents(%s), true);
if (
    ($_GET['endpoint'] ?? null) === 'preflight'
    && !empty($control['block_preflight'])
) {
    file_put_contents(%s, "ready\n", FILE_APPEND);
    do {
        usleep(20000);
        clearstatcache(true, %s);
        $control = json_decode((string) file_get_contents(%s), true);
    } while (!empty($control['block_preflight']));
}
require_once %s;
require_once %s;
Site_Export_HTTP_Server::serve(['default_directory' => %s]);
PHP,
                var_export($this->controlFile, true),
                var_export($this->preflightReadyFile, true),
                var_export($this->controlFile, true),
                var_export($this->controlFile, true),
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
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['SITE_EXPORT_TEST_MODE'] = '1';
        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->serverPipes,
            $this->tempDir,
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
        $this->fail('Exporter did not start.');
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->pullStateDirectory() . '/state.json'),
            true
        );
    }

    private function pullStateDirectory(): string
    {
        return $this->stateDir . '/remotes/'
            . md5(rtrim($this->remoteUrl, '?&'))
            . '/pull';
    }

    private function writeControl(array $control): void
    {
        file_put_contents($this->controlFile, json_encode($control));
        clearstatcache(true, $this->controlFile);
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
