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

    public function testPushHelpShowsItsNarrowPublicSurface(): void
    {
        $output = $this->runHelp('push');

        $this->assertStringContainsString('--source-root=DIR', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--secret=TOKEN', $output);
        $this->assertStringContainsString('--dry-run', $output);
        $this->assertStringContainsString('--abort', $output);
        $this->assertStringContainsString('--allow-http', $output);
        $this->assertStringContainsString('authorizes applying the delta', $output);
        $this->assertStringContainsString('a later push rescans the source tree', $output);
        $this->assertStringNotContainsString('push-commit', $output);
    }
}
