<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Verify the merge-wp-content command.
 *
 * The command folds the wp-content under --from into the one the file pull
 * wrote, so entries only the local site holds survive whatever the caller does
 * to that directory next — flat-docroot replacing it with a symlink being the
 * case it exists for.
 */
class MergeWpContentTest extends TestCase
{
    private const REMOTE_URL = 'https://source.example/export.php';
    private const ABSPATH = '/srv/htdocs/wordpress/';

    private string $tempDir;
    private string $stateDir;
    private string $filesystemRoot;
    private string $siteDir;
    private string $remoteStateDir;
    private string $pulledWpContent;
    private string $localWpContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/merge-wp-content-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystemRoot = $this->tempDir . '/fs-root';
        $this->siteDir = $this->tempDir . '/site';
        $this->remoteStateDir = $this->stateDir . '/remotes/' . md5(self::REMOTE_URL);
        $this->pulledWpContent = $this->filesystemRoot . self::ABSPATH . 'wp-content';
        $this->localWpContent = $this->siteDir . '/wp-content';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->pulledWpContent, 0755, true);
        mkdir($this->localWpContent, 0755, true);
        // The guard reads this file as the record of a finished file pull.
        mkdir($this->remoteStateDir, 0755, true);
        file_put_contents($this->remoteStateDir . '/local_index.jsonl', '');
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testEntriesOnlyTheLocalSiteHoldsReachThePulledTree(): void
    {
        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php // pulled');
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');
        $this->writeLocal('themes/local-theme/style.css', '/* local */');
        $this->writeLocal('custom-folder/note.txt', 'keep me');

        $this->merge();

        $this->assertFileExists(
            $this->pulledWpContent . '/plugins/local-only/plugin.php',
            'a local-only plugin now lives under the filesystem root, where files-push reads',
        );
        $this->assertFileExists($this->pulledWpContent . '/themes/local-theme/style.css');
        $this->assertFileExists($this->pulledWpContent . '/custom-folder/note.txt');
        $this->assertFileDoesNotExist(
            $this->localWpContent . '/plugins/local-only/plugin.php',
            'and no longer under --from: entries move, they are not copied',
        );
        $this->assertFileExists($this->pulledWpContent . '/plugins/from-the-pull/plugin.php');
    }

    public function testAPluginBothSidesHaveIsNeverMerged(): void
    {
        // The pull carries WooCommerce 9.2; the local site has 8.5. Merging
        // them would leave files 9.0 dropped inside a directory claiming to
        // be 9.2, and push those files to the source site later.
        $this->writePulled('plugins/woocommerce/woocommerce.php', 'Version: 9.2');
        $this->writePulled('plugins/woocommerce/includes/class-wc-new.php', 'new');
        $this->writeLocal('plugins/woocommerce/woocommerce.php', 'Version: 8.5');
        $this->writeLocal('plugins/woocommerce/includes/class-wc-legacy.php', 'old');
        $this->writeLocal('plugins/woocommerce/legacy-assets/old.js', 'old');
        $this->writeLocal('plugins/my-dev-plugin/plugin.php', 'mine');

        $this->merge();

        $plugins = $this->pulledWpContent . '/plugins';
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
            'a plugin only the local site has still moves whole',
        );
    }

    public function testAThemeBothSidesHaveIsNeverMerged(): void
    {
        $this->writePulled('themes/twentytwentyfive/style.css', 'Version: 1.3');
        $this->writeLocal('themes/twentytwentyfive/style.css', 'Version: 1.0');
        $this->writeLocal('themes/twentytwentyfive/patterns/dropped.php', 'old');
        $this->writeLocal('themes/my-child-theme/style.css', 'mine');

        $this->merge();

        $themes = $this->pulledWpContent . '/themes';
        $this->assertFileDoesNotExist($themes . '/twentytwentyfive/patterns/dropped.php');
        $this->assertSame('Version: 1.3', file_get_contents($themes . '/twentytwentyfive/style.css'));
        $this->assertFileExists($themes . '/my-child-theme/style.css');
    }

    public function testUploadsMergeFileByFile(): void
    {
        // Media is not a versioned payload: a photo the pull lacks is its own
        // thing, so uploads merges to the leaf where plugins does not.
        $this->writePulled('uploads/2026/01/pulled.jpg', 'pulled');
        $this->writeLocal('uploads/2026/01/local.jpg', 'local');
        $this->writeLocal('uploads/2025/12/older.jpg', 'local');

        $this->merge();

        $uploads = $this->pulledWpContent . '/uploads';
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
        // unit. The local-only file inside it stays where it is; a directory
        // only the local site has still moves.
        $this->writePulled('languages/admin-en_GB.mo', 'pulled');
        $this->writeLocal('languages/plugins/local-only.mo', 'local');
        $this->writeLocal('my-own-folder/keep.txt', 'local');

        $this->merge();

        $this->assertFileDoesNotExist(
            $this->pulledWpContent . '/languages/plugins/local-only.mo',
        );
        $this->assertFileExists($this->pulledWpContent . '/my-own-folder/keep.txt');
    }

    public function testAMovedSymlinkStillResolvesFromItsNewDepth(): void
    {
        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php');
        $this->writeFile($this->siteDir . '/shared-store/local-plugin/plugin.php', '<?php // shared store');
        mkdir($this->localWpContent . '/plugins', 0755, true);
        // A relative value read against a parent two levels below the site
        // directory; the destination parent sits at a different depth.
        symlink(
            '../../shared-store/local-plugin',
            $this->localWpContent . '/plugins/linked-plugin',
        );

        $this->merge();

        $moved = $this->pulledWpContent . '/plugins/linked-plugin';
        $this->assertTrue(is_link($moved), 'the entry is still a symlink, not a copy of its target');
        $this->assertStringNotContainsString(
            '/',
            substr(readlink($moved), 0, 1),
            'and its value is still relative, because files-push sends it verbatim',
        );
        $this->assertFileExists(
            $moved . '/plugin.php',
            'and it resolves from the deeper parent',
        );
        $this->assertStringContainsString(
            'shared store',
            file_get_contents($moved . '/plugin.php'),
        );
    }

    public function testTheExplodedLayoutRoutesEachComponentToItsOwnSource(): void
    {
        // uploads outside content_dir is the WP Cloud shape: each component
        // has to land under the directory preflight reports for it.
        $this->writeFile(
            $this->filesystemRoot . '/srv/htdocs/wp-content/plugins/from-the-pull/plugin.php',
            '<?php',
        );
        $this->writeFile($this->filesystemRoot . '/srv/uploads/2026/01/pulled.jpg', 'pulled');
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');
        $this->writeLocal('uploads/2026/02/local.jpg', 'local');
        $this->writeLocal('local-folder/note.txt', 'keep me');

        $this->merge([
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
    }

    public function testARoutedComponentDirectoryIsRenamedIntoAPathThePullNeverWrote(): void
    {
        // A pull scoped with --only can leave the uploads directory absent
        // while preflight still reports where it belongs. The uploads volume
        // sits on its own path here, so nothing the pull wrote shares a parent
        // with it.
        $this->writeFile(
            $this->filesystemRoot . '/var/www/wp-content/plugins/from-the-pull/plugin.php',
            '<?php',
        );
        $this->writeLocal('uploads/2026/02/local.jpg', 'local');
        $inodeBefore = fileinode($this->localWpContent . '/uploads/2026/02/local.jpg');

        $this->merge([
            'content_dir' => '/var/www/wp-content',
            'uploads' => ['basedir' => '/mnt/uploads'],
        ]);

        $moved = $this->filesystemRoot . '/mnt/uploads/2026/02/local.jpg';
        $this->assertFileExists(
            $moved,
            'the whole local uploads tree moves to the directory preflight names',
        );
        $this->assertDirectoryDoesNotExist(
            $this->filesystemRoot . '/var/www/wp-content/uploads',
            'and not to the conventional place inside wp-content',
        );
        $this->assertSame(
            $inodeBefore,
            fileinode($moved),
            'and it was renamed there, not copied file by file into a path rename could not reach',
        );
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php');
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');

        $this->merge();
        $firstPass = $this->describeTree($this->filesystemRoot);

        $this->merge();

        $this->assertSame(
            $firstPass,
            $this->describeTree($this->filesystemRoot),
            're-running moves nothing further: everything the local site had is already there',
        );
    }

    public function testASecondRunAfterFlatteningIsANoOp(): void
    {
        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php');
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');

        $this->merge();

        // What flat-docroot leaves behind: the site's wp-content is a symlink
        // into the filesystem root, so both sides are the same tree.
        $this->recursiveDelete($this->localWpContent);
        symlink($this->pulledWpContent, $this->localWpContent);
        $beforeSecondRun = $this->describeTree($this->filesystemRoot);

        $this->merge();

        $this->assertSame(
            $beforeSecondRun,
            $this->describeTree($this->filesystemRoot),
            'a symlinked source holds nothing of its own, so nothing moves',
        );
    }

    public function testAMoveThatCannotCompleteLeavesTheLocalSiteUntouched(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Directory permissions do not stop root from writing.');
        }

        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php');
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');
        // rename() into a read-only plugins directory fails, and so does the
        // copy fallback, because both write there.
        chmod($this->pulledWpContent . '/plugins', 0555);

        try {
            $this->merge();
            $this->fail('the failed move should have stopped the command');
        } catch (\RuntimeException $exception) {
            // Expected.
        } finally {
            chmod($this->pulledWpContent . '/plugins', 0755);
        }

        $this->assertFileExists(
            $this->localWpContent . '/plugins/local-only/plugin.php',
            'the entry that could not move is still where it was',
        );
    }

    public function testACopyThatFailsPartwayLeavesNothingAtTheDestination(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('File permissions do not stop root from reading.');
        }

        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php');
        $this->writeLocal('plugins/local-only/first.php', '<?php // copied');
        $this->writeLocal('plugins/local-only/second.php', '<?php // unreadable');
        $this->writeLocal('plugins/local-only/third.php', '<?php // never reached');
        // rename() needs to write to the entry's parent, so a read-only parent
        // sends the move down the cross-filesystem copy path, and an unreadable
        // file part-way through that copy fails it after it has written some.
        chmod($this->localWpContent . '/plugins/local-only/second.php', 0000);
        chmod($this->localWpContent . '/plugins', 0555);

        try {
            $this->merge();
            $this->fail('the failed copy should have stopped the command');
        } catch (\RuntimeException $exception) {
            // Expected.
        } finally {
            chmod($this->localWpContent . '/plugins', 0755);
            chmod($this->localWpContent . '/plugins/local-only/second.php', 0644);
        }

        $plugins = $this->pulledWpContent . '/plugins';
        $this->assertFileDoesNotExist(
            $plugins . '/local-only',
            'a half-copied entry must not sit at the name the next run reads as the pulled copy',
        );
        $this->assertSame(
            [],
            glob($plugins . '/*.reprint-merge-incomplete') ?: [],
            'and the staging path it copied into is cleared',
        );
        $this->assertFileExists(
            $this->localWpContent . '/plugins/local-only/first.php',
            'the original is untouched, so a later run can try again',
        );
    }

    /**
     * rename() keeps permissions; the copy behind EXDEV has to reproduce them.
     *
     * A second filesystem is not something a test can rely on having, so this
     * exercises the copy itself, which is the only part of a move that ever
     * creates a file.
     */
    public function testTheCopyFallbackKeepsPermissions(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('The umask a root process runs under is not the point here.');
        }

        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');
        $this->writeLocal('plugins/local-only/bin/run.sh', '#!/bin/sh');
        $this->writeLocal('plugins/local-only/private/secret.key', 'key');
        chmod($this->localWpContent . '/plugins/local-only/bin/run.sh', 0755);
        chmod($this->localWpContent . '/plugins/local-only/private', 0700);

        $merger = new \WpContentMerger(
            $this->localWpContent,
            $this->pulledWpContent,
            [],
            function (string $line): void {}
        );
        $copy = (new \ReflectionClass($merger))->getMethod('copy_path');
        $copied = $this->tempDir . '/copied-plugin';
        $copy->invoke($merger, $this->localWpContent . '/plugins/local-only', $copied);

        $this->assertSame('0755', $this->permissionsOf($copied . '/bin/run.sh'));
        $this->assertSame('0700', $this->permissionsOf($copied . '/private'));
        $this->assertSame(
            $this->permissionsOf($this->localWpContent . '/plugins/local-only/plugin.php'),
            $this->permissionsOf($copied . '/plugin.php'),
            'an ordinary file keeps what it had rather than taking the umask',
        );
    }

    public function testTheCommandRefusesWhileAFilePullIsUnfinished(): void
    {
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');
        mkdir($this->remoteStateDir . '/pull', 0755, true);
        file_put_contents($this->remoteStateDir . '/pull/index.wal', '');

        try {
            $this->merge();
            $this->fail('an open files-pull lifecycle should have stopped the command');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('interrupted files-pull', $exception->getMessage());
        }

        $this->assertFileExists(
            $this->localWpContent . '/plugins/local-only/plugin.php',
            'nothing moved: mid-download every unfetched path looks absent',
        );
    }

    public function testTheCommandRefusesWhenNoFilePullHasCompleted(): void
    {
        $this->writeLocal('plugins/local-only/plugin.php', '<?php // local');
        unlink($this->remoteStateDir . '/local_index.jsonl');

        try {
            $this->merge();
            $this->fail('a missing local index should have stopped the command');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('requires a completed file pull', $exception->getMessage());
        }

        $this->assertFileExists($this->localWpContent . '/plugins/local-only/plugin.php');
    }

    public function testTheCommandRefusesWhenFromAndTheDestinationOverlap(): void
    {
        $this->writePulled('plugins/from-the-pull/plugin.php', '<?php');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one holds the other');
        // The site directory the pull itself wrote: --from/wp-content is the
        // destination, so the walk would read what it had just moved.
        $this->merge([], $this->filesystemRoot . self::ABSPATH);
    }

    // ---- helpers ----

    /**
     * Run merge-wp-content against the layout built by the write helpers.
     *
     * @param array  $pathsUrls Extra preflight paths_urls keys, for layouts
     *                          where wp-content or its sub-components sit
     *                          outside ABSPATH.
     * @param string $from      Site directory to merge from; the site
     *                          directory the tests build by default.
     */
    private function merge(array $pathsUrls = [], ?string $from = null): void
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
        $client->run_merge_wp_content(['from' => $from ?? $this->siteDir]);
    }

    private function writePulled(string $relative, string $contents): void
    {
        $this->writeFile($this->pulledWpContent . '/' . $relative, $contents);
    }

    private function writeLocal(string $relative, string $contents): void
    {
        $this->writeFile($this->localWpContent . '/' . $relative, $contents);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    /** The permission bits of $path as four octal digits. */
    private function permissionsOf(string $path): string
    {
        clearstatcache(true, $path);
        return substr(sprintf('%o', fileperms($path)), -4);
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
            self::REMOTE_URL,
            $this->stateDir,
            $this->filesystemRoot,
        );
    }

    private function loadClientState(\ImportClient $client): void
    {
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('load_state');
        $property = $reflection->getProperty('state');
        $property->setValue($client, $method->invoke($client));
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            if (is_link($dir)) {
                unlink($dir);
            }
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
