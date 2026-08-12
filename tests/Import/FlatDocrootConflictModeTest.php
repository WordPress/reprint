<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify the three --on-flatten-to-conflict modes.
 *
 * 'adopt' is the one that carries weight: it moves wp-content entries that
 * only the flattened directory holds into the filesystem root, so replacing
 * that directory with a symlink keeps them and files-push — which reads the
 * filesystem root and nothing else — can still see them.
 */
class FlatDocrootConflictModeTest extends TestCase
{
    private const ABSPATH = '/srv/htdocs/wordpress/';

    private string $tempDir;
    private string $stateDir;
    private string $filesystemRoot;
    private string $flattenTo;
    private string $localAbspath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/flat-docroot-conflict-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystemRoot = $this->tempDir . '/fs-root';
        $this->flattenTo = $this->tempDir . '/flat';
        $this->localAbspath = $this->filesystemRoot . self::ABSPATH;
        mkdir($this->stateDir, 0755, true);
        mkdir($this->localAbspath, 0755, true);
        file_put_contents($this->localAbspath . 'wp-load.php', '<?php // wp-load');
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testMovesTargetOnlyEntriesIntoTheFilesystemRoot(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php // pulled');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php // local');
        $this->writeFlattened('wp-content/themes/local-theme/style.css', '/* local */');
        $this->writeFlattened('wp-content/custom-folder/note.txt', 'keep me');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $this->assertFileExists(
            $this->localAbspath . 'wp-content/plugins/local-only/plugin.php',
            'a local-only plugin now lives under the filesystem root, where files-push reads',
        );
        $this->assertFileExists($this->localAbspath . 'wp-content/themes/local-theme/style.css');
        $this->assertFileExists($this->localAbspath . 'wp-content/custom-folder/note.txt');
        $this->assertFileExists(
            $this->flattenTo . '/wp-content/plugins/local-only/plugin.php',
            'and is still reachable through the flattened layout',
        );
        $this->assertFileExists($this->flattenTo . '/wp-content/plugins/from-the-pull/plugin.php');
    }

    public function testWpContentStaysASingleSymlink(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php // pulled');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php // local');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $this->assertTrue(
            is_link($this->flattenTo . '/wp-content'),
            'the flattened layout stays a view: one symlink for the whole subtree',
        );
        $this->assertFalse(
            is_link($this->flattenTo . '/wp-content/plugins/local-only'),
            'the adopted plugin is a real directory behind that symlink, not a link of its own',
        );
    }

    public function testPulledCopyWinsACollision(): void
    {
        $this->writePulled('wp-content/plugins/shared/plugin.php', '<?php // from the pull');
        $this->writeFlattened('wp-content/plugins/shared/plugin.php', '<?php // from the target');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $this->assertStringContainsString(
            'from the pull',
            file_get_contents($this->flattenTo . '/wp-content/plugins/shared/plugin.php'),
            'a collision resolves to the pulled copy rather than raising',
        );
    }

    public function testMovesASymlinkAndKeepsItResolving(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('shared-store/local-plugin/plugin.php', '<?php // shared store');
        mkdir($this->flattenTo . '/wp-content/plugins', 0755, true);
        // A relative value read against a parent two levels below --flatten-to.
        symlink(
            '../../shared-store/local-plugin',
            $this->flattenTo . '/wp-content/plugins/linked-plugin',
        );

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $moved = $this->localAbspath . 'wp-content/plugins/linked-plugin';
        $this->assertTrue(is_link($moved), 'the entry is still a symlink, not a copy of its target');
        $this->assertFileExists(
            $moved . '/plugin.php',
            'and its rewritten value still resolves from the deeper parent',
        );
        $this->assertStringContainsString(
            'shared store',
            file_get_contents($moved . '/plugin.php'),
        );
    }

    public function testExplodedLayoutAdoptsAgainstEachComponentSource(): void
    {
        // uploads detached from content_dir forces the exploded layout, where
        // wp-content is a real directory rather than one symlink.
        $this->writeFileUnderFilesystemRoot(
            '/srv/htdocs/wp-content/plugins/from-the-pull/plugin.php',
            '<?php',
        );
        $this->writeFileUnderFilesystemRoot('/srv/uploads/2026/01/pulled.jpg', 'pulled');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php // local');
        $this->writeFlattened('wp-content/uploads/2026/02/local.jpg', 'local');
        $this->writeFlattened('wp-content/local-folder/note.txt', 'keep me');

        $this->flatten(['on_flatten_to_conflict' => 'adopt'], [
            'content_dir' => '/srv/htdocs/wp-content',
            'uploads' => ['basedir' => '/srv/uploads'],
        ]);

        $this->assertFileExists(
            $this->filesystemRoot . '/srv/htdocs/wp-content/plugins/local-only/plugin.php',
            'a local-only plugin lands under the plugins source',
        );
        $this->assertFileExists(
            $this->filesystemRoot . '/srv/uploads/2026/02/local.jpg',
            'a local-only upload lands under the detached uploads source, not under content_dir',
        );
        $this->assertFileExists(
            $this->filesystemRoot . '/srv/htdocs/wp-content/local-folder/note.txt',
            'an entry belonging to no component lands under content_dir',
        );
        $this->assertFileExists($this->flattenTo . '/wp-content/uploads/2026/01/pulled.jpg');
        $this->assertFileExists($this->flattenTo . '/wp-content/uploads/2026/02/local.jpg');
    }

    public function testSecondFlattenChangesNothing(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php // local');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);
        $firstPass = $this->describeTree($this->filesystemRoot);

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $this->assertSame(
            $firstPass,
            $this->describeTree($this->filesystemRoot),
            're-running moves nothing further: wp-content is already a symlink',
        );
        $this->assertFileExists($this->flattenTo . '/wp-content/plugins/local-only/plugin.php');
    }

    public function testAPluginBothSidesHaveIsNeverMerged(): void
    {
        // The pull carries WooCommerce 9.2; the local site has 8.5. Merging
        // them would leave files 9.0 dropped inside a directory claiming to
        // be 9.2, and push those files back to the source later.
        $this->writePulled('wp-content/plugins/woocommerce/woocommerce.php', 'Version: 9.2');
        $this->writePulled('wp-content/plugins/woocommerce/includes/class-wc-new.php', 'new');
        $this->writeFlattened('wp-content/plugins/woocommerce/woocommerce.php', 'Version: 8.5');
        $this->writeFlattened('wp-content/plugins/woocommerce/includes/class-wc-legacy.php', 'old');
        $this->writeFlattened('wp-content/plugins/woocommerce/legacy-assets/old.js', 'old');
        $this->writeFlattened('wp-content/plugins/my-dev-plugin/plugin.php', 'mine');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $plugins = $this->localAbspath . 'wp-content/plugins';
        $this->assertFileDoesNotExist(
            $plugins . '/woocommerce/includes/class-wc-legacy.php',
            'a file the pulled version dropped is not carried into it',
        );
        $this->assertFileDoesNotExist(
            $plugins . '/woocommerce/legacy-assets/old.js',
            'nor is a whole directory the pulled version dropped',
        );
        $this->assertFileExists($plugins . '/woocommerce/includes/class-wc-new.php');
        $this->assertSame('Version: 9.2', file_get_contents($plugins . '/woocommerce/woocommerce.php'));
        $this->assertFileExists(
            $plugins . '/my-dev-plugin/plugin.php',
            'a plugin only the local site has is still adopted whole',
        );
    }

    public function testAThemeBothSidesHaveIsNeverMerged(): void
    {
        $this->writePulled('wp-content/themes/twentytwentyfive/style.css', 'Version: 1.3');
        $this->writeFlattened('wp-content/themes/twentytwentyfive/style.css', 'Version: 1.0');
        $this->writeFlattened('wp-content/themes/twentytwentyfive/patterns/dropped.php', 'old');
        $this->writeFlattened('wp-content/themes/my-child-theme/style.css', 'mine');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $themes = $this->localAbspath . 'wp-content/themes';
        $this->assertFileDoesNotExist($themes . '/twentytwentyfive/patterns/dropped.php');
        $this->assertSame('Version: 1.3', file_get_contents($themes . '/twentytwentyfive/style.css'));
        $this->assertFileExists($themes . '/my-child-theme/style.css');
    }

    public function testUploadsMergeFileByFile(): void
    {
        // Media is not a versioned payload: a photo the pull lacks is its own
        // thing, so uploads merges to the leaf where plugins does not.
        $this->writePulled('wp-content/uploads/2026/01/pulled.jpg', 'pulled');
        $this->writeFlattened('wp-content/uploads/2026/01/local.jpg', 'local');
        $this->writeFlattened('wp-content/uploads/2025/12/older.jpg', 'local');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $uploads = $this->localAbspath . 'wp-content/uploads';
        $this->assertFileExists(
            $uploads . '/2026/01/local.jpg',
            'a local-only file inside a month both sides have is kept',
        );
        $this->assertFileExists($uploads . '/2025/12/older.jpg');
        $this->assertFileExists($uploads . '/2026/01/pulled.jpg');
    }

    public function testAnUnknownDirectoryBothSidesHaveIsLeftToThePull(): void
    {
        // Nothing here says what the directory holds, so it is treated as one
        // unit. The local-only file inside it goes with the replaced
        // directory; a directory only the local site has is still adopted.
        $this->writePulled('wp-content/languages/admin-en_GB.mo', 'pulled');
        $this->writeFlattened('wp-content/languages/plugins/local-only.mo', 'local');
        $this->writeFlattened('wp-content/my-own-folder/keep.txt', 'local');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $this->assertFileDoesNotExist(
            $this->localAbspath . 'wp-content/languages/plugins/local-only.mo',
        );
        $this->assertFileExists($this->localAbspath . 'wp-content/my-own-folder/keep.txt');
    }

    public function testAdoptReplacesCoreWithoutNeedingForceAsWell(): void
    {
        // What `studio create` leaves behind: a real core directory and a real
        // wp-content, both standing where symlinks must go. One mode has to
        // settle both, or every caller would pass two flags that disagree.
        $this->writePulled('wp-admin/admin.php', '<?php // pulled core');
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-admin/admin.php', '<?php // blank install core');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php // local');

        $this->flatten(['on_flatten_to_conflict' => 'adopt']);

        $this->assertTrue(
            is_link($this->flattenTo . '/wp-admin'),
            'core is replaced, exactly as replace would have done',
        );
        $this->assertStringContainsString(
            'pulled core',
            file_get_contents($this->flattenTo . '/wp-admin/admin.php'),
        );
        $this->assertFileExists(
            $this->localAbspath . 'wp-content/plugins/local-only/plugin.php',
            'and wp-content is salvaged rather than deleted',
        );
    }

    public function testForceContradictingTheModeIsRefused(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--force means --on-flatten-to-conflict=replace');
        $this->flatten(['force' => true, 'on_flatten_to_conflict' => 'adopt']);
    }

    public function testForceAgreeingWithTheModeIsAccepted(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php');

        $this->flatten(['force' => true, 'on_flatten_to_conflict' => 'replace']);

        $this->assertTrue(is_link($this->flattenTo . '/wp-content'));
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --on-flatten-to-conflict value: adapt');
        $this->flatten(['on_flatten_to_conflict' => 'adapt']);
    }

    public function testErrorModeIsTheDefaultAndRefusesAnExistingDirectory(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php');

        $this->expectException(\RuntimeException::class);
        $this->flatten();
    }

    public function testForceIsAnAliasForReplaceAndStillDeletesWpContent(): void
    {
        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php');

        $this->flatten(['force' => true]);

        $this->assertTrue(is_link($this->flattenTo . '/wp-content'));
        $this->assertFileDoesNotExist(
            $this->flattenTo . '/wp-content/plugins/local-only/plugin.php',
            'the documented replace behaviour is unchanged',
        );
    }

    public function testAFailedMoveLeavesTheFlattenedDirectoryIntact(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }

        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-content/plugins/local-only/plugin.php', '<?php // local');
        $this->writeFlattened('wp-content/themes/local-theme/style.css', '/* local */');
        // rename() into a read-only plugins directory fails, and so does the
        // copy fallback, so the command stops before any symlink is placed.
        chmod($this->localAbspath . 'wp-content/plugins', 0555);

        try {
            $this->flatten(['on_flatten_to_conflict' => 'adopt']);
            $this->fail('the failed move should have stopped the command');
        } catch (\RuntimeException $exception) {
            // Expected.
        } finally {
            chmod($this->localAbspath . 'wp-content/plugins', 0755);
        }

        $this->assertFileExists(
            $this->flattenTo . '/wp-content/plugins/local-only/plugin.php',
            'the entry that could not move is still where it was',
        );
        $this->assertFalse(
            is_link($this->flattenTo . '/wp-content'),
            'nothing was replaced with a symlink, so nothing was deleted',
        );
    }

    public function testACopyThatFailsPartwayLeavesNothingAtTheDestination(): void
    {
        if ( function_exists('posix_getuid') && posix_getuid() === 0 ) {
            $this->markTestSkipped('File permissions do not stop root from reading.');
        }

        $this->writePulled('wp-content/plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFlattened('wp-content/plugins/local-only/first.php', '<?php // copied');
        $this->writeFlattened('wp-content/plugins/local-only/second.php', '<?php // unreadable');
        $this->writeFlattened('wp-content/plugins/local-only/third.php', '<?php // never reached');
        // rename() needs to write to the entry's parent, so a read-only parent
        // sends the move down the cross-filesystem copy path, and an unreadable
        // file part-way through that copy fails it after it has written some.
        chmod($this->flattenTo . '/wp-content/plugins/local-only/second.php', 0000);
        chmod($this->flattenTo . '/wp-content/plugins', 0555);

        try {
            $this->flatten(['on_flatten_to_conflict' => 'adopt']);
            $this->fail('the failed copy should have stopped the command');
        } catch (\RuntimeException $exception) {
            // Expected.
        } finally {
            chmod($this->flattenTo . '/wp-content/plugins', 0755);
            chmod($this->flattenTo . '/wp-content/plugins/local-only/second.php', 0644);
        }

        $plugins = $this->localAbspath . 'wp-content/plugins';
        $this->assertFileDoesNotExist(
            $plugins . '/local-only',
            'a half-copied entry must not sit at the name the next run reads as the pulled copy',
        );
        $this->assertSame(
            [],
            glob($plugins . '/*.reprint-adopt-incomplete') ?: [],
            'and the staging path it copied into is cleared',
        );
        $this->assertFileExists(
            $this->flattenTo . '/wp-content/plugins/local-only/first.php',
            'the original is untouched, so a later run can try again',
        );
        $this->assertFalse(
            is_link($this->flattenTo . '/wp-content'),
            'nothing was replaced with a symlink, so nothing was deleted',
        );
    }

    // ---- helpers ----

    /**
     * Run flat-docroot against the layout built by the write* helpers.
     *
     * @param array $options   Options for run_flat_document_root, merged over
     *                         --flatten-to. Pass on_flatten_to_conflict, force,
     *                         or neither.
     * @param array $pathsUrls Extra preflight paths_urls keys, for layouts
     *                         where wp-content or its sub-components sit
     *                         outside ABSPATH.
     */
    private function flatten(array $options = [], array $pathsUrls = []): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'table_prefix' => 'wp_',
                            'paths_urls' => array_merge(
                                ['abspath' => self::ABSPATH],
                                $pathsUrls,
                            ),
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'run_flat_document_root', [
            array_merge(['flatten_to' => $this->flattenTo], $options),
        ]);
    }

    private function writePulled(string $relative, string $contents): void
    {
        $this->writeFile($this->localAbspath . $relative, $contents);
    }

    private function writeFlattened(string $relative, string $contents): void
    {
        $this->writeFile($this->flattenTo . '/' . $relative, $contents);
    }

    private function writeFileUnderFilesystemRoot(string $absolute, string $contents): void
    {
        $this->writeFile($this->filesystemRoot . $absolute, $contents);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * List every path under $dir with its type, for comparing two runs.
     *
     * @return string[] Sorted "type relative/path" lines.
     */
    private function describeTree(string $dir): array
    {
        $described = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach (scandir($current) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $current . '/' . $entry;
                $relative = substr($path, strlen($dir));
                if (is_link($path)) {
                    $described[] = 'link ' . $relative . ' -> ' . readlink($path);
                    continue;
                }
                if (is_dir($path)) {
                    $described[] = 'dir  ' . $relative;
                    $stack[] = $path;
                    continue;
                }
                $described[] = 'file ' . $relative . ' ' . md5_file($path);
            }
        }
        sort($described);
        return $described;
    }

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), $state);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient(
            'https://source.example/export.php',
            $this->stateDir,
            $this->filesystemRoot,
        );
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
                @chmod($path, 0755);
                $this->recursiveDelete($path);
            }
        }
        rmdir($dir);
    }
}
