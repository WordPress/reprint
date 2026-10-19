<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/** Check current-batch membership using real sorted JSONL files. */
final class FetchBatchLookupTest extends TestCase
{
    private string $root;

    /** Keep all client directories private to this test. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fetch-lookup-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    /** Remove only the directories created by this test's client. */
    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    /**
     * Partial replies and distinct extra paths must not repeatedly scan the batch.
     *
     * @dataProvider lookup_sequences
     * @param string $sequence Ordered reply, partial replies, or distinct extra paths.
     */
    public function testLookupsReadBoundedBytes(string $sequence): void
    {
        $paths = [];
        for ($index = 0; $index < 256; ++$index) {
            $paths[] = sprintf('/site/file-%04d', $index);
        }
        $handle = tmpfile();
        $this->assertIsResource($handle);
        foreach ($paths as $path) {
            fwrite($handle, json_encode(['path' => base64_encode($path), 'size' => 1]) . "\n");
        }
        $batch_end = ftell($handle);
        $client = new \ImportClient('https://example.test', $this->root . '/state', $this->root . '/files');
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('fetch_batch_list_handle')->setValue($client, $handle);
        $client->get_state()->fetch->next_offset = $batch_end;
        $lookup = $reflection->getMethod('current_fetch_batch_contains_path');
        if (!in_array('reprint.fetch-read-count', stream_get_filters(), true)) {
            stream_filter_register('reprint.fetch-read-count', FetchListReadCounter::class);
        }
        // Small read buffers make the byte count independent of PHP's default read-ahead size.
        stream_set_chunk_size($handle, 128);
        stream_filter_append($handle, 'reprint.fetch-read-count', STREAM_FILTER_READ);
        rewind($handle);
        FetchListReadCounter::$bytes_read = 0;
        try {
            foreach ($paths as $index => $path) {
                if ($sequence === 'partial replies') {
                    // A new HTTP request reopens the list at the batch's start.
                    fseek($handle, 0);
                }
                $received_path = $sequence === 'extra paths' ? sprintf('/site/zz-extra-%04d', $index) : $path;
                $this->assertSame($sequence !== 'extra paths', $lookup->invoke($client, $received_path));
            }
            $maximum_read_bytes = count($paths) * 2 * 128 * (int) ( ceil(log($batch_end, 2)) + 1 );
            if ($sequence === 'ordered reply') {
                $maximum_read_bytes = $batch_end;
            }
            $this->assertLessThanOrEqual($maximum_read_bytes, FetchListReadCounter::$bytes_read,
                'Lookup reads must grow with the number of queries times the logarithm of the batch size.');
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,list<string>> Ordered replies and cases which previously repeated full scans. */
    public static function lookup_sequences(): array
    {
        return ['ordered reply' => ['ordered reply'], 'one requested path per reply' => ['partial replies'],
            'distinct extra paths' => ['extra paths']];
    }

    /**
     * Search unequal-length records, arbitrary path bytes, and a batch inside a larger list.
     *
     * @dataProvider path_formats
     * @param string $format Source path format.
     * @param string[] $paths Sorted source paths with unequal record lengths.
     * @param string[] $absent_paths Paths absent from every batch.
     */
    public function testLookupStaysInsideBatchWithUnequalRecordLengths(string $format, array $paths, array $absent_paths): void
    {
        $handle = tmpfile();
        $this->assertIsResource($handle);
        $offsets = [];
        foreach ($paths as $path) {
            $offsets[] = ftell($handle);
            fwrite($handle, json_encode(['path' => base64_encode($path), 'size' => 0]) . "\n");
        }
        $offsets[] = ftell($handle);
        $client = new \ImportClient('https://example.test', $this->root . '/state', $this->root . '/files');
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('fetch_batch_list_handle')->setValue($client, $handle);
        $lookup = $reflection->getMethod('current_fetch_batch_contains_path');
        $client->get_state()->set_preflight_record(['data' => ['path_format' => $format]]);
        try {
            // Include single-entry batches, a long first/last entry, and nonzero starts.
            $path_count = count($paths);
            for ($start = 0; $start < $path_count; ++$start) {
                for ($end = $start + 1; $end <= $path_count; ++$end) {
                    $client->get_state()->fetch->offset = $offsets[$start];
                    $client->get_state()->fetch->next_offset = $offsets[$end];
                    foreach (array_merge(array_reverse($paths), $absent_paths) as $path) {
                        $reflection->getProperty('current_requested_path')->setValue($client, null);
                        $reflection->getProperty('last_unrequested_path')->setValue($client, null);
                        fseek($handle, $offsets[$start]);
                        $this->assertSame(in_array($path, array_slice($paths, $start, $end - $start), true),
                            $lookup->invoke($client, $path), "Batch {$start}:{$end}, path " . base64_encode($path));
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array[] Source format, sorted paths, and absent paths. */
    public static function path_formats(): array
    {
        return [
            'unix bytes' => ['unix', ['/site/a', '/site/b' . str_repeat('long', 400), '/site/c', '/site/d\\literal',
                "/site/e\xff", '/site/f', '/site/g'], ['/site/0', '/site/b', '/site/cc', '/site/z']],
            'windows drive and share' => ['windows', ['C:/site/a', 'C:/site/b' . str_repeat('long', 400), 'C:/site/c',
                'C:/site/d', 'C:/site/e', 'D:/site/f', '\\\\SERVER\\SHARE/g'], ['C:/site/0', 'C:/site/b', 'C:/site/cc', 'D:/site/z']],
        ];
    }
}

/** Count actual fetch-list bytes read, without replacing the local file or network transport. */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Read counter belongs only to this test.
final class FetchListReadCounter extends \php_user_filter
{
    public static int $bytes_read = 0;

    /** Pass the real file bytes through unchanged while counting read-ahead as well. */
    public function filter($input, $output, &$consumed, $closing): int
    {
        while (true) {
            $bucket = stream_bucket_make_writeable($input);
            if (!$bucket) {
                break;
            }
            self::$bytes_read += $bucket->datalen;
            $consumed += $bucket->datalen;
            stream_bucket_append($output, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
