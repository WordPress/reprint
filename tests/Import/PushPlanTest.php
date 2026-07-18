<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-plan.php';

/**
 * Coverage for PushPlan's per-target local index at the previous push and bounded local planner.
 *
 * Planning merges the fresh local index, whose directory entries carry an `empty`
 * boolean from the indexer, with the local index at the previous push. The tests pin
 * the resulting local_paths_to_push JSONL, raw NUL-delimited local paths to delete,
 * cursor replay, and every transition among files, symlinks, empty
 * directories, and non-empty directories.
 */
final class PushPlanTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/push-plan-test-' . uniqid();
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

    // ------------------------------------------------------------------
    //  Successful push
    // ------------------------------------------------------------------

    public function testAfterSuccessfulPushCreatesAndOverwritesLocalIndexAtPreviousPush(): void
    {
        $localIndexAtPreviousPush = $this->tempDir . '/state/push/example.com/local_index_at_previous_push.jsonl';
        $this->assertFileDoesNotExist($localIndexAtPreviousPush);

        $plan = $this->recordSuccessfulPush($this->writeIndex([
            'a.txt' => [100, 5, 'file'],
        ]));
        $this->assertFileExists($plan->local_index_at_previous_push);
        $this->assertFileDoesNotExist($plan->local_index_at_previous_push . '.tmp');

        // A second successful push replaces the first. Comparing that same index
        // again produces no paths to push or delete.
        $second = $this->writeIndex(['b.txt' => [200, 9, 'file']]);
        $plan = $this->recordSuccessfulPush($second);
        $result = $this->planToCompletion($plan, $second);
        $this->assertPlanningCounts(0, 0, $result);
    }

    public function testAfterSuccessfulPushRequiresTheFreshLocalIndexToExist(): void
    {
        $plan = $this->makePlan();
        $plan->close();
        unlink($plan->fresh_local_index);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fresh local index is missing');
        $plan->after_successful_push();
    }

    // ------------------------------------------------------------------
    //  Local planning
    // ------------------------------------------------------------------

    public function testFirstPushSelectsFilesSymlinksAndEmptyDirectories(): void
    {
        $index = $this->writeIndex([
            'index.php' => [100, 5, 'file'],
            'wp-content' => [100, 0, 'dir', false],
            'wp-content/themes/foo/style.css' => [150, 5, 'file'],
        ]);

        $plan = $this->makePlan($index);
        $result = $this->planToCompletion($plan, $index);

        $this->assertPlanningCounts(2, 0, $result);
        $this->assertSame(
            ['index.php', 'wp-content/themes/foo/style.css'],
            $this->listPaths($plan->local_paths_to_push)
        );
        $this->assertSame('', file_get_contents($plan->local_paths_to_delete));

        // Pin the local_paths_to_push representation: it remains base64-path JSONL.
        $pathsToPush = file_get_contents($plan->local_paths_to_push);
        $this->assertIsString($pathsToPush);
        $firstLine = strtok($pathsToPush, "\n");
        $this->assertSame('{"path":"' . base64_encode('index.php') . '"}', $firstLine);
    }

    public function testPlanningCopiesEverySourceIndexEntryAndExcludesOnlyPushAndDeletePaths(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
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

        $result = $this->planToCompletion($plan, $current, ['private']);

        $this->assertPlanningCounts(3, 1, $result);
        $this->assertSame(['empty', 'full/child.txt', 'public.txt'], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame("gone.txt\0", file_get_contents($plan->local_paths_to_delete));

        $freshLocalIndexEntries = $this->indexEntries($plan->fresh_local_index);
        $this->assertSame(
            ['empty', 'full', 'full/child.txt', 'private', 'private/current.txt', 'public.txt'],
            array_keys($freshLocalIndexEntries)
        );
        $this->assertTrue($freshLocalIndexEntries['empty']['empty']);
        $this->assertFalse($freshLocalIndexEntries['full']['empty']);
        $this->assertFalse($freshLocalIndexEntries['private']['empty']);
        $this->assertArrayNotHasKey('empty', $freshLocalIndexEntries['public.txt']);
    }

    public function testUnchangedIndexProducesEmptyPlans(): void
    {
        $index = $this->writeIndex([
            'a.txt' => [100, 5, 'file'],
            'b/c.txt' => [200, 7, 'file'],
        ]);
        $plan = $this->recordSuccessfulPush($index);

        $result = $this->planToCompletion($plan, $index);

        $this->assertPlanningCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame('', file_get_contents($plan->local_paths_to_delete));
    }

    public function testCtimeSizeOrTypeChangeEachMarksThePathChanged(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
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

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(3, 0, $result);
        $this->assertSame(
            ['ctime-bump.txt', 'size-bump.txt', 'type-swap'],
            $this->listPaths($plan->local_paths_to_push)
        );
    }

    public function testEveryLogicalTypeTransitionEmitsOnlyTheRequiredPushAndDeletePaths(): void
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
                $plan = $this->recordSuccessfulPush($this->writeLogicalIndex($previousType, 1));
                $current = $this->writeLogicalIndex($currentType, 2);

                $result = $this->planToCompletion($plan, $current);
                $message = $previousType . ' to ' . $currentType;

                $this->assertSame($expectedPushes, $this->listPaths($plan->local_paths_to_push), $message);
                $this->assertSame($expectedDeletes, $this->localPathsToDelete($plan->local_paths_to_delete), $message);
                $this->assertPlanningCounts(count($expectedPushes), count($expectedDeletes), $result, $message);
                $plan->after_successful_push();
            }
        }
    }

    public function testDeletedSubtreeEmitsOnlyItsRootAsALocalPathToDelete(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
            'gone' => [1, 0, 'dir', false],
            'gone/child.txt' => [1, 1, 'file'],
            'gone/nested' => [1, 0, 'dir', false],
            'gone/nested/leaf.txt' => [1, 1, 'file'],
            'stays.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex(['stays.txt' => [1, 1, 'file']]);

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(0, 1, $result);
        $this->assertSame([], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame("gone\0", file_get_contents($plan->local_paths_to_delete));
    }

    public function testActiveLocalDeleteRootSurvivesAnInterleavedSiblingCursor(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
            'a' => [1, 0, 'dir', false],
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $entries = [];
        for ($index = 0; $index < 1000; ++$index) {
            // `-` sorts before `/`, placing these siblings between a and a/child.txt.
            $entries[sprintf('a-%04d.txt', $index)] = [2, 1, 'file'];
        }
        $current = $this->writeIndex($entries);
        $plan = $this->makePlan($current);

        $first = $plan->next_step();

        $this->assertSame('planning', $first['status']);
        $this->assertSame([base64_encode('a')], $first['cursor']['active_local_delete_roots_b64']);
        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(1000, 1, $result);
        $this->assertSame("a\0", file_get_contents($plan->local_paths_to_delete));
        $this->assertCount(1000, $this->listPaths($plan->local_paths_to_push));
    }

    public function testReplacementRootSurvivesLexicallyInterleavedSiblingPaths(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
            'a' => [1, 0, 'dir', false],
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex([
            'a' => [2, 1, 'file'],
            // `-` sorts before `/`, so this sibling appears before a/child.txt.
            'a-other' => [2, 1, 'file'],
        ]);

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(2, 1, $result);
        $this->assertSame(['a', 'a-other'], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame("a\0", file_get_contents($plan->local_paths_to_delete));
    }

    public function testNewChangedDeletedAndUnchangedPathsArePlannedTogether(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
            'changed.txt' => [100, 5, 'file'],
            'deleted.txt' => [100, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]));
        $current = $this->writeIndex([
            'added.txt' => [300, 3, 'file'],
            'changed.txt' => [200, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]);

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(2, 1, $result);
        // Output order follows decoded path order from the indexes.
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame("deleted.txt\0", file_get_contents($plan->local_paths_to_delete));
    }

    public function testAfterSuccessfulPushClearsPreviousOutputForTheNextDiff(): void
    {
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);
        $plan = $this->makePlan($index);

        $this->planToCompletion($plan, $index);
        $this->assertSame(['a.txt'], $this->listPaths($plan->local_paths_to_push));

        $plan = $this->recordSuccessfulPush($index);
        $result = $this->planToCompletion($plan, $index);

        $this->assertPlanningCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame('', file_get_contents($plan->local_paths_to_delete));
    }

    public function testPathsThatNeedBase64SurvivePushAndDeletePlans(): void
    {
        // Newlines and non-ASCII bytes are why JSONL indexes encode paths.
        $newPath = "wp-content/uploads/line\nbreak.png";
        $deletedPath = 'wp-content/uploads/naïve-café.jpg';
        $plan = $this->recordSuccessfulPush($this->writeIndex([
            $deletedPath => [100, 6, 'file'],
        ]));
        $current = $this->writeIndex([
            $newPath => [100, 5, 'file'],
        ]);

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(1, 1, $result);
        $this->assertSame([$newPath], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame($deletedPath . "\0", file_get_contents($plan->local_paths_to_delete));
    }

    public function testPlanningParsesJsonWithoutDependingOnFieldOrderOrEscaping(): void
    {
        $path = 'wp-content/???';
        $base64Path = base64_encode($path);
        $localIndexAtPreviousPush = $this->tempDir . '/local_index_at_previous_push_shape.jsonl';
        $current = $this->tempDir . '/current-shape.jsonl';

        file_put_contents(
            $localIndexAtPreviousPush,
            json_encode(['path' => $base64Path, 'ctime' => 100, 'size' => 5, 'type' => 'file'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents(
            $current,
            json_encode(['type' => 'file', 'size' => 5, 'ctime' => 100, 'path' => $base64Path], JSON_THROW_ON_ERROR) . "\n"
        );
        $plan = $this->recordSuccessfulPush($localIndexAtPreviousPush);

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame('', file_get_contents($plan->local_paths_to_delete));
    }

    // ------------------------------------------------------------------
    //  Bounded progress and restart
    // ------------------------------------------------------------------

    public function testInitializationPinsTheIndexWithoutConsumingAndClearsOldOutput(): void
    {
        $plan = $this->recordSuccessfulPush($this->writeIndex([
            'gone.txt' => [1, 1, 'file'],
        ]));
        $oldIndex = $this->writeIndex(['old.txt' => [1, 1, 'file']]);
        $this->planToCompletion($plan, $oldIndex);
        $this->assertGreaterThan(0, filesize($plan->fresh_local_index));
        $this->assertGreaterThan(0, filesize($plan->local_paths_to_push));
        $this->assertGreaterThan(0, filesize($plan->local_paths_to_delete));
        $plan->after_successful_push();

        $current = $this->writeIndex($this->manyFileEntries(1001));
        $plan = $this->makePlan($current);
        $this->assertSame(file_get_contents($current), file_get_contents($plan->fresh_local_index));
        $this->assertFileExists(dirname($plan->fresh_local_index) . '/cursor.json');
        $this->assertSame(0, filesize($plan->local_paths_to_push));
        $this->assertSame(0, filesize($plan->local_paths_to_delete));
        $plan->close();
    }

    public function testPlanningUsesItsCopyWhenTheConstructorInputIsReplacedBeforeTheFirstBatch(): void
    {
        $current = $this->tempDir . '/fresh-local-index.jsonl';
        copy($this->writeIndex(['value.txt' => [1, 1, 'file']]), $current);
        $plan = $this->makePlan($current);
        // Replace the caller-owned input after the plan retained its copy.
        $replacement = $this->writeIndex([
            'another.txt' => [1, 1, 'file'],
            'value.txt' => [1, 1, 'file'],
        ]);
        rename($replacement, $current);
        clearstatcache(true, $current);

        $complete = $plan->next_step();

        $this->assertSame('complete', $complete['status']);
        $this->assertPlanningCounts(1, 0, $complete);
        $this->assertSame(['value.txt'], $this->listPaths($plan->local_paths_to_push));
        $this->assertSame(0, filesize($plan->local_paths_to_delete));
        $plan->close();
    }

    public function testPlanningStopsAfterItsFixedRecordBudgetAndResumes(): void
    {
        $entries = $this->manyFileEntries(1001);
        $current = $this->writeIndex($entries);
        $plan = $this->makePlan($current);

        $first = $plan->next_step();

        $this->assertSame('planning', $first['status']);
        $cursor = $first['cursor'];
        $this->assertSame(1000, $cursor['progress_changed']);
        $this->assertGreaterThan(0, $cursor['fresh_local_index_byte_offset']);
        $this->assertLessThan(filesize($current), $cursor['fresh_local_index_byte_offset']);
        $this->assertSame(0, $cursor['local_index_at_previous_push_byte_offset']);
        $this->assertSame([], $cursor['active_local_delete_roots_b64']);
        $this->assertSame(filesize($plan->local_paths_to_push), $cursor['local_paths_to_push_bytes']);
        $this->assertSame(filesize($plan->local_paths_to_delete), $cursor['local_paths_to_delete_bytes']);

        $result = $this->planToCompletion($plan, $current);

        $this->assertPlanningCounts(1001, 0, $result);
        $this->assertCount(1001, $this->listPaths($plan->local_paths_to_push));
        $this->assertCount(1001, $this->indexEntries($plan->fresh_local_index));
    }

    public function testNewInstanceResumesFromTheRetainedCursor(): void
    {
        $currentEntries = [];
        $localIndexAtPreviousPushEntries = [];
        for ($index = 0; $index < 2001; ++$index) {
            // The current and deleted names alternate in decoded sort order,
            // so every batch appends to both planning outputs.
            $currentEntries[sprintf('item-%04d-current.txt', $index)] = [$index + 1, 1, 'file'];
            $localIndexAtPreviousPushEntries[sprintf('item-%04d-deleted.txt', $index)] = [$index + 1, 1, 'file'];
        }
        $current = $this->writeIndex($currentEntries);
        $plan = $this->recordSuccessfulPush($this->writeIndex($localIndexAtPreviousPushEntries));
        $plan = $this->makePlan($current);

        $first = $plan->next_step();
        $this->assertSame('planning', $first['status']);
        $durableCursor = $first['cursor'];
        $plan->close();

        $reopened = $this->makePlan($current);
        $resumed = $reopened->next_step();
        $this->assertSame('planning', $resumed['status']);
        $this->assertGreaterThan(
            $durableCursor['local_paths_to_push_bytes'],
            $resumed['cursor']['local_paths_to_push_bytes']
        );
        $this->assertGreaterThan(
            $durableCursor['local_paths_to_delete_bytes'],
            $resumed['cursor']['local_paths_to_delete_bytes']
        );

        $result = $this->planToCompletion($reopened, $current);

        $this->assertPlanningCounts(2001, 2001, $result);
        $this->assertSame(array_keys($currentEntries), $this->listPaths($reopened->local_paths_to_push));
        $this->assertSame(array_keys($localIndexAtPreviousPushEntries), $this->localPathsToDelete($reopened->local_paths_to_delete));
        $this->assertCount(2001, $this->indexEntries($reopened->fresh_local_index));
    }

    public function testCursoredProgressAndCompletedEofAreIdempotentAfterRestart(): void
    {
        $current = $this->writeIndex($this->manyFileEntries(1001));
        $plan = $this->makePlan($current);
        $first = $plan->next_step();
        $this->assertSame('planning', $first['status']);
        $plan->close();

        $reopened = $this->makePlan($current);
        $complete = $reopened->next_step();
        $this->assertSame('complete', $complete['status']);
        $freshLocalIndex = file_get_contents($reopened->fresh_local_index);
        $pathsToPush = file_get_contents($reopened->local_paths_to_push);
        $localPathsToDelete = file_get_contents($reopened->local_paths_to_delete);
        $reopened->close();

        $replayedPlan = $this->makePlan($current);
        $replayedEof = $replayedPlan->next_step();
        $replayedPlan->close();

        $this->assertSame($complete, $replayedEof);
        $this->assertSame($freshLocalIndex, file_get_contents($reopened->fresh_local_index));
        $this->assertSame($pathsToPush, file_get_contents($reopened->local_paths_to_push));
        $this->assertSame($localPathsToDelete, file_get_contents($reopened->local_paths_to_delete));
    }

    public function testReplacingTheConstructorInputBetweenCursorsDoesNotChangeThePlan(): void
    {
        $current = $this->tempDir . '/fresh-local-index.jsonl';
        $source = $this->writeIndex($this->manyFileEntries(1001));
        copy($source, $current);
        $plan = $this->makePlan($current);
        $first = $plan->next_step();
        $this->assertSame('planning', $first['status']);

        $replacement = $this->writeIndex($this->manyFileEntries(1002));
        rename($replacement, $current);
        clearstatcache(true, $current);

        $complete = $plan->next_step();

        $this->assertSame('complete', $complete['status']);
        $this->assertPlanningCounts(1001, 0, $complete);
        $this->assertCount(1001, $this->listPaths($plan->local_paths_to_push));
        $plan->close();
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
        $this->planToCompletion($this->makePlan($garbage), $garbage);
    }

    public function testPlanningRejectsAnUndecodablePath(): void
    {
        $bad = $this->tempDir . '/bad-path.jsonl';
        file_put_contents($bad, '{"path":"%%%not-base64%%%","ctime":1,"size":1,"type":"file"}' . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid index path');
        $this->planToCompletion($this->makePlan($bad), $bad);
    }

    public function testPlanningRejectsACorruptLocalIndexAtPreviousPushDirectoryWithoutEmptyState(): void
    {
        $plan = $this->makePlan();
        $plan->close();
        $invalidFreshLocalIndex = $this->writeIndex([
            'value' => [1, 0, 'dir'],
        ]);
        copy($invalidFreshLocalIndex, $plan->fresh_local_index);
        $plan->after_successful_push();
        $current = $this->writeIndex([
            'value' => [2, 0, 'dir', true],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local index at the previous push has no boolean empty');
        $this->planToCompletion($plan, $current);
    }

    public function testPlanningRejectsACurrentDirectoryWithoutEmptyState(): void
    {
        $current = $this->writeIndex([
            'value' => [2, 0, 'dir'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fresh local index has no boolean empty');
        $this->planToCompletion($this->makePlan($current), $current);
    }

    public function testPlanningRequiresTheFreshLocalIndexToExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fresh local index file is missing');
        $this->makePlan($this->tempDir . '/no-such-index.jsonl');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** @param list<string> $excludedPaths */
    private function makePlan(?string $freshLocalIndexPath = null, array $excludedPaths = []): PushPlan
    {
        if ($freshLocalIndexPath === null) {
            $freshLocalIndexPath = $this->tempDir . '/empty-fresh-local-index.jsonl';
            if (!is_file($freshLocalIndexPath)) {
                file_put_contents($freshLocalIndexPath, '');
            }
        }
        return new PushPlan($this->tempDir . '/state/push/example.com', $freshLocalIndexPath, $excludedPaths);
    }

    private function recordSuccessfulPush(string $freshLocalIndex): PushPlan
    {
        $plan = $this->makePlan($freshLocalIndex);
        $this->planToCompletion($plan, $freshLocalIndex);
        $plan->after_successful_push();
        return $plan;
    }

    /**
     * Continue planning until the plan reports that all input was consumed.
     *
     * @param list<string> $excludedPaths Raw document-root-relative paths.
     * @return array{status:string,cursor:array<string,mixed>}
     */
    private function planToCompletion(
        PushPlan $plan,
        string $freshLocalIndexPath,
        array $excludedPaths = []
    ): array {
        $plan->close();
        $activePlan = $this->makePlan($freshLocalIndexPath, $excludedPaths);
        for ($step = 0; $step < 100; ++$step) {
            $result = $activePlan->next_step();
            if ($result['status'] === 'complete') {
                $activePlan->close();
                return $result;
            }
            $this->assertSame('planning', $result['status']);
        }
        $this->fail('Planning did not complete within 100 bounded steps.');
    }

    private function assertPlanningCounts(
        int $changed,
        int $deleted,
        array $result,
        string $message = ''
    ): void {
        $this->assertSame($changed, $result['cursor']['progress_changed'], $message);
        $this->assertSame($deleted, $result['cursor']['progress_deleted'], $message);
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
     * Decode a local_paths_to_push JSONL file.
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
    private function localPathsToDelete(string $file): array
    {
        $this->assertFileExists($file);
        $bytes = file_get_contents($file);
        $this->assertIsString($bytes);
        if ($bytes === '') {
            return [];
        }
        $paths = explode("\0", $bytes);
        $this->assertSame('', array_pop($paths), 'The local_paths_to_delete file must end at a NUL record boundary.');
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
