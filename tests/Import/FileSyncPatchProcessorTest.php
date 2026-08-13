<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/utils.php';
require_once __DIR__ . '/../../packages/reprint-server/src/class-file-index-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-patch-processor.php';

final class FileSyncPatchProcessorTest extends TestCase {
    private string $temporary_directory;
    private string $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/file-sync-patch-processor-test-'
            . uniqid();
        $this->filesystem_root =
            $this->temporary_directory . '/filesystem-root';
        mkdir($this->filesystem_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temporary_directory);
        parent::tearDown();
    }

    public function testStartMethodsPlanOppositePatchDirections(): void
    {
        file_put_contents($this->filesystem_root . '/current.txt', 'current');
        $saved_index = $this->write_index('saved.jsonl', [
            $this->entry('saved.txt', 5),
        ]);

        $to_fresh_work_directory = $this->work_directory('to-fresh');
        $to_fresh = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $to_fresh_work_directory,
            $this->filesystem_root,
            $saved_index,
            $to_fresh_work_directory
        );
        $this->assertSame(
            [
                ['action' => 'copy', 'path' => 'current.txt'],
                ['action' => 'delete', 'path' => 'saved.txt'],
            ],
            $this->operation_names($this->run_to_completion($to_fresh))
        );

        $from_fresh_work_directory = $this->work_directory('from-fresh');
        $from_fresh = FileSyncPatchProcessor::start_from_fresh_local_tree(
            $from_fresh_work_directory,
            $this->filesystem_root,
            $saved_index,
            $from_fresh_work_directory
        );
        $this->assertSame(
            [
                [
                    'action' => 'delete',
                    'path' => 'current.txt',
                ],
                [
                    'action' => 'copy',
                    'path' => 'saved.txt',
                    'expected_source' => [
                        'type' => 'file',
                        'size' => 5,
                        'ctime' => 1,
                    ],
                ],
            ],
            $this->run_to_completion($from_fresh)
        );
    }

    public function testSortingAndPlannerStartupHaveSeparateSteps(): void
    {
        file_put_contents($this->filesystem_root . '/file.txt', 'file');
        $work_directory = $this->work_directory('phases');
        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $this->filesystem_root,
            $this->temporary_directory . '/missing-index.jsonl',
            $work_directory
        );

        $this->assertSame('indexing', $processor->get_phase());
        for ($step = 0; $step < 20; ++$step) {
            $this->assertTrue($processor->next_step());
            if ($processor->get_phase() === 'sorting') {
                break;
            }
        }
        $this->assertSame('sorting', $processor->get_phase());

        $this->assertTrue($processor->next_step());
        $this->assertSame('starting_patch', $processor->get_phase());
        $this->assertNull($processor->get_operation());

        $this->assertTrue($processor->next_step());
        $this->assertSame('planning', $processor->get_phase());
        $this->assertNull($processor->get_operation());
        $this->assertSame(
            ['phase', 'file_sync_patch_planner_cursor'],
            array_keys($processor->get_cursor()['position'])
        );
        $processor->close();
    }

    public function testResumeUsesOnlyTheCursorAndDiscardsUnstoredIndexBytes(): void
    {
        file_put_contents($this->filesystem_root . '/a.txt', 'a');
        file_put_contents($this->filesystem_root . '/b.txt', 'b');
        $work_directory = $this->work_directory('resume');
        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $this->filesystem_root,
            $this->temporary_directory . '/missing-index.jsonl',
            $work_directory
        );

        do {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_outputs();
            $saved_cursor = $processor->get_cursor();
            $saved_offset = $saved_cursor['position'][
                'fresh_local_index_cursor'
            ]['position']['fresh_local_index_byte_offset'];
        } while ($saved_offset === 0);

        do {
            $this->assertTrue($processor->next_step());
            clearstatcache(
                true,
                $processor->get_fresh_local_index_path()
            );
            $fresh_index_bytes = filesize(
                $processor->get_fresh_local_index_path()
            );
            $this->assertIsInt($fresh_index_bytes);
        } while ($fresh_index_bytes <= $saved_offset);
        $processor->close();

        $resumed = FileSyncPatchProcessor::resume($saved_cursor);
        $this->run_to_completion($resumed);
        $this->assertSame(
            ['a.txt', 'b.txt'],
            $this->read_index_paths(
                $work_directory . '/fresh_local_index.jsonl'
            )
        );
    }

    public function testCompleteAndCloseAreStable(): void
    {
        $work_directory = $this->work_directory('complete');
        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $this->filesystem_root,
            $this->temporary_directory . '/missing-index.jsonl',
            $work_directory
        );
        $this->run_to_completion($processor);
        $complete_cursor = $processor->get_cursor();

        $this->assertFalse($processor->next_step());
        $processor->close();
        $processor->close();
        $this->assertFalse($processor->next_step());
        $this->assertSame($complete_cursor, $processor->get_cursor());

        $resumed = FileSyncPatchProcessor::resume($complete_cursor);
        $this->assertFalse($resumed->next_step());
        $resumed->close();
    }

    public function testFinalStepReturnsItsOperationBeforeStableComplete(): void
    {
        file_put_contents($this->filesystem_root . '/only.txt', 'only');
        $work_directory = $this->work_directory('final-operation');
        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $this->filesystem_root,
            $this->temporary_directory . '/missing-index.jsonl',
            $work_directory
        );
        for ($step = 0; $step < 20; ++$step) {
            if ($processor->get_phase() === 'planning') {
                break;
            }
            $this->assertTrue($processor->next_step());
        }

        $this->assertSame('planning', $processor->get_phase());
        $this->assertFalse($processor->next_step());
        $operation = $processor->get_operation();
        $this->assertIsArray($operation);
        $this->assertSame('copy', $operation['action']);
        $this->assertSame('only.txt', $operation['path']);

        $this->assertFalse($processor->next_step());
        $this->assertNull($processor->get_operation());
        $processor->close();
    }

    public function testCursorBase64EncodesArbitraryByteSelectionRoots(): void
    {
        $included_root = "selected-\xff";
        $excluded_root = $included_root . "/excluded-\xfe";
        $saved_index = $this->write_index('arbitrary-byte-saved.jsonl', [
            $this->entry($included_root . '/file.txt', 1),
        ]);
        $work_directory = $this->work_directory('arbitrary-byte-root');
        $storage_path = $work_directory . "/storage-\xfd";
        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $this->filesystem_root,
            $saved_index,
            $storage_path,
            [$included_root],
            [$excluded_root]
        );
        $cursor = $this->serialize_cursor($processor->get_cursor());
        $this->assertSame(
            ['fresh_local_index_file_b64', 'position'],
            array_keys($cursor)
        );
        $this->assertSame(
            [
                'phase',
                'patch_base_index_file_b64',
                'patch_result_index_file_b64',
                'included_index_path_roots_b64',
                'excluded_index_path_roots_b64',
                'fresh_local_index_cursor',
            ],
            array_keys($cursor['position'])
        );
        $this->assertSame(
            [base64_encode($included_root)],
            $cursor['position']['included_index_path_roots_b64']
        );
        $this->assertSame(
            [base64_encode($excluded_root)],
            $cursor['position']['excluded_index_path_roots_b64']
        );
        $this->assertSame(
            base64_encode($storage_path),
            $cursor['position']['fresh_local_index_cursor']['storage_path_b64']
        );
        $processor->close();

        $resumed = FileSyncPatchProcessor::resume($cursor);
        while ($resumed->get_phase() !== 'sorting') {
            $this->assertTrue($resumed->next_step());
        }
        $sorting_cursor = $this->serialize_cursor($resumed->get_cursor());
        $resumed->close();

        $resumed = FileSyncPatchProcessor::resume($sorting_cursor);
        $this->assertTrue($resumed->next_step());
        $starting_patch_cursor = $this->serialize_cursor(
            $resumed->get_cursor()
        );
        $this->assertSame('starting_patch', $resumed->get_phase());
        $resumed->close();

        $resumed = FileSyncPatchProcessor::resume($starting_patch_cursor);
        $this->assertTrue($resumed->next_step());
        $planning_cursor = $this->serialize_cursor($resumed->get_cursor());
        $this->assertSame('planning', $resumed->get_phase());
        $resumed->close();

        $resumed = FileSyncPatchProcessor::resume($planning_cursor);
        $this->assertSame(
            [
                [
                    'action' => 'delete',
                    'path' => $included_root . '/file.txt',
                ],
            ],
            $this->operation_names($this->run_to_completion($resumed))
        );
        $complete_cursor = $this->serialize_cursor($resumed->get_cursor());
        $resumed = FileSyncPatchProcessor::resume($complete_cursor);
        $this->assertFalse($resumed->next_step());
        $resumed->close();
    }

    public function testCursorKeepsArbitraryByteWorkAndIndexPathsThroughEveryPhase(): void
    {
        $filesystem_root =
            $this->temporary_directory . "/filesystem-root-\xff";
        if (!@mkdir($filesystem_root)) {
            $this->markTestSkipped(
                'This filesystem does not accept non-UTF-8 path components.'
            );
        }
        $work_directory = $this->work_directory("work-\xfe");
        $storage_path = $this->temporary_directory . "/storage-\xfd";
        mkdir($storage_path);
        $current_path = "current-\xfc.txt";
        $saved_path = "saved-\xfb.txt";
        file_put_contents($filesystem_root . '/' . $current_path, 'current');
        $saved_index = $this->write_index("saved-\xfa.jsonl", [
            $this->entry($saved_path, 5),
        ]);
        $fresh_local_index_file =
            $work_directory . '/fresh_local_index.jsonl';

        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $filesystem_root,
            $saved_index,
            $storage_path
        );
        $seen_phases = [];
        $operations = [];
        for ($step = 0; $step < 100; ++$step) {
            $processor->flush_pending_outputs();
            $cursor = $this->serialize_cursor($processor->get_cursor());
            $phase = $cursor['position']['phase'];
            $seen_phases[$phase] = true;
            $this->assertSame(
                $fresh_local_index_file,
                base64_decode($cursor['fresh_local_index_file_b64'], true)
            );
            $processor->close();
            $processor = FileSyncPatchProcessor::resume($cursor);
            if ($phase === 'complete') {
                $this->assertFalse($processor->next_step());
                $processor->close();
                break;
            }
            $processor->next_step();
            $operation = $processor->get_operation();
            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        $this->assertSame(
            ['indexing', 'sorting', 'starting_patch', 'planning', 'complete'],
            array_keys($seen_phases)
        );
        $this->assertSame(
            [
                ['action' => 'copy', 'path' => $current_path],
                ['action' => 'delete', 'path' => $saved_path],
            ],
            $this->operation_names($operations)
        );
    }

    public function testFactoryPassesIncludeCachesToFreshIndexing(): void
    {
        mkdir($this->filesystem_root . '/node_modules');
        file_put_contents(
            $this->filesystem_root . '/node_modules/package.js',
            'package'
        );
        $work_directory = $this->work_directory('include-caches');
        $processor = FileSyncPatchProcessor::start_to_fresh_local_tree(
            $work_directory,
            $this->filesystem_root,
            $this->temporary_directory . '/missing-index.jsonl',
            $work_directory,
            [''],
            [],
            true
        );
        $this->assertTrue(
            $processor->get_cursor()['position'][
                'fresh_local_index_cursor'
            ]['include_caches']
        );

        $operations = $this->operation_names(
            $this->run_to_completion($processor)
        );
        $this->assertSame(
            [
                [
                    'action' => 'copy',
                    'path' => 'node_modules/package.js',
                ],
            ],
            $operations
        );
    }

    /** @return list<array<string,mixed>> */
    private function run_to_completion(
        FileSyncPatchProcessor $processor
    ): array {
        $operations = [];
        for ($step = 0; $step < 100; ++$step) {
            $has_next_step = $processor->next_step();
            $operation = $processor->get_operation();
            if ($operation !== null) {
                $operations[] = $operation;
            }
            $processor->flush_pending_outputs();
            if (!$has_next_step) {
                $processor->close();
                return $operations;
            }
        }
        $this->fail('File sync patch processing did not complete in 100 steps.');
    }

    /**
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    private function serialize_cursor(array $cursor): array
    {
        $stored_cursor = json_decode(
            json_encode($cursor, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($stored_cursor);
        return $stored_cursor;
    }

    /**
     * @param list<array<string,mixed>> $operations
     * @return list<array{action:string,path:string}>
     */
    private function operation_names(array $operations): array
    {
        return array_map(
            static fn (array $operation): array => [
                'action' => $operation['action'],
                'path' => $operation['path'],
            ],
            $operations
        );
    }

    /** @param list<array<string,mixed>> $entries */
    private function write_index(string $filename, array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $entry['path'] = base64_encode($entry['path']);
            $lines[] = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $path = $this->temporary_directory . '/' . $filename;
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    /** @return array{path:string,ctime:int,size:int,type:string} */
    private function entry(string $path, int $size): array
    {
        return [
            'path' => $path,
            'ctime' => 1,
            'size' => $size,
            'type' => 'file',
        ];
    }

    private function work_directory(string $name): string
    {
        $path = $this->temporary_directory . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    /** @return list<string> */
    private function read_index_paths(string $index_file): array
    {
        $lines = file(
            $index_file,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $this->assertIsArray($lines);
        return array_map(
            static function (string $line): string {
                $entry = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $path = base64_decode($entry['path'], true);
                if (!is_string($path)) {
                    throw new RuntimeException('Failed to decode an index path.');
                }
                return $path;
            },
            $lines
        );
    }

    private function remove_path(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = scandir($path);
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $this->remove_path($path . '/' . $child);
                }
            }
        }
        rmdir($path);
    }
}
