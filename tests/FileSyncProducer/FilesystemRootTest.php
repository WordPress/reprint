<?php

namespace FileSyncProducerTests;

use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test filesystem root calculation
 */
class FilesystemRootTest extends FileSyncProducerTestBase
{
    public function testNoSymlinksUsesScannedDirectory()
    {
        // When there are no symlinks, filesystem root should be the scanned directory
        $dir = $this->createTestDirectory('no-symlinks', [
            'file1.txt' => 'Content 1',
            'file2.txt' => 'Content 2'
        ]);

        $sync = new FileTreeProducer($dir, [
            'paths' => $this->enumerateFiles($dir),
        ]);
        $chunks = $this->processAllChunks($sync);

        $fsRoot = $sync->get_filesystem_root();

        // Should be the scan directory itself (or its real path)
        $this->assertEquals(realpath($dir), $fsRoot);
    }

    public function testRootDirectoryResolvesRelativePaths()
    {
        $dir = $this->createTestDirectory('filesystem-root', [
            'from-root.txt' => 'Content from the filesystem root',
        ]);
        $path = $dir . '/from-root.txt';

        $sync = new FileTreeProducer('/', [
            'paths' => [ltrim($path, '/')],
        ]);
        $chunks = $this->processAllChunks($sync);

        $this->assertSame('/', $sync->get_filesystem_root());
        $this->assertSame($path, $chunks[0]['path']);
        $this->assertSame('Content from the filesystem root', $chunks[0]['data']);
    }
}
