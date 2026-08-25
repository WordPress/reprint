<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match existing importer tests.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/pull/class-mapped-remote-index-builder.php';

final class MappedRemoteIndexBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mapped-index-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/files', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testBuildsTheRemoteIndexInMappedLocalOrder(): void
    {
        $remoteIndex = $this->root . '/remote.jsonl';
        $mappedIndex = $this->root . '/mapped.jsonl';
        $this->writeRemoteIndex($remoteIndex, ['/remote/a', '/remote/b']);
        $mapper = new \RemoteToLocalPathMapper(
            $this->root . '/files',
            ['/remote'],
            [
                '/remote/a' => $this->root . '/files/z',
                '/remote/b' => $this->root . '/files/a',
            ]
        );

        \MappedRemoteIndexBuilder::build([
            'remote_index_file' => $remoteIndex,
            'mapped_remote_index_file' => $mappedIndex,
            'filesystem_root' => $this->root . '/files',
            'path_mapper' => $mapper,
        ]);

        $this->assertSame([
            ['path' => 'a', 'source' => '/remote/b'],
            ['path' => 'z', 'source' => '/remote/a'],
        ], array_map(
            static function (string $line): array {
                $entry = \MappedRemoteIndexBuilder::decode_index_line($line);
                return [
                    'path' => $entry['path'],
                    'source' => $entry['copy_source_path'],
                ];
            },
            file($mappedIndex, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        ));
        $this->assertFileDoesNotExist($mappedIndex . '.collision-stack');
    }

    /**
     * @dataProvider collisionProvider
     * @param array<string,string> $mappings
     */
    public function testRejectsMappedPathCollisions(
        array $mappings,
        string $message
    ): void {
        $remoteIndex = $this->root . '/remote.jsonl';
        $remotePaths = array_keys($mappings);
        $this->writeRemoteIndex($remoteIndex, $remotePaths);
        $resolvedMappings = [];
        foreach ($mappings as $remotePath => $localPath) {
            $resolvedMappings[$remotePath] = $this->root . '/files/' . $localPath;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        \MappedRemoteIndexBuilder::build([
            'remote_index_file' => $remoteIndex,
            'mapped_remote_index_file' => $this->root . '/mapped.jsonl',
            'filesystem_root' => $this->root . '/files',
            'path_mapper' => new \RemoteToLocalPathMapper(
                $this->root . '/files',
                ['/remote'],
                $resolvedMappings
            ),
        ]);
    }

    /** @return iterable<string,array{array<string,string>,string}> */
    public static function collisionProvider(): iterable
    {
        yield 'same path' => [[
            '/remote/a' => 'same',
            '/remote/b' => 'same',
        ], 'Remote paths map to the same local path'];
        yield 'ancestor before lexical sibling' => [[
            '/remote/a' => 'x',
            '/remote/b' => 'x-other',
            '/remote/c' => 'x/child',
        ], 'x/child is below x'];
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown mapped remote-index option: remote_file');
        \MappedRemoteIndexBuilder::build(['remote_file' => 'index.jsonl']);
    }

    public function testOmitsExcludedRemotePathsBeforeCollisionValidation(): void
    {
        $remoteIndex = $this->root . '/remote.jsonl';
        $mappedIndex = $this->root . '/mapped.jsonl';
        $this->writeRemoteIndex($remoteIndex, [
            '/remote/included/file.txt',
            '/remote/excluded/a.txt',
            '/remote/excluded/b.txt',
        ]);
        $mapper = new \RemoteToLocalPathMapper(
            $this->root . '/files',
            ['/remote'],
            [
                '/remote/included' => $this->root . '/files/included',
                '/remote/excluded/a.txt' => $this->root . '/files/collision',
                '/remote/excluded/b.txt' => $this->root . '/files/collision',
            ]
        );

        \MappedRemoteIndexBuilder::build([
            'remote_index_file' => $remoteIndex,
            'mapped_remote_index_file' => $mappedIndex,
            'filesystem_root' => $this->root . '/files',
            'path_mapper' => $mapper,
            'excluded_remote_absolute_path_prefixes' => [
                '/remote/excluded',
            ],
        ]);

        $this->assertMappedSourcePaths($mappedIndex, [
            '/remote/included/file.txt',
        ]);
    }

    public function testRetainsIntermediateSymlinksAboveMappedContent(): void
    {
        $remoteIndex = $this->root . '/remote.jsonl';
        $mappedIndex = $this->root . '/mapped.jsonl';
        $this->writeRemoteIndex($remoteIndex, [
            [
                'path' => '/srv/wordpress',
                'size' => 0,
                'type' => 'link',
                'intermediate' => true,
            ],
            [
                'path' => '/wordpress/core/latest',
                'size' => 0,
                'type' => 'link',
            ],
        ]);

        \MappedRemoteIndexBuilder::build([
            'remote_index_file' => $remoteIndex,
            'mapped_remote_index_file' => $mappedIndex,
            'filesystem_root' => $this->root . '/files',
            'path_mapper' => new \RemoteToLocalPathMapper(
                $this->root . '/files',
                ['/srv'],
                [
                    '/wordpress' => $this->root . '/files/srv/wordpress',
                ]
            ),
        ]);

        $this->assertMappedSourcePaths($mappedIndex, [
            '/srv/wordpress',
            '/wordpress/core/latest',
        ]);
        $entries = array_map(
            static function (string $line): array {
                return \MappedRemoteIndexBuilder::decode_index_line($line);
            },
            file($mappedIndex, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
        $this->assertTrue($entries[0]['intermediate']);
        $this->assertArrayNotHasKey('intermediate', $entries[1]);
    }

    public function testRejectsIntermediateSymlinkSharingAMappedLocalPath(): void
    {
        $remoteIndex = $this->root . '/remote.jsonl';
        $this->writeRemoteIndex($remoteIndex, [
            [
                'path' => '/srv/wordpress',
                'size' => 0,
                'type' => 'link',
                'intermediate' => true,
            ],
            ['path' => '/wordpress'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Remote paths map to the same local path: srv/wordpress.'
        );
        \MappedRemoteIndexBuilder::build([
            'remote_index_file' => $remoteIndex,
            'mapped_remote_index_file' => $this->root . '/mapped.jsonl',
            'filesystem_root' => $this->root . '/files',
            'path_mapper' => new \RemoteToLocalPathMapper(
                $this->root . '/files',
                ['/srv'],
                [
                    '/wordpress' => $this->root . '/files/srv/wordpress',
                ]
            ),
        ]);
    }

    /**
     * Writes a remote index from paths, or from field overrides keyed by path.
     *
     * @param list<string|array<string,mixed>> $remoteEntries
     */
    private function writeRemoteIndex(string $path, array $remoteEntries): void
    {
        $lines = '';
        foreach ($remoteEntries as $remoteEntry) {
            if (is_string($remoteEntry)) {
                $remoteEntry = ['path' => $remoteEntry];
            }
            $record = array_merge([
                'path' => '',
                'ctime' => 1,
                'size' => 1,
                'type' => 'file',
            ], $remoteEntry);
            $record['path'] = base64_encode($record['path']);
            $lines .= json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        }
        file_put_contents($path, $lines);
    }

    /**
     * Asserts the mapped index holds exactly these sources, in file order.
     *
     * @param list<string> $expectedRemotePaths
     */
    private function assertMappedSourcePaths(
        string $mappedIndex,
        array $expectedRemotePaths
    ): void {
        $lines = file(
            $mappedIndex,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $this->assertIsArray($lines);
        $this->assertSame($expectedRemotePaths, array_map(
            static function (string $line): string {
                return \MappedRemoteIndexBuilder::decode_index_line(
                    $line
                )['copy_source_path'];
            },
            $lines
        ));
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
