<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only client and interruption exceptions live with their test.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Stops a test immediately after the fetch stage is saved. */
final class SimulatedStopAfterFetchStageSave extends \RuntimeException
{
}

/** Stops a test after an output write and before the next checkpoint. */
final class SimulatedStopAfterFetchListWrite extends \RuntimeException
{
}

/**
 * Stops files-pull at exact boundaries selected by each test.
 *
 * audit_log() runs after a path is written to the fetch list. save_state()
 * returns after state.json is replaced. The test stops after those calls, so
 * the files on disk match the point where the process stopped.
 */
final class DiffCheckpointTestClient extends \ImportClient
{
    public ?string $request_shutdown_after_fetch_path = null;
    public ?string $throw_after_fetch_path = null;
    public bool $throw_after_fetch_stage_save = false;

    public function audit_log(string $message, bool $to_console = true): void
    {
        parent::audit_log($message, $to_console);
        if (
            $this->throw_after_fetch_path !== null
            && $message === 'Added to the fetch list: '
                . $this->throw_after_fetch_path
        ) {
            $this->throw_after_fetch_path = null;
            throw new SimulatedStopAfterFetchListWrite();
        }
        if (
            $this->request_shutdown_after_fetch_path !== null
            && $message === 'Added to the fetch list: '
                . $this->request_shutdown_after_fetch_path
        ) {
            $this->request_shutdown_after_fetch_path = null;
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
            $this->throw_after_fetch_stage_save
            && $this->get_state()->active_resumable_command->current_stage
                === 'fetch'
        ) {
            $this->throw_after_fetch_stage_save = false;
            throw new SimulatedStopAfterFetchStageSave();
        }
    }
}

/**
 * Tests the checkpoint shared by the index diff, fetch list, and pull WAL.
 *
 * Each test shows the retained index, the next index, the chosen interruption
 * point, and the files left after resuming. Helpers below handle JSONL encoding,
 * private-method access, and temporary-directory cleanup.
 */
final class FilesPullDiffCheckpointTest extends TestCase
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

    public function testSecondRunOnSameClientContinuesFromCheckpoint(): void
    {
        // The next remote tree changes b, removes a and c, and adds d.
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            '{"path":"L2EtZGVsZXRlLnR4dA==","ctime":1,"size":1,"type":"file"}' . "\n"
            . '{"path":"L2ItY2hhbmdlLnR4dA==","ctime":1,"size":1,"type":"file"}' . "\n"
            . '{"path":"L2MtZGVsZXRlLnR4dA==","ctime":1,"size":1,"type":"file"}' . "\n"
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            '{"path":"L2ItY2hhbmdlLnR4dA==","ctime":2,"size":2,"type":"file"}' . "\n"
            . '{"path":"L2QtYWRkLnR4dA==","ctime":1,"size":1,"type":"file"}' . "\n"
        );
        foreach (['a-delete.txt', 'b-change.txt', 'c-delete.txt'] as $name) {
            file_put_contents($this->filesystemRoot . '/' . $name, 'x');
        }

        // The first call stops after b is put on the fetch list. The diff saves
        // its index, fetch-list, and WAL positions before returning.
        $client = $this->newClientAtDiffStage();
        $client->request_shutdown_after_fetch_path = '/b-change.txt';

        $client->run_files_pull();
        $this->assertSame(
            'partial',
            $client->get_state()->active_resumable_command->completion_state
        );

        // A normal second invocation creates a new ImportClient. This test
        // reuses the object to check that the first invocation closed its WAL
        // handle, so reset the two invocation-only flags before continuing.
        $client->get_state()->active_resumable_command->completion_state =
            'in_progress';
        ( new \ReflectionProperty(
            \ImportClient::class,
            'shutdown_requested'
        ) )->setValue($client, false);
        // Run the same object again. Stop after the completed diff saves fetch
        // as its next stage, before any downloads begin.
        $client->throw_after_fetch_stage_save = true;
        try {
            $client->run_files_pull();
            $this->fail('Expected the test client to stop after saving the fetch stage.');
        } catch (SimulatedStopAfterFetchStageSave $exception) {
            $this->assertSame(
                'fetch',
                $client->get_state()->active_resumable_command->current_stage
            );
        }

        $actualFetchPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/fetch-list.jsonl',
            'path'
        );
        $actualWalPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/index.wal',
            'remote_absolute_path_b64'
        );
        $this->assertSame(
            ['/b-change.txt', '/d-add.txt'],
            $actualFetchPaths
        );
        $this->assertSame(
            ['/a-delete.txt', '/c-delete.txt'],
            $actualWalPaths
        );
    }

    public function testResumeRemovesOutputWrittenAfterLastCheckpoint(): void
    {
        // Production checkpoints every 200 paths. Odd paths are deleted and
        // even paths are changed, so both output files have a nonzero offset.
        $retainedRemoteIndex = '';
        $nextRemoteIndex = '';
        $expectedFetchPaths = [];
        $expectedDeletedPaths = [];
        for ($pathNumber = 1; $pathNumber <= 206; ++$pathNumber) {
            $path = sprintf('/path-%03d.txt', $pathNumber);
            // Index paths are base64 because filenames may contain bytes which
            // JSON cannot represent. The rest of each record stays literal.
            $retainedRemoteIndex .=
                '{"path":"' . base64_encode($path)
                . '","ctime":1,"size":1,"type":"file"}' . "\n";
            if ($pathNumber % 2 === 0) {
                $nextRemoteIndex .=
                    '{"path":"' . base64_encode($path)
                    . '","ctime":2,"size":2,"type":"file"}' . "\n";
                $expectedFetchPaths[] = $path;
            } else {
                $expectedDeletedPaths[] = $path;
            }
        }
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            $retainedRemoteIndex
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            $nextRemoteIndex
        );

        // The first 200 paths form a saved checkpoint. The test then lets paths
        // 201 through 204 write output and stops before the next checkpoint.
        $client = $this->newClientAtDiffStage();
        $client->throw_after_fetch_path = '/path-204.txt';

        try {
            // Call the diff directly so the exception leaves its unconfirmed
            // output on disk instead of advancing files-pull to fetch.
            ( new \ReflectionClass(\ImportClient::class) )
                ->getMethod('compare_remote_indexes_and_build_fetch_list')
                ->invoke($client);
            $this->fail(
                'Expected the test client to stop after the saved checkpoint.'
            );
        } catch (SimulatedStopAfterFetchListWrite $exception) {
            $fetchPathsBeforeResume = $this->readBase64Paths(
                $this->pullStateDirectory . '/fetch-list.jsonl',
                'path'
            );
            $walPathsBeforeResume = $this->readBase64Paths(
                $this->pullStateDirectory . '/index.wal',
                'remote_absolute_path_b64'
            );
            $this->assertCount(102, $fetchPathsBeforeResume);
            $this->assertCount(102, $walPathsBeforeResume);
        }
        $actualRetainedRemoteIndex = file_get_contents(
            $this->pullStateDirectory . '/remote-index.jsonl'
        );
        $this->assertSame(
            $retainedRemoteIndex,
            $actualRetainedRemoteIndex
        );

        $savedCheckpoint = $client->get_state()->diff;
        $savedFetchListByteOffset = $savedCheckpoint->fetch_list_byte_offset;
        $savedWalByteOffset = $savedCheckpoint->pull_index_wal_byte_offset;
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

        // A new process loads the saved checkpoint. Resume truncates both
        // output tails and processes paths 201 through 206 again. Calling the
        // diff directly leaves the finished output files available below.
        $resumedClient = $this->loadSavedState($this->newClient());
        $this->assertTrue(
            ( new \ReflectionClass(\ImportClient::class) )
                ->getMethod('compare_remote_indexes_and_build_fetch_list')
                ->invoke($resumedClient)
        );

        $actualFetchPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/fetch-list.jsonl',
            'path'
        );
        $actualDeletedPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/index.wal',
            'remote_absolute_path_b64'
        );
        $this->assertSame($expectedFetchPaths, $actualFetchPaths);
        $this->assertSame($expectedDeletedPaths, $actualDeletedPaths);

        // Apply the WAL after resume. The retained index must be sorted and
        // contain exactly the changed even-numbered paths.
        $pullIndexJournal = ( new \ReflectionProperty(
            \ImportClient::class,
            'pull_index_journal'
        ) )->getValue($resumedClient);
        $this->assertInstanceOf(\PullIndexJournal::class, $pullIndexJournal);
        $pullIndexJournal->apply_pending_records();

        $actualRemoteIndexPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/remote-index.jsonl',
            'path'
        );
        $this->assertSame($expectedFetchPaths, $actualRemoteIndexPaths);
        $sortedRemoteIndexPaths = $actualRemoteIndexPaths;
        sort($sortedRemoteIndexPaths, SORT_STRING);
        $this->assertSame($sortedRemoteIndexPaths, $actualRemoteIndexPaths);
    }

    public function testFetchStageIsSavedBeforeWalChangesRetainedIndex(): void
    {
        // The remote tree became empty. Diff removes the local file and writes
        // its deletion to the WAL.
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            '{"path":"L3JlbW92ZWQudHh0","ctime":1,"size":1,"type":"file"}' . "\n"
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            ''
        );
        file_put_contents($this->filesystemRoot . '/removed.txt', 'x');

        // Stop at the exact stage boundary: state.json says fetch, while the
        // retained index still contains removed.txt and the WAL is pending.
        $client = $this->newClientAtDiffStage();
        $client->throw_after_fetch_stage_save = true;

        try {
            $client->run_files_pull();
            $this->fail('Expected the test client to stop after saving the fetch stage.');
        } catch (SimulatedStopAfterFetchStageSave $exception) {
            $this->assertSame(
                'fetch',
                $client->get_state()->active_resumable_command->current_stage
            );
        }

        $savedState = json_decode(
            file_get_contents($this->pullStateDirectory . '/state.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $actualRemoteIndexBeforeResume = file_get_contents(
            $this->pullStateDirectory . '/remote-index.jsonl'
        );
        $actualWalBeforeResume = file_get_contents(
            $this->pullStateDirectory . '/index.wal'
        );
        $this->assertSame(
            'fetch',
            $savedState['active_resumable_command']['current_stage']
        );
        $this->assertSame(
            '{"path":"L3JlbW92ZWQudHh0","ctime":1,"size":1,"type":"file"}' . "\n",
            $actualRemoteIndexBeforeResume
        );
        $this->assertNotSame('', $actualWalBeforeResume);

        // Remove the next index before resuming. The fetch stage has no use for
        // that file. Restarting the diff would fail because the file is gone.
        unlink($this->pullStateDirectory . '/remote-index.next.jsonl');

        $resumedClient = $this->loadSavedState($this->newClient());
        $resumedClient->run_files_pull();

        $actualRemoteIndexPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/remote-index.jsonl',
            'path'
        );
        $savedStateAfterResume = json_decode(
            file_get_contents($this->pullStateDirectory . '/state.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame([], $actualRemoteIndexPaths);
        $this->assertFileDoesNotExist($this->pullStateDirectory . '/index.wal');
        $this->assertSame(
            'complete',
            $savedStateAfterResume['active_resumable_command']['completion_state']
        );
    }

    public function testAbortAppliesCompletedDeletionsFromPartialDiff(): void
    {
        // The first path is deleted, the second changes, and the third stays.
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            '{"path":"L2EtZGVsZXRlLnR4dA==","ctime":1,"size":1,"type":"file"}' . "\n"
            . '{"path":"L2ItY2hhbmdlLnR4dA==","ctime":1,"size":1,"type":"file"}' . "\n"
            . '{"path":"L2Mta2VlcC50eHQ=","ctime":1,"size":1,"type":"file"}' . "\n"
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            '{"path":"L2ItY2hhbmdlLnR4dA==","ctime":2,"size":2,"type":"file"}' . "\n"
            . '{"path":"L2Mta2VlcC50eHQ=","ctime":1,"size":1,"type":"file"}' . "\n"
        );
        foreach (['a-delete.txt', 'b-change.txt', 'c-keep.txt'] as $name) {
            file_put_contents($this->filesystemRoot . '/' . $name, 'x');
        }

        // Stop after the diff has removed a-delete.txt and recorded that work.
        // Calling the diff directly leaves its partial state for abort.
        $client = $this->newClientAtDiffStage();
        $client->request_shutdown_after_fetch_path = '/b-change.txt';
        $this->assertFalse(
            ( new \ReflectionClass(\ImportClient::class) )
                ->getMethod('compare_remote_indexes_and_build_fetch_list')
                ->invoke($client)
        );
        $walBeforeAbort = file_get_contents(
            $this->pullStateDirectory . '/index.wal'
        );
        $this->assertNotSame('', $walBeforeAbort);
        $this->assertFileDoesNotExist(
            $this->filesystemRoot . '/a-delete.txt'
        );

        // Abort clears the diff checkpoint and applies its completed WAL work.
        $this->newClient()->run([
            'command' => 'files-pull',
            'abort' => true,
            'follow_symlinks' => false,
        ]);

        $actualRemoteIndexPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/remote-index.jsonl',
            'path'
        );
        $this->assertSame(
            ['/b-change.txt', '/c-keep.txt'],
            $actualRemoteIndexPaths
        );
        $this->assertFileDoesNotExist($this->pullStateDirectory . '/index.wal');
    }

    public function testDiffUsesDefaultsForMissingRemoteFields(): void
    {
        // same.txt omits ctime, size, and type. The remote decoder supplies the
        // same defaults as the complete next-index entry, so it is unchanged.
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.jsonl',
            '{"path":"L3NhbWUudHh0"}' . "\n"
        );
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            '{"path":"L2FkZGVkLnR4dA=="}' . "\n"
            . '{"path":"L3NhbWUudHh0","ctime":0,"size":0,"type":"file"}' . "\n"
        );

        $client = $this->newClientAtDiffStage();

        // Calling the diff directly leaves the fetch list in place for the
        // assertion below.
        $this->assertTrue(
            ( new \ReflectionClass(\ImportClient::class) )
                ->getMethod('compare_remote_indexes_and_build_fetch_list')
                ->invoke($client)
        );
        $actualFetchPaths = $this->readBase64Paths(
            $this->pullStateDirectory . '/fetch-list.jsonl',
            'path'
        );
        $this->assertSame(['/added.txt'], $actualFetchPaths);
    }

    public function testDiffRejectsBlankRemoteIndexRecords(): void
    {
        file_put_contents($this->pullStateDirectory . '/remote-index.jsonl', '');
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.next.jsonl',
            "\n"
        );

        $client = $this->newClientAtDiffStage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid index line format');
        // Call the diff directly because run_files_pull() would catch the
        // decoder exception and report it as command failure.
        ( new \ReflectionClass(\ImportClient::class) )
            ->getMethod('compare_remote_indexes_and_build_fetch_list')
            ->invoke($client);
    }

    private function newClient(): DiffCheckpointTestClient
    {
        return new DiffCheckpointTestClient(
            $this->remoteReprintApiUrl,
            $this->stateDirectory,
            $this->filesystemRoot
        );
    }

    /** Creates a client whose next files-pull operation starts at diff. */
    private function newClientAtDiffStage(): DiffCheckpointTestClient
    {
        $client = $this->newClient();
        \write_current_pull_state($client, [
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'in_progress',
                'current_stage' => 'diff',
            ],
            'preflight' => [
                'data' => [
                    'ok' => true,
                    'wp_detect' => [
                        'roots' => [[
                            'path' => '/',
                        ]],
                    ],
                ],
                'http_code' => 200,
            ],
            'remote_protocol_version' => PULL_PROTOCOL_VERSION,
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
            'files_pull_path_selection_fingerprint' => hash(
                'sha256',
                json_encode([
                    'only_path_prefixes_b64' => [],
                    'excluded_path_prefixes_b64' => [],
                    'extra_directory_b64' => null,
                    'follow_symlinks' => true,
                    'include_caches' => false,
                ], JSON_UNESCAPED_SLASHES)
            ),
        ]);
        return $client;
    }

    /** Loads state.json as a new PHP process would. */
    private function loadSavedState(
        DiffCheckpointTestClient $client
    ): DiffCheckpointTestClient {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('state')->setValue(
            $client,
            $reflection->getMethod('load_state')->invoke($client)
        );
        return $client;
    }

    /** @return list<string> */
    private function readBase64Paths(string $file, string $pathKey): array
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
