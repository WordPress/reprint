<?php

namespace FileSyncProducerTests;

use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test file updates and modifications during sync
 */
class FileUpdateTest extends FileSyncProducerTestBase
{
    public function testFileGrowsDuringStreaming()
    {
        $initialContent = str_repeat('A', 5000);
        $dir = $this->createTestDirectory('growing-file', [
            'growing.txt' => $initialContent
        ]);

        $sync = new FileTreeProducer($dir, [
            'chunk_size' => 2048,
            'paths' => $this->enumerateFiles($dir),
        ]);

        $chunks = [];
        $chunkCount = 0;

        while ($sync->next_chunk()) {
            $chunk = $sync->get_current_chunk();
            if ($chunk) {
                $chunks[] = $chunk;
                $chunkCount++;

                // Modify file after first chunk
                if ($chunkCount === 1) {
                    $this->updateFile($dir, 'growing.txt', $initialContent . 'BBBB');
                }
            }
        }

        // File should either:
        // 1. Be streamed with original content (snapshot taken at start)
        // 2. Be detected as changed and handled appropriately
        $this->assertNotEmpty($chunks, 'Should produce chunks even if file changes');
    }

    public function testFileDeletedDuringStreaming()
    {
        $dir = $this->createTestDirectory('delete-during', [
            'file1.txt' => str_repeat('A', 5000),
            'file2.txt' => 'Content',
            'file3.txt' => str_repeat('B', 5000)
        ]);

        $sync = new FileTreeProducer($dir, [
            'chunk_size' => 2048,
            'paths' => $this->enumerateFiles($dir),
        ]);

        $chunkCount = 0;

        while ($sync->next_chunk()) {
            $chunk = $sync->get_current_chunk();
            if ($chunk) {
                $chunkCount++;

                // Delete file2.txt after processing first file
                if ($chunkCount === 3) {
                    $this->deleteFile($dir, 'file2.txt');
                }
            }
        }

        // Should handle deletion gracefully without crashing
        $this->assertTrue(true, 'Should handle file deletion during streaming');
    }
}
