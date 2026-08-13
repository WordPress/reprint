<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/utils.php';
require_once __DIR__ . '/../../packages/reprint-server/src/class-file-index-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-cleanup-processor.php';

final class FileSyncCleanupProcessorTest extends TestCase {
    private string $temporary_directory;
    private string $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/file-sync-cleanup-processor-'
            . bin2hex(random_bytes(6));
        $this->filesystem_root =
            $this->temporary_directory . '/filesystem-root';
        mkdir($this->filesystem_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temporary_directory);
        parent::tearDown();
    }

    public function testRemovesIndexedPathsAndPrunesEmptyParents(): void
    {
        mkdir($this->filesystem_root . '/selected/gone/nested', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/gone/child.txt',
            'child'
        );
        file_put_contents(
            $this->filesystem_root . '/selected/gone/nested/leaf.txt',
            'leaf'
        );
        $work_directory = $this->work_directory('remove-tree');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('empty.jsonl', []),
            $work_directory,
            ['selected']
        );

        $phases = $this->run_to_completion($processor);

        $this->assertDirectoryExists($this->filesystem_root . '/selected');
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/gone'
        );
        $this->assertContains('removing', $phases);
        $this->assertContains('pruning', $phases);
        $this->assertSame('complete', $processor->get_status());
    }

    public function testKeepsUnindexedCacheAndStorageChildren(): void
    {
        mkdir($this->filesystem_root . '/selected/tree/node_modules', 0755, true);
        mkdir($this->filesystem_root . '/selected/tree/.git');
        file_put_contents(
            $this->filesystem_root . '/selected/tree/delete.txt',
            'delete'
        );
        file_put_contents(
            $this->filesystem_root
                . '/selected/tree/node_modules/package.js',
            'package'
        );
        file_put_contents(
            $this->filesystem_root . '/selected/tree/.git/config',
            'config'
        );
        $storage_path =
            $this->filesystem_root . '/selected/tree/.reprint';
        mkdir($storage_path);
        file_put_contents($storage_path . '/keep.txt', 'state');
        $work_directory = $this->work_directory('keep-skipped');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('keep-skipped-result.jsonl', []),
            $storage_path,
            ['selected']
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/tree/delete.txt'
        );
        $this->assertFileExists(
            $this->filesystem_root
                . '/selected/tree/node_modules/package.js'
        );
        $this->assertFileExists(
            $this->filesystem_root . '/selected/tree/.git/config'
        );
        $this->assertFileExists($storage_path . '/keep.txt');
    }

    public function testKeepsAnExplicitlyExcludedChild(): void
    {
        mkdir($this->filesystem_root . '/selected/tree/keep', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/tree/delete.txt',
            'delete'
        );
        file_put_contents(
            $this->filesystem_root . '/selected/tree/keep/child.txt',
            'keep'
        );
        $work_directory = $this->work_directory('keep-excluded');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('keep-excluded-result.jsonl', []),
            $work_directory,
            ['selected'],
            ['selected/tree/keep']
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/tree/delete.txt'
        );
        $this->assertFileExists(
            $this->filesystem_root . '/selected/tree/keep/child.txt'
        );
    }

    public function testKeepsAnEmptyIncludedRoot(): void
    {
        mkdir($this->filesystem_root . '/selected');
        $work_directory = $this->work_directory('keep-included-root');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('keep-included-root-result.jsonl', []),
            $work_directory,
            ['selected']
        );

        $this->run_to_completion($processor);

        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected'
        );
    }

    /** @dataProvider exactPathTypes */
    public function testRemovesAnExactPathBeforeItsTypeChanges(
        string $local_type,
        string $result_type
    ): void {
        $path = $this->filesystem_root . '/selected/entry';
        mkdir(dirname($path), 0755, true);
        if ($local_type === 'dir') {
            mkdir($path);
        } elseif ($local_type === 'link') {
            symlink('missing-target', $path);
        } else {
            file_put_contents($path, 'local');
        }
        $work_directory = $this->work_directory(
            'replace-' . $local_type . '-' . $result_type
        );
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('replace-result.jsonl', [
                $this->entry('selected/entry', $result_type),
            ]),
            $work_directory,
            ['selected']
        );

        $this->run_to_completion($processor);

        $this->assertFalse(file_exists($path));
        $this->assertFalse(is_link($path));
        $this->assertDirectoryExists($this->filesystem_root . '/selected');
    }

    /** @return list<array{string,string}> */
    public static function exactPathTypes(): array
    {
        return [
            ['dir', 'file'],
            ['file', 'dir'],
            ['link', 'file'],
            ['file', 'link'],
        ];
    }

    public function testAResultParentDoesNotRemoveAnUnindexedChild(): void
    {
        mkdir(
            $this->filesystem_root . '/selected/tree/node_modules',
            0755,
            true
        );
        file_put_contents(
            $this->filesystem_root
                . '/selected/tree/node_modules/package.js',
            'package'
        );
        file_put_contents(
            $this->filesystem_root . '/selected/tree/indexed.txt',
            'indexed'
        );
        $work_directory = $this->work_directory('result-parent');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('result-parent.jsonl', [
                $this->entry('selected/tree', 'file'),
            ]),
            $work_directory,
            ['selected']
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/tree/indexed.txt'
        );
        $this->assertFileExists(
            $this->filesystem_root
                . '/selected/tree/node_modules/package.js'
        );
        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/tree'
        );
    }

    public function testResumeRepeatsAPendingRemovalSafely(): void
    {
        file_put_contents($this->filesystem_root . '/delete.txt', 'delete');
        $work_directory = $this->work_directory('pending-removal');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('pending-result.jsonl', []),
            $work_directory
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $processor->flush_pending_output();
        $pending_cursor = $this->stored_cursor($processor->get_cursor());
        $this->assertArrayNotHasKey('action', $pending_cursor['position']);
        $this->assertSame(
            'file',
            $pending_cursor['position']['expected_base']['type']
        );

        $processor->next_step();
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/delete.txt'
        );
        $processor->close();

        $resumed = FileSyncCleanupProcessor::resume($pending_cursor);
        $this->run_to_completion($resumed);
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/delete.txt'
        );
    }

    public function testCopyLeavesThePathForTheCallerToWrite(): void
    {
        $work_directory = $this->work_directory('copy-restart');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('copy-restart-result.jsonl', [
                $this->entry('remote.txt', 'file'),
            ]),
            $work_directory
        );

        $this->run_to_completion($processor);

        $this->assertSame('complete', $processor->get_status());
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/remote.txt'
        );
    }

    public function testChangedPendingRemovalRestartsWithoutDeletingTheNewPath(): void
    {
        $path = $this->filesystem_root . '/delete.txt';
        file_put_contents($path, 'old');
        $work_directory = $this->work_directory('changed-removal');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('changed-removal-result.jsonl', []),
            $work_directory
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $processor->flush_pending_output();
        $removing_cursor = $this->stored_cursor($processor->get_cursor());
        $processor->close();

        $processor = FileSyncCleanupProcessor::resume($removing_cursor);

        file_put_contents($path, 'new version');
        clearstatcache(true, $path);

        $this->assertFalse($processor->next_step());
        $this->assertSame('restart', $processor->get_status());
        $this->assertSame('new version', file_get_contents($path));
        $restart_cursor = $this->stored_cursor($processor->get_cursor());
        $processor->close();

        $resumed = FileSyncCleanupProcessor::resume($restart_cursor);
        $this->assertFalse($resumed->next_step());
        $this->assertSame('restart', $resumed->get_status());
        $this->assertSame('new version', file_get_contents($path));
        $resumed->close();
    }

    public function testChangedPendingReplacementRestartsWithoutDeletingTheNewPath(): void
    {
        $path = $this->filesystem_root . '/entry';
        file_put_contents($path, 'old');
        $work_directory = $this->work_directory('changed-replacement');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('changed-replacement-result.jsonl', [
                $this->entry('entry', 'dir'),
            ]),
            $work_directory
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');

        file_put_contents($path, 'new version');
        clearstatcache(true, $path);

        $this->assertFalse($processor->next_step());
        $this->assertSame('restart', $processor->get_status());
        $this->assertSame('new version', file_get_contents($path));
    }

    public function testPendingEmptyDirectoryWhichGainsAChildRequiresRestart(): void
    {
        $path = $this->filesystem_root . '/entry';
        mkdir($path);
        $work_directory = $this->work_directory('changed-empty-directory');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('changed-empty-directory-result.jsonl', [
                $this->entry('entry', 'file'),
            ]),
            $work_directory
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');

        file_put_contents($path . '/new.txt', 'new');
        clearstatcache(true, $path);

        $this->assertFalse($processor->next_step());
        $this->assertSame('restart', $processor->get_status());
        $this->assertSame('new', file_get_contents($path . '/new.txt'));
    }

    public function testPendingRemovalRejectsAParentMovedOutsideTheRoot(): void
    {
        mkdir($this->filesystem_root . '/selected/directory', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/directory/delete.txt',
            'local'
        );
        $outside_directory = $this->temporary_directory . '/outside';
        mkdir($outside_directory);
        file_put_contents($outside_directory . '/delete.txt', 'outside');
        $work_directory = $this->work_directory('moved-parent');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('moved-parent-result.jsonl', []),
            $work_directory,
            ['selected']
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $this->assertSame(
            base64_encode('selected/directory/delete.txt'),
            $processor->get_cursor()['position']['path_b64']
        );

        rename(
            $this->filesystem_root . '/selected/directory',
            $this->filesystem_root . '/saved-directory'
        );
        symlink(
            $outside_directory,
            $this->filesystem_root . '/selected/directory'
        );

        try {
            $processor->next_step();
            $this->fail('Expected the moved parent path to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'resolves outside the filesystem root',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            'outside',
            file_get_contents($outside_directory . '/delete.txt')
        );
        $processor->close();
    }

    public function testResumeKeepsTheEmptyParentStack(): void
    {
        mkdir($this->filesystem_root . '/selected/a/b', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/b/delete.txt',
            'delete'
        );
        $work_directory = $this->work_directory('parent-stack');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('parent-stack-result.jsonl', []),
            $work_directory,
            ['selected']
        );
        do {
            $processor->next_step();
            $processor->flush_pending_output();
        } while ($processor->get_phase() !== 'pruning');
        $cursor = $this->stored_cursor($processor->get_cursor());
        $this->assertNotNull(
            $cursor['empty_parent_path_stack_top_byte_offset']
        );
        $processor->close();

        $resumed = FileSyncCleanupProcessor::resume($cursor);
        $this->run_to_completion($resumed);
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a'
        );
        $this->assertDirectoryExists($this->filesystem_root . '/selected');
    }

    public function testResumeDiscardsAnUnstoredParentStackEntry(): void
    {
        mkdir($this->filesystem_root . '/selected/a', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/first.txt',
            'first'
        );
        file_put_contents(
            $this->filesystem_root . '/selected/a/second.txt',
            'second'
        );
        $work_directory = $this->work_directory('discard-stack-tail');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('discard-stack-tail-result.jsonl', []),
            $work_directory,
            ['selected']
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $processor->flush_pending_output();
        $processor->next_step();
        $processor->flush_pending_output();
        $stored_after_first_removal = $this->stored_cursor(
            $processor->get_cursor()
        );

        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $processor->next_step();
        $this->assertGreaterThan(
            $stored_after_first_removal[
                'empty_parent_paths_file_byte_offset'
            ],
            $processor->get_cursor()[
                'empty_parent_paths_file_byte_offset'
            ]
        );
        $processor->close();

        $resumed = FileSyncCleanupProcessor::resume(
            $stored_after_first_removal
        );
        $this->run_to_completion($resumed);
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a'
        );
    }

    public function testCorruptParentStackDoesNotLeaveItsFileOpen(): void
    {
        mkdir($this->filesystem_root . '/selected/a', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/delete.txt',
            'delete'
        );
        $work_directory = $this->work_directory('corrupt-parent-stack');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('corrupt-parent-stack-result.jsonl', []),
            $work_directory,
            ['selected']
        );
        do {
            $processor->next_step();
            $processor->flush_pending_output();
        } while ($processor->get_phase() !== 'pruning');
        $cursor = $this->stored_cursor($processor->get_cursor());
        $processor->close();

        $parent_stack_file = base64_decode(
            $cursor['empty_parent_paths_file_b64'],
            true
        );
        $this->assertIsString($parent_stack_file);
        file_put_contents($parent_stack_file, "not-json\n");
        $this->assertSame(
            0,
            $this->count_open_streams_for_path($parent_stack_file)
        );

        try {
            FileSyncCleanupProcessor::resume($cursor);
            $this->fail('Expected the corrupt parent stack to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Failed to decode the file sync path stack',
                $exception->getMessage()
            );
        }
        $this->assertSame(
            0,
            $this->count_open_streams_for_path($parent_stack_file)
        );
    }

    public function testResumeAfterAnUnstoredPruneStillSchedulesItsParent(): void
    {
        mkdir($this->filesystem_root . '/selected/a/b', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/b/delete.txt',
            'delete'
        );
        $work_directory = $this->work_directory('repeat-prune');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('repeat-prune-result.jsonl', []),
            $work_directory,
            ['selected']
        );
        do {
            $processor->next_step();
            $processor->flush_pending_output();
        } while ($processor->get_phase() !== 'pruning');
        $pruning_cursor = $this->stored_cursor($processor->get_cursor());

        $processor->next_step();
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a/b'
        );
        $processor->close();

        $resumed = FileSyncCleanupProcessor::resume($pruning_cursor);
        $this->run_to_completion($resumed);
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a'
        );
        $this->assertDirectoryExists($this->filesystem_root . '/selected');
    }

    public function testChangedScheduledParentIsLeftForTheNextFreshScan(): void
    {
        mkdir($this->filesystem_root . '/selected/a/b', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/b/delete.txt',
            'delete'
        );
        $work_directory = $this->work_directory('changed-parent');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('changed-parent-result.jsonl', []),
            $work_directory,
            ['selected']
        );
        do {
            $processor->next_step();
            $processor->flush_pending_output();
        } while ($processor->get_phase() !== 'pruning');

        rmdir($this->filesystem_root . '/selected/a/b');
        file_put_contents(
            $this->filesystem_root . '/selected/a/b',
            'new path'
        );

        $this->run_to_completion($processor);

        $this->assertSame(
            'new path',
            file_get_contents($this->filesystem_root . '/selected/a/b')
        );
        $this->assertSame('complete', $processor->get_status());
    }

    public function testCursorStoresArbitraryBytePathsAsBase64(): void
    {
        $included_root = "selected-\xff";
        $path = $included_root . "/delete-\xfe.txt";
        if (!@mkdir($this->filesystem_root . '/' . $included_root)) {
            $this->markTestSkipped(
                'This filesystem does not accept non-UTF-8 path components.'
            );
        }
        file_put_contents($this->filesystem_root . '/' . $path, 'delete');
        $work_directory = $this->work_directory("arbitrary-\xfd");
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('arbitrary-result.jsonl', []),
            $work_directory,
            [$included_root]
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');

        $cursor = $this->stored_cursor($processor->get_cursor());
        $this->assertSame(
            base64_encode($path),
            $cursor['position']['path_b64']
        );
        $this->assertSame(
            [base64_encode($included_root)],
            $cursor['included_index_path_roots_b64']
        );
        $processor->flush_pending_output();
        $processor->close();

        $resumed = FileSyncCleanupProcessor::resume($cursor);
        $this->run_to_completion($resumed);
        $this->assertFileDoesNotExist($this->filesystem_root . '/' . $path);
    }

    public function testCompleteAndCloseAreStable(): void
    {
        $work_directory = $this->work_directory('complete');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('complete-result.jsonl', []),
            $work_directory
        );
        $this->run_to_completion($processor);
        $cursor = $this->stored_cursor($processor->get_cursor());

        $this->assertSame('complete', $processor->get_status());
        $this->assertFalse($processor->next_step());
        $processor->close();
        $processor->close();
        $this->assertFalse($processor->next_step());

        $resumed = FileSyncCleanupProcessor::resume($cursor);
        $this->assertFalse($resumed->next_step());
        $this->assertSame('complete', $resumed->get_status());
        $resumed->close();
    }

    /** @return list<string> */
    private function run_to_completion(
        FileSyncCleanupProcessor $processor
    ): array {
        $phases = [];
        for ($step = 0; $step < 300; ++$step) {
            $phases[] = $processor->get_phase();
            $has_next_step = $processor->next_step();
            $processor->flush_pending_output();
            if (!$has_next_step) {
                return $phases;
            }
        }
        $this->fail('File sync cleanup did not complete in 300 steps.');
    }

    /** @return array<string,mixed> */
    private function stored_cursor(array $cursor): array
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
     * @param list<array{path:string,ctime:int,size:int,type:string,empty?:bool}> $entries
     */
    private function write_index(string $filename, array $entries): string
    {
        usort(
            $entries,
            static fn (array $left, array $right): int =>
                strcmp($left['path'], $right['path'])
        );
        $lines = [];
        foreach ($entries as $entry) {
            $entry['path'] = base64_encode($entry['path']);
            $lines[] = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $path = $this->temporary_directory . '/' . $filename;
        file_put_contents(
            $path,
            $lines === [] ? '' : implode("\n", $lines) . "\n"
        );
        return $path;
    }

    /** @return array{path:string,ctime:int,size:int,type:string,empty?:bool} */
    private function entry(string $path, string $type): array
    {
        $entry = [
            'path' => $path,
            'ctime' => 1,
            'size' => $type === 'dir' ? 0 : 1,
            'type' => $type,
        ];
        if ($type === 'dir') {
            $entry['empty'] = true;
        }
        return $entry;
    }

    private function work_directory(string $name): string
    {
        $path = $this->temporary_directory . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    private function count_open_streams_for_path(string $path): int
    {
        $count = 0;
        foreach (get_resources('stream') as $stream) {
            $metadata = stream_get_meta_data($stream);
            $stream_path = $metadata['uri'] ?? null;
            if ($stream_path === $path) {
                ++$count;
            }
        }
        return $count;
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
        foreach (scandir($path) ?: [] as $child) {
            if ($child !== '.' && $child !== '..') {
                $this->remove_path($path . '/' . $child);
            }
        }
        rmdir($path);
    }
}
