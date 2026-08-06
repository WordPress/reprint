<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\path_is_within_root;
use function WordPress\Reprint\Exporter\path_remainder_under;
use function WordPress\Reprint\Exporter\relative_path_under;

require_once __DIR__ . '/../../client/import.php';

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
     * @dataProvider provideRelativePathCases
     */
    public function testRelativePathUnder(?string $expected, string $path, string $root): void
    {
        $this->assertSame($expected, relative_path_under($path, $root));
    }

    public static function provideRelativePathCases(): array
    {
        return array(
            'filesystem root itself' => array('', '/', '/'),
            'filesystem root child' => array('child', '/child', '/'),
            'exact non-root match' => array('', '/a', '/a'),
            'non-root descendant' => array('b', '/a/b', '/a'),
            'sibling prefix' => array(null, '/ab', '/a'),
            'trailing slashes' => array('b', '/a/b/', '/a/'),
        );
    }

    /**
     * @dataProvider providePathWithinRootCases
     */
    public function testPathIsWithinRoot(bool $expected, string $path, string $root): void
    {
        $this->assertSame($expected, path_is_within_root($path, $root));
    }

    public static function providePathWithinRootCases(): array
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

    public function testPathIsWithinRootMatchesAnyPathAndRoot(): void
    {
        $this->assertTrue(path_is_within_root('/a/b', ['/elsewhere', '/a']));
        $this->assertTrue(path_is_within_root(['/elsewhere', '/a/b'], '/a'));
        $this->assertTrue(path_is_within_root(
            ['/elsewhere', '/a/b'],
            ['/not-this-one', '/a']
        ));
        $this->assertFalse(path_is_within_root(
            ['/elsewhere', '/other'],
            ['/not-this-one', '/a']
        ));
    }
}
