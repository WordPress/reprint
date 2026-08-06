<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * The rename to client/ and bin/reprint-client kept compatibility entry
 * points behind the old names. Each one must stay executable and produce
 * exactly the output of its canonical counterpart, and the install-exporter
 * command alias must render the same guide as install-server.
 */
class CompatibilityEntryPointsTest extends TestCase
{
    /** @return array{0: string, 1: int} Output and exit code. */
    private function runCli(string $entry, array $arguments): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry);
        foreach ($arguments as $argument) {
            $cmd .= ' ' . escapeshellarg($argument);
        }
        exec($cmd . ' 2>&1', $output_lines, $exit_code);
        return [implode("\n", $output_lines), $exit_code];
    }

    public function testImporterShimMatchesClientEntryPoint(): void
    {
        $shim = __DIR__ . '/../../importer/import.php';
        $canonical = __DIR__ . '/../../client/import.php';

        $this->assertTrue(is_executable($shim), 'importer/import.php must stay executable');

        [$shim_output, $shim_exit] = $this->runCli($shim, ['--help']);
        [$canonical_output, $canonical_exit] = $this->runCli($canonical, ['--help']);

        $this->assertSame($canonical_output, $shim_output);
        $this->assertSame($canonical_exit, $shim_exit);
        $this->assertStringContainsString('Usage: reprint', $shim_output);
    }

    public function testReprintImporterBinMatchesReprintClientBin(): void
    {
        $legacy_bin = __DIR__ . '/../../packages/reprint-client/bin/reprint-importer';
        $canonical_bin = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

        $this->assertTrue(is_executable($legacy_bin), 'bin/reprint-importer must stay executable');

        [$legacy_output, $legacy_exit] = $this->runCli($legacy_bin, ['--help']);
        [$canonical_output, $canonical_exit] = $this->runCli($canonical_bin, ['--help']);

        $this->assertSame($canonical_output, $legacy_output);
        $this->assertSame($canonical_exit, $legacy_exit);
        $this->assertStringContainsString('Usage: reprint', $legacy_output);
    }

    public function testInstallExporterAliasRendersTheInstallServerGuide(): void
    {
        $entry = __DIR__ . '/../../client/import.php';

        [$canonical_output, $canonical_exit] = $this->runCli($entry, ['install-server']);
        [$alias_output, $alias_exit] = $this->runCli($entry, ['install-exporter']);

        $this->assertSame(0, $canonical_exit);
        $this->assertSame($canonical_output, $alias_output);
        $this->assertSame($canonical_exit, $alias_exit);
        $this->assertStringContainsString('reprint-exporter-wp.zip', $canonical_output);
    }

    public function testMainHelpPresentsInstallServerWithoutTheLegacyAlias(): void
    {
        [$help_output] = $this->runCli(__DIR__ . '/../../client/import.php', ['--help']);

        $this->assertStringContainsString('install-server', $help_output);
        $this->assertStringNotContainsString('install-exporter', $help_output);
    }
}
