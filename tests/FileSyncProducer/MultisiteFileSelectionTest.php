<?php

use PHPUnit\Framework\TestCase;
use WordPress\Reprint\Server\FileIndexProcessor;
use WordPress\Reprint\Server\FileTreeProducer;
use WordPress\Reprint\Server\MultisiteFileSelection;

/** Real filesystem traversal and streaming for overlapping multisite upload roots. */
class MultisiteFileSelectionTest extends TestCase
{
    private string $root;

    /** Build a main site's media root with two nested sibling media roots. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reprint-multisite-files-' . uniqid();
        foreach ([
            'wp-content/uploads/main.txt' => 'main-private',
            'wp-content/uploads/sites/7/photo.txt' => str_repeat('shop-image', 1000),
            'wp-content/uploads/sites/8/photo.txt' => 'sibling-private',
            'wp-content/plugins/network-plugin/plugin.php' => '<?php // shared code',
            'wp-content/plugins/reprint-server/secret.php' => 'network-secret',
            'wp-content/themes/shared/style.css' => 'shared theme',
            'wp-content/debug.log' => 'network-private',
            'wp-admin/index.php' => '<?php',
            'wp-includes/version.php' => '<?php',
            'wp-config.php' => 'source-credentials',
            'index.php' => '<?php',
        ] as $relative_path => $contents) {
            $filename = $this->root . '/' . $relative_path;
            if (!is_dir(dirname($filename))) {
                mkdir(dirname($filename), 0755, true);
            }
            file_put_contents($filename, $contents);
        }
        $this->root = realpath($this->root);
    }

    /** Remove only this test's fixture, never following links. */
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    /** Both main and non-main selections prune sibling trees across every resume. */
    public function test_index_resumes_without_visiting_sibling_uploads(): void
    {
        foreach ([1, 7] as $site_id) {
            $selection = $this->selection($site_id);
            $roots = $this->roots();
            $processor = FileIndexProcessor::start($roots, $roots[0], false, '', $selection);
            $paths = [];
            $steps = 0;
            while ($processor->next_index_step()) {
                foreach ($processor->get_index_entries() as $entry) {
                    $paths[] = $entry['path'];
                }
                $this->assertNotSame($this->root . '/wp-content/uploads/sites/8', $processor->get_current_directory());
                $cursor = json_encode($processor->get_cursor());
                $processor->close();
                $processor = FileIndexProcessor::resume($roots, $cursor, false, '', $selection);
                $this->assertLessThan(200, ++$steps);
            }
            $processor->close();
            $selected = $site_id === 1 ? 'main.txt' : 'sites/7/photo.txt';
            $this->assertContains($this->root . '/wp-content/uploads/' . $selected, $paths);
            $this->assertNotContains($this->root . '/wp-content/uploads/sites/8/photo.txt', $paths);
            $this->assertNotContains($this->root . '/wp-config.php', $paths);
            $this->assertNotContains($this->root . '/wp-content/plugins/reprint-server/secret.php', $paths);
            $this->assertContains($this->root . '/wp-content/plugins/network-plugin/plugin.php', $paths);
        }
    }

    /** Every file chunk can resume without mixing another site's same-named file. */
    public function test_fetch_resumes_selected_media_after_each_chunk(): void
    {
        $options = [
            'paths' => [$this->root . '/wp-content/uploads/sites/7/photo.txt'],
            'multisite_selection' => $this->selection(7),
            'chunk_size' => 1024,
        ];
        $producer = new FileTreeProducer([$this->root], $options);
        $contents = '';
        $chunks = 0;
        while ($producer->next_chunk()) {
            $chunk = $producer->get_current_chunk();
            if ($chunk !== null && $chunk['type'] === 'file') {
                $contents .= $chunk['data'];
                ++$chunks;
            }
            $options['cursor'] = $producer->get_reentrancy_cursor();
            unset($producer);
            $producer = new FileTreeProducer([$this->root], $options);
            $this->assertLessThan(30, $chunks);
        }
        $this->assertSame(str_repeat('shop-image', 1000), $contents);
        $this->assertGreaterThan(1, $chunks);
        $options['multisite_selection'] = $this->selection(1);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multisite selection changed');
        new FileTreeProducer([$this->root], $options);
    }

    /** A client cannot bypass the index and request another site's bytes directly. */
    public function test_direct_fetch_rejects_sibling_media(): void
    {
        $producer = new FileTreeProducer([$this->root], [
            'paths' => [$this->root . '/wp-content/uploads/sites/8/photo.txt'],
            'multisite_selection' => $this->selection(7),
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the selected multisite site');
        while ($producer->next_chunk()) {}
    }

    /** A plugin or administrator linking sibling media cannot widen this pull. */
    public function test_symlink_to_sibling_media_is_rejected(): void
    {
        $filename = $this->root . '/wp-content/uploads/sites/7/link.txt';
        symlink('../8/photo.txt', $filename);
        $producer = new FileTreeProducer([$this->root], [
            'paths' => [$filename], 'multisite_selection' => $this->selection(7),
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Symlinks require');
        while ($producer->next_chunk()) {}
    }

    /** An old cursor may not select a different site's uploads after resume. */
    public function test_index_cursor_rejects_a_different_site(): void
    {
        $roots = $this->roots();
        $processor = FileIndexProcessor::start($roots, $roots[0], false, '', $this->selection(7));
        $cursor = json_encode($processor->get_cursor());
        $processor->close();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multisite selection changed');
        FileIndexProcessor::resume($roots, $cursor, false, '', $this->selection(1));
    }

    /** Uses a resolved directory root just like the real endpoint. */
    private function roots(): array
    {
        return [['requested_path' => $this->root, 'resolved_path' => $this->root, 'type' => 'directory']];
    }

    /** Builds trusted source metadata for one selected site. */
    private function selection(int $site_id): MultisiteFileSelection
    {
        return new MultisiteFileSelection([
            'site_id' => $site_id, 'abspath' => $this->root,
            'content_dir' => $this->root . '/wp-content',
            'uploads_dir' => $this->root . '/wp-content/uploads' . ($site_id === 1 ? '' : '/sites/' . $site_id),
            'exporter_dir' => $this->root . '/wp-content/plugins/reprint-server',
        ]);
    }
}
