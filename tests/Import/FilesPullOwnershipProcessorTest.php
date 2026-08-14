<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the following line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-files-pull-ownership-processor.php';

final class FilesPullOwnershipProcessorTest extends TestCase
{
    private string $tempDir;
    private string $nextRemoteIndexPath;
    private string $traversalJournalPath;
    private string $ownershipDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/files-pull-ownership-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->nextRemoteIndexPath = $this->tempDir . '/remote-index.next.jsonl';
        $this->traversalJournalPath = $this->tempDir . '/traversals.jsonl';
        $this->ownershipDirectory = $this->tempDir . '/ownership';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testBuildsByteSafeOwnershipAndFixedWidthLookup(): void
    {
        [$journalByteOffset, $indexByteOffset] = $this->writeTwoTraversals();

        $processor = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        $steps = 0;
        while ($processor->next_step()) {
            ++$steps;
        }
        $snapshotId = $processor->get_snapshot_id();
        $this->assertFalse($processor->next_step());
        $processor->close();
        $processor->close();

        $this->assertGreaterThan(10, $steps);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $snapshotId);
        $pathsFile = $this->snapshotPathsFile($snapshotId);
        $lookupFile = $this->snapshotLookupFile($snapshotId);
        $atoms = $this->readAtoms($pathsFile);
        $this->assertSame([
            ['kind' => 'ancestor', 'path' => '/'],
            ['kind' => 'ancestor', 'path' => '/outside'],
            ['kind' => 'exact', 'path' => '/outside-alias'],
            ['kind' => 'root', 'path' => '/outside/target'],
            ['kind' => 'ancestor', 'path' => '/srv'],
            ['kind' => 'ancestor', 'path' => "/srv/\xFFsite"],
            ['kind' => 'root', 'path' => "/srv/\xFFsite/child"],
        ], $atoms);
        $this->assertNotContains(
            ['kind' => 'root', 'path' => '/outside-alias-target'],
            $atoms
        );
        $this->assertLookupMatchesAtoms($pathsFile, $lookupFile, $atoms);
    }

    public function testResumeTruncatesUnconfirmedOutputAndPublishesSameContent(): void
    {
        [$journalByteOffset, $indexByteOffset] = $this->writeTwoTraversals();
        $processor = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        $this->assertTrue($processor->next_step());
        $this->assertTrue($processor->next_step());
        $cursor = $processor->get_cursor();
        $this->assertSame(base64_encode("/srv/\xFFsite"), $cursor['pending_atom_path_b64']);
        $cursorJson = json_encode($cursor);
        if (!is_string($cursorJson)) {
            $this->fail('Ownership cursor was not JSON encodable.');
        }
        $cursor = json_decode($cursorJson, true);
        $this->assertIsArray($cursor);
        $processor->close();
        file_put_contents(
            $this->ownershipDirectory . '/work/paths.next.jsonl',
            json_encode([
                'kind' => 'root',
                'path_b64' => base64_encode('/unconfirmed'),
            ]) . "\n",
            FILE_APPEND
        );

        $resumed = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            $cursor
        );
        while ($resumed->next_step()) {
            $cursor = $resumed->get_cursor();
        }
        $resumedSnapshotId = $resumed->get_snapshot_id();
        $completeCursor = $resumed->get_cursor();
        $resumed->close();
        $resumedPaths = file_get_contents(
            $this->snapshotPathsFile($resumedSnapshotId)
        );
        $resumedLookup = file_get_contents(
            $this->snapshotLookupFile($resumedSnapshotId)
        );
        $this->assertNotContains(
            ['kind' => 'root', 'path' => '/unconfirmed'],
            $this->readAtoms($this->snapshotPathsFile($resumedSnapshotId))
        );

        $cleanOwnershipDirectory = $this->tempDir . '/clean-ownership';
        $clean = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $cleanOwnershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        while ($clean->next_step()) {
            $this->assertNotSame('complete', $clean->get_cursor()['phase']);
        }
        $cleanSnapshotId = $clean->get_snapshot_id();
        $this->assertSame(
            $resumedPaths,
            file_get_contents($this->snapshotPathsFile(
                $cleanSnapshotId,
                $cleanOwnershipDirectory
            ))
        );
        $this->assertSame(
            $resumedLookup,
            file_get_contents($this->snapshotLookupFile(
                $cleanSnapshotId,
                $cleanOwnershipDirectory
            ))
        );
        $clean->close();

        $completed = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            $completeCursor
        );
        $this->assertFalse($completed->next_step());
        $this->assertSame($resumedSnapshotId, $completed->get_snapshot_id());
        $completed->close();
    }

    public function testLargeInputPerformsAtMostOneAtomWritePerScanningStep(): void
    {
        $lines = [];
        for ($index = 0; $index < 64; ++$index) {
            $lines[] = $this->indexLine(
                '/aliases/' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
                'link',
                true
            );
        }
        file_put_contents($this->nextRemoteIndexPath, implode('', $lines));
        $journalByteOffset = $this->writeJournal([
            [0, filesize($this->nextRemoteIndexPath), ['/source']],
        ]);
        $processor = $this->newProcessor(
            $journalByteOffset,
            filesize($this->nextRemoteIndexPath),
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        $steps = 0;
        while (true) {
            $phase = $processor->get_cursor()['phase'];
            $before = $processor->get_cursor()['paths_byte_offset'];
            $hasNext = $processor->next_step();
            $after = $processor->get_cursor()['paths_byte_offset'];
            if ($phase === 'scanning') {
                $this->assertLessThanOrEqual(1, $this->countNewlinesBetween($before, $after));
            }
            ++$steps;
            if (!$hasNext) {
                break;
            }
        }
        $this->assertGreaterThan(64 * 3, $steps);
        $processor->close();
    }

    public function testResumesEveryNestedSortAndPublicationBoundary(): void
    {
        $lines = [];
        for ($index = 0; $index < 60; ++$index) {
            $lines[] = $this->indexLine(
                '/aliases/' . sprintf('%03d', $index) . str_repeat('x', 19980),
                'link',
                true
            );
        }
        file_put_contents($this->nextRemoteIndexPath, implode('', $lines));
        $indexByteOffset = filesize($this->nextRemoteIndexPath);
        $journalByteOffset = $this->writeJournal([
            [0, $indexByteOffset, ['/source']],
        ]);
        $processor = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        $checkpointPhases = [];
        while (true) {
            $beforePhase = $processor->get_checkpoint_phase();
            $hasNext = $processor->next_step();
            $afterPhase = $processor->get_checkpoint_phase();
            if ($afterPhase !== $beforePhase) {
                $checkpointPhases[] = $afterPhase;
                if ($hasNext) {
                    $savedCursor = $this->jsonCursor(
                        $processor->get_cursor()
                    );
                    $allocatedSnapshotId = $savedCursor['snapshot_id'];
                    if (in_array($afterPhase, ['snapshot_prepared', 'paths_published'], true)) {
                        $this->assertIsString($allocatedSnapshotId);
                    }
                    if ($afterPhase === 'snapshot_prepared') {
                        $this->assertFileDoesNotExist(
                            $this->snapshotPathsFile($allocatedSnapshotId)
                        );
                        $this->assertFileDoesNotExist(
                            $this->snapshotLookupFile($allocatedSnapshotId)
                        );
                    }
                    $processor->next_step();
                    if ($afterPhase === 'snapshot_prepared') {
                        $this->assertFileExists(
                            $this->snapshotPathsFile($allocatedSnapshotId)
                        );
                        $this->assertFileDoesNotExist(
                            $this->snapshotLookupFile($allocatedSnapshotId)
                        );
                    } elseif ($afterPhase === 'paths_published') {
                        $this->assertFileExists(
                            $this->snapshotLookupFile($allocatedSnapshotId)
                        );
                    }
                    $processor->close();
                    $processor = $this->newProcessor(
                        $journalByteOffset,
                        $indexByteOffset,
                        $this->ownershipDirectory,
                        $savedCursor
                    );
                }
            }
            if (!$hasNext) {
                break;
            }
        }
        $snapshotId = $processor->get_snapshot_id();
        $processor->close();

        foreach ([
            'sorting_paths:merge_pass_complete',
            'sorting_paths:publishing_output',
            'sorting_paths:cleaning_work_files',
            'sorting_lookup:publishing_output',
            'sorting_lookup:cleaning_work_files',
            'snapshot_prepared',
            'paths_published',
            'lookup_published',
            'cleaning_work_files',
            'complete',
        ] as $phase) {
            $this->assertContains($phase, $checkpointPhases);
        }
        $atoms = $this->readAtoms($this->snapshotPathsFile($snapshotId));
        $this->assertCount(63, $atoms);
        $this->assertLookupMatchesAtoms(
            $this->snapshotPathsFile($snapshotId),
            $this->snapshotLookupFile($snapshotId),
            $atoms
        );
    }

    public function testLookupSortCursorBindsDeduplicationDisabled(): void
    {
        [$journalByteOffset, $indexByteOffset] = $this->writeTwoTraversals();
        $processor = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        while ($processor->get_cursor()['phase'] !== 'sorting_lookup') {
            $this->assertTrue($processor->next_step());
        }
        $sortCursor = $processor->get_cursor()['lookup_sort_cursor'];
        $processor->close();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('configuration changed');
        \ExternalSortProcessor::resume(
            $this->ownershipDirectory . '/work/lookup.next',
            $this->ownershipDirectory . '/work/lookup.sorted',
            $this->ownershipDirectory . '/work',
            'ownership-lookup',
            static fn(string $line): string => substr($line, 0, 64),
            true,
            $sortCursor
        );
    }

    public function testSnapshotRemovalResumesBetweenArtifactsAndIsIdempotent(): void
    {
        [$journalByteOffset, $indexByteOffset] = $this->writeTwoTraversals();
        $processor = $this->newProcessor(
            $journalByteOffset,
            $indexByteOffset,
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );
        while ($processor->next_step()) {
            $this->assertNotSame('complete', $processor->get_cursor()['phase']);
        }
        $snapshotId = $processor->get_snapshot_id();
        $processor->close();

        unlink($this->snapshotPathsFile($snapshotId));
        $this->assertFileExists($this->snapshotLookupFile($snapshotId));
        \FilesPullOwnershipProcessor::remove_snapshot(
            $this->ownershipDirectory,
            $snapshotId
        );
        $this->assertFileDoesNotExist($this->snapshotLookupFile($snapshotId));
        \FilesPullOwnershipProcessor::remove_snapshot(
            $this->ownershipDirectory,
            $snapshotId
        );
    }

    public function testRejectsTraversalGapBeforeReadingRawIndex(): void
    {
        $line = $this->indexLine('/source/file.txt');
        file_put_contents($this->nextRemoteIndexPath, $line);
        $journalByteOffset = $this->writeJournal([
            [1, strlen($line), ['/source']],
        ]);
        $processor = $this->newProcessor(
            $journalByteOffset,
            strlen($line),
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('contiguous');
        try {
            $processor->next_step();
        } finally {
            $processor->close();
        }
    }

    public function testRejectsOversizedRawIndexRowWithoutUnboundedRead(): void
    {
        $line = $this->indexLine('/' . str_repeat('x', 50000));
        $this->assertGreaterThan(65536, strlen($line));
        file_put_contents($this->nextRemoteIndexPath, $line);
        $journalByteOffset = $this->writeJournal([
            [0, strlen($line), ['/source']],
        ]);
        $processor = $this->newProcessor(
            $journalByteOffset,
            strlen($line),
            $this->ownershipDirectory,
            \FilesPullOwnershipProcessor::initial_cursor()
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'remote-index row at byte offset 0 exceeds the maximum of 65536 bytes'
        );
        try {
            while ($processor->next_step()) {
                $this->assertSame('scanning', $processor->get_cursor()['phase']);
            }
        } finally {
            $processor->close();
        }
    }

    public function testResumeRejectsNumericStringCursorOffset(): void
    {
        file_put_contents($this->nextRemoteIndexPath, '');
        $journalByteOffset = $this->writeJournal([[0, 0, ['/source']]]);
        $cursor = \FilesPullOwnershipProcessor::initial_cursor();
        $cursor['paths_byte_offset'] = '0';

        $this->expectException(\InvalidArgumentException::class);
        $this->newProcessor(
            $journalByteOffset,
            0,
            $this->ownershipDirectory,
            $cursor
        );
    }

    /** @dataProvider invalidSnapshotCursorProvider */
    public function testSnapshotAccessorRejectsInvalidCursor(array $cursor): void
    {
        $this->expectException(\UnexpectedValueException::class);
        \FilesPullOwnershipProcessor::snapshot_id_from_cursor($cursor);
    }

    public static function invalidSnapshotCursorProvider(): array
    {
        $unknownPhase = \FilesPullOwnershipProcessor::initial_cursor();
        $unknownPhase['phase'] = 'future';
        $missingId = \FilesPullOwnershipProcessor::initial_cursor();
        unset($missingId['snapshot_id']);
        $idInScanning = \FilesPullOwnershipProcessor::initial_cursor();
        $idInScanning['snapshot_id'] = str_repeat('a', 64);
        return [
            'unknown phase' => [$unknownPhase],
            'missing ID field' => [$missingId],
            'ID outside publication' => [$idInScanning],
        ];
    }

    /** @return array{int,int} */
    private function writeTwoTraversals(): array
    {
        $firstLines = $this->indexLine("/srv/\xFFsite/child/index.php")
            . $this->indexLine('/outside-alias', 'link', true);
        $secondLines = $this->indexLine('/outside/target/data.bin');
        file_put_contents($this->nextRemoteIndexPath, $firstLines . $secondLines);
        $firstEnd = strlen($firstLines);
        $indexEnd = $firstEnd + strlen($secondLines);
        return [
            $this->writeJournal([
                [0, $firstEnd, ["/srv/\xFFsite/child"]],
                [$firstEnd, $indexEnd, ['/outside/target']],
            ]),
            $indexEnd,
        ];
    }

    /** @param array<int,array{int,int,list<string>}> $records */
    private function writeJournal(array $records): int
    {
        $journal = new \RemoteIndexTraversalJournal($this->traversalJournalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        $journalByteOffset = 0;
        foreach ($records as [$start, $end, $roots]) {
            $journalByteOffset = $journal->complete_traversal(
                $start,
                $end,
                $roots[0],
                $roots
            );
        }
        $journal->close();
        return $journalByteOffset;
    }

    private function indexLine(
        string $path,
        string $type = 'file',
        bool $intermediate = false
    ): string {
        $entry = [
            'path' => base64_encode($path),
            'ctime' => 1,
            'size' => 1,
            'type' => $type,
        ];
        if ($intermediate) {
            $entry['target'] = base64_encode('/outside-alias-target');
            $entry['intermediate'] = true;
        }
        return json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function newProcessor(
        int $journalByteOffset,
        int $indexByteOffset,
        string $ownershipDirectory,
        array $cursor
    ): \FilesPullOwnershipProcessor {
        return \FilesPullOwnershipProcessor::resume(
            $this->traversalJournalPath,
            $journalByteOffset,
            $this->nextRemoteIndexPath,
            $indexByteOffset,
            $ownershipDirectory,
            $cursor
        );
    }

    private function jsonCursor(array $cursor): array
    {
        $json = json_encode($cursor);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /** @return list<array{kind:string,path:string}> */
    private function readAtoms(string $pathsFile): array
    {
        $atoms = [];
        foreach (file($pathsFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $atom = json_decode($line, true);
            $atoms[] = [
                'kind' => $atom['kind'],
                'path' => base64_decode($atom['path_b64'], true),
            ];
        }
        return $atoms;
    }

    private function assertLookupMatchesAtoms(
        string $pathsFile,
        string $lookupFile,
        array $atoms
    ): void {
        $records = file($lookupFile) ?: [];
        $this->assertCount(count($atoms), $records);
        $previousDigest = null;
        $pathsHandle = fopen($pathsFile, 'rb');
        foreach ($records as $record) {
            $this->assertSame(1, preg_match(
                '/^([0-9a-f]{64}) ([0-9a-f]{16})\n$/D',
                $record,
                $matches
            ));
            $this->assertTrue(
                $previousDigest === null
                || strcmp($previousDigest, $matches[1]) < 0
            );
            $previousDigest = $matches[1];
            fseek($pathsHandle, intval($matches[2], 16));
            $atom = json_decode( (string) fgets($pathsHandle), true );
            $path = base64_decode($atom['path_b64'], true);
            $this->assertSame(
                $matches[1],
                hash('sha256', $atom['kind'] . "\0" . $path)
            );
        }
        fclose($pathsHandle);
    }

    private function snapshotPathsFile(
        string $snapshotId,
        ?string $ownershipDirectory = null
    ): string
    {
        return ( $ownershipDirectory ?? $this->ownershipDirectory )
            . '/snapshots/' . $snapshotId . '.paths.jsonl';
    }

    private function snapshotLookupFile(
        string $snapshotId,
        ?string $ownershipDirectory = null
    ): string
    {
        return ( $ownershipDirectory ?? $this->ownershipDirectory )
            . '/snapshots/' . $snapshotId . '.lookup';
    }

    private function countNewlinesBetween(int $before, int $after): int
    {
        if ($after <= $before) {
            return 0;
        }
        $handle = fopen($this->ownershipDirectory . '/work/paths.next.jsonl', 'rb');
        fseek($handle, $before);
        $bytes = fread($handle, $after - $before);
        fclose($handle);
        return substr_count( (string) $bytes, "\n" );
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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
