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
        $remote_reprint_api_url = 'https://example.com/?site-export-api';
        $process_lock = new \ReprintProcessLock($this->root . '/state');
        $client = new \ImportClient(
            $remote_reprint_api_url,
            $this->root . '/state',
            $this->root . '/files'
        );
        $remote_state_directory =
            $this->root . '/state/remotes/' . md5($remote_reprint_api_url);
        $pull_state_directory = $remote_state_directory . '/pull';

        $this->assertFileExists($this->root . '/state/process.lock');
        $this->assertDirectoryExists($pull_state_directory);
        $this->assertDirectoryDoesNotExist($this->root . '/state/pull');
        $this->assertDirectoryDoesNotExist($this->root . '/state/.reprint');
        $this->assertFileDoesNotExist($this->root . '/state/.reprint.lock');
        $this->assertSame(
            realpath($remote_state_directory) . '/push',
            \ImportClient::resolve_push_state_directory(
                $remote_reprint_api_url,
                $this->root . '/state',
                $this->root . '/files',
                'files-diff'
            )
        );

        $reflection = new \ReflectionClass($client);
        $expected_paths = [
            'state_dir' => $this->root . '/state',
            'pull_state_directory' => $pull_state_directory,
            'pull_state_file' => $pull_state_directory . '/state.json',
            'remote_index_file' => $pull_state_directory . '/remote-index.jsonl',
            'pull_index_wal_path' => $pull_state_directory . '/index.wal',
            'next_remote_index_file' => $pull_state_directory . '/remote-index.next.jsonl',
            'fetch_list_file' => $pull_state_directory . '/fetch-list.jsonl',
            'volatile_files_file' => $pull_state_directory . '/volatile-files.json',
            'audit_log_file' => $this->root . '/state/audit.log',
            'progress_file' => $this->root . '/state/progress.json',
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
