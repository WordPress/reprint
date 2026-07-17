<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilePlacementRules;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --follow-symlinks=<directory> ("symlink bundle") mode: resolving the bundle
 * destination, classifying escaping vs in-scope paths, and routing placement.
 */
class SymlinkBundleTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/symlink-bundle-' . uniqid();
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

    private function newClient(): \ImportClient
    {
        return new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
    }

    // ── Resolving the bundle destination (:fs-root: grammar, within-root) ──

    private function resolve(string $raw): string
    {
        $c = $this->newClient();
        return (new \ReflectionClass($c))->getMethod('resolve_symlink_bundle_directory')->invoke($c, $raw);
    }

    public function testFsRootTokenResolvesUnderRoot(): void
    {
        $this->assertSame($this->root . '/.symlinks-bundle', $this->resolve(':fs-root:/.symlinks-bundle'));
    }

    public function testRawAbsoluteWithinRootIsKept(): void
    {
        $this->assertSame($this->root . '/.symlinks-bundle', $this->resolve($this->root . '/.symlinks-bundle'));
    }

    public function testFsRootItselfIsAccepted(): void
    {
        // --follow-symlinks=:fs-root: is the identity case (fs-root itself), not an error.
        $this->assertSame($this->root, $this->resolve(':fs-root:'));
    }

    public function testOutsideFsRootIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolve('/etc/reprint-bundle');
    }

    public function testRelativeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolve('.symlinks-bundle');
    }

    // ── "Escaping" classification against the original export scope ──

    private function inScope(array $onlyPrefixes, string $path): bool
    {
        return FilePlacementRules::pathIsWithinOriginalExportScope($path, $onlyPrefixes);
    }

    public function testTargetUnderScopeIsInScope(): void
    {
        $this->assertTrue($this->inScope(['/srv/site/wp-content'], '/srv/site/wp-content/plugins/foo'));
    }

    public function testTargetOutsideScopeEscapes(): void
    {
        $this->assertFalse($this->inScope(['/srv/site/wp-content'], '/home/master/shared/foo'));
    }

    // ── Placement routing (bundle vs default vs remap) ──

    /**
     * @param string|null $bundleSub Bundle dir as a suffix under fs-root (null = no bundle).
     * @param array<int,string> $scopePrefixes Original export scope (--only prefixes).
     * @param array<string,string> $remapRules source => absolute target.
     */
    private function placeClient(?string $bundleSub, array $scopePrefixes, array $remapRules = []): \ImportClient
    {
        $c = $this->newClient();
        $rc = new \ReflectionClass($c);
        $rc->getProperty('symlink_bundle_directory')->setValue($c, $bundleSub === null ? null : $this->root . $bundleSub);
        $rc->getProperty('pull_only_files_with_path_prefixes')->setValue($c, $scopePrefixes);
        $rc->getProperty('remap_rules')->setValue($c, $remapRules);
        return $c;
    }

    private function place(\ImportClient $c, string $path): string
    {
        return (new \ReflectionClass($c))->getMethod('remote_path_to_local_path_within_import_root')->invoke($c, $path);
    }

    public function testEscapingTargetRoutesIntoBundle(): void
    {
        $c = $this->placeClient('/.symlinks-bundle', ['/var/www/html']);
        $this->assertSame(
            $this->root . '/.symlinks-bundle/tmp/shared/foo/style.css',
            $this->place($c, '/tmp/shared/foo/style.css')
        );
    }

    public function testInScopePathNotBundled(): void
    {
        $c = $this->placeClient('/.symlinks-bundle', ['/var/www/html']);
        $this->assertSame(
            $this->root . '/var/www/html/index.php',
            $this->place($c, '/var/www/html/index.php')
        );
    }

    public function testDefaultPlacementWhenNoBundleDirectory(): void
    {
        $c = $this->placeClient(null, []);
        $this->assertSame(
            $this->root . '/tmp/shared/foo/style.css',
            $this->place($c, '/tmp/shared/foo/style.css')
        );
    }

    // Regression: an escaping root (/shared) that is an ANCESTOR of the scope
    // (/shared/wp-content) must not bundle the in-scope subtree.
    public function testAncestorEscapingRootLeavesInScopeContentInPlace(): void
    {
        $c = $this->placeClient('/.symlinks-bundle', ['/shared/wp-content']);
        $this->assertSame(
            $this->root . '/shared/wp-content/plugins/foo.php',
            $this->place($c, '/shared/wp-content/plugins/foo.php'),
            'in-scope content must not be pulled into the bundle'
        );
        $this->assertSame(
            $this->root . '/.symlinks-bundle/shared/other/bar.php',
            $this->place($c, '/shared/other/bar.php'),
            'genuinely escaping content is still bundled'
        );
    }

    // Regression: an explicit --remap rule wins over bundling, so file placement
    // and the symlink repoint (which share this seam) agree — no dangling link.
    public function testRemapWinsOverBundle(): void
    {
        $c = $this->placeClient('/.symlinks-bundle', ['/var/www/html'], ['/escaped' => $this->root . '/x']);
        $this->assertSame(
            $this->root . '/x/foo',
            $this->place($c, '/escaped/foo'),
            'remap target wins; the path is not diverted into the bundle'
        );
    }

    // ── Bundle-directory fingerprint guard ──

    private function assertGuard(\ImportClient $c, ?string $bundleDir, ?string $persistedFingerprint): void
    {
        $rc = new \ReflectionClass($c);
        $rc->getProperty('symlink_bundle_directory')->setValue($c, $bundleDir);
        $rc->getProperty('state')->getValue($c)->symlink_bundle_directory_fingerprint = $persistedFingerprint;
        $rc->getMethod('assert_symlink_bundle_directory_unchanged')->invoke($c);
    }

    public function testGuardRejectsChangedBundleDirectory(): void
    {
        $c = $this->newClient();
        $this->expectException(\RuntimeException::class);
        $this->assertGuard($c, $this->root . '/.bundle-a', hash('sha256', $this->root . '/.bundle-b'));
    }

    public function testGuardAllowsUnchangedBundleDirectory(): void
    {
        $c = $this->newClient();
        $this->assertGuard($c, $this->root . '/.bundle-a', hash('sha256', $this->root . '/.bundle-a'));
        $this->addToAssertionCount(1); // no exception == pass
    }

    public function testGuardRejectsDroppingTheBundleDirectory(): void
    {
        // A flag-less run after a bundled pull must error, not silently revert
        // to default placement.
        $c = $this->newClient();
        $this->expectException(\RuntimeException::class);
        $this->assertGuard($c, null, hash('sha256', $this->root . '/.bundle-a'));
    }

    public function testGuardTreatsBareFollowAndFsRootAsEquivalent(): void
    {
        // Bare --follow-symlinks (no bundle dir) and --follow-symlinks=:fs-root:
        // fingerprint identically — both place at fs-root.
        $c = $this->newClient();
        $this->assertGuard($c, null, hash('sha256', $this->root));
        $this->assertGuard($c, $this->root, hash('sha256', $this->root));
        $this->addToAssertionCount(1); // no exception == pass
    }

    // ── Repoint routes via remap even when the target is spelled within fs-root ──
    // Overlap case: source docroot == target --fs-root, so an absolute symlink
    // target falls inside fs-root; a non-identity --remap relocates its content.
    // The repoint must follow the content to the remapped location, not keep the
    // verbatim within-fs-root path (which would dangle).
    public function testWithinFsRootTargetRepointsToRemappedLocation(): void
    {
        $c = $this->newClient();
        $rc = new \ReflectionClass($c);
        $rc->getProperty('remap_rules')->setValue($c, [$this->root . '/wp-content' => $this->root . '/custom']);
        $rc->getProperty('follow_symlinks')->setValue($c, true);
        $target = $this->root . '/wp-content/themes/x'; // realpath-clean, so it is its own cache key
        // Pretend the target subtree was followed + indexed.
        $rc->getProperty('remote_index_prefix_cache')->setValue($c, [$target => true]);

        $result = $rc->getMethod('map_symlink_target_for_local_mirror')->invoke(
            $c,
            '/src/wp-content/plugins/p/link',            // source symlink path
            $this->root . '/wp-content/plugins/p/link',  // local path
            $target                                       // absolute, inside fs-root
        );

        $this->assertNotSame($target, $result, 'must not keep the verbatim within-fs-root target');
        $this->assertStringStartsWith('..', $result, 'repoint is a relative path climbing out of plugins/p');
        $this->assertStringContainsString('custom/themes/x', $result,
            'repoint follows the content to the remapped custom/ location, not the pre-remap wp-content');
    }

    // ── Intermediate symlinks are repointed through the placement seam ──

    public function testIntermediateSymlinkRepointsIntoBundle(): void
    {
        // In-scope intermediate link whose relative target resolves to an
        // escaping (bundled) location: the raw target would dangle at
        // fs-root/opt/data; it must be repointed to the bundle placement.
        $c = $this->newClient();
        $rc = new \ReflectionClass($c);
        $rc->getProperty('symlink_bundle_directory')->setValue($c, $this->root . '/.symlinks-bundle');
        $rc->getProperty('pull_only_files_with_path_prefixes')->setValue($c, ['/src/wp-content']);
        $rc->getProperty('follow_symlinks')->setValue($c, true);
        $rc->getProperty('remote_index_prefix_cache')->setValue($c, ['/opt/data' => true]);

        $entry = json_encode([
            'path' => base64_encode('/src/wp-content/data'),
            'target' => base64_encode('../../../opt/data'),
            'type' => 'link',
            'intermediate' => true,
        ]);
        file_put_contents($this->stateDir . '/.import-remote-index.jsonl', $entry . "\n");

        $rc->getMethod('recreate_intermediate_symlinks')->invoke($c);

        $link = $this->root . '/src/wp-content/data';
        $this->assertTrue(is_link($link), 'intermediate link is created');
        $this->assertStringContainsString('.symlinks-bundle/opt/data', readlink($link),
            'target is repointed to the bundled placement, not the raw source spelling');
    }
}
