<?php

declare(strict_types=1);

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test classes.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-remote-to-local-path-mapper.php';

final class RemoteToLocalPathMapperTest extends TestCase
{
    public function testMostSpecificRemotePrefixWins(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/srv/site'],
            [
                '/srv/site/wp-content' => '/local/content',
                '/srv/site/wp-content/plugins' => '/local/plugins',
            ]
        );

        $this->assertSame(
            '/local/plugins/hello/plugin.php',
            $mapper->map_path('/srv/site/wp-content/plugins/hello/plugin.php')
        );
    }

    public function testUnmatchedPathUsesIdentityPlacement(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/srv/site'],
            ['/srv/site/wp-content' => '/local/content']
        );

        $this->assertSame(
            '/local/srv/site/index.php',
            $mapper->map_path('/srv/site/index.php')
        );
        $this->assertSame(
            '/local/srv/site-old/index.php',
            $mapper->map_path('/srv/site-old/index.php')
        );
    }

    public function testOutOfScopeFollowedTargetUsesFollowedSymlinksRoot(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/srv/site'],
            [],
            '/local/followed'
        );

        $this->assertSame(
            '/local/srv/site/index.php',
            $mapper->map_path('/srv/site/index.php')
        );
        $this->assertSame(
            '/local/followed/mnt/shared/file.txt',
            $mapper->map_path('/mnt/shared/file.txt')
        );
    }

    public function testRemapWinsOverFollowedSymlinkPlacement(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/srv/site'],
            ['/mnt/shared' => '/local/shared'],
            '/local/followed'
        );

        $this->assertSame(
            '/local/shared/file.txt',
            $mapper->map_path('/mnt/shared/file.txt')
        );
    }

    public function testReturnsEveryVerifiedRemoteAliasForOverlappingRemapTargets(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/remote/site', '/remote/shared'],
            [
                '/remote/site' => '/local/content',
                '/remote/site/plugins' => '/local/plugins',
                '/remote/shared' => '/local/content/shared',
            ]
        );

        $this->assertSame(
            [
                '/content/shared/file.txt',
                '/remote/site/shared/file.txt',
                '/remote/shared/file.txt',
            ],
            $mapper->remote_paths_mapping_to('/local/content/shared/file.txt')
        );
        $this->assertSame(
            ['/plugins/hello.php', '/remote/site/plugins/hello.php'],
            $mapper->remote_paths_mapping_to('/local/plugins/hello.php')
        );
        $this->assertSame(
            ['/content/plugins/hello.php'],
            $mapper->remote_paths_mapping_to('/local/content/plugins/hello.php')
        );
    }

    public function testReturnsIdentityAndFollowedPlacementAliasesOnlyWhenTheyMapForward(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/followed/site'],
            [],
            '/local/followed'
        );

        $this->assertSame(
            ['/followed/site/file.txt', '/site/file.txt'],
            $mapper->remote_paths_mapping_to('/local/followed/site/file.txt')
        );
        $this->assertSame(
            ['/mnt/shared/file.txt'],
            $mapper->remote_paths_mapping_to('/local/followed/mnt/shared/file.txt')
        );
    }

    public function testExactPlacementRootsReturnVerifiedRootAliases(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/'],
            ['/mapped' => '/local']
        );
        $this->assertSame(
            ['/', '/mapped'],
            $mapper->remote_paths_mapping_to('/local')
        );
        $this->assertFalse(
            $mapper->remote_path_owns_mapped_local_subtree('/')
        );

        $followedMapper = new RemoteToLocalPathMapper(
            '/local',
            ['/site'],
            [],
            '/local/followed'
        );
        $this->assertSame(
            ['/'],
            $followedMapper->remote_paths_mapping_to('/local/followed')
        );
    }

    public function testRemotePathDoesNotOwnLocalSubtreeContainingAnotherRemapTarget(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/a', '/b'],
            [
                '/a' => '/local/shared',
                '/b' => '/local/shared/inner',
            ]
        );

        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/a'));
        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/b'));
        $this->assertTrue($mapper->remote_path_owns_mapped_local_subtree('/a/file.txt'));
    }

    public function testRemotePathDoesNotOwnLocalSubtreeInsideAnotherRemapTarget(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/a', '/b'],
            [
                '/a' => '/local/shared/inner',
                '/b' => '/local/shared',
            ]
        );

        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/a'));
        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/b'));
        $this->assertTrue($mapper->remote_path_owns_mapped_local_subtree('/b/file.txt'));
    }

    public function testRemotePathsDoNotOwnTheSameMappedLocalRoot(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/a', '/b'],
            [
                '/a' => '/local/shared',
                '/b' => '/local/shared',
            ]
        );

        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/a'));
        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/b'));
    }

    public function testRemotePathDoesNotOwnSubtreeWithDescendantRemapSourceHole(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/a', '/b'],
            [
                '/a' => '/local/shared',
                '/a/hole' => '/local/elsewhere',
                '/b' => '/local/shared/hole',
            ]
        );

        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/a'));
        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/b'));
        $this->assertTrue($mapper->remote_path_owns_mapped_local_subtree('/a/file.txt'));
    }

    public function testRemotePathOwnsSubtreeAcrossContinuousNestedRemap(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/a'],
            [
                '/a' => '/local/shared',
                '/a/nested' => '/local/shared/nested',
            ]
        );

        $this->assertTrue($mapper->remote_path_owns_mapped_local_subtree('/a'));
    }

    public function testRemotePathDoesNotOwnNestedRemapTargetAliasedByParentPlacement(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/a'],
            [
                '/a' => '/local/shared',
                '/a/nested' => '/local/shared/other',
            ]
        );
        $this->assertFalse(
            $mapper->remote_path_owns_mapped_local_subtree('/a/nested')
        );
    }

    public function testRemotePathDoesNotOwnSubtreeAcrossOriginalFollowedPlacementBoundary(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/srv/site'],
            [],
            '/local/followed'
        );

        $this->assertFalse($mapper->remote_path_owns_mapped_local_subtree('/srv'));
        $this->assertTrue($mapper->remote_path_owns_mapped_local_subtree('/srv/site'));
        $this->assertTrue($mapper->remote_path_owns_mapped_local_subtree('/mnt/shared'));
    }

    public function testRemoteFilesystemRootOwnsOnlyOneContinuousPlacement(): void
    {
        $identityMapper = new RemoteToLocalPathMapper(
            '/local',
            ['/'],
            [],
            '/local/followed'
        );
        $this->assertTrue(
            $identityMapper->remote_path_owns_mapped_local_subtree('/')
        );

        $splitMapper = new RemoteToLocalPathMapper(
            '/local',
            ['/site'],
            [],
            '/local/followed'
        );
        $this->assertFalse(
            $splitMapper->remote_path_owns_mapped_local_subtree('/')
        );
    }

    public function testRemotePathDoesNotOwnSubtreeWithForeignPlacementAlias(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            ['/followed/site'],
            [],
            '/local/followed'
        );

        $this->assertFalse(
            $mapper->remote_path_owns_mapped_local_subtree('/followed/site')
        );
    }

    public function testPathBytesDoNotNeedToBeValidUtf8(): void
    {
        $remote_root = "/srv/site-\xff";
        $remote_content = $remote_root . "/content-\xfe";
        $local_content = "/local/content-\xfd";
        $mapper = new RemoteToLocalPathMapper(
            '/local',
            [$remote_root],
            [$remote_content => $local_content]
        );

        $this->assertSame(
            $local_content . '/file.txt',
            $mapper->map_path($remote_content . '/file.txt')
        );
        $this->assertSame(
            ["/content-\xfd/file.txt", $remote_content . '/file.txt'],
            $mapper->remote_paths_mapping_to($local_content . '/file.txt')
        );
    }

    public function testConfigurationRoundTripPreservesArbitraryPathBytes(): void
    {
        $mapper = new RemoteToLocalPathMapper(
            "/local-\xff",
            ["/remote-\xfe"],
            ["/remote-\xfe/content" => "/local-\xff/content-\xfd"],
            "/local-\xff/followed-\xfc"
        );

        $config = $mapper->get_config();
        $this->assertIsString(json_encode($config, JSON_THROW_ON_ERROR));
        $resumed = RemoteToLocalPathMapper::from_config($config);

        $this->assertSame(
            "/local-\xff/content-\xfd/file.txt",
            $resumed->map_path("/remote-\xfe/content/file.txt")
        );
        $this->assertSame(
            "/local-\xff/followed-\xfc/outside/file.txt",
            $resumed->map_path('/outside/file.txt')
        );
    }
}
