<?php

use WordPress\Reprint\Server\Utils;
use PHPUnit\Framework\TestCase;

/** The supplied path format, not a prefix guess, controls path interpretation. */
final class PathFormatTest extends TestCase {
    /**
     * The same input bytes must have different meanings under each path format.
     *
     * @dataProvider path_spellings
     * @param string $path Input bytes.
     * @param string $windows_path Expected Windows separator spelling.
     * @param bool $unix_absolute Whether the Unix path is absolute.
     * @param bool $windows_absolute Whether the Windows path is fully absolute.
     */
    public function testExplicitFormatControlsSeparatorsAndRoots(
        string $path,
        string $windows_path,
        bool $unix_absolute,
        bool $windows_absolute
    ): void {
        $this->assertSame($path, Utils::normalize_path_separators($path, 'unix'));
        $this->assertSame($windows_path, Utils::normalize_path_separators($path, 'windows'));
        $this->assertSame($unix_absolute, Utils::is_absolute_path($path, 'unix'));
        $this->assertSame($windows_absolute, Utils::is_absolute_path($path, 'windows'));
    }

    /** @return array[] Input, Windows spelling, and absolute flags for both formats. */
    public static function path_spellings(): array {
        return [
            ['D:\\photos', 'D:/photos', false, true],
            ['d:/photos', 'D:/photos', false, true],
            ['\\\\server\\share\\photos', '\\\\SERVER\\SHARE/photos', false, true],
            ['//server/share/photos', '\\\\SERVER\\SHARE/photos', true, true],
            ['/site/workspace\\group\\user/www', '/site/workspace/group/user/www', true, false],
            ['photos\\old', 'photos/old', false, false],
            ['..\\photos', '../photos', false, false],
            ['\\photos', '/photos', false, false],
            ['D:photos', 'D:photos', false, false],
        ];
    }

    /** A forward-slash share is Windows when the caller supplies Windows rules. */
    public function testWindowsLinkSourceDoesNotNeedBackslashRootToIdentifyItsFormat(): void {
        $this->assertSame(
            '\\\\SERVER\\SHARE/photos',
            Utils::resolve_symlink_target_path('//server/share/site/link', '..\\photos', 'windows')
        );
    }

    /** A Windows-looking Linux link source is still relative and lacks its base. */
    public function testUnixLinkSourceMustBeAbsoluteUnderUnixRules(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        Utils::resolve_symlink_target_path('D:\\site\\link', 'photos', 'unix');
    }

    /** A Windows root-relative link source cannot supply the missing drive. */
    public function testWindowsLinkSourceMustSupplyItsDriveOrShare(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        Utils::resolve_symlink_target_path('/site/link', 'photos', 'windows');
    }

    /** Unix validation must not accept a drive prefix as proof of an absolute path. */
    public function testUnixValidationRejectsWindowsLookingRelativeName(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        Utils::assert_valid_path('D:\\photos', 'unix', 'source path');
    }

    /** Validation must check the actual bytes, including a final NUL byte. */
    public function testValidationDoesNotTrimAwayInvalidPathBytes(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain NUL bytes');
        Utils::assert_valid_path("/site/photos\0", 'unix');
    }

    /** Unknown formats must fail instead of silently selecting either set of rules. */
    public function testUnknownFormatIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('amiga');
        Utils::normalize_path_separators('/site/photos', 'amiga');
    }

    /**
     * A link value must resolve from the link's directory, whatever the spelling of either path.
     *
     * realpath() returns backslashes and an uppercase drive on Windows; splitting
     * that on "/" alone produced values such as ../C:\Users\site.
     *
     * @dataProvider link_targets
     * @param string $link_directory Directory that will contain the link.
     * @param string $target_path Path the link must reach.
     * @param string $path_format Format of both paths.
     * @param string $link_value Expected link value.
     */
    public function testRelativeLinkTargetUsesTheSuppliedFormat(
        string $link_directory,
        string $target_path,
        string $path_format,
        string $link_value
    ): void {
        $this->assertSame($link_value, Utils::relative_link_target($link_directory, $target_path, $path_format));
    }

    /** @return array[] Link directory, target, path format, and link value. */
    public static function link_targets(): array {
        return [
            'Unix sibling' => ['/a/b/c', '/a/d/e', 'unix', '../../d/e'],
            'Unix descendant' => ['/a/b', '/a/b/c/d', 'unix', 'c/d'],
            'Unix same directory' => ['/a/b/', '/a/b', 'unix', '.'],
            'Unix from the root' => ['/', '/srv/site', 'unix', 'srv/site'],
            'Unix keeps backslashes in names' => ['/a/b\\c/e', '/a/d', 'unix', '../../d'],
            'Unix dot segments' => ['/a/b/../c', '/a/./d', 'unix', '../d'],
            'Windows realpath spelling' => ['C:\\Users\\me\\flat', 'C:\\Users\\me\\files\\srv\\htdocs\\wp-config.php', 'windows', '..\\files\\srv\\htdocs\\wp-config.php'],
            'Windows mixed separators and drive case' => ['c:/Users/me/flat/wp-content', 'C:\\Users\\me\\files/wordpress', 'windows', '..\\..\\files\\wordpress'],
            'Windows drive root' => ['D:\\', 'D:\\site\\wp-content', 'windows', 'site\\wp-content'],
            'Windows other drive' => ['C:\\site', 'D:\\files\\wordpress', 'windows', 'D:\\files\\wordpress'],
            'Windows share' => ['\\\\server\\share\\site', '//SERVER/share/files', 'windows', '..\\files'],
            'Windows other share' => ['\\\\server\\one\\site', '\\\\server\\two\\site', 'windows', '\\\\SERVER\\TWO\\site'],
        ];
    }

    /** A relative path has no base from which to compute a link value. */
    public function testRelativeLinkTargetRejectsARelativePath(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Link directory must be an absolute windows path; received "site".');
        Utils::relative_link_target('site', 'C:\\site', 'windows');
    }
}
