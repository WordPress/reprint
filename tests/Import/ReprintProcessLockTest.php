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

    public function testLocalStateLayoutUsesMatchingDirectoriesAndFileNames(): void
    {
        $process_lock = new \ReprintProcessLock($this->root . '/state');
        $client = new \ImportClient(
            'https://example.com/?site-export-api',
            $this->root . '/state',
            $this->root . '/files'
        );

        $this->assertFileExists($this->root . '/state/.reprint/process.lock');
        $this->assertDirectoryExists($this->root . '/state/.reprint/pull');
        $this->assertFileDoesNotExist($this->root . '/state/.reprint.lock');

        $reflection = new \ReflectionClass($client);
        $expected_paths = [
            'reprint_state_directory' => $this->root . '/state/.reprint',
            'pull_state_directory' => $this->root . '/state/.reprint/pull',
            'pull_state_file' => $this->root . '/state/.reprint/pull/state.json',
            'local_index_file' => $this->root . '/state/.reprint/pull/local-index.jsonl',
            'local_index_wal_path' => $this->root . '/state/.reprint/pull/local-index.wal',
            'remote_index_file' => $this->root . '/state/.reprint/pull/remote-index.jsonl',
            'fetch_list_file' => $this->root . '/state/.reprint/pull/fetch-list.jsonl',
            'skipped_fetch_list_file' => $this->root . '/state/.reprint/pull/skipped-fetch-list.jsonl',
            'volatile_files_file' => $this->root . '/state/.reprint/pull/volatile-files.json',
            'domains_file' => $this->root . '/state/.reprint/pull/domains.json',
            'sql_stats_file' => $this->root . '/state/.reprint/pull/sql-stats.json',
            'sql_buffer_file' => $this->root . '/state/.reprint/pull/sql-buffer',
            'audit_log_file' => $this->root . '/state/.reprint/audit.log',
            'status_file' => $this->root . '/state/.reprint/status.json',
        ];
        foreach ($expected_paths as $property_name => $expected_path) {
            $property = $reflection->getProperty($property_name);
            $property->setAccessible(true);
            $this->assertSame($expected_path, $property->getValue($client), $property_name);
        }

        $process_lock->close();
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
