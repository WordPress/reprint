<?php

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Server\assert_valid_path;
use function WordPress\Reprint\Server\is_absolute_path;
use function WordPress\Reprint\Server\normalize_path_separators;
use function WordPress\Reprint\Server\resolve_symlink_target_path;

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
        $this->assertSame($path, normalize_path_separators($path, 'unix'));
        $this->assertSame($windows_path, normalize_path_separators($path, 'windows'));
        $this->assertSame($unix_absolute, is_absolute_path($path, 'unix'));
        $this->assertSame($windows_absolute, is_absolute_path($path, 'windows'));
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
            resolve_symlink_target_path('//server/share/site/link', '..\\photos', 'windows')
        );
    }

    /** A Windows-looking Linux link source is still relative and lacks its base. */
    public function testUnixLinkSourceMustBeAbsoluteUnderUnixRules(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        resolve_symlink_target_path('D:\\site\\link', 'photos', 'unix');
    }

    /** A Windows root-relative link source cannot supply the missing drive. */
    public function testWindowsLinkSourceMustSupplyItsDriveOrShare(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        resolve_symlink_target_path('/site/link', 'photos', 'windows');
    }

    /** Unix validation must not accept a drive prefix as proof of an absolute path. */
    public function testUnixValidationRejectsWindowsLookingRelativeName(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute path');
        assert_valid_path('D:\\photos', 'unix', 'source path');
    }

    /** Validation must check the actual bytes, including a final NUL byte. */
    public function testValidationDoesNotTrimAwayInvalidPathBytes(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain NUL bytes');
        assert_valid_path("/site/photos\0", 'unix');
    }

    /** Unknown formats must fail instead of silently selecting either set of rules. */
    public function testUnknownFormatIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('amiga');
        normalize_path_separators('/site/photos', 'amiga');
    }
}
