<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\ResourceBudget;

require_once dirname(__DIR__) . '/packages/reprint-server/src/export.php';

// Loading export.php installs handlers that would exit() on a later test.
restore_error_handler();
restore_exception_handler();

/**
 * The preflight size estimate. Its job is to answer "how much would files-pull move?" without
 * counting anything twice that files-pull wouldn't, and without ever being the reason preflight
 * times out.
 */
final class ExportDirectorySizeTest extends TestCase {

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = realpath(sys_get_temp_dir()) . '/directory-size-' . uniqid();
        mkdir($this->tempDir . '/content/uploads/2026', 0755, true);
        file_put_contents($this->tempDir . '/content/a.txt', str_repeat('a', 100));
        file_put_contents($this->tempDir . '/content/uploads/b.txt', str_repeat('b', 250));
        file_put_contents($this->tempDir . '/content/uploads/2026/c.txt', str_repeat('c', 30));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    private function removeTree(string $directory): void
    {
        // Silenced: the open_basedir test cleans up under its own restriction.
        if (!@is_dir($directory) || @is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (@is_link($path) || @is_file($path)) {
                @unlink($path);
                continue;
            }

            $this->removeTree($path);
        }

        @rmdir($directory);
    }

    private function writeOutside(string $name, int $bytes): string
    {
        $directory = $this->tempDir . '/outside/' . $name;
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/file.bin', str_repeat('x', $bytes));
        return $directory;
    }

    public function testItTotalsEveryFileBelowTheRoot(): void
    {
        $this->assertSame(380, estimate_directory_bytes([$this->tempDir . '/content']));
    }

    public function testNoReadableRootIsUnknownRatherThanZero(): void
    {
        $this->assertNull(estimate_directory_bytes([$this->tempDir . '/does-not-exist']));
    }

    /** Unknown optional directories (mu-plugins on a site without one) are simply left out. */
    public function testMissingAndNullRootsAreSkipped(): void
    {
        $this->assertSame(
            380,
            estimate_directory_bytes([$this->tempDir . '/content', null, $this->tempDir . '/content/mu-plugins'])
        );
    }

    /** uploads is passed beside wp-content; when it lives inside, it must not be counted twice. */
    public function testARootInsideAnotherIsNotCountedTwice(): void
    {
        $this->assertSame(
            380,
            estimate_directory_bytes([$this->tempDir . '/content/uploads', $this->tempDir . '/content'])
        );
    }

    /** A custom upload_dir outside wp-content is pulled by :wp-content:, so it counts. */
    public function testARootOutsideWpContentIsAdded(): void
    {
        $uploads = $this->writeOutside('uploads', 1000);

        $this->assertSame(1380, estimate_directory_bytes([$this->tempDir . '/content', $uploads]));
    }

    /** files-pull follows a symlinked directory that escapes wp-content, so its bytes land too. */
    public function testASymlinkedDirectoryOutsideTheRootsIsFollowed(): void
    {
        $shared = $this->writeOutside('shared-uploads', 5000);
        symlink($shared, $this->tempDir . '/content/linked');

        $this->assertSame(5380, estimate_directory_bytes([$this->tempDir . '/content']));
    }

    /**
     * files-pull's index drops repeated paths, so content reachable through a link to a directory
     * and a link to its parent lands once — whichever link is followed first.
     */
    public function testLinksToADirectoryAndItsParentCountItOnce(): void
    {
        $parent = $this->writeOutside('shared', 5000);
        mkdir($parent . '/child');
        file_put_contents($parent . '/child/file.bin', str_repeat('y', 700));
        symlink($parent . '/child', $this->tempDir . '/content/to-child');
        symlink($parent, $this->tempDir . '/content/uploads/to-parent');

        $this->assertSame(6080, estimate_directory_bytes([$this->tempDir . '/content']));
    }

    /** A root listed before the root containing it is still counted once. */
    public function testADescendantRootListedFirstIsNotCountedTwice(): void
    {
        $this->assertSame(
            380,
            estimate_directory_bytes([$this->tempDir . '/content/uploads/2026', $this->tempDir . '/content'])
        );
    }

    /** Two links to one target are followed once, as files-pull's visited set does. */
    public function testTwoLinksToOneTargetCountItOnce(): void
    {
        $shared = $this->writeOutside('shared', 5000);
        symlink($shared, $this->tempDir . '/content/first');
        symlink($shared, $this->tempDir . '/content/uploads/second');

        $this->assertSame(5380, estimate_directory_bytes([$this->tempDir . '/content']));
    }

    /** A link back into a walked directory adds nothing, and a cycle ends. */
    public function testALinkIntoAWalkedDirectoryIsNotFollowed(): void
    {
        symlink($this->tempDir . '/content/uploads', $this->tempDir . '/content/uploads/2026/loop');
        symlink($this->tempDir . '/content', $this->tempDir . '/content/root-loop');

        $this->assertSame(380, estimate_directory_bytes([$this->tempDir . '/content']));
    }

    /**
     * Shared hosts often set open_basedir, and checking a symlink target outside it warns.
     * Preflight's error handler turns any unsuppressed warning into a failed request, so the
     * walk must stay silent: the target is skipped and the rest still counts.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnOpenBasedirRestrictionIsSkippedSilently(): void
    {
        symlink('/usr/share', $this->tempDir . '/content/linked');
        $allowed = [
            // The fixture, and where PHPUnit's separate process writes its result.
            realpath(sys_get_temp_dir()) . '/',
            dirname(__DIR__) . '/',
            dirname(PHP_BINARY, 2) . '/',
            '/opt/homebrew/',
        ];

        set_error_handler(function (int $errno, string $errstr): bool {
            if (error_reporting() & $errno) {
                throw new ErrorException($errstr, 0, $errno);
            }
            return true;
        });
        ini_set('open_basedir', implode(PATH_SEPARATOR, $allowed));
        try {
            $bytes = estimate_directory_bytes([$this->tempDir . '/content']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(380, $bytes);
    }

    /**
     * files-pull leaves out caches, VCS metadata and backup archives by default, so the
     * estimate does too. Other files in a backup directory still transfer.
     */
    public function testWhatFilesPullSkipsByDefaultIsNotCounted(): void
    {
        $content = $this->tempDir . '/site/wp-content';
        mkdir($content . '/cache/page', 0755, true);
        mkdir($content . '/plugins/plugin/.git', 0755, true);
        mkdir($content . '/updraft', 0755, true);
        file_put_contents($content . '/plugins/plugin/plugin.php', str_repeat('p', 200));
        file_put_contents($content . '/cache/page/index.html', str_repeat('c', 5000));
        file_put_contents($content . '/plugins/plugin/.git/pack', str_repeat('g', 7000));
        file_put_contents($content . '/updraft/backup_2026-09-24_site_uploads.zip', str_repeat('b', 9000));
        file_put_contents($content . '/updraft/notes.txt', str_repeat('n', 30));

        $this->assertSame(230, estimate_directory_bytes([$content]));
    }

    /** files-pull recreates a file symlink as a link; it transfers no content. */
    public function testASymlinkedFileCountsNothing(): void
    {
        $shared = $this->writeOutside('big', 9000);
        symlink($shared . '/file.bin', $this->tempDir . '/content/linked.bin');

        $this->assertSame(380, estimate_directory_bytes([$this->tempDir . '/content']));
    }

    /**
     * Core's rule, and the one that matters most: a walk that runs out of budget reports no
     * number at all. A partial sum that looks like a total would read as a small site.
     */
    public function testAnExhaustedBudgetReportsNoSize(): void
    {
        $budget = new ResourceBudget(microtime(true) - 10, 1, PHP_INT_MAX, 0.9);

        $this->assertNull(estimate_directory_bytes([$this->tempDir . '/content'], $budget));
    }

    public function testARemainingBudgetLetsTheWalkFinish(): void
    {
        $budget = new ResourceBudget(microtime(true), 30, PHP_INT_MAX, 0.9);

        $this->assertSame(380, estimate_directory_bytes([$this->tempDir . '/content'], $budget));
    }

    /**
     * A memory_limit PHP accepts but parse_size() can't read (PHP 8.1+ allows hex) must not fail
     * the budget; it falls back to PHP's 128M default.
     */
    public function testAnUnreadableMemoryLimitFallsBackToPhpsDefault(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Hex memory_limit values need PHP 8.1.');
        }

        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . 'echo ' . ResourceBudget::class . '::from_ini()->max_memory;';
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' -d memory_limit=0x20000000 -r ' . escapeshellarg($code) . ' 2>&1'
        );

        $this->assertSame((string) (128 * 1024 * 1024), trim((string) $output));
    }

    public function testADatabaseThatCannotBeAskedIsUnknown(): void
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertNull(estimate_database_bytes($sqlite, 'mysql'));
    }

    /**
     * The estimates as preflight reports them. Without a loaded WordPress, wp-content is found at
     * its conventional place under the scanned root, and everything in it counts.
     *
     * Isolated because other tests define ABSPATH and WordPress functions, which would make
     * preflight treat this process as a loaded WordPress with different paths.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreflightReportsEstimatedBytes(): void
    {
        $site = $this->tempDir . '/site';
        mkdir($site . '/wp-content/plugins/plugin', 0755, true);
        mkdir($site . '/wp-content/uploads', 0755, true);
        file_put_contents($site . '/wp-content/index.php', str_repeat('i', 10));
        file_put_contents($site . '/wp-content/plugins/plugin/plugin.php', str_repeat('p', 200));
        file_put_contents($site . '/wp-content/uploads/image.jpg', str_repeat('u', 3000));
        file_put_contents($site . '/outside-wp-content.php', str_repeat('o', 9000));

        ob_start();
        try {
            $preflight = endpoint_preflight(['directory' => [$site]]);
        } finally {
            ob_end_clean();
        }

        $this->assertSame(3210, $preflight['stats']['wp_content']['estimated_bytes']);
    }

    /** Preflight reports the database's size once it can connect. Isolated for its DB_* environment. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreflightReportsEstimatedDatabaseBytes(): void
    {
        $database = 'test_preflight_estimate';
        $server = new PDO('mysql:host=' . getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("DROP DATABASE IF EXISTS {$database}");
        $server->exec("CREATE DATABASE {$database}");
        $server->exec("CREATE TABLE {$database}.wp_posts (id INT PRIMARY KEY, content TEXT) ENGINE=InnoDB");
        $server->exec("INSERT INTO {$database}.wp_posts VALUES (1, REPEAT('x', 1000))");

        // Preflight reads WordPress's constant names; the suite's environment uses DB_PASS.
        putenv("DB_NAME={$database}");
        putenv('DB_PASSWORD=' . getenv('DB_PASS'));

        ob_start();
        try {
            $preflight = endpoint_preflight(['directory' => [$this->tempDir]]);
        } finally {
            ob_end_clean();
            $server->exec("DROP DATABASE IF EXISTS {$database}");
        }

        $this->assertGreaterThan(0, $preflight['stats']['database']['estimated_bytes']);
    }
}
