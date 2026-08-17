<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match existing importer tests.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

use function Reprint\Importer\sort_index_file;
use function Reprint\Importer\write_local_index_entry;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class FilesPullMirrorDiffTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mirror-diff-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testUsesRemoteFilesChangedInEitherIndexDiff(): void
    {
        $client = $this->client();
        $same = $client->filesystem_root . '/same.txt';
        $localOnlyRoot = $client->filesystem_root . '/local-only';
        $localOnly = $localOnlyRoot . '/sub/file.txt';
        $remoteDeleted = $client->filesystem_root . '/remote-deleted.txt';
        file_put_contents($same, 'same');
        mkdir(dirname($localOnly), 0755, true);
        file_put_contents($localOnly, 'local');
        file_put_contents($remoteDeleted, 'same');
        $sameStat = lstat($same);
        $remoteDeletedStat = lstat($remoteDeleted);
        $this->assertIsArray($sameStat);
        $this->assertIsArray($remoteDeletedStat);

        $this->writeIndex($this->path($client, 'local_index_file'), [
            ['path' => 'deleted.txt', 'ctime' => 1, 'size' => 7, 'type' => 'file'],
            [
                'path' => 'remote-deleted.txt',
                'ctime' => $remoteDeletedStat['ctime'],
                'size' => 4,
                'type' => 'file',
            ],
            ['path' => 'same.txt', 'ctime' => $sameStat['ctime'] - 1, 'size' => 4, 'type' => 'file'],
        ]);
        $this->writeMappedIndex($client, [
            ['deleted.txt', '/remote/deleted.txt', 'file', 7],
            ['same.txt', '/remote/same.txt', 'file', 4],
        ]);
        $this->writeFetchList($client, [
            '/remote/remote-change.txt',
            '/remote/same.txt',
        ]);

        $buildLocalChanges = new \ReflectionMethod(
            \ImportClient::class,
            'build_files_pull_mirror_local_changes'
        );
        $buildFetchList = new \ReflectionMethod(
            \ImportClient::class,
            'build_files_pull_mirror_fetch_list'
        );
        $buildLocalChanges->invoke($client);
        unlink($remoteDeleted); // The remote diff owns this later removal.
        $buildFetchList->invoke($client);
        $journal = ( new \ReflectionProperty(
            \ImportClient::class,
            'pull_index_journal'
        ) )->getValue($client);
        $journal->flush();
        $journal->apply_pending_records();
        // A stopped mirror stage may have published its list but not saved fetch.
        $buildFetchList->invoke($client);
        $journal->flush();

        $this->assertSame([
            '/remote/deleted.txt',
            '/remote/remote-change.txt',
            '/remote/same.txt',
        ], $this->readFetchList($client));
        $this->assertDirectoryDoesNotExist($localOnlyRoot);
        $this->assertSame([
            [
                'op' => '-',
                'local_relative_path_b64' => base64_encode(
                    'local-only/sub/file.txt'
                ),
            ],
        ], $this->readJsonLines($this->path($client, 'pull_index_wal_path')));
    }

    public function testUsesCurrentRemoteIndexEntriesOutsideTheIncludeRoot(): void
    {
        $client = $this->client();
        $localPath = $client->filesystem_root . '/followed/file.txt';
        mkdir(dirname($localPath), 0755, true);
        file_put_contents($localPath, 'local edit');
        $localStat = lstat($localPath);
        $this->assertIsArray($localStat);
        $this->writeIndex($this->path($client, 'local_index_file'), [[
            'path' => 'followed/file.txt',
            'ctime' => $localStat['ctime'] - 1,
            'size' => strlen('local edit'),
            'type' => 'file',
        ]]);
        $this->writeMappedIndex($client, [[
            'followed/file.txt',
            '/outside/followed/file.txt',
            'file',
            strlen('remote'),
        ]]);
        $this->writeFetchList($client, []);
        ( new \ReflectionProperty(
            \ImportClient::class,
            'pull_only_files_with_path_prefixes'
        ) )->setValue($client, ['/remote/included']);

        ( new \ReflectionMethod(
            \ImportClient::class,
            'build_files_pull_mirror_local_changes'
        ) )->invoke($client);
        ( new \ReflectionMethod(
            \ImportClient::class,
            'build_files_pull_mirror_fetch_list'
        ) )->invoke($client);

        $this->assertSame(
            ['/outside/followed/file.txt'],
            $this->readFetchList($client)
        );
    }

    private function client(): \ImportClient
    {
        return new \ImportClient(
            'https://src.example/export.php',
            $this->root . '/state-' . bin2hex(random_bytes(2)),
            $this->root . '/files-' . bin2hex(random_bytes(2))
        );
    }

    /** @param list<array{path:string,ctime:int,size:int,type:string}> $entries */
    private function writeIndex(string $path, array $entries): void
    {
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        foreach ($entries as $entry) {
            write_local_index_entry($handle, $entry);
        }
        fclose($handle);
    }

    /** @param list<array{0:string,1:string,2:string,3:int}> $entries */
    private function writeMappedIndex(\ImportClient $client, array $entries): void
    {
        $lines = '';
        foreach ($entries as [$localPath, $remotePath, $type, $size]) {
            $lines .= json_encode([
                'path' => base64_encode(
                    bin2hex($localPath) . '/' . bin2hex($remotePath)
                ),
                'ctime' => 0,
                'size' => $size,
                'type' => $type,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        $path = $this->path($client, 'mapped_remote_index_file');
        file_put_contents($path, $lines);
        $this->assertTrue(sort_index_file($path));
    }

    /** @param list<string> $remotePaths */
    private function writeFetchList(\ImportClient $client, array $remotePaths): void
    {
        $lines = '';
        foreach ($remotePaths as $remotePath) {
            $lines .= json_encode(
                ['path' => base64_encode($remotePath)],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        }
        file_put_contents($this->path($client, 'fetch_list_file'), $lines);
    }

    /** @return list<string> */
    private function readFetchList(\ImportClient $client): array
    {
        return array_map(
            static fn(array $entry): string => base64_decode($entry['path'], true),
            $this->readJsonLines($this->path($client, 'fetch_list_file'))
        );
    }

    /** @return list<array<string,mixed>> */
    private function readJsonLines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        return array_map(
            static fn(string $line): array => json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
            $lines
        );
    }

    private function path(\ImportClient $client, string $property): string
    {
        return ( new \ReflectionProperty(
            \ImportClient::class,
            $property
        ) )->getValue($client);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
