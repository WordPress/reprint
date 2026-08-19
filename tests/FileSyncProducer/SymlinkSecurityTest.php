<?php

namespace FileSyncProducerTests;

use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test symlink security and path traversal protection
 */
class SymlinkSecurityTest extends FileSyncProducerTestBase
{
    public function testSymlinkWithTraversalInTarget()
    {
        // Symlink target pointing outside directory (like __wp__ -> ../../../wordpress)
        $dir = $this->createTestDirectory('traversal-target', [
            'file.txt' => 'Content'
        ]);

        // Create symlink with traversal in target
        $linkPath = $dir . '/external';
        symlink('../../../some/external/path', $linkPath);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should capture the symlink with its original target
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks, 'Should capture symlink with traversal in target');

        $symlinkChunk = reset($symlinkChunks);
        $this->assertEquals('../../../some/external/path', $symlinkChunk['target'],
            'Should preserve original target path');
        // Path should be relative to filesystem root
        $this->assertStringContainsString('external', $symlinkChunk['path']);

        // Clean up
        unlink($linkPath);
    }

    public function testSymlinkWithAbsoluteTarget()
    {
        // Symlink with absolute path target
        $dir = $this->createTestDirectory('absolute-target', [
            'file.txt' => 'Content'
        ]);

        // Create symlink with absolute target
        $linkPath = $dir . '/absolute';
        symlink('/usr/local/bin/something', $linkPath);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should capture the symlink with its absolute target
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks, 'Should capture symlink with absolute target');

        $symlinkChunk = reset($symlinkChunks);
        $this->assertEquals('/usr/local/bin/something', $symlinkChunk['target'],
            'Should preserve absolute target path');

        // Clean up
        unlink($linkPath);
    }

    public function testSymlinkPointingOutsideDocumentRoot()
    {
        // Symlink pointing to parent directory (common pattern like __wp__ -> ../wordpress)
        $dir = $this->createTestDirectory('outside-root', [
            'index.php' => 'Index'
        ]);

        // Create symlink pointing outside
        $linkPath = $dir . '/__wp__';
        symlink('../wordpress/core/latest', $linkPath);

        // Create another symlink using the first one
        $link2Path = $dir . '/wp-load.php';
        symlink('__wp__/wp-load.php', $link2Path);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should capture both symlinks
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(2, $symlinkChunks, 'Should capture both symlinks');

        $targets = array_column($symlinkChunks, 'target');
        $this->assertContains('../wordpress/core/latest', $targets, 'Should preserve parent traversal');
        $this->assertContains('__wp__/wp-load.php', $targets, 'Should preserve relative symlink');

        // Clean up
        unlink($link2Path);
        unlink($linkPath);
    }

    public function testNestedSymlinkChain()
    {
        // Chain of symlinks (a -> b -> c -> file.txt)
        $dir = $this->createTestDirectory('symlink-chain', [
            'file.txt' => 'Final content'
        ]);

        // Create chain
        $link3 = $dir . '/link3';
        symlink('file.txt', $link3);

        $link2 = $dir . '/link2';
        symlink('link3', $link2);

        $link1 = $dir . '/link1';
        symlink('link2', $link1);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should capture all symlinks in the chain
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(3, $symlinkChunks, 'Should capture all symlinks in chain');

        // Clean up
        unlink($link1);
        unlink($link2);
        unlink($link3);
    }

    public function testSymlinkPathIsSafelyRecorded()
    {
        // Verify symlink paths are recorded exactly as they are
        // without modification (security checks should happen on import)
        $dir = $this->createTestDirectory('path-recording', [
            'target.txt' => 'Target'
        ]);

        $linkPath = $dir . '/normal-link';
        symlink('target.txt', $linkPath);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks, 'Should have symlink chunk');

        $symlinkChunk = reset($symlinkChunks);
        $this->assertArrayHasKey('path', $symlinkChunk, 'Should have path field');
        $this->assertArrayHasKey('target', $symlinkChunk, 'Should have target field');
        $this->assertArrayHasKey('ctime', $symlinkChunk, 'Should have ctime field');
        $this->assertArrayNotHasKey('data', $symlinkChunk, 'Should not have data field');
        $this->assertArrayNotHasKey('size', $symlinkChunk, 'Should not have size field');

        // Clean up
        unlink($linkPath);
    }

    public function testSymlinkAndDirectoryWithSameName()
    {
        // Edge case: symlink and directory with similar names
        $dir = $this->createTestDirectory('name-collision');

        // Create directory
        $subdir = $dir . '/mydir';
        mkdir($subdir);
        file_put_contents($subdir . '/file.txt', 'Content');

        // Create symlink with similar name
        $link = $dir . '/mydir-link';
        symlink('mydir', $link);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should have both symlink and directory
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(1, $symlinkChunks, 'Should have symlink chunk');

        // Clean up
        unlink($link);
        unlink($subdir . '/file.txt');
        rmdir($subdir);
    }

    public function testSymlinkToDirectoryPreserved()
    {
        // Symlink to directory should be recorded as symlink
        $dir = $this->createTestDirectory('dir-symlink');

        $targetDir = $dir . '/target-dir';
        mkdir($targetDir);
        file_put_contents($targetDir . '/file.txt', 'Content');

        $linkDir = $dir . '/link-dir';
        symlink($targetDir, $linkDir);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should have symlink chunk for directory symlink
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks, 'Should capture directory symlink');

        $symlinkChunk = reset($symlinkChunks);
        // Path should be relative to filesystem root
        $this->assertStringContainsString('link-dir', $symlinkChunk['path']);

        // Clean up
        unlink($linkDir);
        unlink($targetDir . '/file.txt');
        rmdir($targetDir);
    }

    public function testMultipleSymlinksToSameTarget()
    {
        // Multiple symlinks pointing to the same file
        $dir = $this->createTestDirectory('multiple-links', [
            'target.txt' => 'Shared target'
        ]);

        $link1 = $dir . '/link1';
        $link2 = $dir . '/link2';
        $link3 = $dir . '/link3';

        symlink('target.txt', $link1);
        symlink('target.txt', $link2);
        symlink('target.txt', $link3);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should capture all three symlinks
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(3, $symlinkChunks, 'Should capture all symlinks to same target');

        // All should have same target
        $targets = array_column($symlinkChunks, 'target');
        $this->assertEquals(['target.txt', 'target.txt', 'target.txt'], $targets);

        // Clean up
        unlink($link1);
        unlink($link2);
        unlink($link3);
    }

    public function testSymlinkCtimeIsPreserved()
    {
        // Verify ctime is captured for symlinks
        $dir = $this->createTestDirectory('symlink-ctime', [
            'target.txt' => 'Content'
        ]);

        $linkPath = $dir . '/link';
        symlink('target.txt', $linkPath);

        // Get actual ctime
        $expectedCtime = filectime($linkPath);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks);

        $symlinkChunk = reset($symlinkChunks);
        $this->assertEquals($expectedCtime, $symlinkChunk['ctime'],
            'Should preserve symlink ctime');

        // Clean up
        unlink($linkPath);
    }
}
