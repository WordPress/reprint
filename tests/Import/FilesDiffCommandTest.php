<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * The files-diff user contract.
 *
 * files-diff compares the filesystem root with the previous local index for
 * the remote Reprint API URL — the index a completed files-push publishes —
 * without contacting the target, and reports the complete diff on every run.
 * These tests pin that contract: local-only operation, correct change records,
 * arbitrary path bytes, the previous-local-index requirement, no mutation of
 * that index, and a complete report after an interrupted run.
 */
final class FilesDiffCommandTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $localTree;
    private string $targetUrl = 'https://example.test/export.php?site-export-api';
    private ?string $invalidBytePathInIndex = null;

    /** @var array<string,string> */
    private array $initialFiles = [
        'deleted.txt' => 'delete me',
        'edited.txt' => 'old',
        'swap' => 'file before the type change',
        'unchanged.txt' => 'same',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/files-diff-command-' . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->localTree = $this->root . '/local-tree';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->localTree, 0700, true);

        $invalidBytePathInIndex = "delete-invalid-\xff.txt";
        if (@file_put_contents($this->localTree . '/' . $invalidBytePathInIndex, 'invalid path bytes') !== false) {
            $this->initialFiles[$invalidBytePathInIndex] = 'invalid path bytes';
            $this->invalidBytePathInIndex = $invalidBytePathInIndex;
        }
        foreach ($this->initialFiles as $path => $contents) {
            file_put_contents($this->localTree . '/' . $path, $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testFilesDiffReportsAnEmptyDiffWhenTheLocalTreeMatchesTheIndex(): void
    {
        $this->writePreviousLocalIndex(array_keys($this->initialFiles));

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $expectedRecord = [
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 0,
            'local_paths_to_delete' => 0,
        ];
        $this->assertSame($this->encodeJsonLine($expectedRecord), $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testFilesDiffReportsAddedEditedDeletedAndTypeChangedPaths(): void
    {
        $this->writePreviousLocalIndex(array_keys($this->initialFiles));

        $arbitraryBytePath = "arbitrary-\nname.txt";
        file_put_contents($this->localTree . '/added.txt', 'new file');
        file_put_contents($this->localTree . '/' . $arbitraryBytePath, 'raw path byte');
        file_put_contents($this->localTree . '/edited.txt', 'edited local contents');
        unlink($this->localTree . '/deleted.txt');
        unlink($this->localTree . '/swap');
        mkdir($this->localTree . '/swap');

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $records = $this->filesDiffRecords($result['stdout']);
        $finalRecord = array_pop($records);
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 4,
            'local_paths_to_delete' => 2,
        ], $finalRecord);

        $recordsByActionAndPath = [];
        foreach ($records as $record) {
            $path = base64_decode($record['path_b64'] ?? '', true);
            $this->assertIsString($path);
            $recordsByActionAndPath[( $record['action'] ?? '' ) . ':' . $path] = $record;
        }
        $this->assertCount(6, $recordsByActionAndPath);
        $recordKeys = array_keys($recordsByActionAndPath);
        sort($recordKeys, SORT_STRING);
        $expectedRecordKeys = [
            'delete:deleted.txt',
            'delete:swap',
            'push:added.txt',
            'push:' . $arbitraryBytePath,
            'push:edited.txt',
            'push:swap',
        ];
        sort($expectedRecordKeys, SORT_STRING);
        $this->assertSame(
            $expectedRecordKeys,
            $recordKeys
        );

        $this->assertSame(
            $this->expectedPushRecord('added.txt', 'file'),
            $recordsByActionAndPath['push:added.txt']
        );
        $this->assertSame(
            $this->expectedPushRecord($arbitraryBytePath, 'file'),
            $recordsByActionAndPath['push:' . $arbitraryBytePath]
        );
        $this->assertSame(
            $this->expectedPushRecord('edited.txt', 'file'),
            $recordsByActionAndPath['push:edited.txt']
        );
        $this->assertSame(
            $this->expectedPushRecord('swap', 'dir'),
            $recordsByActionAndPath['push:swap']
        );
        $this->assertSame([
            'command' => 'files-diff',
            'action' => 'delete',
            'path_b64' => base64_encode('deleted.txt'),
        ], $recordsByActionAndPath['delete:deleted.txt']);
        $this->assertSame([
            'command' => 'files-diff',
            'action' => 'delete',
            'path_b64' => base64_encode('swap'),
        ], $recordsByActionAndPath['delete:swap']);
        $this->assertSame('', $result['stderr']);
        $this->assertSame(
            implode('', array_map(
                fn(array $record): string => $this->encodeJsonLine($record),
                array_merge($records, [$finalRecord])
            )),
            $result['stdout']
        );
    }

    public function testFilesDiffPreservesInvalidUtf8PathBytesInPushAndDeleteRecords(): void
    {
        if ($this->invalidBytePathInIndex === null) {
            $this->markTestSkipped('This filesystem does not accept invalid UTF-8 path bytes.');
        }
        $this->writePreviousLocalIndex(array_keys($this->initialFiles));

        $invalidBytePathToPush = "push-invalid-\xfe.txt";
        if (@file_put_contents($this->localTree . '/' . $invalidBytePathToPush, 'new invalid path bytes') === false) {
            $this->markTestSkipped('This filesystem does not accept a second invalid UTF-8 path.');
        }
        unlink($this->localTree . '/' . $this->invalidBytePathInIndex);

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('', $result['stderr']);
        $records = $this->filesDiffRecords($result['stdout']);
        $finalRecord = array_pop($records);
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => 1,
            'local_paths_to_delete' => 1,
        ], $finalRecord);
        $this->assertSame([
            $this->expectedPushRecord($invalidBytePathToPush, 'file'),
            [
                'command' => 'files-diff',
                'action' => 'delete',
                'path_b64' => base64_encode($this->invalidBytePathInIndex),
            ],
        ], $records);
        $this->assertStringNotContainsString($invalidBytePathToPush, $result['stdout']);
        $this->assertStringNotContainsString($this->invalidBytePathInIndex, $result['stdout']);
    }

    public function testFilesDiffDoesNotChangeThePreviousLocalIndexAndRepeatsTheSameReport(): void
    {
        $this->writePreviousLocalIndex(array_keys($this->initialFiles));
        $previousLocalIndex = $this->pushStateDirectory() . '/previous_local_index.jsonl';
        $previousLocalIndexContents = file_get_contents($previousLocalIndex);
        $this->assertIsString($previousLocalIndexContents);
        file_put_contents($this->localTree . '/added-after-index.txt', 'new');

        $firstResult = $this->runFilesDiff();
        $secondResult = $this->runFilesDiff();

        $this->assertSame(0, $firstResult['exit'], $firstResult['output']);
        $this->assertSame(0, $secondResult['exit'], $secondResult['output']);
        $this->assertSame($firstResult['stdout'], $secondResult['stdout']);
        $this->assertSame($previousLocalIndexContents, file_get_contents($previousLocalIndex));
        $records = $this->filesDiffRecords($secondResult['stdout']);
        $this->assertSame(
            $this->expectedPushRecord('added-after-index.txt', 'file'),
            $records[0] ?? null
        );
    }

    public function testFilesDiffWithoutAPreviousLocalIndexFailsWithPointedGuidance(): void
    {
        $result = $this->runFilesDiff();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertCanonicalSingleJsonLine($result['stderr']);
        $this->assertStringContainsString('completed files-push', $result['output']);
        $this->assertStringContainsString(
            'same remote Reprint API URL and state directory',
            $result['output']
        );
    }

    public function testFilesDiffDoesNotReuseThePreviousLocalIndexForADifferentTargetUrlQuery(): void
    {
        $this->writePreviousLocalIndex(array_keys($this->initialFiles));

        $result = $this->runFilesDiff($this->targetUrl . '&site=other');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertCanonicalSingleJsonLine($result['stderr']);
        $this->assertStringContainsString('completed files-push', $result['output']);
        $this->assertStringContainsString(
            'same remote Reprint API URL and state directory',
            $result['output']
        );
    }

    public function testFilesDiffRejectsRuntimeOptionsBeforeStateChanges(): void
    {
        $result = $this->runFilesDiff(null, ['--runtime=php-builtin']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertSame("Error: files-diff does not accept --runtime.\n", $result['stderr']);
        $this->assertDirectoryDoesNotExist($this->pushStateDirectory());
    }

    public function testInterruptedFilesDiffReportsTheCompleteDiffWhenItIsRunAgain(): void
    {
        // An empty previous local index selects every current path for push.
        $this->removeTree($this->localTree);
        mkdir($this->localTree, 0700, true);
        $this->writePreviousLocalIndex([]);
        $bulkPaths = $this->createBulkFiles('rerun');

        // Backpressure from the unread stdout pipe holds the first process
        // mid-report, so the kill interrupts an in-progress record stream.
        [$process, $pipes] = $this->startCliProcess([
            'files-diff',
            $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
        ]);
        stream_set_blocking($pipes[1], false);
        $firstOutput = '';
        for ($attempt = 0; $attempt < 30000; ++$attempt) {
            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk) && $chunk !== '') {
                $firstOutput .= $chunk;
            }
            if (strpos($firstOutput, "\n") !== false) {
                break;
            }
            $processStatus = proc_get_status($process);
            if (!( $processStatus['running'] ?? false )) {
                break;
            }
            usleep(1000);
        }
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->assertStringContainsString(
            "\n",
            $firstOutput,
            'files-diff exited before the test could interrupt its report.'
        );
        $this->assertStringNotContainsString('"status":"complete"', $firstOutput);

        $result = $this->runFilesDiff();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame('', $result['stderr']);
        $records = $this->filesDiffRecords($result['stdout']);
        $finalRecord = array_pop($records);
        $this->assertSame(
            array_map(fn(string $path): array => $this->expectedPushRecord($path, 'file'), $bulkPaths),
            $records
        );
        $this->assertSame([
            'command' => 'files-diff',
            'status' => 'complete',
            'local_paths_to_push' => count($bulkPaths),
            'local_paths_to_delete' => 0,
        ], $finalRecord);
        $this->assertDirectoryDoesNotExist($this->pushStateDirectory() . '/files-diff-plan');
    }

    /**
     * Publishes a previous local index describing the named paths as they
     * exist right now, the way a completed files-push saves its index.
     *
     * @param list<string> $paths Document-root-relative paths to record.
     */
    private function writePreviousLocalIndex(array $paths): void
    {
        usort($paths, 'strcmp');
        $lines = '';
        foreach ($paths as $path) {
            $stat = lstat($this->localTree . '/' . $path);
            $this->assertIsArray($stat);
            $fileTypeBits = $stat['mode'] & 0170000;
            $type = $fileTypeBits === 0040000 ? 'dir' : ( $fileTypeBits === 0120000 ? 'link' : 'file' );
            $entry = [
                'path' => base64_encode($path),
                'ctime' => (int) $stat['ctime'],
                'size' => $type === 'dir' ? 0 : (int) $stat['size'],
                'type' => $type,
            ];
            if ($type === 'dir') {
                $entry['empty'] = count(array_diff(
                    scandir($this->localTree . '/' . $path) ?: [],
                    ['.', '..']
                )) === 0;
            }
            $lines .= json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        $pushStateDirectory = $this->pushStateDirectory();
        if (!is_dir($pushStateDirectory)) {
            mkdir($pushStateDirectory, 0700, true);
        }
        file_put_contents($pushStateDirectory . '/previous_local_index.jsonl', $lines);
    }

    /** @return array{command:string,action:string,path_b64:string,type:string,size:int,ctime:int} */
    private function expectedPushRecord(string $path, string $type): array
    {
        $stat = lstat($this->localTree . '/' . $path);
        $this->assertIsArray($stat);
        return [
            'command' => 'files-diff',
            'action' => 'push',
            'path_b64' => base64_encode($path),
            'type' => $type,
            'size' => $type === 'dir' ? 0 : (int) $stat['size'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    /** @return list<string> Created document-root-relative paths. */
    private function createBulkFiles(string $prefix, int $count = 1000): array
    {
        $paths = [];
        for ($index = 0; $index < $count; ++$index) {
            $path = sprintf(
                '%s-%04d-%s.txt',
                $prefix,
                $index,
                str_repeat('x', 180)
            );
            if (file_put_contents($this->localTree . '/' . $path, 'x') === false) {
                $this->fail('Failed to create a bulk files-diff test path.');
            }
            $paths[] = $path;
        }
        return $paths;
    }

    private function pushStateDirectory(): string
    {
        return realpath($this->stateDirectory)
            . '/push/'
            . md5(rtrim($this->targetUrl, '?&'));
    }

    /** @param list<string> $extraArguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runFilesDiff(?string $targetUrl = null, array $extraArguments = []): array
    {
        return $this->runCli(array_merge([
            'files-diff',
            $targetUrl ?? $this->targetUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->localTree,
        ], $extraArguments));
    }

    /** @param list<string> $arguments
     *  @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runCli(array $arguments): array
    {
        [$process, $pipes] = $this->startCliProcess($arguments);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $stdout . $stderr,
        ];
    }

    /** @param list<string> $arguments
     *  @return array{0:resource,1:array<int,resource>}
     */
    private function startCliProcess(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../importer/import.php'], $arguments),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->root
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @param array<string,mixed> $record */
    private function encodeJsonLine(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function assertCanonicalSingleJsonLine(string $output): void
    {
        $record = json_decode(rtrim($output, "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($record);
        $this->assertSame(json_encode($record, JSON_THROW_ON_ERROR) . "\n", $output);
    }

    /** @return list<array<string,mixed>> */
    private function filesDiffRecords(string $output): array
    {
        $records = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ( $decoded['command'] ?? null ) === 'files-diff') {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
