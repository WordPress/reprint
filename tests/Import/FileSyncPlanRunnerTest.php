<?php

declare(strict_types=1);

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test classes.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-plan-runner.php';

/** Covers durable file-sync plan outputs, required copies, and resume. */
final class FileSyncPlanRunnerTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir()
            . '/file-sync-plan-runner-'
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

    public function testWritesPushCompatibleCopyAndDeletionLists(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'b-deleted.txt' => $this->entry('b-deleted.txt', 'file', 1, 2),
            'c-file-to-directory' => $this->entry('c-file-to-directory', 'file', 1, 3),
            'd-directory-to-link' => $this->entry('d-directory-to-link', 'dir', 1, 0),
            'e-link-to-file' => $this->entry('e-link-to-file', 'link', 1, 4),
            'f-modified.txt' => $this->entry('f-modified.txt', 'file', 1, 5),
            'g-unchanged.txt' => $this->entry('g-unchanged.txt', 'file', 1, 6),
        ]);
        $patch_head_index = $this->write_index('head.jsonl', [
            'a-added.txt' => $this->entry('a-added.txt', 'file', 2, 7),
            'c-file-to-directory' => $this->entry('c-file-to-directory', 'dir', 2, 0),
            'd-directory-to-link' => $this->entry('d-directory-to-link', 'link', 2, 8),
            'e-link-to-file' => $this->entry('e-link-to-file', 'file', 2, 9),
            'f-modified.txt' => $this->entry('f-modified.txt', 'file', 2, 10),
            'g-unchanged.txt' => $this->entry('g-unchanged.txt', 'file', 1, 6),
        ]);
        $patch_planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_head_index,
            $this->path('deletion-roots.jsonl')
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => '',
        ]);

        $this->run_to_completion($runner);

        $this->assertSame(
            $this->copy_line('a-added.txt', 'file', 7, 2)
                . $this->copy_line('c-file-to-directory', 'directory', 0, 2)
                . $this->copy_line('d-directory-to-link', 'symlink', 8, 2)
                . $this->copy_line('e-link-to-file', 'file', 9, 2)
                . $this->copy_line('f-modified.txt', 'file', 10, 2),
            file_get_contents($this->path('copy.jsonl'))
        );
        $this->assertSame(
            "b-deleted.txt\0c-file-to-directory\0d-directory-to-link\0",
            file_get_contents($this->path('delete'))
        );
        $this->assertSame(5, $runner->get_cursor()['position']['paths_to_copy_count']);
        $this->assertSame(26, $runner->get_cursor()['position']['file_bytes_to_copy']);
        $runner->close();
    }

    public function testUsesCopySourcePathWithoutChangingTheDeletionCoordinate(): void
    {
        $local_path = "target/item-\x80";
        $copy_source_path = "/remote/item-\x81";
        $patch_base_index = $this->write_index('base.jsonl', [
            $local_path => $this->entry($local_path, 'dir', 1, 0),
        ]);
        $patch_head_index = $this->path('mapped-head.jsonl');
        file_put_contents(
            $patch_head_index,
            json_encode(
                [
                    'mapped_path' => base64_encode($local_path),
                    'copy_source_path' => base64_encode($copy_source_path),
                    'type' => 'file',
                    'size' => 4,
                    'ctime' => 2,
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
        );
        $decode_patch_head_index_line = static function (string $line): array {
            /** @var array{mapped_path:string,copy_source_path:string,type:'file'|'link'|'dir',size:int,ctime:int} $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            return [
                'path' => base64_decode($entry['mapped_path'], true),
                'copy_source_path' => base64_decode(
                    $entry['copy_source_path'],
                    true
                ),
                'type' => $entry['type'],
                'size' => $entry['size'],
                'ctime' => $entry['ctime'],
            ];
        };
        $patch_planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_head_index,
            $this->path('deletion-roots.jsonl'),
            [''],
            [],
            null,
            $decode_patch_head_index_line
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => 'target',
        ]);

        $this->run_to_completion($runner);

        $copy_entries = $this->read_copy_entries($this->path('copy.jsonl'));
        $this->assertCount(1, $copy_entries);
        $this->assertSame(
            $copy_source_path,
            base64_decode($copy_entries[0]['path'], true)
        );
        $this->assertSame("item-\x80\0", file_get_contents($this->path('delete')));
        $runner->close();
    }

    public function testRequiredPathsAddOnlyCopiesPresentInThePatchHead(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'a-base-only.txt' => $this->entry('a-base-only.txt', 'file', 1, 1),
            'b-shared-required.txt' => $this->entry('b-shared-required.txt', 'file', 1, 2),
            'c-shared-drift.txt' => $this->entry('c-shared-drift.txt', 'file', 1, 3),
            'e-replaced' => $this->entry('e-replaced', 'dir', 1, 0),
            'f-excluded.txt' => $this->entry('f-excluded.txt', 'file', 1, 1),
        ]);
        $patch_head_index = $this->write_index('head.jsonl', [
            'b-shared-required.txt' => $this->entry('b-shared-required.txt', 'file', 1, 2),
            'c-shared-drift.txt' => $this->entry('c-shared-drift.txt', 'file', 9, 3),
            'd-added.txt' => $this->entry('d-added.txt', 'file', 2, 4),
            'e-replaced' => $this->entry('e-replaced', 'file', 2, 5),
            'f-excluded.txt' => $this->entry('f-excluded.txt', 'file', 1, 1),
        ]);
        $paths_requiring_copy = $this->write_paths_requiring_copy(
            'required.jsonl',
            ['a-base-only.txt', 'b-shared-required.txt', 'f-excluded.txt']
        );
        $decode_without_ctime = static function (string $line): array {
            $entry = \Reprint\Importer\decode_local_index_entry($line);
            $entry['ctime'] = 0;
            return $entry;
        };
        $patch_planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_head_index,
            $this->path('deletion-roots.jsonl'),
            [''],
            ['f-excluded.txt'],
            $decode_without_ctime,
            $decode_without_ctime
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => '',
            'paths_requiring_copy_file' => $paths_requiring_copy,
        ]);

        $this->run_to_completion($runner);

        $this->assertSame(
            ['b-shared-required.txt', 'd-added.txt', 'e-replaced'],
            $this->copy_paths($this->path('copy.jsonl'))
        );
        $this->assertSame(
            "a-base-only.txt\0e-replaced\0",
            file_get_contents($this->path('delete'))
        );
        $runner->close();
    }

    public function testResumeTruncatesUnstoredOutputAndRestoresRequiredLookahead(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'a.txt' => $this->entry('a.txt', 'file', 1, 1),
            'b' => $this->entry('b', 'dir', 1, 0),
            'c.txt' => $this->entry('c.txt', 'file', 1, 3),
        ]);
        $patch_head_index = $this->write_index('head.jsonl', [
            'a.txt' => $this->entry('a.txt', 'file', 1, 1),
            'b' => $this->entry('b', 'file', 2, 2),
            'c.txt' => $this->entry('c.txt', 'file', 1, 3),
        ]);
        $decode_patch_base_index_line = static function (string $line): array {
            return \Reprint\Importer\decode_local_index_entry($line);
        };
        $decode_patch_head_index_line = static function (string $line): array {
            $entry = \Reprint\Importer\decode_local_index_entry($line);
            $entry['copy_source_path'] = '/remote/' . $entry['path'];
            return $entry;
        };
        $patch_planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $patch_head_index,
            $this->path('deletion-roots.jsonl'),
            [''],
            [],
            $decode_patch_base_index_line,
            $decode_patch_head_index_line
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => '',
            'paths_requiring_copy_file' =>
                $this->write_paths_requiring_copy(
                    'required.jsonl',
                    ['a.txt', 'c.txt']
                ),
        ]);

        $this->assertSame(0, $runner->get_index_bytes_done());
        $this->assertTrue($runner->next_step());
        $runner->flush_pending_outputs();
        $stored_cursor = $runner->get_cursor();
        $stored_index_bytes_done = $runner->get_index_bytes_done();
        $this->assertGreaterThan(0, $stored_index_bytes_done);
        $this->assertSame('planning', $stored_cursor['position']['phase']);
        $this->assertArrayHasKey(
            'file_sync_patch_planner_cursor',
            $stored_cursor['position']
        );
        $this->assertGreaterThan(
            0,
            $stored_cursor['position']['byte_offset_in_paths_to_copy']
        );
        $this->assertSame(
            0,
            $stored_cursor['position']['byte_offset_in_paths_to_delete']
        );
        $this->assertGreaterThan(
            0,
            $stored_cursor['position']['byte_offset_in_paths_requiring_copy']
        );
        $this->assertSame(1, $stored_cursor['position']['paths_to_copy_count']);
        $this->assertSame(1, $stored_cursor['position']['file_bytes_to_copy']);

        $this->assertTrue($runner->next_step());
        $runner->close();

        $runner = FileSyncPlanRunner::resume(
            $stored_cursor,
            $decode_patch_base_index_line,
            $decode_patch_head_index_line
        );
        $this->assertSame(
            $stored_index_bytes_done,
            $runner->get_index_bytes_done()
        );
        $this->run_to_completion($runner);

        $this->assertSame(
            ['/remote/a.txt', '/remote/b', '/remote/c.txt'],
            $this->copy_paths($this->path('copy.jsonl'))
        );
        $this->assertSame("b\0", file_get_contents($this->path('delete')));
        $this->assertSame(3, $runner->get_paths_to_copy_count());
        $this->assertSame(6, $runner->get_file_bytes_to_copy());
        $runner->close();
    }

    public function testRejectsARequiredCopyPathOutsideTheIndexUnion(): void
    {
        $patch_head_index = $this->write_index('head.jsonl', [
            'b.txt' => $this->entry('b.txt', 'file', 1, 1),
        ]);
        $patch_planner = FileSyncPatchPlanner::create(
            $this->path('missing-base.jsonl'),
            $patch_head_index,
            $this->path('deletion-roots.jsonl')
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => '',
            'paths_requiring_copy_file' =>
                $this->write_paths_requiring_copy(
                    'required.jsonl',
                    ['a-not-in-index.txt']
                ),
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'does not match any remaining patch-index path'
            );
            $runner->next_step();
        } finally {
            $runner->close();
        }
    }

    public function testStartRejectsAPlannerAfterItsFirstStepAttempt(): void
    {
        $patch_planner = FileSyncPatchPlanner::create(
            $this->write_index('base.jsonl', []),
            $this->write_index('head.jsonl', []),
            $this->path('deletion-roots.jsonl')
        );
        // An empty first step leaves both index byte offsets at zero. The
        // planner must still remember that next_path() was attempted.
        $this->assertFalse($patch_planner->next_path());

        $paths_to_copy_file = $this->path('copy.jsonl');
        $paths_to_delete_file = $this->path('delete');
        file_put_contents($paths_to_copy_file, "copy output before start\n");
        file_put_contents($paths_to_delete_file, "delete output before start\n");

        try {
            FileSyncPlanRunner::start($patch_planner, [
                'paths_to_copy_file' => $paths_to_copy_file,
                'paths_to_delete_file' => $paths_to_delete_file,
                'deletion_path_prefix' => '',
            ]);
            $this->fail('An advanced patch planner must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'FileSyncPlanRunner::start() requires a fresh patch planner '
                    . 'positioned before its first path; '
                    . 'is_positioned_before_first_path() returned false.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            "copy output before start\n",
            file_get_contents($paths_to_copy_file)
        );
        $this->assertSame(
            "delete output before start\n",
            file_get_contents($paths_to_delete_file)
        );
        $this->assert_planner_is_closed($patch_planner);
    }

    public function testStartRejectsAResumedPartiallyAdvancedPlanner(): void
    {
        $patch_planner = FileSyncPatchPlanner::create(
            $this->path('missing-base.jsonl'),
            $this->write_index('head.jsonl', [
                'a.txt' => $this->entry('a.txt', 'file', 1, 1),
                'b.txt' => $this->entry('b.txt', 'file', 1, 1),
            ]),
            $this->path('deletion-roots.jsonl')
        );
        $this->assertTrue($patch_planner->next_path());
        $cursor = $patch_planner->get_cursor();
        $patch_planner->close();
        $patch_planner = FileSyncPatchPlanner::resume($cursor);

        $paths_to_copy_file = $this->path('copy.jsonl');
        $paths_to_delete_file = $this->path('delete');
        file_put_contents($paths_to_copy_file, "copy output before start\n");
        file_put_contents($paths_to_delete_file, "delete output before start\n");

        try {
            FileSyncPlanRunner::start($patch_planner, [
                'paths_to_copy_file' => $paths_to_copy_file,
                'paths_to_delete_file' => $paths_to_delete_file,
                'deletion_path_prefix' => '',
            ]);
            $this->fail('A partially advanced patch planner must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'FileSyncPlanRunner::start() requires a fresh patch planner '
                    . 'positioned before its first path; '
                    . 'is_positioned_before_first_path() returned false.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            "copy output before start\n",
            file_get_contents($paths_to_copy_file)
        );
        $this->assertSame(
            "delete output before start\n",
            file_get_contents($paths_to_delete_file)
        );
        $this->assert_planner_is_closed($patch_planner);
    }

    public function testInvalidRunnerOptionsCloseTheSuppliedPlanner(): void
    {
        $patch_planner = FileSyncPatchPlanner::create(
            $this->write_index('base.jsonl', []),
            $this->write_index('head.jsonl', []),
            $this->path('deletion-roots.jsonl')
        );

        try {
            FileSyncPlanRunner::start($patch_planner, [
                'paths_to_delete_file' => $this->path('delete'),
                'deletion_path_prefix' => '',
            ]);
            $this->fail('Missing runner options must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'FileSyncPlanRunner::start() requires the '
                    . 'paths_to_copy_file option.',
                $exception->getMessage()
            );
        }

        $this->assert_planner_is_closed($patch_planner);
    }

    public function testStartRejectsAnUnknownOptionName(): void
    {
        $patch_planner = FileSyncPatchPlanner::create(
            $this->write_index('base.jsonl', []),
            $this->write_index('head.jsonl', []),
            $this->path('deletion-roots.jsonl')
        );

        try {
            FileSyncPlanRunner::start($patch_planner, [
                'paths_to_copy_file' => $this->path('copy.jsonl'),
                'paths_to_copy_files' => $this->path('other-copy.jsonl'),
                'paths_to_delete_file' => $this->path('delete'),
                'deletion_path_prefix' => '',
            ]);
            $this->fail('Unknown runner options must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'FileSyncPlanRunner::start() does not accept the '
                    . 'paths_to_copy_files option.',
                $exception->getMessage()
            );
        }

        $this->assert_planner_is_closed($patch_planner);
    }

    public function testCloseClosesThePatchPlanner(): void
    {
        $patch_planner = FileSyncPatchPlanner::create(
            $this->write_index('base.jsonl', []),
            $this->write_index('head.jsonl', []),
            $this->path('deletion-roots.jsonl')
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => '',
        ]);

        $runner->close();
        $runner->close();

        $this->assert_planner_is_closed($patch_planner);
    }

    public function testRejectsAPlannedDeletionOutsideTheDeletionPrefix(): void
    {
        $patch_base_index = $this->write_index('base.jsonl', [
            'site/a.txt' => $this->entry('site/a.txt', 'file', 1, 1),
        ]);
        $patch_planner = FileSyncPatchPlanner::create(
            $patch_base_index,
            $this->write_index('head.jsonl', []),
            $this->path('deletion-roots.jsonl'),
            ['site']
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => 'other',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The planned deletion path '
            . base64_encode('site')
            . ' is outside the deletion path prefix '
            . base64_encode('other')
            . '.'
        );
        try {
            $runner->next_step();
        } finally {
            $runner->close();
        }
    }

    public function testEachStepWritesOnePathAndTerminalResumeRemainsFalse(): void
    {
        $patch_head_index = $this->write_index('head.jsonl', [
            'a.txt' => $this->entry('a.txt', 'file', 1, 1),
            'b.txt' => $this->entry('b.txt', 'file', 1, 2),
        ]);
        $patch_planner = FileSyncPatchPlanner::create(
            $this->path('missing-base.jsonl'),
            $patch_head_index,
            $this->path('deletion-roots.jsonl')
        );
        $runner = FileSyncPlanRunner::start($patch_planner, [
            'paths_to_copy_file' => $this->path('copy.jsonl'),
            'paths_to_delete_file' => $this->path('delete'),
            'deletion_path_prefix' => '',
        ]);

        $this->assertFalse($runner->is_complete());
        $this->assertTrue($runner->next_step());
        $this->assertSame(['a.txt'], $this->copy_paths($this->path('copy.jsonl')));
        $this->assertFalse($runner->next_step());
        $this->assertSame(
            ['a.txt', 'b.txt'],
            $this->copy_paths($this->path('copy.jsonl'))
        );
        $this->assertTrue($runner->is_complete());
        $this->assertSame(2, $runner->get_paths_to_copy_count());
        $this->assertSame(3, $runner->get_file_bytes_to_copy());
        $complete_cursor = $runner->get_cursor();
        $this->assertSame(
            [
                'phase' => 'complete',
                'paths_to_copy_count' => 2,
                'file_bytes_to_copy' => 3,
            ],
            $complete_cursor['position']
        );
        $this->assertFalse($runner->next_step());
        $runner->close();
        $runner->close();
        $this->assertFalse($runner->next_step());

        $runner = FileSyncPlanRunner::resume($complete_cursor);
        $this->assertTrue($runner->is_complete());
        $this->assertFalse($runner->next_step());
        $runner->close();
        $runner->close();
    }

    private function assert_planner_is_closed(
        FileSyncPatchPlanner $patch_planner
    ): void {
        try {
            $patch_planner->next_path();
            $this->fail('The patch planner must be closed.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot use a closed file sync patch planner.',
                $exception->getMessage()
            );
        }
    }

    private function run_to_completion(FileSyncPlanRunner $runner): void
    {
        while ($runner->next_step()) {
            $runner->flush_pending_outputs();
        }
    }

    /** @return list<array{path:string,type:string,size:int,ctime:int}> */
    private function read_copy_entries(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];
        foreach ($lines === false ? [] : $lines as $line) {
            /** @var array{path:string,type:string,size:int,ctime:int} $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entries[] = $entry;
        }
        return $entries;
    }

    /** @return list<string> */
    private function copy_paths(string $path): array
    {
        return array_map(
            static function (array $entry): string {
                $decoded_path = base64_decode($entry['path'], true);
                self::assertIsString($decoded_path);
                return $decoded_path;
            },
            $this->read_copy_entries($path)
        );
    }

    /** @param array<string,array{path:string,type:string,size:int,ctime:int,empty?:bool}> $entries */
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
        $path = $this->path($filename);
        file_put_contents(
            $path,
            $lines === [] ? '' : implode("\n", $lines) . "\n"
        );
        return $path;
    }

    /** @param list<string> $paths */
    private function write_paths_requiring_copy(
        string $filename,
        array $paths
    ): string {
        $lines = array_map(
            static function (string $path): string {
                return json_encode(
                    ['path' => base64_encode($path)],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            },
            $paths
        );
        $path = $this->path($filename);
        file_put_contents(
            $path,
            $lines === [] ? '' : implode("\n", $lines) . "\n"
        );
        return $path;
    }

    /** @return array{path:string,type:string,size:int,ctime:int,empty?:bool} */
    private function entry(
        string $path,
        string $type,
        int $ctime,
        int $size
    ): array {
        $entry = [
            'path' => $path,
            'type' => $type,
            'size' => $size,
            'ctime' => $ctime,
        ];
        if ($type === 'dir') {
            $entry['empty'] = true;
        }
        return $entry;
    }

    private function copy_line(
        string $path,
        string $type,
        int $size,
        int $ctime
    ): string {
        return json_encode(
            [
                'path' => base64_encode($path),
                'type' => $type,
                'size' => $size,
                'ctime' => $ctime,
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    private function path(string $filename): string
    {
        return $this->temp_dir . '/' . $filename;
    }
}
