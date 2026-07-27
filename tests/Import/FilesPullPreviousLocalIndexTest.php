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
        $this->rawFileRoot = $this->root . '/files';
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
    foreach (($overrides['added_paths'] ?? array()) as $added_path) {
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
    $requested_file_paths = array_fill_keys(
        json_decode((string) file_get_contents($_FILES['file_list']['tmp_name']), true),
        true
    );
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
    echo json_encode(array(
        'ok' => true,
        'protocol_version' => 1,
        'protocol_min_version' => 1,
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
    ), JSON_UNESCAPED_SLASHES);
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
    if (!empty($overrides['make_wal_unwritable'])) {
        file_put_contents($wal_path, '');
        chmod($wal_path, 0400);
    }
    $files_completed = 0;
    $bytes_processed = 0;
    if (
        (!is_array($overrides) || isset($overrides['pulled_ctime']))
        && $request_cursor !== 'pulled-file-complete'
        && ($requested_file_paths === null || isset($requested_file_paths['/var/www/html/' . $pulled_path]))
    ) {
        $file_headers = array(
            'X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode('/var/www/html/' . $pulled_path),
            'X-File-Ctime' => $pulled_ctime,
            'X-File-Size' => strlen($pulled_contents),
            'X-First-Chunk' => 1,
            'X-Last-Chunk' => 1,
            'X-Cursor' => 'pulled-file-complete',
        );
        if (!empty($overrides['pause_mid_file'])) {
            echo "--{$boundary}\r\n";
            foreach ($file_headers as $name => $value) {
                echo $name . ': ' . $value . "\r\n";
            }
            echo 'Content-Length: ' . strlen($pulled_contents) . "\r\n\r\n";
            $first_half_bytes = intdiv(strlen($pulled_contents), 2);
            echo substr($pulled_contents, 0, $first_half_bytes);
            flush();
            file_put_contents($overrides_path . '.pause-ready', '');
            while (!is_file($overrides_path . '.pause-release')) {
                usleep(20000);
            }
            echo substr($pulled_contents, $first_half_bytes) . "\r\n";
        } else {
            $write_part($file_headers, $pulled_contents);
        }
        ++$files_completed;
        $bytes_processed += strlen($pulled_contents);
    }
    foreach ((is_array($overrides) ? ($overrides['added_paths'] ?? array()) : array()) as $added_path) {
        if (
            $requested_file_paths !== null
            && !isset($requested_file_paths['/var/www/html/' . $added_path])
        ) {
            continue;
        }
        $write_part(array(
            'X-Chunk-Type' => 'file',
            'X-File-Path' => base64_encode('/var/www/html/' . $added_path),
            'X-File-Ctime' => 43,
            'X-File-Size' => strlen('added remote contents'),
            'X-First-Chunk' => 1,
            'X-Last-Chunk' => 1,
            'X-Cursor' => 'added-file-complete',
        ), 'added remote contents');
        ++$files_completed;
        $bytes_processed += strlen('added remote contents');
    }
    foreach ((is_array($overrides) ? ($overrides['added_directories'] ?? array()) : array()) as $added_directory) {
        if (
            $requested_file_paths !== null
            && !isset($requested_file_paths['/var/www/html/' . $added_directory])
        ) {
            continue;
        }
        $write_part(array(
            'X-Chunk-Type' => 'directory',
            'X-Directory-Path' => base64_encode('/var/www/html/' . $added_directory),
            'X-Directory-Ctime' => 43,
            'X-Cursor' => 'added-directory-complete',
        ));
        ++$files_completed;
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
