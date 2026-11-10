<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote literal filesystem paths in the generated test router.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * files-pull --include=:wp-core: against the real exporter.
 *
 * The server indexes all of ABSPATH; the client pulls only core entries from
 * it, so a source without an optional core file needs no special handling and
 * site files next to core stay untouched locally.
 */
final class WpCoreFilesPullTest extends TestCase {

    private string $root;
    private string $source;
    private string $stateDirectory;
    private string $fsRoot;
    private string $url;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wp-core-files-pull-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/source', 0700, true);
        $this->source = realpath($this->root . '/source');
        $this->stateDirectory = $this->root . '/state';
        $this->fsRoot = $this->root . '/files';
        mkdir($this->fsRoot, 0700, true);
        $this->url = $this->startExporter();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPullsCoreAndContentButNotSiteFilesOnAStandardHost(): void
    {
        $abspath = $this->source . '/html';
        // readme.html and license.txt are absent, as after common hardening.
        $this->writeCore($abspath, '7.1.4', array('readme.html', 'license.txt'));
        $this->writeFiles($abspath, array(
            'wp-config.php' => 'site config',
            '.htaccess' => 'rules',
            'robots.txt' => 'robots',
            'wp-salt.php' => 'required by wp-config.php',
            'custom/data.txt' => 'custom',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));

        $this->pull(array('--include=:wp-content:', '--include=:wp-core:'));

        $local = $this->fsRoot . $abspath;
        $this->assertSame('7.1.4', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertSame('core', file_get_contents($local . '/wp-admin/index.php'));
        foreach (array('index.php', 'wp-load.php', 'wp-settings.php', 'wp-config-sample.php', 'xmlrpc.php') as $core_file) {
            $this->assertFileExists($local . '/' . $core_file);
        }
        $this->assertSame('plugin', file_get_contents($local . '/wp-content/plugins/a/a.php'));
        foreach (array('wp-config.php', '.htaccess', 'robots.txt', 'wp-salt.php', 'custom') as $site_path) {
            $this->assertFileDoesNotExist($local . '/' . $site_path);
        }
    }

    public function testPullsNoSiteFilesThroughSymlinksUnderAbspath(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'shared/linked/p.php' => 'linked plugin',
            'unselected/u.php' => 'unselected',
            'custom/data.txt' => 'custom',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        $this->writeFiles($this->source, array('outside/o.php' => 'outside'));
        // A selected symlink to a directory next to core: only core is
        // selected there, so the target is not pulled.
        symlink('../../shared/linked', $abspath . '/wp-content/plugins/linked');
        // Symlinks in an unselected directory select nothing, under ABSPATH or outside it.
        symlink('../unselected', $abspath . '/custom/unselected');
        symlink('../../outside', $abspath . '/custom/outside');
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));

        $this->pull(array('--include=:wp-content:', '--include=:wp-core:'));

        $local = $this->fsRoot . $abspath;
        $this->assertSame('plugin', file_get_contents($local . '/wp-content/plugins/a/a.php'));
        $this->assertTrue(is_link($local . '/wp-content/plugins/linked'));
        $this->assertFileDoesNotExist($local . '/shared');
        $this->assertSame('7.1.4', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertFileDoesNotExist($local . '/custom');
        $this->assertFileDoesNotExist($local . '/unselected');
        $this->assertFileDoesNotExist($this->fsRoot . $this->source . '/outside');
    }

    public function testSymlinksToAbspathOrItsParentSelectNoSiteFiles(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'wp-config.php' => 'site config',
            'custom/data.txt' => 'custom',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        $this->writeFiles($this->source, array('beside/b.txt' => 'beside'));
        symlink('../..', $abspath . '/wp-content/plugins/root');
        symlink('../../..', $abspath . '/wp-content/plugins/parent');
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));

        $this->pull(array('--include=:wp-content:', '--include=:wp-core:'));

        $local = $this->fsRoot . $abspath;
        $this->assertTrue(is_link($local . '/wp-content/plugins/root'));
        $this->assertSame('7.1.4', file_get_contents($local . '/wp-content/plugins/root/wp-includes/version.php'));
        $this->assertFileDoesNotExist($local . '/wp-config.php');
        $this->assertFileDoesNotExist($local . '/custom');
        $this->assertFileDoesNotExist($this->fsRoot . $this->source . '/beside');
    }

    public function testExcludingWpCorePullsEverySiteFileButNoCore(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'wp-config.php' => 'site config',
            '.htaccess' => 'rules',
            'wp-salt.php' => 'required by wp-config.php',
            'custom/wp-load.php' => 'not core here',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));

        $this->pull(array('--exclude=:wp-core:'));

        $local = $this->fsRoot . $abspath;
        $this->assertSame('site config', file_get_contents($local . '/wp-config.php'));
        $this->assertSame('rules', file_get_contents($local . '/.htaccess'));
        $this->assertSame('required by wp-config.php', file_get_contents($local . '/wp-salt.php'));
        $this->assertSame('not core here', file_get_contents($local . '/custom/wp-load.php'));
        $this->assertSame('plugin', file_get_contents($local . '/wp-content/plugins/a/a.php'));
        foreach (array('wp-admin', 'wp-includes', 'index.php', 'wp-load.php', 'wp-login.php', 'xmlrpc.php', 'readme.html', 'wp-config-sample.php') as $core_path) {
            $this->assertFileDoesNotExist($local . '/' . $core_path);
        }
    }

    public function testMirrorWithoutWpCoreLeavesLocallySuppliedCoreAlone(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            '.htaccess' => 'rules',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));
        $arguments = array('--exclude=:wp-core:', '--mode=mirror');
        $this->pull($arguments);

        // The client supplies its own core, and adds a stray site file.
        $local = $this->fsRoot . $abspath;
        $this->writeCore($local, 'local 7.1.4');
        $this->writeFiles($local, array('stray.txt' => 'stray'));
        $this->writeFiles($abspath, array(
            'wp-includes/version.php' => '7.2-beta4',
            '.htaccess' => 'changed rules',
        ));
        unlink($abspath . '/wp-links-opml.php');

        $this->pull(array('--abort'));
        $this->pull($arguments);

        $this->assertSame('local 7.1.4', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertFileExists($local . '/wp-links-opml.php');
        $this->assertSame('changed rules', file_get_contents($local . '/.htaccess'));
        $this->assertFileDoesNotExist($local . '/stray.txt');
    }

    public function testPullsOnlyTheActiveCoreVersionFromASharedCoreDirectory(): void
    {
        $active_abspath = $this->source . '/wordpress/core/7.1.4';
        $this->writeCore($active_abspath, '7.1.4');
        $this->writeCore($this->source . '/wordpress/core/7.0.8', '7.0.8');
        $this->writeFiles($this->source, array(
            'wordpress/core/wp-config.php' => 'site config',
            'srv/htdocs/wp-content/themes/t/style.css' => 'theme',
        ));
        $this->writePreflight(array(
            'abspath' => $active_abspath,
            'wp_admin_path' => $active_abspath . '/wp-admin',
            'wp_includes_path' => $active_abspath . '/wp-includes',
            'content_dir' => $this->source . '/srv/htdocs/wp-content',
        ));

        $this->pull(array('--include=:wp-content:', '--include=:wp-core:'));

        $local = $this->fsRoot . $this->source;
        $this->assertSame('7.1.4', file_get_contents($local . '/wordpress/core/7.1.4/wp-includes/version.php'));
        $this->assertFileExists($local . '/wordpress/core/7.1.4/wp-login.php');
        $this->assertSame('theme', file_get_contents($local . '/srv/htdocs/wp-content/themes/t/style.css'));
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.0.8');
        $this->assertFileDoesNotExist($local . '/wordpress/core/wp-config.php');
    }

    /**
     * @dataProvider pullModes
     * @param list<string> $mode_arguments
     */
    public function testRemovesTheEarlierCoreVersionAfterAnUpgrade(array $mode_arguments): void
    {
        $content_dir = $this->source . '/srv/htdocs/wp-content';
        $this->writeFiles($content_dir, array('themes/t/style.css' => 'theme'));
        $this->writeFiles($this->source, array('wordpress/core/wp-config.php' => 'site config'));
        $old_abspath = $this->source . '/wordpress/core/7.1.4';
        $new_abspath = $this->source . '/wordpress/core/7.1.5';
        $this->writeCore($old_abspath, '7.1.4');
        $this->writeSharedCorePreflight($old_abspath, $content_dir, true);
        $arguments = array_merge(array('--include=:wp-content:', '--include=:wp-core:'), $mode_arguments);
        $this->pull($arguments);

        $local = $this->fsRoot . $this->source;
        $this->assertSame('7.1.4', file_get_contents($local . '/wordpress/core/7.1.4/wp-includes/version.php'));

        // The host installs 7.1.5 next to 7.1.4 and switches the site to it.
        $this->writeCore($new_abspath, '7.1.5');
        $this->writeSharedCorePreflight($new_abspath, $content_dir, false);
        $this->pull(array('--abort'));
        $this->pull($arguments);

        $this->assertSame('7.1.5', file_get_contents($local . '/wordpress/core/7.1.5/wp-includes/version.php'));
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.1.4');
        $this->assertSame('theme', file_get_contents($local . '/srv/htdocs/wp-content/themes/t/style.css'));
    }

    /** @return array<string, array{list<string>}> */
    public static function pullModes(): array
    {
        return array(
            'catch-up' => array(array()),
            'mirror' => array(array('--mode=mirror')),
        );
    }

    /**
     * The earlier ABSPATH also holds site files, so only its core may go.
     *
     * @dataProvider pullModes
     * @param list<string> $mode_arguments
     */
    public function testRemovingTheEarlierCoreKeepsSiteFilesInAndBesideIt(array $mode_arguments): void
    {
        $old_abspath = $this->source . '/a/html';
        $this->writeCore($old_abspath, '7.1.4');
        $this->writeFiles($old_abspath, array('wp-content/plugins/p/p.php' => 'plugin'));
        $this->writePreflight(array(
            'abspath' => $old_abspath,
            'content_dir' => $old_abspath . '/wp-content',
        ));
        $arguments = array_merge(array('--include=:wp-content:', '--include=:wp-core:'), $mode_arguments);
        $this->pull($arguments);

        $local_old_abspath = $this->fsRoot . $old_abspath;
        $this->writeFiles($local_old_abspath, array(
            'wp-config.php' => 'local config',
            'mine.txt' => 'local file next to core',
        ));
        $this->writeFiles($this->fsRoot . $this->source . '/a', array('beside.txt' => 'local file beside ABSPATH'));

        // The site moves to another document root behind the same address.
        $new_abspath = $this->source . '/b/html';
        $this->writeCore($new_abspath, '7.1.4');
        $this->writeFiles($new_abspath, array('wp-content/plugins/p/p.php' => 'plugin'));
        $this->removeTree($this->source . '/a');
        $this->replacePreflight(array(
            'abspath' => $new_abspath,
            'content_dir' => $new_abspath . '/wp-content',
        ));
        $this->pull(array('--abort'));
        $this->pull($arguments);

        $this->assertSame('7.1.4', file_get_contents($this->fsRoot . $new_abspath . '/wp-includes/version.php'));
        $this->assertDirectoryDoesNotExist($local_old_abspath . '/wp-includes');
        $this->assertDirectoryDoesNotExist($local_old_abspath . '/wp-admin');
        $this->assertFileDoesNotExist($local_old_abspath . '/wp-load.php');
        $this->assertSame('local config', file_get_contents($local_old_abspath . '/wp-config.php'));
        $this->assertSame('local file next to core', file_get_contents($local_old_abspath . '/mine.txt'));
        $this->assertSame('plugin', file_get_contents($local_old_abspath . '/wp-content/plugins/p/p.php'));
        $this->assertSame('local file beside ABSPATH', file_get_contents($this->fsRoot . $this->source . '/a/beside.txt'));
    }

    public function testRemovesCoreFromAnAbortedPullAfterAnUpgrade(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }
        $content_dir = $this->source . '/srv/htdocs/wp-content';
        $this->writeFiles($content_dir, array('themes/t/style.css' => 'theme'));
        $old_abspath = $this->source . '/wordpress/core/7.1.4';
        $this->writeCore($old_abspath, '7.1.4');
        $this->writeSharedCorePreflight($old_abspath, $content_dir, true);
        $arguments = array('--include=:wp-content:', '--include=:wp-core:', '--mode=mirror');

        // The first pull stops partway: it cannot write into wp-includes.
        $local_old_abspath = $this->fsRoot . $old_abspath;
        mkdir($local_old_abspath . '/wp-includes', 0755, true);
        chmod($local_old_abspath . '/wp-includes', 0555);
        try {
            $output = $this->pullExpectingFailure($arguments);
        } finally {
            chmod($local_old_abspath . '/wp-includes', 0755);
        }
        $this->assertFileExists($local_old_abspath . '/wp-admin/index.php', $output);
        $this->assertFileDoesNotExist($local_old_abspath . '/wp-includes/version.php');
        $this->pull(array('--abort'));

        $new_abspath = $this->source . '/wordpress/core/7.1.5';
        $this->writeCore($new_abspath, '7.1.5');
        $this->writeSharedCorePreflight($new_abspath, $content_dir, false);
        $this->pull($arguments);

        $local = $this->fsRoot . $this->source;
        $this->assertSame('7.1.5', file_get_contents($local . '/wordpress/core/7.1.5/wp-includes/version.php'));
        // The test made wp-includes, so only that empty directory may remain.
        $remaining_files = iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($local_old_abspath, \FilesystemIterator::SKIP_DOTS)
        ), false);
        $this->assertSame(array(), $remaining_files);
    }

    public function testAnInterruptedWpCorePullResumesAndCompletes(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }
        $content_dir = $this->source . '/srv/htdocs/wp-content';
        $this->writeFiles($content_dir, array('themes/t/style.css' => 'theme'));
        $abspath = $this->source . '/wordpress/core/7.1.4';
        $this->writeCore($abspath, '7.1.4');
        $this->writeCore($this->source . '/wordpress/core/7.0.8', '7.0.8');
        $this->writeSharedCorePreflight($abspath, $content_dir, true);
        $arguments = array('--include=:wp-content:', '--include=:wp-core:', '--mode=mirror');

        $local_abspath = $this->fsRoot . $abspath;
        mkdir($local_abspath . '/wp-includes', 0755, true);
        chmod($local_abspath . '/wp-includes', 0555);
        try {
            $output = $this->pullExpectingFailure($arguments);
        } finally {
            chmod($local_abspath . '/wp-includes', 0755);
        }
        $this->assertFileDoesNotExist($local_abspath . '/wp-includes/version.php', $output);

        // The same arguments resume the pull rather than start a new one.
        $this->pull($arguments);

        $local = $this->fsRoot . $this->source;
        $this->assertSame('7.1.4', file_get_contents($local_abspath . '/wp-includes/version.php'));
        $this->assertFileExists($local_abspath . '/wp-load.php');
        $this->assertSame('theme', file_get_contents($local . '/srv/htdocs/wp-content/themes/t/style.css'));
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.0.8');
    }

    public function testRemovesEveryEarlierCoreAfterAnAbortedUpgradePull(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }
        $content_dir = $this->source . '/srv/htdocs/wp-content';
        $this->writeFiles($content_dir, array('themes/t/style.css' => 'theme'));
        $this->writeCore($this->source . '/wordpress/core/7.1.4', '7.1.4');
        $this->writeSharedCorePreflight($this->source . '/wordpress/core/7.1.4', $content_dir, true);
        $arguments = array('--include=:wp-content:', '--include=:wp-core:', '--mode=mirror');
        $this->pull($arguments);

        // The pull for 7.1.5 stops partway and is aborted.
        $this->writeCore($this->source . '/wordpress/core/7.1.5', '7.1.5');
        $this->writeSharedCorePreflight($this->source . '/wordpress/core/7.1.5', $content_dir, false);
        $local_core = $this->fsRoot . $this->source . '/wordpress/core';
        mkdir($local_core . '/7.1.5/wp-includes', 0755, true);
        chmod($local_core . '/7.1.5/wp-includes', 0555);
        $this->pull(array('--abort'));
        try {
            $output = $this->pullExpectingFailure($arguments);
        } finally {
            chmod($local_core . '/7.1.5/wp-includes', 0755);
        }
        $this->assertFileExists($local_core . '/7.1.5/wp-admin/index.php', $output);
        $this->pull(array('--abort'));

        $this->writeCore($this->source . '/wordpress/core/7.1.6', '7.1.6');
        $this->writeSharedCorePreflight($this->source . '/wordpress/core/7.1.6', $content_dir, false);
        $this->pull($arguments);

        $this->assertSame('7.1.6', file_get_contents($local_core . '/7.1.6/wp-includes/version.php'));
        $this->assertDirectoryDoesNotExist($local_core . '/7.1.4');
        // The test made 7.1.5/wp-includes, so only that empty directory may remain.
        $remaining_files = iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($local_core . '/7.1.5', \FilesystemIterator::SKIP_DOTS)
        ), false);
        $this->assertSame(array(), $remaining_files);
    }

    /**
     * ABSPATH is the document root, and its wp-admin and wp-includes link
     * into a versioned core directory.
     *
     * @dataProvider pullModes
     * @param list<string> $mode_arguments
     */
    public function testPullsAndUpgradesCoreLinkedIntoAbspath(array $mode_arguments): void
    {
        $abspath = $this->source . '/srv/htdocs';
        $this->writeFiles($abspath, array(
            'wp-config.php' => 'site config',
            'index.php' => 'core',
            'wp-load.php' => 'core',
            'wp-content/themes/t/style.css' => 'theme',
        ));
        $link_core_version = function (string $version) use ($abspath): void {
            foreach (array('wp-admin', 'wp-includes') as $directory) {
                @unlink($abspath . '/' . $directory);
                symlink('../../wordpress/core/' . $version . '/' . $directory, $abspath . '/' . $directory);
            }
            $this->replacePreflight(array(
                'abspath' => $abspath,
                'wp_admin_path' => $this->source . '/wordpress/core/' . $version . '/wp-admin',
                'wp_includes_path' => $this->source . '/wordpress/core/' . $version . '/wp-includes',
                'content_dir' => $abspath . '/wp-content',
            ));
        };
        $this->writeCore($this->source . '/wordpress/core/7.1.4', '7.1.4');
        $this->writeCore($this->source . '/wordpress/core/7.0.8', '7.0.8');
        $this->writePreflight(array('abspath' => $abspath));
        $link_core_version('7.1.4');
        $arguments = array_merge(array('--include=:wp-content:', '--include=:wp-core:'), $mode_arguments);
        $this->pull($arguments);

        $local = $this->fsRoot . $this->source;
        $this->assertSame('7.1.4', file_get_contents($local . '/srv/htdocs/wp-includes/version.php'));
        $this->assertSame('core', file_get_contents($local . '/srv/htdocs/wp-load.php'));
        $this->assertSame('theme', file_get_contents($local . '/srv/htdocs/wp-content/themes/t/style.css'));
        $this->assertFileDoesNotExist($local . '/srv/htdocs/wp-config.php');
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.0.8');

        $this->writeCore($this->source . '/wordpress/core/7.1.5', '7.1.5');
        $link_core_version('7.1.5');
        $this->pull(array('--abort'));
        $this->pull($arguments);

        $this->assertSame('7.1.5', file_get_contents($local . '/srv/htdocs/wp-includes/version.php'));
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.1.4');
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.0.8');
    }

    public function testExcludingWpCoreOnASharedCoreDirectoryKeepsOtherVersions(): void
    {
        $content_dir = $this->source . '/srv/htdocs/wp-content';
        $this->writeFiles($content_dir, array('themes/t/style.css' => 'theme'));
        $this->writeFiles($this->source, array('wordpress/core/wp-config.php' => 'site config'));
        $this->writeCore($this->source . '/wordpress/core/7.1.4', '7.1.4');
        $this->writeCore($this->source . '/wordpress/core/7.0.8', '7.0.8');
        $abspath = $this->source . '/wordpress/core/7.1.4';
        // As on WP Cloud, the shared core directory and the document root
        // are both roots, so every core version is indexed.
        $this->writePreflight(
            array(
                'abspath' => $abspath,
                'wp_admin_path' => $abspath . '/wp-admin',
                'wp_includes_path' => $abspath . '/wp-includes',
                'content_dir' => $content_dir,
            ),
            array($this->source . '/wordpress/core', $this->source . '/srv/htdocs')
        );

        $this->pull(array('--exclude=:wp-core:'));

        $local = $this->fsRoot . $this->source;
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.1.4/wp-includes');
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.1.4/wp-admin');
        $this->assertFileDoesNotExist($local . '/wordpress/core/7.1.4/wp-load.php');
        $this->assertSame('7.0.8', file_get_contents($local . '/wordpress/core/7.0.8/wp-includes/version.php'));
        $this->assertSame('site config', file_get_contents($local . '/wordpress/core/wp-config.php'));
        $this->assertSame('theme', file_get_contents($local . '/srv/htdocs/wp-content/themes/t/style.css'));
    }

    public function testAPullWithoutWpCoreLeavesTheEarlierCoreVersion(): void
    {
        $content_dir = $this->source . '/srv/htdocs/wp-content';
        $this->writeFiles($content_dir, array('themes/t/style.css' => 'theme'));
        $old_abspath = $this->source . '/wordpress/core/7.1.4';
        $this->writeCore($old_abspath, '7.1.4');
        $this->writeSharedCorePreflight($old_abspath, $content_dir, true);
        $this->pull(array('--include=:wp-content:', '--include=:wp-core:', '--mode=mirror'));

        $new_abspath = $this->source . '/wordpress/core/7.1.5';
        $this->writeCore($new_abspath, '7.1.5');
        $this->writeSharedCorePreflight($new_abspath, $content_dir, false);
        $this->pull(array('--abort'));
        $this->pull(array('--include=:wp-content:', '--mode=mirror'));

        $local = $this->fsRoot . $this->source;
        $this->assertSame('7.1.4', file_get_contents($local . '/wordpress/core/7.1.4/wp-includes/version.php'));
        $this->assertDirectoryDoesNotExist($local . '/wordpress/core/7.1.5');
    }

    public function testMirrorUpdatesCoreAndLeavesLocalSiteFilesNextToCore(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array('wp-config.php' => 'remote config'));
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));
        $this->pull(array('--include=:wp-core:', '--mode=mirror'));

        $local = $this->fsRoot . $abspath;
        $this->writeFiles($local, array(
            'wp-config.php' => 'local config',
            'local-only.txt' => 'local',
            'wp-includes/local-only.php' => 'stray core file',
            'wp-salt.php' => 'local site file named like core',
        ));
        $this->writeFiles($abspath, array(
            'wp-includes/version.php' => '7.2-beta4',
            'wp-config.php' => 'changed remote config',
        ));
        unlink($abspath . '/wp-links-opml.php');

        $this->pull(array('--abort'));
        $this->pull(array('--include=:wp-core:', '--mode=mirror'));

        $this->assertSame('7.2-beta4', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertFileDoesNotExist($local . '/wp-links-opml.php');
        $this->assertFileDoesNotExist($local . '/wp-includes/local-only.php');
        $this->assertSame('local config', file_get_contents($local . '/wp-config.php'));
        $this->assertSame('local', file_get_contents($local . '/local-only.txt'));
        $this->assertSame('local site file named like core', file_get_contents($local . '/wp-salt.php'));
    }

    public function testMirrorPullsCanSwitchWpCoreOnAndOff(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array('wp-content/plugins/a/a.php' => 'plugin'));
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));
        $local = $this->fsRoot . $abspath;
        $without_core = array('--include=:wp-content:', '--mode=mirror');
        $with_core = array('--include=:wp-content:', '--include=:wp-core:', '--mode=mirror');

        $this->pull($without_core);
        $this->assertFileDoesNotExist($local . '/wp-includes/version.php');

        $this->pull(array('--abort'));
        $this->pull($with_core);
        $this->assertSame('7.1.4', file_get_contents($local . '/wp-includes/version.php'));

        // A pull without core leaves the core an earlier pull downloaded.
        $this->writeFiles($abspath, array('wp-content/plugins/a/a.php' => 'plugin, updated'));
        $this->pull(array('--abort'));
        $this->pull($without_core);
        $this->assertSame('plugin, updated', file_get_contents($local . '/wp-content/plugins/a/a.php'));
        $this->assertSame('7.1.4', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertFileExists($local . '/wp-links-opml.php');

        // Selecting core again brings that copy in line with the source's.
        $this->writeFiles($abspath, array(
            'wp-includes/version.php' => '7.2',
            'wp-includes/new.php' => 'new in 7.2',
        ));
        unlink($abspath . '/wp-links-opml.php');
        $this->pull(array('--abort'));
        $this->pull($with_core);
        $this->assertSame('7.2', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertSame('new in 7.2', file_get_contents($local . '/wp-includes/new.php'));
        $this->assertFileDoesNotExist($local . '/wp-links-opml.php');
        $this->assertSame('plugin, updated', file_get_contents($local . '/wp-content/plugins/a/a.php'));
    }

    /**
     * Writes a small WordPress core tree. Every top-level core entry the
     * classification knows about is present unless listed in $omitted.
     *
     * @param list<string> $omitted Top-level core entries to leave out.
     */
    private function writeCore(string $abspath, string $version, array $omitted = array()): void
    {
        $files = array(
            'wp-admin/index.php' => 'core',
            'wp-includes/version.php' => $version,
        );
        foreach (array('index.php', 'license.txt', 'readme.html', 'wp-config-sample.php', 'wp-load.php', 'wp-settings.php', 'wp-links-opml.php', 'wp-login.php', 'xmlrpc.php') as $name) {
            if (!in_array($name, $omitted, true)) {
                $files[$name] = 'core';
            }
        }
        $this->writeFiles($abspath, $files);
    }

    /** @param array<string,string> $files Relative path => contents. */
    private function writeFiles(string $directory, array $files): void
    {
        foreach ($files as $relative_path => $contents) {
            $path = $directory . '/' . $relative_path;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $contents);
        }
    }

    /**
     * @param array<string,string> $paths_urls Preflight paths_urls for the source site.
     * @param list<string>|null    $root_paths Detected WordPress roots; ABSPATH when null.
     */
    private function writePreflight(array $paths_urls, ?array $root_paths = null): void
    {
        $root_paths = $root_paths ?? array($paths_urls['abspath']);
        $client = new \ImportClient($this->url, $this->stateDirectory, $this->fsRoot, array('allow_http' => true));
        \write_current_pull_state($client, array(
            'preflight' => array(
                'http_code' => 200,
                'data' => array(
                    'ok' => true,
                    'runtime' => array('document_root' => $paths_urls['abspath']),
                    'wp_detect' => array('roots' => array_map(
                        function (string $root_path): array {
                            return array('path' => $root_path);
                        },
                        $root_paths
                    )),
                    'database' => array('wp' => array('paths_urls' => $paths_urls)),
                ),
            ),
        ));
    }

    /**
     * Points preflight at a versioned core directory, as on WP Cloud. Unless
     * $fresh_state, the rest of the pull state stays as earlier pulls left it.
     */
    private function writeSharedCorePreflight(string $abspath, string $content_dir, bool $fresh_state): void
    {
        $paths_urls = array(
            'abspath' => $abspath,
            'wp_admin_path' => $abspath . '/wp-admin',
            'wp_includes_path' => $abspath . '/wp-includes',
            'content_dir' => $content_dir,
        );
        if ($fresh_state) {
            $this->writePreflight($paths_urls);
            return;
        }
        $this->replacePreflight($paths_urls);
    }

    /**
     * Points preflight elsewhere and leaves the rest of the pull state as
     * earlier pulls left it.
     *
     * @param array<string,string> $paths_urls Preflight paths_urls for the source site.
     */
    private function replacePreflight(array $paths_urls): void
    {
        $abspath = $paths_urls['abspath'];
        $client = new \ImportClient($this->url, $this->stateDirectory, $this->fsRoot, array('allow_http' => true));
        $reflection = new \ReflectionClass($client);
        $state = $reflection->getMethod('load_state')->invoke($client);
        $preflight = $state->preflight_record();
        $preflight['data']['runtime']['document_root'] = $abspath;
        $preflight['data']['wp_detect']['roots'] = array(array('path' => $abspath));
        $preflight['data']['database']['wp']['paths_urls'] = $paths_urls;
        $state->set_preflight_record($preflight);
        $reflection->getProperty('state')->setValue($client, $state);
        $client->save_state();
    }

    /** @param list<string> $arguments Extra files-pull arguments. */
    private function pull(array $arguments): void
    {
        $process = proc_open(
            array_merge(
                array(
                    PHP_BINARY,
                    __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                    'files-pull',
                    $this->url,
                    '--state-dir=' . $this->stateDirectory,
                    '--fs-root=' . $this->fsRoot,
                    '--secret=test-secret',
                    '--allow-unsafe-http',
                ),
                $arguments
            ),
            array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')),
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output . (string) @file_get_contents($this->root . '/server.log'));
    }

    /**
     * @param list<string> $arguments Extra files-pull arguments.
     * @return string The pull's output.
     */
    private function pullExpectingFailure(array $arguments): string
    {
        $process = proc_open(
            array_merge(
                array(
                    PHP_BINARY,
                    __DIR__ . '/../../packages/reprint-client/bin/reprint-client',
                    'files-pull',
                    $this->url,
                    '--state-dir=' . $this->stateDirectory,
                    '--fs-root=' . $this->fsRoot,
                    '--secret=test-secret',
                    '--allow-unsafe-http',
                ),
                $arguments
            ),
            array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')),
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertNotSame(0, proc_close($process), $output);
        return $output;
    }

    private function startExporter(): string
    {
        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php '
            . ' require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; require ' . var_export(dirname(__DIR__, 2) . '/packages/reprint-server/src/export.php', true)
            . '; (new WordPress\\Reprint\\Server\\HTTPServer())->handle_request();');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertIsResource($listener, $error_message);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->server = proc_open(
            array(PHP_BINARY, '-S', $address, $router),
            array(0 => array('pipe', 'r'), 1 => array('file', $this->root . '/server.log', 'a'), 2 => array('file', $this->root . '/server.log', 'a')),
            $pipes
        );
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error_message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://' . $address . '/';
            }
            usleep(10000);
        }
        $this->fail('The exporter did not start: ' . file_get_contents($this->root . '/server.log'));
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: array() as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
