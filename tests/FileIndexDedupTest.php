<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FileIndexDedupTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-dedup-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    /**
     * Simulates the wp.com Atomic layout where ABSPATH (__wp__) is a child
     * of the document root, but the document root also contains a separate
     * wp-content directory with the site's actual plugins.
     *
     * Both roots must be traversed: __wp__ for WordPress core, and the
     * document root for wp-content.  When traversing the document root,
     * the __wp__ subdirectory should NOT be re-entered (handled by the
     * during-traversal dedup).
     */
    public function testFileIndexTraversesParentRootWithSeparateContent(): void
    {
        // Layout:
        //   tempDir/
        //   ├── parent-only.txt
        //   └── srv/htdocs/           ← document root (parent root)
        //       ├── __wp__/           ← ABSPATH (child root)
        //       │   └── core.txt
        //       └── wp-content/
        //           └── plugin.txt    ← site's actual plugin (MUST be indexed)
        $docroot = $this->tempDir . '/srv/htdocs';
        $abspath = $docroot . '/__wp__';
        mkdir($abspath, 0755, true);
        mkdir($docroot . '/wp-content', 0755, true);
        file_put_contents($abspath . '/core.txt', 'core');
        file_put_contents($docroot . '/wp-content/plugin.txt', 'plugin');
        file_put_contents($this->tempDir . '/parent-only.txt', 'parent');
        $docrootReal = realpath($docroot);
        $abspathReal = realpath($abspath);

        $this->assertNotFalse($docrootReal);
        $this->assertNotFalse($abspathReal);

        $paths = $this->runFileIndex(
            [$abspath, $docroot],
            $abspath,
        );

        // WordPress core file from __wp__ must be present
        $this->assertContains($abspathReal . '/core.txt', $paths);
        // Site's wp-content from the document root must be present
        $this->assertContains(
            $docrootReal . '/wp-content/plugin.txt',
            $paths,
            'The index must include wp-content from the parent document root',
        );
        // Files above the document root should not leak
        $this->assertNotContains(
            realpath($this->tempDir) . '/parent-only.txt',
            $paths,
            'Files above the document root should not be indexed',
        );
    }

    /**
     * @param string[] $directories
     * @return string[]
     */
    private function runFileIndex(array $directories, string $listDir): array
    {
        $configPath = $this->tempDir . '/config.json';
        file_put_contents(
            $configPath,
            json_encode([
                'directory' => $directories,
                'list_dir' => $listDir,
                'follow_symlinks' => true,
                'batch_size' => 1000,
            ], JSON_THROW_ON_ERROR),
        );

        $scriptPath = $this->tempDir . '/run-file-index.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
require_once %s;
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_index($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
                var_export(dirname(__DIR__) . '/packages/reprint-server/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_index should exit cleanly.\nstderr: {$stderr}");

        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded, 'Expected gzip-compressed multipart response');

        preg_match_all('/"path":"([^"]+)"/', $decoded, $matches);

        return array_map(
            static fn(string $encodedPath): string => (string) base64_decode($encodedPath, true),
            $matches[1],
        );
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
