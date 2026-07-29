<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

final class IndexUpdateWalTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/index-update-wal-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->fileRoot = $this->root . '/files';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->fileRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testOneWalRecordUpdatesBothIndexes(): void
    {
        mkdir($this->fileRoot . '/site');
        file_put_contents($this->fileRoot . '/site/file.txt', 'hello');

        $client = $this->client();
        $client->get_import_state()->preflight = [
            'http_code' => 200,
            'data' => [
                'ok' => true,
                'runtime' => [
                    'document_root' => '/site',
                ],
            ],
        ];
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('record_pulled_path')->invoke(
            $client,
            '/site/file.txt',
            realpath($this->fileRoot . '/site/file.txt'),
            42,
            5,
            'file'
        );
        $walHandle = $reflection->getProperty('index_update_wal_handle')
            ->getValue($client);
        $this->assertIsResource($walHandle);
        $this->assertTrue(fflush($walHandle));
        $walLines = file(
            $this->stateDirectory . '/.import-index-updates.wal',
            FILE_IGNORE_NEW_LINES
        );
        $this->assertIsArray($walLines);
        $this->assertCount(1, $walLines);
        $walRecord = json_decode(
            $walLines[0],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            '/site/file.txt',
            base64_decode($walRecord['path'])
        );
        $this->assertSame(
            'file.txt',
            base64_decode($walRecord['local_path_b64'])
        );

        $reflection->getMethod('apply_index_update_wal')->invoke($client);

        $walPath = $this->stateDirectory . '/.import-index-updates.wal';
        $this->assertFileExists($walPath);
        $this->assertSame('', file_get_contents($walPath));
        $this->assertSame('/site/file.txt', $this->firstIndexPath());
        $localIndexPaths = glob(
            $this->stateDirectory . '/local-index/*.jsonl'
        );
        $this->assertIsArray($localIndexPaths);
        $this->assertCount(1, $localIndexPaths);
        $this->assertSame(
            'file.txt',
            $this->firstIndexPath($localIndexPaths[0])
        );

        $reflection->getMethod('remove_index_update_wal')->invoke($client);
        $this->assertFileDoesNotExist($walPath);
    }

    public function testApplyingWalDiscardsACorruptUnterminatedFinalRecord(): void
    {
        $completeRecord = json_encode([
            'op' => 'F',
            'path' => base64_encode('/site/complete.txt'),
            'ctime' => 42,
            'size' => 5,
            'type' => 'file',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents(
            $this->stateDirectory . '/.import-index-updates.wal',
            $completeRecord . '{"op":"F","path":"'
        );

        $client = $this->client();
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('apply_index_update_wal')->invoke($client);

        $this->assertSame('/site/complete.txt', $this->firstIndexPath());
        $this->assertSame(
            '',
            file_get_contents(
                $this->stateDirectory . '/.import-index-updates.wal'
            )
        );
    }

    /**
     * @dataProvider abortCommandProvider
     */
    public function testAbortReplaysAndRemovesTheWal(string $command): void
    {
        file_put_contents(
            $this->stateDirectory . '/.import-index-updates.wal',
            json_encode([
                'op' => 'F',
                'path' => base64_encode('/site/aborted.txt'),
                'ctime' => 42,
                'size' => 5,
                'type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents(
            $this->stateDirectory . '/.import-state.json',
            json_encode([
                'preflight' => [
                    'data' => ['ok' => true],
                    'http_code' => 200,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->client()->run([
            'command' => $command,
            'abort' => true,
        ]);

        $this->assertSame('/site/aborted.txt', $this->firstIndexPath());
        $this->assertFileDoesNotExist(
            $this->stateDirectory . '/.import-index-updates.wal'
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

    private function firstIndexPath(?string $indexPath = null): string
    {
        $lines = file(
            $indexPath ?? $this->stateDirectory . '/.import-index.jsonl',
            FILE_IGNORE_NEW_LINES
        );
        $this->assertIsArray($lines);
        $line = $lines[0] ?? null;
        $this->assertIsString($line);
        $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $encodedPath = (string) $entry['path'];
        $path = base64_decode($encodedPath);
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
