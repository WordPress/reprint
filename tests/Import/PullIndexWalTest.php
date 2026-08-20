<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class PullIndexWalTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $pullStateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/pull-index-wal-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $remoteReprintApiUrl = 'https://example.com/?site-export-api';
        $this->pullStateDirectory =
            $this->stateDirectory
            . '/remotes/'
            . md5(rtrim($remoteReprintApiUrl, '?&'))
            . '/pull';
        $this->fileRoot = $this->root . '/files';
        mkdir($this->pullStateDirectory, 0700, true);
        mkdir($this->fileRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAppliedBatchAdvancesBothIndexesAndLeavesEmptyWal(): void
    {
        mkdir($this->fileRoot . '/site');
        file_put_contents($this->fileRoot . '/site/file.txt', 'hello');
        $journal = $this->journal($this->client());
        $journal->record_remote_upsert(
            '/site/file.txt',
            42,
            5,
            'file',
            (string) realpath($this->fileRoot . '/site/file.txt')
        );
        $journal->apply_pending_records();

        $pullIndexWalPath = $this->pullStateDirectory . '/index.wal';
        $this->assertFileExists($pullIndexWalPath);
        $this->assertSame('', file_get_contents($pullIndexWalPath));
        $this->assertSame(
            '/site/file.txt',
            $this->firstRemoteIndexEntryPath()
        );
        $localIndexEntries = $this->readLocalIndex();
        $this->assertSame(['site/file.txt'], array_keys($localIndexEntries));
        $localStat = lstat($this->fileRoot . '/site/file.txt');
        $this->assertIsArray($localStat);
        $this->assertSame(
            (int) $localStat['ctime'],
            $localIndexEntries['site/file.txt']['ctime']
        );
        $this->assertSame(5, $localIndexEntries['site/file.txt']['size']);

        $journal->remove_empty_wal();
        $this->assertFileDoesNotExist($pullIndexWalPath);
    }

    public function testRecordedMutationsUsePlusAndMinusOperations(): void
    {
        $journal = $this->journal($this->client());
        $journal->record_remote_upsert(
            '/site/file.txt',
            42,
            5,
            'file'
        );
        $filesystemRoot = realpath($this->fileRoot);
        $this->assertIsString($filesystemRoot);
        $journal->record_successful_deletion(
            '/site/file.txt',
            $filesystemRoot . '/site/file.txt'
        );
        $journal->record_remote_invalidation(
            '/site/unreadable.txt'
        );

        $expectedPullIndexWal =
            '{"op":"+","remote_absolute_path_b64":"'
            . base64_encode('/site/file.txt')
            . '","remote_path_ctime":42,"remote_path_size":5,"remote_path_type":"file"}'
            . "\n"
            . '{"op":"-","remote_absolute_path_b64":"'
            . base64_encode('/site/file.txt')
            . '","local_relative_path_b64":"'
            . base64_encode('site/file.txt')
            . '"}'
            . "\n"
            . '{"op":"-","remote_absolute_path_b64":"'
            . base64_encode('/site/unreadable.txt')
            . '"}'
            . "\n";
        $this->assertSame(
            $expectedPullIndexWal,
            file_get_contents($this->pullStateDirectory . '/index.wal')
        );

        $journal->apply_pending_records();
        $this->assertSame([], $this->remoteIndexEntryPaths());
        $journal->remove_empty_wal();
    }

    public function testLocalOnlyDeletionChangesOnlyTheLocalIndex(): void
    {
        mkdir($this->fileRoot . '/site');
        $localAbsolutePath = $this->fileRoot . '/site/local-only.txt';
        file_put_contents($localAbsolutePath, 'hello');
        $localAbsolutePath = realpath($localAbsolutePath);
        $this->assertIsString($localAbsolutePath);
        $journal = $this->journal($this->client());
        $journal->record_remote_upsert(
            '/site/local-only.txt',
            42,
            5,
            'file',
            $localAbsolutePath
        );
        $journal->apply_pending_records();

        unlink($localAbsolutePath);
        $journal->record_local_deletion($localAbsolutePath);
        $journal->apply_pending_records();

        $this->assertSame(
            ['/site/local-only.txt'],
            $this->remoteIndexEntryPaths()
        );
        $this->assertSame([], $this->readLocalIndex());
    }

    public function testUpsertingFileDoesNotCreateParentDirectoryEntries(): void
    {
        $journal = $this->journal($this->client());
        $journal->record_remote_upsert(
            '/site/nested/file.txt',
            42,
            5,
            'file'
        );
        $journal->apply_pending_records();

        $this->assertSame(
            ['/site/nested/file.txt'],
            $this->remoteIndexEntryPaths()
        );
    }

    public function testDeletedFileDerivesTheAbsentDirectoryRoot(): void
    {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $remoteAbsolutePathToDelete = $reflection
            ->getMethod('derive_remote_deletion_root_from_sparse_index')
            ->invoke(
                $this->client(),
                '/srv/site/gone/nested/file.txt',
                '/srv/site/kept.txt',
                null,
                ['/srv/site']
            );

        $this->assertSame('/srv/site/gone', $remoteAbsolutePathToDelete);
    }

    public function testDeletedFileKeepsAParentWithAnotherNextIndexEntry(): void
    {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $remoteAbsolutePathToDelete = $reflection
            ->getMethod('derive_remote_deletion_root_from_sparse_index')
            ->invoke(
                $this->client(),
                '/srv/site/kept/old.txt',
                '/srv/site/kept/current.txt',
                null,
                ['/srv/site']
            );

        $this->assertSame('/srv/site/kept/old.txt', $remoteAbsolutePathToDelete);
    }

    public function testReplayDiscardsAnUnterminatedFinalRecord(): void
    {
        $completeRecord = json_encode([
            'op' => '+',
            'remote_absolute_path_b64' => base64_encode('/site/complete.txt'),
            'remote_path_ctime' => 42,
            'remote_path_size' => 5,
            'remote_path_type' => 'file',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents(
            $this->pullStateDirectory . '/index.wal',
            $completeRecord . '{"op":"+","remote_absolute_path_b64":"'
        );

        $this->journal($this->client())->apply_pending_records();

        $this->assertSame('/site/complete.txt', $this->firstRemoteIndexEntryPath());
        $this->assertSame(
            '',
            file_get_contents(
                $this->pullStateDirectory . '/index.wal'
            )
        );
    }

    /**
     * @dataProvider abortCommandProvider
     */
    public function testAbortReplaysAndRemovesThePullIndexWal(string $command): void
    {
        file_put_contents(
            $this->pullStateDirectory . '/index.wal',
            json_encode([
                'op' => '+',
                'remote_absolute_path_b64' => base64_encode('/site/aborted.txt'),
                'remote_path_ctime' => 42,
                'remote_path_size' => 5,
                'remote_path_type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        \write_current_pull_state($this->client(), [
            'preflight' => [
                'data' => ['ok' => true],
                'http_code' => 200,
            ],
        ]);

        $this->client()->run([
            'command' => $command,
            'abort' => true,
        ]);

        $this->assertSame('/site/aborted.txt', $this->firstRemoteIndexEntryPath());
        $this->assertFileDoesNotExist(
            $this->pullStateDirectory . '/index.wal'
        );
    }

    /** @return array<string,array{string}> */
    public static function abortCommandProvider(): array
    {
        return [
            'files-pull' => ['files-pull'],
            'pull-files' => ['pull-files'],
        ];
    }

    private function client(): \ImportClient
    {
        return new \ImportClient(
            'https://example.com/?site-export-api',
            $this->stateDirectory,
            $this->fileRoot
        );
    }

    private function journal(\ImportClient $client): \PullIndexJournal
    {
        $journal_property = new \ReflectionProperty(
            \ImportClient::class,
            'pull_index_journal'
        );
        return $journal_property->getValue($client);
    }

    private function firstRemoteIndexEntryPath(): string
    {
        $paths = $this->remoteIndexEntryPaths();
        $path = $paths[0] ?? null;
        $this->assertIsString($path);
        return $path;
    }

    /** @return list<string> */
    private function remoteIndexEntryPaths(): array
    {
        $lines = file(
            $this->pullStateDirectory . '/remote-index.jsonl',
            FILE_IGNORE_NEW_LINES
        );
        $this->assertIsArray($lines);
        return array_map(
            static function (string $line): string {
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $path = base64_decode((string) $entry['path']);
                if (!is_string($path)) {
                    throw new \RuntimeException('Failed to decode remote index path.');
                }
                return $path;
            },
            $lines
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function readLocalIndex(): array
    {
        $lines = file(
            dirname($this->pullStateDirectory) . '/local_index.jsonl',
            FILE_IGNORE_NEW_LINES
        );
        $this->assertIsArray($lines);
        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $path = base64_decode( (string) $entry['path']);
            $this->assertIsString($path);
            $entries[$path] = $entry;
        }
        return $entries;
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
