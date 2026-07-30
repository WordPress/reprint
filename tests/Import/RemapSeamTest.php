<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --remap: the single write seam (map_target_filesystem_path_to_local_filesystem_path)
 * routes in-scope source paths to their target and leaves the rest nested.
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

    public function testInScopePathMapsToDest(): void
    {
        $c = $this->clientWithRules(array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $local = $this->call($c, 'map_target_filesystem_path_to_local_filesystem_path', array(
            '/var/www/html/wp-content/plugins/woo/woo.php',
        ));
        $this->assertSame($this->root . '/wp-content/plugins/woo/woo.php', $local);
    }

    public function testDeeperSourceWinsRegardlessOfTargetLength(): void
    {
        // Two nested sources; the deeper (more specific) one has the SHORTER
        // target. It must still win — specificity is ranked by source, not by
        // target length.
        $c = $this->clientWithRules(array(
            '/srv/wp-content' => $this->root . '/archive-of-everything',
            '/srv/wp-content/plugins' => $this->root . '/p',
        ));
        $local = $this->call($c, 'map_target_filesystem_path_to_local_filesystem_path', array(
            '/srv/wp-content/plugins/woo/woo.php',
        ));
        $this->assertSame($this->root . '/p/woo/woo.php', $local);
    }

    public function testDocrootRootTargetPlacesFilesAtDocrootRoot(): void
    {
        // A target that is the docroot itself: files land directly at the root,
        // no double slash.
        $c = $this->clientWithRules(array(
            '/var/www/html/wp-content' => $this->root,
        ));
        $local = $this->call($c, 'map_target_filesystem_path_to_local_filesystem_path', array(
            '/var/www/html/wp-content/plugins/woo/woo.php',
        ));
        $this->assertSame($this->root . '/plugins/woo/woo.php', $local);
    }

    public function testOutOfScopePathFallsBackToNestedIdentity(): void
    {
        $c = $this->clientWithRules(array(
            '/var/www/html/wp-content' => $this->root . '/wp-content',
        ));
        $local = $this->call($c, 'map_target_filesystem_path_to_local_filesystem_path', array(
            '/var/www/html/wp-admin/index.php',
        ));
        $this->assertSame($this->root . '/var/www/html/wp-admin/index.php', $local);
    }

    public function testNoRulesIsLegacyMapping(): void
    {
        $c = $this->clientWithRules(array());
        $local = $this->call($c, 'map_target_filesystem_path_to_local_filesystem_path', array(
            '/var/www/html/wp-content/x.txt',
        ));
        $this->assertSame($this->root . '/var/www/html/wp-content/x.txt', $local);
    }

    /**
     * The path-prefix helper underpinning rule matching. A trailing slash on
     * either argument is path-equivalent and must be ignored.
     *
     * @dataProvider providePathRemainderCases
     */
    public function testPathRemainderUnder(?string $expected, string $path, string $prefix): void
    {
        $c = $this->clientWithRules(array());
        $this->assertSame($expected, $this->call($c, 'path_remainder_under', array($path, $prefix)));
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
}
