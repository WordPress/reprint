<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-plan.php';

/**
 * Coverage for PushPlan's per-target local index at the previous push and bounded steps.
 *
 * The plan merges the fresh local index, whose directory entries carry an `empty`
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

        $this->recordSuccessfulPush($this->writeIndex([
            'a.txt' => [100, 5, 'file'],
        ]));
        $this->assertFileExists($this->planPath('local_index_at_previous_push.jsonl'));
        $this->assertFileDoesNotExist($this->planPath('local_index_at_previous_push.jsonl') . '.tmp');

        // A second successful push replaces the first. Comparing that same index
        // again produces no paths to push or delete.
        $second = $this->writeIndex(['b.txt' => [200, 9, 'file']]);
        $this->recordSuccessfulPush($second);
        $plan = $this->startPlan($second);
        $result = $this->planToCompletion($plan);
        $this->assertPathCounts(0, 0, $result);
    }

    public function testAfterSuccessfulPushRequiresTheFreshLocalIndexToExist(): void
    {
        $plan = $this->startPlan();
        $plan->close();
        unlink($this->planPath('fresh_local_index.jsonl'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fresh local index is missing');
        $plan->after_successful_push();
    }

    public function testAfterSuccessfulPushRejectsAClosedIncompletePlan(): void
    {
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(2)));
        $this->assertTrue($plan->next_step());
        $plan->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the plan is complete');
        $plan->after_successful_push();
    }

    public function testAfterSuccessfulPushRequiresThePreviousIndexToBeConsumed(): void
    {
        $this->recordSuccessfulPush($this->writeIndex($this->manyFileEntries(2)));
        $plan = $this->startPlan();
        $this->assertTrue($plan->next_step());
        $plan->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the plan is complete');
        $plan->after_successful_push();
    }

    public function testStartRejectsAnUnfinishedPlanInsteadOfResumingIt(): void
    {
        $plan = $this->startPlan();
        $plan->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unfinished plan exists');
        $this->startPlan();
    }

    public function testDiscardAllowsANewPlanWithoutPublishingTheOldIndex(): void
    {
        $firstIndex = $this->writeIndex(['first.txt' => [100, 5, 'file']]);
        $plan = $this->startPlan($firstIndex);
        $this->assertTrue(PushPlan::has_plan($this->planDirectory()));
        $plan->close();
        $plan->discard();

        $this->assertFalse(PushPlan::has_plan($this->planDirectory()));
        $this->assertFileDoesNotExist($this->planPath('local_index_at_previous_push.jsonl'));

        $secondIndex = $this->writeIndex(['second.txt' => [200, 6, 'file']]);
        $secondPlan = $this->startPlan($secondIndex);
        $result = $this->planToCompletion($secondPlan);
        $this->assertPathCounts(1, 0, $result);
        $this->assertSame(
            ['second.txt'],
            $this->listPaths(PushPlan::local_paths_to_push_path($this->planDirectory()))
        );
        $this->assertSame(
            [],
            $this->localPathsToDelete(PushPlan::local_paths_to_delete_path($this->planDirectory()))
        );
    }

    // ------------------------------------------------------------------
    //  Local plan
    // ------------------------------------------------------------------

    public function testFirstPushSelectsFilesSymlinksAndEmptyDirectories(): void
    {
        $index = $this->writeIndex([
            'index.php' => [100, 5, 'file'],
            'wp-content' => [100, 0, 'dir', false],
            'wp-content/themes/foo/style.css' => [150, 5, 'file'],
        ]);

        $plan = $this->startPlan($index);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(2, 0, $result);
        $this->assertSame(
            ['index.php', 'wp-content/themes/foo/style.css'],
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));

        // Pin the local_paths_to_push representation: paths remain base64 in JSONL.
        $pathsToPush = file_get_contents($this->planPath('local_paths_to_push.jsonl'));
        $this->assertIsString($pathsToPush);
        $firstLine = strtok($pathsToPush, "\n");
        $this->assertSame(
            '{"path":"' . base64_encode('index.php') . '","type":"file","size":5,"ctime":100}',
            $firstLine
        );
    }

    public function testPlanCopiesEveryFreshLocalIndexEntryAndExcludesOnlyPushAndDeletePaths(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
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

        $plan = $this->startPlan($current, ['private']);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(3, 1, $result);
        $this->assertSame(['empty', 'full/child.txt', 'public.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("gone.txt\0", file_get_contents($this->planPath('local_paths_to_delete')));

        $freshLocalIndexEntries = $this->indexEntries($this->planPath('fresh_local_index.jsonl'));
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
        $this->recordSuccessfulPush($index);

        $plan = $this->startPlan($index);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testCtimeSizeOrTypeChangeEachMarksThePathChanged(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
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

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(3, 0, $result);
        $this->assertSame(
            ['ctime-bump.txt', 'size-bump.txt', 'type-swap'],
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
    }

    public function testUnrelatedIndexFieldsDoNotMarkFilesOrSymlinksChanged(): void
    {
        $previous = $this->tempDir . '/previous-with-extra-fields.jsonl';
        $current = $this->tempDir . '/current-with-extra-fields.jsonl';
        $previousEntries = [
            ['path' => base64_encode('file.txt'), 'ctime' => 100, 'size' => 5, 'type' => 'file', 'future' => 'before'],
            ['path' => base64_encode('link'), 'ctime' => 100, 'size' => 0, 'type' => 'link', 'future' => 'before'],
        ];
        $currentEntries = [
            ['path' => base64_encode('file.txt'), 'ctime' => 100, 'size' => 5, 'type' => 'file', 'future' => 'after'],
            ['path' => base64_encode('link'), 'ctime' => 100, 'size' => 0, 'type' => 'link', 'future' => 'after'],
        ];
        file_put_contents(
            $previous,
            json_encode($previousEntries[0], JSON_THROW_ON_ERROR) . "\n"
            . json_encode($previousEntries[1], JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents(
            $current,
            json_encode($currentEntries[0], JSON_THROW_ON_ERROR) . "\n"
            . json_encode($currentEntries[1], JSON_THROW_ON_ERROR) . "\n"
        );
        $this->recordSuccessfulPush($previous);

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
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
                $this->recordSuccessfulPush($this->writeLogicalIndex($previousType, 1));
                $current = $this->writeLogicalIndex($currentType, 2);

                $plan = $this->startPlan($current);
                $result = $this->planToCompletion($plan);
                $message = $previousType . ' to ' . $currentType;

                $this->assertSame($expectedPushes, $this->listPaths($this->planPath('local_paths_to_push.jsonl')), $message);
                $this->assertSame($expectedDeletes, $this->localPathsToDelete($this->planPath('local_paths_to_delete')), $message);
                $this->assertPathCounts(count($expectedPushes), count($expectedDeletes), $result, $message);
                $plan->after_successful_push();
            }
        }
    }

    public function testDeletedSubtreeEmitsOnlyItsRootAsALocalPathToDelete(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
            'gone' => [1, 0, 'dir', false],
            'gone/child.txt' => [1, 1, 'file'],
            'gone/nested' => [1, 0, 'dir', false],
            'gone/nested/leaf.txt' => [1, 1, 'file'],
            'stays.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex(['stays.txt' => [1, 1, 'file']]);

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(0, 1, $result);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("gone\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testSeenDeletedDirectorySurvivesAnInterleavedSiblingCursor(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
            'a' => [1, 0, 'dir', false],
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $entries = [];
        for ($index = 0; $index < 3; ++$index) {
            // `-` sorts before `/`, placing these siblings between a and a/child.txt.
            $entries[sprintf('a-%04d.txt', $index)] = [2, 1, 'file'];
        }
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current);

        $this->assertTrue($plan->next_step());
        $plan->close();
        $resumedPlan = $this->resumePlan();
        $result = $this->planToCompletion($resumedPlan);

        $this->assertPathCounts(3, 1, $result);
        $this->assertSame("a\0", file_get_contents($this->planPath('local_paths_to_delete')));
        $this->assertCount(3, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
    }

    public function testReplacementRootSurvivesLexicallyInterleavedSiblingPaths(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
            'a' => [1, 0, 'dir', false],
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex([
            'a' => [2, 1, 'file'],
            // `-` sorts before `/`, so this sibling appears before a/child.txt.
            'a-other' => [2, 1, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(2, 1, $result);
        $this->assertSame(['a', 'a-other'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("a\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testNewChangedDeletedAndUnchangedPathsArePlannedTogether(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
            'changed.txt' => [100, 5, 'file'],
            'deleted.txt' => [100, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]));
        $current = $this->writeIndex([
            'added.txt' => [300, 3, 'file'],
            'changed.txt' => [200, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(2, 1, $result);
        // Output order follows decoded path order from the indexes.
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("deleted.txt\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testAfterSuccessfulPushClearsPreviousOutputForTheNextDiff(): void
    {
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);
        $plan = $this->startPlan($index);

        $this->planToCompletion($plan);
        $this->assertSame(['a.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));

        $plan->after_successful_push();
        $plan = $this->startPlan($index);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testPathsThatNeedBase64SurvivePushAndDeletePlans(): void
    {
        // Newlines and non-ASCII bytes are why JSONL indexes encode paths.
        $newPath = "wp-content/uploads/line\nbreak.png";
        $deletedPath = 'wp-content/uploads/naïve-café.jpg';
        $this->recordSuccessfulPush($this->writeIndex([
            $deletedPath => [100, 6, 'file'],
        ]));
        $current = $this->writeIndex([
            $newPath => [100, 5, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(1, 1, $result);
        $this->assertSame([$newPath], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame($deletedPath . "\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testPlanParsesJsonWithoutDependingOnFieldOrderOrEscaping(): void
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
        $this->recordSuccessfulPush($localIndexAtPreviousPush);

        $plan = $this->startPlan($current);
        $result = $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0, $result);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));
    }

    // ------------------------------------------------------------------
    //  Bounded steps and restart
    // ------------------------------------------------------------------

    public function testInitializationPinsTheIndexWithoutConsumingAndClearsOldOutput(): void
    {
        $this->recordSuccessfulPush($this->writeIndex([
            'gone.txt' => [1, 1, 'file'],
        ]));
        $oldIndex = $this->writeIndex(['old.txt' => [1, 1, 'file']]);
        $plan = $this->startPlan($oldIndex);
        $this->planToCompletion($plan);
        $this->assertGreaterThan(0, filesize($this->planPath('fresh_local_index.jsonl')));
        $this->assertGreaterThan(0, filesize($this->planPath('local_paths_to_push.jsonl')));
        $this->assertGreaterThan(0, filesize($this->planPath('local_paths_to_delete')));
        $plan->after_successful_push();

        $current = $this->writeIndex($this->manyFileEntries(2));
        $plan = $this->startPlan($current);
        $this->assertSame(file_get_contents($current), file_get_contents($this->planPath('fresh_local_index.jsonl')));
        $this->assertFileExists(dirname($this->planPath('fresh_local_index.jsonl')) . '/cursor.json');
        $this->assertSame(0, filesize($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(0, filesize($this->planPath('local_paths_to_delete')));
        $plan->close();
    }

    public function testPlanUsesItsCopyWhenTheStartingIndexIsReplacedBeforeTheFirstBatch(): void
    {
        $current = $this->tempDir . '/fresh-local-index.jsonl';
        copy($this->writeIndex(['value.txt' => [1, 1, 'file']]), $current);
        $plan = $this->startPlan($current);
        // Replace the caller-owned index after the plan retained its copy.
        $replacement = $this->writeIndex([
            'another.txt' => [1, 1, 'file'],
            'value.txt' => [1, 1, 'file'],
        ]);
        rename($replacement, $current);
        clearstatcache(true, $current);

        $this->assertFalse($plan->next_step());

        $this->assertPathCounts(1, 0, $this->planCursor());
        $this->assertSame(['value.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(0, filesize($this->planPath('local_paths_to_delete')));
        $plan->close();
    }

    public function testStepProcessesOnePathAndResumes(): void
    {
        $entries = $this->manyFileEntries(2);
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current);

        $this->assertTrue($plan->next_step());

        $this->assertPathCounts(1, 0, $this->planCursor());
        $this->assertCount(1, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(0, filesize($this->planPath('local_paths_to_delete')));
        $plan->close();

        $resumedPlan = $this->resumePlan();
        $result = $this->planToCompletion($resumedPlan);

        $this->assertPathCounts(2, 0, $result);
        $this->assertCount(2, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertCount(2, $this->indexEntries($this->planPath('fresh_local_index.jsonl')));
    }

    public function testStepRetainsTheNextIndexEntryUntilClose(): void
    {
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(3)));
        $fresh_index_handle_property = new ReflectionProperty(PushPlan::class, 'fresh_local_index_handle');
        $fresh_index_entry_property = new ReflectionProperty(PushPlan::class, 'fresh_local_index_entry');

        $this->assertTrue($plan->next_step());
        $first_cursor = $this->planCursor();
        $fresh_index_handle = $fresh_index_handle_property->getValue($plan);
        $this->assertIsResource($fresh_index_handle);
        $this->assertGreaterThan(
            $first_cursor['byte_offset_in_fresh_index'],
            ftell($fresh_index_handle)
        );
        $retained_entry = $fresh_index_entry_property->getValue($plan);
        $this->assertIsArray($retained_entry);
        $this->assertSame('file-0001.txt', $retained_entry['path']);

        $this->assertTrue($plan->next_step());
        $second_cursor = $this->planCursor();
        $this->assertGreaterThan(
            $second_cursor['byte_offset_in_fresh_index'],
            ftell($fresh_index_handle)
        );
        $this->assertSame(2, $second_cursor['local_paths_to_push_count']);

        $plan->close();
        $plan->close();
        $resumed_plan = $this->resumePlan();
        $this->assertFalse($resumed_plan->next_step());
        $this->assertFalse($resumed_plan->next_step());
        $resumed_plan->close();
        $resumed_plan->close();
        $this->assertFalse($resumed_plan->next_step());
    }

    public function testCursorUsesLiteralPathCountAndByteOffsetKeys(): void
    {
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(2)));
        $this->assertTrue($plan->next_step());

        $cursorContents = file_get_contents($this->planPath('cursor.json'));
        $this->assertIsString($cursorContents);
        $cursor = json_decode($cursorContents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($cursor);
        $this->assertSame([
            'byte_offset_in_fresh_index',
            'byte_offset_in_previous_index',
            'byte_offset_in_local_paths_to_push',
            'byte_offset_in_local_paths_to_delete',
            'local_paths_to_push_count',
            'local_paths_to_delete_count',
            'seen_deleted_directories',
        ], array_keys($cursor));
        $this->assertSame(
            filesize($this->planPath('local_paths_to_push.jsonl')),
            $cursor['byte_offset_in_local_paths_to_push']
        );
        $this->assertSame(
            filesize($this->planPath('local_paths_to_delete')),
            $cursor['byte_offset_in_local_paths_to_delete']
        );
        $this->assertSame(1, $cursor['local_paths_to_push_count']);
        $this->assertSame(0, $cursor['local_paths_to_delete_count']);
        $plan->close();
    }

    public function testNewInstanceResumesFromTheRetainedCursor(): void
    {
        $currentEntries = [];
        $localIndexAtPreviousPushEntries = [];
        for ($index = 0; $index < 3; ++$index) {
            // The current and deleted names alternate in decoded sort order,
            // so every batch appends to both path files.
            $currentEntries[sprintf('item-%04d-current.txt', $index)] = [$index + 1, 1, 'file'];
            $localIndexAtPreviousPushEntries[sprintf('item-%04d-deleted.txt', $index)] = [$index + 1, 1, 'file'];
        }
        $current = $this->writeIndex($currentEntries);
        $this->recordSuccessfulPush($this->writeIndex($localIndexAtPreviousPushEntries));
        $plan = $this->startPlan($current);

        $this->assertTrue($plan->next_step());
        $firstPushBytes = filesize($this->planPath('local_paths_to_push.jsonl'));
        $firstDeleteBytes = filesize($this->planPath('local_paths_to_delete'));
        $plan->close();

        $reopened = $this->resumePlan();
        $this->assertTrue($reopened->next_step());
        $this->assertSame(
            $firstPushBytes,
            filesize($this->planPath('local_paths_to_push.jsonl'))
        );
        $this->assertGreaterThan(
            $firstDeleteBytes,
            filesize($this->planPath('local_paths_to_delete'))
        );

        $result = $this->planToCompletion($reopened);

        $this->assertPathCounts(3, 3, $result);
        $this->assertSame(array_keys($currentEntries), $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(array_keys($localIndexAtPreviousPushEntries), $this->localPathsToDelete($this->planPath('local_paths_to_delete')));
        $this->assertCount(3, $this->indexEntries($this->planPath('fresh_local_index.jsonl')));
    }

    public function testLoadRetainedLeavesPlanningFilesClosed(): void
    {
        $freshLocalIndex = $this->writeIndex(['value.txt' => [1, 5, 'file']]);
        $plan = $this->startPlan($freshLocalIndex);
        $this->planToCompletion($plan);

        $loaded = PushPlan::load_retained($this->planDirectory());
        foreach ([
            'fresh_local_index_handle',
            'local_index_at_previous_push_handle',
            'local_paths_to_push_handle',
            'local_paths_to_delete_handle',
        ] as $property_name) {
            $property = new ReflectionProperty(PushPlan::class, $property_name);
            $this->assertNull($property->getValue($loaded));
        }

        $loaded->after_successful_push();
        $this->assertSame(
            file_get_contents($freshLocalIndex),
            file_get_contents($this->planPath('local_index_at_previous_push.jsonl'))
        );
    }

    public function testSavedOffsetsAndCompletedEofAreIdempotentAfterRestart(): void
    {
        $current = $this->writeIndex($this->manyFileEntries(2));
        $plan = $this->startPlan($current);
        $this->assertTrue($plan->next_step());
        $plan->close();

        $reopened = $this->resumePlan();
        $this->assertFalse($reopened->next_step());
        $freshLocalIndex = file_get_contents($this->planPath('fresh_local_index.jsonl'));
        $pathsToPush = file_get_contents($this->planPath('local_paths_to_push.jsonl'));
        $localPathsToDelete = file_get_contents($this->planPath('local_paths_to_delete'));
        $reopened->close();

        $replayedPlan = $this->resumePlan();
        $replayedEof = $replayedPlan->next_step();
        $replayedPlan->close();

        $this->assertFalse($replayedEof);
        $this->assertSame($freshLocalIndex, file_get_contents($this->planPath('fresh_local_index.jsonl')));
        $this->assertSame($pathsToPush, file_get_contents($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame($localPathsToDelete, file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testReplacingTheStartingIndexBetweenStepsDoesNotChangeThePlan(): void
    {
        $current = $this->tempDir . '/fresh-local-index.jsonl';
        $index_to_copy = $this->writeIndex($this->manyFileEntries(2));
        copy($index_to_copy, $current);
        $plan = $this->startPlan($current);
        $this->assertTrue($plan->next_step());

        $replacement = $this->writeIndex($this->manyFileEntries(3));
        rename($replacement, $current);
        clearstatcache(true, $current);

        $this->assertFalse($plan->next_step());

        $this->assertPathCounts(2, 0, $this->planCursor());
        $this->assertCount(2, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $plan->close();
    }

    public function testResumeUsesTheExcludedPathsSavedByStart(): void
    {
        $entries = $this->manyFileEntries(2);
        $entries['private/value.txt'] = [2000, 1, 'file'];
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current, ['private']);

        $this->assertTrue($plan->next_step());
        $plan->close();

        $resumedPlan = $this->resumePlan();
        $result = $this->planToCompletion($resumedPlan);

        $this->assertPathCounts(2, 0, $result);
        $this->assertNotContains(
            'private/value.txt',
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
    }

    // ------------------------------------------------------------------
    //  Invalid indexes
    // ------------------------------------------------------------------

    public function testPlanReportsInvalidJson(): void
    {
        // A blank line is invalid JSON, so the plan stops instead of silently
        // skipping an index line.
        $garbage = $this->tempDir . '/garbage.jsonl';
        file_put_contents(
            $garbage,
            $this->indexLine('a.txt', 100, 5, 'file') . "\n\nnot json at all\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->planToCompletion($this->startPlan($garbage));
    }

    public function testPlanReportsAPathThatIsNotBase64(): void
    {
        $bad = $this->tempDir . '/bad-path.jsonl';
        file_put_contents($bad, '{"path":"%%%not-base64%%%","ctime":1,"size":1,"type":"file"}' . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('index path is not valid base64');
        $this->planToCompletion($this->startPlan($bad));
    }

    public function testPlanRequiresTheFreshLocalIndexToExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fresh local index file is missing');
        $this->startPlan($this->tempDir . '/no-such-index.jsonl');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** @param list<string> $excludedPaths */
    private function startPlan(?string $freshLocalIndexPath = null, array $excludedPaths = []): PushPlan
    {
        if ($freshLocalIndexPath === null) {
            $freshLocalIndexPath = $this->tempDir . '/empty-fresh-local-index.jsonl';
            if (!is_file($freshLocalIndexPath)) {
                file_put_contents($freshLocalIndexPath, '');
            }
        }
        if (!is_dir($this->planDirectory())) {
            mkdir($this->planDirectory(), 0755, true);
        }
        file_put_contents(
            $this->planPath('excluded_paths.json'),
            json_encode(
                array_map('base64_encode', $excludedPaths),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
        return PushPlan::start($this->planDirectory(), $freshLocalIndexPath);
    }

    private function resumePlan(): PushPlan
    {
        return PushPlan::resume($this->planDirectory());
    }

    private function recordSuccessfulPush(string $freshLocalIndex): void
    {
        $plan = $this->startPlan($freshLocalIndex);
        $this->planToCompletion($plan);
        $plan->after_successful_push();
    }

    /**
     * Continue the plan until it reports that both indexes were consumed.
     *
     * @return array<string,mixed> Durable planning cursor after both indexes reach EOF.
     */
    private function planToCompletion(PushPlan $plan): array
    {
        for ($step = 0; $step < 100; ++$step) {
            if (!$plan->next_step()) {
                $cursor = $this->planCursor();
                $plan->close();
                return $cursor;
            }
        }
        $this->fail('Push plan did not complete within 100 bounded steps.');
    }

    /** @return array<string,mixed> */
    private function planCursor(): array
    {
        $contents = file_get_contents($this->planPath('cursor.json'));
        $this->assertIsString($contents);
        $cursor = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($cursor);
        return $cursor;
    }

    private function assertPathCounts(
        int $localPathsToPushCount,
        int $localPathsToDeleteCount,
        array $result,
        string $message = ''
    ): void {
        $this->assertSame($localPathsToPushCount, $result['local_paths_to_push_count'], $message);
        $this->assertSame($localPathsToDeleteCount, $result['local_paths_to_delete_count'], $message);
    }

    private function planDirectory(): string
    {
        return $this->tempDir . '/state/push/example.com';
    }

    private function planPath(string $filename): string
    {
        return $this->planDirectory() . '/' . $filename;
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
