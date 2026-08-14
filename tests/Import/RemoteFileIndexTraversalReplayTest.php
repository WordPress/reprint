<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the next line.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Boundary clients live with the lifecycle tests which use them.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The generated local router needs literal PHP values.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Exercises remote file-index traversal boundaries through the real HTTP endpoint. */
final class RemoteFileIndexTraversalReplayTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystemRoot;
    private $siteDir;
    private $extraDir;
    private $requestLog;
    private $remoteUrl;
    private $serverProcess;
    private $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remote-index-traversal-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystemRoot = $this->tempDir . '/local';
        $this->siteDir = $this->tempDir . '/remote-site';
        $this->extraDir = $this->tempDir . '/remote-extra';
        $this->requestLog = $this->tempDir . '/requests.jsonl';
        foreach (
            [
                $this->stateDir,
                $this->filesystemRoot,
                $this->siteDir,
                $this->extraDir,
            ] as $directory
        ) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($this->siteDir . '/index.php', '<?php');
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

    public function testCompletedInitialAndFollowedTraversalsAreNotRequestedAgain(): void
    {
        $firstTarget = $this->tempDir . '/followed-target';
        $secondTarget = $this->tempDir . '/followed target,%"';
        mkdir($firstTarget);
        mkdir($secondTarget);
        file_put_contents($firstTarget . '/first.txt', 'first');
        file_put_contents($secondTarget . '/second.txt', 'second');
        symlink($firstTarget, $this->siteDir . '/first-link');
        symlink($secondTarget, $firstTarget . '/second-link');

        $this->newFilesIndexClient(true);
        try {
            $this->runClient(
                $this->newLoadedClientThatFailsBeforeSort(),
                ['command' => 'files-index']
            );
            $this->fail('Expected the pre-sort state save to stop the command.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stop before the remote index sort.',
                $exception->getMessage()
            );
        }

        $stateBeforeResume = $this->readState();
        $this->assertSame(
            'index',
            $stateBeforeResume['active_resumable_command']['current_stage']
        );
        $this->assertNull($stateBeforeResume['index']['active_traversal']);
        $journalRecords = $this->readTraversalJournalRecords();
        $this->assertCount(3, $journalRecords);
        $this->assertSame(
            base64_encode( (string) realpath($secondTarget) ),
            $journalRecords[2]['indexed_roots_b64'][0]
        );
        $requestsBeforeResume = $this->fileIndexRequests();
        $this->assertCount(3, $requestsBeforeResume);
        $this->assertSame(
            [$requestsBeforeResume[1]['list_directory_b64']],
            $requestsBeforeResume[1]['requested_directories_b64']
        );
        $this->assertSame(
            [$requestsBeforeResume[2]['list_directory_b64']],
            $requestsBeforeResume[2]['requested_directories_b64']
        );

        $this->runClient($this->newLoadedClient(), ['command' => 'files-index']);

        $this->assertCount(
            count($requestsBeforeResume),
            $this->fileIndexRequests(),
            'A resumed stage must consume durable traversal completions without another HTTP request.'
        );
        $this->assertSame(
            'complete',
            $this->readState()['active_resumable_command']['completion_state']
        );
    }

    public function testEmptyInitialTraversalResumesFromZeroWithoutAnotherRequest(): void
    {
        $this->removeTree($this->siteDir);
        mkdir($this->siteDir);
        $this->newFilesIndexClient(true);

        try {
            $this->runClient(
                $this->newLoadedClientThatFailsBeforeSort(),
                ['command' => 'files-index']
            );
            $this->fail('Expected the pre-sort state save to stop the command.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stop before the remote index sort.',
                $exception->getMessage()
            );
        }

        $stateBeforeResume = $this->readState();
        $this->assertCount(1, $this->fileIndexRequests());
        $this->assertSame(0, filesize($this->nextRemoteIndexPath()));
        $this->assertCount(1, $this->readTraversalJournalRecords());
        $this->assertNull($stateBeforeResume['index']['active_traversal']);
        $this->assertSame(
            0,
            $stateBeforeResume['index']['next_remote_index_byte_offset']
        );
        $this->assertSame(
            0,
            $stateBeforeResume['index']
                ['discovery_next_remote_index_byte_offset']
        );
        $this->assertSame(
            'index',
            $stateBeforeResume['active_resumable_command']['current_stage']
        );

        $this->runClient($this->newLoadedClient(), ['command' => 'files-index']);

        $this->assertCount(1, $this->fileIndexRequests());
        $this->assertSame(
            'complete',
            $this->readState()['active_resumable_command']['completion_state']
        );
    }

    public function testFirstLinkAtByteZeroStartsOnlyItsFollowedTraversal(): void
    {
        $canonicalSiteDirectory = realpath($this->siteDir);
        $canonicalExtraDirectory = realpath($this->extraDir);
        $this->assertIsString($canonicalSiteDirectory);
        $this->assertIsString($canonicalExtraDirectory);
        $this->removeTree($this->siteDir);
        $this->siteDir = $canonicalSiteDirectory;
        $this->extraDir = $canonicalExtraDirectory;
        mkdir($this->siteDir);
        $outsideTarget = $this->tempDir . '/first-link-target';
        mkdir($outsideTarget);
        $outsideTarget = realpath($outsideTarget);
        $this->assertIsString($outsideTarget);
        file_put_contents($outsideTarget . '/outside.txt', 'outside');
        symlink($outsideTarget, $this->siteDir . '/first-link');
        $this->newFilesIndexClient(true);

        try {
            $this->runClient(
                $this->newLoadedClientThatFailsAfterDiscoveryCheckpoint(),
                ['command' => 'files-index']
            );
            $this->fail('Expected the discovery checkpoint to stop the command.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stop after the followed-target discovery checkpoint.',
                $exception->getMessage()
            );
        }

        $nextRemoteIndexHandle = fopen($this->nextRemoteIndexPath(), 'r');
        $this->assertIsResource($nextRemoteIndexHandle);
        $firstLine = fgets($nextRemoteIndexHandle);
        fclose($nextRemoteIndexHandle);
        $this->assertIsString($firstLine);
        $firstEntry = json_decode($firstLine, true);
        $this->assertIsArray($firstEntry);
        $this->assertSame('link', $firstEntry['type']);
        $this->assertSame(
            base64_encode( (string) realpath($outsideTarget) ),
            $firstEntry['target']
        );
        $this->assertSame(
            strlen($firstLine),
            $this->readState()['index']
                ['discovery_next_remote_index_byte_offset']
        );

        $requests = $this->fileIndexRequests();
        $this->assertCount(2, $requests);
        $canonicalTarget = base64_encode(
            (string) realpath($outsideTarget)
        );
        $this->assertSame($canonicalTarget, $requests[1]['list_directory_b64']);
        $this->assertSame(
            [$canonicalTarget],
            $requests[1]['requested_directories_b64']
        );

        $this->runClient($this->newLoadedClient(), ['command' => 'files-index']);

        $this->assertCount(2, $this->fileIndexRequests());
        $this->assertSame(
            'complete',
            $this->readState()['active_resumable_command']['completion_state']
        );
    }

    public function testFollowedTargetDiscoveryResumesFromItsDurableByteOffset(): void
    {
        $outsideTarget = $this->tempDir . '/late-outside-target';
        mkdir($outsideTarget);
        file_put_contents($outsideTarget . '/outside.txt', 'outside');
        for ($index = 0; $index < 1200; ++$index) {
            file_put_contents(
                $this->siteDir . '/file-' . str_pad(
                    (string) $index,
                    4,
                    '0',
                    STR_PAD_LEFT
                ) . '-' . str_repeat('x', 120),
                'x'
            );
        }
        symlink($outsideTarget, $this->siteDir . '/zz-outside-link');
        $this->newFilesIndexClient(true);

        try {
            $this->runClient(
                $this->newLoadedClientThatFailsAfterDiscoveryCheckpoint(),
                ['command' => 'files-index']
            );
            $this->fail('Expected the discovery checkpoint to stop the command.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stop after the followed-target discovery checkpoint.',
                $exception->getMessage()
            );
        }

        $savedDiscoveryByteOffset = $this->readState()['index']
            ['discovery_next_remote_index_byte_offset'];
        $this->assertGreaterThan(0, $savedDiscoveryByteOffset);
        $this->assertLessThan(
            filesize($this->nextRemoteIndexPath()),
            $savedDiscoveryByteOffset
        );
        $this->assertCount(1, $this->fileIndexRequests());

        $this->runClient($this->newLoadedClient(), ['command' => 'files-index']);
        $requests = $this->fileIndexRequests();
        $this->assertCount(2, $requests);
        $this->assertSame(
            [base64_encode( (string) realpath($outsideTarget) )],
            $requests[1]['requested_directories_b64']
        );
    }

    public function testProcessDeathDiscardsRowsPastTheDurableOffsetBeforeDiscoveryResume(): void
    {
        $followedTarget = $this->tempDir . '/followed-root';
        $staleTarget = $this->tempDir . '/stale-target';
        $freshTarget = $this->tempDir . '/fresh-target';
        foreach ([$followedTarget, $staleTarget, $freshTarget] as $directory) {
            mkdir($directory);
        }
        file_put_contents($staleTarget . '/stale.txt', 'stale');
        file_put_contents($freshTarget . '/fresh.txt', 'fresh');
        symlink($staleTarget, $followedTarget . '/old-link');
        symlink($followedTarget, $this->siteDir . '/followed-link');
        $canonicalFollowedTarget = realpath($followedTarget);
        $canonicalStaleTarget = realpath($staleTarget);
        $canonicalFreshTarget = realpath($freshTarget);
        $this->assertIsString($canonicalFollowedTarget);
        $this->assertIsString($canonicalStaleTarget);
        $this->assertIsString($canonicalFreshTarget);
        $this->newFilesIndexClient(true);

        $childScript = $this->tempDir . '/stop-during-followed-index.php';
        $checkpointFile = $this->tempDir . '/followed-batch-written';
        file_put_contents(
            $childScript,
            sprintf(
                <<<'PHP'
<?php
require_once %s;

final class StopDuringFollowedRemoteIndexBatchClient extends ImportClient
{
    private $followedTarget;
    private $checkpointFile;

    public function __construct(
        string $remoteUrl,
        string $stateDirectory,
        string $filesystemRoot,
        string $followedTarget,
        string $checkpointFile
    ) {
        parent::__construct($remoteUrl, $stateDirectory, $filesystemRoot);
        $this->followedTarget = $followedTarget;
        $this->checkpointFile = $checkpointFile;
    }

    public function save_state(): void
    {
        $indexState = $this->get_state()->index;
        $traversal = $indexState->active_traversal_request();
        if (
            is_array($traversal)
            && $traversal['list_directory'] === $this->followedTarget
            && $indexState->cursor !== null
            && $indexState->next_remote_index_byte_offset
                > $traversal['next_remote_index_start_byte_offset']
        ) {
            file_put_contents($this->checkpointFile, 'ready');
            while (true) {
                usleep(100000);
            }
        }
        parent::save_state();
    }
}

$client = new StopDuringFollowedRemoteIndexBatchClient(
    %s,
    %s,
    %s,
    %s,
    %s
);
$reflection = new ReflectionClass(ImportClient::class);
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
$output = fopen('php://temp', 'w+');
$reflection->getProperty('progress_fd')->setValue($client, $output);
$progress = $reflection->getProperty('progress')->getValue($client);
(new ReflectionClass($progress))->getProperty('progress_fd')->setValue(
    $progress,
    $output
);
$client->run(['command' => 'files-index']);
PHP,
                var_export(
                    realpath(
                        __DIR__
                        . '/../../packages/reprint-client/src/import.php'
                    ),
                    true
                ),
                var_export($this->remoteUrl, true),
                var_export($this->stateDir, true),
                var_export($this->filesystemRoot, true),
                var_export($canonicalFollowedTarget, true),
                var_export($checkpointFile, true)
            )
        );

        $childPipes = [];
        $childProcess = proc_open(
            [PHP_BINARY, $childScript],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $childPipes,
            $this->tempDir,
            getenv()
        );
        $this->assertIsResource($childProcess);
        fclose($childPipes[0]);
        $reachedCheckpoint = false;
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            if (is_file($checkpointFile)) {
                $reachedCheckpoint = true;
                break;
            }
            $childStatus = proc_get_status($childProcess);
            if (!$childStatus['running']) {
                break;
            }
            usleep(50000);
        }
        if (!$reachedCheckpoint) {
            $childStatus = proc_get_status($childProcess);
            if ($childStatus['running']) {
                proc_terminate($childProcess, 9);
            }
            $childOutput = stream_get_contents($childPipes[1]);
            $childError = stream_get_contents($childPipes[2]);
            fclose($childPipes[1]);
            fclose($childPipes[2]);
            proc_close($childProcess);
            $this->fail(
                "Child process did not reach the followed-index save boundary.\n"
                . $childOutput . "\n" . $childError
            );
        }
        $this->assertTrue(proc_terminate($childProcess, 9));
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (!proc_get_status($childProcess)['running']) {
                break;
            }
            usleep(50000);
        }
        fclose($childPipes[1]);
        fclose($childPipes[2]);
        proc_close($childProcess);

        $stateAfterProcessDeath = $this->readState();
        $durableNextIndexByteOffset = $stateAfterProcessDeath['index']
            ['next_remote_index_byte_offset'];
        clearstatcache(true, $this->nextRemoteIndexPath());
        $this->assertGreaterThan(
            $durableNextIndexByteOffset,
            filesize($this->nextRemoteIndexPath())
        );
        $nextIndexHandle = fopen($this->nextRemoteIndexPath(), 'r');
        $this->assertIsResource($nextIndexHandle);
        $this->assertSame(0, fseek(
            $nextIndexHandle,
            $durableNextIndexByteOffset
        ));
        $bytesPastDurableOffset = stream_get_contents($nextIndexHandle);
        fclose($nextIndexHandle);
        $this->assertIsString($bytesPastDurableOffset);
        $this->assertStringContainsString(
            base64_encode($canonicalFollowedTarget . '/old-link'),
            $bytesPastDurableOffset
        );

        unlink($followedTarget . '/old-link');
        symlink($freshTarget, $followedTarget . '/new-link');
        $this->runClient($this->newLoadedClient(), ['command' => 'files-index']);

        $requestedListDirectories = array_map(
            static function (array $request): string {
                return (string) base64_decode(
                    $request['list_directory_b64'],
                    true
                );
            },
            $this->fileIndexRequests()
        );
        $this->assertSame(
            [
                $this->siteDir,
                $canonicalFollowedTarget,
                $canonicalFollowedTarget,
                $canonicalFreshTarget,
            ],
            $requestedListDirectories
        );
        $this->assertNotContains(
            $canonicalStaleTarget,
            $requestedListDirectories,
            'Rows past the durable byte offset must not start a traversal after resume.'
        );
        $nextRemoteIndexPaths = $this->nextRemoteIndexPaths();
        $this->assertNotContains(
            base64_encode($canonicalFollowedTarget . '/old-link'),
            $nextRemoteIndexPaths
        );
        $this->assertContains(
            base64_encode($canonicalFollowedTarget . '/new-link'),
            $nextRemoteIndexPaths
        );
    }

    private function newFilesIndexClient(bool $followSymlinks): \ImportClient
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
            'follow_symlinks' => $followSymlinks,
            'include_caches' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
        ]);
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('follow_symlinks')->setValue(
            $client,
            $followSymlinks
        );
        return $client;
    }

    private function newLoadedClient(): \ImportClient
    {
        return $this->loadClientState($this->newClient());
    }

    private function newLoadedClientThatFailsBeforeSort(): \ImportClient
    {
        $client = new FailBeforeRemoteIndexSortClient(
            $this->remoteUrl,
            $this->stateDir,
            $this->filesystemRoot
        );
        $this->configureClientOutput($client);
        return $this->loadClientState($client);
    }

    private function newLoadedClientThatFailsAfterDiscoveryCheckpoint(): \ImportClient
    {
        $client = new FailAfterRemoteIndexDiscoveryCheckpointClient(
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

    private function runClient(\ImportClient $client, array $options): void
    {
        $client->run($options);
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->pullStateDirectory() . '/state.json'),
            true
        );
    }

    private function readTraversalJournalRecords(): array
    {
        $lines = file($this->traversalJournalPath(), FILE_IGNORE_NEW_LINES);
        return array_map(
            static function (string $line): array {
                return json_decode($line, true);
            },
            $lines ?: []
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
        'list_directory_b64' => isset($_GET['list_dir'])
            ? base64_encode((string) $_GET['list_dir'])
            : null,
        'requested_directories_b64' => array_map('base64_encode', $directories),
        'cursor' => $_GET['cursor'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND
);
require_once %s;
require_once %s;
Site_Export_HTTP_Server::serve(['default_directory' => %s]);
PHP,
                var_export($this->requestLog, true),
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

    private function traversalJournalPath(): string
    {
        return $this->pullStateDirectory()
            . '/remote-index-traversals.next.jsonl';
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

final class FailBeforeRemoteIndexSortClient extends \ImportClient
{
    private $failed = false;

    public function save_state(): void
    {
        $activeCommand = $this->get_state()->active_resumable_command;
        if (
            !$this->failed
            && $activeCommand->command_name === 'files-index'
            && $activeCommand->completion_state === 'in_progress'
            && $activeCommand->current_stage === 'sort'
        ) {
            $this->failed = true;
            throw new \RuntimeException('Stop before the remote index sort.');
        }
        parent::save_state();
    }
}

final class FailAfterRemoteIndexDiscoveryCheckpointClient extends \ImportClient
{
    private $failed = false;

    public function save_state(): void
    {
        parent::save_state();
        $indexState = $this->get_state()->index;
        if (
            !$this->failed
            && $this->get_state()->active_resumable_command->current_stage
                === 'index'
            && $indexState->active_traversal === null
            && $indexState->discovery_next_remote_index_byte_offset > 0
            && $indexState->discovery_next_remote_index_byte_offset
                < $indexState->next_remote_index_byte_offset
        ) {
            $this->failed = true;
            throw new \RuntimeException(
                'Stop after the followed-target discovery checkpoint.'
            );
        }
    }
}
