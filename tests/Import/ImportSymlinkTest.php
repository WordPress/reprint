<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test import symlink recreation
 */
class ImportSymlinkTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-symlink-test-' . uniqid();
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

            // Remove symlinks first
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

    public function testSymlinkIsCreated()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/test/link'),
                'x-symlink-target' => base64_encode('target'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/fs-root/test/link';
        $this->assertTrue(is_link($symlinkPath), 'Symlink should be created');
        $this->assertEquals('target', readlink($symlinkPath), 'Symlink target should match');
    }

    /**
     * A relative symlink that escapes the filesystem root should be rejected.
     * Path /a/link with target ../../../escape resolves to
     * fs-root/a/../../escape = fs-root/../escape — outside root.
     */
    public function testRelativeSymlinkEscapingRootRejected()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/a/link'),
                'x-symlink-target' => base64_encode('../../../escape'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/fs-root/a/link';
        $this->assertFalse(is_link($symlinkPath), 'Symlink escaping root should not be created');
    }

    /**
     * When the first symlink in a chain escapes the root, it should be
     * rejected. The second symlink (which references the first) should
     * still be created since its own target stays within root.
     */
    public function testChainedSymlinksEscapingRootRejected()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        // First symlink: /site/__wp__ -> ../wordpress/core
        // Resolved: /wordpress/core — still within root, so this is fine.
        // Wait — /site/../wordpress/core = /wordpress/core which IS under root.
        // Let's use a target that actually escapes.
        $chunk1 = [
            'headers' => [
                'x-symlink-path' => base64_encode('/site/__wp__'),
                'x-symlink-target' => base64_encode('../../outside'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];
        $method->invoke($client, $chunk1);

        $link1 = $this->tempDir . '/fs-root/site/__wp__';
        $this->assertFalse(is_link($link1), 'Symlink escaping root should not be created');

        // Second symlink references a path within root — should succeed
        $chunk2 = [
            'headers' => [
                'x-symlink-path' => base64_encode('/site/wp-load.php'),
                'x-symlink-target' => base64_encode('__wp__/wp-load.php'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];
        $method->invoke($client, $chunk2);

        $link2 = $this->tempDir . '/fs-root/site/wp-load.php';
        $this->assertTrue(is_link($link2), 'Symlink staying within root should be created');
        $this->assertEquals('__wp__/wp-load.php', readlink($link2));
    }

    /**
     * An absolute symlink target pointing outside the filesystem root
     * should be rejected.
     */
    public function testAbsoluteSymlinkOutsideRootRejected()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/bin-link'),
                'x-symlink-target' => base64_encode('/usr/local/bin/php'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/fs-root/bin-link';
        $this->assertFalse(is_link($symlinkPath), 'Absolute symlink outside root should not be created');
    }

    /**
     * A relative symlink target that stays within the filesystem root
     * should be created successfully.
     */
    public function testRelativeSymlinkWithinRootCreated()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/wp-content/link'),
                'x-symlink-target' => base64_encode('../uploads/file.txt'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/fs-root/wp-content/link';
        $this->assertTrue(is_link($symlinkPath), 'Symlink within root should be created');
        $this->assertEquals('../uploads/file.txt', readlink($symlinkPath));
    }

    /**
     * An absolute symlink target that points within the filesystem root
     * should be created successfully.
     */
    public function testAbsoluteSymlinkWithinRootCreated()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');
        $root = realpath($this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/link'),
                'x-symlink-target' => base64_encode($root . '/some/target'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $root . '/link';
        $this->assertTrue(is_link($symlinkPath), 'Absolute symlink within root should be created');
        $this->assertEquals($root . '/some/target', readlink($symlinkPath));
    }

    public function testSymlinkWithMissingDataSkipped()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        // Missing path
        $chunk1 = [
            'headers' => [
                'x-symlink-target' => base64_encode('target'),
            ]
        ];
        $method->invoke($client, $chunk1);

        // Missing target
        $chunk2 = [
            'headers' => [
                'x-symlink-path' => base64_encode('/path'),
            ]
        ];
        $method->invoke($client, $chunk2);

        // No symlinks should be created
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (is_link($file->getPathname())) {
                $count++;
            }
        }

        $this->assertEquals(0, $count, 'No symlinks should be created for invalid chunks');
    }

    public function testSymlinkReplacesExistingFile()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir, $this->tempDir . '/fs-root');

        // Create a regular file
        $filePath = $this->tempDir . '/fs-root/test/link';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, 'content');
        $this->assertTrue(is_file($filePath));

        // Create symlink at same location
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/test/link'),
                'x-symlink-target' => base64_encode('target'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        // Should now be a symlink
        $this->assertTrue(is_link($filePath), 'File should be replaced with symlink');
        $this->assertEquals('target', readlink($filePath));
    }
}
