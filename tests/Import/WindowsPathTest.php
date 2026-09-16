<?php

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Server\assert_valid_path;
use function WordPress\Reprint\Server\normalize_path;
use function WordPress\Reprint\Server\normalize_path_separators;
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
        assert_valid_path('D:\\Sites\\example.test/', 'windows', 'directory entry');
        $this->assertSame('D:/Sites/example.test', normalize_path('D:\\Sites\\example.test/', 'windows'));
        $this->assertSame('D:/', normalize_path('d:\\site\\..\\..', 'windows'));
        $this->assertSame('D:/', trim_right_slash('D:/', 'windows'));
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
        assert_valid_path($remote_path, 'windows');
        $mapper = new RemoteToLocalPathMapper('/local', 'windows', []);
        $this->assertSame($expected_path, normalize_path_separators($remote_path, 'windows'));
        $this->assertSame($expected_path, normalize_path($remote_path, 'windows'));
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
        $path = normalize_path_separators('D:\\site\\upload.txt', 'windows');
        $root = normalize_path_separators('d:/', 'windows');
        $prefix = normalize_path_separators('d:\\\\site', 'windows');
        $share_path = normalize_path_separators('\\\\SERVER\\SHARE/site/file.txt', 'windows');
        $share_prefix = normalize_path_separators('\\\\server\\share\\\\site', 'windows');
        $this->assertTrue(path_is_same_as_or_descendant_of($path, $prefix));
        $this->assertTrue(path_is_same_as_or_descendant_of($prefix, $root));
        $this->assertTrue(path_is_same_as_or_descendant_of($share_path, $share_prefix));
        $this->assertFalse(path_is_same_as_or_descendant_of('D:/site-old', $prefix));
        $this->assertFalse(path_is_same_as_or_descendant_of('E:/site', $root));
        $this->assertFalse(path_is_same_as_or_descendant_of($prefix, '/'));
        $this->assertTrue(path_is_descendant_of($prefix, $root));
        $this->assertFalse(path_is_descendant_of($prefix, $prefix));
        $this->assertSame('/upload.txt', path_remainder_under($path, $prefix));
    }

    /** Drive names remain separate under the Linux destination root. */
    public function test_maps_windows_paths_into_linux_filesystem(): void
    {
        $mapper = new RemoteToLocalPathMapper('/local', 'windows', ['D:/site']);
        $this->assertSame('/local/D:/site/upload.txt', $mapper->remote_path_to_local_path('D:\\site\\upload.txt'));
        $this->assertSame('/local/E:/site/upload.txt', $mapper->remote_path_to_local_path('E:/site/upload.txt'));
        $mapper = new RemoteToLocalPathMapper('/local', 'windows', ['D:/site'], ['D:/site' => '/local/site']);
        $this->assertSame('/local/site/upload.txt', $mapper->remote_path_to_local_path('D:\\site\\upload.txt'));
    }

    /** UNC share roots must stay distinct from drive roots and Unix directories. */
    public function test_maps_unc_share_paths_without_losing_the_share_root(): void
    {
        $remote = '\\\\server\\media\\Sites\\photo.jpg';
        assert_valid_path($remote, 'windows');
        $this->assertSame('\\\\SERVER\\MEDIA/Sites/photo.jpg', normalize_path($remote, 'windows'));
        $this->assertSame('\\\\SERVER\\MEDIA', normalize_path('\\\\server\\media\\site\\..\\..', 'windows'));
        $remote = normalize_path_separators($remote, 'windows');
        $this->assertTrue(path_is_same_as_or_descendant_of($remote, normalize_path_separators('\\\\server\\media\\', 'windows')));
        $this->assertFalse(path_is_same_as_or_descendant_of($remote, normalize_path_separators('\\\\server\\media-old', 'windows')));
        $this->assertSame('/Sites/photo.jpg', path_remainder_under($remote, normalize_path_separators('\\\\server\\media', 'windows')));
        $mapper = new RemoteToLocalPathMapper('/local', 'windows', []);
        $this->assertSame('/local/UNC/SERVER/MEDIA/Sites/photo.jpg', $mapper->remote_path_to_local_path($remote));
        $this->assertSame('/local/C:/UNC/SERVER/MEDIA/Sites/photo.jpg', $mapper->remote_path_to_local_path('C:\\UNC\\SERVER\\MEDIA\\Sites\\photo.jpg'));
        $mapper = new RemoteToLocalPathMapper('/local', 'unix', []);
        $this->assertSame('//server/media/name\\with\\backslashes', normalize_path_separators('//server/media/name\\with\\backslashes', 'unix'));
        $this->assertSame('/local/server/media/name\\with\\backslashes', $mapper->remote_path_to_local_path('//server/media/name\\with\\backslashes'));
    }

    /** The same leading slashes select a Unix tree or a Windows share by format. */
    public function test_maps_the_same_path_text_with_explicit_source_rules(): void
    {
        $path = '//server/share/photos';
        $unix_mapper = new RemoteToLocalPathMapper('/local', 'unix', []);
        $windows_mapper = new RemoteToLocalPathMapper('/local', 'windows', []);
        $this->assertSame('/local/server/share/photos', $unix_mapper->remote_path_to_local_path($path));
        $this->assertSame('/local/UNC/SERVER/SHARE/photos', $windows_mapper->remote_path_to_local_path($path));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        $unix_mapper->remote_path_to_local_path('D:\\photos');
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
        assert_valid_path($path, 'windows');
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

    /**
     * Backslashes in a known absolute Unix path must remain filename bytes.
     *
     * @dataProvider unix_backslash_paths
     * @param string $path Absolute Unix path whose components contain backslashes.
     */
    public function test_preserves_unix_backslashes(string $path): void
    {
        assert_valid_path($path, 'unix');
        $this->assertSame($path, normalize_path_separators($path, 'unix'));
        $this->assertSame($path, normalize_path($path, 'unix'));
        $mapper = new RemoteToLocalPathMapper('/local', 'unix', ['/site']);
        $this->assertSame('/local' . $path, $mapper->remote_path_to_local_path($path));
    }

    /** @return array[] Absolute Unix paths for the documented separator cases. */
    public static function unix_backslash_paths(): array
    {
        return [
            ['/site/name\\with\\backslashes.txt'],
            ['/site/workspace\\group\\user/www'],
            ['/site/D:\\photos'],
        ];
    }

    /**
     * Link targets use the source format, including when read on another operating system.
     *
     * @dataProvider symlink_targets
     * @param string $source_path Absolute source link with normalized separators.
     * @param string $target Stored target, which can be relative.
     * @param string $expected Expected absolute source path.
     * @param string $path_format Explicit source path format.
     */
    public function testResolvesLinkTargetsInTheirSourceFormat(string $source_path, string $target, string $expected, string $path_format): void
    {
        $this->assertSame($expected, \WordPress\Reprint\Server\resolve_symlink_target_path($source_path, $target, $path_format));
    }

    /** @return array[] Source link, stored target, and the resolved source path. */
    public static function symlink_targets(): array
    {
        return [
            ['/site/link', 'D:\\photos', '/site/D:\\photos', 'unix'],
            ['/site/link', 'D:/photos', '/site/D:/photos', 'unix'],
            ['/site/link', '\\\\server\\share', '/site/\\\\server\\share', 'unix'],
            ['/site/link', 'D:\\..\\photos', '/site/D:\\..\\photos', 'unix'],
            ['/site/link', '/shared/photo\\old', '/shared/photo\\old', 'unix'],
            ['/site/link', '/photos/image.jpg', '/photos/image.jpg', 'unix'],
            ['/site/link', '\\photos\\image.jpg', '/site/\\photos\\image.jpg', 'unix'],
            ['/site/link', '//server/share/photos/image.jpg', '/server/share/photos/image.jpg', 'unix'],
            ['/site/workspace\\group\\user/link', 'www/image.jpg', '/site/workspace\\group\\user/www/image.jpg', 'unix'],
            ['/site/link', "../photo\xff\\old", "/photo\xff\\old", 'unix'],
            ['D:/site/link', 'D:\\photos', 'D:/photos', 'windows'],
            ['D:/site/link', '..\\photos\\image.jpg', 'D:/photos/image.jpg', 'windows'],
            ['D:/site/link', '../photos/image.jpg', 'D:/photos/image.jpg', 'windows'],
            ['D:/site/link', '..\\photos/image.jpg', 'D:/photos/image.jpg', 'windows'],
            ['D:/site/link', 'e:\\photos\\image.jpg', 'E:/photos/image.jpg', 'windows'],
            ['D:/site/link', 'E:/photos/image.jpg', 'E:/photos/image.jpg', 'windows'],
            ['D:/site/link', '\\\\server\\share\\image.jpg', '\\\\SERVER\\SHARE/image.jpg', 'windows'],
            ['\\\\SERVER\\SHARE/site/link', '..\\photos/image.jpg', '\\\\SERVER\\SHARE/photos/image.jpg', 'windows'],
            ['\\\\SERVER\\SHARE/site/link', '../../image.jpg', '\\\\SERVER\\SHARE/image.jpg', 'windows'],
            ['D:/site/link', '/photos/image.jpg', 'D:/photos/image.jpg', 'windows'],
            ['D:/site/link', '\\photos\\image.jpg', 'D:/photos/image.jpg', 'windows'],
            ['\\\\SERVER\\SHARE/site/link', '/photos/image.jpg', '\\\\SERVER\\SHARE/photos/image.jpg', 'windows'],
            ['D:/site/link', '//server/share/photos/image.jpg', '\\\\SERVER\\SHARE/photos/image.jpg', 'windows'],
            ['D:/link', 'image.jpg', 'D:/image.jpg', 'windows'],
        ];
    }

    /** Validation must reject traversal before normalization can erase it. */
    public function test_rejects_windows_parent_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain dot-segments');
        assert_valid_path('D:\\site\\..\\outside', 'windows');
    }

    /** A drive-relative path depends on the source process's working directory. */
    public function test_rejects_drive_relative_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        $this->assertSame('D:', trim_right_slash('D:', 'windows'));
        assert_valid_path('D:site/file.txt', 'windows');
    }
}
