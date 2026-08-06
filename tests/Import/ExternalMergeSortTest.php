<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/external-merge-sort.php';

final class ExternalMergeSortTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/external-merge-sort-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Basic sorting
    // ------------------------------------------------------------------

    public function testSortsSimpleLines(): void
    {
        $path = $this->writeFile("cherry\nbanana\napple\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("apple\nbanana\ncherry\n", file_get_contents($path));
    }

    public function testSortIsStableWithinChunk(): void
    {
        // When two lines share the same sort key, their relative order within
        // a chunk is preserved by the stable usort (PHP 8.0+).
        $path = $this->writeFile("b:2\na:1\nb:1\na:2\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => explode(':', $line)[0],
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $lines = $this->readLines($path);
        // Both 'a' lines come first, both 'b' lines after.
        $this->assertSame('a', explode(':', $lines[0])[0]);
        $this->assertSame('a', explode(':', $lines[1])[0]);
        $this->assertSame('b', explode(':', $lines[2])[0]);
        $this->assertSame('b', explode(':', $lines[3])[0]);
    }

    public function testAlreadySortedFile(): void
    {
        $path = $this->writeFile("alpha\nbeta\ngamma\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($path));
    }

    public function testReverseSortedFile(): void
    {
        $path = $this->writeFile("z\ny\nx\nw\nv\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("v\nw\nx\ny\nz\n", file_get_contents($path));
    }

    // ------------------------------------------------------------------
    //  Deduplication
    // ------------------------------------------------------------------

    public function testDeduplicatesWithinSingleChunk(): void
    {
        $path = $this->writeFile("b\na\nb\na\nc\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            true,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("a\nb\nc\n", file_get_contents($path));
    }

    public function testDeduplicatesAcrossChunks(): void
    {
        // Force each line into its own chunk by using a tiny budget.
        // Lines: "b", "a", "b", "c" — after sort+dedup: "a", "b", "c".
        $path = $this->writeFile("b\na\nb\nc\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024, // tiny budget — each line becomes its own chunk
            true,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("a\nb\nc\n", file_get_contents($path));
    }

    public function testNoDedupWhenDisabled(): void
    {
        $path = $this->writeFile("b\na\nb\na\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("a\na\nb\nb\n", file_get_contents($path));
    }

    public function testAllDuplicateLines(): void
    {
        $path = $this->writeFile("same\nsame\nsame\nsame\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            true,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("same\n", file_get_contents($path));
    }

    // ------------------------------------------------------------------
    //  Key extractor
    // ------------------------------------------------------------------

    public function testKeyExtractorNullSkipsLine(): void
    {
        // Lines with key "skip" are dropped entirely.
        $path = $this->writeFile("skip:ignored\nb:keep\na:keep\n");
        $sorter = new ExternalMergeSort(
            function (string $line): ?string {
                $key = explode(':', $line)[0];
                return $key === 'skip' ? null : $key;
            },
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("a:keep\nb:keep\n", file_get_contents($path));
    }

    public function testSortsByKeyNotByFullLine(): void
    {
        // Sort by the numeric suffix, not the full line.
        $path = $this->writeFile("file-3\nfile-1\nfile-2\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => explode('-', $line)[1],
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("file-1\nfile-2\nfile-3\n", file_get_contents($path));
    }

    public function testDeduplicatesOnKeyNotLine(): void
    {
        // Two different lines with the same key — first one wins.
        $path = $this->writeFile("key:v2\nkey:v1\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => explode(':', $line)[0],
            1024 * 1024,
            true,
            $this->tempDir,
        );
        $sorter->sort($path);
        $lines = $this->readLines($path);
        $this->assertCount(1, $lines);
        $this->assertSame('key', explode(':', $lines[0])[0]);
    }

    // ------------------------------------------------------------------
    //  Chunking behaviour (forces multiple chunks)
    // ------------------------------------------------------------------

    public function testMultipleChunksProduceCorrectSort(): void
    {
        // Create enough data to span multiple chunks.
        $lines = [];
        for ($i = 999; $i >= 0; $i--) {
            $lines[] = sprintf("line-%04d", $i);
        }
        $path = $this->writeFile(implode("\n", $lines) . "\n");

        // Budget that forces roughly 100 lines per chunk (~10 chunks).
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024, // each line is 9 bytes, so ~113 lines per chunk → ~9 chunks
            false,
            $this->tempDir,
        );
        $sorter->sort($path);

        $sorted = $this->readLines($path);
        $this->assertCount(1000, $sorted);
        $this->assertSame('line-0000', $sorted[0]);
        $this->assertSame('line-0999', $sorted[999]);

        // Verify full ordering.
        $expected = [];
        for ($i = 0; $i < 1000; $i++) {
            $expected[] = sprintf("line-%04d", $i);
        }
        $this->assertSame($expected, $sorted);
    }

    public function testMultipleChunksWithDedup(): void
    {
        // 2000 unique values, each duplicated — total 4000 lines.
        // At ~9 bytes per line and 1024-byte budget, we get ~40 chunks.
        $lines = [];
        for ($i = 1999; $i >= 0; $i--) {
            $lines[] = sprintf("item-%04d", $i);
            $lines[] = sprintf("item-%04d", $i);
        }
        $path = $this->writeFile(implode("\n", $lines) . "\n");

        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024,
            true,
            $this->tempDir,
        );
        $sorter->sort($path);

        $sorted = $this->readLines($path);
        $this->assertCount(2000, $sorted);
        $this->assertSame('item-0000', $sorted[0]);
        $this->assertSame('item-1999', $sorted[1999]);
    }

    // ------------------------------------------------------------------
    //  Edge cases
    // ------------------------------------------------------------------

    public function testEmptyFile(): void
    {
        $path = $this->writeFile("");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame('', file_get_contents($path));
    }

    public function testSingleLine(): void
    {
        $path = $this->writeFile("only-one\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("only-one\n", file_get_contents($path));
    }

    public function testBlankLinesAreSkipped(): void
    {
        $path = $this->writeFile("\n\nb\n\na\n\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $this->assertSame("a\nb\n", file_get_contents($path));
    }

    public function testWindowsLineEndings(): void
    {
        $path = $this->writeFile("cherry\r\nbanana\r\napple\r\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        // Output always uses LF.
        $this->assertSame("apple\nbanana\ncherry\n", file_get_contents($path));
    }

    public function testLineExceedingChunkBudgetStillProcessed(): void
    {
        // A single line that is larger than the chunk budget must not be
        // dropped — the code accepts it as a one-entry chunk.
        $long = str_repeat('x', 2000);
        $path = $this->writeFile("b\n{$long}\na\n");
        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024, // budget < line length
            false,
            $this->tempDir,
        );
        $sorter->sort($path);
        $lines = $this->readLines($path);
        $this->assertCount(3, $lines);
        $this->assertSame('a', $lines[0]);
        $this->assertSame('b', $lines[1]);
        $this->assertSame($long, $lines[2]);
    }

    // ------------------------------------------------------------------
    //  Output to a separate path
    // ------------------------------------------------------------------

    public function testSortToSeparateOutputPath(): void
    {
        $input = $this->writeFile("c\nb\na\n");
        $output = $this->tempDir . '/sorted-output.txt';

        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024 * 1024,
            false,
            $this->tempDir,
        );
        $sorter->sort($input, $output);

        // Input unchanged.
        $this->assertSame("c\nb\na\n", file_get_contents($input));
        // Output is sorted.
        $this->assertSame("a\nb\nc\n", file_get_contents($output));
    }

    // ------------------------------------------------------------------
    //  Cleanup: no temp files left behind
    // ------------------------------------------------------------------

    public function testNoTempFilesLeftAfterSort(): void
    {
        $lines = [];
        for ($i = 99; $i >= 0; $i--) {
            $lines[] = sprintf("line-%03d", $i);
        }
        $path = $this->writeFile(implode("\n", $lines) . "\n");

        $before = $this->listFiles($this->tempDir);

        $sorter = new ExternalMergeSort(
            fn(string $line) => $line,
            1024, // each line is ~8 bytes, so ~128 lines per chunk
            false,
            $this->tempDir,
        );
        $sorter->sort($path);

        $after = $this->listFiles($this->tempDir);
        // Only the original input file should remain.
        $this->assertSame($before, $after);
    }

    // ------------------------------------------------------------------
    //  Validation
    // ------------------------------------------------------------------

    public function testRejectsChunkBudgetBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExternalMergeSort(fn(string $line) => $line, 512);
    }

    // ------------------------------------------------------------------
    //  JSONL index format (matches the importer's actual usage)
    // ------------------------------------------------------------------

    public function testSortsJsonlIndexByPath(): void
    {
        // Simulate the JSONL format used by the importer's index files.
        $lines = [
            json_encode(['path' => base64_encode('wp-content/uploads/photo.jpg'), 'size' => 1234, 'type' => 'file']),
            json_encode(['path' => base64_encode('index.php'), 'size' => 42, 'type' => 'file']),
            json_encode(['path' => base64_encode('wp-content/plugins/akismet/akismet.php'), 'size' => 800, 'type' => 'file']),
            json_encode(['path' => base64_encode('wp-config.php'), 'size' => 300, 'type' => 'file']),
        ];
        $path = $this->writeFile(implode("\n", $lines) . "\n");

        $key_extractor = function (string $line): ?string {
            $data = json_decode($line, true);
            if (!is_array($data) || empty($data['path'])) {
                return null;
            }
            return base64_decode($data['path'], true) ?: null;
        };

        $sorter = new ExternalMergeSort(
            $key_extractor,
            1024 * 1024,
            true,
            $this->tempDir,
        );
        $sorter->sort($path);

        $sorted = $this->readLines($path);
        $paths = array_map(function (string $line) {
            return base64_decode(json_decode($line, true)['path'], true);
        }, $sorted);

        $this->assertSame([
            'index.php',
            'wp-config.php',
            'wp-content/plugins/akismet/akismet.php',
            'wp-content/uploads/photo.jpg',
        ], $paths);
    }

    public function testDeduplicatesJsonlIndexWithSamePath(): void
    {
        // Two entries for the same path — only the first (after sort) survives.
        $entry1 = json_encode(['path' => base64_encode('readme.txt'), 'size' => 100, 'type' => 'file']);
        $entry2 = json_encode(['path' => base64_encode('readme.txt'), 'size' => 200, 'type' => 'file']);
        $entry3 = json_encode(['path' => base64_encode('about.txt'), 'size' => 50, 'type' => 'file']);
        $path = $this->writeFile("{$entry1}\n{$entry3}\n{$entry2}\n");

        $key_extractor = function (string $line): ?string {
            $data = json_decode($line, true);
            if (!is_array($data) || empty($data['path'])) {
                return null;
            }
            return base64_decode($data['path'], true) ?: null;
        };

        $sorter = new ExternalMergeSort(
            $key_extractor,
            1024 * 1024,
            true,
            $this->tempDir,
        );
        $sorter->sort($path);

        $sorted = $this->readLines($path);
        $this->assertCount(2, $sorted);
        $paths = array_map(function (string $line) {
            return base64_decode(json_decode($line, true)['path'], true);
        }, $sorted);
        $this->assertSame(['about.txt', 'readme.txt'], $paths);
    }

    public function testJsonlIndexWithMultipleChunks(): void
    {
        // Build a realistic-ish index with 200 entries across many chunks.
        $lines = [];
        for ($i = 199; $i >= 0; $i--) {
            $p = sprintf("wp-content/uploads/2024/01/image-%04d.jpg", $i);
            $lines[] = json_encode(['path' => base64_encode($p), 'size' => $i * 100, 'type' => 'file']);
        }
        $path = $this->writeFile(implode("\n", $lines) . "\n");

        $key_extractor = function (string $line): ?string {
            $data = json_decode($line, true);
            if (!is_array($data) || empty($data['path'])) {
                return null;
            }
            return base64_decode($data['path'], true) ?: null;
        };

        // Force many chunks (~10 lines each).
        $sorter = new ExternalMergeSort(
            $key_extractor,
            1024, // each JSON line is ~80-90 bytes, so ~12 lines per chunk
            true,
            $this->tempDir,
        );
        $sorter->sort($path);

        $sorted = $this->readLines($path);
        $this->assertCount(200, $sorted);

        $paths = array_map(function (string $line) {
            return base64_decode(json_decode($line, true)['path'], true);
        }, $sorted);

        // Verify sorted order.
        $expected = $paths;
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $paths);

        // First and last.
        $this->assertSame('wp-content/uploads/2024/01/image-0000.jpg', $paths[0]);
        $this->assertSame('wp-content/uploads/2024/01/image-0199.jpg', $paths[199]);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function writeFile(string $content): string
    {
        $path = $this->tempDir . '/input-' . uniqid() . '.txt';
        file_put_contents($path, $content);
        return $path;
    }

    /** @return string[] Lines without trailing newlines. */
    private function readLines(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === '' || $raw === false) {
            return [];
        }
        return array_values(array_filter(
            explode("\n", $raw),
            fn(string $line) => $line !== '',
        ));
    }

    /** @return string[] Sorted list of file basenames in a directory. */
    private function listFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $files[] = $f;
        }
        sort($files);
        return $files;
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
