<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify run_flat_document_root() with --core-files=copy places real copies
 * of WordPress core entries in the flattened site and keeps every other
 * entry linked into the filesystem root, and that --core-files=use-existing leaves
 * the flattened site's own core in place.
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
            $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/' . $directory), "{$directory} should be a real directory");
            $this->assertDirectoryExists($this->flattenTo . '/' . $directory);
        }
        foreach (['index.php', 'wp-load.php', 'wp-settings.php', 'xmlrpc.php', 'license.txt', 'readme.html'] as $file) {
            $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/' . $file), "{$file} should be a real file");
        }
        $this->assertSame('<?php // admin', file_get_contents($this->flattenTo . '/wp-admin/admin.php'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));

        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-content'), 'wp-content should stay a symlink');
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

        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-admin'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/index.php'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-load.php'));
        foreach (['.htaccess', 'robots.txt', 'wp-config.php', 'wp-content'] as $siteEntry) {
            $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/' . $siteEntry), "{$siteEntry} should stay a symlink");
        }
    }

    public function testLinkModeIsTheDefault(): void
    {
        $this->createWpCloudLayout();

        $this->flatten([]);

        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-admin'));
        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/index.php'));
    }

    public function testReplacesLinksFromAnEarlierLinkModeRun(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'link']);
        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-includes'));

        $this->flatten(['core_files' => 'copy']);

        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-includes'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/index.php'));
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
        mkdir($this->tempDir . '/outside');
        \LocalLink::create_symlink($this->tempDir . '/outside', $this->fsRoot . '/wordpress/core/7.1.3/wp-includes/outside');

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
            $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/' . $directory), "{$directory} should be a real directory");
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

        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-includes'));
        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/index.php'));
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
        mkdir($this->tempDir . '/outside');
        \LocalLink::create_symlink($this->tempDir . '/outside', $this->flattenTo . '/wp-admin/stale-link');

        $this->flatten(['core_files' => 'copy']);

        $this->assertDirectoryDoesNotExist($this->flattenTo . '/wp-includes/stale');
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-admin/stale-link'));
        $this->assertDirectoryExists($this->tempDir . '/outside');
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
        $previousCore = $this->fsRoot . '/wordpress/core/7.0.7';
        $this->createCoreTree($previousCore, '7.0.7');
        file_put_contents($previousCore . '/wp-includes/only-in-7.0.7.php', '<?php // old');
        mkdir($this->flattenTo, 0755, true);
        \LocalLink::create_symlink($previousCore . '/wp-includes', $this->flattenTo . '/wp-includes');
        \LocalLink::create_symlink($previousCore . '/license.txt', $this->flattenTo . '/license.txt');
        file_put_contents($this->fsRoot . '/wordpress/core/7.1.3/license.txt', 'GPL 7.1.3');

        $this->flatten(['core_files' => 'copy']);

        $this->assertSame('<?php // version 7.0.7', file_get_contents($previousCore . '/wp-includes/version.php'));
        $this->assertFileExists($previousCore . '/wp-includes/only-in-7.0.7.php');
        $this->assertSame('GPL', file_get_contents($previousCore . '/license.txt'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-includes'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/license.txt'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertSame('GPL 7.1.3', file_get_contents($this->flattenTo . '/license.txt'));
    }

    public function testStopsWhenALinkCannotBeRemoved(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows chmod() sets only the read-only attribute of files.');
        }

        $this->createWpCloudLayout();
        $previousCore = $this->fsRoot . '/wordpress/core/7.0.7';
        $this->createCoreTree($previousCore, '7.0.7');
        $this->flatten(['core_files' => 'copy']);
        $this->recursiveDelete($this->flattenTo . '/wp-includes');
        \LocalLink::create_symlink($previousCore . '/wp-includes', $this->flattenTo . '/wp-includes');
        chmod($this->flattenTo, 0555);

        try {
            $this->flatten(['core_files' => 'copy']);
            $this->fail('A link which cannot be removed should stop the run');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('wp-includes', $exception->getMessage());
        } finally {
            chmod($this->flattenTo, 0755);
        }

        $this->assertSame('<?php // version 7.0.7', file_get_contents($previousCore . '/wp-includes/version.php'));
    }

    public function testStopsWhenAStaleFileCannotBeRemoved(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows chmod() sets only the read-only attribute of files.');
        }

        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'copy']);
        file_put_contents($this->flattenTo . '/wp-includes/stale.php', '<?php // stale');
        chmod($this->flattenTo . '/wp-includes', 0555);

        try {
            $this->flatten(['core_files' => 'copy']);
            $this->fail('A stale file which cannot be removed should stop the run');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('stale.php', $exception->getMessage());
        } finally {
            chmod($this->flattenTo . '/wp-includes', 0755);
        }
    }

    public function testAnInterruptedCopyLeavesThePreviousCoreVersion(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows chmod() sets only the read-only attribute of files.');
        }

        // On a standard layout wp-includes comes before wp-load.php,
        // wp-settings.php and xmlrpc.php in the copy.
        $abspath = $this->fsRoot . '/var/www/html';
        $this->createCoreTree($abspath, '7.1.3');
        $this->writePreflight(['abspath' => '/var/www/html']);
        $this->flatten(['core_files' => 'copy']);

        file_put_contents($abspath . '/wp-includes/version.php', '<?php // version 7.2');
        file_put_contents($abspath . '/wp-includes/added.php', '<?php // added');
        unlink($this->flattenTo . '/wp-settings.php');
        mkdir($this->flattenTo . '/wp-settings.php/locked', 0755, true);
        file_put_contents($this->flattenTo . '/wp-settings.php/locked/file', 'x');
        chmod($this->flattenTo . '/wp-settings.php/locked', 0555);

        try {
            $this->flatten(['core_files' => 'copy']);
            $this->fail('An entry which cannot be replaced should stop the run');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('wp-settings.php', $exception->getMessage());
        } finally {
            chmod($this->flattenTo . '/wp-settings.php/locked', 0755);
        }

        $this->assertFileExists($this->flattenTo . '/wp-includes/added.php');
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));

        $this->flatten(['core_files' => 'copy']);

        $this->assertSame('<?php // version 7.2', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertSame('<?php // wp-settings', file_get_contents($this->flattenTo . '/wp-settings.php'));
    }

    public function testCopyHandlesCaseOnlyRenameInCore(): void
    {
        $this->createWpCloudLayout();
        $this->flatten(['core_files' => 'copy']);
        $pulledIncludes = $this->fsRoot . '/wordpress/core/7.1.3/wp-includes';
        if (file_exists(strtoupper($this->tempDir) . '/FS-ROOT') === false) {
            $this->markTestSkipped('The filesystem is case-sensitive.');
        }
        file_put_contents($this->flattenTo . '/wp-includes/renamed.php', '<?php // old name');
        file_put_contents($pulledIncludes . '/Renamed.php', '<?php // new name');

        $this->flatten(['core_files' => 'copy']);

        $this->assertContains('Renamed.php', scandir($this->flattenTo . '/wp-includes'));
        $this->assertSame('<?php // new name', file_get_contents($this->flattenTo . '/wp-includes/Renamed.php'));
    }

    public function testCopyRejectsSymlinkedDetachedCoreDirectory(): void
    {
        $this->createCoreTree($this->fsRoot . '/wordpress/core/7.1.3', '7.1.3');
        mkdir($this->fsRoot . '/srv/htdocs/wp-content', 0755, true);
        \LocalLink::create_symlink($this->fsRoot . '/wordpress/core/7.1.3/wp-admin', $this->fsRoot . '/wordpress/wp-admin');
        $this->writePreflight([
            'abspath' => '/srv/htdocs',
            'wp_admin_path' => '/wordpress/wp-admin',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symlink');

        $this->flatten(['core_files' => 'copy']);
    }

    /**
     * Core copied over the document root would replace pulled files and
     * delete those it treats as stale, and --fs-root is what pull diffing reads.
     */
    public function testCopyRefusesToFlattenInsideTheFsRoot(): void
    {
        $this->createWpCloudLayout();
        $documentRoot = $this->fsRoot . '/srv/htdocs';
        mkdir($documentRoot . '/wp-admin', 0755, true);
        file_put_contents($documentRoot . '/index.php', '<?php // pulled');
        file_put_contents($documentRoot . '/wp-admin/custom.php', '<?php // pulled');
        $this->flattenTo = $documentRoot;

        try {
            $this->flatten(['core_files' => 'copy']);
            $this->fail('Flattening inside --fs-root should be refused');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('--fs-root', $exception->getMessage());
        }

        $this->assertSame('<?php // pulled', file_get_contents($documentRoot . '/index.php'));
        $this->assertSame('<?php // pulled', file_get_contents($documentRoot . '/wp-admin/custom.php'));
    }

    public function testCopyRefusesToFlattenIntoThePulledAbspath(): void
    {
        $this->createWpCloudLayout();
        $this->flattenTo = $this->fsRoot . '/wordpress/core/7.1.3';

        try {
            $this->flatten(['core_files' => 'copy']);
            $this->fail('Copying core onto itself should be rejected');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('--fs-root', $exception->getMessage());
        }

        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
    }

    public function testKeepsSiteConfigFilesLinkedButCopiesSampleConfig(): void
    {
        $abspath = $this->fsRoot . '/var/www/html';
        $this->createCoreTree($abspath, '7.1.3');
        file_put_contents($abspath . '/wp-config-sample.php', '<?php // sample');
        file_put_contents($abspath . '/wp-config-local.php', '<?php // local settings');
        file_put_contents($abspath . '/wp-salt.php', '<?php // salts');
        $this->writePreflight(['abspath' => '/var/www/html']);

        $this->flatten(['core_files' => 'copy']);

        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-config-sample.php'), 'wp-config-sample.php ships with core');
        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-config-local.php'), 'wp-config-local.php belongs to the site');
        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-salt.php'), 'wp-salt.php is named like core but belongs to the site');
    }

    public function testKeepsCoreEntriesThatAreSymlinksOnTheSourceLinked(): void
    {
        $this->createCoreTree($this->fsRoot . '/srv/htdocs/__wp__', '7.1.3');
        $abspath = $this->fsRoot . '/srv/htdocs';
        unlink($abspath . '/__wp__/index.php');
        \LocalLink::create_symlink(\LocalLink::native_link_value('__wp__/wp-load.php', $abspath . '/wp-load.php'), $abspath . '/wp-load.php');
        file_put_contents($abspath . '/index.php', '<?php // site index');
        $this->writePreflight(['abspath' => '/srv/htdocs']);

        $this->flatten(['core_files' => 'copy']);

        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-load.php'), 'a symlinked core entry should stay a link');
        $this->assertSame('<?php // wp-load', file_get_contents($this->flattenTo . '/wp-load.php'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/index.php'));
    }

    public function testRejectsInvalidCoreFilesValue(): void
    {
        $this->createWpCloudLayout();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --core-files value: hardlink. Expected link, copy or use-existing.');

        $this->flatten(['core_files' => 'hardlink']);
    }

    public function testUseExistingLeavesTheSitesCoreAndLinksSiteFiles(): void
    {
        $abspath = $this->fsRoot . '/var/www/html';
        $this->createCoreTree($abspath, '7.1.3');
        mkdir($abspath . '/wp-content', 0755, true);
        file_put_contents($abspath . '/.htaccess', '# rewrite rules');
        file_put_contents($abspath . '/wp-salt.php', '<?php // salts');
        $this->writePreflight(['abspath' => '/var/www/html']);
        $this->createCoreTree($this->flattenTo, '7.1.4');
        file_put_contents($this->flattenTo . '/index.php', '<?php // edited index');

        $this->flatten(['core_files' => 'use-existing']);

        // Core from an earlier pull in --fs-root must not replace the site's.
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-includes'));
        $this->assertSame('<?php // version 7.1.4', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/index.php'));
        $this->assertSame('<?php // edited index', file_get_contents($this->flattenTo . '/index.php'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($abspath . '/wp-includes/version.php'));
        // wp-salt.php is named like core, but the site's wp-config.php needs it.
        foreach (['.htaccess', 'wp-content', 'wp-salt.php'] as $siteEntry) {
            $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/' . $siteEntry), "{$siteEntry} should be a symlink");
        }
    }

    public function testUseExistingWorksWithoutAbspathInTheFsRoot(): void
    {
        mkdir($this->fsRoot . '/srv/htdocs/wp-content/themes', 0755, true);
        file_put_contents($this->fsRoot . '/srv/htdocs/wp-content/themes/theme.txt', 'theme');
        $this->writePreflight([
            'abspath' => '/wordpress/core/7.1.3',
            'wp_admin_path' => '/wordpress/core/7.1.3/wp-admin',
            'wp_includes_path' => '/wordpress/core/7.1.3/wp-includes',
            'content_dir' => '/srv/htdocs/wp-content',
        ]);
        $this->createCoreTree($this->flattenTo, '7.1.3');

        $this->flatten(['core_files' => 'use-existing']);

        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-content'));
        $this->assertSame('theme', file_get_contents($this->flattenTo . '/wp-content/themes/theme.txt'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-admin'));
        $this->assertSame('<?php // version 7.1.3', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
    }

    public function testUseExistingLeavesTheSitesWpAdminAndWpIncludesWhenDetached(): void
    {
        $this->createCoreTree($this->fsRoot . '/wordpress/core/7.1.3', '7.1.3');
        mkdir($this->fsRoot . '/srv/htdocs/wp-content', 0755, true);
        $this->writePreflight([
            'abspath' => '/srv/htdocs',
            'wp_admin_path' => '/wordpress/core/7.1.3/wp-admin',
            'wp_includes_path' => '/wordpress/core/7.1.3/wp-includes',
        ]);
        $this->createCoreTree($this->flattenTo, '7.1.4');

        $this->flatten(['core_files' => 'use-existing']);

        foreach (['wp-admin', 'wp-includes'] as $directory) {
            $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/' . $directory), "{$directory} should stay the site's own");
        }
        $this->assertSame('<?php // version 7.1.4', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
    }

    public function testUseExistingWithForceLeavesTheSitesCore(): void
    {
        $abspath = $this->fsRoot . '/var/www/html';
        $this->createCoreTree($abspath, '7.1.3');
        mkdir($abspath . '/wp-content', 0755, true);
        $this->writePreflight(['abspath' => '/var/www/html']);
        $this->createCoreTree($this->flattenTo, '7.1.4');
        mkdir($this->flattenTo . '/wp-content', 0755, true);

        $this->flatten(['core_files' => 'use-existing', 'force' => true]);

        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/wp-includes'));
        $this->assertSame('<?php // version 7.1.4', file_get_contents($this->flattenTo . '/wp-includes/version.php'));
        $this->assertFalse(\LocalLink::is_link($this->flattenTo . '/index.php'));
        $this->assertTrue(\LocalLink::is_link($this->flattenTo . '/wp-content'), '--force replaces the real wp-content with a link');
    }

    public function testUseExistingNeedsAbspathWhenWpContentLivesUnderIt(): void
    {
        $this->writePreflight(['abspath' => '/var/www/html']);
        $this->createCoreTree($this->flattenTo, '7.1.3');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WordPress ABSPATH directory not found');

        $this->flatten(['core_files' => 'use-existing']);
    }

    /**
     * @dataProvider coreFilesModesThatPlaceCore
     */
    public function testModesThatPlaceCoreStillNeedAbspathInTheFsRoot(string $coreFiles): void
    {
        mkdir($this->fsRoot . '/srv/htdocs/wp-content', 0755, true);
        $this->writePreflight([
            'abspath' => '/wordpress/core/7.1.3',
            'content_dir' => '/srv/htdocs/wp-content',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WordPress ABSPATH directory not found');

        $this->flatten(['core_files' => $coreFiles]);
    }

    public static function coreFilesModesThatPlaceCore(): array
    {
        return ['link' => ['link'], 'copy' => ['copy']];
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
        if (!is_dir($dir) || \LocalLink::is_link($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (\LocalLink::is_link($path)) {
                \LocalLink::remove_symlink($path);
                continue;
            }
            if (is_file($path)) {
                unlink($path);
                continue;
            }
            $this->recursiveDelete($path);
        }
        rmdir($dir);
    }
}
