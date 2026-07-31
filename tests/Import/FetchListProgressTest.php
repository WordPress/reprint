<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Verify that files_done and files_total progress counters are correct
 * across multiple invocations (exit-code-2 restarts), with and without
 * the essential-files filter (which splits the fetch list into a
 * main list and a skipped list).
 */
class FetchListProgressTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fetch-list-progress-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/pull', 0755, true);
        mkdir($this->filesystem_root, 0755, true);
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
        return new \ImportClient('http://fake.url', $this->stateDir, $this->filesystem_root);
    }

    /**
     * Build a fetch list JSONL file with N entries.
     */
    private function writeFetchList(int $count, ?string $file = null): string
    {
        $file = $file ?? $this->stateDir . '/pull/fetch-list.jsonl';
        $handle = fopen($file, 'w');
        for ($i = 0; $i < $count; $i++) {
            fwrite($handle, json_encode(["path" => base64_encode("/file-{$i}.txt")]) . "\n");
        }
        fclose($handle);
        return $file;
    }

    private function writeState(array $state): void
    {
        \write_current_pull_state($this->makeClient(), array_replace_recursive([
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
        ], $state));
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
            'total' => $reflection->getProperty('fetch_list_total')->getValue($client),
            'done' => $reflection->getProperty('fetch_list_done')->getValue($client),
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
        $listFile = $this->writeFetchList(100);

        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('fetch_files_from_list');
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
        $listFile = $this->writeFetchList(100);
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
            $reflection->getMethod('fetch_files_from_list')
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
        $listFile = $this->writeFetchList(50);

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
            $reflection->getMethod('fetch_files_from_list')
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
        $listFile = $this->writeFetchList(100);
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
            $ref1->getMethod('fetch_files_from_list')->invoke($client1, $listFile, 'fetch');
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
            $ref2->getMethod('fetch_files_from_list')->invoke($client2, $listFile, 'fetch');
        } catch (\Exception $e) {}

        $c2 = $this->readCounters($client2, $ref2);
        $this->assertSame(100, $c2['total']);
        $this->assertSame(60, $c2['done']);

        // done only goes up
        $this->assertGreaterThan($c1['done'], $c2['done']);
    }

    public function testSkippedListHasOwnCounters()
    {
        $this->writeFetchList(50);
        $skippedList = $this->stateDir . '/pull/skipped-fetch-list.jsonl';
        $this->writeFetchList(200, $skippedList);
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
            $reflection->getMethod('fetch_files_from_list')
                ->invoke($client, $skippedList, 'fetch_skipped');
        } catch (\Exception $e) {}

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(200, $counters['total']);
        $this->assertSame(20, $counters['done']);
    }

    public function testCountNewlinesMatchesLineCount()
    {
        $listFile = $this->writeFetchList(500);

        [$client, $reflection] = $this->prepareClient();
        $method = $reflection->getMethod('count_newlines');

        $this->assertSame(500, $method->invoke($client, $listFile));

        $offset100 = $this->byteOffsetAfterLines($listFile, 100);
        $this->assertSame(100, $method->invoke($client, $listFile, $offset100));
    }

    public function testPrepareFetchBatchReturnsEntryCount()
    {
        $listFile = $this->writeFetchList(10);

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

    public function testFilesDoneIncludesFilesPulled()
    {
        $listFile = $this->writeFetchList(100);
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
            $reflection->getMethod('fetch_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {}

        // Simulate 5 files written in this invocation
        $reflection->getProperty('files_pulled')->setValue($client, 5);

        $done = $reflection->getProperty('fetch_list_done')->getValue($client);
        $pulled = $reflection->getProperty('files_pulled')->getValue($client);
        $total = $reflection->getProperty('fetch_list_total')->getValue($client);

        // files_done as emitted in progress records = done + pulled
        $filesDone = $done + $pulled;
        $this->assertSame(45, $filesDone); // 40 from offset + 5 pulled
        $this->assertSame(100, $total);
        $this->assertLessThanOrEqual($total, $filesDone);
    }
}
