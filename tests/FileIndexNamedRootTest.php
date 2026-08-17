<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/packages/reprint-server/src/class-file-index-processor.php';

final class FileIndexNamedRootTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-named-root-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        parent::tearDown();
    }

    public function testSelectedFileSymlinkEmitsItsLinkAndPhysicalTargetOnce(): void
    {
        $site = $this->tempDir . '/site';
        $shared = $this->tempDir . '/shared';
        mkdir($site, 0755, true);
        mkdir($shared, 0755, true);
        file_put_contents($shared . '/config.php', '<?php');
        symlink($shared . '/config.php', $site . '/config.php');

        $entries = $this->collect([
            $this->root($site . '/config.php', $shared . '/config.php', 'symlink'),
        ], $site . '/config.php');

        $paths = array_column($entries, 'path');
        $this->assertContains($site . '/config.php', $paths);
        $this->assertContains($shared . '/config.php', $paths);
        $this->assertSame('link', $this->entryAt($entries, $site . '/config.php')['type']);
        $this->assertSame('file', $this->entryAt($entries, $shared . '/config.php')['type']);
    }

    public function testSelectedDirectorySymlinkEmitsLinkThenIndexesPhysicalTree(): void
    {
        $site = $this->tempDir . '/site';
        $shared = $this->tempDir . '/shared';
        mkdir($site, 0755, true);
        mkdir($shared . '/theme', 0755, true);
        file_put_contents($shared . '/theme/style.css', 'body{}');
        symlink($shared . '/theme', $site . '/theme');

        $entries = $this->collect([
            $this->root($site . '/theme', $shared . '/theme', 'symlink'),
        ], $site . '/theme');

        $this->assertSame('link', $this->entryAt($entries, $site . '/theme')['type']);
        $this->assertContains(
            (string) realpath($shared . '/theme') . '/style.css',
            array_column($entries, 'path')
        );
    }

    public function testFollowedTargetOutsideConfiguredRootsCanStartAnIndex(): void
    {
        $site = $this->tempDir . '/site';
        $shared = $this->tempDir . '/shared';
        mkdir($site, 0755, true);
        mkdir($shared . '/theme', 0755, true);
        file_put_contents($shared . '/theme/style.css', 'body{}');
        symlink($shared . '/theme', $site . '/theme');

        $target = (string) realpath($shared . '/theme');
        $entries = $this->collect([
            $this->root($site . '/theme', $target, 'symlink'),
        ], $target);

        $this->assertContains($site . '/theme', array_column($entries, 'path'));
        $this->assertContains($target . '/style.css', array_column($entries, 'path'));
    }

    public function testParentSymlinkIsEmittedAtRequestedPathAndFileAtResolvedPath(): void
    {
        $releases = $this->tempDir . '/releases/42';
        $current = $this->tempDir . '/current';
        mkdir($releases, 0755, true);
        file_put_contents($releases . '/wp-config.php', '<?php');
        symlink($releases, $current);

        $entries = $this->collect([
            $this->root($current . '/wp-config.php', $releases . '/wp-config.php', 'file'),
        ], $current . '/wp-config.php');

        $this->assertSame('link', $this->entryAt($entries, $current)['type']);
        $this->assertSame('file', $this->entryAt($entries, $releases . '/wp-config.php')['type']);
    }

    public function testSelectedAliasesKeepBothLinksAndIndexOnePhysicalTargetAcrossResume(): void
    {
        $site = $this->tempDir . '/site';
        $shared = $this->tempDir . '/shared';
        mkdir($site, 0755, true);
        mkdir($shared, 0755, true);
        file_put_contents($shared . '/config.php', '<?php');
        symlink($shared . '/config.php', $site . '/first.php');
        symlink($shared . '/config.php', $site . '/second.php');
        $roots = [
            $this->root($site . '/first.php', $shared . '/config.php', 'symlink'),
            $this->root($site . '/second.php', $shared . '/config.php', 'symlink'),
            $this->root($shared . '/config.php', $shared . '/config.php', 'file'),
        ];

        $entries = $this->collect($roots, $site . '/first.php', true);
        $paths = array_column($entries, 'path');

        $this->assertContains($site . '/first.php', $paths);
        $this->assertContains($site . '/second.php', $paths);
        $this->assertSame(1, count(array_keys($paths, $shared . '/config.php', true)));
    }

    /** @param array[] $roots @return array[] */
    private function collect(array $roots, string $start, bool $resume = false): array
    {
        $processor = FileIndexProcessor::start($roots, $start, true, true, '');
        $entries = [];
        while ($processor->next_index_step()) {
            foreach ($processor->get_index_entries() as $entry) {
                $entries[] = $entry;
            }
            if ($resume) {
                $cursor = json_encode($processor->get_cursor(), JSON_THROW_ON_ERROR);
                $processor->close();
                $processor = FileIndexProcessor::resume($roots, $cursor, true, true, '');
            }
        }
        $processor->close();
        return $entries;
    }

    /** @return array{requested_path:string,resolved_path:string,type:string} */
    private function root(string $requested, string $resolved, string $type): array
    {
        return ['requested_path' => $requested, 'resolved_path' => $resolved, 'type' => $type];
    }

    /** @param array[] $entries @return array */
    private function entryAt(array $entries, string $path): array
    {
        foreach ($entries as $entry) {
            if ($entry['path'] === $path) {
                return $entry;
            }
        }
        $this->fail("Expected indexed path {$path}");
    }

    private function deleteTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
