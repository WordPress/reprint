<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify --preserve-local-content merges wp-content into an existing
 * directory instead of replacing it, and that the flag is opt-in.
 */
class FlatDocrootPreserveLocalContentTest extends TestCase
{
    private const ABSPATH = '/srv/htdocs/wordpress/';

    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private string $flattenTo;
    private string $localAbspath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/flat-docroot-preserve-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->flattenTo = $this->tempDir . '/flat';
        $this->localAbspath = $this->fsRoot . self::ABSPATH;
        mkdir($this->stateDir, 0755, true);
        mkdir($this->localAbspath, 0755, true);
        file_put_contents($this->localAbspath . 'wp-load.php', '<?php // wp-load');
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testMergeKeepsEntriesThatExistOnlyInTheTarget(): void
    {
        $this->writeSource('wp-content/plugins/from-source/plugin.php', '<?php // source');
        $this->writeTarget('wp-content/plugins/target-only/plugin.php', '<?php // target');
        $this->writeTarget('wp-content/themes/target-theme/style.css', '/* target */');
        $this->writeTarget('wp-content/custom-folder/note.txt', 'keep me');

        $this->flatten(true);

        $this->assertFileExists($this->flattenTo . '/wp-content/plugins/target-only/plugin.php');
        $this->assertFileExists($this->flattenTo . '/wp-content/themes/target-theme/style.css');
        $this->assertFileExists($this->flattenTo . '/wp-content/custom-folder/note.txt');
        $this->assertFileExists(
            $this->flattenTo . '/wp-content/plugins/from-source/plugin.php',
            'source entries are still linked in',
        );
        $this->assertFalse(
            is_link($this->flattenTo . '/wp-content'),
            'wp-content stays a real directory',
        );
    }

    public function testSourceWinsOnCollisionWithoutForce(): void
    {
        $this->writeSource('wp-content/plugins/shared/plugin.php', '<?php // from source');
        $this->writeTarget('wp-content/plugins/shared/plugin.php', '<?php // from target');

        $this->flatten(true);

        $this->assertStringContainsString(
            'from source',
            file_get_contents($this->flattenTo . '/wp-content/plugins/shared/plugin.php'),
            'a collision resolves to the source rather than raising',
        );
    }

    public function testPrunesLinkWhoseSourceEntryIsGone(): void
    {
        $this->writeSource('wp-content/plugins/temporary/plugin.php', '<?php');
        $this->writeTarget('wp-content/plugins/target-only/plugin.php', '<?php');
        $this->flatten(true);

        $link = $this->flattenTo . '/wp-content/plugins/temporary';
        $this->assertTrue(is_link($link), 'source entry is linked on the first pass');

        $this->recursiveDelete($this->localAbspath . 'wp-content/plugins/temporary');
        $this->flatten(true);

        $this->assertFalse(is_link($link), 'the link is pruned once its source entry is gone');
        $this->assertFileExists(
            $this->flattenTo . '/wp-content/plugins/target-only/plugin.php',
            'pruning leaves target-only entries alone',
        );
    }

    public function testDoesNotPruneDanglingLinkPointingOutsideTheFsRoot(): void
    {
        $this->writeSource('wp-content/plugins/from-source/plugin.php', '<?php');
        mkdir($this->flattenTo . '/wp-content/plugins', 0755, true);
        // Dangles, but resolves outside the fs root, so it is the target's own.
        symlink($this->tempDir . '/elsewhere', $this->flattenTo . '/wp-content/plugins/target-owned');

        $this->flatten(true);

        $this->assertTrue(
            is_link($this->flattenTo . '/wp-content/plugins/target-owned'),
            'links resolving outside the fs root are not ours to prune',
        );
    }

    public function testSubtreeMissingFromTheTargetCostsASingleLink(): void
    {
        $this->writeSource('wp-content/uploads/2026/01/a.jpg', 'a');
        $this->writeSource('wp-content/uploads/2026/02/b.jpg', 'b');
        $this->writeTarget('wp-content/plugins/target-only/plugin.php', '<?php');

        $this->flatten(true);

        $this->assertTrue(
            is_link($this->flattenTo . '/wp-content/uploads'),
            'uploads is one link, not an exploded tree',
        );
        $this->assertFileExists($this->flattenTo . '/wp-content/uploads/2026/01/a.jpg');
    }

    public function testWithoutTheFlagAnExistingDirectoryIsStillAConflict(): void
    {
        $this->writeSource('wp-content/plugins/from-source/plugin.php', '<?php');
        $this->writeTarget('wp-content/plugins/target-only/plugin.php', '<?php');

        $this->expectException(\RuntimeException::class);
        $this->flatten(false);
    }

    public function testWithoutTheFlagForceStillReplacesWpContent(): void
    {
        $this->writeSource('wp-content/plugins/from-source/plugin.php', '<?php');
        $this->writeTarget('wp-content/plugins/target-only/plugin.php', '<?php');

        $this->flatten(false, true);

        $this->assertTrue(
            is_link($this->flattenTo . '/wp-content'),
            'wp-content is replaced by a single symlink',
        );
        $this->assertFileDoesNotExist(
            $this->flattenTo . '/wp-content/plugins/target-only/plugin.php',
        );
    }

    // ---- helpers ----

    private function flatten(bool $preserve, bool $force = false): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'table_prefix' => 'wp_',
                            'paths_urls' => [
                                'abspath' => self::ABSPATH,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'run_flat_document_root', [
            [
                'flatten_to' => $this->flattenTo,
                'force' => $force,
                'preserve_local_content' => $preserve,
            ],
        ]);
    }

    private function writeSource(string $relative, string $contents): void
    {
        $this->writeFile($this->localAbspath . $relative, $contents);
    }

    private function writeTarget(string $relative, string $contents): void
    {
        $this->writeFile($this->flattenTo . '/' . $relative, $contents);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function writeState(array $state): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode($state, JSON_PRETTY_PRINT),
        );
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('state');
        $property->setValue($client, $state);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }
        rmdir($dir);
    }
}
