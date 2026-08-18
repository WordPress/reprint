<?php

namespace FileSyncProducerTests;

use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test basic file syncing functionality
 */
class BasicFileSyncTest extends FileSyncProducerTestBase
{
    public function testSyncEmptyDirectory()
    {
        $dir = $this->createTestDirectory('empty');
        $paths = $this->enumerateFiles($dir);

        $sync = new FileTreeProducer($dir, [
            'paths' => $paths,
        ]);

        $chunks = $this->processAllChunks($sync);

        // Empty directory: the only path is the directory itself
        $this->assertCount(1, $chunks, 'Empty directory should produce one directory chunk');
        $this->assertEquals('directory', $chunks[0]['type']);
        $this->assertEquals($dir, $chunks[0]['path']);
    }

    public function testSyncSingleSmallFile()
    {
        $dir = $this->createTestDirectory('single-file', [
            'test.txt' => 'Hello, World!'
        ]);

        $sync = new FileTreeProducer($dir, [
            'chunk_size' => 1024,
            'paths' => $this->enumerateFiles($dir),
        ]);

        $chunks = $this->processAllChunks($sync);

        $this->assertCount(1, $chunks, 'Single small file should produce one chunk');
        $this->assertEquals('Hello, World!', $chunks[0]['data']);
        $this->assertTrue($chunks[0]['is_first_chunk']);
        $this->assertTrue($chunks[0]['is_last_chunk']);
    }

    public function testDefaultChunkSizeIsFiveMegabytes()
    {
        $dir = $this->createTestDirectory('default-chunk-size', [
            'test.txt' => 'Hello, World!'
        ]);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $property = new \ReflectionProperty(FileTreeProducer::class, 'chunk_size');
        $property->setAccessible(true);

        $this->assertSame(5 * 1024 * 1024, FileTreeProducer::DEFAULT_CHUNK_SIZE);
        $this->assertSame(5 * 1024 * 1024, $property->getValue($sync));
    }

    public function testSyncMultipleFiles()
    {
        $dir = $this->createTestDirectory('multiple-files', [
            'file1.txt' => 'Content 1',
            'file2.txt' => 'Content 2',
            'file3.txt' => 'Content 3'
        ]);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(3, $files, 'Should process all 3 files');
    }

    public function testLargeFileChunking()
    {
        $largeContent = str_repeat('A', 10000); // 10KB

        $dir = $this->createTestDirectory('large-file', [
            'large.txt' => $largeContent
        ]);

        $sync = new FileTreeProducer($dir, [
            'chunk_size' => 4096, // 4KB chunks
            'paths' => $this->enumerateFiles($dir),
        ]);

        $chunks = $this->processAllChunks($sync);
        // Filter chunks by filename (paths are now relative to filesystem root)
        $fileChunks = array_filter($chunks, fn($c) => str_ends_with($c['path'] ?? '', '/large.txt'));

        $this->assertGreaterThan(1, count($fileChunks), 'Large file should be split into multiple chunks');

        // Verify first and last chunks are marked correctly
        $this->assertTrue($chunks[0]['is_first_chunk']);
        $this->assertTrue(end($chunks)['is_last_chunk']);

        // Reconstruct and verify content
        $reconstructed = $this->reconstructFileFromChunks($chunks, $dir . '/large.txt');
        $this->assertEquals($largeContent, $reconstructed, 'Reconstructed content should match original');
    }

    public function testNestedDirectories()
    {
        $dir = $this->createTestDirectory('nested', [
            'root.txt' => 'Root file',
            'sub1/file1.txt' => 'Sub1 file',
            'sub1/sub2/file2.txt' => 'Sub2 file',
            'sub1/sub2/sub3/file3.txt' => 'Sub3 file'
        ]);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(4, $files, 'Should process all files in nested directories');
    }

    public function testFilesWithSpecialCharacters()
    {
        $dir = $this->createTestDirectory('special-chars', [
            'file with spaces.txt' => 'Spaces',
            "file\twith\ttabs.txt" => 'Tabs',
            'file-with-dashes.txt' => 'Dashes',
            'file_with_underscores.txt' => 'Underscores'
        ]);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(4, $files, 'Should handle files with special characters');
    }

    public function testProgressTracking()
    {
        $dir = $this->createTestDirectory('progress', [
            'file1.txt' => str_repeat('A', 1000),
            'file2.txt' => str_repeat('B', 2000),
            'file3.txt' => str_repeat('C', 3000)
        ]);

        $sync = new FileTreeProducer($dir, [
            'chunk_size' => 1024,
            'paths' => $this->enumerateFiles($dir),
        ]);

        $phases = [];
        while ($sync->next_chunk()) {
            $progress = $sync->get_progress();
            $phases[] = $progress['phase'];
        }

        $uniquePhases = array_unique($phases);
        $this->assertContains('streaming', $uniquePhases, 'Should track streaming phase');
    }
}
