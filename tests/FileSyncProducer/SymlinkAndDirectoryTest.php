<?php

namespace FileSyncProducerTests;

use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test symlink handling and empty directory communication
 */
class SymlinkAndDirectoryTest extends FileSyncProducerTestBase
{
    public function testSymlinkDetectedInPathsList()
    {
        $dir = $this->createTestDirectory('symlink-default', [
            'real.txt' => 'Real content'
        ]);

        // Create symlink to file
        $linkPath = $dir . '/link.txt';
        symlink($dir . '/real.txt', $linkPath);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should include both real file and symlink
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(1, $symlinkChunks, 'Should detect symlink in paths list');

        $fileChunks = $this->getFilesFromChunks($chunks);
        $this->assertCount(1, $fileChunks, 'Should include the real file');

        // Clean up
        unlink($linkPath);
    }

    public function testEmptyDirectoryCommunicated()
    {
        $dir = $this->createTestDirectory('empty-dirs');

        // Create empty directory
        $emptyDir = $dir . '/empty';
        mkdir($emptyDir);

        // Create directory with file
        $fullDir = $dir . '/full';
        mkdir($fullDir);
        file_put_contents($fullDir . '/file.txt', 'Content');

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        // Should have at least one directory chunk for empty dir
        $this->assertGreaterThanOrEqual(1, count($dirChunks), 'Should output empty directories');

        // Verify empty directory is in the output
        $dirPaths = array_map(fn($c) => $c['path'], $dirChunks);
        $hasEmptyDir = false;
        foreach ($dirPaths as $path) {
            if (strpos($path, 'empty') !== false) {
                $hasEmptyDir = true;
                break;
            }
        }
        $this->assertTrue($hasEmptyDir, 'Empty directory should be communicated');

        // Clean up
        rmdir($emptyDir);
        unlink($fullDir . '/file.txt');
        rmdir($fullDir);
    }

    public function testNestedEmptyDirectories()
    {
        $dir = $this->createTestDirectory('nested-empty');

        // Create nested empty directories
        $level1 = $dir . '/level1';
        $level2 = $level1 . '/level2';
        $level3 = $level2 . '/level3';
        mkdir($level1);
        mkdir($level2);
        mkdir($level3);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        // Only the leaf empty directory should appear — intermediate directories
        // contain children (subdirectories) so they are not "empty" from the
        // enumeration perspective. The leaf dir (level3) has no children.
        $this->assertGreaterThanOrEqual(1, count($dirChunks), 'Should output leaf empty directory');

        // Clean up
        rmdir($level3);
        rmdir($level2);
        rmdir($level1);
    }

    public function testDirectoryChunkFormat()
    {
        $dir = $this->createTestDirectory('dir-format');

        // Create empty directory
        $emptyDir = $dir . '/test_empty';
        mkdir($emptyDir);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        $this->assertNotEmpty($dirChunks, 'Should have directory chunks');

        $dirChunk = reset($dirChunks);
        $this->assertEquals('directory', $dirChunk['type'], 'Should have type=directory');
        $this->assertArrayHasKey('path', $dirChunk, 'Should have path');
        $this->assertArrayNotHasKey('data', $dirChunk, 'Directory chunks should not have data');
        $this->assertArrayNotHasKey('size', $dirChunk, 'Directory chunks should not have size');

        // Clean up
        rmdir($emptyDir);
    }
}
