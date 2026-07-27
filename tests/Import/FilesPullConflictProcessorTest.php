<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class FilesPullConflictProcessorTest extends TestCase {
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir =
            sys_get_temp_dir()
            . '/files-pull-conflict-processor-'
            . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        parent::tearDown();
    }

    public function testEveryStepCanReplayAndResumeAcrossAReplacementSubtree(): void
    {
        $localTree = $this->tempDir . '/local';
        mkdir($localTree . '/tree', 0755, true);
        file_put_contents($localTree . '/tree/changed.txt', 'before');
        file_put_contents($localTree . '/tree/unchanged.txt', 'same');

        $previousLocalIndex = $this->tempDir . '/previous-local.jsonl';
        $this->writeLocalIndex(
            $previousLocalIndex,
            $localTree,
            [
                'tree',
                'tree/changed.txt',
                'tree/unchanged.txt',
            ]
        );
        file_put_contents(
            $localTree . '/tree/changed.txt',
            'after with a different size'
        );
        mkdir($localTree . '/tree/.git', 0755, true);
        file_put_contents(
            $localTree . '/tree/.git/config',
            'local'
        );

        $remoteDocumentRoot = '/var/www/html';
        $previousRemoteIndex = $this->tempDir . '/previous-remote.jsonl';
        $currentRemoteIndex = $this->tempDir . '/current-remote.jsonl';
        $remotePaths = [
            $remoteDocumentRoot . '/tree',
            $remoteDocumentRoot . '/tree/changed.txt',
            $remoteDocumentRoot . '/tree/unchanged.txt',
        ];
        $this->writeRemoteIndex($previousRemoteIndex, $remotePaths);
        file_put_contents($currentRemoteIndex, '');

        $planDirectory = $this->tempDir . '/plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath = $this->tempDir . '/conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir . '/planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteDocumentRoot,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        $phases = [];
        $stepCount = 0;
        while (true) {
            $priorCursor = $processor->get_cursor();
            $phases[] = $priorCursor['position']['phase'];

            // Repeat the file action from the preceding durable cursor before
            // storing the second result, then resume after every stored step.
            $processor->next_step();
            $processor->close();
            $processor =
                FilesPullConflictProcessor::resume($priorCursor);
            $hasNextStep = $processor->next_step();
            ++$stepCount;
            $this->assertLessThan(10000, $stepCount);
            if (!$hasNextStep) {
                break;
            }
            $storedCursor = $processor->get_cursor();
            $processor->close();
            $processor =
                FilesPullConflictProcessor::resume($storedCursor);
        }
        $this->assertFalse($processor->next_step());
        $processor->close();

        $this->assertContains('indexing_local', $phases);
        $this->assertContains('diffing_local_changes', $phases);
        $this->assertContains('diffing_remote', $phases);
        $this->assertContains('intersecting', $phases);
        $this->assertContains('sorting_conflicts', $phases);
        $this->assertSame(
            $remotePaths,
            $this->readIndexPaths($conflictsPath)
        );
        $this->assertSame(
            [
                $remoteDocumentRoot . '/tree',
                $remoteDocumentRoot . '/tree/.git',
                $remoteDocumentRoot . '/tree/changed.txt',
                $remoteDocumentRoot . '/tree/unchanged.txt',
            ],
            $this->readIndexPaths($plannedLocalIndexPath)
        );
    }

    public function testNewDirectoryWithOnlyDefaultSkippedChildConflictsWithRemoteScalar(): void
    {
        $localTree = $this->tempDir . '/default-skipped-local';
        mkdir($localTree . '/collision/.git', 0755, true);
        file_put_contents(
            $localTree . '/collision/.git/config',
            'local'
        );

        $this->assertNewDirectoryRootConflictsWithRemoteScalar(
            'default-skipped',
            $localTree
        );
    }

    public function testNewDirectoryWithOnlyUnsupportedFifoConflictsWithRemoteScalar(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('POSIX FIFO creation is unavailable.');
        }

        $localTree = $this->tempDir . '/unsupported-fifo-local';
        mkdir($localTree . '/collision', 0755, true);
        $this->assertTrue(
            posix_mkfifo($localTree . '/collision/private.pipe', 0600)
        );

        $this->assertNewDirectoryRootConflictsWithRemoteScalar(
            'unsupported-fifo',
            $localTree
        );
    }

    public function testStandaloneUnsupportedFifoConflictsWithRemoteFile(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('POSIX FIFO creation is unavailable.');
        }

        $localTree = $this->tempDir . '/standalone-fifo-local';
        mkdir($localTree, 0755, true);
        $this->assertTrue(
            posix_mkfifo($localTree . '/collision', 0600)
        );

        $this->assertNewDirectoryRootConflictsWithRemoteScalar(
            'standalone-fifo',
            $localTree
        );
    }

    public function testSkippedChildAdditionConflictsWithRemoteParentReplacement(): void
    {
        $localTree = $this->tempDir . '/skipped-child-local';
        mkdir($localTree . '/collision', 0755, true);
        file_put_contents(
            $localTree . '/collision/tracked.txt',
            'same'
        );
        $previousLocalIndex =
            $this->tempDir . '/skipped-child-previous-local.jsonl';
        $this->writeLocalIndex(
            $previousLocalIndex,
            $localTree,
            ['collision', 'collision/tracked.txt']
        );
        mkdir($localTree . '/collision/.git', 0755, true);
        file_put_contents(
            $localTree . '/collision/.git/config',
            'local'
        );

        $remoteDocumentRoot = '/var/www/html';
        $remoteParent = $remoteDocumentRoot . '/collision';
        $remoteChild = $remoteParent . '/tracked.txt';
        $previousRemoteIndex =
            $this->tempDir . '/skipped-child-previous-remote.jsonl';
        $currentRemoteIndex =
            $this->tempDir . '/skipped-child-current-remote.jsonl';
        file_put_contents(
            $previousRemoteIndex,
            json_encode(
                [
                    'path' => base64_encode($remoteParent),
                    'ctime' => 10,
                    'size' => 0,
                    'type' => 'dir',
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
                . json_encode(
                    [
                        'path' => base64_encode($remoteChild),
                        'ctime' => 10,
                        'size' => 4,
                        'type' => 'file',
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ) . "\n"
        );
        $this->writeRemoteIndex(
            $currentRemoteIndex,
            [$remoteParent]
        );

        $planDirectory =
            $this->tempDir . '/skipped-child-plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath =
            $this->tempDir . '/skipped-child-conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir
            . '/skipped-child-planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteDocumentRoot,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        while ($processor->next_step()) {
            continue;
        }
        $processor->close();

        $localChanges = $this->readIndexPaths(
            $planDirectory
                . '/local_changes.depth-first.jsonl'
        );
        $this->assertContains('collision/.git', $localChanges);
        $this->assertNotContains(
            'collision/.git/config',
            $localChanges
        );
        $this->assertSame(
            [$remoteParent, $remoteChild],
            $this->readIndexPaths($conflictsPath)
        );
    }

    public function testDefaultSkippedRootDoesNotConflictWithChangedRemoteSibling(): void
    {
        $localTree = $this->tempDir . '/skipped-sibling-local';
        mkdir($localTree . '/tree', 0755, true);
        file_put_contents(
            $localTree . '/tree/tracked.txt',
            'same'
        );
        $previousLocalIndex =
            $this->tempDir . '/skipped-sibling-previous-local.jsonl';
        $this->writeLocalIndex(
            $previousLocalIndex,
            $localTree,
            ['tree', 'tree/tracked.txt']
        );
        mkdir($localTree . '/tree/.git', 0755, true);
        file_put_contents(
            $localTree . '/tree/.git/config',
            'local'
        );

        $remoteDocumentRoot = '/var/www/html';
        $remoteTree = $remoteDocumentRoot . '/tree';
        $remoteTracked = $remoteTree . '/tracked.txt';
        $previousRemoteIndex =
            $this->tempDir . '/skipped-sibling-previous-remote.jsonl';
        $currentRemoteIndex =
            $this->tempDir . '/skipped-sibling-current-remote.jsonl';
        $this->writeRemoteIndex(
            $previousRemoteIndex,
            [$remoteTree, $remoteTracked],
            10,
            0
        );
        $currentLines = '';
        foreach ([$remoteTree, $remoteTracked] as $remotePath) {
            $currentLines .= json_encode(
                [
                    'path' => base64_encode($remotePath),
                    'ctime' => 11,
                    'size' =>
                        $remotePath === $remoteTree ? 0 : 4,
                    'type' =>
                        $remotePath === $remoteTree
                            ? 'dir'
                            : 'file',
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        }
        file_put_contents($currentRemoteIndex, $currentLines);

        $planDirectory =
            $this->tempDir . '/skipped-sibling-plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath =
            $this->tempDir . '/skipped-sibling-conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir
            . '/skipped-sibling-planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteDocumentRoot,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        while ($processor->next_step()) {
            continue;
        }
        $processor->close();

        $this->assertContains(
            'tree/.git',
            $this->readIndexPaths(
                $planDirectory
                    . '/local_changes.depth-first.jsonl'
            )
        );
        $this->assertSame(
            [],
            $this->readIndexPaths($conflictsPath)
        );
    }

    public function testUnchangedNonEmptyDirectoryDoesNotConflictWhenRemoteChildChangesAncestorMetadata(): void
    {
        $localTree = $this->tempDir . '/unchanged-directory-local';
        mkdir($localTree . '/tree', 0755, true);
        file_put_contents($localTree . '/tree/removed.txt', 'same');
        $childStat = lstat($localTree . '/tree/removed.txt');
        $this->assertIsArray($childStat);

        $previousLocalIndex =
            $this->tempDir . '/unchanged-directory-previous-local.jsonl';
        $previousLocalEntries = [
            [
                'path' => base64_encode('tree'),
                'ctime' => 10,
                'size' => 0,
                'type' => 'dir',
                'empty' => false,
            ],
            [
                'path' => base64_encode('tree/removed.txt'),
                'ctime' => (int) $childStat['ctime'],
                'size' => (int) $childStat['size'],
                'type' => 'file',
            ],
        ];
        file_put_contents(
            $previousLocalIndex,
            implode(
                "\n",
                array_map(
                    static function (array $entry): string {
                        return json_encode(
                            $entry,
                            JSON_UNESCAPED_SLASHES
                                | JSON_THROW_ON_ERROR
                        );
                    },
                    $previousLocalEntries
                )
            ) . "\n"
        );

        $remoteDocumentRoot = '/var/www/html';
        $remoteTree = $remoteDocumentRoot . '/tree';
        $previousRemoteIndex =
            $this->tempDir . '/unchanged-directory-previous-remote.jsonl';
        $currentRemoteIndex =
            $this->tempDir . '/unchanged-directory-current-remote.jsonl';
        $this->writeRemoteIndex(
            $previousRemoteIndex,
            [$remoteTree, $remoteTree . '/removed.txt'],
            10,
            0
        );
        $this->writeRemoteIndex(
            $currentRemoteIndex,
            [$remoteTree, $remoteTree . '/added.txt'],
            20,
            64
        );

        $planDirectory =
            $this->tempDir . '/unchanged-directory-plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath =
            $this->tempDir . '/unchanged-directory-conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir
            . '/unchanged-directory-planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteDocumentRoot,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        $stepCount = 0;
        while ($processor->next_step()) {
            ++$stepCount;
            $this->assertLessThan(1000, $stepCount);
        }
        $processor->close();

        $this->assertSame(
            [],
            $this->readIndexPaths(
                $planDirectory
                    . '/local_changes.depth-first.jsonl'
            )
        );
        $this->assertSame(
            [],
            $this->readIndexPaths($conflictsPath)
        );
    }

    public function testEndpointTraversalOrderDoesNotCreateAConflictForAnUnchangedSibling(): void
    {
        $localTree = $this->tempDir . '/endpoint-order-local';
        mkdir($localTree . '/a', 0755, true);
        file_put_contents($localTree . '/a/x', 'same');
        file_put_contents($localTree . '/a-foo', 'before');
        $previousLocalIndex =
            $this->tempDir . '/endpoint-order-previous-local.jsonl';
        $this->writeLocalIndex(
            $previousLocalIndex,
            $localTree,
            ['a', 'a/x', 'a-foo']
        );
        file_put_contents(
            $localTree . '/a-foo',
            'locally changed with a different size'
        );

        $remoteTree = $this->tempDir . '/endpoint-order-remote';
        mkdir($remoteTree . '/a', 0755, true);
        $canonicalRemoteTree = realpath($remoteTree);
        $this->assertIsString($canonicalRemoteTree);
        $remoteTree = $canonicalRemoteTree;
        file_put_contents($remoteTree . '/a/x', 'same');
        file_put_contents($remoteTree . '/a-foo', 'before');
        $previousRemoteIndex =
            $this->tempDir . '/endpoint-order-previous-remote.jsonl';
        $currentRemoteIndex =
            $this->tempDir . '/endpoint-order-current-remote.jsonl';
        $previousRemotePaths =
            $this->writeEndpointOrderRemoteIndex(
                $previousRemoteIndex,
                $remoteTree
            );
        unlink($remoteTree . '/a/x');
        file_put_contents($remoteTree . '/a/y', 'same');
        $currentRemotePaths =
            $this->writeEndpointOrderRemoteIndex(
                $currentRemoteIndex,
                $remoteTree
            );

        $this->assertSame(
            [
                $remoteTree . '/a',
                $remoteTree . '/a/x',
                $remoteTree . '/a-foo',
            ],
            $previousRemotePaths
        );
        $this->assertSame(
            [
                $remoteTree . '/a',
                $remoteTree . '/a/y',
                $remoteTree . '/a-foo',
            ],
            $currentRemotePaths
        );

        $planDirectory = $this->tempDir . '/endpoint-order-plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath =
            $this->tempDir . '/endpoint-order-conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir . '/endpoint-order-planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteTree,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        $stepCount = 0;
        while ($processor->next_step()) {
            ++$stepCount;
            $this->assertLessThan(1000, $stepCount);
        }
        $processor->close();

        $this->assertSame(
            [],
            $this->readIndexPaths($conflictsPath)
        );
    }

    public function testResumesAcrossMultiRunRemoteAndConflictSorts(): void
    {
        $localTree = $this->tempDir . '/large-sort-local';
        mkdir($localTree . '/tree', 0755, true);
        file_put_contents($localTree . '/tree/changed.txt', 'before');
        $previousLocalIndex =
            $this->tempDir . '/large-sort-previous-local.jsonl';
        $this->writeLocalIndex(
            $previousLocalIndex,
            $localTree,
            ['tree', 'tree/changed.txt']
        );
        file_put_contents(
            $localTree . '/tree/changed.txt',
            'after with a different size'
        );

        $remoteDocumentRoot = '/var/www/html';
        $remotePaths = [$remoteDocumentRoot . '/tree'];
        foreach (['a', 'b', 'c'] as $prefix) {
            $remotePaths[] =
                $remoteDocumentRoot
                . '/tree/'
                . $prefix
                . str_repeat($prefix, 800000);
        }
        $previousRemoteIndex =
            $this->tempDir . '/large-sort-previous-remote.jsonl';
        $currentRemoteIndex =
            $this->tempDir . '/large-sort-current-remote.jsonl';
        $this->writeRemoteIndex($previousRemoteIndex, $remotePaths);
        file_put_contents($currentRemoteIndex, '');

        $planDirectory = $this->tempDir . '/large-sort-plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath =
            $this->tempDir . '/large-sort-conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir . '/large-sort-planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteDocumentRoot,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        $remoteSortPhases = [];
        $conflictSortPhases = [];
        $stepCount = 0;
        while (true) {
            $cursor = $processor->get_cursor();
            if (
                $cursor['position']['phase']
                === 'sorting_remote_changes'
            ) {
                $remoteSortPhases[] =
                    $cursor['position']['sort_cursor']['position']['phase'];
            } elseif (
                $cursor['position']['phase']
                === 'sorting_conflicts'
            ) {
                $conflictSortPhases[] =
                    $cursor['position']['sort_cursor']['position']['phase'];
            }
            $hasNextStep = $processor->next_step();
            ++$stepCount;
            $this->assertLessThan(200, $stepCount);
            if (!$hasNextStep) {
                break;
            }
            $cursor = $processor->get_cursor();
            $processor->close();
            $processor =
                FilesPullConflictProcessor::resume($cursor);
        }
        $processor->close();

        $this->assertContains(
            'removing_input_round',
            $remoteSortPhases
        );
        $this->assertContains(
            'removing_input_round',
            $conflictSortPhases
        );
        $this->assertSame(
            $remotePaths,
            $this->readIndexPaths($conflictsPath)
        );
    }

    private function assertNewDirectoryRootConflictsWithRemoteScalar(
        string $scenario,
        string $localTree
    ): void {
        $previousLocalIndex =
            $this->tempDir . '/' . $scenario . '-previous-local.jsonl';
        file_put_contents($previousLocalIndex, '');

        $remoteDocumentRoot = '/var/www/html';
        $remotePath = $remoteDocumentRoot . '/collision';
        $previousRemoteIndex =
            $this->tempDir . '/' . $scenario . '-previous-remote.jsonl';
        $currentRemoteIndex =
            $this->tempDir . '/' . $scenario . '-current-remote.jsonl';
        file_put_contents($previousRemoteIndex, '');
        $this->writeRemoteIndex($currentRemoteIndex, [$remotePath]);

        $planDirectory =
            $this->tempDir . '/' . $scenario . '-plan';
        mkdir($planDirectory, 0755, true);
        $conflictsPath =
            $this->tempDir . '/' . $scenario . '-conflicts.jsonl';
        $plannedLocalIndexPath =
            $this->tempDir
            . '/'
            . $scenario
            . '-planned-local.jsonl';
        $processor = FilesPullConflictProcessor::start(
            $planDirectory,
            $localTree,
            $previousLocalIndex,
            $previousRemoteIndex,
            $currentRemoteIndex,
            $remoteDocumentRoot,
            [],
            $conflictsPath,
            $plannedLocalIndexPath
        );

        $stepCount = 0;
        while ($processor->next_step()) {
            ++$stepCount;
            $this->assertLessThan(1000, $stepCount);
        }
        $processor->close();

        $localChanges = $this->readIndexPaths(
            $planDirectory
                . '/local_changes.depth-first.jsonl'
        );
        $this->assertSame('collision', $localChanges[0] ?? null);
        $this->assertSame(
            [$remotePath],
            $this->readIndexPaths($conflictsPath)
        );
        $this->assertContains(
            $remotePath,
            $this->readIndexPaths($plannedLocalIndexPath)
        );
    }

    /**
     * Writes local index entries from the state currently on disk.
     *
     * @param list<string> $relativePaths Depth-first relative paths.
     */
    private function writeLocalIndex(
        string $indexPath,
        string $localTree,
        array $relativePaths
    ): void {
        $lines = '';
        foreach ($relativePaths as $relativePath) {
            $path = $localTree . '/' . $relativePath;
            $stat = lstat($path);
            $this->assertIsArray($stat);
            $type = is_dir($path) ? 'dir' : 'file';
            $entry = [
                'path' => base64_encode($relativePath),
                'ctime' => (int) $stat['ctime'],
                'size' => (int) $stat['size'],
                'type' => $type,
            ];
            if ($type === 'dir') {
                $entry['empty'] = false;
            }
            $lines .= json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        }
        file_put_contents($indexPath, $lines);
    }

    /**
     * Writes an index in the exact order emitted by FileIndexProcessor.
     *
     * @return list<string> Absolute paths in endpoint traversal order.
     */
    private function writeEndpointOrderRemoteIndex(
        string $indexPath,
        string $remoteTree
    ): array {
        $canonicalRemoteTree = realpath($remoteTree);
        $this->assertIsString($canonicalRemoteTree);
        $processor = FileIndexProcessor::start(
            [$canonicalRemoteTree],
            $canonicalRemoteTree,
            false,
            true,
            ''
        );
        $paths = [];
        $lines = '';
        try {
            while ($processor->next_index_step()) {
                $this->assertNotSame(
                    FileIndexProcessor::STATUS_DIRECTORY_ERROR,
                    $processor->get_step_status()
                );
                if (
                    $processor->get_step_status()
                    !== FileIndexProcessor::STATUS_INDEXED
                ) {
                    continue;
                }
                foreach ($processor->get_index_entries() as $indexEntry) {
                    $entry = [
                        'path' => base64_encode($indexEntry['path']),
                        'ctime' => $indexEntry['ctime'],
                        'size' => $indexEntry['size'],
                        'type' => $indexEntry['type'],
                    ];
                    if (isset($indexEntry['target'])) {
                        $entry['target'] =
                            base64_encode($indexEntry['target']);
                    }
                    if (!empty($indexEntry['intermediate'])) {
                        $entry['intermediate'] = true;
                    }
                    if (isset($indexEntry['empty'])) {
                        $entry['empty'] = $indexEntry['empty'];
                    }
                    $lines .= json_encode(
                        $entry,
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ) . "\n";
                    $paths[] = $indexEntry['path'];
                }
            }
        } finally {
            $processor->close();
        }
        file_put_contents($indexPath, $lines);
        return $paths;
    }

    /**
     * Writes a raw-path-sorted remote index.
     *
     * @param list<string> $paths Absolute remote paths.
     */
    private function writeRemoteIndex(
        string $indexPath,
        array $paths,
        int $directoryCtime = 10,
        int $directorySize = 0
    ): void {
        $lines = '';
        foreach ($paths as $path) {
            $isDirectory = substr($path, -strlen('/tree')) === '/tree';
            $lines .= json_encode(
                [
                    'path' => base64_encode($path),
                    'ctime' =>
                        $isDirectory ? $directoryCtime : 10,
                    'size' =>
                        $isDirectory ? $directorySize : 4,
                    'type' => $isDirectory ? 'dir' : 'file',
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        }
        file_put_contents($indexPath, $lines);
    }

    /** @return list<string> */
    private function readIndexPaths(string $indexPath): array
    {
        $paths = [];
        $handle = fopen($indexPath, 'rb');
        $this->assertIsResource($handle);
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $entry = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $path = base64_decode($entry['path'], true);
            $this->assertIsString($path);
            $paths[] = $path;
        }
        fclose($handle);
        return $paths;
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->deleteTree($path . '/' . $entry);
                }
            }
        }
        @rmdir($path);
    }
}
