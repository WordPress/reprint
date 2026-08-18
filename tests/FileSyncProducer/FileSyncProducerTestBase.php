<?php

namespace FileSyncProducerTests;

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/../../packages/reprint-server/src/class-file-tree-producer.php';

/**
 * Base test class for FileTreeProducer tests
 */
abstract class FileSyncProducerTestBase extends TestCase
{
    protected $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = __DIR__ . '/fixtures';

        // Create fixtures directory if it doesn't exist
        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up all fixtures after each test
        $this->cleanupFixtures();
        parent::tearDown();
    }

    /**
     * Create a test directory with files
     */
    protected function createTestDirectory(string $name, array $files = []): string
    {
        $dir = $this->fixturesDir . '/' . $name;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach ($files as $path => $content) {
            $fullPath = $dir . '/' . $path;
            $pathDir = dirname($fullPath);

            if (!is_dir($pathDir)) {
                mkdir($pathDir, 0755, true);
            }

            file_put_contents($fullPath, $content);
        }

        return $dir;
    }

    /**
     * Delete a file from test directory
     */
    protected function deleteFile(string $dir, string $path): void
    {
        $fullPath = $dir . '/' . $path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Update a file in test directory
     */
    protected function updateFile(string $dir, string $path, string $content): void
    {
        $fullPath = $dir . '/' . $path;
        file_put_contents($fullPath, $content);
        // Touch to update mtime/ctime
        touch($fullPath);
    }

    /**
     * Clean up all fixtures
     */
    protected function cleanupFixtures(): void
    {
        if (!is_dir($this->fixturesDir)) {
            return;
        }

        $this->recursiveDelete($this->fixturesDir);
    }

    /**
     * Recursively delete directory
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Recursively enumerate all filesystem entries (files, directories, symlinks)
     * under a directory. Returns sorted absolute paths. This replaces what tree
     * traversal used to do: tests create fixture dirs, enumerate them, and pass
     * the resulting paths to FileTreeProducer.
     */
    protected function enumerateFiles(string $dir): array
    {
        $paths = [];
        $this->enumerateFilesRecursive($dir, $paths);
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function enumerateFilesRecursive(string $dir, array &$paths): void
    {
        $entries = @scandir($dir, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            return;
        }

        $hasChildren = false;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            $hasChildren = true;

            if (is_link($path)) {
                $paths[] = $path;
            } elseif (is_dir($path)) {
                $this->enumerateFilesRecursive($path, $paths);
            } else {
                $paths[] = $path;
            }
        }

        // Include empty directories so import can recreate them
        if (!$hasChildren) {
            $paths[] = $dir;
        }
    }

    /**
     * Process all chunks from sync producer
     */
    protected function processAllChunks(FileTreeProducer $sync): array
    {
        $chunks = [];

        while ($sync->next_chunk()) {
            $chunk = $sync->get_current_chunk();
            if ($chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * Get list of files from chunks (excludes directory chunks)
     */
    protected function getFilesFromChunks(array $chunks): array
    {
        $files = [];

        foreach ($chunks as $chunk) {
            // Skip directory chunks
            if (isset($chunk['type']) && $chunk['type'] === 'directory') {
                continue;
            }

            if (isset($chunk['is_first_chunk']) && $chunk['is_first_chunk']) {
                $files[] = $chunk['path'];
            }
        }

        return $files;
    }

    /**
     * Reconstruct file content from chunks
     */
    protected function reconstructFileFromChunks(array $chunks, string $path): string
    {
        $content = '';

        foreach ($chunks as $chunk) {
            // Match by exact path or by suffix (for relative paths)
            $chunkPath = $chunk['path'] ?? '';
            if ($chunkPath === $path || str_ends_with($chunkPath, '/' . basename($path)) ||
                str_ends_with($path, $chunkPath)) {
                $content .= $chunk['data'] ?? '';
            }
        }

        return $content;
    }
}
