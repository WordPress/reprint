<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * Drives the real CLI in a subprocess, like OnlyCliParseTest: argument
 * parsing, dispatch, exit codes, and the no-network paths of push-files.
 */
class PushFilesCliTest extends TestCase
{
    private string $state_dir;

    private string $fs_root;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->state_dir = sys_get_temp_dir() . '/push-cli-state-' . $suffix;
        $this->fs_root = sys_get_temp_dir() . '/push-cli-root-' . $suffix;
        mkdir($this->state_dir, 0700, true);
        mkdir($this->fs_root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->state_dir, $this->fs_root] as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    /**
     * @return array{0:string,1:int} Combined output and exit code.
     */
    private function runCli(array $args): array
    {
        $entry = __DIR__ . '/../../importer/import.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $lines, $code);
        return [implode("\n", $lines), $code];
    }

    public function testHelpDescribesTheCommand(): void
    {
        [$output] = $this->runCli(['push-files', '--help']);

        $this->assertStringContainsString('reprint push-files <remote-url>', $output);
        $this->assertStringContainsString('staged artifact store', $output);
        $this->assertStringContainsString('--secret', $output);
        $this->assertStringContainsString('--only', $output);
    }

    public function testMissingSecretFailsWithATypedError(): void
    {
        [$output, $code] = $this->runCli([
            'push-files',
            'http://127.0.0.1:1/?reprint-api',
            '--state-dir=' . $this->state_dir,
            '--fs-root=' . $this->fs_root,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('requires --secret', $output);
    }

    public function testEmptyTreeCompletesWithoutTouchingTheNetwork(): void
    {
        // Port 1 would refuse instantly; an empty plan must never get there.
        [$output, $code] = $this->runCli([
            'push-files',
            'http://127.0.0.1:1/?reprint-api',
            '--secret=test-secret',
            '--state-dir=' . $this->state_dir,
            '--fs-root=' . $this->fs_root,
        ]);

        $this->assertSame(0, $code, $output);
        // The summary is the pretty-printed JSON block at the end of the
        // output, after the single-line progress records.
        $summary = json_decode((string) substr($output, (int) strrpos($output, "{\n")), true);
        $this->assertSame('complete', $summary['status']);
        $this->assertSame(0, $summary['files_total']);
        $this->assertFileExists($this->state_dir . '/.push-state.json');
    }

    public function testUnreachableTargetAbortsWithTheRetryableExitCode(): void
    {
        file_put_contents($this->fs_root . '/wp-config-sample.php', '<?php');

        [$output, $code] = $this->runCli([
            'push-files',
            'http://127.0.0.1:1/?reprint-api',
            '--secret=test-secret',
            '--state-dir=' . $this->state_dir,
            '--fs-root=' . $this->fs_root,
        ]);

        $this->assertSame(2, $code, 'a transient connection failure follows the resume convention');
        // Small files travel batched, so the dead target surfaces on the
        // batch POST as transport_failed — classified retryable.
        $this->assertStringContainsString('transport_failed', $output);
        $this->assertFileExists(
            $this->state_dir . '/.push-state.json',
            'learned sizer state persists across the abort'
        );
    }

    /**
     * The full sender-to-target loop against a real HTTP server: the
     * plugin's lib.php serving the staged endpoints WP-less (the same
     * bootstrap the atomic e2e scenarios use), the real CLI pushing with
     * --apply, files landing in the target root by rename.
     */
    /** @var resource|null */
    private $apply_server = null;

    private string $apply_harness = '';

    /**
     * Serves lib.php WP-less over php -S with staging and an apply root,
     * like the atomic e2e sites do. Returns [url, site_root, staging_dir].
     *
     * @return array{0:string,1:string,2:string}
     */
    private function startApplyHarness(): array
    {
        $harness = sys_get_temp_dir() . '/push-cli-harness-' . bin2hex(random_bytes(8));
        $site_root = $harness . '/site';
        mkdir($site_root, 0700, true);
        mkdir($harness . '/abspath', 0700, true);

        $repo = (string) realpath(__DIR__ . '/../..');
        file_put_contents($harness . '/secret.php', "<?php return 'push-cli-e2e-secret';\n");
        file_put_contents(
            $harness . '/router.php',
            "<?php\n" .
            "define('ABSPATH', '{$harness}/abspath/');\n" .
            "define('SITE_EXPORT_PLUGIN_DIR', '{$repo}/reprint-exporter-wp/');\n" .
            "define('SITE_EXPORT_SECRET_FILE', '{$harness}/secret.php');\n" .
            "define('SITE_EXPORT_STAGING_DIR', '{$harness}/staging');\n" .
            "define('SITE_EXPORT_APPLY_ROOT', '{$site_root}');\n" .
            "if (!function_exists('plugin_dir_path')) {\n" .
            "    function plugin_dir_path(\$file) { return rtrim(dirname(\$file), '/') . '/'; }\n" .
            "}\n" .
            "require_once SITE_EXPORT_PLUGIN_DIR . 'lib.php';\n" .
            "_site_export_handle_api_request();\n"
        );

        return [$this->serveRouter($harness), $site_root, $harness . '/staging'];
    }

    /** Serves $harness/router.php over php -S and returns the API url. */
    private function serveRouter(string $harness): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "cannot allocate a port: {$errstr}");
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $this->apply_server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $harness . '/router.php'],
            [
                1 => ['file', $harness . '/server.log', 'a'],
                2 => ['file', $harness . '/server.log', 'a'],
            ],
            $pipes
        );
        $this->assertIsResource($this->apply_server);
        $this->apply_harness = $harness;

        $deadline = microtime(true) + 10;
        $ready = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        $this->assertTrue($ready, 'php -S did not come up');

        return "http://127.0.0.1:{$port}/?reprint-api";
    }

    private function stopApplyHarness(): void
    {
        if (is_resource($this->apply_server)) {
            proc_terminate($this->apply_server);
            proc_close($this->apply_server);
            $this->apply_server = null;
        }
        if ($this->apply_harness !== '') {
            $this->removeDir($this->apply_harness);
            $this->apply_harness = '';
        }
    }

    private function pushArgs(string $url): array
    {
        return [
            'push-files',
            $url,
            '--secret=push-cli-e2e-secret',
            '--state-dir=' . $this->state_dir,
            '--fs-root=' . $this->fs_root,
            '--apply',
        ];
    }

    public function testFullPushWithApplyLandsFilesInTheTargetRoot(): void
    {
        [$url, $site_root] = $this->startApplyHarness();
        try {
            file_put_contents($this->fs_root . '/index.php', '<?php // pushed');
            mkdir($this->fs_root . '/wp-content/uploads', 0700, true);
            $big = str_repeat('reprint!', 40000); // 320 KB: several append steps
            file_put_contents($this->fs_root . '/wp-content/uploads/a.bin', $big);

            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $this->assertSame('<?php // pushed', file_get_contents($site_root . '/index.php'));
            $this->assertSame($big, file_get_contents($site_root . '/wp-content/uploads/a.bin'));
            $this->assertStringContainsString('push_apply', $output);

            // Re-pushing the identical tree is a no-op that still succeeds:
            // uploads cache-skip, apply classifies everything as applied.
            [$again_output, $again_code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $again_code, $again_output);
        } finally {
            $this->stopApplyHarness();
        }
    }

    public function testInterruptedPushResumesFromTheCommittedOffset(): void
    {
        [$url, $site_root, $staging] = $this->startApplyHarness();
        try {
            mkdir($this->fs_root . '/wp-content/uploads', 0700, true);
            $big = str_repeat('resumable-bytes', 20000); // 300 KB
            file_put_contents($this->fs_root . '/wp-content/uploads/a.bin', $big);

            // A previous push died mid-artifact: the target already holds a
            // committed prefix. The next push must continue from it over
            // HTTP, not restart or corrupt.
            $store = new \Site_Export_Staged_Artifacts($staging);
            $prefix = substr($big, 0, 100000);
            $this->assertSame(
                'accepted',
                $store->append('wp-content/uploads/a.bin', 0, $prefix)['status']
            );

            [$output, $code] = $this->runCli($this->pushArgs($url));

            $this->assertSame(0, $code, $output);
            $this->assertSame(
                $big,
                file_get_contents($site_root . '/wp-content/uploads/a.bin'),
                'the resumed artifact must assemble byte-identical'
            );
        } finally {
            $this->stopApplyHarness();
        }
    }

    public function testAdversarialFilenamesSurviveTheWire(): void
    {
        [$url, $site_root] = $this->startApplyHarness();
        try {
            // Names that stress the query-string transport (space, plus,
            // percent, ampersand, quotes, unicode) and the store's
            // record-lookalike handling.
            $names = [
                'file with space.txt',
                '100%+done.txt',
                'name&param=value.txt',
                "it's \"quoted\".txt",
                'emoji-😀.php',
                'wp-content/uploads/déjà vu.bin',
            ];
            foreach ($names as $index => $name) {
                $path = $this->fs_root . '/' . $name;
                @mkdir(dirname($path), 0700, true);
                file_put_contents($path, "body #{$index} of {$name}");
            }

            [$output, $code] = $this->runCli($this->pushArgs($url));

            $this->assertSame(0, $code, $output);
            foreach ($names as $index => $name) {
                $this->assertSame(
                    "body #{$index} of {$name}",
                    file_get_contents($site_root . '/' . $name),
                    "mangled in transit: {$name}"
                );
            }

            // Deletion ids ride the same manifest lines, so the weird
            // names must survive that direction too.
            unlink($this->fs_root . '/100%+done.txt');
            unlink($this->fs_root . '/emoji-😀.php');
            [$output, $code] = $this->runCli($this->pushArgs($url));

            $this->assertSame(0, $code, $output);
            $summary = json_decode((string) substr($output, (int) strrpos($output, "{\n")), true);
            $this->assertSame(2, $summary['deleted']);
            $this->assertFileDoesNotExist($site_root . '/100%+done.txt');
            $this->assertFileDoesNotExist($site_root . '/emoji-😀.php');
            $this->assertFileExists($site_root . '/file with space.txt', 'the survivors stay');
        } finally {
            $this->stopApplyHarness();
        }
    }

    public function testInsufficientStagingSpaceRefusesBeforeUploading(): void
    {
        // A scripted target whose probe answers ready with 1 byte free: the
        // gate must refuse the transfer without a single upload request.
        $harness = sys_get_temp_dir() . '/push-cli-space-' . bin2hex(random_bytes(8));
        mkdir($harness, 0700, true);
        file_put_contents(
            $harness . '/router.php',
            "<?php\n" .
            "file_put_contents('{$harness}/requests.log', (\$_GET['endpoint'] ?? '?') . \"\\n\", FILE_APPEND);\n" .
            "header('Content-Type: application/json');\n" .
            "echo json_encode(['status' => 'ready', 'staging_free_bytes' => 1, 'target_free_bytes' => 1, 'max_request_bytes' => 65536]);\n"
        );
        $url = $this->serveRouter($harness);
        try {
            file_put_contents($this->fs_root . '/too-big.bin', str_repeat('x', 4096));

            [$output, $code] = $this->runCli($this->pushArgs($url));

            $this->assertSame(1, $code, $output);
            $this->assertStringContainsString('insufficient_staging_space', $output);
            $this->assertSame(
                ['staged_apply'],
                array_unique(array_filter(explode("\n", (string) file_get_contents($harness . '/requests.log')))),
                'the probe must be the only request'
            );
        } finally {
            $this->stopApplyHarness();
        }
    }

    public function testLocalDeletionPropagatesOnTheNextPushApply(): void
    {
        [$url, $site_root] = $this->startApplyHarness();
        try {
            file_put_contents($this->fs_root . '/a.txt', 'stays');
            file_put_contents($this->fs_root . '/b.txt', 'will be removed');
            mkdir($this->fs_root . '/wp-content', 0700, true);
            file_put_contents($this->fs_root . '/wp-content/c.php', '<?php // stays');

            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $this->assertFileExists($site_root . '/b.txt');

            // The file disappears locally; the next push must remove it
            // from the target in the same apply window.
            unlink($this->fs_root . '/b.txt');
            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $summary = json_decode((string) substr($output, (int) strrpos($output, "{\n")), true);
            $this->assertSame('complete', $summary['status']);
            $this->assertSame(1, $summary['deleted']);
            $this->assertFileDoesNotExist($site_root . '/b.txt');
            $this->assertSame('stays', file_get_contents($site_root . '/a.txt'));
            $this->assertSame('<?php // stays', file_get_contents($site_root . '/wp-content/c.php'));

            // The deletion left the done cache with the apply: rerunning
            // does not re-delete or fail.
            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $summary = json_decode((string) substr($output, (int) strrpos($output, "{\n")), true);
            $this->assertSame(0, $summary['deleted']);

            // A kill between the confirmed apply and the cache update
            // resurrects the stale line. The next push re-derives the
            // deletion, which no-ops (the path is already gone) and heals
            // the cache.
            $verified_path = $this->state_dir . '/.push-verified.jsonl';
            file_put_contents(
                $verified_path,
                "\n" . json_encode(['artifact_id' => 'b.txt', 'size' => 15, 'mtime' => 1]) . "\n",
                FILE_APPEND
            );
            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $summary = json_decode((string) substr($output, (int) strrpos($output, "{\n")), true);
            $this->assertSame(0, $summary['deleted']);
            $this->assertStringNotContainsString(
                'b.txt',
                (string) file_get_contents($verified_path),
                'the resurrected line is forgotten again'
            );

            // The deletion broke something? Restoring the file locally
            // ships it back on the next push — the fix-forward loop.
            file_put_contents($this->fs_root . '/b.txt', 'restored, new content');
            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $this->assertSame('restored, new content', file_get_contents($site_root . '/b.txt'));
        } finally {
            $this->stopApplyHarness();
        }
    }

    public function testOnlyScopedPushDerivesDeletionsOnlyInsideItsPrefixes(): void
    {
        [$url, $site_root] = $this->startApplyHarness();
        try {
            file_put_contents($this->fs_root . '/out.txt', 'outside the scope');
            mkdir($this->fs_root . '/wp-content', 0700, true);
            file_put_contents($this->fs_root . '/wp-content/in.txt', 'inside');
            file_put_contents($this->fs_root . '/wp-content/keep.txt', 'kept');

            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);

            // Both files vanish locally, but the scoped push may only act
            // inside its prefixes: out.txt was excluded from the plan on
            // purpose, not removed relative to it.
            unlink($this->fs_root . '/out.txt');
            unlink($this->fs_root . '/wp-content/in.txt');
            [$output, $code] = $this->runCli(array_merge($this->pushArgs($url), ['--only=wp-content']));
            $this->assertSame(0, $code, $output);
            $this->assertFileDoesNotExist($site_root . '/wp-content/in.txt');
            $this->assertFileExists($site_root . '/out.txt', 'out-of-scope paths must survive a scoped push');

            // The next full push sees out.txt gone from the plan and
            // finishes the deletion.
            [$output, $code] = $this->runCli($this->pushArgs($url));
            $this->assertSame(0, $code, $output);
            $this->assertFileDoesNotExist($site_root . '/out.txt');
            $this->assertSame('kept', file_get_contents($site_root . '/wp-content/keep.txt'));
        } finally {
            $this->stopApplyHarness();
        }
    }

    public function testHostileOnlyPrefixIsRejectedBeforeAnyUpload(): void
    {
        file_put_contents($this->fs_root . '/a.txt', 'x');

        [$output, $code] = $this->runCli([
            'push-files',
            'http://127.0.0.1:1/?reprint-api',
            '--secret=test-secret',
            '--state-dir=' . $this->state_dir,
            '--fs-root=' . $this->fs_root,
            '--only=../outside',
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Invalid --only prefix', $output);
    }
}
