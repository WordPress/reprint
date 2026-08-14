<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the next line.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- One fault-injection client exercises a state-save boundary.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generated router and hook scripts require literal PHP values.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Exercises target-confirmed remote index batches through the real HTTP endpoint. */
final class RemoteFileIndexConfirmedBatchTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystemRoot;
    private $siteDir;
    private $extraDir;
    private $requestLog;
    private $controlFile;
    private $remoteUrl;
    private $serverProcess;
    private $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir()
            . '/remote-index-confirmed-batch-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystemRoot = $this->tempDir . '/local';
        $this->siteDir = $this->tempDir . '/remote-site';
        $this->extraDir = $this->tempDir . '/remote-extra';
        $this->requestLog = $this->tempDir . '/requests.jsonl';
        $this->controlFile = $this->tempDir . '/control.json';
        foreach (
            [
                $this->stateDir,
                $this->filesystemRoot,
                $this->siteDir,
                $this->extraDir,
                $this->siteDir . '/wp-content/plugins/site-export',
            ] as $directory
        ) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
        file_put_contents($this->siteDir . '/index.php', '<?php');
        $this->writeControl([]);
        $this->writeDirectoryScanHook();
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
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testRejectedFollowedTargetIsAbandonedBeforeTheNextQueuedTarget(): void
    {
        $rejectedTarget = $this->tempDir . '/remote-target-a';
        $indexedTarget = $this->tempDir . '/remote-target-b';
        mkdir($rejectedTarget);
        mkdir($indexedTarget);
        $canonicalRejectedTarget = realpath($rejectedTarget);
        $canonicalIndexedTarget = realpath($indexedTarget);
        $this->assertIsString($canonicalRejectedTarget);
        $this->assertIsString($canonicalIndexedTarget);
        $indexedFile = $indexedTarget . '/indexed.txt';
        file_put_contents($indexedFile, 'indexed');
        symlink($rejectedTarget, $this->siteDir . '/a-rejected-link');
        symlink($indexedTarget, $this->siteDir . '/b-indexed-link');
        $this->writeControl([
            'remove_and_reject_list_directory' => $canonicalRejectedTarget,
        ]);

        $client = $this->newFilesIndexClient();
        $client->run([
            'command' => 'files-index',
            'follow_symlinks' => true,
            'include_caches' => false,
            'progress' => 'jsonl',
        ]);

        $startedDirectories = [];
        foreach ($this->fileIndexRequests() as $request) {
            $listDirectoryEncoded = $request['list_directory_b64'] ?? null;
            if (!is_string($listDirectoryEncoded)) {
                continue;
            }
            $listDirectory = base64_decode($listDirectoryEncoded, true);
            $this->assertIsString($listDirectory);
            $startedDirectories[] = $listDirectory;
        }
        $this->assertSame(
            [
                $this->siteDir,
                $canonicalRejectedTarget,
                $canonicalIndexedTarget,
            ],
            $startedDirectories
        );
        $this->assertFalse(is_dir($rejectedTarget));
        $this->assertContains(
            base64_encode( (string) realpath($indexedFile) ),
            $this->nextRemoteIndexPaths()
        );
        $state = $this->readState();
        $this->assertSame(
            'complete',
            $state['active_resumable_command']['completion_state']
        );
        $this->assertNull($state['index']['active_traversal']);
    }

    public function testDirectoryErrorDiscardsTheRealWireResponseAtTheSavedBoundary(): void
    {
        $largeDirectory = $this->siteDir . '/before-directory-error';
        mkdir($largeDirectory);
        for ($index = 0; $index < 150; ++$index) {
            file_put_contents(
                $largeDirectory . '/file-' . str_pad(
                    (string) $index,
                    3,
                    '0',
                    STR_PAD_LEFT
                ),
                'x'
            );
        }
        $this->writeControl([
            'file_index_batch_size' => 100,
            'interrupt_before_file_index_batch' => 2,
        ]);
        $client = $this->newFilesIndexClient();
        $this->assertFalse($this->fetchNextRemoteIndex($client));
        $durableIndex = file_get_contents($this->nextRemoteIndexPath());
        $durableState = $this->readState();
        $this->assertNotSame('', $durableIndex);

        $this->writeControl([
            'file_index_batch_size' => 100,
            'remove_extra_during_site_scan' => true,
        ]);
        try {
            $this->fetchNextRemoteIndex($client);
            $this->fail('Expected the real exporter directory error to be fatal.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Remote file indexing could not scan',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                $this->extraDir,
                $exception->getMessage()
            );
        }

        $stateAfterError = $this->readState();
        $this->assertSame(
            $durableIndex,
            file_get_contents($this->nextRemoteIndexPath())
        );
        $this->assertSame(
            $durableState['index']['next_remote_index_byte_offset'],
            $stateAfterError['index']['next_remote_index_byte_offset']
        );
        $this->assertNotNull(
            $stateAfterError['index']['active_traversal']
        );

        mkdir($this->extraDir);
        $recoveredFile = $this->extraDir . '/recovered.txt';
        file_put_contents($recoveredFile, 'recovered');
        $this->writeControl([]);
        $this->assertTrue($this->fetchNextRemoteIndex($client));
        $this->assertNull(
            $this->readState()['index']['active_traversal']
        );
        $this->assertContains(
            base64_encode( (string) realpath($recoveredFile) ),
            $this->nextRemoteIndexPaths()
        );
    }

    public function testConfirmedBatchSurvivesARealWireInterruption(): void
    {
        $largeDirectory = $this->siteDir . '/large';
        mkdir($largeDirectory);
        for ($index = 0; $index < 240; ++$index) {
            file_put_contents(
                $largeDirectory . '/file-' . str_pad(
                    (string) $index,
                    3,
                    '0',
                    STR_PAD_LEFT
                ),
                'x'
            );
        }
        $this->writeControl([
            'file_index_batch_size' => 100,
            'interrupt_before_file_index_batch' => 2,
        ]);
        $client = $this->newFilesIndexClient();

        $this->assertFalse($this->fetchNextRemoteIndex($client));
        $stateAfterInterruption = $this->readState();
        $confirmedPaths = $this->nextRemoteIndexPaths();
        $this->assertGreaterThanOrEqual(100, count($confirmedPaths));
        $this->assertLessThan(240, count($confirmedPaths));
        $this->assertNotNull($stateAfterInterruption['index']['cursor']);
        $this->assertSame(
            filesize($this->nextRemoteIndexPath()),
            $stateAfterInterruption['index']['next_remote_index_byte_offset']
        );

        $this->writeControl(['file_index_batch_size' => 100]);
        $this->assertTrue($this->fetchNextRemoteIndex($client));
        $completedPaths = $this->nextRemoteIndexPaths();
        $this->assertSame(
            count($completedPaths),
            count(array_unique($completedPaths)),
            'Resuming from the confirmed batch cursor must not append duplicate paths.'
        );
        $this->assertContains(
            base64_encode(
                (string) realpath($largeDirectory) . '/file-239'
            ),
            $completedPaths
        );
    }

    public function testFirstConfirmedBatchSurvivesItsStateSaveFailureAndReload(): void
    {
        $largeDirectory = $this->siteDir . '/save-failure';
        mkdir($largeDirectory);
        for ($index = 0; $index < 240; ++$index) {
            file_put_contents(
                $largeDirectory . '/file-' . str_pad(
                    (string) $index,
                    3,
                    '0',
                    STR_PAD_LEFT
                ),
                'x'
            );
        }
        $this->writeControl(['file_index_batch_size' => 100]);
        $this->newFilesIndexClient();
        $failingClient =
            $this->newLoadedClientThatFailsOnFirstConfirmedBatchSave();

        try {
            $this->fetchNextRemoteIndex($failingClient);
            $this->fail('Expected the first confirmed batch state save to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stop before saving the first confirmed remote index batch.',
                $exception->getMessage()
            );
        }

        $inMemoryIndexState = $failingClient->get_state()->index;
        $diskIndexState = $this->readState()['index'];
        $nextRemoteIndexPath = $this->nextRemoteIndexPath();
        clearstatcache(true, $nextRemoteIndexPath);
        $retainedBytes = filesize($nextRemoteIndexPath);
        $this->assertIsInt($retainedBytes);
        $this->assertGreaterThan(0, $retainedBytes);
        $this->assertSame(
            $retainedBytes,
            $inMemoryIndexState->next_remote_index_byte_offset
        );
        $this->assertNotNull($inMemoryIndexState->cursor);
        $this->assertNull($diskIndexState['cursor']);
        $this->assertSame(0, $diskIndexState['next_remote_index_byte_offset']);
        $this->assertNotNull($diskIndexState['active_traversal']);
        $this->assertNotEmpty($this->nextRemoteIndexPaths());

        $resumedClient = $this->newLoadedClient();
        $this->assertTrue($this->fetchNextRemoteIndex($resumedClient));

        $requests = $this->fileIndexRequests();
        $this->assertCount(2, $requests);
        $this->assertNull($requests[1]['cursor']);
        $completedPaths = $this->nextRemoteIndexPaths();
        $this->assertSame(
            count($completedPaths),
            count(array_unique($completedPaths)),
            'Reloading the older disk boundary must replace, not duplicate, the retained batch.'
        );
        $this->assertContains(
            base64_encode(
                (string) realpath($largeDirectory) . '/file-239'
            ),
            $completedPaths
        );
    }

    private function newFilesIndexClient(): \ImportClient
    {
        $client = $this->newClient();
        \write_current_pull_state($client, [
            'active_resumable_command' => [
                'command_name' => 'files-index',
                'completion_state' => 'in_progress',
                'current_stage' => 'index',
                'remote_cursor' => null,
            ],
            'preflight' => [
                'data' => [
                    'ok' => true,
                    'runtime' => ['document_root' => $this->siteDir],
                    'wp_detect' => [
                        'roots' => [
                            ['path' => $this->siteDir],
                            ['path' => $this->extraDir],
                        ],
                    ],
                ],
                'http_code' => 200,
            ],
            'remote_protocol_version' => PULL_PROTOCOL_VERSION,
            'follow_symlinks' => true,
            'include_caches' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
        ]);
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('follow_symlinks')->setValue($client, true);
        return $client;
    }

    private function newLoadedClient(): \ImportClient
    {
        return $this->loadClientState($this->newClient());
    }

    private function newLoadedClientThatFailsOnFirstConfirmedBatchSave(): \ImportClient
    {
        $client = new FailOnFirstConfirmedRemoteIndexBatchSaveClient(
            $this->remoteUrl,
            $this->stateDir,
            $this->filesystemRoot
        );
        $this->configureClientOutput($client);
        return $this->loadClientState($client);
    }

    private function loadClientState(\ImportClient $client): \ImportClient
    {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $state = $reflection->getMethod('load_state')->invoke($client);
        $reflection->getProperty('state')->setValue($client, $state);
        $reflection->getProperty('follow_symlinks')->setValue(
            $client,
            $state->follow_symlinks
        );
        $reflection->getProperty('include_caches')->setValue(
            $client,
            $state->include_caches
        );
        return $client;
    }

    private function newClient(): \ImportClient
    {
        $client = new \ImportClient(
            $this->remoteUrl,
            $this->stateDir,
            $this->filesystemRoot
        );
        $this->configureClientOutput($client);
        return $client;
    }

    private function configureClientOutput(\ImportClient $client): void
    {
        $output = fopen('php://temp', 'w+');
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('progress_fd')->setValue($client, $output);
        $progress = $reflection->getProperty('progress')->getValue($client);
        ( new \ReflectionClass($progress) )->getProperty('progress_fd')->setValue(
            $progress,
            $output
        );
    }

    private function fetchNextRemoteIndex(\ImportClient $client): bool
    {
        return ( new \ReflectionClass(\ImportClient::class) )
            ->getMethod('fetch_next_remote_index')
            ->invoke($client);
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->pullStateDirectory() . '/state.json'),
            true
        );
    }

    /** @return string[] Base64 paths in the next remote index. */
    private function nextRemoteIndexPaths(): array
    {
        $lines = file($this->nextRemoteIndexPath(), FILE_IGNORE_NEW_LINES);
        $paths = [];
        foreach ($lines ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && is_string($record['path'] ?? null)) {
                $paths[] = $record['path'];
            }
        }
        return $paths;
    }

    private function fileIndexRequests(): array
    {
        $lines = is_file($this->requestLog)
            ? file($this->requestLog, FILE_IGNORE_NEW_LINES)
            : [];
        $requests = [];
        foreach ($lines ?: [] as $line) {
            $request = json_decode($line, true);
            if ( ( $request['endpoint'] ?? null ) === 'file_index' ) {
                $requests[] = $request;
            }
        }
        return $requests;
    }

    private function writeControl(array $control): void
    {
        file_put_contents($this->controlFile, json_encode($control));
        clearstatcache(true, $this->controlFile);
    }

    private function writeDirectoryScanHook(): void
    {
        $hook = sprintf(
            <<<'PHP'
<?php
function test_hook_during_dir_scan($directory, &$entries) {
    $control = json_decode((string) file_get_contents(%s), true);
    if (
        !empty($control['remove_extra_during_site_scan'])
        && $directory === %s
        && is_dir(%s)
    ) {
        rmdir(%s);
    }
}

function test_hook_before_index_batch(&$batch_items, $directory_stack) {
    static $batch_count = 0;
    ++$batch_count;
    $control = json_decode((string) file_get_contents(%s), true);
    if (
        isset($control['interrupt_before_file_index_batch'])
        && $batch_count === (int) $control['interrupt_before_file_index_batch']
    ) {
        global $streaming_context;
        $streaming_context['gz']->finish();
        exit;
    }
}

PHP,
            var_export($this->controlFile, true),
            var_export( (string) realpath($this->siteDir), true),
            var_export($this->extraDir, true),
            var_export($this->extraDir, true),
            var_export($this->controlFile, true)
        );
        file_put_contents(
            $this->siteDir . '/wp-content/plugins/site-export/test-hooks.php',
            $hook
        );
    }

    private function startRealExporter(): string
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
$directories = $_GET['directory'] ?? [];
if (!is_array($directories)) {
    $directories = [$directories];
}
file_put_contents(
    %s,
    json_encode([
        'endpoint' => $_GET['endpoint'] ?? null,
        'cursor' => $_GET['cursor'] ?? null,
        'list_directory_b64' => isset($_GET['list_dir'])
            && is_string($_GET['list_dir'])
                ? base64_encode($_GET['list_dir'])
                : null,
        'requested_directories_b64' => array_map('base64_encode', $directories),
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND
);
$control = json_decode((string) file_get_contents(%s), true);
if (
    ($_GET['endpoint'] ?? null) === 'file_index'
    && isset($control['file_index_batch_size'])
) {
    $_GET['batch_size'] = (string) $control['file_index_batch_size'];
}
if (
    ($_GET['endpoint'] ?? null) === 'file_index'
    && isset($control['remove_and_reject_list_directory'])
    && is_string($_GET['list_dir'] ?? null)
    && $_GET['list_dir'] === $control['remove_and_reject_list_directory']
) {
    if (is_dir($_GET['list_dir'])) {
        rmdir($_GET['list_dir']);
        clearstatcache(true, $_GET['list_dir']);
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'The requested directory is no longer available.']);
    return;
}
require_once %s;
require_once %s;
Site_Export_HTTP_Server::serve(['default_directory' => %s]);
PHP,
                var_export($this->requestLog, true),
                var_export($this->controlFile, true),
                var_export($autoload, true),
                var_export($serverClass, true),
                var_export($this->siteDir, true)
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
        $this->fail('Real exporter did not start.');
    }

    private function pullStateDirectory(): string
    {
        return $this->stateDir . '/remotes/'
            . md5(rtrim($this->remoteUrl, '?&'))
            . '/pull';
    }

    private function nextRemoteIndexPath(): string
    {
        return $this->pullStateDirectory() . '/remote-index.next.jsonl';
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

final class FailOnFirstConfirmedRemoteIndexBatchSaveClient extends \ImportClient
{
    private $failed = false;

    public function save_state(): void
    {
        $indexState = $this->get_state()->index;
        if (
            !$this->failed
            && is_array($indexState->active_traversal)
            && $indexState->cursor !== null
            && $indexState->next_remote_index_byte_offset > 0
        ) {
            $this->failed = true;
            throw new \RuntimeException(
                'Stop before saving the first confirmed remote index batch.'
            );
        }
        parent::save_state();
    }
}
