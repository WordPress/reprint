<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Verify that files_done and files_total progress counters are correct
 * across multiple invocations (exit-code-2 restarts), with and without
 * the essential-files filter (which splits the download list into a
 * main list and a skipped list).
 */
class DownloadListProgressTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $import_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/download-list-progress-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->import_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->import_root, 0755, true);
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

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->import_root);
    }

    /**
     * Build a download list JSONL file with N entries.
     */
    private function writeDownloadList(int $count, ?string $file = null): string
    {
        $file = $file ?? $this->stateDir . '/.import-download-list.jsonl';
        $handle = fopen($file, 'w');
        for ($i = 0; $i < $count; $i++) {
            fwrite($handle, json_encode(["path" => base64_encode("/file-{$i}.txt")]) . "\n");
        }
        fclose($handle);
        return $file;
    }

    private function writeState(array $state): void
    {
        $defaults = [
            "active_resumable_command" => [
                "command_name" => null,
                "completion_state" => null,
                "current_stage" => null,
                "remote_cursor" => null,
            ],
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
            "fetch" => ["offset" => 0, "next_offset" => 0, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
            "fetch_skipped" => ["offset" => 0, "next_offset" => 0, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function prepareClient(string $filter = "none"): array
    {
        $client = $this->makeClient();
        $reflection = new \ReflectionClass($client);

        $loadState = $reflection->getMethod('load_state');
        $stateProperty = $reflection->getProperty('state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $ttyProperty = $reflection->getProperty('is_tty');
        $ttyProperty->setValue($client, false);

        $filterProp = $reflection->getProperty('filter');
        $filterProp->setValue($client, $filter);

        return [$client, $reflection];
    }

    private function readCounters(\ImportClient $client, \ReflectionClass $reflection): array
    {
        return [
            'total' => $reflection->getProperty('download_list_total')->getValue($client),
            'done' => $reflection->getProperty('download_list_done')->getValue($client),
        ];
    }

    private function byteOffsetAfterLines(string $file, int $n): int
    {
        $handle = fopen($file, 'r');
        for ($i = 0; $i < $n; $i++) {
            fgets($handle);
        }
        $offset = ftell($handle);
        fclose($handle);
        return $offset;
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    public function testFreshDownloadShowsZeroDone()
    {
        $listFile = $this->writeDownloadList(100);

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('download_files_from_list');
        try {
            $method->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {
            // Expected: network error
        }

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(0, $counters['done']);
    }

    public function testResumedDownloadReflectsOffset()
    {
        $listFile = $this->writeDownloadList(100);
        $offset = $this->byteOffsetAfterLines($listFile, 40);

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => [
                "offset" => $offset,
                "next_offset" => $offset,
                "batch_file" => null,
                "batch_entries" => 0,
                "cursor" => null,
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(40, $counters['done']);
    }

    public function testDoneNeverExceedsTotal()
    {
        $listFile = $this->writeDownloadList(50);

        // Offset past the end of the file
        $pastEnd = filesize($listFile) + 1000;

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => [
                "offset" => $pastEnd,
                "next_offset" => $pastEnd,
                "batch_file" => null,
                "batch_entries" => 0,
                "cursor" => null,
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(50, $counters['total']);
        $this->assertLessThanOrEqual($counters['total'], $counters['done']);
    }

    public function testCountersStableAcrossInvocations()
    {
        $listFile = $this->writeDownloadList(100);
        $offset30 = $this->byteOffsetAfterLines($listFile, 30);
        $offset60 = $this->byteOffsetAfterLines($listFile, 60);

        // First invocation at offset 30
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => ["offset" => $offset30, "next_offset" => $offset30, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client1, $ref1] = $this->prepareClient();
        try {
            $ref1->getMethod('download_files_from_list')->invoke($client1, $listFile, 'fetch');
        } catch (\Exception $e) {}

        $c1 = $this->readCounters($client1, $ref1);
        $this->assertSame(100, $c1['total']);
        $this->assertSame(30, $c1['done']);

        // Second invocation at offset 60
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => ["offset" => $offset60, "next_offset" => $offset60, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client2, $ref2] = $this->prepareClient();
        try {
            $ref2->getMethod('download_files_from_list')->invoke($client2, $listFile, 'fetch');
        } catch (\Exception $e) {}

        $c2 = $this->readCounters($client2, $ref2);
        $this->assertSame(100, $c2['total']);
        $this->assertSame(60, $c2['done']);

        // done only goes up
        $this->assertGreaterThan($c1['done'], $c2['done']);
    }

    public function testSkippedListHasOwnCounters()
    {
        $this->writeDownloadList(50);
        $skippedList = $this->stateDir . '/.import-download-list-skipped.jsonl';
        $this->writeDownloadList(200, $skippedList);
        $offset20 = $this->byteOffsetAfterLines($skippedList, 20);

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch-skipped",
            ],
            "fetch_skipped" => ["offset" => $offset20, "next_offset" => $offset20, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client, $reflection] = $this->prepareClient("skipped-earlier");

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $skippedList, 'fetch_skipped');
        } catch (\Exception $e) {}

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(200, $counters['total']);
        $this->assertSame(20, $counters['done']);
    }

    public function testCountNewlinesMatchesLineCount()
    {
        $listFile = $this->writeDownloadList(500);

        [$client, $reflection] = $this->prepareClient();
        $method = $reflection->getMethod('count_newlines');

        $this->assertSame(500, $method->invoke($client, $listFile));

        $offset100 = $this->byteOffsetAfterLines($listFile, 100);
        $this->assertSame(100, $method->invoke($client, $listFile, $offset100));
    }

    public function testPrepareFetchBatchReturnsEntryCount()
    {
        $listFile = $this->writeDownloadList(10);

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();
        $batch = $reflection->getMethod('prepare_fetch_batch')
            ->invoke($client, $listFile, 0);

        $this->assertNotNull($batch);
        $this->assertSame(10, $batch['entries']);
        $this->assertSame(0, $batch['offset']);
        $this->assertGreaterThan(0, $batch['next_offset']);

        if (file_exists($batch['file'])) {
            @unlink($batch['file']);
        }
    }

    public function testFilesDoneIncludesFilesImported()
    {
        $listFile = $this->writeDownloadList(100);
        $offset40 = $this->byteOffsetAfterLines($listFile, 40);

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
            "fetch" => ["offset" => $offset40, "next_offset" => $offset40, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client, $reflection] = $this->prepareClient();

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {}

        // Simulate 5 files written in this invocation
        $reflection->getProperty('files_imported')->setValue($client, 5);

        $done = $reflection->getProperty('download_list_done')->getValue($client);
        $imported = $reflection->getProperty('files_imported')->getValue($client);
        $total = $reflection->getProperty('download_list_total')->getValue($client);

        // files_done as emitted in progress records = done + imported
        $filesDone = $done + $imported;
        $this->assertSame(45, $filesDone); // 40 from offset + 5 imported
        $this->assertSame(100, $total);
        $this->assertLessThanOrEqual($total, $filesDone);
    }
}
