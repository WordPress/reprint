<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Filesystem\PulledFileContext;
use Reprint\Importer\Filesystem\PulledFilesystem;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Tests the public PulledFilesystem contract directly. */
class PulledFilesystemTest extends TestCase {
    private $temp_dir;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/pulled-filesystem-test-' . uniqid();
        $this->filesystem_root = $this->temp_dir . '/root';
        mkdir($this->temp_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTestPath($this->temp_dir);
        parent::tearDown();
    }

    private function removeTestPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTestPath($path . '/' . $entry);
        }
        rmdir($path);
    }

    private function newFilesystem(
        string $filesystem_root_nonempty_behavior = 'error',
        array $resolved_path_mappings = [],
        ?string $local_followed_symlinks_root = null,
        array $original_export_directories = []
    ): PulledFilesystem {
        return new PulledFilesystem(
            $this->filesystem_root,
            $resolved_path_mappings,
            $local_followed_symlinks_root,
            $filesystem_root_nonempty_behavior,
            $original_export_directories,
        );
    }

    public function testConstructorAppliesCompleteRoutingConfiguration(): void
    {
        $mapped_root = $this->temp_dir . '/mapped';
        $followed_root = $this->temp_dir . '/followed';
        $filesystem = $this->newFilesystem(
            'preserve-local',
            ['/remote/mapped' => $mapped_root],
            $followed_root,
            ['/remote/original'],
        );

        $this->assertSame(
            $mapped_root . '/file.txt',
            $filesystem->map_remote_absolute_path_to_local_absolute_path('/remote/mapped/file.txt'),
        );
        $this->assertSame(
            $filesystem->get_filesystem_root() . '/remote/original/file.txt',
            $filesystem->map_remote_absolute_path_to_local_absolute_path('/remote/original/file.txt'),
        );
        $this->assertSame(
            $followed_root . '/remote/outside/file.txt',
            $filesystem->map_remote_absolute_path_to_local_absolute_path('/remote/outside/file.txt'),
        );
    }

    public function testGetFilesystemRootCreatesAndResolvesRoot(): void
    {
        $filesystem = $this->newFilesystem();

        $root_path = $filesystem->get_filesystem_root();

        $this->assertTrue(is_dir($this->filesystem_root));
        $this->assertSame(realpath($this->filesystem_root), $root_path);
    }

    public function testMapsRemoteAbsolutePathToLocalAbsolutePathUsingConfiguredMapping(): void
    {
        $mapped_root = $this->temp_dir . '/mapped';
        $filesystem = $this->newFilesystem('error', ['/remote/content' => $mapped_root]);

        $this->assertSame(
            $mapped_root . '/plugin/file.php',
            $filesystem->map_remote_absolute_path_to_local_absolute_path('/remote/content/plugin/file.php'),
        );
    }

    public function testPreserveLocalSkipReasonUsesConfiguredBehavior(): void
    {
        $preserving_filesystem = $this->newFilesystem('preserve-local');
        $local_path = $preserving_filesystem
            ->map_remote_absolute_path_to_local_absolute_path('/existing.txt');
        file_put_contents($local_path, 'local');

        $this->assertSame(
            'PRESERVE-LOCAL skip file (exists): /existing.txt',
            $preserving_filesystem->preserve_local_skip_reason('/existing.txt'),
        );
        $this->assertNull(
            $this->newFilesystem()->preserve_local_skip_reason('/existing.txt'),
        );
    }

    public function testWriteLocalFileChunkWritesAndReportsCompletedFile(): void
    {
        $filesystem = $this->newFilesystem();
        $context = new PulledFileContext();
        $result = $filesystem->write_local_file_chunk([
            'headers' => [
                'x-file-path' => base64_encode('/content/file.txt'),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
                'x-file-ctime' => '1234',
                'x-file-size' => '7',
            ],
            'body' => 'content',
        ], $context);

        $this->assertSame('content', file_get_contents($this->filesystem_root . '/content/file.txt'));
        $this->assertTrue($result['completed']);
        $this->assertSame(7, $result['final_size']);
        $this->assertSame([
            'path' => '/content/file.txt',
            'ctime' => 1234,
            'size' => 7,
            'type' => 'file',
        ], $result['index_entry']);
        $this->assertNull($context->file_handle);
    }

    public function testCreatesLocalDirectoryFromRemoteDirectoryPart(): void
    {
        $filesystem = $this->newFilesystem();
        $result = $filesystem->create_local_directory_from_remote_directory_part([
            'headers' => [
                'x-directory-path' => base64_encode('/content/directory'),
                'x-directory-ctime' => '2345',
            ],
        ]);

        $this->assertSame('/content/directory', $result['remote_absolute_path']);
        $this->assertSame(2345, $result['ctime']);
        $this->assertTrue(is_dir($this->filesystem_root . '/content/directory'));
    }

    public function testCreatesLocalSymlinkFromRemoteSymlinkPart(): void
    {
        $filesystem = $this->newFilesystem();
        $filesystem->create_local_directory($filesystem->get_filesystem_root() . '/links');
        $result = $filesystem->create_local_symlink_from_remote_symlink_part([
            'headers' => [
                'x-symlink-path' => base64_encode('/links/link'),
                'x-symlink-target' => base64_encode('../target'),
                'x-symlink-ctime' => '0',
            ],
        ], '../target');

        $local_path = $this->filesystem_root . '/links/link';
        $this->assertNull($result['error']);
        $this->assertSame('/links/link', $result['remote_absolute_path']);
        $this->assertTrue(is_link($local_path));
        $this->assertSame('../target', readlink($local_path));
    }

    public function testRemovesLocalAbsolutePathWithoutFollowingSymlinks(): void
    {
        $filesystem = $this->newFilesystem();
        $root_path = $filesystem->get_filesystem_root();
        $external_path = $this->temp_dir . '/external';
        mkdir($external_path);
        file_put_contents($external_path . '/keep.txt', 'keep');
        mkdir($root_path . '/tree/nested', 0755, true);
        file_put_contents($root_path . '/tree/nested/remove.txt', 'remove');
        symlink($external_path, $root_path . '/tree/external-link');

        $this->assertTrue(
            $filesystem->remove_local_absolute_path_without_following_symlinks(
                $root_path . '/tree'
            )
        );
        $this->assertFileDoesNotExist($root_path . '/tree');
        $this->assertSame('keep', file_get_contents($external_path . '/keep.txt'));
    }

    public function testCreateLocalDirectoryReplacesBlockingFile(): void
    {
        $filesystem = $this->newFilesystem();
        $root_path = $filesystem->get_filesystem_root();
        file_put_contents($root_path . '/blocked', 'blocker');

        $filesystem->create_local_directory($root_path . '/blocked/child');

        $this->assertTrue(is_dir($root_path . '/blocked/child'));
    }

    public function testDrainOperationMessagesReturnsAndClearsMessages(): void
    {
        $filesystem = $this->newFilesystem();
        $root_path = $filesystem->get_filesystem_root();
        file_put_contents($root_path . '/blocked', 'blocker');
        $filesystem->create_local_directory($root_path . '/blocked/child');

        $this->assertSame(
            ["Removing file blocking directory: {$root_path}/blocked"],
            $filesystem->drain_operation_messages(),
        );
        $this->assertSame([], $filesystem->drain_operation_messages());
    }

    public function testAssertLocalSymlinkTargetWithinFilesystemRoot(): void
    {
        $filesystem = $this->newFilesystem();
        $root_path = $filesystem->get_filesystem_root();
        $filesystem->assert_local_symlink_target_within_filesystem_root(
            $root_path . '/links',
            '../target'
        );
        $this->addToAssertionCount(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Security: symlink target escapes filesystem root');
        $filesystem->assert_local_symlink_target_within_filesystem_root(
            $root_path . '/links',
            '../../outside'
        );
    }
}
