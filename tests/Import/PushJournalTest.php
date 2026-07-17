<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php';

/**
 * Coverage for PushJournal's per-target baseline and bounded local planner.
 *
 * Planning merges the current index, whose directory entries carry an `empty`
 * boolean from the indexer, with the last successful baseline. The tests pin
 * the resulting positive-work JSONL, raw NUL-delimited work deletes,
 * checkpoint replay, and every transition among files, symlinks, empty
 * directories, and non-empty directories.
 */
final class PushJournalTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/push-journal-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Site keys
    // ------------------------------------------------------------------

    public function testSiteKeyIdentifiesTheSiteNotTheUrlSpelling(): void
    {
        $canonical = PushJournal::site_key('https://example.com/blog');

        // Spelling variants of the same site map to the same directory.
        $this->assertSame($canonical, PushJournal::site_key('http://example.com/blog'));
        $this->assertSame($canonical, PushJournal::site_key('https://EXAMPLE.com/blog/'));
        $this->assertSame($canonical, PushJournal::site_key('https://example.com/blog?preview=1'));
        $this->assertSame($canonical, PushJournal::site_key('example.com/blog'));

        // Different sites map to different directories.
        $this->assertNotSame($canonical, PushJournal::site_key('https://example.com'));
        $this->assertNotSame($canonical, PushJournal::site_key('https://example.com:8080/blog'));
        $this->assertNotSame($canonical, PushJournal::site_key('https://example.org/blog'));

        // The slug part stays readable.
        $this->assertStringStartsWith('example.com-blog-', $canonical);
    }

    public function testSiteKeyRejectsUrlsWithoutAHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no host');
        PushJournal::site_key('/just/a/path');
    }

    // ------------------------------------------------------------------
    //  Baselines
    // ------------------------------------------------------------------

    public function testCaptureCreatesAndOverwritesTheBaseline(): void
    {
        $journal = $this->makeJournal();
        $this->assertFileDoesNotExist($journal->local_files_baseline_path);

        $journal->capture_local_files_baseline($this->writeIndex([
            'a.txt' => [100, 5, 'file'],
        ]));
        $this->assertFileExists($journal->local_files_baseline_path);
        $this->assertFileDoesNotExist($journal->local_files_baseline_path . '.tmp');

        // A second capture replaces the first: planning from an identical
        // second capture produces no work.
        $second = $this->writeIndex(['b.txt' => [200, 9, 'file']]);
        $journal->capture_local_files_baseline($second);
        $result = $this->planToCompletion($journal, $second);
        $this->assertPlanningCounts(0, 0, $result);
    }

    public function testCaptureRequiresTheIndexFileToExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('index file is missing');
        $this->makeJournal()->capture_local_files_baseline($this->tempDir . '/no-such-index.jsonl');
    }

    // ------------------------------------------------------------------
    //  Local planning
    // ------------------------------------------------------------------

    public function testFirstPushTreatsOnlyInstallableEntriesAsChanged(): void
    {
        $index = $this->writeIndex([
            'index.php' => [100, 5, 'file'],
            'wp-content' => [100, 0, 'dir', false],
            'wp-content/themes/foo/style.css' => [150, 5, 'file'],
        ]);

        $journal = $this->makeJournal();
        $result = $this->planToCompletion($journal, $index);

        $this->assertPlanningCounts(2, 0, $result);
        $this->assertSame(
            ['index.php', 'wp-content/themes/foo/style.css'],
            $this->listPaths($journal->local_paths_to_push)
        );
        $this->assertSame('', file_get_contents($journal->work_deletes_path));

        // Pin the positive-work representation: it remains base64-path JSONL.
        $pathsToPush = file_get_contents($journal->local_paths_to_push);
        $this->assertIsString($pathsToPush);
        $firstLine = strtok($pathsToPush, "\n");
        $this->assertSame('{"path":"' . base64_encode('index.php') . '"}', $firstLine);
    }

    public function testPlanningCopiesEverySourceIndexEntryAndExcludesOnlyWork(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'gone.txt' => [1, 1, 'file'],
            'private' => [1, 0, 'dir', false],
            'private/gone.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex([
            'empty' => [2, 0, 'dir', true],
            'full' => [2, 0, 'dir', false],
            'full/child.txt' => [2, 5, 'file'],
            'private' => [2, 0, 'dir', false],
            'private/current.txt' => [2, 7, 'file'],
            'public.txt' => [2, 6, 'file'],
        ]);

        $result = $this->planToCompletion($journal, $current, ['private']);

        $this->assertPlanningCounts(3, 1, $result);
        $this->assertSame(['empty', 'full/child.txt', 'public.txt'], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame("gone.txt\0", file_get_contents($journal->work_deletes_path));

        $senderEntries = $this->indexEntries($journal->sender_index_path);
        $this->assertSame(
            ['empty', 'full', 'full/child.txt', 'private', 'private/current.txt', 'public.txt'],
            array_keys($senderEntries)
        );
        $this->assertTrue($senderEntries['empty']['empty']);
        $this->assertFalse($senderEntries['full']['empty']);
        $this->assertFalse($senderEntries['private']['empty']);
        $this->assertArrayNotHasKey('empty', $senderEntries['public.txt']);
    }

    public function testUnchangedIndexProducesEmptyPlans(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex([
            'a.txt' => [100, 5, 'file'],
            'b/c.txt' => [200, 7, 'file'],
        ]);
        $journal->capture_local_files_baseline($index);

        $result = $this->planToCompletion($journal, $index);

        $this->assertPlanningCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame('', file_get_contents($journal->work_deletes_path));
    }

    public function testCtimeSizeOrTypeChangeEachMarksThePathChanged(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'ctime-bump.txt' => [100, 5, 'file'],
            'size-bump.txt' => [100, 5, 'file'],
            'type-swap' => [100, 5, 'file'],
            'same.txt' => [100, 5, 'file'],
        ]));
        $current = $this->writeIndex([
            'ctime-bump.txt' => [101, 5, 'file'],
            'size-bump.txt' => [100, 6, 'file'],
            'type-swap' => [100, 5, 'link'],
            'same.txt' => [100, 5, 'file'],
        ]);

        $result = $this->planToCompletion($journal, $current);

        $this->assertPlanningCounts(3, 0, $result);
        $this->assertSame(
            ['ctime-bump.txt', 'size-bump.txt', 'type-swap'],
            $this->listPaths($journal->local_paths_to_push)
        );
    }

    public function testEveryLogicalTypeTransitionEmitsOnlyTheRequiredDeleteAndInstallWork(): void
    {
        $matrix = [
            'file' => [
                'file' => [['value'], []],
                'symlink' => [['value'], []],
                'empty_directory' => [['value'], ['value']],
                'non_empty_directory' => [['value/child.txt'], ['value']],
            ],
            'symlink' => [
                'file' => [['value'], []],
                'symlink' => [['value'], []],
                'empty_directory' => [['value'], ['value']],
                'non_empty_directory' => [['value/child.txt'], ['value']],
            ],
            'empty_directory' => [
                'file' => [['value'], ['value']],
                'symlink' => [['value'], ['value']],
                'empty_directory' => [[], []],
                'non_empty_directory' => [['value/child.txt'], []],
            ],
            'non_empty_directory' => [
                'file' => [['value'], ['value']],
                'symlink' => [['value'], ['value']],
                'empty_directory' => [['value'], ['value']],
                'non_empty_directory' => [['value/child.txt'], []],
            ],
        ];

        foreach ($matrix as $previousType => $transitions) {
            foreach ($transitions as $currentType => [$expectedPushes, $expectedDeletes]) {
                $journal = $this->makeJournal();
                $journal->capture_local_files_baseline($this->writeLogicalIndex($previousType, 1));
                $current = $this->writeLogicalIndex($currentType, 2);

                $result = $this->planToCompletion($journal, $current);
                $message = $previousType . ' to ' . $currentType;

                $this->assertSame($expectedPushes, $this->listPaths($journal->local_paths_to_push), $message);
                $this->assertSame($expectedDeletes, $this->workDeletePaths($journal->work_deletes_path), $message);
                $this->assertPlanningCounts(count($expectedPushes), count($expectedDeletes), $result, $message);
            }
        }
    }

    public function testDeletedSubtreeEmitsOnlyItsRootAsRawWorkDeletes(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'gone' => [1, 0, 'dir', false],
            'gone/child.txt' => [1, 1, 'file'],
            'gone/nested' => [1, 0, 'dir', false],
            'gone/nested/leaf.txt' => [1, 1, 'file'],
            'stays.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex(['stays.txt' => [1, 1, 'file']]);

        $result = $this->planToCompletion($journal, $current);

        $this->assertPlanningCounts(0, 1, $result);
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame("gone\0", file_get_contents($journal->work_deletes_path));
    }

    public function testActiveWorkDeleteRootSurvivesAnInterleavedSiblingCheckpoint(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'a' => [1, 0, 'dir', false],
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $entries = [];
        for ($index = 0; $index < 1000; ++$index) {
            // `-` sorts before `/`, placing these siblings between a and a/child.txt.
            $entries[sprintf('a-%04d.txt', $index)] = [2, 1, 'file'];
        }
        $current = $this->writeIndex($entries);

        $initial = $journal->diff_local_files($current);
        $this->assertInitialPlanningCheckpoint($initial);
        $first = $journal->diff_local_files($current, [], $initial['checkpoint']);

        $this->assertSame('planning', $first['status']);
        $this->assertSame([base64_encode('a')], $first['checkpoint']['active_work_delete_roots_b64']);
        $result = $this->planToCompletion($journal, $current, [], $first['checkpoint']);

        $this->assertPlanningCounts(1000, 1, $result);
        $this->assertSame("a\0", file_get_contents($journal->work_deletes_path));
        $this->assertCount(1000, $this->listPaths($journal->local_paths_to_push));
    }

    public function testReplacementRootSurvivesLexicallyInterleavedSiblingPaths(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'a' => [1, 0, 'dir', false],
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex([
            'a' => [2, 1, 'file'],
            // `-` sorts before `/`, so this sibling appears before a/child.txt.
            'a-other' => [2, 1, 'file'],
        ]);

        $result = $this->planToCompletion($journal, $current);

        $this->assertPlanningCounts(2, 1, $result);
        $this->assertSame(['a', 'a-other'], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame("a\0", file_get_contents($journal->work_deletes_path));
    }

    public function testNewChangedDeletedAndUnchangedPathsArePlannedTogether(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'changed.txt' => [100, 5, 'file'],
            'deleted.txt' => [100, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]));
        $current = $this->writeIndex([
            'added.txt' => [300, 3, 'file'],
            'changed.txt' => [200, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]);

        $result = $this->planToCompletion($journal, $current);

        $this->assertPlanningCounts(2, 1, $result);
        // Output order follows decoded path order from the indexes.
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame("deleted.txt\0", file_get_contents($journal->work_deletes_path));
    }

    public function testAPlanningRunWithoutACheckpointReplacesEarlierOutput(): void
    {
        $journal = $this->makeJournal();
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);

        $this->planToCompletion($journal, $index);
        $this->assertSame(['a.txt'], $this->listPaths($journal->local_paths_to_push));

        $journal->capture_local_files_baseline($index);
        $result = $this->planToCompletion($journal, $index);

        $this->assertPlanningCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame('', file_get_contents($journal->work_deletes_path));
    }

    public function testPathsThatNeedBase64SurvivePositiveAndDeletePlans(): void
    {
        // Newlines and non-ASCII bytes are why JSONL indexes encode paths.
        $newPath = "wp-content/uploads/line\nbreak.png";
        $deletedPath = 'wp-content/uploads/naïve-café.jpg';
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            $deletedPath => [100, 6, 'file'],
        ]));
        $current = $this->writeIndex([
            $newPath => [100, 5, 'file'],
        ]);

        $result = $this->planToCompletion($journal, $current);

        $this->assertPlanningCounts(1, 1, $result);
        $this->assertSame([$newPath], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame($deletedPath . "\0", file_get_contents($journal->work_deletes_path));
    }

    public function testPlanningParsesJsonWithoutDependingOnFieldOrderOrEscaping(): void
    {
        $journal = $this->makeJournal();
        $path = 'wp-content/???';
        $base64Path = base64_encode($path);
        $baseline = $this->tempDir . '/baseline-shape.jsonl';
        $current = $this->tempDir . '/current-shape.jsonl';

        file_put_contents(
            $baseline,
            json_encode(['path' => $base64Path, 'ctime' => 100, 'size' => 5, 'type' => 'file'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents(
            $current,
            json_encode(['type' => 'file', 'size' => 5, 'ctime' => 100, 'path' => $base64Path], JSON_THROW_ON_ERROR) . "\n"
        );
        $journal->capture_local_files_baseline($baseline);

        $result = $this->planToCompletion($journal, $current);

        $this->assertPlanningCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($journal->local_paths_to_push));
        $this->assertSame('', file_get_contents($journal->work_deletes_path));
    }

    // ------------------------------------------------------------------
    //  Bounded progress and restart
    // ------------------------------------------------------------------

    public function testInitializationPinsTheIndexWithoutConsumingAndClearsOldOutput(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'gone.txt' => [1, 1, 'file'],
        ]));
        $oldIndex = $this->writeIndex(['old.txt' => [1, 1, 'file']]);
        $this->planToCompletion($journal, $oldIndex);
        $this->assertGreaterThan(0, filesize($journal->sender_index_path));
        $this->assertGreaterThan(0, filesize($journal->local_paths_to_push));
        $this->assertGreaterThan(0, filesize($journal->work_deletes_path));

        $current = $this->writeIndex($this->manyFileEntries(1001));
        $initial = $journal->diff_local_files($current);

        $this->assertInitialPlanningCheckpoint($initial);
        $this->assertSame(filesize($current), $initial['checkpoint']['current_index_identity']['size']);
        $this->assertSame(0, filesize($journal->sender_index_path));
        $this->assertSame(0, filesize($journal->local_paths_to_push));
        $this->assertSame(0, filesize($journal->work_deletes_path));
    }

    public function testInitializationRejectsAReplacementBeforeTheFirstBatch(): void
    {
        $current = $this->tempDir . '/current-index.jsonl';
        copy($this->writeIndex(['value.txt' => [1, 1, 'file']]), $current);
        $journal = $this->makeJournal();
        $initial = $journal->diff_local_files($current);
        $this->assertInitialPlanningCheckpoint($initial);

        // Simulate a process dying after it persisted the empty checkpoint.
        // Even identical replacement bytes belong to a different generation.
        $replacement = $this->tempDir . '/replacement-index.jsonl';
        copy($current, $replacement);
        rename($replacement, $current);
        clearstatcache(true, $current);

        $changed = $journal->diff_local_files($current, [], $initial['checkpoint']);

        $this->assertSame('source_changed', $changed['status']);
        $this->assertSame($initial['checkpoint'], $changed['checkpoint']);
        $this->assertSame(0, filesize($journal->sender_index_path));
        $this->assertSame(0, filesize($journal->local_paths_to_push));
        $this->assertSame(0, filesize($journal->work_deletes_path));
    }

    public function testPlanningStopsAfterItsFixedRecordBudgetAndResumes(): void
    {
        $entries = $this->manyFileEntries(1001);
        $current = $this->writeIndex($entries);
        $journal = $this->makeJournal();

        $initial = $journal->diff_local_files($current);
        $this->assertInitialPlanningCheckpoint($initial);
        $first = $journal->diff_local_files($current, [], $initial['checkpoint']);

        $this->assertSame('planning', $first['status']);
        $checkpoint = $first['checkpoint'];
        $this->assertSame(1000, $checkpoint['changed']);
        $this->assertGreaterThan(0, $checkpoint['current_index_byte_offset']);
        $this->assertLessThan(filesize($current), $checkpoint['current_index_byte_offset']);
        $this->assertSame(0, $checkpoint['baseline_byte_offset']);
        $this->assertSame([], $checkpoint['active_work_delete_roots_b64']);
        $this->assertEqualsCanonicalizing(
            ['device', 'inode', 'size', 'ctime', 'mtime'],
            array_keys($checkpoint['current_index_identity'])
        );
        $this->assertSame(filesize($journal->sender_index_path), $checkpoint['sender_index_bytes']);
        $this->assertSame(filesize($journal->local_paths_to_push), $checkpoint['local_paths_to_push_bytes']);
        $this->assertSame(filesize($journal->work_deletes_path), $checkpoint['work_deletes_bytes']);

        $result = $this->planToCompletion($journal, $current, [], $checkpoint);

        $this->assertPlanningCounts(1001, 0, $result);
        $this->assertCount(1001, $this->listPaths($journal->local_paths_to_push));
        $this->assertCount(1001, $this->indexEntries($journal->sender_index_path));
    }

    public function testRestartTruncatesAndReplaysOutputWhoseCheckpointWasDiscarded(): void
    {
        $currentEntries = [];
        $baselineEntries = [];
        for ($index = 0; $index < 2001; ++$index) {
            // The current and deleted names alternate in decoded sort order,
            // so every batch appends to all three planning outputs.
            $currentEntries[sprintf('item-%04d-current.txt', $index)] = [$index + 1, 1, 'file'];
            $baselineEntries[sprintf('item-%04d-deleted.txt', $index)] = [$index + 1, 1, 'file'];
        }
        $current = $this->writeIndex($currentEntries);
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex($baselineEntries));

        $initial = $journal->diff_local_files($current);
        $this->assertInitialPlanningCheckpoint($initial);
        $first = $journal->diff_local_files($current, [], $initial['checkpoint']);
        $this->assertSame('planning', $first['status']);
        $durableCheckpoint = $first['checkpoint'];

        // These writes reached disk, but the caller dies before persisting the
        // returned checkpoint. A new process must discard and replay this tail.
        $discarded = $journal->diff_local_files($current, [], $durableCheckpoint);
        $this->assertSame('planning', $discarded['status']);
        $this->assertGreaterThan(
            $durableCheckpoint['sender_index_bytes'],
            filesize($journal->sender_index_path)
        );
        $this->assertGreaterThan(
            $durableCheckpoint['local_paths_to_push_bytes'],
            filesize($journal->local_paths_to_push)
        );
        $this->assertGreaterThan(
            $durableCheckpoint['work_deletes_bytes'],
            filesize($journal->work_deletes_path)
        );

        $reopened = $this->makeJournal();
        $replayed = $reopened->diff_local_files($current, [], $durableCheckpoint);
        $this->assertSame($discarded, $replayed);
        $result = $this->planToCompletion($reopened, $current, [], $replayed['checkpoint']);

        $this->assertPlanningCounts(2001, 2001, $result);
        $this->assertSame(array_keys($currentEntries), $this->listPaths($reopened->local_paths_to_push));
        $this->assertSame(array_keys($baselineEntries), $this->workDeletePaths($reopened->work_deletes_path));
        $this->assertCount(2001, $this->indexEntries($reopened->sender_index_path));
    }

    public function testCheckpointedProgressAndCompletedEofAreIdempotentAfterRestart(): void
    {
        $current = $this->writeIndex($this->manyFileEntries(1001));
        $journal = $this->makeJournal();
        $initial = $journal->diff_local_files($current);
        $this->assertInitialPlanningCheckpoint($initial);
        $first = $journal->diff_local_files($current, [], $initial['checkpoint']);
        $this->assertSame('planning', $first['status']);

        $reopened = $this->makeJournal();
        $complete = $reopened->diff_local_files($current, [], $first['checkpoint']);
        $this->assertSame('complete', $complete['status']);
        $senderIndex = file_get_contents($reopened->sender_index_path);
        $pathsToPush = file_get_contents($reopened->local_paths_to_push);
        $workDeletes = file_get_contents($reopened->work_deletes_path);

        $replayedEof = $this->makeJournal()->diff_local_files(
            $current,
            [],
            $complete['checkpoint']
        );

        $this->assertSame($complete, $replayedEof);
        $this->assertSame($senderIndex, file_get_contents($reopened->sender_index_path));
        $this->assertSame($pathsToPush, file_get_contents($reopened->local_paths_to_push));
        $this->assertSame($workDeletes, file_get_contents($reopened->work_deletes_path));
    }

    public function testReplacingTheCurrentIndexBetweenCheckpointsReturnsSourceChanged(): void
    {
        $current = $this->tempDir . '/current-index.jsonl';
        $source = $this->writeIndex($this->manyFileEntries(1001));
        copy($source, $current);
        $journal = $this->makeJournal();
        $initial = $journal->diff_local_files($current);
        $this->assertInitialPlanningCheckpoint($initial);
        $first = $journal->diff_local_files($current, [], $initial['checkpoint']);
        $this->assertSame('planning', $first['status']);

        // Keep the same bytes so device/inode identity, rather than content or
        // length, proves that a different index generation was installed.
        $replacement = $this->tempDir . '/replacement-index.jsonl';
        copy($current, $replacement);
        rename($replacement, $current);
        clearstatcache(true, $current);

        $changed = $journal->diff_local_files($current, [], $first['checkpoint']);

        $this->assertSame('source_changed', $changed['status']);
        $this->assertSame($first['checkpoint'], $changed['checkpoint']);
    }

    // ------------------------------------------------------------------
    //  Invalid input
    // ------------------------------------------------------------------

    public function testPlanningRejectsLinesTheIndexWritersDoNotProduce(): void
    {
        // A blank line means the file is not a JSONL index and planning stops
        // instead of silently skipping a possibly corrupt entry.
        $garbage = $this->tempDir . '/garbage.jsonl';
        file_put_contents(
            $garbage,
            $this->indexLine('a.txt', 100, 5, 'file') . "\n\nnot json at all\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->planToCompletion($this->makeJournal(), $garbage);
    }

    public function testPlanningRejectsAnUndecodablePath(): void
    {
        $bad = $this->tempDir . '/bad-path.jsonl';
        file_put_contents($bad, '{"path":"%%%not-base64%%%","ctime":1,"size":1,"type":"file"}' . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid index path');
        $this->planToCompletion($this->makeJournal(), $bad);
    }

    public function testPlanningRejectsABaselineDirectoryWithoutEmptyState(): void
    {
        $journal = $this->makeJournal();
        $journal->capture_local_files_baseline($this->writeIndex([
            'value' => [1, 0, 'dir'],
        ]));
        $current = $this->writeIndex([
            'value' => [2, 0, 'dir', true],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local baseline has no boolean empty');
        $this->planToCompletion($journal, $current);
    }

    public function testPlanningRejectsACurrentDirectoryWithoutEmptyState(): void
    {
        $current = $this->writeIndex([
            'value' => [2, 0, 'dir'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current index has no boolean empty');
        $this->planToCompletion($this->makeJournal(), $current);
    }

    public function testPlanningRequiresTheCurrentIndexToExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current index file is missing');
        $this->makeJournal()->diff_local_files($this->tempDir . '/no-such-index.jsonl');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function makeJournal(): PushJournal
    {
        return new PushJournal($this->tempDir . '/state', 'https://example.com/');
    }

    /**
     * Continue planning until the journal reports that all input was consumed.
     *
     * @param list<string> $excludedPaths Raw document-root-relative paths.
     * @param array<string,mixed>|null $checkpoint Last durable planning boundary.
     * @return array{status:string,checkpoint:array<string,mixed>}
     */
    private function planToCompletion(
        PushJournal $journal,
        string $currentIndex,
        array $excludedPaths = [],
        ?array $checkpoint = null
    ): array {
        for ($step = 0; $step < 100; ++$step) {
            $result = $journal->diff_local_files($currentIndex, $excludedPaths, $checkpoint);
            if ($result['status'] === 'complete') {
                return $result;
            }
            $this->assertSame('planning', $result['status']);
            $checkpoint = $result['checkpoint'];
        }
        $this->fail('Planning did not complete within 100 bounded steps.');
    }

    private function assertPlanningCounts(
        int $changed,
        int $deleted,
        array $result,
        string $message = ''
    ): void {
        $this->assertSame($changed, $result['checkpoint']['changed'], $message);
        $this->assertSame($deleted, $result['checkpoint']['deleted'], $message);
    }

    private function assertInitialPlanningCheckpoint(array $result): void
    {
        $this->assertSame('planning', $result['status']);
        $checkpoint = $result['checkpoint'];
        foreach (
            [
                'current_index_byte_offset',
                'baseline_byte_offset',
                'sender_index_bytes',
                'local_paths_to_push_bytes',
                'work_deletes_bytes',
                'changed',
                'deleted',
            ] as $zeroValue
        ) {
            $this->assertSame(0, $checkpoint[$zeroValue], $zeroValue);
        }
        $this->assertSame([], $checkpoint['active_work_delete_roots_b64']);
        $this->assertEqualsCanonicalizing(
            ['device', 'inode', 'size', 'ctime', 'mtime'],
            array_keys($checkpoint['current_index_identity'])
        );
    }

    private function writeLogicalIndex(string $logicalType, int $version): string
    {
        if ($logicalType === 'file') {
            return $this->writeIndex(['value' => [$version, $version, 'file']]);
        }
        if ($logicalType === 'symlink') {
            return $this->writeIndex(['value' => [$version, 0, 'link']]);
        }
        if ($logicalType === 'empty_directory') {
            return $this->writeIndex(['value' => [$version, 0, 'dir', true]]);
        }
        return $this->writeIndex([
            'value' => [$version, 0, 'dir', false],
            'value/child.txt' => [$version, $version, 'file'],
        ]);
    }

    /** @return array<string,array{0:int,1:int,2:string}> */
    private function manyFileEntries(int $count): array
    {
        $entries = [];
        for ($index = 0; $index < $count; ++$index) {
            $entries[sprintf('file-%04d.txt', $index)] = [$index + 1, 1, 'file'];
        }
        return $entries;
    }

    /**
     * Write a sorted index. Entries map path to ctime, size, type, and the
     * empty flag the indexer records on directory entries.
     *
     * @param array<string,array{0:int,1:int,2:string,3?:bool}> $entries
     */
    private function writeIndex(array $entries): string
    {
        uksort($entries, 'strcmp');
        $lines = '';
        foreach ($entries as $path => $entry) {
            $lines .= $this->indexLine(
                $path,
                $entry[0],
                $entry[1],
                $entry[2],
                $entry[3] ?? null
            ) . "\n";
        }
        $file = $this->tempDir . '/index-' . uniqid() . '.jsonl';
        file_put_contents($file, $lines);
        return $file;
    }

    private function indexLine(
        string $path,
        int $ctime,
        int $size,
        string $type,
        ?bool $directoryIsEmpty = null
    ): string {
        $entry = ['path' => base64_encode($path), 'ctime' => $ctime, 'size' => $size, 'type' => $type];
        if ($directoryIsEmpty !== null) {
            $entry['empty'] = $directoryIsEmpty;
        }
        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a positive-work JSONL list.
     *
     * @return list<string>
     */
    private function listPaths(string $file): array
    {
        $this->assertFileExists($file);
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $paths = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode($data['path'], true);
            $this->assertIsString($path);
            $paths[] = $path;
        }
        return $paths;
    }

    /** @return list<string> */
    private function workDeletePaths(string $file): array
    {
        $this->assertFileExists($file);
        $bytes = file_get_contents($file);
        $this->assertIsString($bytes);
        if ($bytes === '') {
            return [];
        }
        $paths = explode("\0", $bytes);
        $this->assertSame('', array_pop($paths), 'The work-delete stream must end at a NUL record boundary.');
        return $paths;
    }

    /** @return array<string,array<string,mixed>> Entries keyed by decoded path. */
    private function indexEntries(string $file): array
    {
        $this->assertFileExists($file);
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode($entry['path'], true);
            $this->assertIsString($path);
            $entries[$path] = $entry;
        }
        return $entries;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            if (is_link($dir) || is_file($dir)) {
                unlink($dir);
            }
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
