<?php

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Server\assert_valid_path;
use function WordPress\Reprint\Server\normalize_path;
use function WordPress\Reprint\Server\path_is_descendant_of;
use function WordPress\Reprint\Server\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Server\path_remainder_under;
use function WordPress\Reprint\Server\trim_right_slash;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-remote-to-local-path-mapper.php';

/** Windows source paths must remain absolute without changing Unix filename bytes. */
class WindowsPathTest extends TestCase
{
    /** The default WordPress ABSPATH mixes native separators with a trailing slash. */
    public function test_accepts_windows_wordpress_directory(): void
    {
        assert_valid_path('D:\\spacewww\\nihaokids.nl/', 'directory entry');
        $this->assertSame('D:/spacewww/nihaokids.nl', normalize_path('D:\\spacewww\\nihaokids.nl/'));
        $this->assertSame('D:/', normalize_path('d:\\site\\..\\..'));
        $this->assertSame('D:/', trim_right_slash('D:/'));
    }

    /** Both spellings must describe the same selected Windows subtree. */
    public function test_compares_windows_paths_at_component_boundaries(): void
    {
        $this->assertTrue(path_is_same_as_or_descendant_of('D:\\site\\upload.txt', 'd:/site'));
        $this->assertTrue(path_is_same_as_or_descendant_of('D:/site', 'D:/'));
        $this->assertFalse(path_is_same_as_or_descendant_of('D:/site-old', 'D:/site'));
        $this->assertFalse(path_is_same_as_or_descendant_of('E:/site', 'D:/'));
        $this->assertFalse(path_is_same_as_or_descendant_of('D:/site', '/'));
        $this->assertTrue(path_is_descendant_of('D:\\site', 'd:/'));
        $this->assertFalse(path_is_descendant_of('D:\\site', 'd:/site'));
        $this->assertSame('/upload.txt', path_remainder_under('D:\\site\\upload.txt', 'd:/site'));
    }

    /** Drive names remain separate under the Linux destination root. */
    public function test_maps_windows_paths_into_linux_filesystem(): void
    {
        $mapper = new RemoteToLocalPathMapper('/local', ['D:/site']);
        $this->assertSame('/local/D:/site/upload.txt', $mapper->remote_path_to_local_path('D:\\site\\upload.txt'));
        $this->assertSame('/local/E:/site/upload.txt', $mapper->remote_path_to_local_path('E:/site/upload.txt'));
        $mapper = new RemoteToLocalPathMapper('/local', ['D:/site'], ['D:/site' => '/local/site']);
        $this->assertSame('/local/site/upload.txt', $mapper->remote_path_to_local_path('D:\\site\\upload.txt'));
    }

    /** Backslashes are ordinary filename bytes on Unix, not path separators. */
    public function test_preserves_unix_backslashes(): void
    {
        $path = '/site/name\\with\\backslashes.txt';
        assert_valid_path($path);
        $this->assertSame($path, normalize_path($path));
        $mapper = new RemoteToLocalPathMapper('/local', ['/site']);
        $this->assertSame('/local' . $path, $mapper->remote_path_to_local_path($path));
    }

    /** Validation must reject traversal before normalization can erase it. */
    public function test_rejects_windows_parent_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain dot-segments');
        assert_valid_path('D:\\site\\..\\outside');
    }

    /** A drive-relative path depends on the source process's working directory. */
    public function test_rejects_drive_relative_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        $this->assertSame('D:', trim_right_slash('D:'));
        assert_valid_path('D:site/file.txt');
    }
}
