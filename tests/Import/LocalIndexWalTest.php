<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class LocalIndexWalTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/local-index-wal-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->fileRoot = $this->root . '/files';
        mkdir($this->stateDirectory . '/.reprint/pull', 0700, true);
        mkdir($this->fileRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAppliedBatchLeavesTheLocalIndexWalMarkerUntilCompletion(): void
    {
        $client = $this->client();
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('record_local_index_wal_file')->invoke(
            $client,
            '/site/file.txt',
            42,
            5,
            'file'
        );
        $reflection->getMethod('apply_local_index_wal')->invoke($client);

        $localIndexWalPath = $this->stateDirectory . '/.reprint/pull/local-index.wal';
        $this->assertFileExists($localIndexWalPath);
        $this->assertSame('', file_get_contents($localIndexWalPath));
        $this->assertSame(
            '/site/file.txt',
            $this->firstIndexPath()
        );

        $reflection->getMethod('remove_local_index_wal')->invoke($client);
        $this->assertFileDoesNotExist($localIndexWalPath);
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
            $this->stateDirectory . '/.reprint/pull/local-index.wal',
            $completeRecord . '{"op":"F","path":"'
        );

        $client = $this->client();
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('replay_local_index_wal')->invoke($client);

        $this->assertSame('/site/complete.txt', $this->firstIndexPath());
        $this->assertSame(
            '',
            file_get_contents(
                $this->stateDirectory . '/.reprint/pull/local-index.wal'
            )
        );
    }

    /**
     * @dataProvider abortCommandProvider
     */
    public function testAbortReplaysAndRemovesTheLocalIndexWal(string $command): void
    {
        file_put_contents(
            $this->stateDirectory . '/.reprint/pull/local-index.wal',
            json_encode([
                'op' => 'F',
                'path' => base64_encode('/site/aborted.txt'),
                'ctime' => 42,
                'size' => 5,
                'type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        \write_current_import_state($this->client(), [
            'preflight' => [
                'data' => ['ok' => true],
                'http_code' => 200,
            ],
        ]);

        $this->client()->run([
            'command' => $command,
            'abort' => true,
        ]);

        $this->assertSame('/site/aborted.txt', $this->firstIndexPath());
        $this->assertFileDoesNotExist(
            $this->stateDirectory . '/.reprint/pull/local-index.wal'
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

    private function firstIndexPath(): string
    {
        $lines = file(
            $this->stateDirectory . '/.reprint/pull/local-index.jsonl',
            FILE_IGNORE_NEW_LINES
        );
        $this->assertIsArray($lines);
        $line = $lines[0] ?? null;
        $this->assertIsString($line);
        $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $path = base64_decode((string) $entry['path']);
        $this->assertIsString($path);
        return $path;
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
