<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote fixture paths in the real exporter router.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Pull Unix links over the real HTTP API without treating their names as Windows paths. */
final class UnixSymlinkPathTest extends TestCase {

    private string $root;
    private string $source;
    private string $url;

    /** @var resource|null Local exporter process. */
    private $server;

    /** Start a real exporter for an isolated Unix source tree. */
    protected function setUp(): void
    {
        if (PHP_OS === 'WINNT') {
            $this->markTestSkipped('These fixtures use literal Unix backslash names.');
        }
        $this->root = sys_get_temp_dir() . '/unix-link-path-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/source', 0700, true);
        $this->root = realpath($this->root);
        $this->source = $this->root . '/source';
        $repository = dirname(__DIR__, 2);
        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php require ' . var_export($repository . '/vendor/autoload.php', true)
            . '; require ' . var_export($repository . '/packages/reprint-server/src/export.php', true)
            . '; (new WordPress\\Reprint\\Server\\HTTPServer())->handle_request();');
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->server = proc_open([PHP_BINARY, '-S', $address, $router], [
            0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a'],
        ], $pipes);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
            if ($connection) {
                fclose($connection);
                $this->url = 'http://' . $address . '/';
                return;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    /** Release the listener and remove only this test's files, without following links. */
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->root)) {
            $this->remove_tree($this->root);
        }
    }

    /**
     * Both directory and file links must follow a remapped target on first and later pulls.
     *
     * @dataProvider relative_targets
     * @param string $target Relative link target on the Unix source.
     * @param bool $directory Whether the link points at a directory rather than a file.
     */
    public function testRemappedRelativeTargetsKeepTheirUnixNames(string $target, bool $directory): void
    {
        $target_path = $this->source . '/' . ( str_starts_with($target, './') ? substr($target, 2) : $target );
        $source_file = $target_path . ( $directory ? '/image.txt' : '' );
        if (!is_dir(dirname($source_file))) {
            mkdir(dirname($source_file), 0700, true);
        }
        file_put_contents($source_file, 'first image');
        symlink($target, $this->source . '/gallery');
        $this->assertSame('first image', file_get_contents($this->source . '/gallery' . ( $directory ? '/image.txt' : '' )));

        $arguments = ['--include=' . $this->source, '--remap', $this->source, ':fs-root:/site',
            '--remap', $target_path, ':fs-root:/media'];
        foreach (['first image', 'updated image with more bytes'] as $contents) {
            file_put_contents($source_file, $contents);
            if ($contents !== 'first image') {
                $this->pull_files(array_merge($arguments, ['--abort']));
            }
            $this->pull_files($arguments);
            $local_link = $this->root . '/files/site/gallery';
            $this->assertSame($contents, file_get_contents($this->root . '/files/media' . ( $directory ? '/image.txt' : '' )));
            $this->assertTrue(is_link($local_link));
            $this->assertSame('../media', readlink($local_link), 'The copied link must follow the remapped target.');
            $this->assertSame($contents, file_get_contents($local_link . ( $directory ? '/image.txt' : '' )));
        }
    }

    /**
     * Ordinary backslashes, drive-like names, share-like names, and mixed Unix components.
     *
     * @return array[] Each row supplies a literal target and whether it is a directory.
     */
    public static function relative_targets(): array
    {
        $cases = [];
        foreach (['photos', 'photo\\old', 'D:\\photos', 'd:\\photos', 'D:/photos', '\\\\server\\share',
            '\\\\server/share\\photos', '\\photos', 'D:photos', 'D:\\..\\photos', './D:\\photos'] as $target) {
            foreach ([true, false] as $directory) {
                $cases[$target . ( $directory ? ' directory' : ' file' )] = [$target, $directory];
            }
        }
        return $cases;
    }

    /**
     * Selecting only the final link still needs intermediate links outside that selection.
     *
     * @dataProvider intermediate_names
     * @param string $name Unix link name which resembles a Windows root.
     */
    public function testSelectedLinkRetainsIntermediateUnixLinks(string $name): void
    {
        mkdir($this->root . '/outside/photos', 0700, true);
        file_put_contents($this->root . '/outside/photos/image.txt', 'outside image');
        symlink($this->root . '/outside', $this->source . '/' . $name);
        symlink($name . '/photos', $this->source . '/gallery');
        $this->assertSame('outside image', file_get_contents($this->source . '/gallery/image.txt'));

        $this->pull_files(['--include=' . $this->source . '/gallery']);
        $local_source = $this->root . '/files' . $this->source;
        $this->assertTrue(is_link($local_source . '/' . $name), 'The source index must retain the intermediate link.');
        $this->assertTrue(is_link($local_source . '/gallery'));
        $this->assertSame('outside image', file_get_contents($local_source . '/gallery/image.txt'));
    }

    /** @return array[] Relative Unix names whose prefixes resemble Windows absolute paths. */
    public static function intermediate_names(): array
    {
        return [['D:\\bridge'], ['\\\\server\\share']];
    }

    /**
     * Excluding slash-delimited directories must not exclude a literal backslash name.
     *
     * @dataProvider distinct_names
     * @param string $literal Name containing literal backslashes.
     * @param string $directories Separate slash-delimited directories.
     */
    public function testExclusionsKeepBackslashNamesDistinct(string $literal, string $directories): void
    {
        foreach ([$literal, $directories] as $name) {
            mkdir($this->source . '/' . $name, 0700, true);
            file_put_contents($this->source . '/' . $name . '/image.txt', $name);
        }
        $this->pull_files(['--include=' . $this->source, '--exclude=' . $this->source . '/' . $directories,
            '--remap', $this->source, ':fs-root:/site']);
        $this->assertSame($literal, file_get_contents($this->root . '/files/site/' . $literal . '/image.txt'));
        $this->assertFileDoesNotExist($this->root . '/files/site/' . $directories . '/image.txt');
    }

    /** @return array[] Distinct Unix names which Windows separator replacement would conflate. */
    public static function distinct_names(): array
    {
        return [['photo\\old', 'photo/old'], ['D:\\photos', 'D:/photos'], ['\\\\server\\share', 'server/share']];
    }

    /**
     * Host-plugin filtering must not leave a link to a package it did not download.
     *
     * @dataProvider host_plugin_links
     * @param bool $include_host_plugins Whether to include the source-host plugin.
     * @param bool $shared_target Whether another selected link needs the same package.
     */
    public function testHostPluginExclusionDoesNotLeaveDanglingIntermediateLinks(bool $include_host_plugins, bool $shared_target): void
    {
        $package = $this->root . '/shared/wpcomsh';
        mkdir($package . '/10.0.0-alpha', 0700, true);
        file_put_contents($package . '/10.0.0-alpha/plugin.php', '<?php // Host plugin');
        symlink('10.0.0-alpha', $package . '/latest');
        mkdir($this->source . '/wp-content/mu-plugins', 0700, true);
        symlink($package . '/latest', $this->source . '/wp-content/mu-plugins/wpcomsh');
        file_put_contents($this->source . '/index.php', '<?php // Site');
        if ($shared_target) {
            symlink($package . '/latest', $this->source . '/shared-plugin');
        }

        $arguments = ['--include=' . $this->source, '--remap', $this->source, ':fs-root:/site',
            '--remap', $this->root . '/shared', ':fs-root:/shared',
            $include_host_plugins ? '--include-host-plugins' : '--exclude-host-plugins'];
        foreach ([false, true] as $second_pull) {
            if ($second_pull) {
                $this->pull_files(array_merge($arguments, ['--abort']));
            }
            $this->pull_files($arguments);
            $local_package = $this->root . '/files/shared/wpcomsh';
            $this->assertSame('<?php // Site', file_get_contents($this->root . '/files/site/index.php'));
            $this->assertSame($include_host_plugins, is_link($this->root . '/files/site/wp-content/mu-plugins/wpcomsh'));
            if ($include_host_plugins || $shared_target) {
                $this->assertSame('<?php // Host plugin', file_get_contents($local_package . '/latest/plugin.php'));
            } else {
                $this->assertFalse(is_link($local_package . '/latest'), 'An excluded package must not leave a dangling intermediate link.');
                $this->assertFileDoesNotExist($local_package . '/10.0.0-alpha/plugin.php');
                $audit_log = file_get_contents($this->root . '/state/audit.log');
                $this->assertStringContainsString('target was not downloaded: ' . $package . '/10.0.0-alpha', $audit_log);
                $this->assertStringNotContainsString('escapes filesystem root', $audit_log);
            }
        }
    }

    /** @return array[] Whether host plugins are included and another link uses their target. */
    public static function host_plugin_links(): array
    {
        return [
            'excluded host plugin' => [false, false],
            'included host plugin' => [true, false],
            'target used by another link' => [false, true],
        ];
    }

    /** A link below another link may name its target through that parent's alias. */
    public function testIntermediateTargetBelowAnotherLinkRemainsReadable(): void
    {
        mkdir($this->root . '/shared/version', 0700, true);
        file_put_contents($this->root . '/shared/version/plugin.php', '<?php // Plugin');
        symlink('version', $this->root . '/shared/latest');
        symlink('shared', $this->root . '/bridge');
        symlink($this->root . '/bridge/latest', $this->source . '/plugin');

        $this->pull_files(['--include=' . $this->source]);

        $this->assertSame('<?php // Plugin', file_get_contents($this->root . '/files' . $this->source . '/plugin/plugin.php'));
    }

    /**
     * Intermediate links must respect explicit exclusions just like downloaded paths.
     *
     * @dataProvider intermediate_exclusions
     * @param string $excluded_path Relative path excluded from the shared package.
     */
    public function testIntermediateLinksRespectExplicitExclusions(string $excluded_path): void
    {
        mkdir($this->root . '/shared/version', 0700, true);
        file_put_contents($this->root . '/shared/version/plugin.php', '<?php // Plugin');
        symlink('version', $this->root . '/shared/latest');
        symlink($this->root . '/shared/latest', $this->source . '/plugin');

        $this->pull_files(['--include=' . $this->source,
            '--exclude=' . $this->root . '/shared/' . $excluded_path,
            '--remap', $this->root . '/shared', ':fs-root:/shared']);

        $this->assertFalse(is_link($this->root . '/files/shared/latest'));
        $this->assertSame($excluded_path === 'latest', file_exists($this->root . '/files/shared/version/plugin.php'));
    }

    /** @return array[] Exclude either the intermediate link itself or its target contents. */
    public static function intermediate_exclusions(): array
    {
        return [['latest'], ['version'], ['version/plugin.php']];
    }

    /**
     * Seed file-only preflight metadata, then run the real CLI, index endpoint, and file endpoint.
     *
     * @param string[] $arguments Additional CLI path selections and remaps.
     */
    private function pull_files(array $arguments): void
    {
        $state_directory = $this->root . '/state';
        if (!is_dir($state_directory)) {
            $client = new \ImportClient($this->url, $state_directory, $this->root . '/files', ['allow_http' => true]);
            \write_current_pull_state($client, [
                'preflight' => ['data' => ['ok' => true, 'path_format' => 'unix', 'database' => ['wp' => ['paths_urls' => ['abspath' => $this->source]]], 'wp_detect' => ['roots' => [['path' => $this->source]]]], 'http_code' => 200],
            ]);
        }
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/packages/reprint-client/src/import.php',
            'files-pull', $this->url, '--state-dir=' . $state_directory, '--fs-root=' . $this->root . '/files',
            '--allow-unsafe-http', '--follow-symlinks', '--progress=jsonl'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/pull.log', 'w'],
            2 => ['file', $this->root . '/pull.log', 'a']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($process), file_get_contents($this->root . '/pull.log'));
    }

    /** Remove a fixture tree without traversing any symlink targets. */
    private function remove_tree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove_tree($path . '/' . $name);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
