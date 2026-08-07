<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/**
 * The files-diff user contract.
 *
 * files-diff compares the filesystem root with the local index for the remote
 * Reprint API URL — the index a completed files-push writes —
 * without contacting the target, and reports the complete diff on every run.
 * These tests pin that contract: local-only operation, automatic terminal or
 * JSONL output, explicit progress modes, arbitrary path bytes, the local-index
 * requirement, no mutation of the local index, and a complete report after an
 * interrupted run.
 */
final class FilesDiffCommandTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $filesystemRoot;
    private string $remoteReprintApiUrl = 'https://example.test/export.php?site-export-api';
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
        $this->filesystemRoot = $this->root . '/filesystem-root';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->filesystemRoot, 0700, true);

        $invalidBytePathInIndex = "delete-invalid-\xff.txt";
        if (@file_put_contents($this->filesystemRoot . '/' . $invalidBytePathInIndex, 'invalid path bytes') !== false) {
            $this->initialFiles[$invalidBytePathInIndex] = 'invalid path bytes';
            $this->invalidBytePathInIndex = $invalidBytePathInIndex;
        }
        foreach ($this->initialFiles as $path => $contents) {
            file_put_contents($this->filesystemRoot . '/' . $path, $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testFilesDiffReportsAnEmptyDiffWhenTheFilesystemRootMatchesTheLocalIndex(): void
    {
        $this->writeLocalIndex(array_keys($this->initialFiles));

        $this->assertDirectoryDoesNotExist($this->pushStateDirectory());

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

    public function testFilesDiffSelectsStatusLinesInAutoModeOnATerminal(): void
    {
        if (!function_exists('posix_isatty') || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('This test requires POSIX pseudoterminal support.');
        }
        $this->writeLocalIndex(array_keys($this->initialFiles));
        file_put_contents($this->filesystemRoot . '/added.txt', 'new file');
        file_put_contents($this->filesystemRoot . "/bell-\x07.txt", 'control path byte');
        file_put_contents($this->filesystemRoot . "/line\nbreak.txt", 'raw path byte');
        unlink($this->filesystemRoot . '/deleted.txt');

        $result = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
        ], true);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            "\033[31mmodified: added.txt\033[0m\n"
            . "\033[31mmodified: \"bell-\\a.txt\"\033[0m\n"
            . "\033[31mmodified: \"line\\nbreak.txt\"\033[0m\n"
            . "\033[31mdeleted: deleted.txt\033[0m\n",
            str_replace("\r\n", "\n", $result['stdout'])
        );
        $this->assertSame('', $result['stderr']);

        $jsonlResult = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
            '--progress=jsonl',
        ], true);

        $this->assertSame(0, $jsonlResult['exit'], $jsonlResult['output']);
        $this->assertStringNotContainsString("\033[", $jsonlResult['stdout']);
        $this->assertStringContainsString('"action":"push"', $jsonlResult['stdout']);
        $this->assertStringContainsString('"action":"delete"', $jsonlResult['stdout']);
        $this->assertSame('', $jsonlResult['stderr']);
    }

    public function testFilesDiffSelectsJsonlInAutoModeWhenRedirected(): void
    {
        $this->writeLocalIndex(array_keys($this->initialFiles));
        file_put_contents($this->filesystemRoot . '/added.txt', 'new file');
        unlink($this->filesystemRoot . '/deleted.txt');

        $result = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringNotContainsString("\033[", $result['stdout']);
        $this->assertStringContainsString('"action":"push"', $result['stdout']);
        $this->assertStringContainsString('"action":"delete"', $result['stdout']);
        $this->assertSame('', $result['stderr']);

        $ttyResult = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
            '--progress=tty',
        ]);

        $this->assertSame(0, $ttyResult['exit'], $ttyResult['output']);
        $this->assertSame(
            "\033[31mmodified: added.txt\033[0m\n"
            . "\033[31mdeleted: deleted.txt\033[0m\n",
            $ttyResult['stdout']
        );
        $this->assertSame('', $ttyResult['stderr']);
    }

    public function testFilesDiffReportsAddedEditedDeletedAndTypeChangedPaths(): void
    {
        $this->writeLocalIndex(array_keys($this->initialFiles));

        $arbitraryBytePath = "arbitrary-\nname.txt";
        file_put_contents($this->filesystemRoot . '/added.txt', 'new file');
        file_put_contents($this->filesystemRoot . '/' . $arbitraryBytePath, 'raw path byte');
        file_put_contents($this->filesystemRoot . '/edited.txt', 'edited local contents');
        unlink($this->filesystemRoot . '/deleted.txt');
        unlink($this->filesystemRoot . '/swap');
        mkdir($this->filesystemRoot . '/swap');

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
        $this->writeLocalIndex(array_keys($this->initialFiles));

        $invalidBytePathToPush = "push-invalid-\xfe.txt";
        if (@file_put_contents($this->filesystemRoot . '/' . $invalidBytePathToPush, 'new invalid path bytes') === false) {
            $this->markTestSkipped('This filesystem does not accept a second invalid UTF-8 path.');
        }
        unlink($this->filesystemRoot . '/' . $this->invalidBytePathInIndex);

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

    public function testFilesDiffDoesNotChangeTheLocalIndexAndRepeatsTheSameReport(): void
    {
        $this->writeLocalIndex(array_keys($this->initialFiles));
        $localIndexFile = $this->localIndexFile();
        $localIndexContents = file_get_contents($localIndexFile);
        $this->assertIsString($localIndexContents);
        file_put_contents($this->filesystemRoot . '/added-after-index.txt', 'new');

        $firstResult = $this->runFilesDiff();
        $secondResult = $this->runFilesDiff();

        $this->assertSame(0, $firstResult['exit'], $firstResult['output']);
        $this->assertSame(0, $secondResult['exit'], $secondResult['output']);
        $this->assertSame($firstResult['stdout'], $secondResult['stdout']);
        $this->assertSame($localIndexContents, file_get_contents($localIndexFile));
        $records = $this->filesDiffRecords($secondResult['stdout']);
        $this->assertSame(
            $this->expectedPushRecord('added-after-index.txt', 'file'),
            $records[0] ?? null
        );
    }

    public function testFilesDiffWithoutALocalIndexFailsWithPointedGuidance(): void
    {
        $result = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
        ]);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertCanonicalSingleJsonLine($result['stderr']);
        $errorRecord = json_decode($result['stderr'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($errorRecord);
        $this->assertSame(
            'files-diff requires <remote-state-directory>/local_index.jsonl. '
            . 'files-pull writes it from completed local mutations; files-push '
            . 'writes it after the target finishes applying the push. Use the same '
            . 'remote Reprint API URL and state directory.',
            $errorRecord['error'] ?? null
        );
    }

    public function testFilesDiffPrintsHumanReadableErrorsWithProgressTty(): void
    {
        $result = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
            '--progress=tty',
        ]);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertSame(
            "Error: files-diff requires <remote-state-directory>/local_index.jsonl. "
            . "files-pull writes it from completed local mutations; files-push "
            . "writes it after the target finishes applying the push. Use the same "
            . "remote Reprint API URL and state directory.\n",
            $result['stderr']
        );
    }

    public function testFilesDiffDoesNotUseTheLocalIndexForADifferentRemoteReprintApiUrl(): void
    {
        $this->writeLocalIndex(array_keys($this->initialFiles));

        $result = $this->runFilesDiff($this->remoteReprintApiUrl . '&site=other');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertCanonicalSingleJsonLine($result['stderr']);
        $errorRecord = json_decode($result['stderr'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($errorRecord);
        $this->assertSame(
            'files-diff requires <remote-state-directory>/local_index.jsonl. '
            . 'files-pull writes it from completed local mutations; files-push '
            . 'writes it after the target finishes applying the push. Use the same '
            . 'remote Reprint API URL and state directory.',
            $errorRecord['error'] ?? null
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

    public function testFilesDiffRejectsInvalidProgressMode(): void
    {
        $result = $this->runCli([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
            '--progress=pretty',
        ]);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame('', $result['stdout']);
        $this->assertSame(
            "Invalid --progress value: pretty. Valid values: auto, tty, jsonl\n",
            $result['stderr']
        );
    }

    public function testOtherCommandsAcceptTheProgressOption(): void
    {
        $result = $this->runCli([
            'pull-metadata',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--progress=jsonl',
        ]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('"hasCompletedOnce": false', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testInterruptedFilesDiffReportsTheCompleteDiffWhenItIsRunAgain(): void
    {
        // An empty local index selects every current path for push.
        $this->removeTree($this->filesystemRoot);
        mkdir($this->filesystemRoot, 0700, true);
        $this->writeLocalIndex([]);
        $bulkPaths = $this->createBulkFiles('rerun');

        // Backpressure from the unread stdout pipe holds the first process
        // mid-report, so the kill interrupts an in-progress record stream.
        [$process, $pipes] = $this->startCliProcess([
            'files-diff',
            $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
            '--progress=jsonl',
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
     * Writes a local index describing the named paths as they exist right now,
     * the way a completed files-push writes its local index.
     *
     * @param list<string> $localRelativePaths Local relative paths to record.
     */
    private function writeLocalIndex(array $localRelativePaths): void
    {
        usort($localRelativePaths, 'strcmp');
        $lines = '';
        foreach ($localRelativePaths as $localRelativePath) {
            $stat = lstat($this->filesystemRoot . '/' . $localRelativePath);
            $this->assertIsArray($stat);
            $fileTypeBits = $stat['mode'] & 0170000;
            $type = $fileTypeBits === 0040000 ? 'dir' : ( $fileTypeBits === 0120000 ? 'link' : 'file' );
            $entry = [
                'path' => base64_encode($localRelativePath),
                'ctime' => (int) $stat['ctime'],
                'size' => $type === 'dir' ? 0 : (int) $stat['size'],
                'type' => $type,
            ];
            if ($type === 'dir') {
                $entry['empty'] = count(array_diff(
                    scandir($this->filesystemRoot . '/' . $localRelativePath) ?: [],
                    ['.', '..']
                )) === 0;
            }
            $lines .= json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        $remoteStateDirectory = $this->remoteStateDirectory();
        if (!is_dir($remoteStateDirectory)) {
            mkdir($remoteStateDirectory, 0700, true);
        }
        file_put_contents($this->localIndexFile(), $lines);
    }

    /** @return array{command:string,action:string,path_b64:string,type:string,size:int,ctime:int} */
    private function expectedPushRecord(string $path, string $type): array
    {
        $stat = lstat($this->filesystemRoot . '/' . $path);
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
            if (file_put_contents($this->filesystemRoot . '/' . $path, 'x') === false) {
                $this->fail('Failed to create a bulk files-diff test path.');
            }
            $paths[] = $path;
        }
        return $paths;
    }

    private function pushStateDirectory(): string
    {
        return $this->remoteStateDirectory() . '/push';
    }

    private function remoteStateDirectory(): string
    {
        return realpath($this->stateDirectory)
            . '/remotes/'
            . md5(rtrim($this->remoteReprintApiUrl, '?&'));
    }

    private function localIndexFile(): string
    {
        return $this->remoteStateDirectory() . '/local_index.jsonl';
    }

    /**
     * Runs files-diff with explicit JSONL output for record-level assertions.
     *
     * @param list<string> $extraArguments
     * @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runFilesDiff(?string $remoteReprintApiUrl = null, array $extraArguments = []): array
    {
        return $this->runCli(array_merge([
            'files-diff',
            $remoteReprintApiUrl ?? $this->remoteReprintApiUrl,
            '--state-dir=' . $this->stateDirectory,
            '--fs-root=' . $this->filesystemRoot,
            '--progress=jsonl',
        ], $extraArguments));
    }

    /**
     * @param list<string> $arguments CLI arguments.
     * @param bool         $stdoutIsTty Whether stdout uses a pseudoterminal.
     * @return array{exit:int,stdout:string,stderr:string,output:string}
     */
    private function runCli(array $arguments, bool $stdoutIsTty = false): array
    {
        [$process, $pipes] = $this->startCliProcess($arguments, $stdoutIsTty);
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

    /**
     * @param list<string> $arguments CLI arguments.
     * @param bool         $stdoutIsTty Whether stdout uses a pseudoterminal.
     * @return array{0:resource,1:array<int,resource>}
     */
    private function startCliProcess(array $arguments, bool $stdoutIsTty = false): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../../packages/reprint-client/bin/reprint-client'], $arguments),
            [
                ['pipe', 'r'],
                $stdoutIsTty ? ['pty'] : ['pipe', 'w'],
                ['pipe', 'w'],
            ],
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
