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
final class WpCoreFilesPullTest extends TestCase
{
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
        foreach (array('wp-config.php', '.htaccess', 'robots.txt', 'custom') as $site_path) {
            $this->assertFileDoesNotExist($local . '/' . $site_path);
        }
    }

    public function testPullsSymlinkTargetsUnderAbspathOnlyForSelectedSymlinks(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'shared/linked/p.php' => 'linked plugin',
            'chained/c.php' => 'chained',
            'unselected/u.php' => 'unselected',
            'custom/data.txt' => 'custom',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        $this->writeFiles($this->source, array('outside/o.php' => 'outside'));
        // A chain: wp-content -> shared/linked -> chained.
        symlink('../../shared/linked', $abspath . '/wp-content/plugins/linked');
        symlink('../../chained', $abspath . '/shared/linked/chained');
        // Symlinks in an unselected directory select nothing, under ABSPATH or outside it.
        symlink('../unselected', $abspath . '/custom/unselected');
        symlink('../../outside', $abspath . '/custom/outside');
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));

        $this->pull(array('--include=:wp-content:', '--include=:wp-core:'));

        $local = $this->fsRoot . $abspath;
        $this->assertSame('linked plugin', file_get_contents($local . '/wp-content/plugins/linked/p.php'));
        $this->assertSame('chained', file_get_contents($local . '/wp-content/plugins/linked/chained/c.php'));
        $this->assertSame('linked plugin', file_get_contents($local . '/shared/linked/p.php'));
        $this->assertSame('chained', file_get_contents($local . '/chained/c.php'));
        $this->assertSame('7.1.4', file_get_contents($local . '/wp-includes/version.php'));
        $this->assertFileDoesNotExist($local . '/custom');
        $this->assertFileDoesNotExist($local . '/unselected');
        $this->assertFileDoesNotExist($this->fsRoot . $this->source . '/outside');
    }

    public function testPullsSymlinksThatASelectedSymlinkReachesThrough(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'hopped/h.php' => 'hopped',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        // wp-content/plugins/hop -> link1 -> link2 -> hopped, every hop in ABSPATH.
        symlink('hopped', $abspath . '/link2');
        symlink('link2', $abspath . '/link1');
        symlink('../../link1', $abspath . '/wp-content/plugins/hop');
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));

        $this->pull(array('--include=:wp-content:', '--include=:wp-core:'));

        $local = $this->fsRoot . $abspath;
        $this->assertSame('hopped', file_get_contents($local . '/wp-content/plugins/hop/h.php'));
        $this->assertSame('link2', readlink($local . '/link1'));
        $this->assertSame('hopped', readlink($local . '/link2'));
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

    public function testMirrorKeepsAndUpdatesSymlinkTargetsUnderAbspath(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'shared/linked/p.php' => 'linked plugin',
            'wp-content/plugins/a/a.php' => 'plugin',
        ));
        symlink('../../shared/linked', $abspath . '/wp-content/plugins/linked');
        $this->writePreflight(array(
            'abspath' => $abspath,
            'content_dir' => $abspath . '/wp-content',
        ));
        $arguments = array('--include=:wp-content:', '--include=:wp-core:', '--mode=mirror');
        $this->pull($arguments);

        $local = $this->fsRoot . $abspath;
        $this->writeFiles($abspath, array(
            'shared/linked/p.php' => 'linked plugin, updated',
            'shared/linked/new.php' => 'new',
        ));
        $this->writeFiles($local, array('shared/linked/stray.php' => 'stray'));

        $this->pull(array('--abort'));
        $this->pull($arguments);

        $this->assertSame('linked plugin, updated', file_get_contents($local . '/shared/linked/p.php'));
        $this->assertSame('new', file_get_contents($local . '/shared/linked/new.php'));
        $this->assertFileDoesNotExist($local . '/shared/linked/stray.php');
    }

    public function testExcludingWpCorePullsEverySiteFileButNoCore(): void
    {
        $abspath = $this->source . '/html';
        $this->writeCore($abspath, '7.1.4');
        $this->writeFiles($abspath, array(
            'wp-config.php' => 'site config',
            '.htaccess' => 'rules',
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
            'wp-local-only.php' => 'stray top-level core file',
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
        $this->assertFileDoesNotExist($local . '/wp-local-only.php');
        $this->assertSame('local config', file_get_contents($local . '/wp-config.php'));
        $this->assertSame('local', file_get_contents($local . '/local-only.txt'));
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

    /** @param array<string,string> $paths_urls Preflight paths_urls for the source site. */
    private function writePreflight(array $paths_urls): void
    {
        $client = new \ImportClient($this->url, $this->stateDirectory, $this->fsRoot, array('allow_http' => true));
        \write_current_pull_state($client, array(
            'preflight' => array(
                'http_code' => 200,
                'data' => array(
                    'ok' => true,
                    'runtime' => array('document_root' => $paths_urls['abspath']),
                    'wp_detect' => array('roots' => array(array('path' => $paths_urls['abspath']))),
                    'database' => array('wp' => array('paths_urls' => $paths_urls)),
                ),
            ),
        ));
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
