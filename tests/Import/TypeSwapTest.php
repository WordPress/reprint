<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * Test type swap handling during delta sync.
 *
 * When a path changes type between syncs (e.g., symlink→file, symlink→directory),
 * the importer must replace the old entity rather than failing or leaving stale state.
 */
class TypeSwapTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-typeswap-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

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

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /** create_directory_if_missing should keep a symlink that blocks directory creation. */
    public function testEnsureDirectoryPathKeepsBlockingSymlink()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('create_directory_if_missing');

        // Resolve the fs-root path so it matches the realpath() check
        // inside create_directory_if_missing (on macOS, /var -> /private/var).
        $fsRoot = realpath($this->tempDir . '/fs-root');

        // Create a symlink at a path where we want a real directory
        $symlinkPath = $fsRoot . '/some-dir';
        $targetDir = $fsRoot . '/target';
        mkdir($targetDir, 0755);
        symlink($targetDir, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        $this->expectException(\Reprint\Importer\PreserveLocalSkipException::class);
        $method->invoke($client, $fsRoot . '/some-dir/child');
    }

    /** create_directory_if_missing should report a file that blocks a directory. */
    public function testEnsureDirectoryPathReportsBlockingFileConflict()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('create_directory_if_missing');
        $fsRoot = realpath($this->tempDir . '/fs-root');
        $blockingFile = $fsRoot . '/some-dir';
        file_put_contents($blockingFile, 'local file');

        $this->expectException(\Reprint\Importer\LocalPathConflictException::class);
        $method->invoke($client, $fsRoot . '/some-dir/child');
    }

    /**
     * A file chunk should replace a symlink-to-directory with a regular file.
     */
    public function testFileChunkReplacesSymlinkToDirectory()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $fsRoot = $this->tempDir . '/fs-root';

        // Create a real directory and a symlink pointing to it
        $realDir = $fsRoot . '/real-target-dir';
        mkdir($realDir, 0755);
        $symlinkPath = $fsRoot . '/swapped-path';
        symlink($realDir, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        // Send a file chunk at the same path
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_file_chunk');

        $context = new StreamingContext();
        $chunk = [
            'headers' => [
                'x-file-path' => base64_encode('/swapped-path'),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
                'x-file-ctime' => '1234567890',
                'x-file-size' => '5',
            ],
            'body' => 'hello',
        ];

        $method->invoke($client, $chunk, $context);

        // Clean up streaming context
        if ($context->file_handle) {
            fclose($context->file_handle);
        }

        $this->assertFalse(is_link($symlinkPath), 'Symlink should be removed');
        $this->assertTrue(is_file($symlinkPath), 'Should be a regular file now');
        $this->assertEquals('hello', file_get_contents($symlinkPath));
    }

    /** A directory chunk should keep a symlink-to-file. */
    public function testDirectoryChunkKeepsSymlinkToFile()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $fsRoot = $this->tempDir . '/fs-root';

        // Create a real file and a symlink pointing to it
        $realFile = $fsRoot . '/real-target-file';
        file_put_contents($realFile, 'content');
        $symlinkPath = $fsRoot . '/swapped-dir';
        symlink($realFile, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        // Send a directory chunk at the same path
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_directory_chunk');

        $chunk = [
            'headers' => [
                'x-directory-path' => base64_encode('/swapped-dir'),
                'x-directory-ctime' => '1234567890',
            ],
        ];

        $method->invoke($client, $chunk);

        $this->assertTrue(is_link($symlinkPath), 'Symlink should remain');
        $this->assertSame($realFile, readlink($symlinkPath));
    }

    /** A directory chunk should keep a symlink that blocks a nested path. */
    public function testDirectoryChunkKeepsSymlinkBlockingNestedPath()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $fsRoot = $this->tempDir . '/fs-root';

        // Create a symlink at the path
        $realFile = $fsRoot . '/target-file';
        file_put_contents($realFile, 'old');
        $symlinkPath = $fsRoot . '/parent';
        symlink($realFile, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        $reflection = new \ReflectionClass($client);

        // A directory chunk cannot replace the symlink.
        $dirMethod = $reflection->getMethod('handle_directory_chunk');
        $dirMethod->invoke($client, [
            'headers' => [
                'x-directory-path' => base64_encode('/parent'),
                'x-directory-ctime' => '1234567890',
            ],
        ]);

        $this->assertTrue(is_link($symlinkPath), 'Symlink should remain');
        $this->assertFalse(file_exists($fsRoot . '/parent/sub/file.txt'));
    }

    /** create_directory_if_missing should keep a symlink blocking nested paths. */
    public function testEnsureDirectoryPathKeepsNestedBlockingSymlink()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        // Resolve the fs-root path so it matches the realpath() check
        // inside create_directory_if_missing (on macOS, /var -> /private/var).
        $fsRoot = realpath($this->tempDir . '/fs-root');

        // Create a symlink at the top-level path component
        $targetDir = $fsRoot . '/real-target';
        mkdir($targetDir, 0755);
        $symlinkPath = $fsRoot . '/top';
        symlink($targetDir, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        // A deeply nested path cannot replace the symlink.
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('create_directory_if_missing');
        $this->expectException(\Reprint\Importer\PreserveLocalSkipException::class);
        $method->invoke($client, $fsRoot . '/top/sub/deep');
    }
}
