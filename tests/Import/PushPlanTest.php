<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\sort_index_file;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/sort-index-file.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/push/class-push-plan.php';

/**
 * Coverage for PushPlan's local index and bounded steps.
 *
 * The plan diffs the fresh local index, whose directory entries carry an
 * `empty` boolean from the indexer, against the local index.
 * The tests pin the resulting local_paths_to_push JSONL, raw NUL-delimited
 * local paths to delete, cursor replay, and every transition among files,
 * symlinks, and empty directories.
 */
final class PushPlanTest extends TestCase
{
    private string $tempDir;

    /** @var array<string,mixed> Cursor stored by the test caller after each plan step. */
    private array $cursor;

    /** @var array<string,array<string,mixed>> Last filesystem-root shape created by materializeFilesystemRoot(). */
    private array $materializedIndexEntries = [];

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
    //  Local index
    // ------------------------------------------------------------------

    public function testLocalIndexCanBeUsedByTheNextPlan(): void
    {
        $this->assertFileDoesNotExist($this->localIndexFile());

        $this->saveLocalIndex($this->writeIndex([
            'a.txt' => [100, 5, 'file'],
        ]));
        $this->assertFileExists($this->localIndexFile());
        $this->assertFileDoesNotExist($this->localIndexFile() . '.tmp');
        $this->assertDirectoryDoesNotExist($this->planDirectory());

        // A second successful push replaces the first. Comparing that same index
        // again produces no paths to push or delete.
        $second = $this->writeIndex(['b.txt' => [200, 9, 'file']]);
        $this->saveLocalIndex($second);
        $plan = $this->startPlan($second);
        $this->planToCompletion($plan);
        $this->assertPathCounts(0, 0);
    }

    public function testStartRequiresTheCallerToCreateThePlanDirectory(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('without its directory');
        PushPlan::start(
            $this->planDirectory(),
            $this->filesystemRoot(),
            $this->localIndexFile(),
            $this->excludedPathsPath()
        );
    }

    public function testRemovingThePlanDirectoryAllowsANewPlanWithoutSavingTheOldIndex(): void
    {
        $firstIndex = $this->writeIndex(['first.txt' => [100, 5, 'file']]);
        $plan = $this->startPlan($firstIndex);
        $plan->close();
        $this->removePlanDirectory();

        $this->assertDirectoryDoesNotExist($this->planDirectory());
        $this->assertFileDoesNotExist($this->localIndexFile());

        $secondIndex = $this->writeIndex(['second.txt' => [200, 6, 'file']]);
        $secondPlan = $this->startPlan($secondIndex);
        $this->planToCompletion($secondPlan);
        $this->assertPathCounts(1, 0);
        $this->assertSame(
            ['second.txt'],
            $this->listPaths($secondPlan->get_local_paths_to_push_path())
        );
        $this->assertSame(
            [],
            $this->localPathsToDelete($secondPlan->get_local_paths_to_delete_path())
        );
    }

    // ------------------------------------------------------------------
    //  Local plan
    // ------------------------------------------------------------------

    public function testFirstPushSelectsFilesSymlinksAndEmptyDirectories(): void
    {
        $index = $this->writeIndex([
            'index.php' => [100, 5, 'file'],
            'wp-content/themes/foo/style.css' => [150, 5, 'file'],
        ]);

        $plan = $this->startPlan($index);
        $this->planToCompletion($plan);

        $this->assertPathCounts(2, 0);
        $this->assertSame(2, $this->planCursor()['local_paths_to_push_count']);
        $this->assertSame(10, $this->planCursor()['local_file_bytes_to_push']);
        $this->assertSame(
            ['index.php', 'wp-content/themes/foo/style.css'],
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));

        // Pin the local_paths_to_push representation: paths remain base64 in JSONL.
        $pathsToPush = file_get_contents($this->planPath('local_paths_to_push.jsonl'));
        $this->assertIsString($pathsToPush);
        $firstLine = strtok($pathsToPush, "\n");
        $firstPath = json_decode($firstLine, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(base64_encode('index.php'), $firstPath['path']);
        $this->assertSame('file', $firstPath['type']);
        $this->assertSame(5, $firstPath['size']);
        $this->assertIsInt($firstPath['ctime']);
    }

    public function testPlanRetainsACompactFreshLocalIndexAndExcludesOnlyPushAndDeletePaths(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'gone.txt' => [1, 1, 'file'],
            'private/gone.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex([
            'empty' => [2, 0, 'dir', true],
            'full/child.txt' => [2, 5, 'file'],
            'private/current.txt' => [2, 7, 'file'],
            'public.txt' => [2, 6, 'file'],
        ]);

        $plan = $this->startPlan($current, ['private']);
        $this->planToCompletion($plan);

        $this->assertPathCounts(3, 1);
        $this->assertSame(['empty', 'full/child.txt', 'public.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("gone.txt\0", file_get_contents($this->planPath('local_paths_to_delete')));

        $freshLocalIndexEntries = $this->indexEntries($this->planPath('fresh_local_index.jsonl'));
        $this->assertSame(
            ['empty', 'full/child.txt', 'private/current.txt', 'public.txt'],
            array_keys($freshLocalIndexEntries)
        );
        $this->assertTrue($freshLocalIndexEntries['empty']['empty']);
        $this->assertArrayNotHasKey('full', $freshLocalIndexEntries);
        $this->assertArrayNotHasKey('private', $freshLocalIndexEntries);
        $this->assertArrayNotHasKey('empty', $freshLocalIndexEntries['public.txt']);
    }

    public function testUnchangedIndexProducesEmptyPlans(): void
    {
        $index = $this->writeIndex([
            'a.txt' => [100, 5, 'file'],
            'b/c.txt' => [200, 7, 'file'],
        ]);
        $this->saveLocalIndex($index);

        $plan = $this->startPlan($index);
        $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testUnchangedRawPathSortedIndexProducesEmptyPlans(): void
    {
        $index = $this->writeIndex([
            'wp-admin/js/widgets.js' => [100, 5, 'file'],
            'wp-admin/js/widgets/custom-html-widgets.js' => [200, 7, 'file'],
        ]);
        $this->saveLocalIndex($index);
        sort_index_file($this->localIndexFile());

        $plan = $this->startPlan($index);
        $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testCtimeSizeOrTypeChangeEachMarksThePathChanged(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'ctime-bump.txt' => [100, 5, 'file'],
            'size-bump.txt' => [100, 5, 'file'],
            'type-swap' => [100, 5, 'file'],
            'same.txt' => [100, 5, 'file'],
        ]));
        $previousStat = lstat($this->filesystemRoot() . '/ctime-bump.txt');
        $this->assertIsArray($previousStat);
        $previousCtime = $previousStat['ctime'];
        while (time() <= $previousCtime) {
            usleep(10000);
        }
        $current = $this->writeIndex([
            'ctime-bump.txt' => [101, 5, 'file'],
            'size-bump.txt' => [100, 6, 'file'],
            'type-swap' => [100, 5, 'link'],
            'same.txt' => [100, 5, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $this->planToCompletion($plan);

        $this->assertPathCounts(3, 0);
        $this->assertSame(
            ['ctime-bump.txt', 'size-bump.txt', 'type-swap'],
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
    }

    public function testEveryLogicalTypeTransitionEmitsOnlyTheRequiredPushAndDeletePaths(): void
    {
        $matrix = [
            'file' => [
                'file' => [['value'], []],
                'symlink' => [['value'], []],
                'empty_directory' => [['value'], ['value']],
            ],
            'symlink' => [
                'file' => [['value'], []],
                'symlink' => [['value'], []],
                'empty_directory' => [['value'], ['value']],
            ],
            'empty_directory' => [
                'file' => [['value'], ['value']],
                'symlink' => [['value'], ['value']],
                'empty_directory' => [[], []],
            ],
        ];

        foreach ($matrix as $previousType => $transitions) {
            foreach ($transitions as $currentType => [$expectedPushes, $expectedDeletes]) {
                $this->saveLocalIndex($this->writeLogicalIndex($previousType, 1));
                $current = $this->writeLogicalIndex($currentType, 2);

                $plan = $this->startPlan($current);
                $this->planToCompletion($plan);
                $message = $previousType . ' to ' . $currentType;

                $this->assertSame($expectedPushes, $this->listPaths($this->planPath('local_paths_to_push.jsonl')), $message);
                $this->assertSame($expectedDeletes, $this->localPathsToDelete($this->planPath('local_paths_to_delete')), $message);
                $this->assertPathCounts(count($expectedPushes), count($expectedDeletes), $message);
                $this->removePlanDirectory();
            }
        }
    }

    public function testDeletedSubtreeEmitsOnlyItsRootAsALocalPathToDelete(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'gone/child.txt' => [1, 1, 'file'],
            'gone/nested/leaf.txt' => [1, 1, 'file'],
            'stays.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex(['stays.txt' => [1, 1, 'file']]);

        $plan = $this->startPlan($current);
        $this->planToCompletion($plan);

        $this->assertPathCounts(0, 1);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("gone\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testDeletedDirectoryStackAppendsWithoutGrowingTheCursor(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'a/child.txt' => [1, 1, 'file'],
            'b.txt' => [1, 1, 'file'],
        ]));
        $plan = $this->startPlan();

        $this->assertTrue($this->nextPlanStep($plan));
        $stack_bytes = filesize($this->planPath('deleted_directories_stack.jsonl'));
        $this->assertIsInt($stack_bytes);
        $this->assertGreaterThan(0, $stack_bytes);
        $first_cursor = $this->planCursor();
        $this->assertSame(0, $first_cursor['deleted_directory_stack_top_byte_offset']);

        $this->assertFalse($this->nextPlanStep($plan));
        $complete_cursor = $this->planCursor();
        $this->assertSame(
            [
                'phase' => 'complete',
                'local_paths_to_push_count' => 0,
                'local_file_bytes_to_push' => 0,
            ],
            $complete_cursor
        );
        $this->assertSame($stack_bytes, filesize($this->planPath('deleted_directories_stack.jsonl')));
        $plan->close();

        $this->assertSame("a\0b.txt\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testSeenDeletedDirectorySurvivesAnInterleavedSiblingCursor(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $entries = [];
        for ($index = 0; $index < 3; ++$index) {
            // `-` sorts before `/`, placing these siblings between a and a/child.txt.
            $entries[sprintf('a-%04d.txt', $index)] = [2, 1, 'file'];
        }
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current);

        $this->assertTrue($this->nextPlanStep($plan));
        $plan->close();
        $resumedPlan = $this->resumePlan();
        $this->planToCompletion($resumedPlan);

        $this->assertPathCounts(3, 1);
        $this->assertSame("a\0", file_get_contents($this->planPath('local_paths_to_delete')));
        $this->assertCount(3, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
    }

    public function testReplacementRootSurvivesLexicallyInterleavedSiblingPaths(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'a/child.txt' => [1, 1, 'file'],
        ]));
        $current = $this->writeIndex([
            'a' => [2, 1, 'file'],
            // `-` sorts before `/`, so this sibling appears before a/child.txt.
            'a-other' => [2, 1, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $this->planToCompletion($plan);

        $this->assertPathCounts(2, 1);
        $this->assertSame(['a', 'a-other'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("a\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testNewChangedDeletedAndUnchangedPathsArePlannedTogether(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'changed.txt' => [100, 5, 'file'],
            'deleted.txt' => [100, 5, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]));
        $current = $this->writeIndex([
            'added.txt' => [300, 3, 'file'],
            'changed.txt' => [200, 6, 'file'],
            'unchanged.txt' => [100, 5, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $this->planToCompletion($plan);

        $this->assertPathCounts(2, 1);
        // Output order follows decoded path order from the indexes.
        $this->assertSame(['added.txt', 'changed.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame("deleted.txt\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testSavingTheFreshLocalIndexAsTheLocalIndexClearsPlanOutput(): void
    {
        $index = $this->writeIndex(['a.txt' => [100, 5, 'file']]);
        $plan = $this->startPlan($index);

        $this->planToCompletion($plan);
        $this->assertSame(['a.txt'], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));

        copy($this->planPath('fresh_local_index.jsonl'), $this->localIndexFile());
        $this->removePlanDirectory();
        $this->assertDirectoryDoesNotExist($this->planDirectory());
        $plan = $this->startPlan($index);
        $this->planToCompletion($plan);

        $this->assertPathCounts(0, 0);
        $this->assertSame([], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame('', file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testPathsThatNeedBase64SurvivePushAndDeletePlans(): void
    {
        // Newlines and non-ASCII bytes are why JSONL indexes encode paths.
        $newPath = "wp-content/uploads/line\nbreak.png";
        $deletedPath = 'wp-content/uploads/naïve-café.jpg';
        $this->saveLocalIndex($this->writeIndex([
            $deletedPath => [100, 6, 'file'],
        ]));
        $current = $this->writeIndex([
            $newPath => [100, 5, 'file'],
        ]);

        $plan = $this->startPlan($current);
        $this->planToCompletion($plan);

        $this->assertPathCounts(1, 1);
        $this->assertSame([$newPath], $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame($deletedPath . "\0", file_get_contents($this->planPath('local_paths_to_delete')));
    }

    // ------------------------------------------------------------------
    //  Bounded steps and restart
    // ------------------------------------------------------------------

    public function testIndexingFinishesBeforeDiffingAndClearsOldOutput(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'gone.txt' => [1, 1, 'file'],
        ]));
        $oldIndex = $this->writeIndex(['old.txt' => [1, 1, 'file']]);
        $plan = $this->startPlan($oldIndex);
        $this->planToCompletion($plan);
        $this->assertGreaterThan(0, filesize($this->planPath('fresh_local_index.jsonl')));
        $this->assertGreaterThan(0, filesize($this->planPath('local_paths_to_push.jsonl')));
        $this->assertGreaterThan(0, filesize($this->planPath('local_paths_to_delete')));
        $this->removePlanDirectory();

        $current = $this->writeIndex($this->manyFileEntries(2));
        $plan = $this->startPlan($current);
        $this->assertCount(2, $this->indexEntries($this->planPath('fresh_local_index.jsonl')));
        $this->assertSame('diffing', $this->planCursor()['phase']);
        $this->assertFileDoesNotExist($this->planPath('cursor.json'));
        $this->assertSame(0, filesize($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(0, filesize($this->planPath('local_paths_to_delete')));
        $plan->close();
    }

    public function testStepProcessesOnePathAndResumes(): void
    {
        $entries = $this->manyFileEntries(2);
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current);

        $this->assertTrue($this->nextPlanStep($plan));

        $this->assertPathCounts(1, 0);
        $this->assertCount(1, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(0, filesize($this->planPath('local_paths_to_delete')));
        $plan->close();

        $resumedPlan = $this->resumePlan();
        $this->planToCompletion($resumedPlan);

        $this->assertPathCounts(2, 0);
        $this->assertCount(2, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertCount(2, $this->indexEntries($this->planPath('fresh_local_index.jsonl')));
    }

    public function testReportsDiffProgressAcrossResume(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'deleted-1.txt' => [1, 1, 'file'],
            'deleted-2.txt' => [1, 1, 'file'],
        ]));
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(3)));

        $progress = $plan->get_progress();
        $this->assertSame('diffing', $progress['phase']);
        $this->assertSame(0, $progress['index_bytes_done']);
        $this->assertGreaterThan(0, $progress['index_bytes_total']);

        $this->assertTrue($this->nextPlanStep($plan));
        $progress = $plan->get_progress();
        $this->assertSame('diffing', $progress['phase']);
        $this->assertGreaterThan(0, $progress['index_bytes_done']);
        $this->assertLessThan($progress['index_bytes_total'], $progress['index_bytes_done']);
        $plan->close();

        $resumedPlan = $this->resumePlan();
        $this->assertSame($progress, $resumedPlan->get_progress());
        $resumedPlan->close();
    }

    public function testResumesCursorWrittenBeforeDocumentRootMappingAndProgressTotals(): void
    {
        $entries = $this->manyFileEntries(2);
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current);

        $this->assertTrue($this->nextPlanStep($plan));
        $plan->close();

        unset($this->cursor['document_root_local_relative_path']);
        unset($this->cursor['position']['local_paths_to_push_count']);
        unset($this->cursor['position']['local_file_bytes_to_push']);

        $resumedPlan = $this->resumePlan();
        $this->planToCompletion($resumedPlan);

        $this->assertCount(2, $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertNull($this->planCursor()['local_paths_to_push_count']);
        $this->assertNull($this->planCursor()['local_file_bytes_to_push']);
    }

    public function testResumeDiscardsACompletedStepWhoseCursorWasNotStored(): void
    {
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(2)));
        $stored_cursor = $this->cursor;

        // Simulate the process stopping after PushPlan returns but before its caller stores the new cursor.
        $this->assertTrue($plan->next_step());
        $this->assertPathCounts(1, 0);
        $plan->close();

        $this->cursor = $stored_cursor;
        $resumed_plan = $this->resumePlan();
        $this->planToCompletion($resumed_plan);

        $this->assertPathCounts(2, 0);
        $this->assertSame(
            ['file-0000.txt', 'file-0001.txt'],
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
    }

    public function testStepRetainsTheFollowingFreshLocalIndexEntryUntilClose(): void
    {
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(3)));
        $index_diff_processor_property = new ReflectionProperty(
            PushPlan::class,
            'index_diff_processor'
        );
        $new_index_handle_property = new ReflectionProperty(
            FileIndexDiffProcessor::class,
            'new_index_handle'
        );
        $new_index_entry_property = new ReflectionProperty(
            FileIndexDiffProcessor::class,
            'new_index_entry'
        );

        $this->assertTrue($this->nextPlanStep($plan));
        $first_plan_cursor = $this->planCursor();
        $index_diff_processor = $index_diff_processor_property->getValue($plan);
        $fresh_local_index_handle = $new_index_handle_property->getValue(
            $index_diff_processor
        );
        $this->assertIsResource($fresh_local_index_handle);
        $this->assertGreaterThan(
            $first_plan_cursor['byte_offset_in_fresh_local_index'],
            ftell($fresh_local_index_handle)
        );
        $retained_fresh_local_index_entry = $new_index_entry_property->getValue(
            $index_diff_processor
        );
        $this->assertIsArray($retained_fresh_local_index_entry);
        $this->assertSame('file-0001.txt', $retained_fresh_local_index_entry['path']);

        $this->assertTrue($this->nextPlanStep($plan));
        $second_plan_cursor = $this->planCursor();
        $this->assertGreaterThan(
            $second_plan_cursor['byte_offset_in_fresh_local_index'],
            ftell($fresh_local_index_handle)
        );
        $this->assertPathCounts(2, 0);

        $plan->close();
        $plan->close();
        $resumed_plan = $this->resumePlan();
        $this->assertFalse($this->nextPlanStep($resumed_plan));
        $this->assertFalse($this->nextPlanStep($resumed_plan));
        $resumed_plan->close();
        $resumed_plan->close();
        $this->assertFalse($this->nextPlanStep($resumed_plan));
    }

    public function testCursorContainsResumePathsOffsetsAndCompletionState(): void
    {
        $plan = $this->startPlan($this->writeIndex($this->manyFileEntries(2)));
        $this->assertTrue($this->nextPlanStep($plan));

        $cursor = $plan->get_cursor();
        $this->assertSame([
            'plan_directory',
            'filesystem_root',
            'local_index_file',
            'document_root_local_relative_path',
            'position',
        ], array_keys($cursor));
        $this->assertSame($this->planDirectory(), $cursor['plan_directory']);
        $this->assertSame(realpath($this->filesystemRoot()), $cursor['filesystem_root']);
        $this->assertSame($this->localIndexFile(), $cursor['local_index_file']);
        $this->assertSame('', $cursor['document_root_local_relative_path']);
        $this->assertSame([
            'phase',
            'byte_offset_in_fresh_local_index',
            'byte_offset_in_local_index',
            'byte_offset_in_local_paths_to_push',
            'byte_offset_in_local_paths_to_delete',
            'local_paths_to_push_count',
            'local_file_bytes_to_push',
            'deleted_directory_stack_top_byte_offset',
            'preceding_fresh_local_index_entry_path',
        ], array_keys($cursor['position']));
        $this->assertSame(1, $cursor['position']['local_paths_to_push_count']);
        $this->assertSame(1, $cursor['position']['local_file_bytes_to_push']);
        $this->assertSame(
            filesize($this->planPath('local_paths_to_push.jsonl')),
            $cursor['position']['byte_offset_in_local_paths_to_push']
        );
        $this->assertSame(
            filesize($this->planPath('local_paths_to_delete')),
            $cursor['position']['byte_offset_in_local_paths_to_delete']
        );
        $this->assertFileDoesNotExist($this->planPath('cursor.json'));
        $plan->close();
    }

    public function testCursorKeepsTheFilesystemRootSlash(): void
    {
        mkdir($this->planDirectory(), 0755, true);
        file_put_contents($this->excludedPathsPath(), '[]');

        $plan = PushPlan::start(
            $this->planDirectory(),
            '/',
            $this->localIndexFile(),
            $this->excludedPathsPath()
        );

        try {
            $this->assertSame('/', $plan->get_cursor()['filesystem_root']);
        } finally {
            $plan->close();
        }
    }

    public function testNewInstanceResumesFromTheRetainedCursor(): void
    {
        $currentEntries = [];
        $localIndexEntries = [];
        for ($index = 0; $index < 3; ++$index) {
            // The current and deleted names alternate in decoded sort order,
            // so every batch appends to both path files.
            $currentEntries[sprintf('item-%04d-current.txt', $index)] = [$index + 1, 1, 'file'];
            $localIndexEntries[sprintf('item-%04d-deleted.txt', $index)] = [$index + 1, 1, 'file'];
        }
        $current = $this->writeIndex($currentEntries);
        $this->saveLocalIndex($this->writeIndex($localIndexEntries));
        $plan = $this->startPlan($current);

        $this->assertTrue($this->nextPlanStep($plan));
        $firstPushBytes = filesize($this->planPath('local_paths_to_push.jsonl'));
        $firstDeleteBytes = filesize($this->planPath('local_paths_to_delete'));
        $plan->close();

        $reopened = $this->resumePlan();
        $this->assertTrue($this->nextPlanStep($reopened));
        $this->assertSame(
            $firstPushBytes,
            filesize($this->planPath('local_paths_to_push.jsonl'))
        );
        $this->assertGreaterThan(
            $firstDeleteBytes,
            filesize($this->planPath('local_paths_to_delete'))
        );

        $this->planToCompletion($reopened);

        $this->assertPathCounts(3, 3);
        $this->assertSame(3, $this->planCursor()['local_paths_to_push_count']);
        $this->assertSame(3, $this->planCursor()['local_file_bytes_to_push']);
        $this->assertSame(array_keys($currentEntries), $this->listPaths($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame(array_keys($localIndexEntries), $this->localPathsToDelete($this->planPath('local_paths_to_delete')));
        $this->assertCount(3, $this->indexEntries($this->planPath('fresh_local_index.jsonl')));
    }

    public function testSavedOffsetsAndCompletedEofAreIdempotentAfterRestart(): void
    {
        $current = $this->writeIndex($this->manyFileEntries(2));
        $plan = $this->startPlan($current);
        $this->assertTrue($this->nextPlanStep($plan));
        $plan->close();

        $reopened = $this->resumePlan();
        $this->assertFalse($this->nextPlanStep($reopened));
        $freshLocalIndex = file_get_contents($this->planPath('fresh_local_index.jsonl'));
        $pathsToPush = file_get_contents($this->planPath('local_paths_to_push.jsonl'));
        $localPathsToDelete = file_get_contents($this->planPath('local_paths_to_delete'));
        $reopened->close();

        $replayedPlan = $this->resumePlan();
        $replayedEof = $this->nextPlanStep($replayedPlan);
        $replayedPlan->close();

        $this->assertFalse($replayedEof);
        $this->assertSame($freshLocalIndex, file_get_contents($this->planPath('fresh_local_index.jsonl')));
        $this->assertSame($pathsToPush, file_get_contents($this->planPath('local_paths_to_push.jsonl')));
        $this->assertSame($localPathsToDelete, file_get_contents($this->planPath('local_paths_to_delete')));
    }

    public function testResumeUsesTheExcludedPathsSavedByStart(): void
    {
        $entries = $this->manyFileEntries(2);
        $entries['private/value.txt'] = [2000, 1, 'file'];
        $current = $this->writeIndex($entries);
        $plan = $this->startPlan($current, ['private']);
        $this->assertSame(
            file_get_contents($this->excludedPathsPath()),
            file_get_contents($this->planPath('excluded_paths.json'))
        );
        unlink($this->excludedPathsPath());

        $this->assertTrue($this->nextPlanStep($plan));
        $plan->close();

        $resumedPlan = $this->resumePlan();
        $this->planToCompletion($resumedPlan);

        $this->assertPathCounts(2, 0);
        $this->assertNotContains(
            'private/value.txt',
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
    }

    public function testPlanMapsDocumentRootAndIgnoresPathsOutsideIt(): void
    {
        $this->saveLocalIndex($this->writeIndex([
            'outside-deleted.txt' => [100, 5, 'file'],
            'var/www/html/deleted.txt' => [100, 5, 'file'],
        ]));
        $current = $this->writeIndex([
            '.htaccess' => [300, 3, 'file'],
            'var/www/html/added.txt' => [300, 3, 'file'],
            'var/www/html/preserved/value.txt' => [300, 3, 'file'],
        ]);

        $plan = $this->startPlan(
            $current,
            ['preserved'],
            'var/www/html'
        );
        $plan->close();
        $plan = $this->resumePlan();
        $this->planToCompletion($plan);

        $this->assertSame(
            ['var/www/html/added.txt'],
            $this->listPaths($this->planPath('local_paths_to_push.jsonl'))
        );
        $this->assertSame(
            "deleted.txt\0",
            file_get_contents($this->planPath('local_paths_to_delete'))
        );
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** @param list<string> $excludedPaths */
    private function startPlan(
        ?string $filesystemRootDescriptionFile = null,
        array $excludedPaths = [],
        string $documentRootLocalRelativePath = ''
    ): PushPlan {
        if ($filesystemRootDescriptionFile === null) {
            $filesystemRootDescriptionFile = $this->tempDir . '/empty-filesystem-root.jsonl';
            if (!is_file($filesystemRootDescriptionFile)) {
                file_put_contents($filesystemRootDescriptionFile, '');
            }
        }
        $this->materializeFilesystemRoot($filesystemRootDescriptionFile);
        if (!is_dir($this->planDirectory())) {
            mkdir($this->planDirectory(), 0755, true);
        }
        file_put_contents(
            $this->excludedPathsPath(),
            json_encode(
                array_map('base64_encode', $excludedPaths),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
        $plan = PushPlan::start(
            $this->planDirectory(),
            $this->filesystemRoot(),
            $this->localIndexFile(),
            $this->excludedPathsPath(),
            $documentRootLocalRelativePath
        );
        $this->cursor = $plan->get_cursor();
        for ($step = 0; $step < 100; ++$step) {
            if ($this->planCursor()['phase'] === 'diffing') {
                return $plan;
            }
            $this->assertTrue($this->nextPlanStep($plan));
        }
        $this->fail('Push plan did not finish indexing within 100 bounded steps.');
    }

    private function resumePlan(): PushPlan
    {
        return PushPlan::resume($this->cursor);
    }

    private function saveLocalIndex(string $filesystemRootDescriptionFile): void
    {
        $plan = $this->startPlan($filesystemRootDescriptionFile);
        $this->planToCompletion($plan);
        copy($this->planPath('fresh_local_index.jsonl'), $this->localIndexFile());
        $this->removePlanDirectory();
    }

    /**
     * Continue the plan until it reports that both indexes were consumed.
     *
     */
    private function planToCompletion(PushPlan $plan): void
    {
        for ($step = 0; $step < 100; ++$step) {
            if (!$this->nextPlanStep($plan)) {
                $plan->close();
                return;
            }
        }
        $this->fail('Push plan did not complete within 100 bounded steps.');
    }

    /**
     * Performs one plan step and stores its cursor as the caller would.
     */
    private function nextPlanStep(PushPlan $plan): bool
    {
        $has_next_step = $plan->next_step();
        $this->cursor = $plan->get_cursor();
        return $has_next_step;
    }

    /** @return array<string,mixed> */
    private function planCursor(): array
    {
        return $this->cursor['position'];
    }

    private function assertPathCounts(
        int $localPathsToPushCount,
        int $localPathsToDeleteCount,
        string $message = ''
    ): void {
        $this->assertCount(
            $localPathsToPushCount,
            $this->listPaths($this->planPath('local_paths_to_push.jsonl')),
            $message
        );
        $localPathsToDelete = file_get_contents($this->planPath('local_paths_to_delete'));
        $this->assertIsString($localPathsToDelete);
        $this->assertSame($localPathsToDeleteCount, substr_count($localPathsToDelete, "\0"), $message);
    }

    private function planDirectory(): string
    {
        return $this->pushStateDirectory() . '/plan';
    }

    private function planPath(string $filename): string
    {
        return $this->planDirectory() . '/' . $filename;
    }

    private function pushStateDirectory(): string
    {
        return $this->remoteStateDirectory() . '/push';
    }

    private function remoteStateDirectory(): string
    {
        return $this->tempDir . '/state/remotes/example.com';
    }

    private function localIndexFile(): string
    {
        return $this->remoteStateDirectory() . '/local_index.jsonl';
    }

    private function excludedPathsPath(): string
    {
        return $this->pushStateDirectory() . '/excluded_paths.json';
    }

    private function removePlanDirectory(): void
    {
        $this->recursiveDelete($this->planDirectory());
    }

    private function filesystemRoot(): string
    {
        return $this->tempDir . '/filesystem-root';
    }

    /**
     * Makes the filesystem root match one test description while preserving unchanged paths.
     *
     * @param string $filesystemRootDescriptionFile JSONL path descriptions created by writeIndex().
     */
    private function materializeFilesystemRoot(string $filesystemRootDescriptionFile): void
    {
        $entries = $this->indexEntries($filesystemRootDescriptionFile);
        if (!is_dir($this->filesystemRoot())) {
            mkdir($this->filesystemRoot(), 0755, true);
        }

        $pathsToKeep = $this->pathsRequiredByIndexEntries($entries);
        $previousPaths = $this->pathsRequiredByIndexEntries($this->materializedIndexEntries);
        $pathsToRemove = array_keys(array_diff_key($previousPaths, $pathsToKeep));
        usort($pathsToRemove, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($pathsToRemove as $path) {
            $this->recursiveDelete($this->filesystemRoot() . '/' . $path);
        }

        $implicitDirectories = array_keys(array_diff_key($pathsToKeep, $entries));
        usort($implicitDirectories, static fn(string $left, string $right): int => strlen($left) <=> strlen($right));
        foreach ($implicitDirectories as $path) {
            $localPath = $this->filesystemRoot() . '/' . $path;
            if (!is_dir($localPath) || is_link($localPath)) {
                $this->recursiveDelete($localPath);
                mkdir($localPath, 0755, true);
            }
        }

        uksort($entries, static function (string $left, string $right): int {
            $depthComparison = substr_count($left, '/') <=> substr_count($right, '/');
            return $depthComparison !== 0 ? $depthComparison : strcmp($left, $right);
        });
        foreach ($entries as $path => $entry) {
            $localPath = $this->filesystemRoot() . '/' . $path;
            $parentDirectory = dirname($localPath);
            if (!is_dir($parentDirectory)) {
                mkdir($parentDirectory, 0755, true);
            }
            $previousEntry = $this->materializedIndexEntries[$path] ?? null;
            if ($entry['type'] === 'dir') {
                if (!is_dir($localPath) || is_link($localPath)) {
                    $this->recursiveDelete($localPath);
                    mkdir($localPath, 0755, true);
                }
                continue;
            }
            if ($previousEntry === $entry) {
                continue;
            }
            $this->recursiveDelete($localPath);
            if ($entry['type'] === 'link') {
                symlink(str_repeat('x', max(1, (int) $entry['ctime'])), $localPath);
                continue;
            }
            $byte = chr(97 + ( (int) $entry['ctime'] % 26 ));
            file_put_contents($localPath, str_repeat($byte, (int) $entry['size']));
        }
        $this->materializedIndexEntries = $entries;
    }

    /**
     * Returns every described path and the implicit parent directories it needs.
     *
     * @param array<string,array<string,mixed>> $entries Index entries keyed by local path.
     * @return array<string,true>
     */
    private function pathsRequiredByIndexEntries(array $entries): array
    {
        $paths = [];
        foreach (array_keys($entries) as $path) {
            $paths[$path] = true;
            $parent = dirname($path);
            while ($parent !== '.' && $parent !== '') {
                $paths[$parent] = true;
                $parent = dirname($parent);
            }
        }
        return $paths;
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
        throw new InvalidArgumentException("Unknown logical index type: {$logicalType}");
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
     * Writes a sorted description of paths, versions, sizes, types, and directory emptiness.
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
        $this->assertSame('', array_pop($paths), 'The local_paths_to_delete file must end after a NUL-terminated path.');
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
