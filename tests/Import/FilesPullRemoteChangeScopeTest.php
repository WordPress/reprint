<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Exercises ownership-scoped deletions through the production files-pull diff. */
final class FilesPullRemoteChangeScopeTest extends TestCase {
    private const CURRENT_SELECTION =
        '1111111111111111111111111111111111111111111111111111111111111111';
    private const PROTECTED_SELECTION =
        '2222222222222222222222222222222222222222222222222222222222222222';

    private string $temporaryDirectory;
    private string $stateDirectory;
    private string $pullStateDirectory;
    private string $filesystemRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir()
            . '/files-pull-remote-scope-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->temporaryDirectory . '/state';
        $this->pullStateDirectory = $this->stateDirectory
            . '/remotes/' . md5('http://fake.url') . '/pull';
        $this->filesystemRoot = $this->temporaryDirectory . '/files';
        mkdir($this->pullStateDirectory, 0700, true);
        mkdir($this->filesystemRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testCurrentOwnershipWinsWhileProtectionBlocksPriorOwnership(): void
    {
        $currentPath = '/site/current/remove.txt';
        $priorPath = '/site/old/remove.txt';
        $protectedPath = '/site/shared/keep.txt';
        $this->writeIndexes([
            [$currentPath, 'file'],
            [$priorPath, 'file'],
            [$protectedPath, 'file'],
        ]);
        foreach ([$currentPath, $priorPath, $protectedPath] as $path) {
            $this->seedLocalFile($path);
        }

        $client = $this->clientAtDiffStage(
            [['kind' => 'root', 'path' => '/site/current']],
            [['kind' => 'root', 'path' => '/site']],
            [[
                ['kind' => 'root', 'path' => '/site/current'],
                ['kind' => 'root', 'path' => '/site/shared'],
            ]]
        );
        $this->runDiffAndApplyJournal($client);

        $this->assertFileDoesNotExist($this->localPath($currentPath));
        $this->assertFileDoesNotExist($this->localPath($priorPath));
        $this->assertFileExists($this->localPath($protectedPath));
        $this->assertSame([$protectedPath], $this->retainedRemoteIndexPaths());
    }

    public function testExclusionsAndPersistedCachesSettingKeepStaleDescendants(): void
    {
        $cachePath = '/site/.git/config';
        $excludedPath = '/site/excluded/remove.txt';
        $ownedPath = '/site/remove.txt';
        $this->writeIndexes([
            [$cachePath, 'file'],
            [$excludedPath, 'file'],
            [$ownedPath, 'file'],
        ]);
        foreach ([$cachePath, $excludedPath, $ownedPath] as $path) {
            $this->seedLocalFile($path);
        }

        $client = $this->clientAtDiffStage(
            [['kind' => 'root', 'path' => '/site']],
            [],
            [],
            ['/site/excluded'],
            false,
            true
        );
        $this->runDiffAndApplyJournal($client);

        $this->assertFileExists($this->localPath($cachePath));
        $this->assertFileExists($this->localPath($excludedPath));
        $this->assertFileDoesNotExist($this->localPath($ownedPath));
        $this->assertSame(
            [$cachePath, $excludedPath],
            $this->retainedRemoteIndexPaths()
        );
    }

    public function testExactIntermediateLinkDoesNotAuthorizeItsAncestor(): void
    {
        $linkPath = '/outside/intermediate';
        $this->writeIndexes([[$linkPath, 'link']]);
        $localLinkPath = $this->localPath($linkPath);
        mkdir(dirname($localLinkPath), 0700, true);
        symlink('/remote/link/target', $localLinkPath);
        $siblingPath = '/outside/keep.txt';
        $this->seedLocalFile($siblingPath);

        $client = $this->clientAtDiffStage([
            ['kind' => 'exact', 'path' => $linkPath],
        ], [], [], [], true);
        $this->runDiffAndApplyJournal($client);

        $this->assertFileDoesNotExist($localLinkPath);
        $this->assertFileExists($this->localPath($siblingPath));
        $this->assertSame([], $this->retainedRemoteIndexPaths());
    }

    public function testDefaultSkippedExactIntermediateLinkIsFetched(): void
    {
        $linkPath = '/site/.git';
        $this->writeIndexes([], [[$linkPath, 'link']]);

        $client = $this->clientAtDiffStage([
            ['kind' => 'exact', 'path' => $linkPath],
        ]);
        $this->runDiffAndApplyJournal($client);

        $this->assertSame([$linkPath], $this->fetchListPaths());
    }

    public function testMirrorSchedulesAnUnchangedSelectedRemoteEntry(): void
    {
        $path = '/site/unchanged.txt';
        $this->writeIndexes([[$path, 'file']], [[$path, 'file']]);

        $client = $this->clientAtDiffStage(
            [['kind' => 'root', 'path' => '/site']],
            [],
            [],
            [],
            false,
            false,
            'mirror'
        );
        $this->runDiffAndApplyJournal($client);

        $this->assertSame([$path], $this->fetchListPaths());
    }

    public function testMirrorDoesNotFetchCurrentEntryThroughProtectedLocalAlias(): void
    {
        $path = '/current/file.txt';
        $canonicalFilesystemRoot = realpath($this->filesystemRoot);
        $this->assertIsString($canonicalFilesystemRoot);
        $this->writeIndexes([[$path, 'file']], [[$path, 'file']]);

        $client = $this->clientAtDiffStage(
            [['kind' => 'root', 'path' => '/current']],
            [],
            [[['kind' => 'root', 'path' => '/protected']]],
            [],
            false,
            false,
            'mirror',
            [
                '/current' => $canonicalFilesystemRoot . '/shared',
                '/protected' => $canonicalFilesystemRoot . '/shared',
            ],
            ['/current', '/protected']
        );
        $this->runDiffAndApplyJournal($client);

        $this->assertSame([], $this->fetchListPaths());
    }

    public function testMirrorDoesNotDeleteCurrentEntryThroughProtectedLocalAlias(): void
    {
        $path = '/current/file.txt';
        $canonicalFilesystemRoot = realpath($this->filesystemRoot);
        $this->assertIsString($canonicalFilesystemRoot);
        $this->writeIndexes([[$path, 'file']]);
        $localPath = $this->filesystemRoot . '/shared/file.txt';
        mkdir(dirname($localPath), 0700, true);
        file_put_contents($localPath, 'x');

        $client = $this->clientAtDiffStage(
            [['kind' => 'root', 'path' => '/current']],
            [],
            [[['kind' => 'root', 'path' => '/protected']]],
            [],
            false,
            false,
            'mirror',
            [
                '/current' => $canonicalFilesystemRoot . '/shared',
                '/protected' => $canonicalFilesystemRoot . '/shared',
            ],
            ['/current', '/protected']
        );
        $this->runDiffAndApplyJournal($client);

        $this->assertFileExists($localPath);
        $this->assertSame([], $this->retainedRemoteIndexPaths());
    }

    public function testMirrorRecreatesOnlyLocallyAuthorizedIntermediateAlias(): void
    {
        $canonicalFilesystemRoot = realpath($this->filesystemRoot);
        $this->assertIsString($canonicalFilesystemRoot);
        $this->writeIndexes([]);
        $client = $this->clientAtDiffStage(
            [
                ['kind' => 'exact', 'path' => '/current/blocked'],
                ['kind' => 'exact', 'path' => '/allowed/link'],
            ],
            [],
            [[['kind' => 'root', 'path' => '/protected']]],
            [],
            false,
            false,
            'mirror',
            [
                '/current' => $canonicalFilesystemRoot . '/shared',
                '/protected' => $canonicalFilesystemRoot . '/shared',
                '/allowed' => $canonicalFilesystemRoot . '/allowed',
            ],
            ['/current', '/protected', '/allowed']
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            implode("\n", [
                json_encode([
                    'path' => base64_encode('/allowed/link'),
                    'ctime' => 1,
                    'size' => 6,
                    'type' => 'link',
                    'target' => base64_encode('target'),
                    'intermediate' => true,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode([
                    'path' => base64_encode('/current/blocked'),
                    'ctime' => 1,
                    'size' => 6,
                    'type' => 'link',
                    'target' => base64_encode('target'),
                    'intermediate' => true,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]) . "\n"
        );

        $reflection = new \ReflectionClass(\ImportClient::class);
        $scope = $reflection->getMethod(
            'open_files_pull_local_change_scope'
        )->invoke($client);
        try {
            $reflection->getMethod('recreate_intermediate_symlinks')->invoke(
                $client,
                $scope
            );
        } finally {
            $scope->close();
        }

        $this->assertTrue(is_link($this->filesystemRoot . '/allowed/link'));
        $this->assertFalse(is_link($this->filesystemRoot . '/shared/blocked'));
    }

    public function testCatchUpDoesNotScheduleAnUnchangedRemoteEntry(): void
    {
        $path = '/site/unchanged.txt';
        $this->writeIndexes([[$path, 'file']], [[$path, 'file']]);

        $client = $this->clientAtDiffStage([
            ['kind' => 'root', 'path' => '/site'],
        ]);
        $this->runDiffAndApplyJournal($client);

        $this->assertSame([], $this->fetchListPaths());
    }

    public function testImpliedDirectoryInvalidatesOnlyItsStaleExplicitRow(): void
    {
        $directoryPath = '/site/sparse';
        $descendantPath = $directoryPath . '/remote.txt';
        $this->writeIndexes(
            [[$directoryPath, 'dir']],
            [[$descendantPath, 'file']]
        );
        $localMarkerPath = $directoryPath . '/local-marker.txt';
        $this->seedLocalFile($localMarkerPath);

        $client = $this->clientAtDiffStage([
            ['kind' => 'root', 'path' => $directoryPath],
        ]);
        $this->runDiffAndApplyJournal($client);

        $this->assertFileExists($this->localPath($localMarkerPath));
        $this->assertSame([], $this->retainedRemoteIndexPaths());
        $this->assertSame(
            [$descendantPath],
            $this->indexFilePaths('remote-index.next.jsonl')
        );
        $this->assertSame([$descendantPath], $this->fetchListPaths());
    }

    public function testMissingExplicitDirectoryKeepsItsLocalDescendant(): void
    {
        $directoryPath = '/site/old';
        $localDescendantPath = $directoryPath . '/local.txt';
        $this->writeIndexes([[$directoryPath, 'dir']]);
        $this->seedLocalFile($localDescendantPath);

        $client = $this->clientAtDiffStage(
            [['kind' => 'root', 'path' => '/site']],
            [],
            [],
            [$localDescendantPath],
            false
        );
        $this->runDiffAndApplyJournal($client);

        $this->assertFileExists($this->localPath($localDescendantPath));
        $this->assertSame([], $this->retainedRemoteIndexPaths());
    }

    /**
     * @param list<array{0:string,1:'file'|'link'|'dir'}> $retainedEntries
     * @param list<array{0:string,1:'file'|'link'|'dir'}> $nextEntries
     */
    private function writeIndexes(
        array $retainedEntries,
        array $nextEntries = []
    ): void {
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            $this->indexRows($retainedEntries)
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            $this->indexRows($nextEntries)
        );
    }

    /**
     * @param list<array{0:string,1:'file'|'link'|'dir'}> $entries
     */
    private function indexRows(array $entries): string
    {
        usort(
            $entries,
            static function (array $left, array $right): int {
                return strcmp($left[0], $right[0]);
            }
        );
        $rows = '';
        foreach ($entries as [$path, $type]) {
            $rows .= json_encode([
                'path' => base64_encode($path),
                'ctime' => 1,
                'size' => $type === 'file' ? 1 : 0,
                'type' => $type,
            ], JSON_UNESCAPED_SLASHES) . "\n";
        }
        return $rows;
    }

    /**
     * @param list<array{kind:'root'|'exact',path:string}> $currentAtoms
     * @param list<array{kind:'root'|'exact',path:string}> $priorAtoms
     * @param list<list<array{kind:'root'|'exact',path:string}>> $protectedAtomGroups
     * @param list<string> $excludedRemoteAbsolutePathRoots
     * @param array<string,string> $resolvedPathMappings
     * @param list<string> $exportDirectories
     */
    private function clientAtDiffStage(
        array $currentAtoms,
        array $priorAtoms = [],
        array $protectedAtomGroups = [],
        array $excludedRemoteAbsolutePathRoots = [],
        bool $persistedIncludeCaches = false,
        bool $invocationIncludeCaches = false,
        string $filesPullIntent = 'catch-up',
        array $resolvedPathMappings = [],
        array $exportDirectories = ['/']
    ): \ImportClient {
        $activeSnapshotId = reprint_test_write_ownership_snapshot(
            $this->pullStateDirectory,
            $currentAtoms
        );
        $committedSnapshotIds = [];
        if ($priorAtoms !== []) {
            $committedSnapshotIds[self::CURRENT_SELECTION] = [
                reprint_test_write_ownership_snapshot(
                    $this->pullStateDirectory,
                    $priorAtoms
                ),
            ];
        }
        foreach ($protectedAtomGroups as $protectedAtoms) {
            $committedSnapshotIds[self::PROTECTED_SELECTION][] =
                reprint_test_write_ownership_snapshot(
                    $this->pullStateDirectory,
                    $protectedAtoms
                );
        }
        foreach ($committedSnapshotIds as &$snapshotIds) {
            sort($snapshotIds, SORT_STRING);
        }
        unset($snapshotIds);

        $client = new \ImportClient(
            'http://fake.url',
            $this->stateDirectory,
            $this->filesystemRoot
        );
        \write_current_pull_state($client, [
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'in_progress',
                'current_stage' => 'diff',
            ],
            'preflight' => [
                'data' => [
                    'ok' => true,
                    'wp_detect' => [
                        'roots' => array_map(
                            static fn (string $path): array => [
                                'path' => $path,
                            ],
                            $exportDirectories
                        ),
                    ],
                ],
                'http_code' => 200,
            ],
            'remote_protocol_version' => PULL_PROTOCOL_VERSION,
            'follow_symlinks' => false,
            'include_caches' => $persistedIncludeCaches,
            'fs_root_nonempty_behavior' => $filesPullIntent === 'mirror'
                ? 'error'
                : 'preserve-local',
            'files_pull_intent' => $filesPullIntent,
            'files_pull_path_selection_fingerprint' =>
                self::CURRENT_SELECTION,
            'files_pull_ownership' => [
                'committed_snapshot_ids_by_selection_fingerprint' =>
                    $committedSnapshotIds,
                'active_snapshot_id' => $activeSnapshotId,
                'processor_cursor' => null,
                'snapshot_ids_pending_removal' => [],
            ],
        ]);

        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client)
        );
        $reflection->getProperty('is_tty')->setValue($client, false);
        $reflection->getProperty('fs_root_nonempty_behavior')->setValue(
            $client,
            $filesPullIntent === 'mirror' ? 'error' : 'preserve-local'
        );
        $reflection->getProperty('files_pull_intent')->setValue(
            $client,
            $filesPullIntent
        );
        $reflection->getProperty('include_caches')->setValue(
            $client,
            $invocationIncludeCaches
        );
        $reflection->getProperty('resolved_path_mappings')->setValue(
            $client,
            $resolvedPathMappings
        );
        $reflection->getProperty(
            'pull_excluded_files_with_path_prefixes'
        )->setValue($client, $excludedRemoteAbsolutePathRoots);
        if ($filesPullIntent === 'mirror') {
            $this->writeMirrorLocalChangeScope($client, $reflection);
        }
        return $client;
    }

    private function writeMirrorLocalChangeScope(
        \ImportClient $client,
        \ReflectionClass $reflection
    ): void {
        $remoteScope = $reflection
            ->getMethod('create_files_pull_remote_change_scope')
            ->invoke($client);
        $pathMapper = $reflection
            ->getMethod('create_files_pull_path_mapper')
            ->invoke($client);
        $processor = \FileSyncChangeScopeMappingProcessor::start(
            $remoteScope,
            $pathMapper,
            $this->pullStateDirectory . '/files-pull-mirror/local-scope'
        );
        try {
            $hasNextStep = true;
            while ($hasNextStep) {
                $hasNextStep = $processor->next_step();
            }
            $config = $processor->get_local_change_scope_config();
        } finally {
            $processor->close();
            $remoteScope->close();
        }
        $reflection->getMethod(
            'write_files_pull_local_change_scope_config'
        )->invoke($client, $config);
    }

    private function runDiffAndApplyJournal(\ImportClient $client): void
    {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $this->assertTrue(
            $reflection->getMethod(
                'compare_remote_indexes_and_build_fetch_list'
            )->invoke($client)
        );
        $reflection->getProperty('pull_index_journal')
            ->getValue($client)
            ->apply_pending_records();
    }

    private function seedLocalFile(string $remoteAbsolutePath): void
    {
        $localPath = $this->localPath($remoteAbsolutePath);
        if (!is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0700, true);
        }
        file_put_contents($localPath, 'x');
    }

    private function localPath(string $remoteAbsolutePath): string
    {
        return $this->filesystemRoot . $remoteAbsolutePath;
    }

    /** @return list<string> */
    private function retainedRemoteIndexPaths(): array
    {
        return $this->indexFilePaths('remote-index.jsonl');
    }

    /** @return list<string> */
    private function fetchListPaths(): array
    {
        return $this->indexFilePaths('fetch-list.jsonl');
    }

    /** @return list<string> */
    private function indexFilePaths(string $fileName): array
    {
        $rows = file(
            $this->pullStateDirectory . '/' . $fileName,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [];
        return array_map(
            static function (string $row): string {
                $entry = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
                $path = base64_decode($entry['path'], true);
                if (!is_string($path)) {
                    throw new \RuntimeException('Invalid remote index fixture path.');
                }
                return $path;
            },
            $rows
        );
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
