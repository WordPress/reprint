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
        mkdir($this->fileRoot . '/site');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testOneWalRecordUpdatesBothIndexes(): void
    {
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
        $client->prepare_files_pull_options([]);
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
            $this->remoteStateDirectory() . '/pull-index-updates.wal',
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
            base64_decode($walRecord['remote_path_b64'])
        );
        $this->assertSame(
            'file.txt',
            base64_decode($walRecord['local_path_b64'])
        );

        $reflection->getMethod('apply_index_update_wal')->invoke($client);

        $walPath =
            $this->remoteStateDirectory() . '/pull-index-updates.wal';
        $this->assertFileExists($walPath);
        $this->assertSame('', file_get_contents($walPath));
        $this->assertSame('/site/file.txt', $this->firstIndexPath());
        $this->assertSame(
            'file.txt',
            $this->firstIndexPath(
                $this->remoteStateDirectory() . '/.local-index.jsonl'
            )
        );

        $reflection->getMethod('remove_index_update_wal')->invoke($client);
        $this->assertFileDoesNotExist($walPath);
    }

    public function testApplyingWalDiscardsACorruptUnterminatedFinalRecord(): void
    {
        $completeRecord = json_encode([
            'op' => 'F',
            'remote_path_b64' => base64_encode('/site/complete.txt'),
            'remote_ctime' => 42,
            'remote_size' => 5,
            'remote_type' => 'file',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $remoteStateDirectory = $this->remoteStateDirectory();
        mkdir($remoteStateDirectory);
        file_put_contents(
            $remoteStateDirectory . '/pull-index-updates.wal',
            $completeRecord . '{"op":"F","remote_path_b64":"'
        );

        $client = $this->client();
        $client->get_import_state()->preflight = [
            'data' => [
                'runtime' => [
                    'document_root' => '/site',
                ],
            ],
        ];
        $client->prepare_files_pull_options([]);
        $reflection = new \ReflectionClass(\ImportClient::class);
        $reflection->getMethod('apply_index_update_wal')->invoke($client);

        $this->assertSame('/site/complete.txt', $this->firstIndexPath());
        $this->assertSame(
            '',
            file_get_contents(
                $remoteStateDirectory . '/pull-index-updates.wal'
            )
        );
    }

    /**
     * @dataProvider abortCommandProvider
     */
    public function testAbortReplaysAndRemovesTheWal(string $command): void
    {
        $remoteStateDirectory = $this->remoteStateDirectory();
        mkdir($remoteStateDirectory);
        file_put_contents(
            $remoteStateDirectory . '/pull-index-updates.wal',
            json_encode([
                'op' => 'F',
                'remote_path_b64' => base64_encode('/site/aborted.txt'),
                'remote_ctime' => 42,
                'remote_size' => 5,
                'remote_type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents(
            $remoteStateDirectory . '/pull-state.json',
            json_encode([
                'preflight' => [
                    'data' => [
                        'ok' => true,
                        'runtime' => [
                            'document_root' => '/site',
                        ],
                    ],
                    'http_code' => 200,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        $filesystemRoot = realpath($this->fileRoot);
        $localTree = realpath($this->fileRoot . '/site');
        $this->assertIsString($filesystemRoot);
        $this->assertIsString($localTree);
        file_put_contents(
            $remoteStateDirectory . '/path-mapping.json',
            json_encode([
                'target_url_fingerprint' => hash(
                    'sha256',
                    'https://example.com/'
                ),
                'filesystem_root_b64' => base64_encode($filesystemRoot),
                'local_tree_b64' => base64_encode($localTree),
                'target_document_root_b64' => base64_encode('/site'),
                'prefix_rules' => [[
                    'kind' => 'default',
                    'remote_prefix_b64' => base64_encode('/site'),
                    'local_prefix_b64' => base64_encode($localTree),
                ]],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->client()->run([
            'command' => $command,
            'abort' => true,
        ]);

        $this->assertSame('/site/aborted.txt', $this->firstIndexPath());
        $this->assertFileDoesNotExist(
            $remoteStateDirectory . '/pull-index-updates.wal'
        );
    }

    /**
     * @dataProvider retainedWalBoundaryProvider
     */
    public function testRetainedWalReplaysAfterIndexReplacement(
        bool $localIndexWasReplaced
    ): void {
        file_put_contents($this->fileRoot . '/site/file.txt', 'hello');
        $client = $this->client();
        $client->get_import_state()->preflight = [
            'data' => [
                'runtime' => [
                    'document_root' => '/site',
                ],
            ],
        ];
        $client->prepare_files_pull_options([]);
        $remoteStateDirectory = $client->remote_state_directory;
        $remoteIndexLine = $this->indexLine(
            '/site/file.txt',
            42,
            5,
            'file'
        );
        $localIndexLine = $this->indexLine(
            'file.txt',
            52,
            5,
            'file'
        );
        file_put_contents(
            $remoteStateDirectory . '/.remote-index.jsonl',
            $remoteIndexLine
        );
        if ($localIndexWasReplaced) {
            file_put_contents(
                $remoteStateDirectory . '/.local-index.jsonl',
                $localIndexLine
            );
        }
        file_put_contents(
            $remoteStateDirectory . '/pull-index-updates.wal',
            json_encode([
                'op' => 'F',
                'remote_path_b64' => base64_encode('/site/file.txt'),
                'remote_ctime' => 42,
                'remote_size' => 5,
                'remote_type' => 'file',
                'local_path_b64' => base64_encode('file.txt'),
                'local_ctime' => 52,
                'local_size' => 5,
                'local_type' => 'file',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        (new \ReflectionClass(\ImportClient::class))
            ->getMethod('apply_index_update_wal')
            ->invoke($client);

        $this->assertSame(
            $remoteIndexLine,
            file_get_contents(
                $remoteStateDirectory . '/.remote-index.jsonl'
            )
        );
        $this->assertSame(
            $localIndexLine,
            file_get_contents(
                $remoteStateDirectory . '/.local-index.jsonl'
            )
        );
        $this->assertSame(
            '',
            file_get_contents(
                $remoteStateDirectory . '/pull-index-updates.wal'
            )
        );
    }

    /** @return array<string,array{bool}> */
    public static function retainedWalBoundaryProvider(): array
    {
        return [
            'remote index replaced' => [false],
            'both indexes replaced' => [true],
        ];
    }

    public function testTargetsKeepSeparateRemoteStateDirectories(): void
    {
        $firstClient = $this->client();
        $secondClient = new \ImportClient(
            'https://other.example/?site-export-api',
            $this->stateDirectory,
            $this->fileRoot
        );
        foreach ([$firstClient, $secondClient] as $client) {
            $client->get_import_state()->preflight = [
                'data' => [
                    'runtime' => [
                        'document_root' => '/site',
                    ],
                ],
            ];
            $client->prepare_files_pull_options([]);
        }

        $this->assertNotSame(
            $firstClient->remote_state_directory,
            $secondClient->remote_state_directory
        );
        $this->assertStringStartsWith(
            realpath($this->stateDirectory) . '/remote-',
            $firstClient->remote_state_directory
        );
        $this->assertStringStartsWith(
            realpath($this->stateDirectory) . '/remote-',
            $secondClient->remote_state_directory
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

    private function remoteStateDirectory(): string
    {
        $context = \ImportClient::prepare_files_command_context(
            'https://example.com/?site-export-api',
            $this->stateDirectory,
            $this->fileRoot . '/site',
            'files-diff'
        );
        return $context['remote_state_directory'];
    }

    private function firstIndexPath(?string $indexPath = null): string
    {
        $lines = file(
            $indexPath
                ?? $this->remoteStateDirectory() . '/.remote-index.jsonl',
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

    private function indexLine(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): string {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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
