<?php
declare(strict_types=1);

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the following line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-file-sync-change-scope-mapping-processor.php';

final class FileSyncChangeScopeMappingProcessorTest extends TestCase
{
    private string $temporaryDirectory;
    private string $ownershipDirectory;
    private string $filesystemRoot;
    private int $nextSnapshotNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir()
            . '/change-scope-mapping-' . uniqid('', true);
        $this->ownershipDirectory = $this->temporaryDirectory . '/ownership';
        $this->filesystemRoot = $this->temporaryDirectory . '/local';
        mkdir($this->ownershipDirectory . '/snapshots', 0777, true);
        mkdir($this->filesystemRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testMaterializesOnlySelectedCurrentAndPriorSkippedExactPaths(): void
    {
        $arbitraryBytePath = "/site/.DS_Store/child-\xFF";
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
            ['kind' => 'exact', 'path' => '/site/.git/current-link'],
            ['kind' => 'exact', 'path' => $arbitraryBytePath],
            ['kind' => 'exact', 'path' => '/site/ordinary-link'],
            ['kind' => 'exact', 'path' => '/alias-a/.cache/shared-link'],
            ['kind' => 'exact', 'path' => '/excluded/.git/link'],
        ]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/old/node_modules/prior-link'],
            ['kind' => 'exact', 'path' => '/alias-b/.cache/shared-link'],
            ['kind' => 'exact', 'path' => '/protected/.git/link'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/protected/.git/link'],
        ]);
        $remoteScope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId],
            ['/excluded']
        );
        $mapper = new \RemoteToLocalPathMapper(
            $this->filesystemRoot,
            ['/site'],
            [
                '/alias-a' => $this->filesystemRoot . '/shared',
                '/alias-b' => $this->filesystemRoot . '/shared',
            ]
        );

        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $mapper,
            $this->temporaryDirectory . '/work'
        );
        $this->finish($processor);
        $config = $processor->get_local_change_scope_config();
        $localScope = \FileSyncChangeScope::from_config($config);

        $this->assertSame(
            [
                "old/node_modules/prior-link",
                "shared/.cache/shared-link",
                "site/.DS_Store/child-\xFF",
                'site/.git/current-link',
            ],
            $this->readSelectedPaths(
                $localScope->get_selected_default_skipped_index_paths_file()
            )
        );
        $this->assertFalse(
            $localScope->index_entry_may_change(
                'protected/.git/link',
                'link'
            )
        );
        $this->assertSame(
            $mapper->get_config(),
            $config['remote_to_local_path_mapper_config']
        );
        $this->assertSame(
            base64_encode(
                realpath($this->temporaryDirectory . '/work')
                    . '/selected-default-skipped-index-paths.jsonl'
            ),
            $config['selected_default_skipped_index_paths_file_b64']
        );
        try {
            $localScope->initial_selected_ownership_atom_cursor();
            $this->fail('Local-coordinate scope exposed remote ownership atoms.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString(
                'remote_absolute',
                $exception->getMessage()
            );
        }

        $localScope->close();
        $completedCursor = $processor->get_cursor();
        $processor->close();
        $processor = \FileSyncChangeScopeMappingProcessor::resume(
            $remoteScope,
            $mapper,
            $this->temporaryDirectory . '/work',
            $completedCursor
        );
        $this->assertSame($config, $processor->get_local_change_scope_config());
        $processor->close();
        $remoteScope->close();
    }

    public function testSelectedAtomCursorIsBoundToItsOwnershipDirectory(): void
    {
        $snapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/site/.git/link'],
        ]);
        $firstScope = $this->scope($snapshotId);
        $cursor = $firstScope->initial_selected_ownership_atom_cursor();

        $otherOwnershipDirectory = $this->temporaryDirectory
            . '/other-ownership';
        mkdir($otherOwnershipDirectory . '/snapshots', 0777, true);
        foreach (['paths.jsonl', 'lookup'] as $suffix) {
            copy(
                $this->ownershipDirectory . '/snapshots/'
                    . $snapshotId . '.' . $suffix,
                $otherOwnershipDirectory . '/snapshots/'
                    . $snapshotId . '.' . $suffix
            );
        }
        $otherScope = \FileSyncChangeScope::from_config([
            'index_path_coordinates' => 'remote_absolute',
            'ownership_directory_b64' => base64_encode(
                $otherOwnershipDirectory
            ),
            'current_snapshot_id' => $snapshotId,
            'prior_snapshot_ids' => [],
            'protected_snapshot_ids' => [],
            'excluded_remote_absolute_path_roots_b64' => [],
            'include_caches' => false,
        ]);

        try {
            $otherScope->read_next_selected_ownership_atom($cursor);
            $this->fail('Ownership cursor resumed against another artifact directory.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'does not match',
                $exception->getMessage()
            );
        }

        $otherScope->close();
        $firstScope->close();
    }

    public function testIncludeCachesPublishesAnEmptySidecarWithoutReadingAtoms(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
            ['kind' => 'exact', 'path' => '/site/.git/link'],
        ]);
        $remoteScope = $this->scope(
            $currentSnapshotId,
            [],
            [],
            [],
            true
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            new \RemoteToLocalPathMapper(
                $this->filesystemRoot,
                ['/site'],
                ['/site' => $this->filesystemRoot . '/.git']
            ),
            $this->temporaryDirectory . '/include-caches-work'
        );

        $this->finish($processor);
        $scope = \FileSyncChangeScope::from_config(
            $processor->get_local_change_scope_config()
        );
        $this->assertSame(
            '',
            file_get_contents(
                $scope->get_selected_default_skipped_index_paths_file()
            )
        );

        $scope->close();
        $processor->close();
        $remoteScope->close();
    }

    public function testResumeTruncatesOutputAndReplaysOuterSortBoundaries(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/site/.git/z-link'],
            ['kind' => 'exact', 'path' => '/site/.git/a-link'],
        ]);
        $remoteScope = $this->scope($currentSnapshotId);
        $mapper = new \RemoteToLocalPathMapper(
            $this->filesystemRoot,
            ['/site']
        );
        $workDirectory = $this->temporaryDirectory . '/resume-work';
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $mapper,
            $workDirectory
        );

        while ($processor->get_cursor()['paths_byte_offset'] === 0) {
            $this->assertTrue($processor->next_step());
        }
        $durableCursor = $processor->get_cursor();
        $this->assertTrue($processor->next_step());
        $processor->close();
        file_put_contents(
            $workDirectory
                . '/selected-default-skipped-index-paths.unsorted.jsonl',
            "corrupt-tail\n",
            FILE_APPEND
        );
        $processor = \FileSyncChangeScopeMappingProcessor::resume(
            $remoteScope,
            $mapper,
            $workDirectory,
            $durableCursor
        );
        while ($processor->get_cursor()['phase'] === 'scanning_atoms') {
            $this->assertTrue($processor->next_step());
        }

        $startingSortCursor = $processor->get_cursor();
        $this->assertSame('starting_sort', $startingSortCursor['phase']);
        $this->assertTrue($processor->next_step());
        $processor->close();
        $processor = \FileSyncChangeScopeMappingProcessor::resume(
            $remoteScope,
            $mapper,
            $workDirectory,
            $startingSortCursor
        );

        $replayedPublish = false;
        $replayedCompletion = false;
        while ($processor->get_cursor()['phase'] !== 'complete') {
            $beforeStep = $processor->get_cursor();
            $processor->next_step();
            if (
                !$replayedPublish
                && $beforeStep['phase'] === 'sorting_paths'
                && $beforeStep['sort_cursor']['phase'] === 'publishing_output'
            ) {
                $processor->close();
                $processor = \FileSyncChangeScopeMappingProcessor::resume(
                    $remoteScope,
                    $mapper,
                    $workDirectory,
                    $beforeStep
                );
                $replayedPublish = true;
            } elseif (
                !$replayedCompletion
                && $beforeStep['phase'] === 'sorting_paths'
                && $beforeStep['sort_cursor']['phase'] === 'cleaning_work_files'
                && $beforeStep['sort_cursor']['next_cleanup_slot'] === 2
            ) {
                $processor->close();
                $processor = \FileSyncChangeScopeMappingProcessor::resume(
                    $remoteScope,
                    $mapper,
                    $workDirectory,
                    $beforeStep
                );
                $replayedCompletion = true;
            }
        }
        $this->assertTrue($replayedPublish);
        $this->assertTrue($replayedCompletion);
        $this->assertSame(
            ['site/.git/a-link', 'site/.git/z-link'],
            $this->readSelectedPaths(
                base64_decode(
                    $processor->get_local_change_scope_config()[
                        'selected_default_skipped_index_paths_file_b64'
                    ],
                    true
                )
            )
        );

        $processor->close();
        $remoteScope->close();
    }

    public function testRejectsCursorStateThatCouldSkipSelectedWork(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/site/.git/link'],
        ]);
        $remoteScope = $this->scope($currentSnapshotId);
        $mapper = new \RemoteToLocalPathMapper(
            $this->filesystemRoot,
            ['/site']
        );
        $workDirectory = $this->temporaryDirectory . '/strict-cursor-work';
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $mapper,
            $workDirectory
        );
        $this->finish($processor);
        $completeCursor = $processor->get_cursor();
        $processor->close();

        $invalidNestedCursor = $completeCursor;
        $invalidNestedCursor['selected_atom_cursor'] = [];
        $this->assertMappingResumeRejected(
            $remoteScope,
            $mapper,
            $workDirectory,
            $invalidNestedCursor
        );

        $pendingRootCursor = $completeCursor;
        $pendingRootCursor['pending_root_path_b64'] = base64_encode('/site');
        $this->assertMappingResumeRejected(
            $remoteScope,
            $mapper,
            $workDirectory,
            $pendingRootCursor
        );

        $wrongSourceOffsetCursor = $completeCursor;
        ++$wrongSourceOffsetCursor['paths_byte_offset'];
        $this->assertMappingResumeRejected(
            $remoteScope,
            $mapper,
            $workDirectory,
            $wrongSourceOffsetCursor
        );

        $startingSortWorkDirectory = $this->temporaryDirectory
            . '/strict-starting-sort-work';
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $mapper,
            $startingSortWorkDirectory
        );
        while ($processor->get_cursor()['phase'] === 'scanning_atoms') {
            $this->assertTrue($processor->next_step());
        }
        $startingSortCursor = $processor->get_cursor();
        $processor->close();
        $startingSortCursor['selected_atom_cursor'] =
            $remoteScope->initial_selected_ownership_atom_cursor();
        $this->assertMappingResumeRejected(
            $remoteScope,
            $mapper,
            $startingSortWorkDirectory,
            $startingSortCursor
        );

        $remoteScope->close();
    }

    /**
     * @dataProvider mappingContextCases
     * @param list<string> $remoteRoots
     * @param array<string,string> $resolvedMappings Local values are suffixes below the test filesystem root.
     */
    public function testRejectsOnlyMappingsWithStricterLocalSkipContext(
        array $remoteRoots,
        array $resolvedMappings,
        ?string $followedRootSuffix,
        bool $expectRejection
    ): void {
        $atoms = array_map(
            static function (string $remoteRoot): array {
                return ['kind' => 'root', 'path' => $remoteRoot];
            },
            $remoteRoots
        );
        $currentSnapshotId = $this->publishSnapshot($atoms);
        $remoteScope = $this->scope($currentSnapshotId);
        $localMappings = [];
        foreach ($resolvedMappings as $remotePrefix => $localSuffix) {
            $localMappings[$remotePrefix] = $this->filesystemRoot
                . ( $localSuffix === '' ? '' : '/' . $localSuffix );
        }
        $mapper = new \RemoteToLocalPathMapper(
            $this->filesystemRoot,
            ['/site'],
            $localMappings,
            $followedRootSuffix === null
                ? null
                : $this->filesystemRoot . '/' . $followedRootSuffix
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $mapper,
            $this->temporaryDirectory . '/mapping-' . $this->nextSnapshotNumber
        );

        try {
            $this->finish($processor);
            $this->assertFalse(
                $expectRejection,
                'Expected stricter mapped default-skip context to be rejected.'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertTrue(
                $expectRejection,
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'stricter',
                $exception->getMessage()
            );
        } finally {
            $processor->close();
            $remoteScope->close();
        }
    }

    /** @return array<string,array{0:list<string>,1:array<string,string>,2:string|null,3:bool}> */
    public static function mappingContextCases(): array
    {
        return [
            'ordinary root into git' => [
                ['/site'], ['/site' => '.git'], null, true,
            ],
            'wp-content root into ordinary' => [
                ['/site/wp-content'], ['/site/wp-content' => 'content'], null, false,
            ],
            'wp-content root into git' => [
                ['/site/wp-content'], ['/site/wp-content' => '.git'], null, true,
            ],
            'ordinary plugins region into local wp-content' => [
                ['/site/wp-content/plugins'],
                ['/site/wp-content/plugins' => 'wp-content'],
                null,
                true,
            ],
            'reachable nested remap into git' => [
                ['/site'], ['/site/nested' => '.git'], null, true,
            ],
            'unreachable scratch remap is not inherited' => [
                ['/site'], ['/site/foo~' => '.git'], null, false,
            ],
            'separately scheduled scratch root is checked' => [
                ['/site', '/site/foo~'], ['/site/foo~' => '.git'], null, true,
            ],
            'followed placement into skipped component' => [
                ['/outside'], [], '.git', true,
            ],
            'near-miss names remain ordinary' => [
                ["/site/\xFF.gitmodules"],
                ["/site/\xFF.gitmodules" => 'cache-control'],
                null,
                false,
            ],
        ];
    }

    public function testFullyExcludedUnsafeRegionNeedsNoLocalTraversal(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
        ]);
        $remoteScope = $this->scope(
            $currentSnapshotId,
            [],
            [],
            ['/site']
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            new \RemoteToLocalPathMapper(
                $this->filesystemRoot,
                ['/site'],
                ['/site' => $this->filesystemRoot . '/.git']
            ),
            $this->temporaryDirectory . '/excluded-region-work'
        );

        $this->finish($processor);
        $this->assertSame('complete', $processor->get_cursor()['phase']);
        $processor->close();
        $remoteScope->close();
    }

    public function testProtectedPriorRegionNeedsNoLocalTraversal(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old'],
        ]);
        $remoteScope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId]
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            new \RemoteToLocalPathMapper(
                $this->filesystemRoot,
                ['/old'],
                ['/old' => $this->filesystemRoot . '/.git']
            ),
            $this->temporaryDirectory . '/protected-region-work'
        );

        $this->finish($processor);
        $this->assertSame('complete', $processor->get_cursor()['phase']);
        $processor->close();
        $remoteScope->close();
    }

    public function testNestedProtectedHoleDoesNotHideAllowedUnsafeRemainder(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old/protected'],
        ]);
        $remoteScope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId]
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            new \RemoteToLocalPathMapper(
                $this->filesystemRoot,
                ['/old'],
                ['/old' => $this->filesystemRoot . '/.git']
            ),
            $this->temporaryDirectory . '/protected-hole-work'
        );

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->finish($processor);
        } finally {
            $processor->close();
            $remoteScope->close();
        }
    }

    public function testCurrentNestedRootIsCheckedInsideProtectedPriorRegion(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old/current'],
        ]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/old'],
        ]);
        $remoteScope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId]
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            new \RemoteToLocalPathMapper(
                $this->filesystemRoot,
                ['/old'],
                ['/old/current' => $this->filesystemRoot . '/.git']
            ),
            $this->temporaryDirectory . '/current-nested-work'
        );

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->finish($processor);
        } finally {
            $processor->close();
            $remoteScope->close();
        }
    }

    public function testScheduledScratchRootIsSafeOnlyWhenMappedToFilesystemRoot(): void
    {
        $scratchFilesystemRoot = $this->temporaryDirectory . '/local~';
        mkdir($scratchFilesystemRoot);
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/foo~'],
        ]);
        $remoteScope = $this->scope($currentSnapshotId);
        $mapper = new \RemoteToLocalPathMapper(
            $scratchFilesystemRoot,
            ['/foo~'],
            ['/foo~' => $scratchFilesystemRoot]
        );
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $mapper,
            $this->temporaryDirectory . '/scratch-root-work'
        );

        $this->finish($processor);
        $this->assertSame('complete', $processor->get_cursor()['phase']);
        $this->assertSame(
            '',
            file_get_contents(base64_decode(
                $processor->get_local_change_scope_config()[
                    'selected_default_skipped_index_paths_file_b64'
                ],
                true
            ))
        );
        $processor->close();
        $remoteScope->close();
    }

    /**
     * @param list<string> $priorSnapshotIds
     * @param list<string> $protectedSnapshotIds
     * @param list<string> $excludedRemoteAbsolutePathRoots
     */
    private function scope(
        string $currentSnapshotId,
        array $priorSnapshotIds = [],
        array $protectedSnapshotIds = [],
        array $excludedRemoteAbsolutePathRoots = [],
        bool $includeCaches = false
    ): \FileSyncChangeScope {
        return \FileSyncChangeScope::from_config([
            'index_path_coordinates' => 'remote_absolute',
            'ownership_directory_b64' => base64_encode(
                $this->ownershipDirectory
            ),
            'current_snapshot_id' => $currentSnapshotId,
            'prior_snapshot_ids' => $priorSnapshotIds,
            'protected_snapshot_ids' => $protectedSnapshotIds,
            'excluded_remote_absolute_path_roots_b64' => array_map(
                'base64_encode',
                $excludedRemoteAbsolutePathRoots
            ),
            'include_caches' => $includeCaches,
        ]);
    }

    /** @param list<array{kind:'root'|'exact'|'ancestor',path:string}> $atoms */
    private function publishSnapshot(array $atoms): string
    {
        usort(
            $atoms,
            static function (array $left, array $right): int {
                return strcmp(
                    $left['path'] . "\0" . $left['kind'],
                    $right['path'] . "\0" . $right['kind']
                );
            }
        );
        ++$this->nextSnapshotNumber;
        $snapshotId = str_pad(
            dechex($this->nextSnapshotNumber),
            64,
            '0',
            STR_PAD_LEFT
        );
        $pathsFile = $this->ownershipDirectory . '/snapshots/'
            . $snapshotId . '.paths.jsonl';
        $pathsHandle = fopen($pathsFile, 'wb');
        $lookupRows = [];
        foreach ($atoms as $atom) {
            $pathsByteOffset = ftell($pathsHandle);
            fwrite($pathsHandle, json_encode([
                'kind' => $atom['kind'],
                'path_b64' => base64_encode($atom['path']),
            ], JSON_UNESCAPED_SLASHES) . "\n");
            $lookupRows[] = hash(
                'sha256',
                $atom['kind'] . "\0" . $atom['path']
            ) . ' ' . sprintf('%016x', $pathsByteOffset) . "\n";
        }
        fclose($pathsHandle);
        sort($lookupRows, SORT_STRING);
        file_put_contents(
            $this->ownershipDirectory . '/snapshots/'
                . $snapshotId . '.lookup',
            implode('', $lookupRows)
        );
        return $snapshotId;
    }

    private function finish(\FileSyncChangeScopeMappingProcessor $processor): void
    {
        $steps = 0;
        while ($processor->next_step()) {
            ++$steps;
            if ($steps > 10000) {
                $this->fail('File-sync change-scope mapping did not complete.');
            }
        }
    }

    /** @param array<string,mixed> $cursor */
    private function assertMappingResumeRejected(
        \FileSyncChangeScope $remoteScope,
        \RemoteToLocalPathMapper $mapper,
        string $workDirectory,
        array $cursor
    ): void {
        try {
            $resumed = \FileSyncChangeScopeMappingProcessor::resume(
                $remoteScope,
                $mapper,
                $workDirectory,
                $cursor
            );
            $resumed->close();
            $this->fail('Invalid mapping cursor resumed successfully.');
        } catch (\InvalidArgumentException | \UnexpectedValueException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    /** @return list<string> */
    private function readSelectedPaths(string $pathsFile): array
    {
        $paths = [];
        $handle = fopen($pathsFile, 'rb');
        while (true) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $paths[] = base64_decode($row['path_b64'], true);
        }
        fclose($handle);
        return $paths;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
