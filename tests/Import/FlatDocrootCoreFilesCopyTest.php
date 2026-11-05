<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify run_flat_document_root() with --core-files=copy places real copies
 * of WordPress core entries in the flattened site and keeps every other
 * entry linked into the filesystem root.
 */
class FlatDocrootCoreFilesCopyTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private string $flattenTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/flat-docroot-core-copy-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->flattenTo = $this->tempDir . '/flat';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testCopiesCoreFromWpCloudVersionedAbspath(): void
    {
        $this->createWpCloudLayout();

        $this->flatten(['core_files' => 'copy']);

        foreach (['wp-admin', 'wp-includes'] as $directory) {
            $this->assertFalse(is_link($this->flattenTo . '/' . $directory), "{$directory} should be a real directory");
            $this->assertDirectoryExists($this->flattenTo . '/' . $directory);
        }
        foreach (['index.php', 'wp-load.php', 'wp-settings.php', 'xmlrpc.php', 'license.txt', 'readme.html'] as $file) {
            $this->assertFalse(is_link($this->flattenTo . '/' . $file), "{$file} should be a real file");
        }
        $this->assertSame('<?php // admin', file_get_contents($this->flattenTo . '/wp-admin/admin.php'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));

        $this->assertTrue(is_link($this->flattenTo . '/wp-content'), 'wp-content should stay a symlink');
        $this->assertSame('theme', file_get_contents($this->flattenTo . '/wp-content/themes/theme.txt'));
    }

    public function testCopiesCoreButLinksSiteFilesOnStandardLayout(): void
    {
        $abspath = $this->fsRoot . '/var/www/html';
        $this->createCoreTree($abspath, '7.1.3');
        mkdir($abspath . '/wp-content', 0755, true);
        file_put_contents($abspath . '/.htaccess', '# rewrite rules');
        file_put_contents($abspath . '/robots.txt', 'User-agent: *');
        file_put_contents($abspath . '/wp-config.php', '<?php // config');
        $this->writePreflight(['abspath' => '/var/www/html']);

        $this->flatten(['core_files' => 'copy']);

        $this->assertFalse(is_link($this->flattenTo . '/wp-admin'));
        $this->assertFalse(is_link($this->flattenTo . '/index.php'));
        $this->assertFalse(is_link($this->flattenTo . '/wp-load.php'));
        foreach (['.htaccess', 'robots.txt', 'wp-config.php', 'wp-content'] as $siteEntry) {
            $this->assertTrue(is_link($this->flattenTo . '/' . $siteEntry), "{$siteEntry} should stay a symlink");
        }
    }

    public function testLinkModeIsTheDefault(): void
    {
        $this->createWpCloudLayout();

        $this->flatten([]);

        $this->assertTrue(is_link($this->flattenTo . '/wp-admin'));
        $this->assertTrue(is_link($this->flattenTo . '/index.php'));
    }

    public function testReplacesLinksFromAnEarlierLinkModeRun(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'link']);
        $this->assertTrue(is_link($this->flattenTo . '/wp-includes'));

        $this->flatten(['core_files' => 'copy']);

        $this->assertFalse(is_link($this->flattenTo . '/wp-includes'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertFalse(is_link($this->flattenTo . '/index.php'));
    }

    public function testEachRunReplacesTheCopyWithPulledCore(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'copy']);
        file_put_contents($this->flattenTo . '/wp-includes/version.php', '<?php // edited locally');
        file_put_contents($this->flattenTo . '/wp-includes/stale.php', '<?php // stale');
        file_put_contents($this->flattenTo . '/index.php', '<?php // edited index');

        $this->flatten(['core_files' => 'copy']);

        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertFileDoesNotExist($this->flattenTo . '/wp-includes/stale.php');
        $this->assertSame('<?php // index', file_get_contents($this->flattenTo . '/index.php'));
    }

    public function testWpConfigInCoreKeepsLinkConflictRule(): void
    {
        $this->createWpCloudLayout();
        file_put_contents($this->fsRoot . '/wordpress/core/7.1.3/wp-config.php', '<?php // source config');
        mkdir($this->flattenTo, 0755, true);
        file_put_contents($this->flattenTo . '/wp-config.php', '<?php // local config');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp-config.php');

        // wp-config.php is not core: it keeps today's link behaviour, which
        // refuses to replace a real file without --force.
        $this->flatten(['core_files' => 'copy']);
    }

    public function testCopyRejectsSymlinkInsideCore(): void
    {
        $this->createWpCloudLayout();
        symlink('/etc', $this->fsRoot . '/wordpress/core/7.1.3/wp-includes/outside');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symlink');

        $this->flatten(['core_files' => 'copy']);
    }

    public function testCopiesDetachedWpAdminAndWpIncludes(): void
    {
        $this->createCoreTree($this->fsRoot . '/wordpress/core/7.1.3', '7.1.3');
        $abspath = $this->fsRoot . '/srv/htdocs';
        mkdir($abspath . '/wp-content', 0755, true);
        file_put_contents($abspath . '/index.php', '<?php // site index');
        $this->writePreflight([
            'abspath' => '/srv/htdocs',
            'wp_admin_path' => '/wordpress/core/7.1.3/wp-admin',
            'wp_includes_path' => '/wordpress/core/7.1.3/wp-includes',
        ]);

        $this->flatten(['core_files' => 'copy']);

        foreach (['wp-admin', 'wp-includes'] as $directory) {
            $this->assertFalse(is_link($this->flattenTo . '/' . $directory), "{$directory} should be a real directory");
        }
        $this->assertSame('<?php // admin', file_get_contents($this->flattenTo . '/wp-admin/admin.php'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
    }

    public function testSwitchingBackToLinkNeedsForce(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'copy']);

        try {
            $this->flatten(['core_files' => 'link']);
            $this->fail('Replacing copied core with links should require --force');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('--force', $exception->getMessage());
        }

        $this->flatten(['core_files' => 'link', 'force' => true]);

        $this->assertTrue(is_link($this->flattenTo . '/wp-includes'));
        $this->assertTrue(is_link($this->flattenTo . '/index.php'));
    }

    public function testSkipsFilesWithMatchingSizeAndMtime(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'copy']);
        $source = $this->fsRoot . '/wordpress/core/7.1.3/wp-admin/admin.php';
        $copy = $this->flattenTo . '/wp-admin/admin.php';
        $this->assertSame(filemtime($source), filemtime($copy));

        // Same size and mtime as the source, so the next run leaves it alone.
        file_put_contents($copy, '<?php // ADMIN');
        touch($copy, filemtime($source));
        $this->flatten(['core_files' => 'copy']);
        $this->assertSame('<?php // ADMIN', file_get_contents($copy));

        // A different mtime alone is enough to copy it again.
        touch($copy, filemtime($source) - 60);
        clearstatcache();
        $this->flatten(['core_files' => 'copy']);
        $this->assertSame('<?php // admin', file_get_contents($copy));
    }

    public function testRemovesWhatPulledCoreNoLongerHas(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'copy']);
        mkdir($this->flattenTo . '/wp-includes/stale/nested', 0755, true);
        file_put_contents($this->flattenTo . '/wp-includes/stale/nested/old.php', '<?php // old');
        symlink('/etc', $this->flattenTo . '/wp-admin/stale-link');

        $this->flatten(['core_files' => 'copy']);

        $this->assertDirectoryDoesNotExist($this->flattenTo . '/wp-includes/stale');
        $this->assertFalse(is_link($this->flattenTo . '/wp-admin/stale-link'));
        $this->assertDirectoryExists('/etc');
    }

    public function testReplacesTargetsOfTheWrongType(): void
    {
        $this->createWpCloudLayout();
        mkdir($this->flattenTo . '/index.php/nested', 0755, true);
        file_put_contents($this->flattenTo . '/wp-includes', 'a file where a directory belongs');

        $this->flatten(['core_files' => 'copy']);

        $this->assertSame('<?php // index', file_get_contents($this->flattenTo . '/index.php'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
    }

    public function testCopyNeverWritesThroughLinksIntoThePulledTree(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'link']);
        $pulledFile = $this->fsRoot . '/wordpress/core/7.1.3/wp-load.php';
        $pulledDirectory = $this->fsRoot . '/wordpress/core/7.1.3/wp-includes';
        unlink($this->flattenTo . '/wp-load.php');
        symlink($this->fsRoot . '/wordpress/core/7.1.3/license.txt', $this->flattenTo . '/wp-load.php');
        file_put_contents($pulledDirectory . '/pulled-only.php', '<?php // pulled');

        $this->flatten(['core_files' => 'copy']);

        $this->assertSame('GPL', file_get_contents($this->fsRoot . '/wordpress/core/7.1.3/license.txt'));
        $this->assertSame('<?php // wp-load', file_get_contents($pulledFile));
        $this->assertFileExists($pulledDirectory . '/pulled-only.php');
        $this->assertFalse(is_link($this->flattenTo . '/wp-load.php'));
    }

    public function testCopyRefusesToFlattenIntoThePulledAbspath(): void
    {
        $this->createWpCloudLayout();
        $this->flattenTo = $this->fsRoot . '/wordpress/core/7.1.3';

        try {
            $this->flatten(['core_files' => 'copy']);
            $this->fail('Copying core onto itself should be rejected');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('ABSPATH', $exception->getMessage());
        }

        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
    }

    public function testKeepsSiteConfigFilesLinkedButCopiesSampleConfig(): void
    {
        $abspath = $this->fsRoot . '/var/www/html';
        $this->createCoreTree($abspath, '7.1.3');
        file_put_contents($abspath . '/wp-config-sample.php', '<?php // sample');
        file_put_contents($abspath . '/wp-config-local.php', '<?php // local settings');
        $this->writePreflight(['abspath' => '/var/www/html']);

        $this->flatten(['core_files' => 'copy']);

        $this->assertFalse(is_link($this->flattenTo . '/wp-config-sample.php'), 'wp-config-sample.php ships with core');
        $this->assertTrue(is_link($this->flattenTo . '/wp-config-local.php'), 'wp-config-local.php belongs to the site');
    }

    public function testKeepsCoreEntriesThatAreSymlinksOnTheSourceLinked(): void
    {
        $this->createCoreTree($this->fsRoot . '/srv/htdocs/__wp__', '7.1.3');
        $abspath = $this->fsRoot . '/srv/htdocs';
        unlink($abspath . '/__wp__/index.php');
        symlink('__wp__/wp-load.php', $abspath . '/wp-load.php');
        file_put_contents($abspath . '/index.php', '<?php // site index');
        $this->writePreflight(['abspath' => '/srv/htdocs']);

        $this->flatten(['core_files' => 'copy']);

        $this->assertTrue(is_link($this->flattenTo . '/wp-load.php'), 'a symlinked core entry should stay a link');
        $this->assertSame('<?php // wp-load', file_get_contents($this->flattenTo . '/wp-load.php'));
        $this->assertFalse(is_link($this->flattenTo . '/index.php'));
    }

    public function testRejectsInvalidCoreFilesValue(): void
    {
        $this->createWpCloudLayout();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--core-files');

        $this->flatten(['core_files' => 'hardlink']);
    }

    // ---- helpers ----

    /**
     * WP Cloud layout: versioned core outside the document root, wp-content
     * in the document root.
     */
    private function createWpCloudLayout(): void
    {
        $this->createCoreTree($this->fsRoot . '/wordpress/core/7.1.3', '7.1.3');
        mkdir($this->fsRoot . '/srv/htdocs/wp-content/themes', 0755, true);
        file_put_contents($this->fsRoot . '/srv/htdocs/wp-content/themes/theme.txt', 'theme');
        $this->writePreflight([
            'abspath' => '/wordpress/core/7.1.3',
            'wp_admin_path' => '/wordpress/core/7.1.3/wp-admin',
            'wp_includes_path' => '/wordpress/core/7.1.3/wp-includes',
            'content_dir' => '/srv/htdocs/wp-content',
            'plugins_dir' => '/srv/htdocs/wp-content/plugins',
            'mu_plugins_dir' => '/srv/htdocs/wp-content/mu-plugins',
        ]);
    }

    private function createCoreTree(string $directory, string $version): void
    {
        mkdir($directory . '/wp-admin', 0755, true);
        mkdir($directory . '/wp-includes', 0755, true);
        file_put_contents($directory . '/wp-admin/admin.php', '<?php // admin');
        file_put_contents($directory . '/wp-includes/version.php', "<?php // version {$version}");
        file_put_contents($directory . '/index.php', '<?php // index');
        file_put_contents($directory . '/wp-load.php', '<?php // wp-load');
        file_put_contents($directory . '/wp-settings.php', '<?php // wp-settings');
        file_put_contents($directory . '/xmlrpc.php', '<?php // xmlrpc');
        file_put_contents($directory . '/license.txt', 'GPL');
        file_put_contents($directory . '/readme.html', '<html></html>');
    }

    private function writePreflight(array $paths_urls): void
    {
        \write_current_pull_state($this->makeClient(), [
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'table_prefix' => 'wp_',
                            'paths_urls' => $paths_urls,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function flatten(array $options): void
    {
        $client = $this->makeClient();
        $state = $this->callPrivate($client, 'load_state');
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('state');
        $property->setValue($client, $state);
        $this->callPrivate($client, 'run_flat_document_root', [
            array_merge(['flatten_to' => $this->flattenTo, 'force' => false], $options),
        ]);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
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
            $this->recursiveDelete($path);
        }
        rmdir($dir);
    }
}
