<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\decode_local_index_entry;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-pull-local-index-processor.php';

final class PullLocalIndexProcessorTest extends TestCase {
    private string $temporary_directory;
    private string $filesystem_root;
    private string $work_directory;
    private string $next_remote_index_file;
    private string $remote_index_file;
    private string $retained_local_index_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/pull-local-index-processor-test-'
            . bin2hex(random_bytes(6));
        $this->filesystem_root =
            $this->temporary_directory . '/filesystem-root';
        $this->work_directory = $this->temporary_directory . '/work';
        $this->next_remote_index_file =
            $this->temporary_directory . '/next-remote-index.jsonl';
        $this->remote_index_file =
            $this->temporary_directory . '/remote-index.jsonl';
        $this->retained_local_index_file =
            $this->temporary_directory . '/retained-local-index.jsonl';
        mkdir($this->filesystem_root, 0700, true);
        mkdir($this->work_directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temporary_directory);
        parent::tearDown();
    }

    public function testSortsMappedPathsAfterRemapsChangeTheirOrder(): void
    {
        $this->write_file('a-local.txt', 'a-local');
        $this->write_file('z-local.txt', 'z-local');
        $this->write_remote_index([
            $this->remote_entry('/remote/a.txt', 'file', 900, 900),
            $this->remote_entry('/remote/z.txt', 'file', 901, 901),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('a-local.txt'),
            $this->local_entry_from_path('z-local.txt'),
        ]);
        $mapper = new RemoteToLocalPathMapper(
            $this->filesystem_root,
            ['/remote'],
            [
                '/remote/a.txt' => $this->filesystem_root . '/z-local.txt',
                '/remote/z.txt' => $this->filesystem_root . '/a-local.txt',
            ]
        );

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $mapper,
                $this->temporary_directory . '/storage',
                false
            )
        );

        $this->assertSame(
            ['a-local.txt', 'z-local.txt'],
            array_column($entries, 'path')
        );
        $a_stat = lstat($this->filesystem_root . '/a-local.txt');
        $z_stat = lstat($this->filesystem_root . '/z-local.txt');
        $this->assertIsArray($a_stat);
        $this->assertIsArray($z_stat);
        $this->assertSame( (int) $a_stat['ctime'], $entries[0]['ctime']);
        $this->assertSame( (int) $a_stat['size'], $entries[0]['size']);
        $this->assertSame( (int) $z_stat['ctime'], $entries[1]['ctime']);
        $this->assertSame( (int) $z_stat['size'], $entries[1]['size']);
        $this->assertArrayNotHasKey('remote_absolute_path', $entries[0]);
        $this->assertArrayNotHasKey('remote_absolute_path', $entries[1]);
    }

    public function testPatchResultKeepsRetainedMetadataAndRemotePaths(): void
    {
        mkdir($this->filesystem_root . '/empty', 0700, true);
        $this->write_file('file.txt', 'local file');
        $this->write_remote_index([
            $this->remote_entry('/remote/empty', 'dir', 0, 456, true),
            $this->remote_entry('/remote/file.txt', 'file', 123, 789),
        ]);
        $empty_entry = $this->local_entry_from_path('empty');
        $empty_entry['empty'] = true;
        $file_entry = $this->local_entry_from_path('file.txt');
        $this->write_local_index([$empty_entry, $file_entry]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                )
            )
        );

        $this->assertSame(
            [
                $empty_entry + [
                    'remote_absolute_path' => '/remote/empty',
                ],
                $file_entry + [
                    'remote_absolute_path' => '/remote/file.txt',
                ],
            ],
            $entries
        );
    }

    public function testPatchResultKeepsAMissingRemoteEmptyDirectory(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/empty', 'dir', 0, 456, true),
        ]);
        $retained_entry = [
            'path' => 'empty',
            'ctime' => 123,
            'size' => 0,
            'type' => 'dir',
            'empty' => true,
        ];
        $this->write_local_index([$retained_entry]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                )
            )
        );

        $this->assertSame(
            $retained_entry + [
                'remote_absolute_path' => '/remote/empty',
            ],
            $entries[0]
        );
    }

    public function testFilesystemRootIsATraversalBoundaryNotAnIndexEntry(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote', 'dir', 0, 10, true),
        ]);
        $this->write_local_index([]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                )
            )
        );

        $this->assertSame([], $entries);
    }

    public function testRejectsSelectedRemoteDirectoryWithoutAnEmptyScan(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/unreadable', 'dir'),
        ]);
        $this->write_local_index([]);
        $processor = PullLocalIndexProcessor::start_patch_result(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                ['/remote' => $this->filesystem_root]
            )
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Remote directory was not confirmed empty during indexing: '
                    . base64_encode('/remote/unreadable')
                    . '.'
            );
            $processor->next_step();
        } finally {
            $processor->close();
        }
    }

    public function testDropsSelectedRetainedPathsMissingFromTheRemoteTree(): void
    {
        $this->write_file('kept.txt', 'remote value');
        $this->write_remote_index([
            $this->remote_entry('/remote/kept.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('kept.txt'),
            $this->local_entry('local-stale.txt', 2, 2),
            $this->local_entry('pushed-after-pull.txt', 3, 3),
        ]);

        $entries = $this->run_to_completion($this->start_processor());

        $this->assertSame(['kept.txt'], array_column($entries, 'path'));
    }

    public function testRetainsPathsOutsideSelectionAndUnderExclusions(): void
    {
        $this->write_file('selected/current.txt', 'current');
        $this->write_file('selected/excluded/remote.txt', 'excluded remote');
        $this->write_remote_index([
            $this->remote_entry('/remote/selected/current.txt', 'file'),
            $this->remote_entry('/remote/selected/excluded/remote.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry('outside.txt', 10, 10),
            $this->local_entry('selected/excluded/retained.txt', 11, 11),
            $this->local_entry('selected/stale.txt', 12, 12),
            $this->local_entry_from_path('selected/current.txt'),
        ]);

        $entries = $this->run_to_completion(
            $this->start_processor(
                ['selected'],
                ['selected/excluded']
            )
        );

        $this->assertSame(
            [
                'outside.txt',
                'selected/current.txt',
                'selected/excluded/retained.txt',
            ],
            array_column($entries, 'path')
        );
    }

    public function testDropsRetainedEmptyDirectoryImpliedBySelectedDescendant(): void
    {
        $this->write_file('parent/child.txt', 'child');
        $this->write_remote_index([
            $this->remote_entry('/remote/parent/child.txt', 'file'),
        ]);
        $this->write_local_index([
            [
                'path' => 'parent',
                'ctime' => 10,
                'size' => 0,
                'type' => 'dir',
                'empty' => true,
            ],
            $this->local_entry_from_path('parent/child.txt'),
        ]);

        $entries = $this->run_to_completion(
            $this->start_processor(['parent/child.txt'])
        );

        $this->assertSame(
            ['parent/child.txt'],
            array_column($entries, 'path')
        );
    }

    public function testDropsRetainedEmptyDirectoryAcrossInterleavingNewPath(): void
    {
        $this->write_file('parent-name.txt', 'unrelated');
        $this->write_file('parent/child.txt', 'child');
        $this->write_remote_index([
            $this->remote_entry('/remote/parent-name.txt', 'file'),
            $this->remote_entry('/remote/parent/child.txt', 'file'),
        ]);
        $this->write_local_index([
            [
                'path' => 'parent',
                'ctime' => 10,
                'size' => 0,
                'type' => 'dir',
                'empty' => true,
            ],
            $this->local_entry_from_path('parent-name.txt'),
            $this->local_entry_from_path('parent/child.txt'),
        ]);

        $entries = $this->run_to_completion(
            $this->start_processor(
                ['parent-name.txt', 'parent/child.txt']
            )
        );

        $this->assertSame(
            ['parent-name.txt', 'parent/child.txt'],
            array_column($entries, 'path')
        );
    }

    public function testDropsMappedEmptyDirectoryImpliedByOverlappingRemapDescendant(): void
    {
        $this->write_file('parent-name.txt', 'parent name');
        $this->write_file('parent/child.txt', 'child');
        $this->write_remote_index([
            $this->remote_entry('/remote/empty', 'dir', 0, 10, true),
            $this->remote_entry('/remote/interleaving.txt', 'file', 11, 11),
            $this->remote_entry('/remote/leaf.txt', 'file', 12, 12),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('parent-name.txt'),
            $this->local_entry_from_path('parent/child.txt'),
        ]);
        $processor = PullLocalIndexProcessor::start_patch_result(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/empty' => $this->filesystem_root . '/parent',
                    '/remote/interleaving.txt' =>
                        $this->filesystem_root . '/parent-name.txt',
                    '/remote/leaf.txt' =>
                        $this->filesystem_root . '/parent/child.txt',
                ]
            )
        );

        $entries = $this->run_to_completion($processor);

        $this->assertSame(
            ['parent-name.txt', 'parent/child.txt'],
            array_column($entries, 'path')
        );
    }

    /** @dataProvider mappedLeafAncestorTypeProvider */
    public function testRejectsMappedLeafAncestorOfMappedDescendant(
        string $ancestor_type
    ): void {
        $this->write_remote_index([
            $this->remote_entry('/remote/ancestor', $ancestor_type),
            $this->remote_entry('/remote/leaf.txt', 'file'),
        ]);
        $this->write_local_index([]);
        $processor = PullLocalIndexProcessor::start_patch_result(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/ancestor' => $this->filesystem_root . '/parent',
                    '/remote/leaf.txt' =>
                        $this->filesystem_root . '/parent/child.txt',
                ]
            )
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Mapped local index path '
                    . base64_encode('parent')
                    . " has type {$ancestor_type} and has a mapped descendant."
            );
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    /** @return iterable<string,array{string}> */
    public static function mappedLeafAncestorTypeProvider(): iterable
    {
        yield 'file' => ['file'];
        yield 'link' => ['link'];
    }

    public function testRejectsRetainedFileOutsideNarrowSelectionWithMappedDescendant(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/parent-name.txt', 'file'),
            $this->remote_entry('/remote/parent/child.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry('parent', 10, 10),
        ]);
        $processor = PullLocalIndexProcessor::start_patch_result(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                ['/remote' => $this->filesystem_root]
            ),
            ['parent-name.txt', 'parent/child.txt']
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Retained local index path '
                    . base64_encode('parent')
                    . ' has type file and has a selected mapped descendant.'
            );
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    public function testResumeBetweenParentMembershipLookupsWithInterleavingPath(): void
    {
        $this->write_file('parent-name.txt', 'unrelated');
        $this->write_file('parent/child.txt', 'child');
        $this->write_remote_index([
            $this->remote_entry('/remote/parent-name.txt', 'file'),
            $this->remote_entry('/remote/parent/child.txt', 'file'),
        ]);
        $this->write_local_index([
            [
                'path' => 'parent',
                'ctime' => 10,
                'size' => 0,
                'type' => 'dir',
                'empty' => true,
            ],
            $this->local_entry_from_path('parent-name.txt'),
            $this->local_entry_from_path('parent/child.txt'),
        ]);
        $processor = $this->start_processor(
            ['parent-name.txt', 'parent/child.txt']
        );
        while ($processor->get_phase() !== 'merging') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }

        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $cursor_before_parent_lookup_advance = $processor->get_cursor();
        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $cursor = $processor->get_cursor();
        $this->assertSame(
            $cursor_before_parent_lookup_advance['position'][
                'index_diff_cursor'
            ],
            $cursor['position']['index_diff_cursor']
        );
        $this->assertGreaterThan(
            0,
            $cursor['position']['mapped_index_parent_cursor'][
                'new_index_byte_offset'
            ]
        );
        $this->assertIsString(json_encode($cursor, JSON_THROW_ON_ERROR));
        $processor->close();

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::resume($cursor)
        );
        $this->assertSame(
            ['parent-name.txt', 'parent/child.txt'],
            array_column($entries, 'path')
        );
    }

    public function testFirstPullDoesNotAddAnExcludedRemoteEntry(): void
    {
        $this->write_file('selected/keep.txt', 'keep');
        $this->write_file('selected/excluded.txt', 'excluded');
        $this->write_remote_index([
            $this->remote_entry('/remote/selected/excluded.txt', 'file'),
            $this->remote_entry('/remote/selected/keep.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('selected/keep.txt'),
        ]);

        $entries = $this->run_to_completion(
            $this->start_processor(
                ['selected'],
                ['selected/excluded.txt']
            )
        );

        $this->assertSame(['selected/keep.txt'], array_column($entries, 'path'));
    }

    public function testSkipsARemoteParentWhichContainsAnExcludedRoot(): void
    {
        mkdir($this->filesystem_root . '/selected/parent/protected', 0700, true);
        $this->write_file(
            'selected/parent/protected/keep.txt',
            'protected'
        );
        $this->write_remote_index([
            $this->remote_entry(
                '/remote/selected/parent',
                'dir',
                0,
                1,
                true
            ),
        ]);
        $this->write_local_index([
            $this->local_entry(
                'selected/parent/protected/keep.txt',
                10,
                9
            ),
        ]);

        $entries = $this->run_to_completion(
            $this->start_processor(
                ['selected'],
                ['selected/parent/protected']
            )
        );

        $this->assertSame(
            ['selected/parent/protected/keep.txt'],
            array_column($entries, 'path')
        );
    }

    public function testKeepsAnEmptyDirectorySparseWhenItHasAnUnmanagedChild(): void
    {
        mkdir($this->filesystem_root . '/parent', 0700, true);
        $this->write_file('parent/node_modules/package.js', 'unmanaged');
        $this->write_remote_index([
            $this->remote_entry('/remote/parent', 'dir', 0, 1, true),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('parent'),
        ]);

        $entries = $this->run_to_completion($this->start_processor());

        $this->assertSame([], $entries);
    }

    public function testDropsRetainedEmptyAncestorWhenSelectedDirectoryHasAnUnmanagedChild(): void
    {
        $this->write_file(
            'parent/child/node_modules/package.js',
            'unmanaged'
        );
        $this->write_remote_index([
            $this->remote_entry(
                '/remote/parent/child',
                'dir',
                0,
                1,
                true
            ),
        ]);
        $this->write_local_index([
            [
                'path' => 'parent',
                'ctime' => 10,
                'size' => 0,
                'type' => 'dir',
                'empty' => true,
            ],
        ]);

        $entries = $this->run_to_completion(
            $this->start_processor(['parent/child'])
        );

        $this->assertSame([], $entries);
    }

    public function testWritesALocallyEmptyRemoteDirectory(): void
    {
        mkdir($this->filesystem_root . '/empty', 0700, true);
        $this->write_remote_index([
            $this->remote_entry('/remote/empty', 'dir', 0, 10, true),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('empty'),
        ]);

        $entries = $this->run_to_completion($this->start_processor());

        $this->assertCount(1, $entries);
        $this->assertSame('empty', $entries[0]['path']);
        $this->assertSame('dir', $entries[0]['type']);
        $this->assertTrue($entries[0]['empty']);
        $local_stat = lstat($this->filesystem_root . '/empty');
        $this->assertIsArray($local_stat);
        $this->assertSame(
            (int) $local_stat['ctime'],
            $entries[0]['ctime']
        );
    }

    public function testRejectsRemotePathsWhichMapToTheSameLocalPath(): void
    {
        $this->write_file('collision.txt', 'collision');
        $this->write_remote_index([
            $this->remote_entry('/remote/a.txt', 'file'),
            $this->remote_entry('/remote/b.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('collision.txt'),
        ]);
        $local_path = $this->filesystem_root . '/collision.txt';
        $processor = PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/a.txt' => $local_path,
                    '/remote/b.txt' => $local_path,
                ]
            ),
            $this->temporary_directory . '/storage',
            false
        );

        try {
            for ($step = 0; $step < 30; ++$step) {
                $processor->next_step();
            }
            $this->fail('Expected the local mapping collision to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Two remote paths map to the same local relative path: '
                    . base64_encode('collision.txt') . '.',
                $exception->getMessage()
            );
        } finally {
            $processor->close();
        }
    }

    public function testMissingMappedPathReturnsStableRestart(): void
    {
        $this->write_file('missing.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/missing.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('missing.txt'),
        ]);
        unlink($this->filesystem_root . '/missing.txt');
        $processor = $this->start_processor();
        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $this->assertFalse($processor->next_step());
        $cursor = json_decode(
            json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $processor->close();
        $resumed = PullLocalIndexProcessor::resume($cursor);
        $this->assertFalse($resumed->next_step());
        $this->assertSame('restart', $resumed->get_status());
        $resumed->close();
    }

    public function testMappedPathTypeChangeReturnsRestart(): void
    {
        $this->write_file('value', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/value', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('value'),
        ]);
        unlink($this->filesystem_root . '/value');
        mkdir($this->filesystem_root . '/value', 0700, true);
        $processor = $this->start_processor();
        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testFileChangedBeforeCandidateMappingReturnsRestart(): void
    {
        $this->write_file('value.txt', 'old');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file', 3, 10),
        ]);
        $retained_entry = $this->local_entry_from_path('value.txt');
        $this->write_local_index([$retained_entry]);
        $this->write_file('value.txt', 'changed after fetch');

        $processor = $this->start_processor();
        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $candidate_entries = $this->read_local_index(
            $processor->get_index_path()
        );
        $this->assertSame($retained_entry['size'], $candidate_entries[0]['size']);
        $this->assertSame(
            $retained_entry['ctime'],
            $candidate_entries[0]['ctime']
        );
        $processor->close();
    }

    public function testLocalOnlyAdditionDuringFinalVerificationReturnsRestart(): void
    {
        $this->write_file('value.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file', 7, 10),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('value.txt'),
        ]);
        $processor = $this->start_processor();
        while ($processor->get_phase() !== 'verifying') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }

        $this->write_file('local-only.txt', 'new local value');
        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testNextRemoteEntryMissingFromRemoteIndexReturnsRestart(): void
    {
        $this->write_file('value.txt', 'fetched');
        $target_entry = $this->remote_entry(
            '/remote/value.txt',
            'file',
            7,
            10
        );
        $this->write_remote_index([$target_entry]);
        $this->write_remote_index_file($this->remote_index_file, []);
        $this->write_local_index([
            $this->local_entry_from_path('value.txt'),
        ]);
        $processor = $this->start_processor();

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testSelectedRemoteIndexEntryMissingFromNextRemoteIndexReturnsRestart(): void
    {
        $stale_entry = $this->remote_entry(
            '/remote/stale.txt',
            'file',
            7,
            10
        );
        $this->write_remote_index([]);
        $this->write_remote_index_file(
            $this->remote_index_file,
            [$stale_entry]
        );
        $this->write_local_index([]);
        $processor = $this->start_processor();

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testNonzeroNextRemoteSymlinkSizeMustMatchRemoteIndex(): void
    {
        $link_path = $this->filesystem_root . '/link';
        $this->assertTrue(symlink('abcdefghij', $link_path));
        $next_remote_entry = $this->remote_entry(
            '/remote/link',
            'link',
            10,
            20
        );
        $remote_entry = $this->remote_entry(
            '/remote/link',
            'link',
            9,
            20
        );
        $this->write_remote_index([$next_remote_entry]);
        $this->write_remote_index_file(
            $this->remote_index_file,
            [$remote_entry]
        );
        $this->write_local_index([
            $this->local_entry_from_path('link'),
        ]);
        $processor = $this->start_processor();

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    /** @dataProvider verificationPhaseProvider */
    public function testResumeFromEachFinalVerificationPhase(
        string $verification_phase
    ): void {
        $this->write_file('value.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file', 7, 10),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('value.txt'),
        ]);
        $processor = $this->start_processor();
        while (true) {
            $cursor = $processor->get_cursor();
            if (
                $processor->get_phase() === 'verifying'
                && $cursor['position']['file_sync_patch_processor_cursor']
                    ['position']['phase'] === $verification_phase
            ) {
                break;
            }
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        $cursor = json_decode(
            json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $processor->close();

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::resume($cursor)
        );

        $this->assertSame(['value.txt'], array_column($entries, 'path'));
    }

    /** @return iterable<string,array{string}> */
    public static function verificationPhaseProvider(): iterable
    {
        yield 'indexing' => ['indexing'];
        yield 'sorting' => ['sorting'];
        yield 'starting patch' => ['starting_patch'];
        yield 'planning' => ['planning'];
    }

    public function testCursorAndIndexesSupportArbitraryPathBytes(): void
    {
        $remote_root = "/remote-\xfd";
        $local_root = $this->filesystem_root . '/selected';
        $local_path = $local_root . '/file.txt';
        mkdir($local_root, 0700, true);
        file_put_contents($local_path, 'bytes');
        $this->write_remote_index([
            $this->remote_entry(
                $remote_root . "/file-\xff.txt",
                'file'
            ),
        ]);
        $this->write_local_index([
            $this->local_entry("outside-\xfa.txt", 10, 10),
            $this->local_entry_from_path('selected/file.txt'),
        ]);
        $processor = PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                [$remote_root],
                [$remote_root . "/file-\xff.txt" => $local_path]
            ),
            $this->temporary_directory . '/storage',
            false,
            ['selected'],
            ["selected/excluded-\xfc"]
        );
        $cursor = $processor->get_cursor();
        $this->assertIsString(json_encode($cursor, JSON_THROW_ON_ERROR));
        $processor->close();

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::resume($cursor)
        );

        $this->assertSame(
            ["outside-\xfa.txt", 'selected/file.txt'],
            array_column($entries, 'path')
        );
    }

    public function testResumeTruncatesUnstoredMappedEntries(): void
    {
        $this->write_file('a/one.txt', 'a');
        $this->write_file('b/two.txt', 'b');
        $this->write_remote_index([
            $this->remote_entry('/remote/a/one.txt', 'file'),
            $this->remote_entry('/remote/b/two.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('a/one.txt'),
            $this->local_entry_from_path('b/two.txt'),
        ]);
        $processor = $this->start_processor();

        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $saved_cursor = $processor->get_cursor();
        $saved_offset = $saved_cursor['position'][
            'mapped_index_byte_offset'
        ];
        $saved_parents_offset = $saved_cursor['position'][
            'mapped_index_parents_byte_offset'
        ];
        $this->assertGreaterThan(0, $saved_offset);
        $this->assertGreaterThan(0, $saved_parents_offset);

        $this->assertTrue($processor->next_step());
        $processor->close();
        $mapped_local_index_file = base64_decode(
            $saved_cursor['mapped_index_file_b64'],
            true
        );
        $this->assertIsString($mapped_local_index_file);
        $mapped_index_parents_file = base64_decode(
            $saved_cursor['mapped_index_parents_file_b64'],
            true
        );
        $this->assertIsString($mapped_index_parents_file);
        clearstatcache(true, $mapped_local_index_file);
        clearstatcache(true, $mapped_index_parents_file);
        $this->assertGreaterThan(
            $saved_offset,
            filesize($mapped_local_index_file)
        );
        $this->assertGreaterThan(
            $saved_parents_offset,
            filesize($mapped_index_parents_file)
        );

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::resume($saved_cursor)
        );
        $this->assertSame(
            ['a/one.txt', 'b/two.txt'],
            array_column($entries, 'path')
        );
    }

    /** @dataProvider phaseCursorProvider */
    public function testResumeContinuesFromEachDurablePhase(
        string $phase
    ): void {
        $this->write_file('value.txt', 'value');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('value.txt'),
        ]);
        $processor = $this->start_processor();
        while ($processor->get_phase() !== $phase) {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        $cursor = $processor->get_cursor();
        $this->assertIsString(json_encode($cursor, JSON_THROW_ON_ERROR));
        $processor->close();

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::resume($cursor)
        );

        $this->assertSame(['value.txt'], array_column($entries, 'path'));
    }

    /** @return iterable<string,array{string}> */
    public static function phaseCursorProvider(): iterable
    {
        yield 'sorting' => ['sorting'];
        yield 'sorting parents' => ['sorting_parents'];
        yield 'starting merge' => ['starting_merge'];
        yield 'merging' => ['merging'];
    }

    public function testCompleteAndCloseAreStable(): void
    {
        $this->write_remote_index([]);
        $this->write_local_index([]);
        $processor = $this->start_processor();
        $this->run_to_completion_without_closing($processor);
        $complete_cursor = json_decode(
            json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($processor->next_step());
        $processor->close();
        $processor->close();
        $this->assertFalse($processor->next_step());
        $this->assertSame($complete_cursor, $processor->get_cursor());

        $resumed = PullLocalIndexProcessor::resume($complete_cursor);
        $this->assertFalse($resumed->next_step());
        $this->assertSame('complete', $resumed->get_phase());
        $resumed->close();
    }

    public function testTerminalStepKeepsTheLastResumableCursorForSignalSave(): void
    {
        $this->write_file('file.txt', 'contents');
        $this->write_remote_index([
            $this->remote_entry('/remote/file.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('file.txt'),
        ]);

        $client = new ImportClient(
            'http://fake.url',
            $this->temporary_directory . '/state',
            $this->filesystem_root
        );
        $take_steps = ( new ReflectionClass($client) )
            ->getMethod('take_files_pull_local_index_steps');
        $processor = $this->start_processor();
        for ($batch = 0; $batch < 20; ++$batch) {
            $has_next_step = $take_steps->invoke($client, $processor);
            if (!$has_next_step) {
                break;
            }
            $processor = PullLocalIndexProcessor::resume(
                $client->get_state()->diff->processor_cursor
            );
        }

        $this->assertFalse($has_next_step);
        $saved_cursor = $client->get_state()->diff->processor_cursor;
        $this->assertIsArray($saved_cursor);
        $this->assertNotSame('complete', $saved_cursor['position']['phase']);

        $resumed = PullLocalIndexProcessor::resume($saved_cursor);
        $this->run_to_completion_without_closing($resumed);
        $this->assertSame('complete', $resumed->get_status());
        $resumed->close();
    }

    public function testMissingRemoteIndexRepresentsAnEmptyConfirmationIndex(): void
    {
        $this->write_remote_index([]);
        unlink($this->remote_index_file);
        $this->write_local_index([]);

        $entries = $this->run_to_completion($this->start_processor());

        $this->assertSame([], $entries);
    }

    public function testCompleteResumeRejectsADeletedOutput(): void
    {
        $this->write_remote_index([]);
        $this->write_local_index([]);
        $processor = $this->start_processor();
        $this->run_to_completion_without_closing($processor);
        $cursor = $processor->get_cursor();
        $output = $processor->get_index_path();
        $processor->close();
        unlink($output);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('output index is missing');
        PullLocalIndexProcessor::resume($cursor);
    }

    public function testMappingResumeRejectsADeletedNextRemoteIndex(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file'),
        ]);
        $this->write_local_index([]);
        $processor = $this->start_processor();
        $cursor = $processor->get_cursor();
        $processor->close();
        unlink($this->next_remote_index_file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('next remote index is missing');
        PullLocalIndexProcessor::resume($cursor);
    }

    /** @dataProvider remoteIndexStartingPresenceProvider */
    public function testMappingResumeRejectsRemoteIndexPresenceDrift(
        bool $starts_present
    ): void
    {
        $this->write_remote_index([]);
        if (!$starts_present) {
            unlink($this->remote_index_file);
        }
        $this->write_local_index([]);
        $processor = $this->start_processor();
        $cursor = $processor->get_cursor();
        $processor->close();
        if ($starts_present) {
            unlink($this->remote_index_file);
        } else {
            file_put_contents($this->remote_index_file, '');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'remote index presence changed during pull local indexing'
        );
        PullLocalIndexProcessor::resume($cursor);
    }

    /** @return iterable<string,array{bool}> */
    public static function remoteIndexStartingPresenceProvider(): iterable
    {
        yield 'present then missing' => [true];
        yield 'missing then present' => [false];
    }

    public function testSortingResumeRejectsADeletedMappedIndex(): void
    {
        $this->write_remote_index([]);
        $this->write_local_index([]);
        $processor = $this->start_processor();
        $this->assertTrue($processor->next_step());
        $cursor = $processor->get_cursor();
        $mapped_index = base64_decode(
            $cursor['mapped_index_file_b64'],
            true
        );
        $this->assertIsString($mapped_index);
        $processor->close();
        unlink($mapped_index);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapped index is missing');
        PullLocalIndexProcessor::resume($cursor);
    }

    public function testSortingParentsResumeRejectsADeletedParentIndex(): void
    {
        $this->write_remote_index([]);
        $this->write_local_index([]);
        $processor = $this->start_processor();
        $this->assertTrue($processor->next_step());
        $this->assertTrue($processor->next_step());
        $cursor = $processor->get_cursor();
        $mapped_index_parents = base64_decode(
            $cursor['mapped_index_parents_file_b64'],
            true
        );
        $this->assertIsString($mapped_index_parents);
        $processor->close();
        unlink($mapped_index_parents);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapped index parents is missing');
        PullLocalIndexProcessor::resume($cursor);
    }

    public function testStartingMergeResumeRejectsADeletedRetainedIndex(): void
    {
        $this->write_remote_index([]);
        $this->write_local_index([
            $this->local_entry('retained.txt', 1, 1),
        ]);
        $processor = $this->start_processor();
        while ($processor->get_phase() !== 'starting_merge') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        $cursor = $processor->get_cursor();
        $processor->close();
        unlink($this->retained_local_index_file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retained local index presence changed');
        PullLocalIndexProcessor::resume($cursor);
    }

    public function testResumeTruncatesUnstoredDesiredEntries(): void
    {
        $this->write_file('a.txt', 'a');
        $this->write_file('b.txt', 'b');
        $this->write_file('c.txt', 'c');
        $this->write_remote_index([
            $this->remote_entry('/remote/a.txt', 'file'),
            $this->remote_entry('/remote/b.txt', 'file'),
            $this->remote_entry('/remote/c.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('a.txt'),
            $this->local_entry_from_path('b.txt'),
            $this->local_entry_from_path('c.txt'),
        ]);
        $processor = $this->start_processor();
        while ($processor->get_phase() !== 'merging') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }

        do {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        } while (
            $processor->get_cursor()['position']['index_byte_offset'] === 0
        );
        $saved_cursor = $processor->get_cursor();
        $saved_offset = $saved_cursor['position'][
            'index_byte_offset'
        ];
        $this->assertGreaterThan(0, $saved_offset);

        do {
            $this->assertTrue($processor->next_step());
        } while (
            $processor->get_cursor()['position']['index_byte_offset']
                === $saved_offset
        );
        $processor->close();
        $desired_local_index_file = base64_decode(
            $saved_cursor['index_file_b64'],
            true
        );
        $this->assertIsString($desired_local_index_file);
        clearstatcache(true, $desired_local_index_file);
        $this->assertGreaterThan(
            $saved_offset,
            filesize($desired_local_index_file)
        );

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::resume($saved_cursor)
        );
        $this->assertSame(
            ['a.txt', 'b.txt', 'c.txt'],
            array_column($entries, 'path')
        );
    }

    /**
     * @param list<string> $included_local_index_path_roots
     * @param list<string> $excluded_local_index_path_roots
     */
    private function start_processor(
        array $included_local_index_path_roots = [""],
        array $excluded_local_index_path_roots = []
    ): PullLocalIndexProcessor
    {
        return PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                ['/remote' => $this->filesystem_root]
            ),
            $this->temporary_directory . '/storage',
            false,
            $included_local_index_path_roots,
            $excluded_local_index_path_roots
        );
    }

    /** @return list<array{path:string,ctime:int,size:int,type:string,empty?:bool}> */
    private function run_to_completion(
        PullLocalIndexProcessor $processor
    ): array {
        $this->run_to_completion_without_closing($processor);
        $this->assertSame('complete', $processor->get_status());
        $desired_local_index_path =
            $processor->get_index_path();
        $processor->close();
        return $this->read_local_index($desired_local_index_path);
    }

    private function run_to_completion_without_closing(
        PullLocalIndexProcessor $processor
    ): void {
        for ($step = 0; $step < 300; ++$step) {
            $has_next_step = $processor->next_step();
            $processor->flush_pending_output();
            if (!$has_next_step) {
                return;
            }
        }
        $processor->close();
        $this->fail(
            'Pull local indexing did not complete within 300 steps.'
        );
    }

    /** @param list<array<string,mixed>> $entries */
    private function write_remote_index(array $entries): void
    {
        $this->write_remote_index_file(
            $this->next_remote_index_file,
            $entries
        );
        $this->write_remote_index_file($this->remote_index_file, $entries);
    }

    /** @param list<array<string,mixed>> $entries */
    private function write_remote_index_file(
        string $path,
        array $entries
    ): void
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
        file_put_contents(
            $path,
            $lines === [] ? '' : implode("\n", $lines) . "\n"
        );
    }

    /** @param list<array<string,mixed>> $entries */
    private function write_local_index(array $entries): void
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
        file_put_contents(
            $this->retained_local_index_file,
            $lines === [] ? '' : implode("\n", $lines) . "\n"
        );
    }

    /**
     * @return array{path:string,ctime:int,size:int,type:string,empty?:bool}
     */
    private function remote_entry(
        string $path,
        string $type,
        int $size = 1,
        int $ctime = 1,
        ?bool $directory_is_empty = null
    ): array {
        $entry = [
            'path' => $path,
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ];
        if ($directory_is_empty !== null) {
            $entry['empty'] = $directory_is_empty;
        }
        return $entry;
    }

    /** @return array{path:string,ctime:int,size:int,type:string} */
    private function local_entry(
        string $path,
        int $ctime,
        int $size
    ): array {
        return [
            'path' => $path,
            'ctime' => $ctime,
            'size' => $size,
            'type' => 'file',
        ];
    }

    /** @return array{path:string,ctime:int,size:int,type:string} */
    private function local_entry_from_path(string $relative_path): array
    {
        $absolute_path = $this->filesystem_root . '/' . $relative_path;
        $path_stat = lstat($absolute_path);
        $this->assertIsArray($path_stat);
        $entry = \WordPress\Reprint\Exporter\read_file_index_entry_from_stat(
            $absolute_path,
            $path_stat
        );
        $entry['path'] = $relative_path;
        $this->assertNotSame('other', $entry['type']);
        return $entry;
    }

    /** @return list<array{path:string,ctime:int,size:int,type:string,empty?:bool}> */
    private function read_local_index(string $path): array
    {
        $lines = file(
            $path,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $this->assertIsArray($lines);
        return array_map(
            static fn (string $line): array => decode_local_index_entry($line),
            $lines
        );
    }

    private function write_file(string $relative_path, string $contents): void
    {
        $path = $this->filesystem_root . '/' . $relative_path;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
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
