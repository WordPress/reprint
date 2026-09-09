<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

/**
 * Verify that files_done and files_total progress counters are correct
 * across multiple invocations (exit-code-2 restarts).
 */
class FetchListProgressTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $pullStateDirectory;
    private $filesystem_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fetch-list-progress-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->pullStateDirectory =
            $this->stateDir . '/remotes/' . md5('http://fake.url') . '/pull';
        $this->filesystem_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->pullStateDirectory, 0755, true);
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
    private function writeFetchList(
        int $count,
        ?string $fetchListFilePath = null,
        bool $includeSizes = false
    ): string
    {
        $fetchListFilePath =
            $fetchListFilePath
            ?? $this->pullStateDirectory . '/fetch-list.jsonl';
        $fetchListFileHandle = fopen($fetchListFilePath, 'w');
        for ($i = 0; $i < $count; $i++) {
            fwrite(
                $fetchListFileHandle,
                json_encode(
                    array_merge(
                        ["path" => base64_encode("/file-{$i}.txt")],
                        $includeSizes ? ["size" => ( $i + 1 ) * 100] : []
                    )
                ) . "\n"
            );
        }
        fclose($fetchListFileHandle);
        return $fetchListFilePath;
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
            'total' => $reflection->getProperty('files_pull_progress')->getValue($client)->get_details()['items']['total'],
            'done' => $reflection->getProperty('files_pull_progress')->getValue($client)->get_details()['items']['done'],
        ];
    }

    private function byteOffsetAfterLines(
        string $fetchListFilePath,
        int $lineCount
    ): int
    {
        $fetchListFileHandle = fopen($fetchListFilePath, 'r');
        for ($lineNumber = 0; $lineNumber < $lineCount; $lineNumber++) {
            fgets($fetchListFileHandle);
        }
        $fetchListByteOffset = ftell($fetchListFileHandle);
        fclose($fetchListFileHandle);
        return $fetchListByteOffset;
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
            $method->invoke($client, $listFile);
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
                ->invoke($client, $listFile);
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(40, $counters['done']);
    }

    public function testResumedDownloadReportsSelectedFileByteTotals()
    {
        $listFile = $this->writeFetchList(4, null, true);
        $offset = $this->byteOffsetAfterLines($listFile, 2);

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
            $reflection->getMethod('fetch_files_from_list')->invoke($client, $listFile);
        } catch (\Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(
            1000,
            $reflection->getProperty('files_pull_progress')->getValue($client)->get_details()['bytes']['total'] ?? null
        );
        $this->assertSame(
            300,
            $reflection->getProperty('files_pull_progress')->getValue($client)->get_details()['bytes']['done'] ?? null
        );
    }

    public function testOldFetchListKeepsByteTotalsUnknown()
    {
        $listFile = $this->writeFetchList(4);
        $this->writeState([
            "active_resumable_command" => [
                "command_name" => "files-pull",
                "completion_state" => "in_progress",
                "current_stage" => "fetch",
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();
        try {
            $reflection->getMethod('fetch_files_from_list')->invoke($client, $listFile);
        } catch (\Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertNull(
            $reflection->getProperty('files_pull_progress')->getValue($client)->get_details()['bytes']['total'] ?? null
        );
        $this->assertNull(
            $reflection->getProperty('files_pull_progress')->getValue($client)->get_details()['bytes']['done'] ?? null
        );
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
                ->invoke($client, $listFile);
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
            $ref1->getMethod('fetch_files_from_list')->invoke($client1, $listFile);
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
            $ref2->getMethod('fetch_files_from_list')->invoke($client2, $listFile);
        } catch (\Exception $e) {}

        $c2 = $this->readCounters($client2, $ref2);
        $this->assertSame(100, $c2['total']);
        $this->assertSame(60, $c2['done']);

        // done only goes up
        $this->assertGreaterThan($c1['done'], $c2['done']);
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
        $this->assertSame(
            array_map(
                static function (int $index): array {
                    return [
                        'path' => base64_encode("/file-{$index}.txt"),
                    ];
                },
                range(0, 9)
            ),
            json_decode(
                file_get_contents($batch['file']),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        if (file_exists($batch['file'])) {
            @unlink($batch['file']);
        }
    }

    public function testPrepareFetchBatchPreservesArbitraryPathBytes()
    {
        $listFile = $this->pullStateDirectory . '/fetch-list.jsonl';
        $encodedPath = base64_encode("/binary-\xff.txt");
        file_put_contents(
            $listFile,
            json_encode(["path" => $encodedPath], JSON_THROW_ON_ERROR) . "\n"
        );
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
        $this->assertSame(
            [["path" => $encodedPath]],
            json_decode(
                file_get_contents($batch['file']),
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        if (file_exists($batch['file'])) {
            @unlink($batch['file']);
        }
    }

    public function testRestartingBatchKeepsEarlierCounts(): void
    {
        $list_file = $this->writeFetchList(4, null, true);
        $fetch = new \Reprint\Importer\State\FetchListProgressState();
        $fetch->offset = $this->byteOffsetAfterLines($list_file, 1);
        $fetch->next_offset = $this->byteOffsetAfterLines($list_file, 3);
        $fetch->cursor = base64_encode(json_encode([
            'path' => base64_encode('/file-1.txt'), 'bytes' => 0,
        ]));
        $progress = new \Reprint\Importer\FilesPullProgress();
        $progress->load_list($list_file, $fetch);
        $this->assertSame(300, $progress->get_details()['bytes']['done']);
        $this->assertSame(2, $progress->get_details()['items']['done']);
        $progress->complete_file(300);
        $progress->restart_batch();
        $this->assertSame(100, $progress->get_details()['bytes']['done']);
        $this->assertSame(1, $progress->get_details()['items']['done']);
        $progress->complete_file(200);
        $progress->complete_file(300);
        $progress->complete_batch(2);
        $this->assertSame(600, $progress->get_details()['bytes']['done']);
        $this->assertSame(3, $progress->get_details()['items']['done']);
        $this->assertSame(0, $progress->get_batch_files_done());
        $this->assertSame(1000, $progress->get_details()['bytes']['total']);
    }

    public function testResumedBatchUsesRemotePathOrder(): void
    {
        $list_file = $this->pullStateDirectory . '/fetch-list.jsonl';
        foreach (['/z' => 100, '/a' => 200, '/m' => 300] as $path => $size) {
            file_put_contents($list_file, json_encode([
                'path' => base64_encode($path), 'size' => $size,
            ]) . "\n", FILE_APPEND);
        }
        $fetch = new \Reprint\Importer\State\FetchListProgressState();
        $fetch->next_offset = filesize($list_file);
        $fetch->cursor = base64_encode(json_encode([
            'path' => base64_encode('/m'), 'bytes' => 10,
        ]));
        $progress = new \Reprint\Importer\FilesPullProgress();
        $progress->load_list($list_file, $fetch);
        $context = new \Reprint\Importer\StreamingContext();
        $context->remote_file_path = '/m';
        $context->remote_file_size = 300;
        $context->file_bytes_written = 10;
        $this->assertSame(210, $progress->get_details($context)['bytes']['done']);
        $this->assertSame(1, $progress->get_details($context)['items']['done']);
        $this->assertSame(600, $progress->get_details($context)['bytes']['total']);
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
                ->invoke($client, $listFile);
        } catch (\Exception $e) {}

        // Five completed files add to the paths before the saved batch offset.
        $progress = $reflection->getProperty('files_pull_progress')->getValue($client);
        for ($file = 0; $file < 5; ++$file) {
            $progress->complete_file(0);
        }
        $items = $progress->get_details()['items'];
        $this->assertSame(45, $items['done']); // 40 from offset + 5 pulled.
        $this->assertSame(100, $items['total']);
        $this->assertLessThanOrEqual($items['total'], $items['done']);
    }
}
