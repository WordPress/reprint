<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class RemoteIndexWalTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $pullStateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/remote-index-wal-'
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

    public function testAppliedBatchLeavesTheRemoteIndexWalMarkerUntilCompletion(): void
    {
        $client = $this->client();
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('record_remote_index_wal_upsert')->invoke(
            $client,
            '/site/file.txt',
            42,
            5,
            'file'
        );
        $reflection->getMethod('apply_remote_index_wal')->invoke($client);

        $remoteIndexWalPath = $this->pullStateDirectory . '/remote-index.wal';
        $this->assertFileExists($remoteIndexWalPath);
        $this->assertSame('', file_get_contents($remoteIndexWalPath));
        $this->assertSame(
            '/site/file.txt',
            $this->firstRemoteIndexEntryPath()
        );

        $reflection->getMethod('remove_remote_index_wal')->invoke($client);
        $this->assertFileDoesNotExist($remoteIndexWalPath);
    }

    public function testUpsertingFileDoesNotCreateParentDirectoryEntries(): void
    {
        $client = $this->client();
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('upsert_remote_index_entry')->invoke(
            $client,
            '/site/nested/file.txt',
            42,
            5,
            'file'
        );
        $reflection->getMethod('apply_remote_index_wal')->invoke($client);

        $this->assertSame(
            ['/site/nested/file.txt'],
            $this->remoteIndexEntryPaths()
        );
    }

    public function testDeletedFileDerivesTheAbsentDirectoryRoot(): void
    {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $remoteAbsolutePathToDelete = $reflection
            ->getMethod('remote_absolute_path_to_delete')
            ->invoke(
                $this->client(),
                '/srv/site/gone/nested/file.txt',
                '/srv/site/kept.txt',
                null
            );

        $this->assertSame('/srv/site/gone', $remoteAbsolutePathToDelete);
    }

    public function testDeletedFileKeepsAParentWithAnotherNextIndexEntry(): void
    {
        $reflection = new \ReflectionClass(\ImportClient::class);
        $remoteAbsolutePathToDelete = $reflection
            ->getMethod('remote_absolute_path_to_delete')
            ->invoke(
                $this->client(),
                '/srv/site/kept/old.txt',
                '/srv/site/kept/current.txt',
                null
            );

        $this->assertSame('/srv/site/kept/old.txt', $remoteAbsolutePathToDelete);
    }

    public function testReplayDiscardsAnUnterminatedFinalRecord(): void
    {
        $completeRecord = json_encode([
            'op' => 'F',
            'path' => base64_encode('/site/complete.txt'),
            'ctime' => 42,
            'size' => 5,
            'type' => 'file',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.wal',
            $completeRecord . '{"op":"F","path":"'
        );

        $client = $this->client();
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('replay_remote_index_wal')->invoke($client);

        $this->assertSame('/site/complete.txt', $this->firstRemoteIndexEntryPath());
        $this->assertSame(
            '',
            file_get_contents(
                $this->pullStateDirectory . '/remote-index.wal'
            )
        );
    }

    /**
     * @dataProvider abortCommandProvider
     */
    public function testAbortReplaysAndRemovesTheRemoteIndexWal(string $command): void
    {
        file_put_contents(
            $this->pullStateDirectory . '/remote-index.wal',
            json_encode([
                'op' => 'F',
                'path' => base64_encode('/site/aborted.txt'),
                'ctime' => 42,
                'size' => 5,
                'type' => 'file',
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
            $this->pullStateDirectory . '/remote-index.wal'
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
