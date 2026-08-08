<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class PullIntentReconciliationTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $filesystemRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pull-intent-reconciliation-' . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->filesystemRoot = $this->root . '/files';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->filesystemRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testMakeIdenticalRemovesLocalChangesAndFetchesRemoteReplacements(): void
    {
        file_put_contents($this->filesystemRoot . '/edited.txt', 'old');
        file_put_contents($this->filesystemRoot . '/deleted.txt', 'delete me');
        file_put_contents($this->filesystemRoot . '/stable.txt', 'stable');
        $this->writeLocalIndex([
            $this->localIndexEntry('deleted.txt'),
            $this->localIndexEntry('edited.txt', true),
            $this->localIndexEntry('stable.txt'),
        ]);

        file_put_contents($this->filesystemRoot . '/edited.txt', 'local edit');
        unlink($this->filesystemRoot . '/deleted.txt');
        file_put_contents($this->filesystemRoot . '/local-only.txt', 'remove me');

        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->stateDirectory,
            $this->filesystemRoot
        );
        $this->writeNextRemoteIndex($client, [
            $this->remoteIndexEntry('/deleted.txt', 9),
            $this->remoteIndexEntry('/edited.txt', 3),
            $this->remoteIndexEntry('/stable.txt', 6),
        ]);

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            if ($this->call($client, 'advance_files_pull_local_plan')) {
                break;
            }
        }
        $this->assertLessThan(10, $attempt);
        $this->call($client, 'build_next_local_index_file');
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            if ($this->call($client, 'reconcile_local_changes_with_next_remote_index')) {
                break;
            }
        }
        $this->assertLessThan(10, $attempt);

        $this->assertFileDoesNotExist($this->filesystemRoot . '/edited.txt');
        $this->assertFileDoesNotExist($this->filesystemRoot . '/local-only.txt');
        $this->assertFileDoesNotExist($this->filesystemRoot . '/deleted.txt');
        $this->assertFileExists($this->filesystemRoot . '/stable.txt');
        $this->assertSame(
            ['/deleted.txt', '/edited.txt'],
            $this->readFetchList($client)
        );
    }

    public function testMakeIdenticalLeavesLocalChangesOutsideOnlySelection(): void
    {
        mkdir($this->filesystemRoot . '/selected');
        file_put_contents($this->filesystemRoot . '/selected/edited.txt', 'old');
        file_put_contents($this->filesystemRoot . '/outside.txt', 'old');
        $this->writeLocalIndex([
            $this->localIndexEntry('outside.txt', true),
            $this->localIndexEntry('selected/edited.txt', true),
        ]);
        file_put_contents($this->filesystemRoot . '/selected/edited.txt', 'selected edit');
        file_put_contents($this->filesystemRoot . '/selected/local-only.txt', 'remove me');
        file_put_contents($this->filesystemRoot . '/outside.txt', 'outside edit');

        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->stateDirectory,
            $this->filesystemRoot
        );
        ( new \ReflectionClass($client) )
            ->getProperty('pull_only_files_with_path_prefixes')
            ->setValue($client, ['/selected']);
        $this->writeNextRemoteIndex($client, [
            $this->remoteIndexEntry('/selected/edited.txt', 3),
        ]);

        while (!$this->call($client, 'advance_files_pull_local_plan')) {
            continue;
        }
        $this->call($client, 'build_next_local_index_file');
        while (!$this->call($client, 'reconcile_local_changes_with_next_remote_index')) {
            continue;
        }

        $this->assertFileDoesNotExist($this->filesystemRoot . '/selected/edited.txt');
        $this->assertFileDoesNotExist($this->filesystemRoot . '/selected/local-only.txt');
        $this->assertSame('outside edit', file_get_contents($this->filesystemRoot . '/outside.txt'));
        $this->assertSame(['/selected/edited.txt'], $this->readFetchList($client));
    }

    public function testMakeIdenticalFetchesRemoteDescendantsAfterLocalFileReplacesDirectory(): void
    {
        mkdir($this->filesystemRoot . '/tree');
        file_put_contents($this->filesystemRoot . '/tree/child.txt', 'remote child');
        $this->writeLocalIndex([
            $this->localIndexEntry('tree/child.txt'),
        ]);
        unlink($this->filesystemRoot . '/tree/child.txt');
        rmdir($this->filesystemRoot . '/tree');
        file_put_contents($this->filesystemRoot . '/tree', 'local replacement');

        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->stateDirectory,
            $this->filesystemRoot
        );
        $this->writeNextRemoteIndex($client, [
            $this->remoteIndexEntry('/tree/child.txt', 12),
        ]);

        while (!$this->call($client, 'advance_files_pull_local_plan')) {
            continue;
        }
        $this->call($client, 'build_next_local_index_file');
        while (!$this->call($client, 'reconcile_local_changes_with_next_remote_index')) {
            continue;
        }

        $this->assertFileDoesNotExist($this->filesystemRoot . '/tree');
        $this->assertSame(['/tree/child.txt'], $this->readFetchList($client));
    }

    public function testMakeIdenticalRetainsChangedRootsAcrossInterleavedSiblings(): void
    {
        mkdir($this->filesystemRoot . '/tree');
        file_put_contents($this->filesystemRoot . '/tree/child.txt', 'remote child');
        file_put_contents($this->filesystemRoot . '/tree-other.txt', 'old');
        $this->writeLocalIndex([
            $this->localIndexEntry('tree-other.txt', true),
            $this->localIndexEntry('tree/child.txt'),
        ]);
        unlink($this->filesystemRoot . '/tree/child.txt');
        rmdir($this->filesystemRoot . '/tree');
        file_put_contents($this->filesystemRoot . '/tree', 'local replacement');
        file_put_contents($this->filesystemRoot . '/tree-other.txt', 'local edit');

        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->stateDirectory,
            $this->filesystemRoot
        );
        $this->writeNextRemoteIndex($client, [
            $this->remoteIndexEntry('/tree-other.txt', 3),
            $this->remoteIndexEntry('/tree/child.txt', 12),
        ]);

        while (!$this->call($client, 'advance_files_pull_local_plan')) {
            continue;
        }
        $this->call($client, 'build_next_local_index_file');
        while (!$this->call($client, 'reconcile_local_changes_with_next_remote_index')) {
            continue;
        }

        $this->assertFileDoesNotExist($this->filesystemRoot . '/tree');
        $this->assertFileDoesNotExist($this->filesystemRoot . '/tree-other.txt');
        $this->assertSame(
            ['/tree-other.txt', '/tree/child.txt'],
            $this->readFetchList($client)
        );
    }

    /** @param list<array<string,mixed>> $entries */
    private function writeLocalIndex(array $entries): void
    {
        $path = $this->remoteStateDirectory() . '/local_index.jsonl';
        mkdir(dirname($path), 0700, true);
        $lines = array_map(
            static fn(array $entry): string => json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            $entries
        );
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /** @return array{path:string,type:string,size:int,ctime:int} */
    private function localIndexEntry(string $path, bool $forceChanged = false): array
    {
        $stat = lstat($this->filesystemRoot . '/' . $path);
        $this->assertIsArray($stat);
        return [
            'path' => base64_encode($path),
            'type' => 'file',
            'size' => (int) $stat['size'],
            'ctime' => (int) $stat['ctime'] - ( $forceChanged ? 1 : 0 ),
        ];
    }

    /** @return array{path:string,type:string,size:int,ctime:int} */
    private function remoteIndexEntry(string $path, int $size): array
    {
        return [
            'path' => base64_encode($path),
            'type' => 'file',
            'size' => $size,
            'ctime' => 41,
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function writeNextRemoteIndex(\ImportClient $client, array $entries): void
    {
        $path = $this->property($client, 'next_remote_index_file');
        $lines = array_map(
            static fn(array $entry): string => json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            $entries
        );
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /** @return list<string> */
    private function readFetchList(\ImportClient $client): array
    {
        $paths = [];
        foreach (file($this->property($client, 'fetch_list_file'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $paths[] = base64_decode($entry['path']);
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function remoteStateDirectory(): string
    {
        return $this->stateDirectory . '/remotes/' . md5('https://source.example/export.php');
    }

    private function property(object $target, string $property)
    {
        return ( new \ReflectionClass($target) )->getProperty($property)->getValue($target);
    }

    private function call(object $target, string $method)
    {
        return ( new \ReflectionClass($target) )->getMethod($method)->invoke($target);
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
