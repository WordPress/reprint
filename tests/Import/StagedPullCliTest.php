<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * Drives the real CLI against a real WP-less export server (php -S serving
 * lib.php, the test-44 bootstrap): files-pull --staged-apply downloads into
 * the store under --state-dir and lands in --fs-root in one rename window.
 */
class StagedPullCliTest extends TestCase
{
    private const SECRET = 'staged-pull-cli-secret';

    private string $state_dir;

    private string $fs_root;

    private string $source_dir;

    private string $harness;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $base = (string) realpath(sys_get_temp_dir());
        $this->state_dir = $base . '/staged-pull-state-' . $suffix;
        $this->fs_root = $base . '/staged-pull-root-' . $suffix;
        $this->source_dir = $base . '/staged-pull-source-' . $suffix;
        $this->harness = $base . '/staged-pull-harness-' . $suffix;
        foreach ([$this->state_dir, $this->fs_root, $this->source_dir, $this->harness] as $dir) {
            mkdir($dir, 0700, true);
        }
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ([$this->state_dir, $this->fs_root, $this->source_dir, $this->harness] as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_link($entry) || !is_dir($entry)) {
                @unlink($entry);
            } else {
                $this->removeDir($entry);
            }
        }
        @rmdir($dir);
    }

    private function startServer(): void
    {
        $repo = (string) realpath(__DIR__ . '/../..');
        file_put_contents($this->harness . '/secret.php', "<?php return '" . self::SECRET . "';\n");
        file_put_contents(
            $this->harness . '/router.php',
            "<?php\n" .
            "define('ABSPATH', '{$this->harness}/abspath/');\n" .
            "define('SITE_EXPORT_PLUGIN_DIR', '{$repo}/reprint-exporter-wp/');\n" .
            "define('SITE_EXPORT_SECRET_FILE', '{$this->harness}/secret.php');\n" .
            "if (!function_exists('plugin_dir_path')) {\n" .
            "    function plugin_dir_path(\$file) { return rtrim(dirname(\$file), '/') . '/'; }\n" .
            "}\n" .
            "require_once SITE_EXPORT_PLUGIN_DIR . 'lib.php';\n" .
            "_site_export_handle_api_request();\n"
        );
        mkdir($this->harness . '/abspath', 0700, true);

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = (string) stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        // -t points DOCUMENT_ROOT at the source tree: the exporter
        // advertises it as a root, and it must not leak the harness or the
        // repo checkout into the export.
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $this->source_dir, $this->harness . '/router.php'],
            [
                1 => ['file', $this->harness . '/server.log', 'a'],
                2 => ['file', $this->harness . '/server.log', 'a'],
            ],
            $pipes
        );

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(100000);
        }
        $this->fail('php -S did not come up');
    }

    private function url(): string
    {
        return "http://127.0.0.1:{$this->port}/?reprint-api&directory[]=" . rawurlencode($this->source_dir);
    }

    private function baseArgs(array $extra = []): array
    {
        return array_merge([
            $this->url(),
            '--state-dir=' . $this->state_dir,
            '--fs-root=' . $this->fs_root,
            '--secret=' . self::SECRET,
        ], $extra);
    }

    /**
     * @return array{0:string,1:int}
     */
    private function runCli(string $command, array $extra = []): array
    {
        $entry = __DIR__ . '/../../importer/import.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry) . ' ' . escapeshellarg($command);
        foreach ($this->baseArgs($extra) as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $lines, $code);
        return [implode("\n", $lines), $code];
    }

    /** Runs a pull command to completion, following the exit-2 resume protocol. */
    private function pullToCompletion(array $extra = [], string $command = 'files-pull'): array
    {
        $output = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            [$out, $code] = $this->runCli($command, $extra);
            $output .= $out . "\n";
            if ($code !== 2) {
                return [$output, $code];
            }
        }
        return [$output, 2];
    }

    private function seedSource(): void
    {
        mkdir($this->source_dir . '/wp-content/uploads', 0700, true);
        mkdir($this->source_dir . '/wp-content/plugins/my-plugin', 0700, true);
        // An empty directory and a symlink: parts that are not file
        // content and must still respect the staged window.
        mkdir($this->source_dir . '/wp-content/empty-dir', 0700, true);
        symlink('index.php', $this->source_dir . '/link.php');
        // wp_detect needs these to recognize the directory as a root.
        file_put_contents($this->source_dir . '/wp-load.php', '<?php /* stub */');
        file_put_contents($this->source_dir . '/wp-config.php', '<?php /* stub config */');
        file_put_contents($this->source_dir . '/index.php', '<?php // remote index');
        file_put_contents($this->source_dir . '/empty.txt', '');
        file_put_contents($this->source_dir . '/wp-content/plugins/my-plugin/my-plugin.php', '<?php // plugin v1');
        file_put_contents($this->source_dir . '/wp-content/uploads/big.bin', str_repeat('reprint!', 40000));
    }

    /** Files, directories, and symlinks under $dir — the window must gate all three. */
    private function countEntries(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            ++$count;
        }
        return $count;
    }

    /**
     * Where the pulled tree lands: pull mirrors absolute remote paths
     * under --fs-root (flat-docroot reassembles them later), so the source
     * tree appears at fs_root + realpath(source).
     */
    private function mappedRoot(): string
    {
        return $this->fs_root . (string) realpath($this->source_dir);
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    public function testStagedPullLandsTheTreeInOneWindowAndDeltaReplaces(): void
    {
        $this->seedSource();
        $this->runCli('preflight');

        [$output, $code] = $this->pullToCompletion(['--staged-apply']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('staged_apply', $output);
        exec('diff -r ' . escapeshellarg($this->source_dir) . ' ' . escapeshellarg($this->mappedRoot()) . ' 2>&1', $diff_lines, $diff_code);
        $this->assertSame(0, $diff_code, implode("\n", $diff_lines));
        $this->assertSame(
            0,
            $this->countFiles($this->state_dir . '/.pull-staging/files'),
            'the apply window consumes the staged transfer'
        );

        // Delta: the remote plugin changes; the next staged pull replaces
        // it in place (no preserve-local), old bytes never truncated early.
        file_put_contents(
            $this->source_dir . '/wp-content/plugins/my-plugin/my-plugin.php',
            '<?php // plugin v2 — longer than before'
        );
        touch($this->source_dir . '/wp-content/plugins/my-plugin/my-plugin.php', time() + 5);
        // Deltas re-enter through --abort, the same way the delta e2e
        // scenarios drive files-pull: state clears, the local index
        // survives, and the rerun diffs against it.
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        [$delta_output, $delta_code] = $this->pullToCompletion(['--staged-apply']);

        $this->assertSame(0, $delta_code, $delta_output);
        $this->assertSame(
            '<?php // plugin v2 — longer than before',
            file_get_contents($this->mappedRoot() . '/wp-content/plugins/my-plugin/my-plugin.php'),
            $delta_output
        );
    }

    public function testMidPullFailureNeverTouchesFsRootAndResumes(): void
    {
        $this->seedSource();
        $this->runCli('preflight');

        // A held store lock makes the first staged write fail mid-fetch —
        // a deterministic interruption, no timing games. The essence under
        // test: no failure between download start and apply may leave
        // anything in the live tree.
        $staging = $this->state_dir . '/.pull-staging';
        mkdir($staging, 0700, true);
        $holder = fopen($staging . '/lock', 'c+b');
        flock($holder, LOCK_EX);

        [$held_output, $held_code] = $this->runCli('files-pull', ['--staged-apply']);
        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertNotSame(0, $held_code, $held_output);
        $this->assertSame(
            0,
            $this->countEntries($this->fs_root),
            'an interrupted staged pull must leave the live tree untouched — no files, directories, or symlinks'
        );

        [$output, $code] = $this->pullToCompletion(['--staged-apply']);

        $this->assertSame(0, $code, $output);
        exec('diff -r ' . escapeshellarg($this->source_dir) . ' ' . escapeshellarg($this->mappedRoot()) . ' 2>&1', $diff_lines, $diff_code);
        $this->assertSame(0, $diff_code, implode("\n", $diff_lines));
        $this->assertTrue(is_link($this->mappedRoot() . '/link.php'), 'the deferred symlink lands with the window');
        $this->assertDirectoryExists($this->mappedRoot() . '/wp-content/empty-dir');
    }

    public function testAbortSwitchesModesCleanlyInBothDirections(): void
    {
        // Modes are sticky per state dir (like preserve-local); --abort is
        // the documented switch. Staged, interrupted, aborted, finished
        // unstaged — then a remote change pulled staged again. The tree
        // must come out complete every time: the index never claims
        // anything the live tree does not have, so no delta ever skips a
        // file the previous mode left behind.
        $this->seedSource();
        $this->runCli('preflight');

        $staging = $this->state_dir . '/.pull-staging';
        mkdir($staging, 0700, true);
        $holder = fopen($staging . '/lock', 'c+b');
        flock($holder, LOCK_EX);
        [$held_output, $held_code] = $this->runCli('files-pull', ['--staged-apply']);
        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertNotSame(0, $held_code, $held_output);

        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        $this->assertDirectoryDoesNotExist($staging, '--abort clears staged leftovers');

        // Unstaged from here: no --staged-apply flag, fresh state.
        [$output, $code] = $this->pullToCompletion();
        $this->assertSame(0, $code, $output);
        exec('diff -r ' . escapeshellarg($this->source_dir) . ' ' . escapeshellarg($this->mappedRoot()) . ' 2>&1', $diff_lines, $diff_code);
        $this->assertSame(0, $diff_code, implode("\n", $diff_lines));

        // Back to staged for a delta.
        file_put_contents($this->source_dir . '/index.php', '<?php // remote index v2');
        touch($this->source_dir . '/index.php', time() + 5);
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        [$output, $code] = $this->pullToCompletion(['--staged-apply']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(
            '<?php // remote index v2',
            file_get_contents($this->mappedRoot() . '/index.php')
        );
        $this->assertStringContainsString('staged_apply', $output, 'the delta ran through the window');
    }

    public function testPreserveLocalPoliciesHoldAtApplyTime(): void
    {
        $this->seedSource();
        // Pre-seed the target at the MAPPED paths (pull mirrors absolute
        // remote paths under fs-root): a local file that must win, and a
        // symlinked plugins dir nothing may be created through.
        $mapped = $this->mappedRoot();
        mkdir($mapped . '/wp-content', 0700, true);
        file_put_contents($mapped . '/index.php', 'local wins');
        $shared = $this->fs_root . '-shared-plugins';
        mkdir($shared, 0700, true);
        symlink($shared, $mapped . '/wp-content/plugins');

        try {
            $this->runCli('preflight');
            [$output, $code] = $this->pullToCompletion([
                '--staged-apply',
                '--on-fs-root-nonempty=preserve-local',
            ]);

            $this->assertSame(0, $code, $output);
            $this->assertSame('local wins', file_get_contents($mapped . '/index.php'));
            $this->assertSame([], glob($shared . '/*'), 'nothing may appear behind the symlink');
            // Protection fires at download time (trunk's write-time checks
            // stay active in staged mode, so protected files are never even
            // fetched); the apply engine enforces the same policies as a
            // backstop. Either way, every skip is audit-logged.
            $audit = (string) @file_get_contents($this->state_dir . '/.import-audit.log');
            $this->assertStringContainsString(
                'PRESERVE-LOCAL skip file',
                $audit,
                'every protected path is audit-logged'
            );
            $this->assertSame(
                str_repeat('reprint!', 40000),
                file_get_contents($mapped . '/wp-content/uploads/big.bin'),
                'unprotected content still lands'
            );
        } finally {
            $this->removeDir($shared);
        }
    }

    public function testPreserveLocalDeltaStillUpdatesFilesWeOwn(): void
    {
        // Trunk's contract: "preserve-local does not protect files we own"
        // — a file the pull itself shipped re-downloads and replaces on
        // delta. The apply window must not re-protect it just because its
        // own previous copy occupies the path.
        $this->seedSource();
        $this->runCli('preflight');
        [$output, $code] = $this->pullToCompletion([
            '--staged-apply',
            '--on-fs-root-nonempty=preserve-local',
        ]);
        $this->assertSame(0, $code, $output);
        $this->assertSame(
            '<?php // plugin v1',
            file_get_contents($this->mappedRoot() . '/wp-content/plugins/my-plugin/my-plugin.php')
        );

        file_put_contents(
            $this->source_dir . '/wp-content/plugins/my-plugin/my-plugin.php',
            '<?php // plugin v2, still ours'
        );
        touch($this->source_dir . '/wp-content/plugins/my-plugin/my-plugin.php', time() + 5);
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        [$delta_output, $delta_code] = $this->pullToCompletion([
            '--staged-apply',
            '--on-fs-root-nonempty=preserve-local',
        ]);

        $this->assertSame(0, $delta_code, $delta_output);
        $this->assertSame(
            '<?php // plugin v2, still ours',
            file_get_contents($this->mappedRoot() . '/wp-content/plugins/my-plugin/my-plugin.php'),
            'an owned file must update on delta even under preserve-local'
        );
    }

    public function testRemoteDeletionsLandInsideTheApplyWindow(): void
    {
        $this->seedSource();
        $this->runCli('preflight');
        [$output, $code] = $this->pullToCompletion(['--staged-apply']);
        $this->assertSame(0, $code, $output);
        $mapped = $this->mappedRoot();
        $this->assertFileExists($mapped . '/empty.txt');

        // The remote drops two files and updates a third — a delta mixing
        // deletions with an arrival.
        unlink($this->source_dir . '/empty.txt');
        unlink($this->source_dir . '/wp-content/plugins/my-plugin/my-plugin.php');
        file_put_contents($this->source_dir . '/index.php', '<?php // remote index v2');
        touch($this->source_dir . '/index.php', time() + 5);
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);

        // A held store lock fails the delta mid-fetch. Deletions used to
        // happen at diff time; in staged mode nothing — removals included —
        // may touch the live tree before the window. (--abort cleared the
        // staging dir, so recreate the scaffolding to plant the lock.)
        @mkdir($this->state_dir . '/.pull-staging', 0700, true);
        $holder = fopen($this->state_dir . '/.pull-staging/lock', 'c+b');
        flock($holder, LOCK_EX);
        [$held_output, $held_code] = $this->runCli('files-pull', ['--staged-apply']);
        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertNotSame(0, $held_code, $held_output);
        $this->assertFileExists(
            $mapped . '/empty.txt',
            'a deletion must wait for the apply window'
        );
        $this->assertFileExists($mapped . '/wp-content/plugins/my-plugin/my-plugin.php');
        $this->assertSame('<?php // remote index', file_get_contents($mapped . '/index.php'));

        // An abort in between must not orphan the pending deletions: the
        // index still owns the files, so the fresh delta re-derives them.
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        [$output, $code] = $this->pullToCompletion(['--staged-apply']);

        $this->assertSame(0, $code, $output);
        $this->assertFileDoesNotExist($mapped . '/empty.txt');
        $this->assertFileDoesNotExist($mapped . '/wp-content/plugins/my-plugin/my-plugin.php');
        $this->assertSame('<?php // remote index v2', file_get_contents($mapped . '/index.php'));
        $audit = (string) @file_get_contents($this->state_dir . '/.import-audit.log');
        $this->assertStringContainsString('Deletion staged for the apply window', $audit);
        $this->assertStringContainsString('deleted=2', $audit);

        // The confirmed deletions left the index; the next delta derives
        // nothing new for them.
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        [$output, $code] = $this->pullToCompletion(['--staged-apply']);
        $this->assertSame(0, $code, $output);
        $this->assertFileDoesNotExist($mapped . '/empty.txt');
        $audit = (string) @file_get_contents($this->state_dir . '/.import-audit.log');
        $this->assertSame(
            0,
            substr_count(explode('deleted=2', $audit, 2)[1] ?? '', 'Deletion staged for the apply window'),
            'a confirmed deletion must not re-derive on the next delta'
        );
    }

    public function testPreserveLocalStillDeletesFilesWeOwn(): void
    {
        // Ownership beats occupancy for deletions too: a file the pull
        // shipped disappears remotely, and preserve-local — which protects
        // what the sync never owned — does not keep our own stale copy.
        $this->seedSource();
        $this->runCli('preflight');
        [$output, $code] = $this->pullToCompletion([
            '--staged-apply',
            '--on-fs-root-nonempty=preserve-local',
        ]);
        $this->assertSame(0, $code, $output);
        $this->assertFileExists($this->mappedRoot() . '/empty.txt');

        unlink($this->source_dir . '/empty.txt');
        [$abort_output, $abort_code] = $this->runCli('files-pull', ['--abort']);
        $this->assertSame(0, $abort_code, $abort_output);
        [$delta_output, $delta_code] = $this->pullToCompletion([
            '--staged-apply',
            '--on-fs-root-nonempty=preserve-local',
        ]);

        $this->assertSame(0, $delta_code, $delta_output);
        $this->assertFileDoesNotExist(
            $this->mappedRoot() . '/empty.txt',
            'preserve-local does not protect files we own from their own deletion'
        );
    }

    public function testCrossDeviceStateDirIsRefusedBeforeDownloading(): void
    {
        if (!is_dir('/dev/shm')) {
            $this->markTestSkipped('needs a tmpfs to make a real device boundary');
        }
        $this->seedSource();
        $xdev_state = '/dev/shm/staged-pull-xdev-' . bin2hex(random_bytes(6));
        mkdir($xdev_state, 0700, true);

        try {
            $entry = __DIR__ . '/../../importer/import.php';
            $args = [
                $this->url(),
                '--state-dir=' . $xdev_state,
                '--fs-root=' . $this->fs_root,
                '--secret=' . self::SECRET,
            ];
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry) . ' preflight';
            foreach ($args as $arg) {
                $cmd .= ' ' . escapeshellarg($arg);
            }
            exec($cmd . ' 2>&1');

            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry) . ' files-pull';
            foreach (array_merge($args, ['--staged-apply']) as $arg) {
                $cmd .= ' ' . escapeshellarg($arg);
            }
            exec($cmd . ' 2>&1', $lines, $code);
            $output = implode("\n", $lines);

            $this->assertSame(1, $code, $output);
            $this->assertStringContainsString('cross_device', $output);
            $this->assertSame(0, $this->countFiles($this->fs_root), 'nothing may download');
        } finally {
            $this->removeDir($xdev_state);
        }
    }
}
