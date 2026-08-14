<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/utils.php';
require_once __DIR__ . '/../../packages/reprint-server/src/class-file-index-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-cleanup-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-file-sync-change-scope-mapping-processor.php';

final class FileSyncCleanupProcessorTest extends TestCase {
    private string $temporary_directory;
    private string $filesystem_root;
    private string $last_ownership_directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/file-sync-cleanup-processor-'
            . bin2hex(random_bytes(6));
        $this->filesystem_root =
            $this->temporary_directory . '/filesystem-root';
        mkdir($this->filesystem_root, 0755, true);
        $canonical_filesystem_root = realpath($this->filesystem_root);
        $this->assertIsString($canonical_filesystem_root);
        $this->filesystem_root = $canonical_filesystem_root;
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
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('empty.jsonl', []),
            $work_directory,
            ['selected'],
            [],
            true
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
        $processor = $this->startCleanup(
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

    public function testIncludeCachesRemovesStaleDotGitEntryOwnedByCurrentRoot(): void
    {
        mkdir($this->filesystem_root . '/.git', 0755, true);
        $stale_path = $this->filesystem_root . '/.git/stale.txt';
        file_put_contents($stale_path, 'stale');
        $work_directory = $this->work_directory('include-caches-dot-git');
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('include-caches-dot-git-result.jsonl', []),
            $work_directory,
            [''],
            [],
            true
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist($stale_path);
    }

    public function testRemovesAnExactLinkInsideDotGit(): void
    {
        mkdir($this->filesystem_root . '/site/.git', 0755, true);
        $path = $this->filesystem_root . '/site/.git/stale-link';
        symlink('missing-target', $path);
        $work_directory = $this->work_directory('exact-dot-git-link');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('exact-dot-git-link-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig([
                ['kind' => 'exact', 'path' => '/site/.git/stale-link'],
            ], [], [], [], ['/site'])
        );

        $this->run_to_completion($processor);

        $this->assertFalse(is_link($path));
        $this->assertDirectoryExists(
            $this->filesystem_root . '/site/.git'
        );
    }

    public function testPriorExactTombstoneKeepsALocalFifo(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('This PHP build cannot create a FIFO.');
        }
        mkdir($this->filesystem_root . '/site/.git', 0755, true);
        $fifo_path = $this->filesystem_root . '/site/.git/stale.pipe';
        posix_mkfifo($fifo_path, 0600);
        $work_directory = $this->work_directory('prior-exact-local-fifo');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('prior-exact-local-fifo-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig(
                [],
                [[
                    'kind' => 'exact',
                    'path' => '/site/.git/stale.pipe',
                ]],
                [],
                [],
                ['/site']
            )
        );

        $this->run_to_completion($processor);

        $this->assertIsArray(lstat($fifo_path));
        unlink($fifo_path);
    }

    public function testKeepsOrdinaryDefaultSkippedDrift(): void
    {
        mkdir($this->filesystem_root . '/site/.git', 0755, true);
        $path = $this->filesystem_root . '/site/.git/ordinary.txt';
        file_put_contents($path, 'ordinary drift');
        $work_directory = $this->work_directory('ordinary-dot-git-drift');
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('ordinary-dot-git-result.jsonl', []),
            $work_directory,
            ['site']
        );

        $this->run_to_completion($processor);

        $this->assertSame('ordinary drift', file_get_contents($path));
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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

    public function testCopyDoesNotReadATamperedOwnershipSnapshot(): void
    {
        $work_directory = $this->work_directory('copy-restart');
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('copy-restart-result.jsonl', [
                $this->entry('remote.txt', 'file'),
            ]),
            $work_directory
        );
        $change_scope_config = $processor->get_cursor()[
            'file_sync_change_scope_config'
        ];
        $ownership_directory = base64_decode(
            $change_scope_config['ownership_directory_b64'],
            true
        );
        $this->assertIsString($ownership_directory);
        unlink(
            $ownership_directory . '/snapshots/'
                . $change_scope_config['current_snapshot_id']
                . '.paths.jsonl'
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
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
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('parent-stack-result.jsonl', []),
            $work_directory,
            ['selected'],
            [],
            true
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
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('discard-stack-tail-result.jsonl', []),
            $work_directory,
            ['selected'],
            [],
            true
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
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('corrupt-parent-stack-result.jsonl', []),
            $work_directory,
            ['selected'],
            [],
            true
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
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('repeat-prune-result.jsonl', []),
            $work_directory,
            ['selected'],
            [],
            true
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
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('changed-parent-result.jsonl', []),
            $work_directory,
            ['selected'],
            [],
            true
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

    public function testPriorOwnershipRemovesOnlyUnprotectedLocalPaths(): void
    {
        mkdir(
            $this->filesystem_root . '/selected/protected/current',
            0755,
            true
        );
        file_put_contents(
            $this->filesystem_root . '/selected/stale.txt',
            'stale'
        );
        file_put_contents(
            $this->filesystem_root . '/selected/protected/keep.txt',
            'protected'
        );
        file_put_contents(
            $this->filesystem_root
                . '/selected/protected/current/remove.txt',
            'current'
        );
        file_put_contents($this->filesystem_root . '/outside.txt', 'outside');
        $work_directory = $this->work_directory('ownership-precedence');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('ownership-precedence-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig(
                [[
                    'kind' => 'root',
                    'path' => '/selected/protected/current',
                ]],
                [['kind' => 'root', 'path' => '/selected']],
                [['kind' => 'root', 'path' => '/selected/protected']]
            )
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/stale.txt'
        );
        $this->assertFileExists(
            $this->filesystem_root . '/selected/protected/keep.txt'
        );
        $this->assertFileDoesNotExist(
            $this->filesystem_root
                . '/selected/protected/current/remove.txt'
        );
        $this->assertFileExists($this->filesystem_root . '/outside.txt');
        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/protected/current'
        );
    }

    public function testRemapSourceHoleKeepsTheLocalPath(): void
    {
        mkdir($this->filesystem_root . '/mapped/hole', 0755, true);
        mkdir($this->filesystem_root . '/elsewhere', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/mapped/remove.txt',
            'remove'
        );
        file_put_contents(
            $this->filesystem_root . '/mapped/hole/keep.txt',
            'keep'
        );
        $work_directory = $this->work_directory('remap-source-hole');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('remap-source-hole-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig(
                [['kind' => 'root', 'path' => '/site']],
                [],
                [],
                [],
                ['/site'],
                [
                    '/site' => $this->filesystem_root . '/mapped',
                    '/site/hole' => $this->filesystem_root . '/elsewhere',
                ]
            )
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/mapped/remove.txt'
        );
        $this->assertFileExists(
            $this->filesystem_root . '/mapped/hole/keep.txt'
        );
        $this->assertDirectoryExists(
            $this->filesystem_root . '/mapped/hole'
        );
    }

    public function testReplacementUsesTheResultLinkTypeAsAuthority(): void
    {
        mkdir($this->filesystem_root . '/selected', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/entry',
            'old file'
        );
        $work_directory = $this->work_directory('governing-link-type');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('governing-link-result.jsonl', [
                $this->entry('selected/entry', 'link'),
            ]),
            $work_directory,
            $this->localScopeConfig([
                ['kind' => 'exact', 'path' => '/selected/entry'],
            ], [], [], [], ['/selected'])
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');

        $cursor = $this->stored_cursor($processor->get_cursor());
        $this->assertSame('link', $cursor['position']['governing_type']);
        $this->assertSame(
            'file',
            $cursor['position']['expected_base']['type']
        );
        $this->run_to_completion($processor);
        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/entry'
        );
    }

    public function testEmptyDirectoryKeepsAnAbsentProtectedDescendant(): void
    {
        mkdir($this->filesystem_root . '/selected/a', 0755, true);
        $work_directory = $this->work_directory(
            'empty-protected-descendant'
        );
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('empty-protected-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig(
                [['kind' => 'root', 'path' => '/current']],
                [['kind' => 'root', 'path' => '/selected']],
                [
                    ['kind' => 'ancestor', 'path' => '/selected/a'],
                    [
                        'kind' => 'root',
                        'path' => '/selected/a/future',
                    ],
                ],
                [],
                ['/'],
                [],
                true
            )
        );

        $this->run_to_completion($processor);

        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/a'
        );
    }

    public function testCachesOmittedStillRemoveVerifiedEmptyDirectory(): void
    {
        mkdir($this->filesystem_root . '/selected/empty', 0755, true);
        $work_directory = $this->work_directory(
            'empty-with-caches-omitted'
        );
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('empty-caches-omitted-result.jsonl', []),
            $work_directory,
            ['selected']
        );

        $this->run_to_completion($processor);

        $this->assertDirectoryDoesNotExist(
            $this->filesystem_root . '/selected/empty'
        );
        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected'
        );
    }

    public function testCurrentAndPriorExactLinksMayReplaceEmptyDirectories(): void
    {
        foreach (['current', 'prior'] as $ownership) {
            $index_path = 'selected/' . $ownership;
            mkdir($this->filesystem_root . '/' . $index_path, 0755, true);
            $work_directory = $this->work_directory(
                'exact-link-over-empty-directory-' . $ownership
            );
            $exact_atom = [[
                'kind' => 'exact',
                'path' => '/' . $index_path,
            ]];
            $processor = FileSyncCleanupProcessor::start(
                $work_directory,
                $this->write_index(
                    'exact-link-over-empty-directory-' . $ownership
                        . '-result.jsonl',
                    [$this->entry($index_path, 'link')]
                ),
                $work_directory,
                $this->localScopeConfig(
                    $ownership === 'current' ? $exact_atom : [],
                    $ownership === 'prior' ? $exact_atom : [],
                    [],
                    [],
                    ['/selected']
                )
            );

            $this->run_to_completion($processor);

            $this->assertDirectoryDoesNotExist(
                $this->filesystem_root . '/' . $index_path
            );
        }
    }

    public function testParentPruningKeepsTheScopeTraversalRoot(): void
    {
        mkdir($this->filesystem_root . '/selected/a/b', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/b/stale.txt',
            'stale'
        );
        $work_directory = $this->work_directory('scope-prune-boundary');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('scope-prune-boundary-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig([
                ['kind' => 'root', 'path' => '/selected/a'],
            ], [], [], [], ['/'], [], true)
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a/b'
        );
        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/a'
        );
    }

    public function testParentPruningKeepsAnAbsentProtectedDescendant(): void
    {
        mkdir($this->filesystem_root . '/selected/a/b', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/b/stale.txt',
            'stale'
        );
        $work_directory = $this->work_directory('protected-prune-boundary');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('protected-prune-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig(
                [['kind' => 'root', 'path' => '/current']],
                [['kind' => 'root', 'path' => '/selected']],
                [
                    ['kind' => 'ancestor', 'path' => '/selected/a'],
                    [
                        'kind' => 'root',
                        'path' => '/selected/a/future',
                    ],
                ],
                [],
                ['/'],
                [],
                true
            )
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a/b'
        );
        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/a'
        );
    }

    public function testParentPruningStopsWhenCachesWereOmitted(): void
    {
        mkdir($this->filesystem_root . '/selected/a/b', 0755, true);
        file_put_contents(
            $this->filesystem_root . '/selected/a/b/stale.txt',
            'stale'
        );
        $work_directory = $this->work_directory('cache-prune-boundary');
        $processor = $this->startCleanup(
            $work_directory,
            $this->filesystem_root,
            $this->write_index('cache-prune-result.jsonl', []),
            $work_directory,
            ['selected']
        );

        $this->run_to_completion($processor);

        $this->assertFileDoesNotExist(
            $this->filesystem_root . '/selected/a/b/stale.txt'
        );
        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/a/b'
        );
    }

    public function testTamperedRemovalCursorRechecksEmptyDirectoryNamespace(): void
    {
        mkdir($this->filesystem_root . '/selected/a', 0755, true);
        $work_directory = $this->work_directory(
            'tampered-empty-directory-scope'
        );
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('tampered-empty-directory-result.jsonl', []),
            $work_directory,
            $this->localScopeConfig(
                [['kind' => 'root', 'path' => '/selected']],
                [],
                [],
                [],
                ['/'],
                [],
                true
            )
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $processor->flush_pending_output();
        $cursor = $this->stored_cursor($processor->get_cursor());
        $processor->close();

        $cursor['file_sync_change_scope_config'] = $this->localScopeConfig(
            [['kind' => 'root', 'path' => '/current']],
            [['kind' => 'root', 'path' => '/selected']],
            [
                ['kind' => 'ancestor', 'path' => '/selected/a'],
                ['kind' => 'root', 'path' => '/selected/a/future'],
            ],
            [],
            ['/'],
            [],
            true
        );
        $resumed = FileSyncCleanupProcessor::resume($cursor);
        $this->run_to_completion($resumed);

        $this->assertDirectoryExists(
            $this->filesystem_root . '/selected/a'
        );
    }

    public function testTamperedOutOfScopeRemovalCursorIgnoresPhysicalDrift(): void
    {
        mkdir($this->filesystem_root . '/selected', 0755, true);
        $path = $this->filesystem_root . '/selected/entry';
        file_put_contents($path, 'old file');
        $work_directory = $this->work_directory('out-of-scope-drift');
        $processor = FileSyncCleanupProcessor::start(
            $work_directory,
            $this->write_index('out-of-scope-drift-result.jsonl', [
                $this->entry('selected/entry', 'link'),
            ]),
            $work_directory,
            $this->localScopeConfig([
                ['kind' => 'exact', 'path' => '/selected/entry'],
            ], [], [], [], ['/selected'])
        );
        do {
            $processor->next_step();
        } while ($processor->get_phase() !== 'removing');
        $processor->flush_pending_output();
        $cursor = $this->stored_cursor($processor->get_cursor());
        $processor->close();

        // An exact link atom does not authorize changing a file at that path.
        $cursor['position']['governing_type'] = 'file';
        file_put_contents($path, 'new file state');
        clearstatcache(true, $path);
        $resumed = FileSyncCleanupProcessor::resume($cursor);

        $this->assertFalse($resumed->next_step());
        $this->assertSame('complete', $resumed->get_status());
        $this->assertSame('new file state', file_get_contents($path));
        $resumed->close();
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
        $processor = $this->startCleanup(
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
        $this->assertArrayNotHasKey(
            'included_index_path_roots_b64',
            $cursor
        );
        $this->assertSame(
            'local_relative',
            $cursor['file_sync_change_scope_config'][
                'index_path_coordinates'
            ]
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
        $processor = $this->startCleanup(
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

    /**
     * Starts cleanup through a local-coordinate selection scope.
     *
     * @param list<string> $included_index_path_roots
     * @param list<string> $excluded_index_path_roots
     */
    private function startCleanup(
        string $work_directory,
        string $filesystem_root,
        string $patch_result_index_file,
        string $storage_path,
        array $included_index_path_roots = [''],
        array $excluded_index_path_roots = [],
        bool $include_caches = false
    ): FileSyncCleanupProcessor {
        $remote_roots = array_map(
            static fn (string $path): string => $path === ''
                ? '/'
                : '/' . $path,
            $included_index_path_roots
        );
        return FileSyncCleanupProcessor::start(
            $work_directory,
            $patch_result_index_file,
            $storage_path,
            $this->localScopeConfig(
                array_map(
                    static fn (string $path): array => [
                        'kind' => 'root',
                        'path' => $path,
                    ],
                    $remote_roots
                ),
                [],
                [],
                array_map(
                    static fn (string $path): string => $path === ''
                        ? '/'
                        : '/' . $path,
                    $excluded_index_path_roots
                ),
                $remote_roots,
                [],
                $include_caches
            )
        );
    }

    /**
     * @param list<array{kind:'root'|'exact'|'ancestor',path:string}> $current_atoms
     * @param list<array{kind:'root'|'exact'|'ancestor',path:string}> $prior_atoms
     * @param list<array{kind:'root'|'exact'|'ancestor',path:string}> $protected_atoms
     * @param list<string> $excluded_remote_roots
     * @param list<string> $original_remote_roots
     * @param array<string,string> $resolved_path_mappings
     * @return array<string,mixed>
     */
    private function localScopeConfig(
        array $current_atoms,
        array $prior_atoms = [],
        array $protected_atoms = [],
        array $excluded_remote_roots = [],
        array $original_remote_roots = ['/'],
        array $resolved_path_mappings = [],
        bool $include_caches = false
    ): array {
        $current_snapshot_id = $this->publishOwnershipSnapshot(
            $current_atoms
        );
        $ownership_directory = $this->last_ownership_directory;
        $prior_snapshot_ids = $prior_atoms === []
            ? []
            : [$this->publishOwnershipSnapshot(
                $prior_atoms,
                $ownership_directory
            )];
        $protected_snapshot_ids = $protected_atoms === []
            ? []
            : [$this->publishOwnershipSnapshot(
                $protected_atoms,
                $ownership_directory
            )];
        sort($prior_snapshot_ids, SORT_STRING);
        sort($protected_snapshot_ids, SORT_STRING);
        sort($excluded_remote_roots, SORT_STRING);
        $mapper = new RemoteToLocalPathMapper(
            $this->filesystem_root,
            $original_remote_roots,
            $resolved_path_mappings
        );
        $remote_scope = FileSyncChangeScope::from_config([
            'index_path_coordinates' => 'remote_absolute',
            'ownership_directory_b64' => base64_encode($ownership_directory),
            'current_snapshot_id' => $current_snapshot_id,
            'prior_snapshot_ids' => $prior_snapshot_ids,
            'protected_snapshot_ids' => $protected_snapshot_ids,
            'excluded_remote_absolute_path_roots_b64' => array_map(
                'base64_encode',
                $excluded_remote_roots
            ),
            'include_caches' => $include_caches,
        ]);
        $mapping_work_directory = $this->temporary_directory
            . '/scope-mapping-' . bin2hex(random_bytes(4));
        mkdir($mapping_work_directory, 0755, true);
        $mapping_processor = FileSyncChangeScopeMappingProcessor::start(
            $remote_scope,
            $mapper,
            $mapping_work_directory
        );
        do {
            $has_next_mapping_step = $mapping_processor->next_step();
        } while ($has_next_mapping_step);
        $local_scope_config =
            $mapping_processor->get_local_change_scope_config();
        $mapping_processor->close();
        $remote_scope->close();
        return $local_scope_config;
    }

    /**
     * @param list<array{kind:'root'|'exact'|'ancestor',path:string}> $atoms
     */
    private function publishOwnershipSnapshot(
        array $atoms,
        ?string $ownership_directory = null
    ): string
    {
        if ($ownership_directory === null) {
            $ownership_directory = $this->temporary_directory . '/ownership-'
                . bin2hex(random_bytes(4));
            $this->last_ownership_directory = $ownership_directory;
            mkdir($ownership_directory . '/snapshots', 0755, true);
        }
        usort(
            $atoms,
            static fn (array $left, array $right): int => strcmp(
                $left['path'] . "\0" . $left['kind'],
                $right['path'] . "\0" . $right['kind']
            )
        );
        $snapshot_id = hash('sha256', random_bytes(16));
        $paths_path = $this->ownershipSnapshotPath(
            $snapshot_id,
            'paths.jsonl',
            $ownership_directory
        );
        $paths_handle = fopen($paths_path, 'wb');
        $this->assertIsResource($paths_handle);
        $lookup_rows = [];
        foreach ($atoms as $atom) {
            $paths_byte_offset = ftell($paths_handle);
            $this->assertIsInt($paths_byte_offset);
            fwrite($paths_handle, json_encode([
                'kind' => $atom['kind'],
                'path_b64' => base64_encode($atom['path']),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $lookup_rows[] = hash(
                'sha256',
                $atom['kind'] . "\0" . $atom['path']
            ) . ' ' . sprintf('%016x', $paths_byte_offset) . "\n";
        }
        fclose($paths_handle);
        sort($lookup_rows, SORT_STRING);
        file_put_contents(
            $this->ownershipSnapshotPath(
                $snapshot_id,
                'lookup',
                $ownership_directory
            ),
            implode('', $lookup_rows)
        );
        return $snapshot_id;
    }

    private function ownershipSnapshotPath(
        string $snapshot_id,
        string $extension,
        string $ownership_directory
    ): string {
        return $ownership_directory . '/snapshots/'
            . $snapshot_id . '.' . $extension;
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
