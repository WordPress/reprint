<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\decode_local_index_entry;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-pull-local-index-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-file-sync-change-scope-mapping-processor.php';

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
        $change_scope = $this->change_scope(new RemoteToLocalPathMapper(
            $this->filesystem_root,
            ['/remote'],
            [
                '/remote/a.txt' => $this->filesystem_root . '/z-local.txt',
                '/remote/z.txt' => $this->filesystem_root . '/a-local.txt',
            ]
        ));

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $change_scope,
                $this->temporary_directory . '/storage'
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
    }

    public function testPatchResultIgnoresUnownedRowCollidingWithOwnedAlias(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/owned/file.txt', 'file', 11, 12),
            $this->remote_entry('/unowned/file.txt', 'file', 21, 22),
        ]);
        $this->write_local_index([]);
        $shared_path = $this->filesystem_root . '/shared/file.txt';

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/owned', '/unowned'],
                        [
                            '/owned/file.txt' => $shared_path,
                            '/unowned/file.txt' => $shared_path,
                        ]
                    ),
                    ['/owned']
                )
            )
        );

        $this->assertSame([
            [
                'path' => 'shared/file.txt',
                'ctime' => 12,
                'size' => 11,
                'type' => 'file',
            ],
        ], $entries);
    }

    public function testUnownedCollidingAliasDriftDoesNotRestartConfirmation(): void
    {
        $this->write_file('shared/file.txt', 'fetched');
        $owned_entry = $this->remote_entry('/owned/file.txt', 'file', 7, 10);
        $this->write_remote_index_file(
            $this->next_remote_index_file,
            [
                $owned_entry,
                $this->remote_entry('/unowned/file.txt', 'file', 9, 21),
            ]
        );
        $this->write_remote_index_file(
            $this->remote_index_file,
            [
                $owned_entry,
                $this->remote_entry('/unowned/file.txt', 'file', 8, 20),
            ]
        );
        $this->write_local_index([
            $this->local_entry_from_path('shared/file.txt'),
        ]);
        $shared_path = $this->filesystem_root . '/shared/file.txt';

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/owned', '/unowned'],
                        [
                            '/owned/file.txt' => $shared_path,
                            '/unowned/file.txt' => $shared_path,
                        ]
                    ),
                    ['/owned']
                ),
                $this->temporary_directory . '/storage'
            )
        );

        $this->assertSame(
            ['shared/file.txt'],
            array_column($entries, 'path')
        );
    }

    public function testPatchResultKeepsRemoteMetadataAndEmptyDirectory(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/empty', 'dir', 0, 456, true),
            $this->remote_entry('/remote/file.txt', 'file', 123, 789),
        ]);
        $this->write_local_index([]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ))
            )
        );

        $this->assertSame(
            [
                [
                    'path' => 'empty',
                    'ctime' => 456,
                    'size' => 0,
                    'type' => 'dir',
                    'empty' => true,
                ],
                [
                    'path' => 'file.txt',
                    'ctime' => 789,
                    'size' => 123,
                    'type' => 'file',
                ],
            ],
            $entries
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
                $this->retained_local_index_file,
                $this->change_scope(new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ))
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
            $this->retained_local_index_file,
            $this->change_scope(new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                ['/remote' => $this->filesystem_root]
            ))
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
            $this->start_processor(['parent'])
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
            $this->start_processor()
        );

        $this->assertSame(
            ['parent-name.txt', 'parent/child.txt'],
            array_column($entries, 'path')
        );
    }

    public function testDropsMappedEmptyDirectoryImpliedByOverlappingRemapDescendant(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/empty', 'dir', 0, 10, true),
            $this->remote_entry('/remote/interleaving.txt', 'file', 11, 11),
            $this->remote_entry('/remote/leaf.txt', 'file', 12, 12),
        ]);
        $this->write_local_index([]);
        $processor = PullLocalIndexProcessor::start_patch_result(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/empty' => $this->filesystem_root . '/parent',
                    '/remote/interleaving.txt' =>
                        $this->filesystem_root . '/parent-name.txt',
                    '/remote/leaf.txt' =>
                        $this->filesystem_root . '/parent/child.txt',
                ]
            ))
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
            $this->retained_local_index_file,
            $this->change_scope(new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/ancestor' => $this->filesystem_root . '/parent',
                    '/remote/leaf.txt' =>
                        $this->filesystem_root . '/parent/child.txt',
                ]
            ))
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
            $this->remote_entry('/remote/source/parent-name.txt', 'file'),
            $this->remote_entry('/remote/source/parent/child.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry('parent', 10, 10),
        ]);
        $processor = PullLocalIndexProcessor::start_patch_result(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/source/parent-name.txt' =>
                        $this->filesystem_root . '/parent-name.txt',
                    '/remote/source/parent/child.txt' =>
                        $this->filesystem_root . '/parent/child.txt',
                ]
            ), ['/remote/source'])
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
        $processor = $this->start_processor();
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

    public function testProtectedDescendantKeepsDirectoryInsteadOfCurrentExactLink(): void
    {
        mkdir($this->filesystem_root . '/value', 0700, true);
        $this->write_remote_index([
            $this->remote_entry('/remote/value', 'link', 6),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('value'),
        ]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/remote'],
                        ['/remote' => $this->filesystem_root]
                    ),
                    [],
                    [],
                    false,
                    ['/remote/value/future'],
                    ['/remote/value']
                )
            )
        );

        $this->assertSame(['value'], array_column($entries, 'path'));
        $this->assertSame('dir', $entries[0]['type']);
    }

    public function testExcludedDescendantKeepsDirectoryInsteadOfSelectedLink(): void
    {
        mkdir($this->filesystem_root . '/value', 0700, true);
        $this->write_remote_index([
            $this->remote_entry('/remote/value', 'link', 6),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('value'),
        ]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/remote'],
                        ['/remote' => $this->filesystem_root]
                    ),
                    ['/remote'],
                    ['/remote/value/future']
                )
            )
        );

        $this->assertSame(['value'], array_column($entries, 'path'));
        $this->assertSame('dir', $entries[0]['type']);
    }

    public function testFirstPullDoesNotCreateLeafAboveExcludedDescendant(): void
    {
        $this->write_remote_index([
            $this->remote_entry('/remote/value', 'link', 6),
        ]);
        $this->write_local_index([]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_patch_result(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/remote'],
                        ['/remote' => $this->filesystem_root]
                    ),
                    ['/remote'],
                    ['/remote/value/future']
                )
            )
        );

        $this->assertSame([], $entries);
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
            $this->start_processor(['parent'])
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
            $this->change_scope(new RemoteToLocalPathMapper(
                $this->filesystem_root,
                ['/remote'],
                [
                    '/remote/a.txt' => $local_path,
                    '/remote/b.txt' => $local_path,
                ]
            )),
            $this->temporary_directory . '/storage'
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

    public function testUnchangedExactGitLinkCompletesFinalVerification(): void
    {
        mkdir($this->filesystem_root . '/.git', 0700, true);
        $path = $this->filesystem_root . '/.git/link';
        symlink('target', $path);
        $this->write_remote_index([
            $this->remote_entry('/remote/.git/link', 'link', 6),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('.git/link'),
        ]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/remote'],
                        ['/remote' => $this->filesystem_root]
                    ),
                    [],
                    [],
                    false,
                    [],
                    ['/remote/.git/link']
                ),
                $this->temporary_directory . '/storage'
            )
        );

        $this->assertSame(['.git/link'], array_column($entries, 'path'));
    }

    public function testOrdinaryUnownedCacheDriftCompletesFinalVerification(): void
    {
        $this->write_file('value.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file'),
        ]);
        $this->write_local_index([$this->local_entry_from_path('value.txt')]);
        $processor = $this->start_processor();
        while ($processor->get_phase() !== 'verifying') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        $this->write_file('.git/unowned.txt', 'cache drift');

        $entries = $this->run_to_completion($processor);

        $this->assertSame(['value.txt'], array_column($entries, 'path'));
    }

    public function testSelectedCacheDriftRestartsWhenCachesAreIncluded(): void
    {
        $this->write_file('value.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file'),
        ]);
        $this->write_local_index([$this->local_entry_from_path('value.txt')]);
        $processor = PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ),
                ['/remote'],
                [],
                true
            ),
            $this->temporary_directory . '/storage'
        );
        while ($processor->get_phase() !== 'verifying') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        $this->write_file('.git/selected.txt', 'cache drift');

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testFinalVerificationIgnoresOutsideAndProtectedAdditions(): void
    {
        $this->write_file('selected/value.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/selected/value.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('selected/value.txt'),
        ]);
        $processor = PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ),
                ['/remote/selected'],
                [],
                false,
                ['/remote/protected']
            ),
            $this->temporary_directory . '/storage'
        );
        while ($processor->get_phase() !== 'verifying') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        $this->write_file('outside.txt', 'outside');
        $this->write_file('protected/new.txt', 'protected');

        $entries = $this->run_to_completion($processor);

        $this->assertSame(['selected/value.txt'], array_column($entries, 'path'));
    }

    public function testFinalVerificationSelectedDeletionReturnsRestart(): void
    {
        $this->write_file('value.txt', 'fetched');
        $this->write_remote_index([
            $this->remote_entry('/remote/value.txt', 'file'),
        ]);
        $this->write_local_index([$this->local_entry_from_path('value.txt')]);
        $processor = $this->start_processor();
        while ($processor->get_phase() !== 'verifying') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        unlink($this->filesystem_root . '/value.txt');

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testFinalVerificationUsesReplaceSourceTypeForExactLink(): void
    {
        $path = $this->filesystem_root . '/value';
        symlink('target', $path);
        $this->write_remote_index([
            $this->remote_entry('/remote/value', 'link', 6),
        ]);
        $this->write_local_index([$this->local_entry_from_path('value')]);
        $processor = PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ),
                [],
                [],
                false,
                [],
                ['/remote/value']
            ),
            $this->temporary_directory . '/storage'
        );
        while ($processor->get_phase() !== 'verifying') {
            $this->assertTrue($processor->next_step());
            $processor->flush_pending_output();
        }
        unlink($path);
        file_put_contents($path, 'file');

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testFinalVerificationUsesDeleteBaseTypeForExactLink(): void
    {
        $path = $this->filesystem_root . '/obsolete-link';
        symlink('target', $path);
        $this->write_remote_index([]);
        $this->write_local_index([
            $this->local_entry_from_path('obsolete-link'),
        ]);
        $processor = PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ),
                [],
                [],
                false,
                [],
                ['/remote/obsolete-link']
            ),
            $this->temporary_directory . '/storage'
        );

        $this->run_to_completion_without_closing($processor);

        $this->assertSame('restart', $processor->get_status());
        $processor->close();
    }

    public function testFinalVerificationPreservesFifoAtPriorExactTombstone(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('This PHP build cannot create a FIFO.');
        }
        mkdir($this->filesystem_root . '/.git');
        $fifo_path = $this->filesystem_root . '/.git/obsolete';
        $this->assertTrue(posix_mkfifo($fifo_path, 0600));
        $this->write_remote_index([]);
        $this->write_local_index([
            [
                'path' => '.git/obsolete',
                'ctime' => 1,
                'size' => 6,
                'type' => 'link',
            ],
        ]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/remote'],
                        ['/remote' => $this->filesystem_root]
                    ),
                    [],
                    [],
                    false,
                    [],
                    [],
                    ['/remote/.git/obsolete']
                ),
                $this->temporary_directory . '/storage'
            )
        );

        $this->assertSame([], $entries);
        clearstatcache(true, $fifo_path);
        $this->assertSame('fifo', filetype($fifo_path));
        unlink($fifo_path);
    }

    public function testCandidateRetainsProtectedRemapSourceHole(): void
    {
        $this->write_file('shared/kept.txt', 'kept');
        $this->write_file('shared/hole/protected.txt', 'protected');
        $this->write_remote_index([
            $this->remote_entry('/a/kept.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('shared/kept.txt'),
            $this->local_entry_from_path('shared/hole/protected.txt'),
        ]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/a', '/b'],
                        [
                            '/a' => $this->filesystem_root . '/shared',
                            '/a/hole' => $this->filesystem_root . '/elsewhere',
                            '/b' => $this->filesystem_root . '/shared/hole',
                        ]
                    ),
                    ['/a'],
                    [],
                    false,
                    ['/b']
                ),
                $this->temporary_directory . '/storage'
            )
        );

        $this->assertSame(
            ['shared/hole/protected.txt', 'shared/kept.txt'],
            array_column($entries, 'path')
        );
    }

    public function testCandidateMapsFollowedTarget(): void
    {
        $this->write_file('followed/outside/file.txt', 'followed');
        $this->write_remote_index([
            $this->remote_entry('/outside/file.txt', 'file'),
        ]);
        $this->write_local_index([
            $this->local_entry_from_path('followed/outside/file.txt'),
        ]);

        $entries = $this->run_to_completion(
            PullLocalIndexProcessor::start_next_local_index(
                $this->work_directory,
                $this->next_remote_index_file,
                $this->remote_index_file,
                $this->retained_local_index_file,
                $this->change_scope(
                    new RemoteToLocalPathMapper(
                        $this->filesystem_root,
                        ['/remote'],
                        [],
                        $this->filesystem_root . '/followed'
                    ),
                    ['/outside']
                ),
                $this->temporary_directory . '/storage'
            )
        );

        $this->assertSame(
            ['followed/outside/file.txt'],
            array_column($entries, 'path')
        );
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
            $this->change_scope(
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    [$remote_root],
                    [$remote_root . "/file-\xff.txt" => $local_path]
                ),
                [$remote_root],
                [$remote_root . "/excluded-\xfc"]
            ),
            $this->temporary_directory . '/storage'
        );
        $cursor = $processor->get_cursor();
        $this->assertArrayHasKey('file_sync_change_scope_config', $cursor);
        $this->assertArrayNotHasKey('path_mapper_config', $cursor);
        $this->assertArrayNotHasKey(
            'included_local_index_path_roots_b64',
            $cursor
        );
        $this->assertArrayNotHasKey('verification_include_caches', $cursor);
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
     * @param list<string> $remote_roots
     * @param list<string> $excluded_remote_roots
     * @param list<string> $protected_remote_roots
     * @param list<string> $current_exact_remote_paths
     * @param list<string> $prior_exact_remote_paths
     */
    private function change_scope(
        RemoteToLocalPathMapper $path_mapper,
        array $remote_roots = ['/remote'],
        array $excluded_remote_roots = [],
        bool $include_caches = false,
        array $protected_remote_roots = [],
        array $current_exact_remote_paths = [],
        array $prior_exact_remote_paths = []
    ): FileSyncChangeScope {
        $current_atoms = array_map(
            static fn (string $path): array => [
                'kind' => 'root',
                'path' => $path,
            ],
            $remote_roots
        );
        foreach ($current_exact_remote_paths as $path) {
            $current_atoms[] = ['kind' => 'exact', 'path' => $path];
        }
        $snapshot_id = $this->publish_ownership_snapshot($current_atoms);
        $protected_snapshot_ids = [];
        if ($protected_remote_roots !== []) {
            $protected_snapshot_ids[] = $this->publish_ownership_snapshot(
                array_map(
                    static fn (string $path): array => [
                        'kind' => 'root',
                        'path' => $path,
                    ],
                    $protected_remote_roots
                )
            );
        }
        $prior_snapshot_ids = [];
        if ($prior_exact_remote_paths !== []) {
            $prior_snapshot_ids[] = $this->publish_ownership_snapshot(
                array_map(
                    static fn (string $path): array => [
                        'kind' => 'exact',
                        'path' => $path,
                    ],
                    $prior_exact_remote_paths
                )
            );
        }
        $ownership_directory = $this->temporary_directory
            . '/pull-state/files-pull-ownership';
        $remote_scope = FileSyncChangeScope::from_config([
            'index_path_coordinates' => 'remote_absolute',
            'ownership_directory_b64' => base64_encode($ownership_directory),
            'current_snapshot_id' => $snapshot_id,
            'prior_snapshot_ids' => $prior_snapshot_ids,
            'protected_snapshot_ids' => $protected_snapshot_ids,
            'excluded_remote_absolute_path_roots_b64' => array_map(
                'base64_encode',
                $excluded_remote_roots
            ),
            'include_caches' => $include_caches,
        ]);
        try {
            $mapping_processor = FileSyncChangeScopeMappingProcessor::start(
                $remote_scope,
                $path_mapper,
                $this->temporary_directory
                    . '/scope-mapping-'
                    . bin2hex(random_bytes(6))
            );
            try {
                do {
                    $has_next_step = $mapping_processor->next_step();
                } while ($has_next_step);
                $local_config =
                    $mapping_processor->get_local_change_scope_config();
            } finally {
                $mapping_processor->close();
            }
            return FileSyncChangeScope::from_config($local_config);
        } finally {
            $remote_scope->close();
        }
    }

    /** @param list<array{kind:'root'|'exact',path:string}> $atoms */
    private function publish_ownership_snapshot(array $atoms): string
    {
        $snapshots_directory = $this->temporary_directory
            . '/pull-state/files-pull-ownership/snapshots';
        if (!is_dir($snapshots_directory)) {
            mkdir($snapshots_directory, 0777, true);
        }
        $expanded_atoms = [];
        foreach ($atoms as $atom) {
            $expanded_atoms[$atom['kind'] . "\0" . $atom['path']] = $atom;
            if ($atom['kind'] !== 'root') {
                continue;
            }
            $ancestor = $atom['path'];
            while ($ancestor !== '/') {
                $ancestor = dirname($ancestor);
                $expanded_atoms['ancestor' . "\0" . $ancestor] = [
                    'kind' => 'ancestor',
                    'path' => $ancestor,
                ];
            }
        }
        $paths = '';
        $lookup_rows = [];
        foreach ($expanded_atoms as $atom) {
            $lookup_rows[] = hash('sha256', $atom['kind'] . "\0" . $atom['path'])
                . ' ' . sprintf('%016x', strlen($paths)) . "\n";
            $paths .= json_encode([
                'kind' => $atom['kind'],
                'path_b64' => base64_encode($atom['path']),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        sort($lookup_rows, SORT_STRING);
        $snapshot_id = bin2hex(random_bytes(32));
        file_put_contents($snapshots_directory . '/' . $snapshot_id . '.paths.jsonl', $paths);
        file_put_contents($snapshots_directory . '/' . $snapshot_id . '.lookup', implode('', $lookup_rows));
        return $snapshot_id;
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
        $remote_roots = array_map(
            static fn (string $path): string =>
                $path === '' ? '/remote' : '/remote/' . $path,
            $included_local_index_path_roots
        );
        $excluded_remote_roots = array_map(
            static fn (string $path): string => '/remote/' . $path,
            $excluded_local_index_path_roots
        );
        return PullLocalIndexProcessor::start_next_local_index(
            $this->work_directory,
            $this->next_remote_index_file,
            $this->remote_index_file,
            $this->retained_local_index_file,
            $this->change_scope(
                new RemoteToLocalPathMapper(
                    $this->filesystem_root,
                    ['/remote'],
                    ['/remote' => $this->filesystem_root]
                ),
                $remote_roots,
                $excluded_remote_roots
            ),
            $this->temporary_directory . '/storage'
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
