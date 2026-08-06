<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../client/cli.php';

class SqliteRuntimePluginCopyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sqlite-runtime-copy-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
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
        foreach (scandir($dir) as $item) {
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

    private function pluginSource(): string
    {
        return dirname(__DIR__, 2) . '/lib/sqlite-database-integration/packages/plugin-sqlite-database-integration';
    }

    public function testCopyIncludesDatabaseDriverAsRealDirectory(): void
    {
        $copied = \copy_sqlite_plugin($this->pluginSource(), $this->tempDir);

        $database_dir = $copied . '/wp-includes/database';
        $this->assertDirectoryExists($database_dir);
        $this->assertFalse(is_link($database_dir));
        $this->assertFileExists($database_dir . '/version.php');
        $this->assertFileExists($database_dir . '/load.php');
    }

    public function testCopyRepairsExistingOutputMissingDatabaseDriver(): void
    {
        $target_database_dir = $this->tempDir . '/sqlite-database-integration/wp-includes/database';
        mkdir($target_database_dir, 0755, true);

        $copied = \copy_sqlite_plugin($this->pluginSource(), $this->tempDir);

        $this->assertSame($this->tempDir . '/sqlite-database-integration', $copied);
        $this->assertFileExists($copied . '/wp-includes/database/version.php');
        $this->assertFileExists($copied . '/wp-includes/database/load.php');
    }
}
