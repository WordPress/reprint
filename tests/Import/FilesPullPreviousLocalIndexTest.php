<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * The pull side of the pair's previous local index.
 *
 * A compatible file-only pull seeds the index before changing the local tree.
 * Each applied WAL batch advances only the paths the pull changed, keeping
 * unrelated local changes pending for files-diff and files-push. The WAL
 * remains as a marker until the pull completes. Incompatible pulls, pipelines
 * that keep changing the tree, and aborts with an unfinished WAL remove the
 * index instead. These tests run real pulls against a local index server and
 * read the result through the files-diff CLI.
 */
final class FilesPullPreviousLocalIndexTest extends TestCase
{
    private const REMOTE_CTIME = 41;
    private const PULLED_PATH = 'selected/written-by-files-pull.txt';
    private const PULLED_CONTENTS = 'contents delivered by the real file_fetch endpoint';

    private string $root;
    private string $stateDirectory;
    private string $rawFileRoot;
    private string $localTree;
    private string $targetUrl;
    private string $requestsLog;
    private ?string $invalidBytePathAtPreviousPull = null;

    /** @var resource|null */
    private $serverProcess = null;

    /** @var array<int,resource> */
    private array $serverPipes = [];

    /** @var array<string,string> */
    private array $initialFiles = [
        'deleted.txt' => 'delete me',
        'edited.txt' => 'old',
        'pending-directory/child.txt' => 'pending directory child',
        'shared/remote-deleted.txt' => 'remote deleted child',
        'swap' => 'file before the type change',
        'unchanged.txt' => 'same',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/files-diff-command-' . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->rawFileRoot = $this->stateDirectory . '/fs-root';
        $this->localTree = $this->rawFileRoot . '/var/www/html';
        $this->requestsLog = $this->root . '/requests.jsonl';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->localTree, 0700, true);

        $invalidBytePathAtPreviousPull = "delete-invalid-\xff.txt";
        if (@file_put_contents($this->localTree . '/' . $invalidBytePathAtPreviousPull, 'invalid path bytes') !== false) {
            $this->initialFiles[$invalidBytePathAtPreviousPull] = 'invalid path bytes';
            $this->invalidBytePathAtPreviousPull = $invalidBytePathAtPreviousPull;
        }
        foreach ($this->initialFiles as $path => $contents) {
            $directory = dirname($this->localTree . '/' . $path);
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($this->localTree . '/' . $path, $contents);
        }

        $this->writePullStateAndIndex();
        $this->targetUrl = $this->startIndexServer();
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
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testEligiblePullSeedsAndAdvancesThePreviousLocalIndex(): void
    {
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME,
            'require_previous_local_index_before_fetch' => true,
        ]);
        $this->completeEligiblePull();
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';

        $this->assertFileExists($previousLocalIndex);
        $this->assertSame(
            self::PULLED_CONTENTS,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH),
            'files-pull must record the local metadata written by the real file_fetch endpoint.'
        );

        $index = $this->readIndex($previousLocalIndex);
        $expectedPaths = array_merge(
            array_keys($this->initialFiles),
            ['pending-directory', 'selected', 'shared', self::PULLED_PATH]
        );
        usort($expectedPaths, static function (string $leftPath, string $rightPath): int {
            return strcmp(
                str_replace('/', "\0", $leftPath),
                str_replace('/', "\0", $rightPath)
            );
        });
        $this->assertSame($expectedPaths, array_keys($index));
        $editedStat = lstat($this->localTree . '/edited.txt');
        $this->assertIsArray($editedStat);
        $this->assertSame( (int) $editedStat['ctime'], $index['edited.txt']['ctime']);
        $this->assertSame(strlen($this->initialFiles['edited.txt']), $index['edited.txt']['size']);
        $this->assertNotSame(self::REMOTE_CTIME, $index['edited.txt']['ctime']);
    }

    public function testFetchStagesAValidLongDestinationBasename(): void
    {
        $basename = str_repeat('a', 240);
        $this->writeRemoteOverrides([
            'added_paths' => [$basename],
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $basename)
        );
        $stat = lstat($this->localTree . '/' . $basename);
        $this->assertIsArray($stat);
        $this->assertSame(
            0666 & ~umask(),
            (int) $stat['mode'] & 07777
        );
    }

    /**
     * @dataProvider existingFileModeProvider
     */
    public function testFetchPreservesAnExistingFileMode(int $mode): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath =
            $this->localTree . '/' . self::PULLED_PATH;
        chmod($localPath, $mode);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('replacement contents'),
        ]);

        $result = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        clearstatcache(true, $localPath);
        $stat = lstat($localPath);
        $this->assertIsArray($stat);
        $this->assertSame(
            $mode,
            (int) $stat['mode'] & 07777
        );
        $this->assertSame(
            'replacement contents',
            file_get_contents($localPath)
        );
    }

    /** @return array<string,array{int}> */
    public static function existingFileModeProvider(): array
    {
        return [
            'private file' => [0600],
            'executable file' => [0755],
        ];
    }

    public function testEmptyInitialPullPublishesAnEmptyPreviousLocalIndex(): void
    {
        unlink($this->stateDirectory . '/.import-index.jsonl');
        foreach (scandir($this->rawFileRoot) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($this->rawFileRoot . '/' . $entry);
            }
        }
        $this->writeRemoteOverrides([
            'empty_index' => true,
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertDirectoryExists($this->localTree);
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileExists($previousLocalIndex);
        $this->assertSame('', file_get_contents($previousLocalIndex));

        $diff = $this->runFilesDiff();

        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testInitialIndexOrdersAnAncestorBeforeAnEarlyByteChildName(): void
    {
        $this->writeRemoteOverrides([
            'added_paths' => ['punctuation/!child.txt'],
        ]);

        $this->completeEligiblePull();

        $index = $this->readIndex(
            $this->pushStateDirectory() . '/previous_local_index.jsonl'
        );
        $paths = array_keys($index);
        $this->assertLessThan(
            array_search('punctuation/!child.txt', $paths, true),
            array_search('punctuation', $paths, true)
        );
        $this->assertFalse($index['punctuation']['empty'] ?? true);
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testDefaultPullUpdatesChildrenOfAnUntouchedRemoteDirectory(): void
    {
        $directory = 'z-remote-nonempty-directory';
        $deletedChild = $directory . '/deleted.txt';
        $addedChild = $directory . '/added.txt';
        $this->writeRemoteOverrides([
            'added_directories' => [$directory],
            'added_paths' => [$deletedChild],
        ]);
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->writeRemoteOverrides([
            'added_directories' => [$directory],
            'added_paths' => [$addedChild],
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertDirectoryExists(
            $this->localTree . '/' . $directory
        );
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . $deletedChild
        );
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $addedChild)
        );
    }

    public function testStoredOldExporterProtocolStopsFilesPullBeforeNetworkWork(): void
    {
        $statePath = $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $state['remote_protocol_version'] = 1;
        file_put_contents(
            $statePath,
            json_encode(
                $state,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            )
        );
        $requestsBefore = $this->requestCount();

        $result = $this->runFilesPull();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString(
            'requires exporter protocol v2 or newer',
            $result['output']
        );
        $this->assertSame($requestsBefore, $this->requestCount());

        $abort = $this->runFilesPull(['--abort']);

        $this->assertSame(0, $abort['exit'], $abort['output']);
        $saved = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(1, $saved['remote_protocol_version'] ?? null);
    }

    public function testNewPreflightDoesNotReuseAnOlderProtocolVersion(): void
    {
        $compatible = $this->runCli([
            'preflight',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $this->assertSame(0, $compatible['exit'], $compatible['output']);
        $this->writeRemoteOverrides([
            'protocol_version' => null,
            'protocol_min_version' => null,
        ]);

        $preflight = $this->runCli([
            'preflight',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);

        $this->assertSame(0, $preflight['exit'], $preflight['output']);
        $this->assertStringNotContainsString(
            '"protocol_version"',
            $preflight['stdout']
        );
        $state = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNull($state['remote_protocol_version'] ?? null);

        $endpointCountsBefore = $this->requestEndpointCounts();
        $rejected = $this->runFilesPull();
        $endpointCountsAfter = $this->requestEndpointCounts();

        $this->assertSame(1, $rejected['exit'], $rejected['output']);
        $this->assertStringContainsString(
            'does not report a protocol version',
            $rejected['output']
        );
        $this->assertSame(
            $endpointCountsBefore['file_index'] ?? 0,
            $endpointCountsAfter['file_index'] ?? 0
        );
        $this->assertSame(
            $endpointCountsBefore['file_fetch'] ?? 0,
            $endpointCountsAfter['file_fetch'] ?? 0
        );
    }

    public function testCorruptedWALDoesNotAdvanceTheFetchCursorPastItsPathRecord(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $newPulledContents = 'remote change before a WAL write failure';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
            'make_wal_unwritable' => true,
        ]);

        $failed = $this->runFilesPull();

        $this->assertSame(1, $failed['exit'], $failed['output']);
        $this->assertSame(
            $newPulledContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $state = json_decode(
            (string) file_get_contents($this->stateDirectory . '/.import-state.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNull($state['fetch']['cursor'] ?? null);

        $walPath = $this->stateDirectory . '/.import-index-updates.wal';
        chmod($walPath, 0600);
        unlink($walPath);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
        ]);
        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testMissingBatchArtifactsPreserveAPendingInstalledFile(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $remoteContents =
            'installed contents awaiting fetch settlement';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
            'make_wal_unwritable' => true,
        ]);

        $failed = $this->runFilesPull();

        $this->assertSame(1, $failed['exit'], $failed['output']);
        $statePath =
            $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsInt(
            $state['fetch']['pending_file_install']['installed_ctime']
                ?? null
        );
        $batchPath = $state['fetch']['batch_file'] ?? null;
        $this->assertIsString($batchPath);
        if (str_starts_with($batchPath, 'base64:')) {
            $batchPath = base64_decode(
                substr($batchPath, strlen('base64:')),
                true
            );
            $this->assertIsString($batchPath);
        }
        $this->assertFileExists($batchPath);
        $this->assertFileExists(
            $batchPath . '.planned-local-state.jsonl'
        );
        unlink($batchPath);
        unlink($batchPath . '.planned-local-state.jsonl');

        $walPath =
            $this->stateDirectory . '/.import-index-updates.wal';
        chmod($walPath, 0600);
        unlink($walPath);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
        ]);

        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $localPath =
            $this->localTree . '/' . self::PULLED_PATH;
        $this->assertSame(
            $remoteContents,
            file_get_contents($localPath)
        );
        $saved = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNull(
            $saved['fetch']['pending_file_install'] ?? null
        );
        $this->assertSame(
            [],
            glob(dirname($localPath) . '/.reprint-*.part')
        );
    }

    public function testRemoteWinsReplaysAnInstalledFileChangedBeforeSettlement(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $remoteContents = 'remote install awaiting durable settlement';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
            'make_wal_unwritable' => true,
        ]);

        $failed = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(1, $failed['exit'], $failed['output']);
        $statePath = $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsInt(
            $state['fetch']['pending_file_install']['installed_ctime']
                ?? null
        );
        $walPath =
            $this->stateDirectory . '/.import-index-updates.wal';
        chmod($walPath, 0600);
        unlink($walPath);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        sleep(1);
        file_put_contents(
            $localPath,
            str_repeat('l', strlen($remoteContents))
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
        ]);

        $resumed = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertSame($remoteContents, file_get_contents($localPath));
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testResumeStopsAfterProcessDeathBeforeSavingInstalledCtime(): void
    {
        if (
            !function_exists('posix_kill')
            || !defined('SIGSTOP')
            || !defined('SIGKILL')
        ) {
            $this->markTestSkipped(
                'This test needs POSIX process signals.'
            );
        }
        $this->writeRemoteOverrides([
            'preflight_padding_bytes' => 32 * 1024 * 1024,
        ]);
        $preflight = $this->runCli([
            'preflight',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $this->assertSame(
            0,
            $preflight['exit'],
            $preflight['stderr']
        );
        unset($preflight);

        $remoteContents =
            str_repeat('remote install awaiting checkpoint ', 4096);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
            'pause_mid_file' => true,
        ]);
        [$partialProcess, $partialPipes] =
            $this->startCliProcess([
                'files-pull',
                $this->targetUrl,
                '--state-dir=' . $this->stateDirectory,
                '--fs-root=' . $this->rawFileRoot,
            ]);
        $readyPath =
            $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        $statePath =
            $this->stateDirectory . '/.import-state.json';
        $stagingInode = null;
        $deadline = microtime(true) + 10;
        while (
            $stagingInode === null
            && microtime(true) < $deadline
        ) {
            clearstatcache(true, $statePath . '.tmp');
            if (!is_file($statePath . '.tmp')) {
                $candidateState = json_decode(
                    (string) file_get_contents($statePath),
                    true
                );
                $candidateStagingInode =
                    is_array($candidateState)
                        ? (
                            $candidateState['fetch']['staged_file']
                                ['staging_ino']
                                ?? null
                        )
                        : null;
                if (is_int($candidateStagingInode)) {
                    $stagingInode = $candidateStagingInode;
                }
                unset($candidateState);
            }
            if ($stagingInode === null) {
                usleep(20000);
            }
        }
        $this->assertIsInt($stagingInode);
        proc_terminate($partialProcess, 9);
        fclose($partialPipes[1]);
        fclose($partialPipes[2]);
        proc_close($partialProcess);
        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );

        $this->assertFileDoesNotExist($statePath . '.tmp');
        $localPath =
            $this->localTree . '/' . self::PULLED_PATH;
        $this->assertFileDoesNotExist($localPath);

        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
        ]);
        [$installProcess, $installPipes] =
            $this->startCliProcess([
                'files-pull',
                $this->targetUrl,
                '--state-dir=' . $this->stateDirectory,
                '--fs-root=' . $this->rawFileRoot,
            ]);
        $installStatus = proc_get_status($installProcess);
        $this->assertIsArray($installStatus);
        $installProcessId =
            (int) ( $installStatus['pid'] ?? 0 );
        $this->assertGreaterThan(0, $installProcessId);
        $stoppedDuringCheckpoint = false;
        try {
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline) {
                clearstatcache(true, $localPath);
                clearstatcache(true, $statePath . '.tmp');
                $destinationStat = @lstat($localPath);
                if (
                    is_array($destinationStat)
                    && (int) $destinationStat['ino']
                        === $stagingInode
                    && is_file($statePath . '.tmp')
                ) {
                    $this->assertTrue(
                        posix_kill($installProcessId, SIGSTOP)
                    );
                    $stoppedDuringCheckpoint = true;
                    break;
                }
                $installStatus = proc_get_status($installProcess);
                if (
                    !is_array($installStatus)
                    || empty($installStatus['running'])
                ) {
                    break;
                }
            }
            $this->assertTrue(
                $stoppedDuringCheckpoint,
                'The install did not expose its atomic state write.'
            );
            usleep(20000);
            $stoppedState = json_decode(
                (string) file_get_contents($statePath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertNull(
                $stoppedState['fetch']['pending_file_install']
                    ['installed_ctime']
                    ?? null
            );
            unset($stoppedState);
            $this->assertSame(
                $remoteContents,
                file_get_contents($localPath)
            );
        } finally {
            if ($installProcessId > 0) {
                posix_kill($installProcessId, SIGKILL);
            } elseif (is_resource($installProcess)) {
                proc_terminate($installProcess, 9);
            }
            foreach ([1, 2] as $pipeIndex) {
                if (
                    isset($installPipes[$pipeIndex])
                    && is_resource($installPipes[$pipeIndex])
                ) {
                    fclose($installPipes[$pipeIndex]);
                }
            }
            if (is_resource($installProcess)) {
                proc_close($installProcess);
            }
        }

        $resumed = $this->runFilesPull();

        $this->assertSame(1, $resumed['exit'], $resumed['output']);
        $this->assertStringContainsString(
            'stopped before saving its installed ctime',
            $resumed['output']
        );
        $this->assertStringContainsString(
            'Abort this files-pull lifecycle, then rerun it.',
            $resumed['output']
        );
        $this->assertSame(
            $remoteContents,
            file_get_contents($localPath)
        );
        $savedState = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNull(
            $savedState['fetch']['pending_file_install']
                ['installed_ctime']
                ?? null
        );
        unset($savedState);

        $aborted = $this->runFilesPull(['--abort']);

        $this->assertSame(0, $aborted['exit'], $aborted['output']);
        $this->assertSame(
            $remoteContents,
            file_get_contents($localPath)
        );
    }

    public function testFilesPullReplaysARetainedWALIntoBothIndexesBeforeResuming(): void
    {
        $newPulledContents = 'remote change retained before the import-index merge';
        $this->corruptImportIndexOutputAfterFilesPullWritesWAL($newPulledContents);
        $walPath = $this->stateDirectory . '/.import-index-updates.wal';
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileExists($walPath);
        $this->assertFileExists($previousLocalIndex);

        $blockedDiff = $this->runFilesDiff();
        $this->assertSame(1, $blockedDiff['exit'], $blockedDiff['output']);
        $this->assertStringContainsString(
            'Finish or abort the interrupted files-pull',
            $blockedDiff['output']
        );
        $this->assertFileExists($walPath);

        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertFileDoesNotExist($walPath);
        $index = $this->readIndex($this->stateDirectory . '/.import-index.jsonl');
        $this->assertSame(
            self::REMOTE_CTIME + 1,
            $index['/var/www/html/' . self::PULLED_PATH]['ctime'] ?? null
        );
        $this->assertSame(
            strlen($newPulledContents),
            $index['/var/www/html/' . self::PULLED_PATH]['size'] ?? null
        );
        $localStat = lstat($this->localTree . '/' . self::PULLED_PATH);
        $this->assertIsArray($localStat);
        $previousIndex = $this->readIndex($previousLocalIndex);
        $this->assertSame(
            (int) $localStat['ctime'],
            $previousIndex[self::PULLED_PATH]['ctime'] ?? null
        );
        $this->assertSame(
            strlen($newPulledContents),
            $previousIndex[self::PULLED_PATH]['size'] ?? null
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    public function testIncompatibleFilesPullDoesNotStartMaintainingAfterResume(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $addedPath = 'added-before-incompatible-resume.txt';
        $this->writeRemoteOverrides([
            'added_paths' => [$addedPath],
        ]);
        $blockedIndexOutput = $this->stateDirectory . '/.import-index.jsonl.new';
        file_put_contents($blockedIndexOutput, '');
        chmod($blockedIndexOutput, 0400);
        if (is_writable($blockedIndexOutput)) {
            $this->markTestSkipped('This runner cannot make the importer-index output unwritable.');
        }
        try {
            $failed = $this->runFilesPull([
                '--on-fs-root-nonempty=preserve-local',
            ]);
        } finally {
            chmod($blockedIndexOutput, 0600);
            unlink($blockedIndexOutput);
        }

        $this->assertSame(1, $failed['exit'], $failed['output']);
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $addedPath)
        );
        $this->assertFileExists(
            $this->stateDirectory . '/.import-index-updates.wal'
        );
        $previousLocalIndex =
            $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileDoesNotExist($previousLocalIndex);

        $resumed = $this->runFilesPull([
            '--on-fs-root-nonempty=error',
        ]);

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertFileDoesNotExist(
            $this->stateDirectory . '/.import-index-updates.wal'
        );
        $this->assertFileDoesNotExist($previousLocalIndex);
        $diff = $this->runFilesDiff();
        $this->assertSame(1, $diff['exit'], $diff['output']);
        $this->assertStringContainsString(
            'fresh completed files-pull',
            $diff['output']
        );
    }

    public function testInterruptedPullKeepsTheWALMarkerUntilResume(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $newPulledContents = str_repeat('interrupted remote contents ', 4096);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
            'pause_mid_file' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $readyPath = $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $walPath = $this->stateDirectory . '/.import-index-updates.wal';
        $this->assertFileExists($walPath);
        $diff = $this->runFilesDiff();
        $this->assertSame(1, $diff['exit'], $diff['output']);
        $this->assertStringContainsString(
            'Finish or abort the interrupted files-pull',
            $diff['output']
        );

        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
        ]);
        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertFileDoesNotExist($walPath);
        $this->assertSame(
            $newPulledContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
    }

    /** @dataProvider savedRecursiveDeletionContinuationProvider */
    public function testSavedRecursiveDeletionSurvivesProcessDeath(
        string $continuation
    ): void {
        if (
            !function_exists('posix_kill')
            || !defined('SIGSTOP')
            || !defined('SIGKILL')
        ) {
            $this->markTestSkipped(
                'This test needs POSIX process signals.'
            );
        }
        $removedRoot = 'interrupted-delete';
        $removedPaths = [];
        for ($pathIndex = 0; $pathIndex < 2000; $pathIndex++) {
            $removedPaths[] = sprintf(
                '%s/%05d.txt',
                $removedRoot,
                $pathIndex
            );
        }
        $this->writeRemoteOverrides([
            'added_directories' => [$removedRoot],
            'added_paths' => $removedPaths,
        ]);
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        sleep(1);
        $this->writeRemoteOverrides([]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=remote-wins',
        ]);
        $processStatus = proc_get_status($process);
        $this->assertIsArray($processStatus);
        $processId = (int) ( $processStatus['pid'] ?? 0 );
        $this->assertGreaterThan(0, $processId);
        $pendingAction = null;
        $earlyPath =
            $this->localTree . '/' . $removedPaths[0];
        $latePath =
            $this->localTree
            . '/'
            . $removedPaths[count($removedPaths) - 1];
        $stopped = false;
        try {
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                $state = json_decode(
                    (string) @file_get_contents(
                        $this->stateDirectory . '/.import-state.json'
                    ),
                    true
                );
                $candidate =
                    is_array($state)
                        ? ( $state['diff']['pending_local_action'] ?? null )
                        : null;
                if (
                    is_array($candidate)
                    && ( $candidate['path_b64'] ?? null )
                        === base64_encode(
                            '/var/www/html/' . $removedRoot
                        )
                    && !file_exists($earlyPath)
                    && file_exists($latePath)
                ) {
                    $pendingAction = $candidate;
                    break;
                }
                usleep(1000);
            }
            $this->assertIsArray(
                $pendingAction,
                'The recursive deletion did not expose its durable action.'
            );
            $this->assertTrue(posix_kill($processId, SIGSTOP));
            $stopped = true;
            usleep(20000);
            $stoppedState = json_decode(
                (string) file_get_contents(
                    $this->stateDirectory . '/.import-state.json'
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertSame(
                $pendingAction,
                $stoppedState['diff']['pending_local_action'] ?? null
            );
        } finally {
            if ($processId > 0) {
                posix_kill($processId, SIGKILL);
            } elseif (is_resource($process)) {
                proc_terminate($process, 9);
            }
            foreach ([1, 2] as $pipeIndex) {
                if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                    fclose($pipes[$pipeIndex]);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
        $this->assertTrue($stopped);

        if ($continuation === 'abort') {
            $aborted = $this->runFilesPull(['--abort']);

            $this->assertSame(
                0,
                $aborted['exit'],
                $aborted['output']
            );
            $this->assertDirectoryExists(
                $this->localTree . '/' . $removedRoot
            );
            $this->assertFileDoesNotExist($earlyPath);
            $this->assertFileExists($latePath);
            $this->assertFileDoesNotExist(
                $this->pushStateDirectory()
                    . '/previous_local_index.jsonl'
            );
            return;
        }

        $resumed = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertDirectoryDoesNotExist(
            $this->localTree . '/' . $removedRoot
        );
        $state = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNull(
            $state['diff']['pending_local_action'] ?? null
        );
        $index = $this->readIndex(
            $this->pushStateDirectory() . '/previous_local_index.jsonl'
        );
        $this->assertArrayNotHasKey($removedRoot, $index);
        $this->assertArrayNotHasKey($removedPaths[0], $index);
        $this->assertArrayNotHasKey(
            $removedPaths[count($removedPaths) - 1],
            $index
        );
    }

    /** @return iterable<string,array{string}> */
    public static function savedRecursiveDeletionContinuationProvider(): iterable
    {
        yield 'resume' => ['resume'];
        yield 'abort' => ['abort'];
    }

    /** @dataProvider pendingDeletionAbortStateProvider */
    public function testAbortReconcilesAFailedSavedDeletion(
        string $localStateBeforeAbort
    ): void {
        $removedRoot = 'failed-pending-delete';
        $removedChild = $removedRoot . '/child.txt';
        $this->writeRemoteOverrides([
            'added_directories' => [$removedRoot],
            'added_paths' => [$removedChild],
        ]);
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localRoot = $this->localTree . '/' . $removedRoot;
        $previousLocalIndex =
            $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileExists($previousLocalIndex);
        chmod($localRoot, 0500);
        clearstatcache(true, $localRoot);
        if (is_writable($localRoot)) {
            chmod($localRoot, 0700);
            $this->markTestSkipped(
                'This runner cannot make the local directory unwritable.'
            );
        }
        try {
            $this->writeRemoteOverrides([]);
            $failed = $this->runFilesPull([
                '--on-conflict=remote-wins',
            ]);

            $this->assertSame(1, $failed['exit'], $failed['output']);
            $state = json_decode(
                (string) file_get_contents(
                    $this->stateDirectory . '/.import-state.json'
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertSame(
                'delete_path',
                $state['diff']['pending_local_action']['kind'] ?? null
            );
            $this->assertFileExists(
                $this->localTree . '/' . $removedChild
            );

            if ($localStateBeforeAbort === 'absent') {
                chmod($localRoot, 0700);
                $this->removeTree($localRoot);
            }
            $aborted = $this->runFilesPull(['--abort']);

            $this->assertSame(
                0,
                $aborted['exit'],
                $aborted['output']
            );
            if ($localStateBeforeAbort === 'unchanged') {
                $this->assertFileExists($previousLocalIndex);
                $this->assertFileExists(
                    $this->localTree . '/' . $removedChild
                );
            } else {
                $this->assertFileDoesNotExist($previousLocalIndex);
                $index = $this->readIndex(
                    $this->stateDirectory . '/.import-index.jsonl'
                );
                $this->assertArrayNotHasKey(
                    '/var/www/html/' . $removedRoot,
                    $index
                );
            }
            $state = json_decode(
                (string) file_get_contents(
                    $this->stateDirectory . '/.import-state.json'
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertNull(
                $state['diff']['pending_local_action'] ?? null
            );
        } finally {
            if (is_dir($localRoot)) {
                chmod($localRoot, 0700);
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function pendingDeletionAbortStateProvider(): iterable
    {
        yield 'accepted local state remains' => ['unchanged'];
        yield 'path is already absent' => ['absent'];
    }

    public function testInterruptedFetchRejectsASameSizeLateLocalEdit(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        $originalContents = file_get_contents($localPath);
        $this->assertIsString($originalContents);
        $remoteContents = str_repeat('r', strlen($originalContents));
        $localContents = str_repeat('l', strlen($originalContents));
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
            'pause_mid_file' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $readyPath = $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );
        $interruptedState = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $batchPath =
            $interruptedState['fetch']['batch_file'] ?? null;
        $this->assertIsString($batchPath);
        if (str_starts_with($batchPath, 'base64:')) {
            $batchPath = base64_decode(
                substr($batchPath, strlen('base64:')),
                true
            );
            $this->assertIsString($batchPath);
        }
        @unlink(
            $batchPath . '.planned-local-state.jsonl.enabled'
        );

        $originalCtime = filectime($localPath);
        $this->assertIsInt($originalCtime);
        sleep(1);
        file_put_contents($localPath, $localContents);
        clearstatcache(true, $localPath);
        $this->assertNotSame($originalCtime, filectime($localPath));
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
        ]);

        $resumed = $this->runFilesPull();

        $this->assertSame(1, $resumed['exit'], $resumed['output']);
        $this->assertSame($localContents, file_get_contents($localPath));
        $this->assertStringContainsString(
            'changed locally after files-pull planned the remote change',
            $resumed['output']
        );
        $this->assertStringContainsString(
            'Abort this files-pull lifecycle, then rerun',
            $resumed['output']
        );
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
    }

    public function testMissingBatchArtifactsPreservePartialRemoteWinsStaging(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $remoteContents =
            str_repeat('partial staged contents survive ', 4096);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode($remoteContents),
            'pause_mid_file' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=remote-wins',
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );

        $statePath =
            $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $stagingPath = base64_decode(
            $state['fetch']['staged_file']['staging_path_b64']
                ?? '',
            true
        );
        $this->assertIsString($stagingPath);
        $stagingStat = lstat($stagingPath);
        $this->assertIsArray($stagingStat);
        $batchPath = $state['fetch']['batch_file'] ?? null;
        $this->assertIsString($batchPath);
        if (str_starts_with($batchPath, 'base64:')) {
            $batchPath = base64_decode(
                substr($batchPath, strlen('base64:')),
                true
            );
            $this->assertIsString($batchPath);
        }
        $this->assertFileExists($batchPath);
        $this->assertFileExists(
            $batchPath . '.planned-local-state.jsonl'
        );
        unlink($batchPath);
        unlink($batchPath . '.planned-local-state.jsonl');

        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode($remoteContents),
        ]);
        $resumed = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $localPath =
            $this->localTree . '/' . self::PULLED_PATH;
        $this->assertSame(
            $remoteContents,
            file_get_contents($localPath)
        );
        $installedStat = lstat($localPath);
        $this->assertIsArray($installedStat);
        $this->assertSame(
            (int) $stagingStat['ino'],
            (int) $installedStat['ino']
        );
        $this->assertFileDoesNotExist($stagingPath);
        $this->assertSame(
            [],
            glob(dirname($localPath) . '/.reprint-*.part')
        );
    }

    public function testAbortDiscardsPartialRemoteWinsStagingBeforeReset(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        $localContents = file_get_contents($localPath);
        $this->assertIsString($localContents);
        $remoteContents =
            str_repeat('partial remote-wins contents ', 4096);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode($remoteContents),
            'pause_mid_file' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=remote-wins',
        ]);
        $readyPath = $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );
        $statePath = $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $stagingPath = base64_decode(
            $state['fetch']['staged_file']['staging_path_b64']
                ?? '',
            true
        );
        $this->assertIsString($stagingPath);
        $this->assertFileExists($stagingPath);
        $batchPath = $state['fetch']['batch_file'] ?? null;
        $this->assertIsString($batchPath);
        if (str_starts_with($batchPath, 'base64:')) {
            $batchPath = base64_decode(
                substr($batchPath, strlen('base64:')),
                true
            );
            $this->assertIsString($batchPath);
        }
        $this->assertFileExists($batchPath);
        $this->assertFileExists(
            $batchPath . '.planned-local-state.jsonl'
        );
        $previousLocalIndex =
            $this->pushStateDirectory()
            . '/previous_local_index.jsonl';
        $this->assertFileExists($previousLocalIndex);
        $this->assertSame($localContents, file_get_contents($localPath));

        $cleared = $this->runFilesPull(['--abort']);

        $this->assertSame(0, $cleared['exit'], $cleared['output']);
        $this->assertSame($localContents, file_get_contents($localPath));
        $this->assertFileDoesNotExist($stagingPath);
        $this->assertFileDoesNotExist($batchPath);
        $this->assertFileDoesNotExist(
            $batchPath . '.planned-local-state.jsonl'
        );
        $this->assertFileDoesNotExist($previousLocalIndex);
        $saved = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(2, $saved['remote_protocol_version'] ?? null);
    }

    /** @dataProvider terminalFileErrorProvider */
    public function testTerminalFileErrorPreservesTheDestinationAndContinues(
        string $fileErrorMode
    ): void {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        $localContents = file_get_contents($localPath);
        $this->assertIsString($localContents);
        $followingPath = 'z-after-file-error.txt';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('remote contents which fail'),
            'added_paths' => [$followingPath],
            'file_error_mode' => $fileErrorMode,
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($localPath)
        );
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $followingPath)
        );
        $this->assertStringContainsString(
            'Remote error:',
            $result['output']
        );
    }

    /** @return iterable<string,array{string}> */
    public static function terminalFileErrorProvider(): iterable
    {
        yield 'before the first file part' => ['before-first-part'];
        yield 'after a non-final file part' => [
            'after-non-final-part',
        ];
    }

    public function testRemoteWinsTerminalFileErrorKeepsTheCompleteDestination(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        $localContents = file_get_contents($localPath);
        $this->assertIsString($localContents);
        $followingPath = 'z-after-remote-wins-file-error.txt';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('remote contents which fail'),
            'added_paths' => [$followingPath],
            'file_error_mode' => 'after-non-final-part',
        ]);

        $result = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($localContents, file_get_contents($localPath));
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $followingPath)
        );
    }

    public function testInitialHighLevelPullKeepsCompleteDestinationAfterTerminalFileError(): void
    {
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        mkdir(dirname($localPath), 0700, true);
        $localContents = 'complete local contents before pull';
        file_put_contents($localPath, $localContents);
        $followingPath = 'z-after-high-level-file-error.txt';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('remote contents which fail'),
            'added_paths' => [$followingPath],
            'file_error_mode' => 'after-non-final-part',
        ]);

        $result = $this->runCli([
            'pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--runtime=none',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($localPath)
        );
        $this->assertSame(
            'added remote contents',
            file_get_contents(
                $this->localTree . '/' . $followingPath
            )
        );
        $this->assertStringContainsString(
            'Remote error:',
            $result['output']
        );
    }

    public function testCompletedFileFetchMustSettleEveryRequestedPath(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        $localContents = file_get_contents($localPath);
        $this->assertIsString($localContents);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('remote path omitted from the response'),
            'complete_without_path_parts' => true,
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString(
            'completed before every requested path was settled',
            $result['output']
        );
        $this->assertSame($localContents, file_get_contents($localPath));
        $state = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNotNull($state['fetch']['batch_file'] ?? null);
    }

    public function testFileFetchCannotOmitAnEarlierRequestedPath(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $laterPath = 'z-returned-after-omission.txt';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('omitted changed remote path'),
            'added_paths' => [$laterPath],
            'omit_pulled_path_part' => true,
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString(
            'response path does not match its planned-local-state record',
            $result['output']
        );
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . $laterPath
        );
    }

    public function testOurWinsSettlesAnErrorInTheNextResponseForARejectedFile(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localPath = $this->localTree . '/' . self::PULLED_PATH;
        $localContents = 'late local edit retained across responses';
        $followingPath = 'z-after-separated-file-error.txt';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('remote contents which fail later'),
            'added_paths' => [$followingPath],
            'file_error_mode' =>
                'after-non-final-part-response-boundary',
            'pause_before_file_fetch' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=our-wins',
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.before-fetch-ready';
        $releasePath =
            $this->root . '/remote-overrides.json.before-fetch-release';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        file_put_contents($localPath, $localContents);
        file_put_contents($releasePath, '');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        $this->assertSame(2, $exit, $stdout . $stderr);

        $resumed = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertSame($localContents, file_get_contents($localPath));
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $followingPath)
        );
    }

    public function testObjectFetchPathPreservesInvalidFilenameBytes(): void
    {
        if ($this->invalidBytePathAtPreviousPull === null) {
            $this->markTestSkipped(
                'This filesystem does not accept invalid UTF-8 filename bytes.'
            );
        }
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $path = "z-added-invalid-\xff.txt";
        $this->writeRemoteOverrides([
            'added_paths_b64' => [base64_encode($path)],
        ]);

        $result = $this->runFilesPull();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $path)
        );
    }

    public function testUnrelatedCommandShutdownDoesNotConsumeTheFilesPullWAL(): void
    {
        $walPath = $this->stateDirectory . '/.import-index-updates.wal';
        $walContents = json_encode([
            'op' => 'F',
            'path' => base64_encode('/var/www/html/retained.txt'),
            'ctime' => 17,
            'size' => 8,
            'type' => 'file',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($walPath, $walContents);
        $script = $this->root . '/unrelated-command-shutdown.php';
        file_put_contents(
            $script,
            "<?php\n"
            . 'require ' . var_export(
                realpath(__DIR__ . '/../../packages/reprint-importer/src/import.php'),
                true
            ) . ";\n"
            . '$client = new ImportClient('
            . var_export($this->targetUrl, true) . ', '
            . var_export($this->stateDirectory, true) . ', '
            . var_export($this->rawFileRoot, true) . ", 'db-pull');\n"
            . "\$client->handle_shutdown(SIGTERM);\n"
        );

        $process = proc_open(
            [PHP_BINARY, $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame($walContents, file_get_contents($walPath));
    }

    public function testAbortReplaysARetainedWALThenInvalidatesTheBaseline(): void
    {
        $newPulledContents = 'remote change retained before abort';
        $this->corruptImportIndexOutputAfterFilesPullWritesWAL($newPulledContents);
        $walPath = $this->stateDirectory . '/.import-index-updates.wal';
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileExists($walPath);
        $this->assertFileExists($previousLocalIndex);

        $abort = $this->runFilesPull(['--abort']);

        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->assertFileDoesNotExist($walPath);
        $index = $this->readIndex($this->stateDirectory . '/.import-index.jsonl');
        $this->assertSame(
            self::REMOTE_CTIME + 1,
            $index['/var/www/html/' . self::PULLED_PATH]['ctime'] ?? null
        );
        $this->assertSame(
            strlen($newPulledContents),
            $index['/var/www/html/' . self::PULLED_PATH]['size'] ?? null
        );
        $this->assertFileDoesNotExist($previousLocalIndex);
        $diff = $this->runFilesDiff();
        $this->assertSame(1, $diff['exit'], $diff['output']);
        $this->assertStringContainsString(
            'files-diff requires a fresh completed files-pull',
            $diff['output']
        );
    }

    public function testPullFilesAbortWithABareUrlReplaysItsWAL(): void
    {
        $this->corruptImportIndexOutputAfterFilesPullWritesWAL(
            'remote change retained before a bare-URL abort'
        );
        $bareTargetUrl = substr($this->targetUrl, 0, -strlen('?site-export-api'));

        $abort = $this->runCli([
            'pull-files',
            $bareTargetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--abort',
        ]);

        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->assertFileDoesNotExist(
            $this->stateDirectory . '/.import-index-updates.wal'
        );
    }

    public function testEligiblePullDoesNotAdmitAnUntrackedUnsupportedPath(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('This PHP build does not provide posix_mkfifo().');
        }
        $fifoPath = $this->localTree . '/local.pipe';
        if (!@posix_mkfifo($fifoPath, 0600)) {
            $this->markTestSkipped('This filesystem does not support a local FIFO test path.');
        }

        try {
            $result = $this->runFilesPull();

            $this->assertSame(0, $result['exit'], $result['output']);
            $this->assertFileExists($this->pushStateDirectory() . '/previous_local_index.jsonl');
            $this->assertArrayNotHasKey(
                'local.pipe',
                $this->readIndex($this->pushStateDirectory() . '/previous_local_index.jsonl')
            );
            $this->assertDirectoryDoesNotExist(
                $this->pushStateDirectory() . '/files-diff-plan'
            );
        } finally {
            @unlink($fifoPath);
        }
    }

    public function testPullFilesMaintainsThePreviousLocalIndex(): void
    {
        $result = $this->runCli([
            'pull-files',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(self::PULLED_CONTENTS, file_get_contents($this->localTree . '/' . self::PULLED_PATH));
        $this->assertFileExists(
            $this->pushStateDirectory() . '/previous_local_index.jsonl'
        );
    }

    public function testPullFilesWithABareSiteUrlEstablishesTheNormalizedFilesDiffPair(): void
    {
        $bareTargetUrl = substr($this->targetUrl, 0, -strlen('?site-export-api'));

        $pullResult = $this->runCli([
            'pull-files',
            $bareTargetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $diffResult = $this->runFilesDiff();

        $this->assertSame(0, $pullResult['exit'], $pullResult['output']);
        $this->assertSame(0, $diffResult['exit'], $diffResult['output']);
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ], $this->filesDiffRecords($diffResult['stdout'])[0] ?? null);
    }

    public function testPullInvalidatesAndDoesNotRecreateThePreviousLocalIndex(): void
    {
        $this->completeEligiblePull();
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileExists($previousLocalIndex);

        $arguments = [
            'pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--runtime=none',
        ];

        $result = $this->runCli($arguments);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(self::PULLED_CONTENTS, file_get_contents($this->localTree . '/' . self::PULLED_PATH));
        $this->assertFileDoesNotExist($previousLocalIndex);
    }

    /** @dataProvider incompatibleFilesPullOptionsProvider
     *  @param list<string> $incompatibleOptions
     */
    public function testIncompatibleFilesPullDoesNotPublishAPreviousLocalIndex(array $incompatibleOptions): void
    {
        $result = $this->runFilesPull($incompatibleOptions);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist($this->pushStateDirectory() . '/previous_local_index.jsonl');
    }

    public function testPartialPullDoesNotSeedAMissingPreviousLocalIndex(): void
    {
        file_put_contents(
            $this->localTree . '/edited.txt',
            'an edit outside the selected pull'
        );

        $result = $this->runFilesPull([
            '--only=/var/www/html/selected',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            'an edit outside the selected pull',
            file_get_contents($this->localTree . '/edited.txt')
        );
        $this->assertFileDoesNotExist(
            $this->pushStateDirectory() . '/previous_local_index.jsonl'
        );
    }

    /** @dataProvider incompatibleFilesPullOptionsProvider
     *  @param list<string> $incompatibleOptions
     */
    public function testIncompatibleFilesPullInvalidatesThePreviousLocalIndex(array $incompatibleOptions): void
    {
        $this->completeEligiblePull();
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->assertFileExists($previousLocalIndex);

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->assertFileExists($previousLocalIndex);
        $result = $this->runFilesPull($incompatibleOptions);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist($previousLocalIndex);
    }

    public function testIncompatibleFilesPullInvalidatesAfterTheLocalTreeWasDeleted(): void
    {
        $this->completeEligiblePull();
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $this->removeTree($this->localTree);
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->assertFileExists($previousLocalIndex);

        $result = $this->runFilesPull([
            '--filter=essential-files',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist($previousLocalIndex);
    }

    /** @return iterable<string,array{list<string>}> */
    public static function incompatibleFilesPullOptionsProvider(): iterable
    {
        yield 'filtered' => [['--filter=essential-files']];
        yield 'preserve local' => [['--on-fs-root-nonempty=preserve-local']];
    }

    public function testRemappedFilesPullDoesNotPublishAPreviousLocalIndex(): void
    {
        $this->removeTree($this->rawFileRoot);
        mkdir($this->rawFileRoot, 0700, true);
        unlink($this->stateDirectory . '/.import-index.jsonl');

        $result = $this->runFilesPull([
            '--remap',
            '/var/www/html',
            ':fs-root:/var/www/html',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist($this->pushStateDirectory() . '/previous_local_index.jsonl');
    }

    public function testFilesDiffIsLocalAndReportsAnEmptyDiffImmediatelyAfterPull(): void
    {
        $this->completeEligiblePull();
        $requestCountBeforeDiff = $this->requestCount();

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame($requestCountBeforeDiff, $this->requestCount());
        $expectedRecord = [
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ];
        $this->assertSame($this->encodeJsonLine($expectedRecord), $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testFilesDiffGuidanceNamesTheCompatiblePullRequirements(): void
    {
        $result = $this->runFilesDiff();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertCanonicalSingleJsonLine($result['stderr']);
        $this->assertStringContainsString('completed files-pull or pull-files', $result['output']);
        $this->assertStringContainsString('--filter=none', $result['output']);
        $this->assertStringContainsString('completed files-push', $result['output']);
        $this->assertStringContainsString(
            'same target URL, state directory, and local tree',
            $result['output']
        );
    }

    public function testCompatibleDeltaPullKeepsUnpulledLocalChangesPending(): void
    {
        $this->completeEligiblePull();

        // Local changes the delta pull must not absorb into the index.
        file_put_contents($this->localTree . '/edited.txt', 'local edit with a longer size');
        unlink($this->localTree . '/deleted.txt');
        // Remote changes the delta pull must absorb: one changed file and one
        // remote deletion applied to the local tree.
        $newPulledContents = 'remote change delivered by the second pull';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
            'removed_paths' => ['unchanged.txt'],
        ]);

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $delta = $this->runFilesPull();
        $this->assertSame(0, $delta['exit'], $delta['output']);
        $this->assertSame('local edit with a longer size', file_get_contents($this->localTree . '/edited.txt'));
        $this->assertSame($newPulledContents, file_get_contents($this->localTree . '/' . self::PULLED_PATH));
        $this->assertFileDoesNotExist($this->localTree . '/unchanged.txt');

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('', $result['stderr']);
        $records = $this->filesDiffRecords($result['stdout']);
        $finalRecord = array_pop($records);
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 1,
            'local_paths_to_delete' => 1,
        ], $finalRecord);
        $this->assertSame([
            $this->expectedPushRecord('edited.txt', 'file'),
            [
                'command' => 'files-diff',
                'action' => 'delete',
                'path_b64' => base64_encode('deleted.txt'),
            ],
        ], $records);
    }

    public function testFinalizeCheckpointDoesNotStartANewRemoteIndex(): void
    {
        $this->completeEligiblePull();
        $statePath = $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $state['active_resumable_command']['completion_state'] =
            'in_progress';
        $state['active_resumable_command']['current_stage'] =
            'finalize';
        file_put_contents(
            $statePath,
            json_encode(
                $state,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
        $localContents = 'local edit retained at finalization';
        file_put_contents(
            $this->localTree . '/' . self::PULLED_PATH,
            $localContents
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode('later remote edit'),
        ]);
        $requestsBeforeResume = file_get_contents($this->requestsLog);

        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $this->assertSame(
            $requestsBeforeResume,
            file_get_contents($this->requestsLog)
        );
    }

    public function testFilesPullStopsBeforeReplacingAFileChangedLocallyAndRemotely(): void
    {
        $localContents = 'local edit that must remain on disk';
        $remoteContents = 'remote edit that must not be downloaded';
        $this->prepareChangedFileConflict($localContents, $remoteContents);

        $result = $this->runFilesPull();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $this->assertStringContainsString(
            'The remote and local path both changed',
            $result['output']
        );
        $this->assertStringContainsString(
            'path_b64=' . base64_encode(self::PULLED_PATH),
            $result['output']
        );

        $continued = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);
        $this->assertSame(0, $continued['exit'], $continued['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
    }

    public function testOurWinsKeepsAConflictingLocalEditPending(): void
    {
        $localContents = 'local edit retained by our-wins';
        $this->prepareChangedFileConflict(
            $localContents,
            'remote edit rejected by our-wins'
        );

        $result = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([
            $this->expectedPushRecord(self::PULLED_PATH, 'file'),
            [
                'command' => 'files-diff',
                'status' => 'complete',
                'local_paths_to_push' => 1,
                'local_paths_to_delete' => 0,
            ],
        ], $this->filesDiffRecords($diff['stdout']));
    }

    public function testRemoteWinsReplacesAConflictingLocalEdit(): void
    {
        $remoteContents = 'remote edit selected by remote-wins';
        $this->prepareChangedFileConflict(
            'local edit replaced by remote-wins',
            $remoteContents
        );

        $result = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            $remoteContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($diff['stdout']));
    }

    /** @dataProvider lateDirectoryChildContinuationProvider */
    public function testRemoteWinsRemovesLateDirectoryChildrenBeforeInstallingAFile(
        string $continuation
    ): void
    {
        $replacementPath = 'swap';
        $knownChild = $replacementPath . '/known.txt';
        $this->writeRemoteOverrides([
            'removed_paths' => [$replacementPath],
            'added_directories' => [$replacementPath],
            'added_paths' => [$knownChild],
        ]);
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->writeRemoteOverrides([
            'pause_before_file_fetch' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=remote-wins',
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.before-fetch-ready';
        $releasePath =
            $this->root . '/remote-overrides.json.before-fetch-release';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        $localReplacementPath =
            $this->localTree . '/' . $replacementPath;
        $this->assertDirectoryExists($localReplacementPath);
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . $knownChild
        );
        file_put_contents(
            $localReplacementPath . '/late.txt',
            'late local child'
        );
        mkdir($localReplacementPath . '/late-directory', 0700);
        file_put_contents(
            $localReplacementPath . '/late-directory/nested.txt',
            'late nested local child'
        );
        $outsideDirectory = $this->root . '/outside';
        mkdir($outsideDirectory, 0700);
        $outsidePath = $outsideDirectory . '/sentinel.txt';
        file_put_contents($outsidePath, 'must remain');
        symlink(
            $outsidePath,
            $localReplacementPath . '/late-link'
        );
        file_put_contents($releasePath, '');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        $this->assertSame(2, $exit, $stdout . $stderr);

        $state = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsInt(
            $state['fetch']['pending_file_install']['staging_ino']
                ?? null
        );
        $stagingPath = base64_decode(
            $state['fetch']['pending_file_install']
                ['staging_path_b64']
                ?? '',
            true
        );
        $this->assertIsString($stagingPath);
        $this->assertFileExists($stagingPath);
        $destinationRemoval =
            $state['fetch']['pending_file_install']
                ['destination_removal']
                ?? null;
        $this->assertIsArray($destinationRemoval);
        $this->assertNull($destinationRemoval['top_offset'] ?? null);
        $quarantinePath = base64_decode(
            $destinationRemoval['quarantine_path_b64'] ?? '',
            true
        );
        $this->assertIsString($quarantinePath);
        $this->assertFileDoesNotExist($quarantinePath);

        if ($continuation === 'abort after process death') {
            if (
                !function_exists('posix_kill')
                || !defined('SIGSTOP')
                || !defined('SIGKILL')
            ) {
                $this->markTestSkipped(
                    'This test needs POSIX process signals.'
                );
            }
            $this->writeRemoteOverrides([
                'preflight_padding_bytes' => 32 * 1024 * 1024,
            ]);
            $preflight = $this->runCli([
                'preflight',
                $this->targetUrl,
                '--state-dir=' . $this->stateDirectory,
                '--fs-root=' . $this->rawFileRoot,
            ]);
            $this->assertSame(
                0,
                $preflight['exit'],
                $preflight['output']
            );
            unset($preflight);
            [$abortProcess, $abortPipes] =
                $this->startCliProcess([
                    'files-pull',
                    $this->targetUrl,
                    '--state-dir=' . $this->stateDirectory,
                    '--fs-root=' . $this->rawFileRoot,
                    '--abort',
                ]);
            $abortStatus = proc_get_status($abortProcess);
            $this->assertIsArray($abortStatus);
            $abortProcessId =
                (int) ( $abortStatus['pid'] ?? 0 );
            $this->assertGreaterThan(0, $abortProcessId);
            $statePath =
                $this->stateDirectory . '/.import-state.json';
            $stoppedDuringFinalStateSave = false;
            try {
                $deadline = microtime(true) + 15;
                while (microtime(true) < $deadline) {
                    clearstatcache(true, $stagingPath);
                    clearstatcache(true, $statePath . '.tmp');
                    if (
                        !file_exists($stagingPath)
                        && is_file($statePath . '.tmp')
                    ) {
                        $this->assertTrue(
                            posix_kill($abortProcessId, SIGSTOP)
                        );
                        $stoppedDuringFinalStateSave = true;
                        break;
                    }
                    $abortStatus =
                        proc_get_status($abortProcess);
                    if (
                        !is_array($abortStatus)
                        || empty($abortStatus['running'])
                    ) {
                        break;
                    }
                    usleep(1000);
                }
                $this->assertTrue(
                    $stoppedDuringFinalStateSave,
                    'Abort did not expose its clean-state write.'
                );
                usleep(20000);
                $stoppedState = json_decode(
                    (string) file_get_contents($statePath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $this->assertSame(
                    0,
                    $stoppedState['files_pull_staging_version']
                        ?? null
                );
                $this->assertTrue(
                    $stoppedState['fetch']['pending_file_install']
                        ['discard_started']
                        ?? false
                );
                $this->assertSame(
                    $state['fetch']['cursor'] ?? null,
                    $stoppedState['fetch']['cursor'] ?? null
                );
            } finally {
                if ($abortProcessId > 0) {
                    posix_kill($abortProcessId, SIGKILL);
                } elseif (is_resource($abortProcess)) {
                    proc_terminate($abortProcess, 9);
                }
                foreach ([1, 2] as $pipeIndex) {
                    if (
                        isset($abortPipes[$pipeIndex])
                        && is_resource($abortPipes[$pipeIndex])
                    ) {
                        fclose($abortPipes[$pipeIndex]);
                    }
                }
                if (is_resource($abortProcess)) {
                    proc_close($abortProcess);
                }
            }
            $blockedResume = $this->runFilesPull([
                '--on-conflict=remote-wins',
            ]);
            $this->assertSame(
                1,
                $blockedResume['exit'],
                $blockedResume['output']
            );
            $this->assertStringContainsString(
                'private regular-file staging schema',
                $blockedResume['output']
            );
            $aborted = $this->runFilesPull(['--abort']);
            $this->assertSame(
                0,
                $aborted['exit'],
                $aborted['output']
            );
            $this->assertFileDoesNotExist($stagingPath);
            $this->assertFileDoesNotExist($quarantinePath);
            $this->assertDirectoryExists($localReplacementPath);
            $this->assertFileDoesNotExist(
                $this->pushStateDirectory()
                    . '/previous_local_index.jsonl'
            );
            $this->writeRemoteOverrides([]);
            $resumed = $this->runFilesPull([
                '--on-conflict=remote-wins',
            ]);
            $resumeCount = 1;
            while (
                $resumed['exit'] === 2
                && $resumeCount < 20
            ) {
                $resumed = $this->runFilesPull([
                    '--on-conflict=remote-wins',
                ]);
                ++$resumeCount;
            }
            $this->assertSame(
                0,
                $resumed['exit'],
                $resumed['output']
            );
            $this->assertFileExists($localReplacementPath);
            $this->assertFalse(is_dir($localReplacementPath));
            $this->assertSame(
                str_repeat(
                    'r',
                    strlen($this->initialFiles[$replacementPath])
                ),
                file_get_contents($localReplacementPath)
            );
            $this->assertSame(
                'must remain',
                file_get_contents($outsidePath)
            );
            return;
        }

        $renameStep = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);
        $this->assertSame(2, $renameStep['exit'], $renameStep['output']);
        $this->assertFileDoesNotExist($localReplacementPath);
        $this->assertDirectoryExists($quarantinePath);

        $stackStep = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);
        $this->assertSame(2, $stackStep['exit'], $stackStep['output']);
        $state = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsInt(
            $state['fetch']['pending_file_install']
                ['destination_removal']['top_offset']
                ?? null
        );
        $removalStack =
            $this->stateDirectory
            . '/.files-pull-fetch-pending-file-install'
            . '-directory-stack.jsonl';
        $this->assertFileExists($removalStack);

        if ($continuation === 'abort') {
            $aborted = $this->runFilesPull(['--abort']);
            $this->assertSame(
                1,
                $aborted['exit'],
                $aborted['output']
            );
            $this->assertStringContainsString(
                'Resume files-pull until the pending fetched file settles',
                $aborted['output']
            );
            $this->assertFileExists($stagingPath);
            $this->assertFileExists($removalStack);
            $this->assertDirectoryExists($quarantinePath);
            $this->assertFileDoesNotExist($localReplacementPath);
            $abortedState = json_decode(
                (string) file_get_contents(
                    $this->stateDirectory . '/.import-state.json'
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertSame(
                1,
                $abortedState['files_pull_staging_version']
                    ?? null
            );
            $this->writeRemoteOverrides([]);
            $resumed = $this->runFilesPull([
                '--on-conflict=remote-wins',
            ]);
            $resumeCount = 1;
        } else {
            symlink($outsideDirectory, $localReplacementPath);
            $this->writeRemoteOverrides([]);
            $resumed = ['exit' => 2, 'output' => ''];
            $resumeCount = 2;
        }
        while (
            $resumed['exit'] === 2
            && $resumeCount < 20
        ) {
            $resumed = $this->runFilesPull([
                '--on-conflict=remote-wins',
            ]);
            ++$resumeCount;
        }
        if ($continuation === 'resume') {
            $this->assertGreaterThanOrEqual(3, $resumeCount);
        }

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertFileExists($localReplacementPath);
        $this->assertFalse(is_dir($localReplacementPath));
        $this->assertSame(
            str_repeat(
                'r',
                strlen($this->initialFiles[$replacementPath])
            ),
            file_get_contents($localReplacementPath)
        );
        $this->assertSame('must remain', file_get_contents($outsidePath));
        $this->assertFileDoesNotExist($removalStack);
        $this->assertFileDoesNotExist($quarantinePath);
    }

    /** @return iterable<string,array{string}> */
    public static function lateDirectoryChildContinuationProvider(): iterable
    {
        yield 'resume' => ['resume'];
        yield 'abort waits for settlement' => ['abort'];
        yield 'abort after process death' =>
            ['abort after process death'];
    }

    public function testDefaultPolicyQuarantinesADirectoryBeforeInstallingAFile(): void
    {
        $replacementPath = 'swap';
        $this->writeRemoteOverrides([
            'removed_paths' => [$replacementPath],
            'added_directories' => [$replacementPath],
            'added_paths' => [$replacementPath . '/known.txt'],
        ]);
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->writeRemoteOverrides([]);

        $result = ['exit' => 2, 'output' => ''];
        $invocations = 0;
        while ($result['exit'] === 2 && $invocations < 12) {
            $result = $this->runFilesPull();
            ++$invocations;
        }

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertGreaterThanOrEqual(3, $invocations);
        $localReplacementPath =
            $this->localTree . '/' . $replacementPath;
        $this->assertFileExists($localReplacementPath);
        $this->assertFalse(is_dir($localReplacementPath));
        $this->assertSame(
            str_repeat(
                'r',
                strlen($this->initialFiles[$replacementPath])
            ),
            file_get_contents($localReplacementPath)
        );
        $this->assertSame(
            [],
            glob($this->localTree . '/.reprint-*.remove')
        );
        $this->assertFileDoesNotExist(
            $this->stateDirectory
                . '/.files-pull-fetch-pending-file-install'
                . '-directory-stack.jsonl'
        );
    }

    public function testFilesPullStopsWhenAFileChangesAfterConflictPlanning(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $remoteContents = 'remote edit planned before the local edit';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
            'pause_before_file_fetch' => true,
        ]);
        // files-pull opens file_fetch only after conflict planning. The real
        // endpoint pauses before returning any multipart part, so the local
        // edit happens after planning and before the remote change is applied.
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.before-fetch-ready';
        $releasePath =
            $this->root . '/remote-overrides.json.before-fetch-release';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $reachedFetchBoundary = is_file($readyPath);
        $localContents = 'local edit made after conflict planning';
        if ($reachedFetchBoundary) {
            file_put_contents(
                $this->localTree . '/' . self::PULLED_PATH,
                $localContents
            );
        }
        file_put_contents($releasePath, '');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        $output = $stdout . $stderr;

        $this->assertTrue(
            $reachedFetchBoundary,
            'The file_fetch endpoint was not reached before the test timeout. '
                . $output
        );
        $this->assertSame(1, $exit, $output);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $this->assertStringContainsString(
            'changed locally after files-pull planned the remote change',
            $output
        );
        $this->assertStringContainsString(
            'path_b64=' . base64_encode(self::PULLED_PATH),
            $output
        );
    }

    public function testOurWinsRetainsLateDirectoryDriftAndItsSubtree(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $remoteDirectory = 'late-retained-tree';
        $remoteChild = $remoteDirectory . '/remote.txt';
        $localChild = $remoteDirectory . '/local.txt';
        $this->writeRemoteOverrides([
            'added_directories' => [$remoteDirectory],
            'added_paths' => [$remoteChild],
            'pause_before_file_fetch' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=our-wins',
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.before-fetch-ready';
        $releasePath =
            $this->root . '/remote-overrides.json.before-fetch-release';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        mkdir(
            $this->localTree . '/' . $remoteDirectory,
            0700,
            true
        );
        file_put_contents(
            $this->localTree . '/' . $localChild,
            'late local contents'
        );
        file_put_contents($releasePath, '');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        $output = $stdout . $stderr;

        $this->assertSame(0, $exit, $output);
        $this->assertSame(
            'late local contents',
            file_get_contents($this->localTree . '/' . $localChild)
        );
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . $remoteChild
        );
    }

    public function testOurWinsRetainsInterleavedSubtreesAcrossFetchResponses(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $firstRoot = 'interleaved/a';
        $secondRoot = 'interleaved/a-';
        $firstRemoteChild = $firstRoot . '/remote.txt';
        $secondRemoteChild = $secondRoot . '/remote.txt';
        $interveningPath = 'interleaved/a.remote.txt';
        $this->writeRemoteOverrides([
            'added_directories' => [$firstRoot, $secondRoot],
            'added_paths' => [
                $secondRemoteChild,
                $interveningPath,
                $firstRemoteChild,
            ],
            'pause_before_file_fetch' => true,
            'partial_after_added_directories' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=our-wins',
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.before-fetch-ready';
        $releasePath =
            $this->root . '/remote-overrides.json.before-fetch-release';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        foreach ([$firstRoot, $secondRoot] as $root) {
            mkdir($this->localTree . '/' . $root, 0700, true);
            file_put_contents(
                $this->localTree . '/' . $root . '/local.txt',
                'late local contents'
            );
        }
        file_put_contents($releasePath, '');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        $this->assertSame(2, $exit, $stdout . $stderr);
        $state = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-state.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertNotNull(
            $state['fetch']['retained_local_subtree_top_offset']
                ?? null
        );
        $this->assertFileExists(
            $this->stateDirectory
                . '/.files-pull-fetch-retained-subtrees.jsonl'
        );

        $resumed = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        foreach ([$firstRoot, $secondRoot] as $root) {
            $this->assertSame(
                'late local contents',
                file_get_contents(
                    $this->localTree . '/' . $root . '/local.txt'
                )
            );
        }
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . $firstRemoteChild
        );
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . $secondRemoteChild
        );
        $this->assertSame(
            'added remote contents',
            file_get_contents(
                $this->localTree . '/' . $interveningPath
            )
        );
        $this->assertFileDoesNotExist(
            $this->stateDirectory
                . '/.files-pull-fetch-retained-subtrees.jsonl'
        );
    }

    public function testOurWinsKeepsALocalEditWhenTheRemoteFileWasDeleted(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localContents = 'local edit retained after a remote deletion';
        file_put_contents(
            $this->localTree . '/' . self::PULLED_PATH,
            $localContents
        );
        $this->writeRemoteOverrides([
            'removed_paths' => [self::PULLED_PATH],
        ]);

        $result = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $records = $this->filesDiffRecords($diff['stdout']);
        $this->assertSame(
            $this->expectedPushRecord(self::PULLED_PATH, 'file'),
            $records[0] ?? null
        );
    }

    public function testOurWinsProtectsAReplacementDirectorySubtree(): void
    {
        $parent = 'z-remote-tree';
        $editedChild = $parent . '/edited.txt';
        $unchangedSibling = $parent . '/unchanged.txt';
        $this->writeRemoteOverrides([
            'added_directories' => [$parent],
            'added_paths' => [$editedChild, $unchangedSibling],
        ]);
        $this->completeEligiblePull();
        $remoteBaseline = $this->readIndex(
            $this->stateDirectory . '/.import-index.jsonl'
        );
        $this->assertArrayHasKey(
            '/var/www/html/' . $parent,
            $remoteBaseline
        );
        $this->assertArrayHasKey(
            '/var/www/html/' . $editedChild,
            $remoteBaseline
        );
        $this->assertArrayHasKey(
            '/var/www/html/' . $unchangedSibling,
            $remoteBaseline
        );
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localContents = 'local child edit retained under remote deletion';
        file_put_contents($this->localTree . '/' . $editedChild, $localContents);
        $this->writeRemoteOverrides([]);

        $ourWins = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $ourWins['exit'], $ourWins['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . $editedChild)
        );
        $this->assertSame(
            'added remote contents',
            file_get_contents($this->localTree . '/' . $unchangedSibling)
        );

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $remoteWins = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(0, $remoteWins['exit'], $remoteWins['output']);
        $this->assertDirectoryDoesNotExist($this->localTree . '/' . $parent);
    }

    public function testFilesPullStopsBeforeDeletingAParentWithALocallyEditedChild(): void
    {
        $parent = 'remote-tree';
        $child = $parent . '/child.txt';
        $this->writeRemoteOverrides([
            'added_directories' => [$parent],
            'added_paths' => [$child],
        ]);
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localContents = 'local child edit retained under remote deletion';
        file_put_contents($this->localTree . '/' . $child, $localContents);
        $this->writeRemoteOverrides([]);

        $result = $this->runFilesPull();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . $child)
        );
        $this->assertStringContainsString(
            'path_b64=' . base64_encode($parent),
            $result['output']
        );
    }

    public function testResumedPullRejectsAConflictPolicyChangeAfterFetchStarts(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $newPulledContents = str_repeat('interrupted remote contents ', 4096);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
            'pause_mid_file' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
            '--on-conflict=our-wins',
        ]);
        $readyPath = $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
        ]);
        $changedPolicy = $this->runFilesPull([
            '--on-conflict=remote-wins',
        ]);

        $this->assertSame(1, $changedPolicy['exit'], $changedPolicy['output']);
        $this->assertStringContainsString(
            'conflict policy',
            strtolower($changedPolicy['output'])
        );
        $this->assertStringContainsString('our-wins', $changedPolicy['output']);
        $this->assertStringContainsString('remote-wins', $changedPolicy['output']);

        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertSame(
            $newPulledContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
    }

    /** @dataProvider incompatibleResumeOptionsProvider
     *  @param list<string> $incompatibleOptions
     */
    public function testResumedCompatiblePullRejectsOptionsThatDisableConflictPlanning(
        array $incompatibleOptions
    ): void {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $localContents =
            'local edit retained while the remote index request is open';
        $remoteContents =
            'remote edit returned after the interrupted index request';
        file_put_contents(
            $this->localTree . '/' . self::PULLED_PATH,
            $localContents
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
            'pause_before_file_index_response' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $readyPath =
            $this->root . '/remote-overrides.json.before-index-ready';
        $releasePath =
            $this->root . '/remote-overrides.json.before-index-release';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        file_put_contents($releasePath, '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
        ]);

        $changedOptions =
            $this->runFilesPull($incompatibleOptions);

        $this->assertSame(
            1,
            $changedOptions['exit'],
            $changedOptions['output']
        );
        $this->assertStringContainsString(
            'previous-local-index decision',
            $changedOptions['output']
        );
        $this->assertStringContainsString(
            'original command context',
            $changedOptions['output']
        );
        $this->assertStringContainsString(
            'or abort this files-pull lifecycle',
            $changedOptions['output']
        );

        $resumed = $this->runFilesPull([
            '--on-fs-root-nonempty=error',
        ]);

        $this->assertSame(1, $resumed['exit'], $resumed['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $this->assertStringContainsString(
            'The remote and local path both changed',
            $resumed['output']
        );
    }

    /** @return iterable<string,array{list<string>}> */
    public static function incompatibleResumeOptionsProvider(): iterable
    {
        yield 'include caches' => [['--include-caches']];
        yield 'preserve local' => [[
            '--on-fs-root-nonempty=preserve-local',
        ]];
    }

    public function testLegacyResumeInfersCompatibilityBeforeRejectingChangedOptions(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $newPulledContents =
            str_repeat('legacy interrupted remote contents ', 4096);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode($newPulledContents),
            'pause_mid_file' => true,
        ]);
        [$process, $pipes] = $this->startCliProcess([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ]);
        $readyPath = $this->root . '/remote-overrides.json.pause-ready';
        $deadline = microtime(true) + 10;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($readyPath);
        proc_terminate($process, 9);
        file_put_contents(
            $this->root . '/remote-overrides.json.pause-release',
            ''
        );
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // State written before this field existed is still a supported resume
        // input. The WAL and paired baseline carry the earlier decision.
        $statePath = $this->stateDirectory . '/.import-state.json';
        $state = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        unset($state['diff']['maintain_previous_local_index']);
        file_put_contents(
            $statePath,
            json_encode(
                $state,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' =>
                base64_encode($newPulledContents),
        ]);

        $changedOptions = $this->runFilesPull([
            '--include-caches',
        ]);

        $this->assertSame(
            1,
            $changedOptions['exit'],
            $changedOptions['output']
        );
        $this->assertStringContainsString(
            'previous-local-index decision',
            $changedOptions['output']
        );
        $migratedState = json_decode(
            (string) file_get_contents($statePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertTrue(
            $migratedState['diff']['maintain_previous_local_index']
                ?? false
        );

        $resumed = $this->runFilesPull();

        $this->assertSame(0, $resumed['exit'], $resumed['output']);
        $this->assertSame(
            $newPulledContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
    }

    public function testOurWinsKeepsALocalAdditionWhenTheRemoteAddedTheSamePath(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $path = 'added-on-both-sides.txt';
        $localContents = 'local addition retained by our-wins';
        file_put_contents($this->localTree . '/' . $path, $localContents);
        $this->writeRemoteOverrides([
            'added_paths' => [$path],
        ]);

        $result = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            $localContents,
            file_get_contents($this->localTree . '/' . $path)
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $records = $this->filesDiffRecords($diff['stdout']);
        $this->assertSame(
            $this->expectedPushRecord($path, 'file'),
            $records[0] ?? null
        );
    }

    public function testOurWinsKeepsALocalDeletionWhenTheRemoteFileChanged(): void
    {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        unlink($this->localTree . '/' . self::PULLED_PATH);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode(
                'remote edit rejected after the local deletion'
            ),
        ]);

        $result = $this->runFilesPull([
            '--on-conflict=our-wins',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertFileDoesNotExist(
            $this->localTree . '/' . self::PULLED_PATH
        );
        $diff = $this->runFilesDiff();
        $this->assertSame(0, $diff['exit'], $diff['output']);
        $records = $this->filesDiffRecords($diff['stdout']);
        $this->assertSame(
            $this->expectedPushRecord('selected', 'dir'),
            $records[0] ?? null
        );
        $this->assertSame([
            'command' => 'files-diff',
            'action' => 'delete',
            'path_b64' => base64_encode('selected'),
        ], $records[1] ?? null);
    }

    public function testSelectedDeltaPullKeepsChangesOutsideItsAppliedPathsPending(): void
    {
        $this->completeEligiblePull();

        file_put_contents($this->localTree . '/edited.txt', 'local edit with a longer size');
        unlink($this->localTree . '/deleted.txt');
        $this->removeTree($this->localTree . '/pending-directory');
        $newPulledContents = 'selected remote change';
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
        ]);

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $delta = $this->runFilesPull([
            '--only=/var/www/html/selected',
        ]);
        $this->assertSame(0, $delta['exit'], $delta['output']);
        $this->assertSame(
            $newPulledContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $records = $this->filesDiffRecords($result['stdout']);
        $finalRecord = array_pop($records);
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 1,
            'local_paths_to_delete' => 2,
        ], $finalRecord);
        $this->assertSame([
            $this->expectedPushRecord('edited.txt', 'file'),
            [
                'command' => 'files-diff',
                'action' => 'delete',
                'path_b64' => base64_encode('deleted.txt'),
            ],
            [
                'command' => 'files-diff',
                'action' => 'delete',
                'path_b64' => base64_encode('pending-directory'),
            ],
        ], $records);
    }

    public function testSelectedDeltaPullPreservesPendingAdditionWhenPulledDeletionMakesParentEmpty(): void
    {
        $this->completeEligiblePull();

        file_put_contents($this->localTree . '/shared/local-added.txt', 'local addition');
        $this->writeRemoteOverrides([
            'removed_paths' => ['shared/remote-deleted.txt'],
        ]);

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $delta = $this->runFilesPull([
            '--only=/var/www/html/shared',
        ]);
        $this->assertSame(0, $delta['exit'], $delta['output']);
        $this->assertFileDoesNotExist($this->localTree . '/shared/remote-deleted.txt');
        $this->assertFileExists($this->localTree . '/shared/local-added.txt');

        $index = $this->readIndex(
            $this->pushStateDirectory() . '/previous_local_index.jsonl'
        );
        $this->assertTrue($index['shared']['empty'] ?? false);
        $this->assertArrayNotHasKey('shared/remote-deleted.txt', $index);
        $this->assertArrayNotHasKey('shared/local-added.txt', $index);

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame([
            $this->expectedPushRecord('shared/local-added.txt', 'file'),
            [
                'command' => 'files-diff',
                'status' => 'complete',
                'local_paths_to_push' => 1,
                'local_paths_to_delete' => 0,
            ],
        ], $this->filesDiffRecords($result['stdout']));
    }

    public function testDeltaPullRemovesACompleteRemoteSubtreeFromThePreviousLocalIndex(): void
    {
        $this->writeRemoteOverrides([
            'added_directories' => ['removed-tree'],
            'added_paths' => ['removed-tree/child.txt'],
        ]);
        $this->completeEligiblePull();
        $this->assertDirectoryExists($this->localTree . '/removed-tree');
        $this->assertFileExists($this->localTree . '/removed-tree/child.txt');

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->writeRemoteOverrides([]);
        $delta = $this->runFilesPull();

        $this->assertSame(0, $delta['exit'], $delta['output']);
        clearstatcache(true, $this->localTree . '/removed-tree');
        $this->assertDirectoryDoesNotExist($this->localTree . '/removed-tree');
        $index = $this->readIndex(
            $this->pushStateDirectory() . '/previous_local_index.jsonl'
        );
        $this->assertArrayNotHasKey('removed-tree', $index);
        $this->assertArrayNotHasKey('removed-tree/child.txt', $index);

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($result['stdout']));
    }

    public function testDeltaPullKeepsDefaultSkippedRemotePathsOutOfTheIndex(): void
    {
        $this->completeEligiblePull();

        // The remote gains a path files-push scope always omits. The delta
        // pull downloads it, but the index must not admit it: a stray entry
        // would make files-diff report a deletion files-push would then apply.
        $this->writeRemoteOverrides([
            'added_paths' => ['node_modules/library/index.js'],
        ]);

        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $delta = $this->runFilesPull();
        $this->assertSame(0, $delta['exit'], $delta['output']);
        $this->assertFileExists($this->localTree . '/node_modules/library/index.js');

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame([[
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ]], $this->filesDiffRecords($result['stdout']));
    }

    public function testAbortedFilesPullKeepsThePreviousLocalIndexUsable(): void
    {
        $this->completeEligiblePull();
        file_put_contents($this->localTree . '/added-before-abort.txt', 'new');

        $abort = $this->runFilesPull(['--abort']);
        $result = $this->runFilesDiff();

        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->assertSame(0, $result['exit'], $result['output']);
        $records = $this->filesDiffRecords($result['stdout']);
        $this->assertSame(
            $this->expectedPushRecord('added-before-abort.txt', 'file'),
            $records[0] ?? null
        );
    }

    /** @return array{command:string,action:string,path_b64:string,type:string,size:int,ctime:int} */
    private function expectedPushRecord(string $path, string $type): array
    {
        $stat = lstat($this->localTree . '/' . $path);
        $this->assertIsArray($stat);
        return [
            'command' => 'files-diff',
            'action' => 'push',
            'path_b64' => base64_encode($path),
            'type' => $type,
            'size' => $type === 'dir' ? 0 : (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    private function completeEligiblePull(): void
    {
        $result = $this->runFilesPull();
        $this->assertSame(0, $result['exit'], $result['output']);
    }

    private function prepareChangedFileConflict(
        string $localContents,
        string $remoteContents
    ): void {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        file_put_contents(
            $this->localTree . '/' . self::PULLED_PATH,
            $localContents
        );
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($remoteContents),
        ]);
    }

    private function corruptImportIndexOutputAfterFilesPullWritesWAL(
        string $newPulledContents
    ): void {
        $this->completeEligiblePull();
        $abort = $this->runFilesPull(['--abort']);
        $this->assertSame(0, $abort['exit'], $abort['output']);
        $this->writeRemoteOverrides([
            'pulled_ctime' => self::REMOTE_CTIME + 1,
            'pulled_contents_b64' => base64_encode($newPulledContents),
        ]);
        $blockedIndexOutput = $this->stateDirectory . '/.import-index.jsonl.new';
        file_put_contents($blockedIndexOutput, '');
        chmod($blockedIndexOutput, 0400);
        if (is_writable($blockedIndexOutput)) {
            $this->markTestSkipped('This runner cannot make the importer-index output unwritable.');
        }
        try {
            $failed = $this->runFilesPull();
        } finally {
            chmod($blockedIndexOutput, 0600);
            unlink($blockedIndexOutput);
        }
        $this->assertSame(1, $failed['exit'], $failed['output']);
        $this->assertSame(
            $newPulledContents,
            file_get_contents($this->localTree . '/' . self::PULLED_PATH)
        );
        $state = json_decode(
            (string) file_get_contents($this->stateDirectory . '/.import-state.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('pulled-file-complete', $state['fetch']['cursor'] ?? null);
    }

    /** @param array<string,mixed> $overrides */
    private function writeRemoteOverrides(array $overrides): void
    {
        file_put_contents(
            $this->root . '/remote-overrides.json',
            json_encode($overrides, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /** @param list<string> $extraArguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runFilesPull(array $extraArguments = []): array
    {
        return $this->runCli(array_merge([
            'files-pull',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->rawFileRoot,
        ], $extraArguments));
    }

    private function pushStateDirectory(): string
    {
        $canonicalLocalTree = realpath($this->localTree);
        $this->assertIsString($canonicalLocalTree);
        $pair = hash('sha256', rtrim($this->targetUrl, '?&') . "\0" . $canonicalLocalTree);
        return realpath($this->stateDirectory) . '/push/' . $pair;
    }

    /** @param list<string> $extraArguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runFilesDiff(?string $targetUrl = null, array $extraArguments = []): array
    {
        return $this->runCli(array_merge([
            'files-diff',
            $targetUrl ?? $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
        ], $extraArguments));
    }

    /** @param list<string> $arguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runCli(array $arguments): array
    {
        [$process, $pipes] = $this->startCliProcess($arguments);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $stdout . $stderr,
        ];
    }

    /** @param list<string> $arguments
     *  @return array{0:resource,1:array<int,resource>}
     */
    private function startCliProcess(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../importer/import.php'], $arguments),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @param array<string,mixed> $record */
    private function encodeJsonLine(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function assertCanonicalSingleJsonLine(string $output): void
    {
        $record = json_decode(rtrim($output, "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($record);
        $this->assertSame(json_encode($record, JSON_THROW_ON_ERROR) . "\n", $output);
    }

    /** @return list<array<string,mixed>> */
    private function filesDiffRecords(string $output): array
    {
        $records = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ( $decoded['command'] ?? null ) === 'files-diff') {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    private function requestCount(): int
    {
        if (!file_exists($this->requestsLog)) {
            return 0;
        }
        return count(file($this->requestsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    private function requestEndpointCounts(): array
    {
        $counts = [];
        foreach (file($this->requestsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $endpoint = $request['endpoint'] ?? null;
            if (is_string($endpoint)) {
                $counts[$endpoint] = ( $counts[$endpoint] ?? 0 ) + 1;
            }
        }
        return $counts;
    }

    /** @return array<string,array{path:string,type:string,size:int,ctime:int,empty?:bool}> */
    private function readIndex(string $path): array
    {
        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $decodedPath = base64_decode($entry['path'] ?? '', true);
            $this->assertIsString($decodedPath);
            $entries[$decodedPath] = [
                'path' => $decodedPath,
                'type' => $entry['type'],
                'size' => $entry['size'],
                'ctime' => $entry['ctime'],
            ];
            if (array_key_exists('empty', $entry)) {
                $entries[$decodedPath]['empty'] = (bool) $entry['empty'];
            }
        }
        return $entries;
    }

    private function writePullStateAndIndex(): void
    {
        $roots = [[
            'path' => '/var/www/html',
        ]];
        file_put_contents(
            $this->stateDirectory . '/.import-state.json',
            json_encode([
                'preflight' => [
                    'http_code' => 200,
                    'data' => [
                        'ok' => true,
                        'protocol_version' => 2,
                        'protocol_min_version' => 1,
                        'runtime' => [
                            'document_root' => '/var/www/html',
                        ],
                        'wp_detect' => [
                            'roots' => $roots,
                        ],
                        'database' => [
                            'wp' => [
                                'paths_urls' => [
                                    'content_dir' => '/var/www/html/wp-content',
                                    'uploads' => [
                                        'basedir' => '/var/www/html/wp-content/uploads',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'remote_protocol_version' => 2,
                'remote_protocol_min_version' => 1,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $index = '';
        $initialFiles = $this->initialFiles;
        uksort($initialFiles, static fn(string $left, string $right): int => strcmp($left, $right));
        foreach ($initialFiles as $path => $contents) {
            $index .= json_encode([
                'path' => base64_encode('/var/www/html/' . $path),
                'ctime' => self::REMOTE_CTIME,
                'size' => strlen($contents),
                'type' => 'file',
            ], JSON_UNESCAPED_SLASHES) . "\n";
        }
        file_put_contents($this->stateDirectory . '/.import-index.jsonl', $index);
    }

    private function startIndexServer(): string
    {
        $remoteFiles = $this->initialFiles;
        $remoteFiles[self::PULLED_PATH] = self::PULLED_CONTENTS;
        $remoteIndex = [];
        foreach ($remoteFiles as $path => $contents) {
            $remoteIndex[] = [
                'path' => base64_encode('/var/www/html/' . $path),
                'ctime' => self::REMOTE_CTIME,
                'size' => strlen($contents),
                'type' => 'file',
            ];
        }

        $router = $this->root . '/index-server.php';
        file_put_contents($router, sprintf(<<<'PHP'
<?php
$requests_log = base64_decode('%s');
$remote_index = json_decode(base64_decode('%s'), true);
$pulled_path = base64_decode('%s');
$pulled_contents = base64_decode('%s');
$overrides_path = base64_decode('%s');
$wal_path = base64_decode('%s');
$pulled_ctime = 41;
$overrides = is_file($overrides_path)
    ? json_decode((string) file_get_contents($overrides_path), true)
    : null;
$protocol_version =
    is_array($overrides)
    && array_key_exists('protocol_version', $overrides)
        ? $overrides['protocol_version']
        : 2;
$protocol_min_version =
    is_array($overrides)
    && array_key_exists('protocol_min_version', $overrides)
        ? $overrides['protocol_min_version']
        : 1;
$added_paths = array();
if (is_array($overrides)) {
    if (!empty($overrides['pulled_contents_b64'])) {
        $pulled_contents = base64_decode($overrides['pulled_contents_b64']);
    }
    if (isset($overrides['pulled_ctime'])) {
        $pulled_ctime = (int) $overrides['pulled_ctime'];
    }
    $removed_paths = array();
    foreach (($overrides['removed_paths'] ?? array()) as $removed_path) {
        $removed_paths['/var/www/html/' . $removed_path] = true;
    }
    $updated_index = array();
    foreach ($remote_index as $entry) {
        $entry_path = base64_decode($entry['path']);
        if (isset($removed_paths[$entry_path])) {
            continue;
        }
        if ($entry_path === '/var/www/html/' . $pulled_path) {
            $entry['ctime'] = $pulled_ctime;
            $entry['size'] = strlen($pulled_contents);
        }
        $updated_index[] = $entry;
    }
    $added_paths = $overrides['added_paths'] ?? array();
    foreach (($overrides['added_paths_b64'] ?? array()) as $added_path_b64) {
        $decoded_added_path = base64_decode($added_path_b64, true);
        if (is_string($decoded_added_path) && $decoded_added_path !== '') {
            $added_paths[] = $decoded_added_path;
        }
    }
    foreach ($added_paths as $added_path) {
        $updated_index[] = array(
            'path' => base64_encode('/var/www/html/' . $added_path),
            'ctime' => 43,
            'size' => strlen('added remote contents'),
            'type' => 'file',
        );
    }
    foreach (($overrides['added_directories'] ?? array()) as $added_directory) {
        $updated_index[] = array(
            'path' => base64_encode('/var/www/html/' . $added_directory),
            'ctime' => 43,
            'size' => 0,
            'type' => 'dir',
            'empty' => false,
        );
    }
    $remote_index = $updated_index;
    if (!empty($overrides['empty_index'])) {
        $remote_index = array();
    }
}
$endpoint = $_GET['endpoint'] ?? null;
$request_cursor = $_GET['cursor'] ?? null;
$selected_directories = $_GET['directory'] ?? array();
if ($endpoint === 'file_index' && is_array($selected_directories) && count($selected_directories) > 0) {
    $selected_index = array();
    foreach ($remote_index as $entry) {
        $entry_path = base64_decode($entry['path']);
        foreach ($selected_directories as $selected_directory) {
            $selected_directory = rtrim($selected_directory, '/');
            if (
                $entry_path === $selected_directory
                || strpos($entry_path, $selected_directory . '/') === 0
            ) {
                $selected_index[] = $entry;
                break;
            }
        }
    }
    $remote_index = $selected_index;
}
$requested_file_paths = null;
if (
    $endpoint === 'file_fetch'
    && isset($_FILES['file_list']['tmp_name'])
    && is_file($_FILES['file_list']['tmp_name'])
) {
    $requested_file_paths = array();
    $requested_file_path_order = array();
    $requested_entries = json_decode(
        (string) file_get_contents($_FILES['file_list']['tmp_name']),
        true
    );
    foreach ($requested_entries as $requested_entry) {
        if (is_string($requested_entry)) {
            $requested_path = $requested_entry;
        } elseif (
            is_array($requested_entry)
            && isset($requested_entry['path'])
            && is_string($requested_entry['path'])
        ) {
            $requested_path = base64_decode(
                $requested_entry['path'],
                true
            );
        } else {
            $requested_path = false;
        }
        if (is_string($requested_path) && $requested_path !== '') {
            $requested_file_paths[$requested_path] = true;
            $requested_file_path_order[] = $requested_path;
        }
    }
}
file_put_contents(
    $requests_log,
    json_encode(
        array('endpoint' => $endpoint, 'cursor' => $request_cursor),
        JSON_UNESCAPED_SLASHES
    ) . "\n",
    FILE_APPEND
);

if (
    $endpoint === 'file_fetch'
    && !empty($overrides['require_previous_local_index_before_fetch'])
) {
    $previous_local_indexes = glob(
        dirname($wal_path) . '/push/*/previous_local_index.jsonl'
    );
    if (!is_array($previous_local_indexes) || count($previous_local_indexes) !== 1) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(array('message' => 'Previous local index was not seeded before file_fetch.'));
        exit;
    }
}

if ($endpoint === 'preflight') {
    header('Content-Type: application/json');
    $preflight = array(
        'ok' => true,
        'runtime' => array(
            'document_root' => '/var/www/html',
            'ini_get_all' => array(),
        ),
        'wp_detect' => array(
            'roots' => array(array('path' => '/var/www/html')),
        ),
        'database' => array(
            'wp' => array(
                'wp_version' => '6.0-test',
                'table_prefix' => 'wp_',
                'paths_urls' => array(
                    'abspath' => '/var/www/html',
                    'content_dir' => '/var/www/html/wp-content',
                    'uploads' => array(
                        'basedir' => '/var/www/html/wp-content/uploads',
                    ),
                ),
            ),
        ),
        'limits' => array('max_request_bytes' => 4194304),
    );
    if ($protocol_version !== null) {
        $preflight['protocol_version'] = $protocol_version;
    }
    if ($protocol_min_version !== null) {
        $preflight['protocol_min_version'] =
            $protocol_min_version;
    }
    if (!empty($overrides['preflight_padding_bytes'])) {
        $preflight['test_padding'] = str_repeat(
            'p',
            (int) $overrides['preflight_padding_bytes']
        );
    }
    echo json_encode($preflight, JSON_UNESCAPED_SLASHES);
    exit;
}

$boundary = 'reprint-files-diff-test';
header('Content-Type: multipart/mixed; boundary=' . $boundary);
$write_part = static function (array $headers, string $body = '') use ($boundary): void {
    echo "--{$boundary}\r\n";
    foreach ($headers as $name => $value) {
        echo $name . ': ' . $value . "\r\n";
    }
    echo 'Content-Length: ' . strlen($body) . "\r\n\r\n";
    echo $body . "\r\n";
};

if ($endpoint === 'file_index') {
    if (!empty($overrides['pause_before_file_index_response'])) {
        file_put_contents($overrides_path . '.before-index-ready', '');
        while (!is_file($overrides_path . '.before-index-release')) {
            usleep(20000);
        }
    }
    $write_part(
        array('X-Chunk-Type' => 'index_batch'),
        json_encode($remote_index, JSON_UNESCAPED_SLASHES)
    );
    $write_part(array(
        'X-Chunk-Type' => 'completion',
        'X-Status' => 'complete',
        'X-Total-Entries' => count($remote_index),
    ));
} elseif ($endpoint === 'file_fetch') {
    if (!empty($overrides['pause_before_file_fetch'])) {
        file_put_contents($overrides_path . '.before-fetch-ready', '');
        while (!is_file($overrides_path . '.before-fetch-release')) {
            usleep(20000);
        }
    }
    if (!empty($overrides['make_wal_unwritable'])) {
        file_put_contents($wal_path, '');
        chmod($wal_path, 0400);
    }
    $files_completed = 0;
    $bytes_processed = 0;
    if (!empty($overrides['complete_without_path_parts'])) {
        $write_part(array(
            'X-Chunk-Type' => 'completion',
            'X-Status' => 'complete',
            'X-Files-Completed' => 0,
            'X-Bytes-Processed' => 0,
        ));
        echo "--{$boundary}--\r\n";
        exit;
    }
    $split_after_added_directories =
        !empty($overrides['partial_after_added_directories']);
    $send_added_directories =
        !$split_after_added_directories
        || $request_cursor !== 'added-directory-complete';
    $remote_entries_by_path = array();
    foreach ($remote_index as $remote_entry) {
        $remote_entry_path = base64_decode($remote_entry['path'], true);
        if (is_string($remote_entry_path)) {
            $remote_entries_by_path[$remote_entry_path] =
                $remote_entry;
        }
    }
    $requested_file_path_order =
        isset($requested_file_path_order)
            ? $requested_file_path_order
            : array_keys($requested_file_paths ?? array());
    $added_directory_paths = array();
    foreach (($overrides['added_directories'] ?? array()) as $added_directory) {
        $added_directory_paths[
            '/var/www/html/' . $added_directory
        ] = true;
    }
    $added_directories_sent = 0;
    $added_directory_count = count($added_directory_paths);
    $added_file_paths = array();
    foreach ($added_paths as $added_path) {
        $added_file_paths['/var/www/html/' . $added_path] = true;
    }
    foreach ($requested_file_path_order as $requested_file_path) {
        if ($request_cursor === 'requested-file-complete') {
            continue;
        }
        if (
            $request_cursor === 'pulled-file-complete'
            && $requested_file_path
                === '/var/www/html/' . $pulled_path
        ) {
            continue;
        }
        if (
            !$send_added_directories
            && isset($added_directory_paths[$requested_file_path])
        ) {
            continue;
        }
        $remote_entry =
            $remote_entries_by_path[$requested_file_path] ?? null;
        if (!is_array($remote_entry)) {
            $write_part(array(
                'X-Chunk-Type' => 'missing',
                'X-File-Path' => base64_encode($requested_file_path),
                'X-Cursor' => 'missing-path-complete',
            ));
            continue;
        }
        if (($remote_entry['type'] ?? null) === 'dir') {
            $write_part(array(
                'X-Chunk-Type' => 'directory',
                'X-Directory-Path' =>
                    base64_encode($requested_file_path),
                'X-Directory-Ctime' =>
                    (int) ($remote_entry['ctime'] ?? 0),
                'X-Cursor' => 'added-directory-complete',
            ));
            ++$files_completed;
            ++$added_directories_sent;
            if (
                $split_after_added_directories
                && $added_directories_sent === $added_directory_count
            ) {
                $write_part(array(
                    'X-Chunk-Type' => 'completion',
                    'X-Status' => 'partial',
                    'X-Files-Completed' => $files_completed,
                    'X-Bytes-Processed' => $bytes_processed,
                ));
                echo "--{$boundary}--\r\n";
                exit;
            }
            continue;
        }
        $is_pulled_file =
            $requested_file_path
                === '/var/www/html/' . $pulled_path;
        if (
            $is_pulled_file
            && !empty($overrides['omit_pulled_path_part'])
        ) {
            continue;
        }
        $contents =
            $is_pulled_file
                ? $pulled_contents
                : (
                    isset($added_file_paths[$requested_file_path])
                        ? 'added remote contents'
                        : str_repeat(
                            'r',
                            (int) ($remote_entry['size'] ?? 0)
                        )
                );
        $file_headers = array(
            'X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode($requested_file_path),
            'X-File-Ctime' => (int) ($remote_entry['ctime'] ?? 0),
            'X-File-Size' => strlen($contents),
            'X-First-Chunk' => 1,
            'X-Last-Chunk' => 1,
            'X-Cursor' =>
                $is_pulled_file
                    ? 'pulled-file-complete'
                    : 'requested-file-complete',
        );
        $file_error_mode =
            $is_pulled_file
                ? ($overrides['file_error_mode'] ?? null)
                : null;
        if ($file_error_mode === 'after-non-final-part') {
            $file_headers['X-Last-Chunk'] = 0;
            $file_headers['X-Cursor'] = 'pulled-file-partial';
            $write_part(
                $file_headers,
                substr(
                    $contents,
                    0,
                    max(1, intdiv(strlen($contents), 2))
                )
            );
            $write_part(
                array(
                    'X-Chunk-Type' => 'error',
                    'X-Cursor' => 'pulled-file-error',
                ),
                json_encode(array(
                    'error_type' => 'file_read',
                    'path' => base64_encode($requested_file_path),
                    'message' => 'The remote file could not be read.',
                ))
            );
        } elseif (
            $file_error_mode
                === 'after-non-final-part-response-boundary'
        ) {
            if ($request_cursor !== 'pulled-file-partial') {
                $file_headers['X-Last-Chunk'] = 0;
                $file_headers['X-Cursor'] = 'pulled-file-partial';
                $write_part(
                    $file_headers,
                    substr(
                        $contents,
                        0,
                        max(1, intdiv(strlen($contents), 2))
                    )
                );
                $write_part(array(
                    'X-Chunk-Type' => 'completion',
                    'X-Status' => 'partial',
                    'X-Files-Completed' => 0,
                ));
                echo "--{$boundary}--\r\n";
                exit;
            }
            $write_part(
                array(
                    'X-Chunk-Type' => 'error',
                    'X-Cursor' => 'pulled-file-error',
                ),
                json_encode(array(
                    'error_type' => 'file_read',
                    'path' => base64_encode($requested_file_path),
                    'message' => 'The remote file could not be read.',
                ))
            );
        } elseif ($file_error_mode === 'before-first-part') {
            $write_part(
                array(
                    'X-Chunk-Type' => 'error',
                    'X-Cursor' => 'pulled-file-error',
                ),
                json_encode(array(
                    'error_type' => 'file_open',
                    'path' => base64_encode($requested_file_path),
                    'message' => 'The remote file could not be opened.',
                ))
            );
        } elseif ($is_pulled_file && !empty($overrides['pause_mid_file'])) {
            echo "--{$boundary}\r\n";
            foreach ($file_headers as $name => $value) {
                echo $name . ': ' . $value . "\r\n";
            }
            echo 'Content-Length: ' . strlen($contents) . "\r\n\r\n";
            $first_half_bytes = intdiv(strlen($contents), 2);
            echo substr($contents, 0, $first_half_bytes);
            flush();
            file_put_contents($overrides_path . '.pause-ready', '');
            while (!is_file($overrides_path . '.pause-release')) {
                usleep(20000);
            }
            echo substr($contents, $first_half_bytes) . "\r\n";
        } else {
            $write_part($file_headers, $contents);
        }
        if ($file_error_mode === null) {
            ++$files_completed;
            $bytes_processed += strlen($contents);
        }
    }
    $write_part(array(
        'X-Chunk-Type' => 'completion',
        'X-Status' => 'complete',
        'X-Files-Completed' => $files_completed,
        'X-Bytes-Processed' => $bytes_processed,
    ));
} elseif ($endpoint === 'db_index') {
    $write_part(array(
        'X-Chunk-Type' => 'completion',
        'X-Status' => 'complete',
        'X-Tables-Processed' => 0,
        'X-Rows-Estimated' => 0,
    ));
} elseif ($endpoint === 'sql_chunk') {
    $write_part(array(
        'X-Chunk-Type' => 'completion',
        'X-Status' => 'complete',
        'X-Sql-Bytes' => 0,
    ));
} else {
    $write_part(array(
        'X-Chunk-Type' => 'error',
        'X-Status' => 'failed',
    ), json_encode(array('message' => 'Unexpected endpoint')));
}
echo "--{$boundary}--\r\n";
PHP,
            base64_encode($this->requestsLog),
            base64_encode( (string) json_encode($remoteIndex)),
            base64_encode(self::PULLED_PATH),
            base64_encode(self::PULLED_CONTENTS),
            base64_encode($this->root . '/remote-overrides.json'),
            base64_encode($this->stateDirectory . '/.import-index-updates.wal')
        ));

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
            $connection = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://127.0.0.1:' . $port . '/export.php?site-export-api';
            }
            usleep(100000);
        }
        $this->fail('Local index server did not start.');
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
