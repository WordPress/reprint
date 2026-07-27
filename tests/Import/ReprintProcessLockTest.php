<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class ReprintProcessLockTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-process-lock-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/state', 0700, true);
        mkdir($this->root . '/files', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testImportClientRejectsAConcurrentCommand(): void
    {
        $process_lock = new \ReprintProcessLock($this->root . '/state');
        $client = new \ImportClient(
            'https://example.com/?site-export-api',
            $this->root . '/state',
            $this->root . '/files'
        );

        try {
            $client->run(['command' => 'files-stats']);
            $this->fail('A second Reprint command must not use the same state directory.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Another Reprint process is using the state directory',
                $exception->getMessage()
            );
        } finally {
            $process_lock->close();
        }
    }

    public function testCloseIsIdempotentAndAllowsTheNextCommand(): void
    {
        $process_lock = new \ReprintProcessLock($this->root . '/state');
        $process_lock->close();
        $process_lock->close();

        $next_process_lock = new \ReprintProcessLock($this->root . '/state');
        $this->assertTrue($next_process_lock->is_held());
        $next_process_lock->close();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
