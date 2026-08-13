<?php

declare(strict_types=1);

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test classes.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-patch-planner.php';

/**
 * Covers file-sync patch operations, tree boundaries, and resume cursors.
 *
 * @phpstan-type ExpectedSource array{type:string,size:int,ctime:int}
 * @phpstan-type SyncOperation array{action:'delete',path:string}|array{action:'copy'|'replace',path:string,expected_source:ExpectedSource}
 */
final class FileSyncPatchPlannerTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir()
            . '/file-sync-patch-planner-'
            . bin2hex(random_bytes(6));
        mkdir($this->temp_dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->temp_dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->temp_dir . '/' . $entry);
            }
        }
        rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testPlansAddedModifiedDeletedAndReplacedPaths(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'b-deleted.txt' => $this->entry('b-deleted.txt'),
            'c-empty-to-file' => $this->entry('c-empty-to-file', 1, 0, 'dir'),
            'd-file-to-empty' => $this->entry('d-file-to-empty'),
            'e-modified.txt' => $this->entry('e-modified.txt', 1),
            'f-unchanged.txt' => $this->entry('f-unchanged.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', [
            'a-added.txt' => $this->entry('a-added.txt', 2, 3),
            'c-empty-to-file' => $this->entry('c-empty-to-file', 2, 4),
            'd-file-to-empty' => $this->entry('d-file-to-empty', 2, 0, 'dir'),
            'e-modified.txt' => $this->entry('e-modified.txt', 2),
            'f-unchanged.txt' => $this->entry('f-unchanged.txt'),
        ]);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file()
        );

        $this->assertSame(
            [
                $this->copy_operation('copy', 'a-added.txt', 'file', 3, 2),
                $this->delete_operation('b-deleted.txt'),
                $this->copy_operation('replace', 'c-empty-to-file', 'file', 4, 2),
                $this->copy_operation('replace', 'd-file-to-empty', 'dir', 0, 2),
                $this->copy_operation('copy', 'e-modified.txt', 'file', 1, 2),
                null,
            ],
            $this->collect_operations($planner)
        );
        $this->assertTrue($planner->is_complete());
        $this->assertFalse($planner->next_path());
        $planner->close();
    }

    public function testReplacesASubtreeWithOnePatchResultPath(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'tree/child.txt' => $this->entry('tree/child.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', [
            'tree' => $this->entry('tree', 2),
            // `-` sorts before `/`, so this sibling appears before tree/child.
            'tree-other.txt' => $this->entry('tree-other.txt', 2),
        ]);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file()
        );

        $this->assertSame(
            [
                $this->copy_operation('replace', 'tree', 'file', 1, 2),
                $this->copy_operation('copy', 'tree-other.txt', 'file', 1, 2),
                null,
            ],
            $this->collect_operations($planner)
        );
        $planner->close();
    }

    public function testCollapsesADeletedSubtreeToItsRoot(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'gone/child.txt' => $this->entry('gone/child.txt'),
            'gone/nested/leaf.txt' => $this->entry('gone/nested/leaf.txt'),
            'stays.txt' => $this->entry('stays.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', [
            'stays.txt' => $this->entry('stays.txt'),
        ]);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file()
        );

        $this->assertSame(
            [
                $this->delete_operation('gone'),
                null,
                null,
            ],
            $this->collect_operations($planner)
        );
        $planner->close();
    }

    public function testCollapsesADeletedSubtreeAtANestedIncludedRoot(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'outside/selected/child.txt' =>
                $this->entry('outside/selected/child.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', []);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file(),
            ['outside/selected']
        );

        $this->assertSame(
            [
                $this->delete_operation('outside/selected'),
            ],
            $this->collect_operations($planner)
        );
        $planner->close();
    }

    public function testCollapsesAnAllowedSiblingWithoutDeletingAnExcludedSubtree(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'selected/delete/child.txt' =>
                $this->entry('selected/delete/child.txt'),
            'selected/keep/child.txt' =>
                $this->entry('selected/keep/child.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', []);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file(),
            ['selected'],
            ['selected/keep']
        );

        $this->assertSame(
            [
                $this->delete_operation('selected/delete'),
                null,
            ],
            $this->collect_operations($planner)
        );
        $planner->close();
    }

    public function testIncludedAndExcludedRootsLimitBothOperations(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'outside/deleted.txt' => $this->entry('outside/deleted.txt'),
            'selected/delete.txt' => $this->entry('selected/delete.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', [
            'outside/added.txt' => $this->entry('outside/added.txt', 2),
            'selected/excluded/added.txt' =>
                $this->entry('selected/excluded/added.txt', 2),
            'selected/copied.txt' =>
                $this->entry('selected/copied.txt', 2),
        ]);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file(),
            ['selected'],
            ['selected/excluded']
        );

        $operations = $this->collect_operations($planner);
        $this->assertSame(
            [
                null,
                null,
                $this->copy_operation('copy', 'selected/copied.txt', 'file', 1, 2),
                $this->delete_operation('selected/delete.txt'),
                null,
            ],
            $operations
        );
        $planner->close();
    }

    public function testCursorBase64EncodesArbitraryByteSelectionRoots(): void
    {
        $included_root = "selected-\xff";
        $patch_base_index = $this->write_index('base.jsonl', [
            $included_root . '/file.txt' =>
                $this->entry($included_root . '/file.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', []);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file(),
            [$included_root]
        );
        $cursor = $planner->get_cursor();
        $this->assertSame(
            [base64_encode($included_root)],
            $cursor['included_index_path_roots_b64']
        );
        $this->assertIsString(
            json_encode($cursor, JSON_THROW_ON_ERROR)
        );
        $planner->close();

        $resumed = FileSyncPatchPlanner::resume($cursor);
        $this->assertSame(
            [$this->delete_operation($included_root)],
            $this->collect_operations($resumed)
        );
        $resumed->close();
    }

    public function testResumeKeepsAnActiveDeletionRootAcrossASibling(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'a/child.txt' => $this->entry('a/child.txt'),
        ]);
        $patch_result_index = $this->write_index('result.jsonl', [
            'a' => $this->entry('a', 2),
            'a-other.txt' => $this->entry('a-other.txt', 2),
        ]);
        $planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_result_index,
            $this->active_deletion_roots_file()
        );

        $this->assertTrue($planner->next_path());
        $this->assertSame(
            $this->copy_operation('replace', 'a', 'file', 1, 2),
            $planner->get_operation()
        );
        $planner->flush_pending_outputs();
        $cursor = $planner->get_cursor();
        $planner->close();

        $resumed_planner = FileSyncPatchPlanner::resume($cursor);
        $this->assertSame(
            [
                $this->copy_operation('copy', 'a-other.txt', 'file', 1, 2),
                null,
            ],
            $this->collect_operations($resumed_planner)
        );
        $resumed_planner->close();
        $resumed_planner->close();
    }

    /** @return list<SyncOperation|null> */
    private function collect_operations(FileSyncPatchPlanner $planner): array
    {
        $operations = [];
        while ($planner->next_path()) {
            $operations[] = $planner->get_operation();
        }
        return $operations;
    }

    /**
     * @param 'copy'|'replace' $action Whether the source entry replaces a conflicting path.
     * @return SyncOperation
     */
    private function copy_operation(
        string $action,
        string $path,
        string $type,
        int $size,
        int $ctime
    ): array {
        return [
            'action' => $action,
            'path' => $path,
            'expected_source' => [
                'type' => $type,
                'size' => $size,
                'ctime' => $ctime,
            ],
        ];
    }

    /** @return SyncOperation */
    private function delete_operation(string $path): array
    {
        return [
            'action' => 'delete',
            'path' => $path,
        ];
    }

    /**
     * @param array<string,array{path:string,ctime:int,size:int,type:string,empty?:bool}> $entries
     */
    private function write_index(string $filename, array $entries): string
    {
        ksort($entries, SORT_STRING);
        $lines = [];
        foreach ($entries as $entry) {
            $entry['path'] = base64_encode($entry['path']);
            $lines[] = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $path = $this->temp_dir . '/' . $filename;
        file_put_contents($path, $lines === [] ? '' : implode("\n", $lines) . "\n");
        return $path;
    }

    /** @return array{path:string,ctime:int,size:int,type:string,empty?:bool} */
    private function entry(
        string $path,
        int $ctime = 1,
        int $size = 1,
        string $type = 'file'
    ): array {
        $entry = [
            'path' => $path,
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ];
        if ($type === 'dir') {
            $entry['empty'] = true;
        }
        return $entry;
    }

    private function active_deletion_roots_file(): string
    {
        return $this->temp_dir . '/deleted-directories.jsonl';
    }
}
