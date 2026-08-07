<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\relative_path_under;

/**
 * Coverage for the default deny-list applied by endpoint_file_index().
 *
 * Two layers of testing:
 *
 *   1. path_is_default_skipped() unit tests — exhaustive per-input
 *      classification of cache dirs, VCS metadata, OS junk, editor
 *      scratch, AND a long list of *negative* cases where a name
 *      looks superficially similar but should be preserved
 *      (.htaccess, .well-known, cache-control.css, etc.).
 *
 *   2. endpoint_file_index() integration tests via subprocess: build a
 *      fixture tree that mixes real-WP-shaped junk and real content,
 *      run the endpoint, decode the multipart response, and assert
 *      which entries appear and which were filtered. A separate run
 *      with include_caches=1 confirms the override turns the filter
 *      off cleanly.
 *
 * The unit tests are the safety net: silent over-skip would mean
 * silent data loss during migration, which is the worst failure mode.
 * The integration tests verify the filter is actually wired into the
 * traversal (and into traversal *resume*, since the cursor-after
 * pointer must advance past filtered entries).
 */
final class FileIndexSkipDefaultsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-skip-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Unit tests — path_is_default_skipped()
    // ------------------------------------------------------------------

    /**
     * @dataProvider skipCases
     */
    public function testPathIsDefaultSkippedClassifier(string $path, bool $expected): void
    {
        require_once __DIR__ . '/../packages/reprint-server/src/export.php';
        $this->assertSame($expected, path_is_default_skipped($path), "classifier for '$path'");
    }

    /**
     * @return array[] {
     *     Default skip classifier cases.
     *
     *     @type string $0 Path to classify.
     *     @type bool   $1 Expected skip result.
     * }
     * @phpstan-return list<array{0:string,1:bool}>
     */
    public static function skipCases(): array
    {
        return [
            // -------- generated caches under wp-content --------
            'wp-content cache dir entry'  => ['/srv/htdocs/wp-content/cache', true],
            'wp-content cache child file' => ['/srv/htdocs/wp-content/cache/page-cache/index.html', true],
            'wp-content upgrade'          => ['/srv/htdocs/wp-content/upgrade/wp-7.0-12345/wp-admin/about.php', true],
            'wpcomsh-cache'               => ['/srv/htdocs/wp-content/wpcomsh-cache/data.bin', true],
            'wflogs (Wordfence)'          => ['/srv/htdocs/wp-content/wflogs/attack-data.php', true],

            // Negative: file or dir whose NAME starts with cache- but is NOT inside the cache dir.
            'cache-control plugin css'    => ['/srv/htdocs/wp-content/plugins/cache-control/admin.css', false],
            'cache-this folder (user)'    => ['/srv/htdocs/wp-content/uploads/cache-this/notes.txt', false],
            'image with cache in name'    => ['/srv/htdocs/wp-content/uploads/2024/cache-page.png', false],
            // Negative: dir literally named "cache" but NOT under wp-content/ — out of our scope.
            'cache outside wp-content'    => ['/srv/htdocs/data/cache/x.json', false],

            // -------- VCS metadata --------
            '.git head'                   => ['/srv/htdocs/.git/HEAD', true],
            '.git objects'                => ['/srv/htdocs/.git/objects/12/ab', true],
            '.git in plugin'              => ['/srv/htdocs/wp-content/plugins/foo/.git/config', true],
            '.svn'                        => ['/srv/htdocs/.svn/entries', true],
            '.hg'                         => ['/srv/htdocs/.hg/store', true],
            '.bzr'                        => ['/srv/htdocs/.bzr/branch', true],

            // Negative: filename that CONTAINS .git but doesn't have it as a component.
            'gitignore (no leading dot)'  => ['/srv/htdocs/gitignore.md', false],
            'foo.gitkeep file'            => ['/srv/htdocs/foo.gitkeep', false],
            'longer name starts with .git'=> ['/srv/htdocs/.gitignore', false],
            'longer name starts with .git2'=> ['/srv/htdocs/.gitattributes', false],
            // .gitmodules is a real file in many themes, must NOT be skipped.
            '.gitmodules at root'         => ['/srv/htdocs/.gitmodules', false],

            // -------- dev tooling --------
            'node_modules'                => ['/srv/htdocs/wp-content/themes/foo/node_modules/react/index.js', true],
            '.idea'                       => ['/srv/htdocs/.idea/workspace.xml', true],
            '.vscode'                     => ['/srv/htdocs/.vscode/settings.json', true],
            '.cache anywhere'             => ['/srv/htdocs/wp-content/plugins/foo/.cache/parcel/x.json', true],
            '.npm in home'                => ['/srv/htdocs/.npm/_logs/run.log', true],
            '.yarn'                       => ['/srv/htdocs/.yarn/install-state.gz', true],

            // Negative: similar names that ARE legitimate user content.
            'directory with hyphen suffix' => ['/srv/htdocs/wp-content/themes/foo/node_modules-archive/x.js', false],
            'dot-file with similar name'   => ['/srv/htdocs/wp-content/themes/foo/.cached-bundle', false],
            'idea-pad app data'            => ['/srv/htdocs/wp-content/uploads/.idea-pad/notes.md', false],

            // -------- OS junk --------
            '.DS_Store'                   => ['/srv/htdocs/wp-content/.DS_Store', true],
            '._.DS_Store'                 => ['/srv/htdocs/wp-content/uploads/._.DS_Store', true],
            'Thumbs.db'                   => ['/srv/htdocs/wp-content/uploads/Thumbs.db', true],
            'desktop.ini'                 => ['/srv/htdocs/wp-content/desktop.ini', true],
            'ehthumbs.db'                 => ['/srv/htdocs/ehthumbs.db', true],

            // Negative: similar-but-different names.
            'ds-store-like.txt'           => ['/srv/htdocs/ds_store.txt', false],
            'thumbsdb-no-dot'             => ['/srv/htdocs/Thumbsdb', false],

            // -------- editor scratch --------
            'Vim swap .swp'               => ['/srv/htdocs/wp-config.php.swp', true],
            'Vim swap .swo'               => ['/srv/htdocs/foo.swo', true],
            'Vim swap .swn'               => ['/srv/htdocs/.foo.swn', true],
            'editor backup ~'             => ['/srv/htdocs/wp-content/themes/foo/style.css~', true],
            'generic .bak'                => ['/srv/htdocs/database.sql.bak', true],
            'merge .orig'                 => ['/srv/htdocs/file.php.orig', true],
            'merge .rej'                  => ['/srv/htdocs/file.php.rej', true],
            'Emacs lock .#name'           => ['/srv/htdocs/wp-content/themes/foo/.#style.css', true],
            'Emacs autosave #name#'       => ['/srv/htdocs/wp-content/themes/foo/#style.css#', true],

            // Negative: tilde in middle, not at end.
            'tilde in middle'             => ['/srv/htdocs/some~thing.txt', false],
            // Hash in middle, not bracketing.
            'hash in middle'              => ['/srv/htdocs/some#thing.txt', false],
            // Single # alone — shouldn't trigger autosave pattern.
            'single # file'               => ['/srv/htdocs/#', false],
            // Just a leading dot, not the .# emacs pattern.
            'leading dot only'            => ['/srv/htdocs/wp-content/.config.json', false],

            // -------- preserved dotfiles (must NOT skip) --------
            '.htaccess at root'           => ['/srv/htdocs/.htaccess', false],
            '.htaccess deep'              => ['/srv/htdocs/wp-content/uploads/.htaccess', false],
            '.user.ini'                   => ['/srv/htdocs/.user.ini', false],
            '.well-known/acme'            => ['/srv/htdocs/.well-known/acme-challenge/abc', false],
            '.well-known/security'        => ['/srv/htdocs/.well-known/security.txt', false],
            '.env (sensitive but kept)'   => ['/srv/htdocs/.env', false],
            'Plugin readme.txt'           => ['/srv/htdocs/wp-content/plugins/akismet/readme.txt', false],

            // -------- composite path traversals --------
            'cache-then-uploads (under cache)' => ['/srv/htdocs/wp-content/cache/uploads/photo.jpg', true],
            'uploads-then-cache-named-file'    => ['/srv/htdocs/wp-content/uploads/some-cache.zip', false],
            'theme + node_modules deep'        => ['/srv/htdocs/wp-content/themes/foo/build/node_modules/x.js', true],
        ];
    }

    // ------------------------------------------------------------------
    // Integration tests — endpoint_file_index() over the fixture
    // ------------------------------------------------------------------

    public function testFileIndexFiltersDefaultJunk(): void
    {
        $siteDir = $this->buildFixtureSite();
        $entries = $this->runFileIndexEntries($siteDir, /* include_caches */ false);

        $rel = $this->relativePaths($entries, $siteDir);

        // --- must be present (user content) ---
        $this->assertContains('index.php', $rel);
        $this->assertContains('.htaccess', $rel);
        $this->assertContains('.well-known/acme/abc', $rel);
        $this->assertContains('wp-content/themes/foo/style.css', $rel);
        $this->assertContains('wp-content/uploads/2024/01/photo.jpg', $rel);
        $this->assertContains('wp-content/uploads/some-cache.zip', $rel);
        $this->assertContains('wp-content/plugins/cache-control/admin.css', $rel);

        // --- must be filtered (junk / regenerable) ---
        $this->assertNotContains('wp-content/cache/page.html', $rel);
        $this->assertNotContains('wp-content/cache', $rel, 'cache dir entry itself should be skipped, not just its children');
        $this->assertNotContains('wp-content/upgrade/wp-7.0/file.php', $rel);
        $this->assertNotContains('wp-content/wpcomsh-cache/data.bin', $rel);
        $this->assertNotContains('wp-content/wflogs/attack-data.php', $rel);
        $this->assertNotContains('.git/HEAD', $rel);
        $this->assertNotContains('wp-content/themes/foo/node_modules/react.js', $rel);
        $this->assertNotContains('.DS_Store', $rel);
        $this->assertNotContains('wp-content/uploads/Thumbs.db', $rel);
        $this->assertNotContains('wp-content/themes/foo/style.css~', $rel);
        $this->assertNotContains('wp-config.php.swp', $rel);
        $this->assertNotContains('database.sql.bak', $rel);
        $this->assertNotContains('wp-content/themes/foo/.#style.css', $rel);
    }

    public function testFileIndexIncludesEverythingWhenOverrideEnabled(): void
    {
        $siteDir = $this->buildFixtureSite();
        $entries = $this->runFileIndexEntries($siteDir, /* include_caches */ true);
        $rel = $this->relativePaths($entries, $siteDir);

        // Override should ship the junk too.
        $this->assertContains('wp-content/cache/page.html', $rel);
        $this->assertContains('wp-content/upgrade/wp-7.0/file.php', $rel);
        $this->assertContains('.git/HEAD', $rel);
        $this->assertContains('wp-content/themes/foo/node_modules/react.js', $rel);
        $this->assertContains('.DS_Store', $rel);
        $this->assertContains('wp-content/themes/foo/style.css~', $rel);
    }

    public function testFileIndexNeverListsReprintStorage(): void
    {
        $siteDir = $this->buildFixtureSite();
        // Reprint's own storage inside the document root, plus a sibling
        // whose name shares the prefix and must survive the exclusion.
        $storage = $siteDir . '/wp-content/reprint-storage';
        mkdir($storage . '/files/wp-content/themes/foo', 0755, true);
        file_put_contents($storage . '/files/wp-content/themes/foo/style.css', 'staged');
        file_put_contents($storage . '/state.json', '{}');
        mkdir($siteDir . '/wp-content/reprint-storage-2', 0755, true);
        file_put_contents($siteDir . '/wp-content/reprint-storage-2/keep.txt', 'mine');

        // Configured with a trailing slash on purpose: the endpoint must
        // normalize the setting before comparing it against entry paths.
        $rel = $this->relativePaths(
            $this->runFileIndexEntries($siteDir, false, 5000, $storage . '/'),
            $siteDir
        );

        $this->assertNotContains('wp-content/reprint-storage', $rel);
        $this->assertNotContains('wp-content/reprint-storage/state.json', $rel);
        $this->assertNotContains('wp-content/reprint-storage/files/wp-content/themes/foo/style.css', $rel);
        $this->assertContains('wp-content/reprint-storage-2/keep.txt', $rel, 'a shared name prefix must not widen the exclusion');

        // include_caches=1 turns the junk filter off; it must not turn the
        // storage exclusion off.
        $withCaches = $this->relativePaths(
            $this->runFileIndexEntries($siteDir, true, 5000, $storage),
            $siteDir
        );
        $this->assertContains('wp-content/cache/page.html', $withCaches);
        $this->assertNotContains('wp-content/reprint-storage/state.json', $withCaches);
    }

    public function testFileIndexKeepsOnlyPhysicalEmptyDirectories(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir . '/empty', 0755, true);
        mkdir($siteDir . '/full', 0755, true);
        file_put_contents($siteDir . '/full/child.txt', 'child');
        mkdir($siteDir . '/skipped-only/.git', 0755, true);
        file_put_contents($siteDir . '/skipped-only/.git/HEAD', 'ref: refs/heads/main');
        $storage = $siteDir . '/storage-parent/reprint-storage';
        mkdir($storage, 0755, true);
        file_put_contents($storage . '/state.json', '{}');
        file_put_contents($siteDir . '/file.txt', 'file');

        $entries = $this->relativeEntries(
            $this->runFileIndexEntries($siteDir, false, 5000, $storage),
            $siteDir
        );

        foreach ($entries as $path => $entry) {
            if ($entry['type'] !== 'dir') {
                continue;
            }
            $this->assertArrayHasKey('empty', $entry, $path);
            $this->assertIsBool($entry['empty'], $path);
        }
        $this->assertTrue($entries['empty']['empty']);
        $this->assertArrayNotHasKey('full', $entries);
        $this->assertArrayNotHasKey('skipped-only', $entries);
        $this->assertArrayNotHasKey('storage-parent', $entries);
        $this->assertArrayNotHasKey('empty', $entries['file.txt']);
        $this->assertArrayNotHasKey('skipped-only/.git', $entries);
        $this->assertArrayNotHasKey('storage-parent/reprint-storage', $entries);
    }

    public function testFileIndexDoesNotClaimAnUnreadableDirectoryIsEmpty(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permissions do not restrict root.');
        }

        $siteDir = $this->tempDir . '/site';
        $unreadable = $siteDir . '/unreadable';
        mkdir($unreadable, 0755, true);
        chmod($unreadable, 0000);
        $probe = @opendir($unreadable);
        if ($probe !== false) {
            closedir($probe);
            chmod($unreadable, 0755);
            $this->markTestSkipped('Directory permissions did not prevent inspection.');
        }

        try {
            $entries = $this->relativeEntries(
                $this->runFileIndexEntries($siteDir, false),
                $siteDir
            );
            $this->assertSame('dir', $entries['unreadable']['type']);
            $this->assertArrayNotHasKey('empty', $entries['unreadable']);
        } finally {
            chmod($unreadable, 0755);
        }
    }

    public function testFileIndexFilterDoesNotBreakResume(): void
    {
        // The skip is applied AFTER the cursor's "after" pointer is updated,
        // so a paused-and-resumed traversal must produce identical output
        // (modulo cursor batch boundaries). Run the fixture with a very
        // small batch_size so multiple batches are emitted, then verify the
        // joined output matches the single-batch run.
        // batch_size has a server-side floor of 100 (see require_int_range
        // in endpoint_file_index), so the "small" run picks the minimum
        // that still forces multiple batches given our fixture size.
        $siteDir = $this->buildFixtureSite();
        $small = $this->runFileIndexEntries($siteDir, false, /* batch_size */ 100);
        $large = $this->runFileIndexEntries($siteDir, false, /* batch_size */ 5000);

        $this->assertSame(
            $this->relativePaths($large, $siteDir),
            $this->relativePaths($small, $siteDir),
            'small-batch traversal should produce identical entries to large-batch (filtered set is order-stable)'
        );
    }

    // ------------------------------------------------------------------
    // Fixture & runner
    // ------------------------------------------------------------------

    private function buildFixtureSite(): string
    {
        $site = $this->tempDir . '/site';
        mkdir($site, 0755, true);

        $files = [
            // user content (must be present)
            'index.php' => "<?php\n",
            '.htaccess' => "RewriteRule ^/.*$ index.php\n",
            '.well-known/acme/abc' => "abc",
            'wp-content/themes/foo/style.css' => ".x{}\n",
            'wp-content/uploads/2024/01/photo.jpg' => "fakejpg",
            'wp-content/uploads/some-cache.zip' => "userdata", // user-named, not in cache/
            'wp-content/plugins/cache-control/admin.css' => ".x{}\n", // plugin name contains "cache"

            // generated/junk (must be filtered)
            'wp-content/cache/page.html' => "cached",
            'wp-content/upgrade/wp-7.0/file.php' => "<?php\n",
            'wp-content/wpcomsh-cache/data.bin' => "bin",
            'wp-content/wflogs/attack-data.php' => "<?php\n",
            '.git/HEAD' => "ref: refs/heads/main\n",
            '.git/objects/12/abcdef' => "object",
            'wp-content/themes/foo/node_modules/react.js' => "// react",
            '.DS_Store' => "macos",
            'wp-content/uploads/Thumbs.db' => "win",
            'wp-content/themes/foo/style.css~' => ".old{}\n",
            'wp-config.php.swp' => "swap",
            'database.sql.bak' => "BACKUP",
            'wp-content/themes/foo/.#style.css' => "lock",
        ];

        foreach ($files as $rel => $body) {
            $abs = $site . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($abs, $body);
        }

        return $site;
    }

    /**
     * @return array[] {
     *     File-index entries.
     *
     *     @type string $path Indexed path.
     *     @type string $type  Indexed path type.
     *     @type bool   $empty Whether a directory was physically empty. Present
     *                         only when the directory could be inspected.
     * }
     * @phpstan-return list<array{path: string, type: string, empty?: bool}>
     */
    private function runFileIndexEntries(string $siteDir, bool $includeCaches, int $batchSize = 5000, ?string $storagePath = null): array
    {
        $stdout = $this->runFileIndex($siteDir, $includeCaches, $batchSize, $storagePath);

        // The response is `multipart/mixed; boundary="…"` containing one or
        // more `index_batch` JSON chunks. Parse out each batch and flatten.
        $entries = [];
        if (!preg_match('/^Content-Type: multipart\\/mixed; boundary="([^"]+)"/m', $stdout, $m)) {
            // gzip-framed response — decompress first, then re-parse the boundary
            $decoded = @gzdecode($stdout);
            if ($decoded === false) {
                $this->fail('Could not find multipart boundary in stdout and stream is not gzip framed.');
            }
            $stdout = $decoded;
        }

        // The actual boundary comes either from a Content-Type header (rare in
        // our CLI invocation; PHP's header() is a no-op there) or from the
        // boundary line itself. Find any `--<boundary>` line and use it.
        if (!preg_match('/^--(boundary-[A-Za-z0-9]+)/m', $stdout, $bm)) {
            $this->fail('No multipart boundary delimiter found in output.');
        }
        $boundary = $bm[1];

        $parts = explode('--' . $boundary, $stdout);
        foreach ($parts as $part) {
            if (strpos($part, 'X-Chunk-Type: index_batch') === false) {
                continue;
            }
            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }
            $body = substr($part, $headerEnd + 4);
            // Strip trailing CRLF (multipart adds one between body and the
            // next boundary line).
            $body = rtrim($body, "\r\n");
            // encode_index_batch() returns a bare array of items.
            $json = json_decode($body, true);
            if (!is_array($json)) {
                $this->fail('index_batch chunk did not decode to an array: ' . substr($body, 0, 200));
            }
            foreach ($json as $item) {
                $entry = [
                    'path' => base64_decode($item['path'], true),
                    'type' => $item['type'] ?? 'file',
                ];
                if (array_key_exists('empty', $item)) {
                    $entry['empty'] = $item['empty'];
                }
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function relativePaths(array $entries, string $siteDir): array
    {
        return array_keys($this->relativeEntries($entries, $siteDir));
    }

    /**
     * @return array<string,array{path:string,type:string,empty?:bool}>
     */
    private function relativeEntries(array $entries, string $siteDir): array
    {
        // endpoint_file_index() reports canonical paths. macOS exposes /tmp
        // and /var through /private symlinks, so compare against the same form.
        $siteRoot = realpath($siteDir) ?: $siteDir;
        $out = [];
        foreach ($entries as $e) {
            $relativePath = relative_path_under($e['path'], $siteRoot);
            if ($relativePath !== null && $relativePath !== '') {
                $out[$relativePath] = $e;
            }
        }
        ksort($out);
        return $out;
    }

    private function runFileIndex(string $siteDir, bool $includeCaches, int $batchSize, ?string $storagePath = null): string
    {
        $configPath = $this->tempDir . '/index-config.json';
        $config = [
            'directory' => $siteDir,
            'list_dir' => $siteDir,
            'batch_size' => $batchSize,
            'include_caches' => $includeCaches,
        ];
        if ($storagePath !== null) {
            $config['storage_path'] = $storagePath;
        }
        file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));

        $scriptPath = $this->tempDir . '/run-file-index.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
require_once %s;
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_index($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
                var_export(dirname(__DIR__) . '/packages/reprint-server/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_index should exit cleanly.\nstderr: {$stderr}");

        return $stdout;
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
