<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Test namespace follows the suite convention.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-sync-change-scope.php';

class FileSyncChangeScopeTest extends TestCase {
    private string $temporaryDirectory;
    private string $ownershipDirectory;
    private int $nextSnapshotNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir()
            . '/file-sync-change-scope-' . uniqid('', true);
        $this->ownershipDirectory = $this->temporaryDirectory
            . "/ownership-\nname";
        mkdir($this->ownershipDirectory . '/snapshots', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testCurrentRootAndExactOwnershipUseTheirOwnDimensions(): void
    {
        $arbitraryByteLink = "/outside/linked-\xFF\npath";
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
            ['kind' => 'exact', 'path' => $arbitraryByteLink],
        ]);
        $scope = $this->scope($currentSnapshotId);

        $this->assertFalse($scope->index_entry_may_change('/site', 'dir'));
        $this->assertTrue($scope->index_entry_may_change('/site/file.php', 'file'));
        $this->assertTrue($scope->index_entry_may_change($arbitraryByteLink, 'link'));
        $this->assertFalse($scope->index_entry_may_change($arbitraryByteLink, 'file'));
        $this->assertFalse($scope->index_entry_may_change('/outside/file.php', 'file'));

        $scope->close();
    }

    public function testCurrentOwnershipWinsAndPriorOwnershipLosesToProtection(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site/current'],
        ]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site/current'],
            ['kind' => 'root', 'path' => '/site/shared'],
        ]);
        $scope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId]
        );

        $this->assertTrue(
            $scope->index_entry_may_change('/site/current/file.php', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/shared/file.php', 'file')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/old/file.php', 'file')
        );

        $scope->close();
    }

    public function testDisappearedIntermediateLinkUsesPriorExactOwnership(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/outside/followed'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/outside/protected'],
        ]);
        $scope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId]
        );

        $this->assertTrue(
            $scope->index_entry_may_change('/outside/followed', 'link')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/outside/followed', 'file')
        );

        $scope->close();

        $protectedPriorSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/outside/followed'],
        ]);
        $scope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedPriorSnapshotId]
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/outside/followed', 'link')
        );
        $scope->close();
    }

    public function testExclusionsAndDefaultSkipsApplyBeforeCurrentOwnership(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
            ['kind' => 'exact', 'path' => '/site/private/link'],
        ]);
        $scope = $this->scope(
            $currentSnapshotId,
            [],
            [],
            ['/site/private'],
            false
        );

        $this->assertFalse(
            $scope->index_entry_may_change('/site/private/file.php', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/private/link', 'link')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/.git/config', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/wp-content/cache/a', 'file')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/cache/a', 'file')
        );
        $this->assertFalse($scope->includes_caches());
        $scope->close();

        $scope = $this->scope($currentSnapshotId, [], [], [], true);
        $this->assertTrue(
            $scope->index_entry_may_change('/site/.git/config', 'file')
        );
        $this->assertTrue($scope->includes_caches());
        $scope->close();
    }

    public function testShallowRootChecksEveryDefaultSkippedPrefix(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/'],
        ]);
        $scope = $this->scope($currentSnapshotId);

        $this->assertFalse(
            $scope->index_entry_may_change('/site/.DS_Store/child', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/wp-content/cache/child', 'file')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/cache/child', 'file')
        );

        $scope->close();
    }

    public function testDeepestExplicitSkippedNameRootSetsTheTraversalBoundary(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $priorSnapshotIds = [
            $this->publishSnapshot([
                ['kind' => 'root', 'path' => '/site'],
            ]),
            $this->publishSnapshot([
                ['kind' => 'root', 'path' => '/site/.DS_Store'],
                ['kind' => 'root', 'path' => '/site/.git'],
            ]),
        ];
        sort($priorSnapshotIds, SORT_STRING);
        $scope = $this->scope($currentSnapshotId, $priorSnapshotIds);

        $this->assertTrue(
            $scope->index_entry_may_change('/site/.DS_Store/child', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/.git/child', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/.DS_Store', 'dir')
        );

        $scope->close();
    }

    public function testNestedSkippedDirectoryBelowAnExplicitRootIsBlocked(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site/.DS_Store'],
        ]);
        $scope = $this->scope($currentSnapshotId);

        $this->assertTrue(
            $scope->index_entry_may_change('/site/.DS_Store/content/file', 'file')
        );
        $this->assertFalse(
            $scope->index_entry_may_change(
                '/site/.DS_Store/content/.DS_Store/child',
                'file'
            )
        );

        $scope->close();
    }

    public function testExactLinkOwnershipConfirmsDefaultSkippedNames(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => '/site/.git'],
            ['kind' => 'exact', 'path' => '/site/.git/link'],
            ['kind' => 'exact', 'path' => '/site/.git/node_modules'],
            ['kind' => 'exact', 'path' => '/site/.DS_Store'],
            ['kind' => 'exact', 'path' => '/site/.DS_Store/link'],
            ['kind' => 'exact', 'path' => '/site/wp-content/cache'],
        ]);
        $scope = $this->scope($currentSnapshotId);

        $this->assertTrue(
            $scope->index_entry_may_change('/site/.git/link', 'link')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/.DS_Store/link', 'link')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/.git', 'link')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/.git/node_modules', 'link')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/.DS_Store', 'link')
        );
        $this->assertTrue(
            $scope->index_entry_may_change('/site/wp-content/cache', 'link')
        );
        $this->assertFalse(
            $scope->index_entry_may_change('/site/.git/link', 'file')
        );

        $scope->close();
    }

    public function testSubtreeRequiresCompleteCurrentOrUnprotectedPriorOwnership(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/current'],
        ]);
        $priorSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/'],
            ['kind' => 'root', 'path' => '/prior'],
        ]);
        $protectedSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/current/overlap'],
            ['kind' => 'root', 'path' => '/prior/blocked/root'],
            ['kind' => 'exact', 'path' => '/prior/contains/link'],
            ['kind' => 'ancestor', 'path' => '/prior'],
            ['kind' => 'ancestor', 'path' => '/prior/blocked'],
            ['kind' => 'ancestor', 'path' => '/prior/contains'],
        ]);
        $scope = $this->scope(
            $currentSnapshotId,
            [$priorSnapshotId],
            [$protectedSnapshotId],
            [],
            true
        );

        $this->assertTrue($scope->subtree_may_change('/current/overlap'));
        $this->assertFalse($scope->subtree_may_change('/current'));
        $this->assertTrue($scope->subtree_may_change('/prior/open'));
        $this->assertFalse($scope->subtree_may_change('/prior/blocked'));
        $this->assertFalse($scope->subtree_may_change('/prior/contains'));

        $scope->close();
    }

    public function testSubtreeRejectsHiddenDefaultSkipsAndExcludedDescendants(): void
    {
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'root', 'path' => '/site'],
        ]);
        $scope = $this->scope($currentSnapshotId, [], [], [], false);
        $this->assertFalse($scope->subtree_may_change('/site/old'));
        $scope->close();

        $scope = $this->scope(
            $currentSnapshotId,
            [],
            [],
            ['/site/old/keep'],
            true
        );
        $this->assertFalse($scope->subtree_may_change('/site/old'));
        $this->assertTrue($scope->subtree_may_change('/site/other'));
        $scope->close();
    }

    public function testConfigRoundTripsCanonicalArbitraryBytes(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $config = $this->config(
            $currentSnapshotId,
            [],
            [],
            ["/excluded-\xFE"],
            true
        );
        $scope = \FileSyncChangeScope::from_config($config);

        $this->assertSame($config, $scope->get_config());
        $scope->close();
        $scope->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('after close()');
        $scope->index_entry_may_change('/path', 'file');
    }

    public function testStrictConfigRejectsUnknownFieldsAndUnsortedRawPaths(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $config = $this->config($currentSnapshotId);
        $config['unexpected'] = true;
        try {
            \FileSyncChangeScope::from_config($config);
            $this->fail('Unknown config field was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('must be exactly', $exception->getMessage());
        }

        $config = $this->config(
            $currentSnapshotId,
            [],
            [],
            ['/z', '/a']
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('byte-sorted');
        \FileSyncChangeScope::from_config($config);
    }

    public function testStrictConfigRejectsNoncanonicalBase64(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $config = $this->config($currentSnapshotId);
        $config['ownership_directory_b64'] .= '=';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical base64');
        \FileSyncChangeScope::from_config($config);
    }

    public function testStrictConfigRejectsOtherIndexPathCoordinates(): void
    {
        $currentSnapshotId = $this->publishSnapshot([]);
        $config = $this->config($currentSnapshotId);
        $config['index_path_coordinates'] = 'local_relative';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'index_path_coordinates must be remote_absolute'
        );
        \FileSyncChangeScope::from_config($config);
    }

    public function testLargeSnapshotLookupRemainsBounded(): void
    {
        $atoms = [];
        for ($index = 0; $index < 100000; ++$index) {
            $atoms[] = [
                'kind' => 'exact',
                'path' => sprintf('/links/%06d', $index),
            ];
        }
        $currentSnapshotId = $this->publishSnapshot($atoms);
        $configPath = $this->temporaryDirectory . '/bounded-config.json';
        file_put_contents(
            $configPath,
            json_encode($this->config($currentSnapshotId), JSON_THROW_ON_ERROR)
        );
        $scriptPath = $this->temporaryDirectory . '/bounded-lookup.php';
        file_put_contents($scriptPath, <<<'PHP'
<?php
require $argv[1];
require $argv[2];

$config = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
$scope = FileSyncChangeScope::from_config($config);
if (!$scope->index_entry_may_change('/links/099999', 'link')) {
    fwrite(STDERR, "Expected the final exact link to be owned.\n");
    exit(2);
}
if ($scope->index_entry_may_change('/links/missing', 'link')) {
    fwrite(STDERR, "Unexpected ownership for the missing link.\n");
    exit(3);
}
$scope->close();
PHP
        );
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'memory_limit=8M',
                $scriptPath,
                __DIR__ . '/../../vendor/autoload.php',
                __DIR__ . '/../../packages/reprint-client/src/lib/index/'
                    . 'class-file-sync-change-scope.php',
                $configPath,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            "Low-memory lookup failed.\nstdout: {$stdout}\nstderr: {$stderr}"
        );
    }

    public function testManySnapshotsRetainOnlyOneOpenArtifactPair(): void
    {
        $descriptorsBefore = glob('/dev/fd/*');
        if (!is_array($descriptorsBefore)) {
            $this->markTestSkipped('/dev/fd is unavailable for the handle bound check.');
        }
        $currentSnapshotId = $this->publishSnapshot([]);
        $protectedSnapshotIds = [];
        for ($index = 0; $index < 40; ++$index) {
            $protectedSnapshotIds[] = $this->publishSnapshot([
                [
                    'kind' => 'exact',
                    'path' => sprintf('/protected/%02d', $index),
                ],
            ]);
        }
        sort($protectedSnapshotIds, SORT_STRING);

        $scope = $this->scope(
            $currentSnapshotId,
            [],
            $protectedSnapshotIds
        );
        $descriptorsAfterOpen = glob('/dev/fd/*');
        $this->assertIsArray($descriptorsAfterOpen);
        $this->assertLessThanOrEqual(
            count($descriptorsBefore) + 2,
            count($descriptorsAfterOpen)
        );

        $this->assertFalse(
            $scope->index_entry_may_change('/protected/39', 'link')
        );
        $descriptorsAfterLookup = glob('/dev/fd/*');
        $this->assertIsArray($descriptorsAfterLookup);
        $this->assertLessThanOrEqual(
            count($descriptorsBefore) + 2,
            count($descriptorsAfterLookup)
        );
        $scope->close();
    }

    public function testCorruptOversizedPathRowIsRejectedAtTheReadBound(): void
    {
        $path = '/links/target';
        $currentSnapshotId = $this->publishSnapshot([
            ['kind' => 'exact', 'path' => $path],
        ]);
        file_put_contents(
            $this->ownershipDirectory . '/snapshots/'
                . $currentSnapshotId . '.paths.jsonl',
            str_repeat('x', 64 * 1024 + 1)
        );
        $scope = $this->scope($currentSnapshotId);

        try {
            $scope->index_entry_may_change($path, 'link');
            $this->fail('Oversized ownership path row was accepted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString(
                'exceeds the 65536-byte path-row limit',
                $exception->getMessage()
            );
        } finally {
            $scope->close();
        }
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
        return \FileSyncChangeScope::from_config($this->config(
            $currentSnapshotId,
            $priorSnapshotIds,
            $protectedSnapshotIds,
            $excludedRemoteAbsolutePathRoots,
            $includeCaches
        ));
    }

    /**
     * @param list<string> $priorSnapshotIds
     * @param list<string> $protectedSnapshotIds
     * @param list<string> $excludedRemoteAbsolutePathRoots
     */
    private function config(
        string $currentSnapshotId,
        array $priorSnapshotIds = [],
        array $protectedSnapshotIds = [],
        array $excludedRemoteAbsolutePathRoots = [],
        bool $includeCaches = false
    ): array {
        return [
            'index_path_coordinates' => 'remote_absolute',
            'ownership_directory_b64' => base64_encode($this->ownershipDirectory),
            'current_snapshot_id' => $currentSnapshotId,
            'prior_snapshot_ids' => $priorSnapshotIds,
            'protected_snapshot_ids' => $protectedSnapshotIds,
            'excluded_remote_absolute_path_roots_b64' => array_map(
                'base64_encode',
                $excludedRemoteAbsolutePathRoots
            ),
            'include_caches' => $includeCaches,
        ];
    }

    /**
     * @param list<array{kind:'root'|'exact'|'ancestor',path:string}> $atoms
     */
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
        $temporaryPrefix = $this->ownershipDirectory . '/snapshot-' . uniqid();
        $pathsPath = $temporaryPrefix . '.paths.jsonl';
        $lookupPath = $temporaryPrefix . '.lookup';
        $pathsHandle = fopen($pathsPath, 'wb');
        $lookupRows = [];
        foreach ($atoms as $atom) {
            $pathsByteOffset = ftell($pathsHandle);
            $line = json_encode([
                'kind' => $atom['kind'],
                'path_b64' => base64_encode($atom['path']),
            ], JSON_UNESCAPED_SLASHES) . "\n";
            fwrite($pathsHandle, $line);
            $lookupRows[] = hash(
                'sha256',
                $atom['kind'] . "\0" . $atom['path']
            ) . ' ' . sprintf('%016x', $pathsByteOffset) . "\n";
        }
        fclose($pathsHandle);
        sort($lookupRows, SORT_STRING);
        file_put_contents($lookupPath, implode('', $lookupRows));

        ++$this->nextSnapshotNumber;
        $snapshotId = str_pad(
            dechex($this->nextSnapshotNumber),
            64,
            '0',
            STR_PAD_LEFT
        );
        rename(
            $pathsPath,
            $this->ownershipDirectory . '/snapshots/'
                . $snapshotId . '.paths.jsonl'
        );
        rename(
            $lookupPath,
            $this->ownershipDirectory . '/snapshots/'
                . $snapshotId . '.lookup'
        );
        return $snapshotId;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
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
