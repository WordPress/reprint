<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Exporter\canonical_root_path;

final class ExporterUtilsTest extends TestCase {

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/exporter-utils-' . uniqid();
        mkdir($this->tempDir . '/real', 0755, true);
        file_put_contents($this->tempDir . '/real/target.txt', 'hi');
        symlink('real/target.txt', $this->tempDir . '/link-to-file');
        symlink('nowhere.txt', $this->tempDir . '/broken-link');
        symlink('real', $this->tempDir . '/link-to-dir');
    }

    protected function tearDown(): void
    {
        foreach (['link-to-file', 'broken-link', 'link-to-dir', 'real/target.txt'] as $path) {
            @unlink($this->tempDir . '/' . $path);
        }
        @rmdir($this->tempDir . '/real');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testDirectoryResolvesThroughRealpath(): void
    {
        $this->assertSame(
            realpath($this->tempDir . '/real'),
            canonical_root_path($this->tempDir . '/real')
        );
    }

    public function testDirectorySymlinkStillResolvesToItsTarget(): void
    {
        $this->assertSame(
            realpath($this->tempDir . '/real'),
            canonical_root_path($this->tempDir . '/link-to-dir'),
            'A symlinked directory root must keep resolving, as traversal depends on it'
        );
    }

    public function testRegularFileKeepsItsOwnPath(): void
    {
        $this->assertSame(
            realpath($this->tempDir) . '/real/target.txt',
            canonical_root_path($this->tempDir . '/real/target.txt')
        );
    }

    public function testFileSymlinkKeepsItsOwnPathInsteadOfTheTarget(): void
    {
        $this->assertSame(
            realpath($this->tempDir) . '/link-to-file',
            canonical_root_path($this->tempDir . '/link-to-file'),
            'A file link must not collapse into its target, or pull writes it to the wrong path'
        );
    }

    public function testBrokenSymlinkIsAcceptedRatherThanRejected(): void
    {
        $this->assertSame(
            realpath($this->tempDir) . '/broken-link',
            canonical_root_path($this->tempDir . '/broken-link')
        );
    }

    public function testMissingPathReturnsNull(): void
    {
        $this->assertNull(canonical_root_path($this->tempDir . '/absent.txt'));
    }

    public function testPathUnderAMissingParentReturnsNull(): void
    {
        $this->assertNull(canonical_root_path($this->tempDir . '/absent-dir/absent.txt'));
    }
}
