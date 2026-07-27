<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

class CliCommandRoutingTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-cli-routing-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/files';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testStatusUsesTheExistingMetadataContractWithoutAUrlOrFsRoot(): void
    {
        [$exit_code, $output] = $this->runCli([
            'status',
            '--porcelain=v1',
            '--state-dir=' . $this->stateDir,
        ]);

        $this->assertSame(0, $exit_code, $output);
        $this->assertSame(
            [
                'hasCompletedOnce' => false,
                'pullStage' => null,
            ],
            json_decode($output, true),
        );
        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
    }

    public function testStatusRejectsAnUnknownPorcelainVersion(): void
    {
        [$exit_code, $output] = $this->runCli([
            'status',
            '--porcelain=v2',
            '--state-dir=' . $this->stateDir,
        ]);

        $this->assertSame(1, $exit_code);
        $this->assertStringContainsString('Invalid --porcelain value: v2', $output);
    }

    public function testSourceCheckRequiresTheCachedModeMarker(): void
    {
        [$exit_code, $output] = $this->runCli([
            'source',
            'check',
            '--state-dir=' . $this->stateDir,
        ]);

        $this->assertSame(1, $exit_code);
        $this->assertStringContainsString('source check requires --cached', $output);
        $this->assertStringNotContainsString('<remote-url> is required', $output);
    }

    public function testSourceCheckReadsCachedStateWithoutAUrlOrFsRoot(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                'preflight' => [
                    'data' => [
                        'ok' => false,
                        'error' => 'cached failure',
                    ],
                    'http_code' => 503,
                ],
            ]),
        );

        [$exit_code, $output] = $this->runCli([
            'source',
            'check',
            '--cached',
            '--state-dir=' . $this->stateDir,
        ]);

        $this->assertSame(1, $exit_code);
        $this->assertStringContainsString('[FAIL] Server responded: HTTP 503', $output);
        $this->assertStringContainsString('[FAIL] Preflight OK: cached failure', $output);
        $this->assertStringNotContainsString('<remote-url> is required', $output);
    }

    public function testFilesStatsNeedsNoDummyUrl(): void
    {
        [$exit_code, $output] = $this->runCli([
            'files',
            'stats',
            '--state-dir=' . $this->stateDir,
            '--fs-root=' . $this->fsRoot,
        ]);

        $this->assertSame(0, $exit_code, $output);
        $stats = json_decode($output, true);
        $this->assertSame(0, $stats['indexed']['files']);
        $this->assertSame(0, $stats['pending']['files']);
    }

    public function testDatabaseDomainsNeedsNoDummyUrlOrFsRoot(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-domains.json',
            json_encode(['example.com', 'cdn.example.com']),
        );

        [$exit_code, $output] = $this->runCli([
            'database',
            'domains',
            '--state-dir=' . $this->stateDir,
        ]);

        $this->assertSame(0, $exit_code, $output);
        $this->assertSame("example.com\ncdn.example.com\n", $output);
    }

    public function testDatabaseApplyNeedsNoDummyUrl(): void
    {
        [$exit_code, $output] = $this->runCli([
            'database',
            'apply',
            '--state-dir=' . $this->stateDir,
            '--fs-root=' . $this->fsRoot,
            '--target-engine=sqlite',
        ]);

        $this->assertSame(1, $exit_code);
        $this->assertStringContainsString('Run database dump first', $output);
        $this->assertStringNotContainsString('<remote-url> is required', $output);
    }

    public function testOldAndPreferredStatusSpellingsReturnTheSameOutput(): void
    {
        [, $preferred_output] = $this->runCli([
            'status',
            '--porcelain=v1',
            '--state-dir=' . $this->stateDir,
        ]);
        [, $hidden_output] = $this->runCli([
            'import-metadata',
            '--state-dir=' . $this->stateDir,
        ]);

        $this->assertSame($hidden_output, $preferred_output);
    }

    public function testGroupedRemoteUrlSecretIsMaskedInTheAuditLog(): void
    {
        $secret = 'do-not-log';
        $this->runCli([
            'source',
            'inspect',
            'http://127.0.0.1:1/export.php?site-export-api&SECRET_KEY=' . $secret,
            '--state-dir=' . $this->stateDir,
            '--fs-root=' . $this->fsRoot,
        ]);

        $audit_log = file_get_contents($this->stateDir . '/.import-audit.log');
        $this->assertIsString($audit_log);
        $this->assertStringContainsString('COMMAND | preflight', $audit_log);
        $this->assertStringContainsString('SECRET_KEY=***', $audit_log);
        $this->assertStringNotContainsString($secret, $audit_log);
    }

    public function testGroupedOptionsAreRejectedByOtherCommands(): void
    {
        [$cached_exit_code, $cached_output] = $this->runCli([
            'files',
            'stats',
            '--cached',
            '--state-dir=' . $this->stateDir,
            '--fs-root=' . $this->fsRoot,
        ]);
        [$porcelain_exit_code, $porcelain_output] = $this->runCli([
            'files',
            'stats',
            '--porcelain=v1',
            '--state-dir=' . $this->stateDir,
            '--fs-root=' . $this->fsRoot,
        ]);

        $this->assertSame(1, $cached_exit_code);
        $this->assertStringContainsString('--cached is accepted only by source check', $cached_output);
        $this->assertSame(1, $porcelain_exit_code);
        $this->assertStringContainsString('--porcelain is accepted only by status', $porcelain_output);
    }

    public function testUnknownGroupedCommandDoesNotExposeHiddenNames(): void
    {
        [$exit_code, $output] = $this->runCli(['database', 'unknown']);

        $this->assertSame(1, $exit_code);
        $this->assertStringContainsString('Unknown command: database unknown', $output);
        $this->assertStringNotContainsString('db-pull', $output);
        $this->assertStringNotContainsString('db-index', $output);
        $this->assertStringNotContainsString('db-apply', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCli(array $arguments): array
    {
        $entry = __DIR__ . '/../../importer/import.php';
        $command = array_merge([PHP_BINARY, $entry], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            $this->fail('Failed to start Reprint CLI process');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function recursiveDelete(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
