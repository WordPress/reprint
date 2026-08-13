<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only client and interruption exceptions live with their test.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class StopAfterFetchStageSave extends \RuntimeException
{
}

final class StopAfterFetchListWrite extends \RuntimeException
{
}

final class InterruptibleFilesPullClient extends \ImportClient
{
    public ?string $stop_after_fetch_path = null;
    public ?string $stop_immediately_after_fetch_path = null;
    public bool $stop_after_fetch_stage_save = false;

    public function audit_log(string $message, bool $to_console = true): void
    {
        parent::audit_log($message, $to_console);
        if (
            $this->stop_immediately_after_fetch_path !== null
            && $message === 'Added to the fetch list: '
                . $this->stop_immediately_after_fetch_path
        ) {
            $this->stop_immediately_after_fetch_path = null;
            throw new StopAfterFetchListWrite();
        }
        if (
            $this->stop_after_fetch_path !== null
            && $message === 'Added to the fetch list: '
                . $this->stop_after_fetch_path
        ) {
            $this->stop_after_fetch_path = null;
            ( new \ReflectionProperty(
                \ImportClient::class,
                'shutdown_requested'
            ) )->setValue($this, true);
        }
    }

    public function save_state(): void
    {
        parent::save_state();
        if (
            $this->stop_after_fetch_stage_save
            && $this->get_state()->active_resumable_command->current_stage
                === 'fetch'
        ) {
            $this->stop_after_fetch_stage_save = false;
            throw new StopAfterFetchStageSave();
        }
    }
}

final class FilesPullDiffResumeTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $pullStateDirectory;
    private string $filesystemRoot;
    private string $remoteReprintApiUrl =
        'https://example.com/?site-export-api';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/files-pull-diff-resume-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->pullStateDirectory =
            $this->stateDirectory
            . '/remotes/'
            . md5(rtrim($this->remoteReprintApiUrl, '?&'))
            . '/pull';
        $this->filesystemRoot = $this->root . '/files';
        mkdir($this->pullStateDirectory, 0700, true);
        mkdir($this->filesystemRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPartialDiffCanResumeOnTheSameClient(): void
    {
        $this->seedResumeScenario();

        $client = $this->newClient();
        $this->writeDiffState($client);
        $client->stop_after_fetch_path = '/b-change.txt';

        $client->run_files_pull();
        $this->assertSame(
            'partial',
            $client->get_state()->active_resumable_command->completion_state
        );

        $client->get_state()->active_resumable_command->completion_state =
            'in_progress';
        ( new \ReflectionProperty(
            \ImportClient::class,
            'shutdown_requested'
        ) )->setValue($client, false);
        $client->stop_after_fetch_stage_save = true;
        try {
            $client->run_files_pull();
            $this->fail('Expected the test client to stop after saving the fetch stage.');
        } catch (StopAfterFetchStageSave $exception) {
            $this->assertSame(
                'fetch',
                $client->get_state()->active_resumable_command->current_stage
            );
        }

        $this->assertSame(
            ['/b-change.txt', '/d-add.txt'],
            $this->readFetchPaths()
        );
        $this->assertSame(
            ['/a-delete.txt', '/c-delete.txt'],
            $this->readWalRemotePaths()
        );
    }

    public function testResumeTruncatesOutputsAfterANonzeroCheckpoint(): void
    {
        $remoteIndex = '';
        $nextRemoteIndex = '';
        $expectedFetchPaths = [];
        $expectedDeletedPaths = [];
        for ($pathNumber = 1; $pathNumber <= 206; ++$pathNumber) {
            $path = sprintf('/path-%03d.txt', $pathNumber);
            $remoteIndex .= $this->indexLine($path, 1, 1);
            if ($pathNumber % 2 === 0) {
                $nextRemoteIndex .= $this->indexLine($path, 2, 2);
                $expectedFetchPaths[] = $path;
            } else {
                $expectedDeletedPaths[] = $path;
            }
        }
        $this->writeIndex('remote-index.jsonl', $remoteIndex);
        $this->writeIndex('remote-index.next.jsonl', $nextRemoteIndex);

        $client = $this->newClient();
        $this->writeDiffState($client);
        $client->stop_immediately_after_fetch_path = '/path-204.txt';

        try {
            $this->runDiff($client);
            $this->fail(
                'Expected the test client to stop after the saved checkpoint.'
            );
        } catch (StopAfterFetchListWrite $exception) {
            $this->assertCount(102, $this->readFetchPaths());
            $this->assertCount(102, $this->readWalRemotePaths());
        }
        $this->assertSame(
            $remoteIndex,
            $this->readIndexFile('remote-index.jsonl')
        );

        $savedFetchListByteOffset =
            $client->get_state()->diff->fetch_list_byte_offset;
        $savedWalByteOffset =
            $client->get_state()->diff->pull_index_wal_byte_offset;
        $this->assertGreaterThan(0, $savedFetchListByteOffset);
        $this->assertGreaterThan(0, $savedWalByteOffset);
        $this->assertGreaterThan(
            $savedFetchListByteOffset,
            filesize($this->pullStateDirectory . '/fetch-list.jsonl')
        );
        $this->assertGreaterThan(
            $savedWalByteOffset,
            filesize($this->pullStateDirectory . '/index.wal')
        );

        $resumedClient = $this->loadClient($this->newClient());
        $this->assertTrue($this->runDiff($resumedClient));

        $this->assertSame($expectedFetchPaths, $this->readFetchPaths());
        $this->assertSame($expectedDeletedPaths, $this->readWalRemotePaths());

        $pullIndexJournal = ( new \ReflectionProperty(
            \ImportClient::class,
            'pull_index_journal'
        ) )->getValue($resumedClient);
        $this->assertInstanceOf(\PullIndexJournal::class, $pullIndexJournal);
        $pullIndexJournal->apply_pending_records();

        $remoteIndexPaths = $this->readRemoteIndexPaths();
        $this->assertSame($expectedFetchPaths, $remoteIndexPaths);
        $sortedRemoteIndexPaths = $remoteIndexPaths;
        sort($sortedRemoteIndexPaths, SORT_STRING);
        $this->assertSame($sortedRemoteIndexPaths, $remoteIndexPaths);
    }

    public function testFetchStageIsSavedBeforeTheDiffWalIsApplied(): void
    {
        $remoteIndex = $this->indexLine('/removed.txt', 1, 1);
        $this->writeIndex('remote-index.jsonl', $remoteIndex);
        $this->writeIndex('remote-index.next.jsonl', '');
        $this->seedLocalFile('/removed.txt');

        $client = $this->newClient();
        $this->writeDiffState($client);
        $client->stop_after_fetch_stage_save = true;

        try {
            $client->run_files_pull();
            $this->fail('Expected the test client to stop after saving the fetch stage.');
        } catch (StopAfterFetchStageSave $exception) {
            $this->assertSame(
                'fetch',
                $this->readState()['active_resumable_command']['current_stage']
            );
        }

        $this->assertSame($remoteIndex, $this->readIndexFile('remote-index.jsonl'));
        $this->assertNotSame('', $this->readIndexFile('index.wal'));
        unlink($this->pullStateDirectory . '/remote-index.next.jsonl');

        $resumedClient = $this->loadClient($this->newClient());
        $resumedClient->run_files_pull();

        $this->assertSame([], $this->readRemoteIndexPaths());
        $this->assertFileDoesNotExist($this->pullStateDirectory . '/index.wal');
        $this->assertSame(
            'complete',
            $this->readState()['active_resumable_command']['completion_state']
        );
    }

    public function testAbortAppliesTheWalFromAPartialDiff(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            $this->indexLine('/a-delete.txt', 1, 1)
                . $this->indexLine('/b-change.txt', 1, 1)
                . $this->indexLine('/c-keep.txt', 1, 1)
        );
        $this->writeIndex(
            'remote-index.next.jsonl',
            $this->indexLine('/b-change.txt', 2, 2)
                . $this->indexLine('/c-keep.txt', 1, 1)
        );
        foreach (['/a-delete.txt', '/b-change.txt', '/c-keep.txt'] as $path) {
            $this->seedLocalFile($path);
        }

        $client = $this->newClient();
        $this->writeDiffState($client);
        $client->stop_after_fetch_path = '/b-change.txt';
        $this->assertFalse($this->runDiff($client));
        $this->assertNotSame('', $this->readIndexFile('index.wal'));

        $this->newClient()->run([
            'command' => 'files-pull',
            'abort' => true,
            'follow_symlinks' => false,
        ]);

        $this->assertSame(
            ['/b-change.txt', '/c-keep.txt'],
            $this->readRemoteIndexPaths()
        );
        $this->assertFileDoesNotExist($this->pullStateDirectory . '/index.wal');
    }

    public function testDiffSkipsBlankRemoteIndexLinesAndUsesRemoteFieldDefaults(): void
    {
        $this->writeIndex(
            'remote-index.jsonl',
            "\n" . json_encode([
                'path' => base64_encode('/same.txt'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $this->writeIndex(
            'remote-index.next.jsonl',
            "\n"
                . json_encode([
                    'path' => base64_encode('/added.txt'),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
                . $this->indexLine('/same.txt', 0, 0)
        );

        $client = $this->newClient();
        $this->writeDiffState($client);

        $this->assertTrue($this->runDiff($client));
        $this->assertSame(['/added.txt'], $this->readFetchPaths());
    }

    private function newClient(): InterruptibleFilesPullClient
    {
        return new InterruptibleFilesPullClient(
            $this->remoteReprintApiUrl,
            $this->stateDirectory,
            $this->filesystemRoot
        );
    }

    private function writeDiffState(InterruptibleFilesPullClient $client): void
    {
        \write_current_pull_state($client, [
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'in_progress',
                'current_stage' => 'diff',
            ],
            'preflight' => [
                'data' => ['ok' => true],
                'http_code' => 200,
            ],
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
            'files_pull_path_selection_fingerprint' => hash(
                'sha256',
                json_encode([
                    'only_path_prefixes' => [],
                    'excluded_path_prefixes' => [],
                ], JSON_UNESCAPED_SLASHES)
            ),
        ]);
    }

    private function loadClient(
        InterruptibleFilesPullClient $client
    ): InterruptibleFilesPullClient {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client)
        );
        return $client;
    }

    private function runDiff(InterruptibleFilesPullClient $client): bool
    {
        return ( new \ReflectionClass(\ImportClient::class) )
            ->getMethod('compare_remote_indexes_and_build_fetch_list')
            ->invoke($client);
    }

    private function seedLocalFile(string $path): void
    {
        $localPath = $this->filesystemRoot . $path;
        if (!is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0700, true);
        }
        file_put_contents($localPath, 'x');
    }

    private function seedResumeScenario(): string
    {
        $remoteIndex =
            $this->indexLine('/a-delete.txt', 1, 1)
            . $this->indexLine('/b-change.txt', 1, 1)
            . $this->indexLine('/c-delete.txt', 1, 1);
        $this->writeIndex('remote-index.jsonl', $remoteIndex);
        $this->writeIndex(
            'remote-index.next.jsonl',
            $this->indexLine('/b-change.txt', 2, 2)
                . $this->indexLine('/d-add.txt', 1, 1)
        );
        foreach (['/a-delete.txt', '/b-change.txt', '/c-delete.txt'] as $path) {
            $this->seedLocalFile($path);
        }
        return $remoteIndex;
    }

    private function writeIndex(string $name, string $contents): void
    {
        file_put_contents($this->pullStateDirectory . '/' . $name, $contents);
    }

    private function readIndexFile(string $name): string
    {
        $contents = file_get_contents($this->pullStateDirectory . '/' . $name);
        $this->assertIsString($contents);
        return $contents;
    }

    private function indexLine(
        string $path,
        int $ctime,
        int $size,
        string $type = 'file'
    ): string {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return list<string> */
    private function readFetchPaths(): array
    {
        return $this->readJsonlPaths(
            $this->pullStateDirectory . '/fetch-list.jsonl',
            'path'
        );
    }

    /** @return list<string> */
    private function readRemoteIndexPaths(): array
    {
        return $this->readJsonlPaths(
            $this->pullStateDirectory . '/remote-index.jsonl',
            'path'
        );
    }

    /** @return list<string> */
    private function readWalRemotePaths(): array
    {
        return $this->readJsonlPaths(
            $this->pullStateDirectory . '/index.wal',
            'remote_absolute_path_b64'
        );
    }

    /** @return list<string> */
    private function readJsonlPaths(string $file, string $pathKey): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        return array_map(
            static function (string $line) use ($pathKey): string {
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $path = base64_decode($entry[$pathKey], true);
                if (!is_string($path)) {
                    throw new \RuntimeException('Failed to decode an index path.');
                }
                return $path;
            },
            $lines
        );
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $state = json_decode(
            $this->readIndexFile('state.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($state);
        return $state;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
