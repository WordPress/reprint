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
