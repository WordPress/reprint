<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\merge_import_index_updates;

require_once __DIR__ . '/../../importer/import.php';
require_once __DIR__ . '/FailedFlushStreamWrapper.php';

final class IndexUpdateFunctionsTest extends TestCase
{
    private string $root;
    private string $stateDirectory;
    private string $fileRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/index-update-functions-'
            . bin2hex(random_bytes(6));
        $this->stateDirectory = $this->root . '/state';
        $this->fileRoot = $this->root . '/files';
        mkdir($this->stateDirectory, 0700, true);
        mkdir($this->fileRoot . '/site', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    /**
     * @dataProvider localPathDriftProvider
     */
    public function testLocalPathDriftIsNotMarkedAsAppliedByFilesPull(
        string $localPathType,
        int $expectedSize
    ): void {
        $localPath = $this->fileRoot . '/site/changed.txt';
        if ($localPathType === 'dir') {
            mkdir($localPath);
        } else {
            file_put_contents($localPath, 'changed after pull');
        }

        $client = new \ImportClient(
            'http://example.test',
            $this->stateDirectory,
            $this->fileRoot
        );
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getProperty('maintain_previous_local_index')
            ->setValue($client, true);
        $reflection->getMethod('record_index_update_file')->invoke(
            $client,
            '/site/changed.txt',
            42,
            $expectedSize,
            'file'
        );
        $walHandle = $reflection->getProperty('index_update_wal_handle')
            ->getValue($client);
        $this->assertIsResource($walHandle);
        $this->assertTrue(fflush($walHandle));
        $this->assertTrue(fclose($walHandle));

        $update = json_decode(
            (string) file_get_contents(
                $this->stateDirectory . '/.import-index-updates.wal'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertArrayNotHasKey('applied_by_files_pull', $update);
        $this->assertArrayNotHasKey('local_type', $update);
    }

    public static function localPathDriftProvider(): array
    {
        return [
            'file size changed' => ['file', 5],
            'path type changed' => ['dir', 5],
        ];
    }

    public function testMergeRejectsAnOutputFlushFailure(): void
    {
        $updatesPath = $this->root . '/updates.jsonl';
        file_put_contents(
            $updatesPath,
            json_encode([
                'op' => 'F',
                'path' => base64_encode('/file.txt'),
                'ctime' => 42,
                'size' => 5,
                'type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $this->assertTrue(
            stream_wrapper_register(
                'reprint-failed-flush',
                FailedFlushStreamWrapper::class
            )
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to flush the merged index.');
            merge_import_index_updates(
                $this->root . '/missing-base.jsonl',
                $updatesPath,
                'reprint-failed-flush://output'
            );
        } finally {
            stream_wrapper_unregister('reprint-failed-flush');
        }
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
