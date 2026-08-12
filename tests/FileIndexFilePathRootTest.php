<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileIndexEndpointRunnerTrait.php';

final class FileIndexFilePathRootTest extends TestCase
{
    use FileIndexEndpointRunnerTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-file-root-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testEndpointIndexesASingleFileRoot(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/wp-content', 0755, true);
        file_put_contents($docroot . '/wp-config.php', '<?php // config');
        file_put_contents($docroot . '/wp-content/style.css', 'body{}');
        $configPath = (string) realpath($docroot . '/wp-config.php');

        $paths = $this->runFileIndex([$configPath], $configPath);

        $this->assertSame([$configPath], $paths);
    }

    public function testEndpointIndexesAFileRootAlongsideADirectoryRoot(): void
    {
        $docroot = $this->tempDir . '/site';
        mkdir($docroot . '/wp-content/plugins/hello', 0755, true);
        file_put_contents($docroot . '/wp-config.php', '<?php // config');
        file_put_contents($docroot . '/wp-content/plugins/hello/hello.php', '<?php // hello');
        $configPath = (string) realpath($docroot . '/wp-config.php');
        $pluginsPath = (string) realpath($docroot . '/wp-content/plugins');

        $paths = $this->runFileIndex([$configPath, $pluginsPath], $configPath);

        $this->assertContains($configPath, $paths);
        $this->assertContains($pluginsPath . '/hello/hello.php', $paths);
    }
}
