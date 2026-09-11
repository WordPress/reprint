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
        assert_valid_path('D:\\Sites\\example.test/', 'directory entry');
        $this->assertSame('D:/Sites/example.test', normalize_path('D:\\Sites\\example.test/'));
        $this->assertSame('D:/', normalize_path('d:\\site\\..\\..'));
        $this->assertSame('D:/', trim_right_slash('D:/'));
    }

    /**
     * Windows separators change, but filename case and bytes must not change.
     *
     * @dataProvider portable_windows_paths
     * @param string $remote_path Windows source spelling.
     * @param string $expected_path Expected slash-delimited spelling.
     */
    public function test_maps_portable_windows_filename_bytes(string $remote_path, string $expected_path): void
    {
        assert_valid_path($remote_path);
        $mapper = new RemoteToLocalPathMapper('/local', []);
        $this->assertSame($expected_path, normalize_path($remote_path));
        $this->assertSame('/local/' . $expected_path, $mapper->remote_path_to_local_path($remote_path));
    }

    /**
     * Names here fit within Linux's 255-byte filename limit without renaming.
     *
     * @return array[] Each row contains the source and expected path spellings.
     */
    public static function portable_windows_paths(): array
    {
        return [
            'lowercase drive and mixed separators' => ['c:\\Sites/Mixed Case\\File.txt', 'C:/Sites/Mixed Case/File.txt'],
            'different drive' => ['Z:\\Sites\\File.txt', 'Z:/Sites/File.txt'],
            'URL and shell punctuation' => ["D:\\Sites\\[draft] #100% & dollar$ 'quote'.txt", "D:/Sites/[draft] #100% & dollar$ 'quote'.txt"],
            'Unicode and emoji' => ['D:\\Sites\\日本語\\café 😀.txt', 'D:/Sites/日本語/café 😀.txt'],
            'decomposed Unicode' => ["D:\\Sites\\cafe\u{0301}.txt", "D:/Sites/cafe\u{0301}.txt"],
            'leading spaces and dotfile' => ['D:\\Sites\\ space\\.hidden', 'D:/Sites/ space/.hidden'],
            'percent bytes are not URL escapes' => ['D:\\Sites\\%2e%2e\\file.txt', 'D:/Sites/%2e%2e/file.txt'],
            '255-byte filename' => ['D:\\Sites\\' . str_repeat('a', 251) . '.txt', 'D:/Sites/' . str_repeat('a', 251) . '.txt'],
            'long total path' => ['D:\\Sites\\' . str_repeat('nested\\', 45) . 'file.txt', 'D:/Sites/' . str_repeat('nested/', 45) . 'file.txt'],
        ];
    }

    /** Both spellings must describe the same selected Windows subtree. */
    public function test_compares_windows_paths_at_component_boundaries(): void
    {
        $this->assertTrue(path_is_same_as_or_descendant_of('D:\\site\\upload.txt', 'd:/site'));
        $this->assertTrue(path_is_same_as_or_descendant_of('D:/site', 'D:/'));
        $this->assertTrue(path_is_same_as_or_descendant_of('D:/site/upload.txt', 'd:\\\\site'));
        $this->assertTrue(path_is_same_as_or_descendant_of('\\\\SERVER\\SHARE/site/file.txt', '\\\\server\\share\\\\site'));
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

    /** UNC share roots must stay distinct from drive roots and Unix directories. */
    public function test_maps_unc_share_paths_without_losing_the_share_root(): void
    {
        $remote = '\\\\server\\media\\Sites\\photo.jpg';
        assert_valid_path($remote);
        $this->assertSame('\\\\SERVER\\MEDIA/Sites/photo.jpg', normalize_path($remote));
        $this->assertSame('\\\\SERVER\\MEDIA', normalize_path('\\\\server\\media\\site\\..\\..'));
        $this->assertTrue(path_is_same_as_or_descendant_of($remote, '\\\\server\\media\\'));
        $this->assertFalse(path_is_same_as_or_descendant_of($remote, '\\\\server\\media-old'));
        $this->assertSame('/Sites/photo.jpg', path_remainder_under($remote, '\\\\server\\media'));
        $mapper = new RemoteToLocalPathMapper('/local', []);
        $this->assertSame('/local/UNC/SERVER/MEDIA/Sites/photo.jpg', $mapper->remote_path_to_local_path($remote));
        $this->assertSame('/local/C:/UNC/SERVER/MEDIA/Sites/photo.jpg', $mapper->remote_path_to_local_path('C:\\UNC\\SERVER\\MEDIA\\Sites\\photo.jpg'));
        $this->assertSame('/local/server/media/name\\with\\backslashes', $mapper->remote_path_to_local_path('//server/media/name\\with\\backslashes'));
    }

    /**
     * Relative paths, incomplete shares, and device namespaces cannot select a source tree.
     *
     * @dataProvider unsupported_windows_paths
     * @param string $path Unsupported source path.
     */
    public function test_rejects_unsupported_windows_path_forms(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        assert_valid_path($path);
    }

    /** @return array[] Unsupported spellings, kept separate from portable filenames. */
    public static function unsupported_windows_paths(): array
    {
        return [
            'drive-relative' => ['C:Sites\\file.txt'],
            'current-drive-relative' => ['\\Sites\\file.txt'],
            'incomplete share' => ['\\\\server'],
            'missing share name' => ['\\\\server\\'],
            'share parent' => ['\\\\server\\..\\outside'],
            'UNC parent component' => ['\\\\server\\share\\..\\outside'],
            'mixed parent component' => ['C:/Sites\\..\\outside'],
            'current component' => ['C:\\Sites\\.\\file.txt'],
            'extended drive namespace' => ['\\\\?\\C:\\Sites'],
            'extended UNC namespace' => ['\\\\?\\UNC\\server\\share'],
            'device namespace' => ['\\\\.\\PhysicalDrive0'],
            'NUL byte' => ["C:\\Sites\\bad\0name"],
        ];
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
