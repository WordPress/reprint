<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileIndexEndpointRunnerTrait.php';

final class FileIndexDedupTest extends TestCase
{
    use FileIndexEndpointRunnerTrait;

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
}
