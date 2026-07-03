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
        // The very first request is the status resync, so a dead target
        // surfaces as status_unavailable — classified retryable.
        $this->assertStringContainsString('status_unavailable', $output);
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
    public function testFullPushWithApplyLandsFilesInTheTargetRoot(): void
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

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "cannot allocate a port: {$errstr}");
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $harness . '/router.php'],
            [
                1 => ['file', $harness . '/server.log', 'a'],
                2 => ['file', $harness . '/server.log', 'a'],
            ],
            $pipes
        );
        $this->assertIsResource($server);

        try {
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

            file_put_contents($this->fs_root . '/index.php', '<?php // pushed');
            mkdir($this->fs_root . '/wp-content/uploads', 0700, true);
            $big = str_repeat('reprint!', 40000); // 320 KB: several append steps
            file_put_contents($this->fs_root . '/wp-content/uploads/a.bin', $big);

            $url = "http://127.0.0.1:{$port}/?reprint-api";
            $args = [
                'push-files',
                $url,
                '--secret=push-cli-e2e-secret',
                '--state-dir=' . $this->state_dir,
                '--fs-root=' . $this->fs_root,
                '--apply',
            ];

            [$output, $code] = $this->runCli($args);
            $this->assertSame(0, $code, $output);
            $this->assertSame('<?php // pushed', file_get_contents($site_root . '/index.php'));
            $this->assertSame($big, file_get_contents($site_root . '/wp-content/uploads/a.bin'));
            $this->assertStringContainsString('push_apply', $output);

            // Re-pushing the identical tree is a no-op that still succeeds:
            // uploads cache-skip, apply classifies everything as applied.
            [$again_output, $again_code] = $this->runCli($args);
            $this->assertSame(0, $again_code, $again_output);
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->removeDir($harness);
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
