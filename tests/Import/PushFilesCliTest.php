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
