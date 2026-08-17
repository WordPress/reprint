<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/packages/reprint-server/src/export.php';

// Loading export.php installs handlers that would exit() on a later test.
restore_error_handler();
restore_exception_handler();

final class ExportResolveDirectoriesTest extends TestCase {

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/resolve-directories-' . uniqid();
        mkdir($this->tempDir . '/site', 0755, true);
        file_put_contents($this->tempDir . '/site/wp-config.php', '<?php');
        symlink('wp-config.php', $this->tempDir . '/site/config-link.php');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/site/config-link.php');
        @unlink($this->tempDir . '/site/wp-config.php');
        @rmdir($this->tempDir . '/site');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testDirectoryEntryStillResolves(): void
    {
        $resolved = resolve_directories(['directory' => [$this->tempDir . '/site']]);
        $this->assertSame([realpath($this->tempDir . '/site')], $resolved);
    }

    public function testDirectoryResolverRejectsAFileEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not an accessible directory');

        resolve_directories(['directory' => [$this->tempDir . '/site/wp-config.php']]);
    }

    public function testFileIndexResolverKeepsRequestedAndResolvedCoordinates(): void
    {
        $roots = resolve_file_index_roots([
            'directory' => [$this->tempDir . '/site/config-link.php'],
            'follow_symlinks' => true,
        ]);
        $this->assertSame(
            [[
                'requested_path' => $this->tempDir . '/site/config-link.php',
                'resolved_path' => realpath($this->tempDir . '/site/wp-config.php'),
                'type' => 'symlink',
            ]],
            $roots
        );
    }

    public function testMissingEntryNamesTheObservedPath(): void
    {
        $missing = $this->tempDir . '/site/absent.php';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($missing);

        resolve_directories(['directory' => [$missing]]);
    }
}
