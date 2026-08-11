<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Exporter\path_is_descendant_of;
use function WordPress\Reprint\Exporter\path_remainder_under;
use function WordPress\Reprint\Exporter\realpath_with_missing_tail;
use function WordPress\Reprint\Exporter\relative_path_under;
use function WordPress\Reprint\Exporter\trim_right_slash;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * --remap: the single write seam (map_remote_absolute_path_to_local_absolute_path)
 * routes remote absolute paths to local absolute paths and leaves the rest nested.
 */
class RemapSeamTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remap-seam-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
        $this->root = realpath($this->fsRoot);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tempDir);
        parent::tearDown();
    }

    private function rrm(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = "$d/$i";
            (is_dir($p) && !is_link($p)) ? $this->rrm($p) : unlink($p);
        }
        rmdir($d);
    }

    private function call($c, string $m, array $a = array())
    {
        return (new \ReflectionClass($c))->getMethod($m)->invoke($c, ...$a);
    }

    private function set($c, string $p, $v): void
    {
        (new \ReflectionClass($c))->getProperty($p)->setValue($c, $v);
    }

    private function clientWithRules(array $rules): \ImportClient
    {
        $c = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $this->set($c, 'resolved_path_mappings', $rules);
        return $c;
    }

    public function testRemoteAbsolutePathMapsToLocalAbsolutePath(): void
    {
        $c = $this->clientWithRules(array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $local_absolute_path = $this->call($c, 'map_remote_absolute_path_to_local_absolute_path', array(
            '/var/www/html/wp-content/plugins/woo/woo.php',
        ));
        $this->assertSame($this->root . '/wp-content/plugins/woo/woo.php', $local_absolute_path);
    }

    public function testDeeperRemotePrefixWinsRegardlessOfLocalPrefixLength(): void
    {
        // Two nested remote prefixes; the deeper (more specific) one has the
        // shorter local prefix. It must still win — specificity is ranked by
        // remote-prefix length, not local-prefix length.
        $c = $this->clientWithRules(array(
            '/srv/wp-content' => $this->root . '/archive-of-everything',
            '/srv/wp-content/plugins' => $this->root . '/p',
        ));
        $local_absolute_path = $this->call($c, 'map_remote_absolute_path_to_local_absolute_path', array(
            '/srv/wp-content/plugins/woo/woo.php',
        ));
        $this->assertSame($this->root . '/p/woo/woo.php', $local_absolute_path);
    }

    public function testLocalAbsolutePrefixPlacesFilesAtItsRoot(): void
    {
        // A local absolute prefix that is the filesystem root: files land directly at the root,
        // no double slash.
        $c = $this->clientWithRules(array(
            '/var/www/html/wp-content' => $this->root,
        ));
        $local_absolute_path = $this->call($c, 'map_remote_absolute_path_to_local_absolute_path', array(
            '/var/www/html/wp-content/plugins/woo/woo.php',
        ));
        $this->assertSame($this->root . '/plugins/woo/woo.php', $local_absolute_path);
    }

    public function testOutOfScopePathFallsBackToNestedIdentity(): void
    {
        $c = $this->clientWithRules(array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $local_absolute_path = $this->call($c, 'map_remote_absolute_path_to_local_absolute_path', array(
            '/var/www/html/wp-admin/index.php',
        ));
        $this->assertSame($this->root . '/var/www/html/wp-admin/index.php', $local_absolute_path);
    }

    public function testNoRulesIsLegacyMapping(): void
    {
        $c = $this->clientWithRules(array());
        $local_absolute_path = $this->call($c, 'map_remote_absolute_path_to_local_absolute_path', array(
            '/var/www/html/wp-content/x.txt',
        ));
        $this->assertSame($this->root . '/var/www/html/wp-content/x.txt', $local_absolute_path);
    }

    /**
     * The path-prefix helper underpinning rule matching. A trailing slash on
     * either argument is path-equivalent and must be ignored.
     *
     * @dataProvider providePathRemainderCases
     */
    public function testPathRemainderUnder(?string $expected, string $path, string $prefix): void
    {
        $this->assertSame($expected, path_remainder_under($path, $prefix));
    }

    public static function providePathRemainderCases(): array
    {
        return array(
            'exact match' => array('', '/a/b', '/a/b'),
            'under prefix' => array('/c', '/a/b/c', '/a/b'),
            'not under (prefix is not a path boundary)' => array(null, '/a/bc', '/a/b'),
            'trailing slash on prefix' => array('', '/home/adam', '/home/adam/'),
            'trailing slash on path' => array('', '/home/adam/', '/home/adam'),
            'trailing slash on both' => array('', '/home/adam/', '/home/adam/'),
            'under, prefix has trailing slash' => array('/c', '/a/b/c', '/a/b/'),
        );
    }

    /**
     * @dataProvider provideTrailingSlashPathCases
     */
    public function testTrimRightSlash(string $expected, string $path): void
    {
        $this->assertSame($expected, trim_right_slash($path));
    }

    public static function provideTrailingSlashPathCases(): array
    {
        return array(
            'path without trailing slashes' => array('/srv/site', '/srv/site'),
            'path with trailing slashes' => array('/srv/site', '/srv/site///'),
            'filesystem root' => array('/', '/'),
            'empty input becomes filesystem root' => array('/', ''),
        );
    }

    /**
     * @dataProvider provideRelativePathCases
     */
    public function testRelativePathUnder(?string $expected, string $path, string $root): void
    {
        $this->assertSame($expected, relative_path_under($path, $root));
    }

    public static function provideRelativePathCases(): array
    {
        return array(
            'empty relative root itself' => array('', '', ''),
            'empty relative root child' => array('child/path', 'child/path', ''),
            'empty relative root child with trailing slash' => array('child/path', 'child/path/', ''),
            'absolute path outside empty relative root' => array(null, '/child/path', ''),
            'relative path outside filesystem root' => array(null, 'child/path', '/'),
            'filesystem root itself' => array('', '/', '/'),
            'filesystem root child' => array('child', '/child', '/'),
            'exact non-root match' => array('', '/a', '/a'),
            'non-root descendant' => array('b', '/a/b', '/a'),
            'sibling prefix' => array(null, '/ab', '/a'),
            'trailing slashes' => array('b', '/a/b/', '/a/'),
        );
    }

    /**
     * @dataProvider providePathSameAsOrDescendantOfCases
     */
    public function testPathIsSameAsOrDescendantOf(bool $expected, string $path, string $ancestor): void
    {
        $this->assertSame($expected, path_is_same_as_or_descendant_of($path, $ancestor));
    }

    public static function providePathSameAsOrDescendantOfCases(): array
    {
        return array(
            'filesystem root itself' => array(true, '/', '/'),
            'filesystem root child' => array(true, '/child', '/'),
            'relative path is outside filesystem root' => array(false, 'child', '/'),
            'exact non-root match' => array(true, '/a', '/a'),
            'non-root descendant' => array(true, '/a/b', '/a'),
            'sibling prefix' => array(false, '/ab', '/a'),
        );
    }

    public function testPathIsSameAsOrDescendantOfMatchesAnyPathAndAncestor(): void
    {
        $this->assertTrue(path_is_same_as_or_descendant_of('/a/b', ['/elsewhere', '/a']));
        $this->assertTrue(path_is_same_as_or_descendant_of(['/elsewhere', '/a/b'], '/a'));
        $this->assertTrue(path_is_same_as_or_descendant_of(
            ['/elsewhere', '/a/b'],
            ['/not-this-one', '/a']
        ));
        $this->assertFalse(path_is_same_as_or_descendant_of(
            ['/elsewhere', '/other'],
            ['/not-this-one', '/a']
        ));
    }

    /**
     * @dataProvider providePathDescendantOfCases
     */
    public function testPathIsDescendantOf(bool $expected, string $path, string $ancestor): void
    {
        $this->assertSame($expected, path_is_descendant_of($path, $ancestor));
    }

    public static function providePathDescendantOfCases(): array
    {
        return array(
            'filesystem root itself' => array(false, '/', '/'),
            'filesystem root child' => array(true, '/child', '/'),
            'relative path is outside filesystem root' => array(false, 'child', '/'),
            'exact non-root match' => array(false, '/a', '/a'),
            'non-root descendant' => array(true, '/a/b', '/a'),
            'sibling prefix' => array(false, '/ab', '/a'),
            'relative logical descendant' => array(true, 'a/b', 'a'),
        );
    }

    public function testPathIsDescendantOfMatchesAnyPathAndAncestor(): void
    {
        $this->assertTrue(path_is_descendant_of('/a/b', ['/elsewhere', '/a']));
        $this->assertTrue(path_is_descendant_of(['/elsewhere', '/a/b'], '/a'));
        $this->assertTrue(path_is_descendant_of(
            ['/elsewhere', '/a/b'],
            ['/not-this-one', '/a']
        ));
        $this->assertFalse(path_is_descendant_of(
            ['/elsewhere', '/a'],
            ['/not-this-one', '/a']
        ));
    }

    public function testRealpathWithMissingTail(): void
    {
        $existing_directory = $this->tempDir . '/existing';
        mkdir($existing_directory);
        $canonical_existing_directory = realpath($existing_directory);
        $this->assertIsString($canonical_existing_directory);

        $this->assertSame(
            $canonical_existing_directory,
            realpath_with_missing_tail($existing_directory)
        );
        $this->assertSame(
            $canonical_existing_directory . '/missing',
            realpath_with_missing_tail($existing_directory . '/missing')
        );
        $this->assertSame(
            $canonical_existing_directory . '/missing/child',
            realpath_with_missing_tail($existing_directory . '/missing/child')
        );
        $this->assertSame('/', realpath_with_missing_tail('/'));

        $symlink = $this->tempDir . '/existing-link';
        symlink($existing_directory, $symlink);
        $this->assertSame(
            $canonical_existing_directory . '/missing-through-link',
            realpath_with_missing_tail($symlink . '/missing-through-link')
        );

        $broken_symlink = $this->tempDir . '/broken-link';
        symlink($this->tempDir . '/missing-target', $broken_symlink);
        $this->assertSame(
            $broken_symlink . '/child',
            realpath_with_missing_tail($broken_symlink . '/child')
        );
    }

    public function testNextRemoteIndexMatchesFilesystemRootAndPathBoundaries(): void
    {
        $client = $this->clientWithRules(array());
        $next_remote_index_file = $this->tempDir . '/remote-index.next.jsonl';
        file_put_contents(
            $next_remote_index_file,
            json_encode([
                'path' => base64_encode('/srv/site/child.txt'),
                'ctime' => 0,
                'size' => 1,
                'type' => 'file',
            ]) . "\n"
        );
        $this->set($client, 'next_remote_index_file', $next_remote_index_file);

        $this->assertTrue($this->call(
            $client,
            'next_remote_index_contains_remote_absolute_path_prefix',
            array('/')
        ));
        $this->assertTrue($this->call(
            $client,
            'next_remote_index_contains_remote_absolute_path_prefix',
            array('/srv/site')
        ));
        $this->assertFalse($this->call(
            $client,
            'next_remote_index_contains_remote_absolute_path_prefix',
            array('/srv/site-old')
        ));
    }
}
