<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/utils.php';
require_once __DIR__ . '/../../packages/reprint-server/src/class-file-index-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-fresh-local-index-processor.php';

final class FreshLocalIndexProcessorTest extends TestCase {
    private string $temporary_directory;
    private string $filesystem_root;
    private string $fresh_local_index_file;
    private string $storage_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/fresh-local-index-processor-test-'
            . uniqid();
        $this->filesystem_root =
            $this->temporary_directory . '/filesystem-root';
        $this->fresh_local_index_file =
            $this->temporary_directory . '/fresh-local-index.jsonl';
        $this->storage_path = $this->temporary_directory . '/state';
        mkdir($this->filesystem_root, 0755, true);
        mkdir($this->storage_path, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temporary_directory);
        parent::tearDown();
    }

    public function testBuildsASortedLocalRelativeIndex(): void
    {
        mkdir($this->filesystem_root . '/empty');
        mkdir($this->filesystem_root . '/full');
        file_put_contents($this->filesystem_root . '/full/child.txt', 'child');
        file_put_contents($this->filesystem_root . '/z.txt', 'z');
        file_put_contents($this->filesystem_root . '/a.txt', 'a');

        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );
        $this->run_to_completion($processor);

        $entries = $this->read_index_entries();
        $this->assertSame(
            ['a.txt', 'empty', 'full/child.txt', 'z.txt'],
            array_column($entries, 'decoded_path')
        );
        $this->assertTrue($entries[1]['empty']);
        $this->assertArrayNotHasKey('empty', $entries[2]);
        $this->assertArrayNotHasKey('decoded_path', $entries[0]['stored']);
        $this->assertSame(base64_encode('a.txt'), $entries[0]['stored']['path']);
    }

    public function testResumeDiscardsBytesWrittenAfterTheSavedCursor(): void
    {
        file_put_contents($this->filesystem_root . '/a.txt', 'a');
        file_put_contents($this->filesystem_root . '/b.txt', 'b');

        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );
        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $saved_cursor = $processor->get_cursor();

        $this->assertTrue($processor->next_step());
        $processor->close();
        $this->assertGreaterThan(
            $saved_cursor['position']['fresh_local_index_byte_offset'],
            filesize($this->fresh_local_index_file)
        );

        $resumed_processor = FreshLocalIndexProcessor::resume($saved_cursor);
        $this->run_to_completion($resumed_processor);

        $this->assertSame(
            ['a.txt', 'b.txt'],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    public function testSortingIsASeparateResumableStep(): void
    {
        file_put_contents($this->filesystem_root . '/value.txt', 'value');
        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );

        while ($processor->get_phase() === 'indexing') {
            $this->assertTrue($processor->next_step());
        }
        $this->assertSame('sorting', $processor->get_phase());
        $sorting_cursor = $processor->get_cursor();
        $processor->close();

        $resumed_processor = FreshLocalIndexProcessor::resume($sorting_cursor);
        $this->assertFalse($resumed_processor->next_step());
        $this->assertSame('complete', $resumed_processor->get_phase());
        $this->assertFalse($resumed_processor->next_step());
        $resumed_processor->close();
    }

    private function run_to_completion(
        FreshLocalIndexProcessor $processor
    ): void {
        for ($step = 0; $step < 100; ++$step) {
            if (!$processor->next_step()) {
                $processor->close();
                return;
            }
        }
        $this->fail('Fresh local index did not complete within 100 steps.');
    }

    /** @return list<array<string,mixed>> */
    private function read_index_entries(): array
    {
        $lines = file(
            $this->fresh_local_index_file,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $this->assertIsArray($lines);
        $entries = [];
        foreach ($lines as $line) {
            $stored = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $decoded_path = base64_decode($stored['path'], true);
            $this->assertIsString($decoded_path);
            $entry = [
                'decoded_path' => $decoded_path,
                'stored' => $stored,
                'type' => $stored['type'],
            ];
            if (array_key_exists('empty', $stored)) {
                $entry['empty'] = $stored['empty'];
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    private function remove_path(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = scandir($path);
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $this->remove_path($path . '/' . $child);
                }
            }
        }
        rmdir($path);
    }
}
