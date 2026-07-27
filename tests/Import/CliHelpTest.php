<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

class CliHelpTest extends TestCase
{
    private function runHelp(string $command): string
    {
        $entry = __DIR__ . '/../../importer/import.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry) . ' ' . escapeshellarg($command) . ' --help';
        return shell_exec($cmd . ' 2>&1') ?? '';
    }

    public function testPullFilesHelpShowsRequiredAndFileSelectionOptions(): void
    {
        $output = $this->runHelp('pull-files');

        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--filter=MODE', $output);
        $this->assertStringContainsString('--remap SOURCE TARGET', $output);
        $this->assertStringContainsString('--only=SOURCE', $output);
        $this->assertStringContainsString('--on-conflict=POLICY', $output);
    }

    public function testPullDbHelpShowsRequiredAndDatabaseOptions(): void
    {
        $output = $this->runHelp('pull-db');

        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--max-allowed-packet=SIZE', $output);
        $this->assertStringContainsString('--target-engine=ENGINE', $output);
        $this->assertStringContainsString('--new-site-url=URL', $output);
    }

    public function testFilesPushHelpShowsOnlyItsCommandOptions(): void
    {
        $output = $this->runHelp('files-push');

        $this->assertStringContainsString('Usage: reprint files-push <target-url>', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--secret=TOKEN', $output);
        $this->assertStringContainsString('--force-http', $output);
        $this->assertStringContainsString('--verbose, -v', $output);
        $this->assertStringContainsString('low-level, files-only command', $output);
        $this->assertStringContainsString('existing local tree at --fs-root', $output);
        $this->assertStringContainsString('read or modify', $output);
        $this->assertStringNotContainsString('--abort', $output);
        $this->assertStringNotContainsString('--filter', $output);
        $this->assertStringNotContainsString('--remap', $output);
        $this->assertStringNotContainsString('--only', $output);
        $this->assertStringNotContainsString('--on-conflict', $output);
    }

    public function testFilesDiffHelpShowsOnlyItsLocalCommandOptions(): void
    {
        $output = $this->runHelp('files-diff');

        $this->assertStringContainsString('Usage: reprint files-diff <target-url>', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('previous local index', $output);
        $this->assertStringContainsString('completed files-push', $output);
        $this->assertStringContainsString('compatible file-only', $output);
        $this->assertStringContainsString('files-pull or pull-files', $output);
        $this->assertStringContainsString('maintains that index after each durable WAL batch', $output);
        $this->assertStringContainsString('default path mapping', $output);
        $this->assertStringContainsString('overwrite behavior', $output);
        $this->assertStringContainsString('same files-pull with --abort', $output);
        $this->assertStringContainsString('push operation plan', $output);
        $this->assertStringContainsString('built-in exclusions', $output);
        $this->assertStringContainsString('No network calls', $output);
        $this->assertStringContainsString('complete diff from the beginning', $output);
        $this->assertStringNotContainsString('--runtime', $output);
        $this->assertStringNotContainsString('--secret', $output);
        $this->assertStringNotContainsString('--force-http', $output);
        $this->assertStringNotContainsString('--filter', $output);
        $this->assertStringNotContainsString('--remap', $output);
        $this->assertStringNotContainsString('--only', $output);
    }

    public function testMainHelpDescribesFilesPushWithoutApplyingPullOnlyContractsGlobally(): void
    {
        $output = $this->runHelp('--help');

        $this->assertStringContainsString('files-push', $output);
        $this->assertStringContainsString('files-diff', $output);
        $this->assertStringContainsString('Low-level commands:', $output);
        $this->assertStringNotContainsString('Low-level commands (used by pull internally):', $output);
        $this->assertStringNotContainsString('State is stored in --state-dir/.import-state.json', $output);
        $this->assertStringNotContainsString('Use --abort to abort the current', $output);
    }
}
