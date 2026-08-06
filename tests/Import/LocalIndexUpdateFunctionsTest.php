<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\merge_local_index_mutations;

require_once __DIR__ . '/../../client/import.php';

final class LocalIndexUpdateFunctionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/local-index-update-functions-'
            . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    /**
     * @dataProvider localIndexDescendantProvider
     */
    public function testParentMutationRemovesOlderDescendants(
        array $operations,
        array $expected
    ): void {
        $localIndex = $this->root . '/local-index.jsonl';
        $sortedUpdatesPath = $this->root . '/sorted-updates.jsonl';
        $this->writeJsonLines($localIndex, [[
            'path' => base64_encode('directory/old.txt'),
            'ctime' => 1,
            'size' => 3,
            'type' => 'file',
        ]]);
        $updates = [];
        foreach ($operations as [$op, $type]) {
            $update = [
                'op' => $op,
                'path' => base64_encode('directory'),
            ];
            if ($type !== null) {
                $update['ctime'] = 2;
                $update['size'] = $type === 'dir' ? 0 : 4;
                $update['type'] = $type;
            }
            $updates[] = $update;
        }
        $this->writeJsonLines($sortedUpdatesPath, $updates);

        merge_local_index_mutations(
            $localIndex,
            $sortedUpdatesPath
        );

        $actual = [];
        foreach (file($localIndex, FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $actual[] = [
                'path' => base64_decode($entry['path']),
                'type' => $entry['type'],
                'empty' => $entry['empty'] ?? null,
            ];
        }
        $this->assertSame($expected, $actual);
    }

    public function testLocalIndexUpdateDoesNotAddParentDirectories(): void
    {
        $localIndex = $this->root . '/local-index.jsonl';
        $sortedUpdatesPath = $this->root . '/sorted-updates.jsonl';
        $this->writeJsonLines($sortedUpdatesPath, [[
            'op' => '+',
            'path' => base64_encode('first/second/file.txt'),
            'ctime' => 7,
            'size' => 4,
            'type' => 'file',
        ]]);

        merge_local_index_mutations(
            $localIndex,
            $sortedUpdatesPath
        );

        $entries = [];
        foreach (file($localIndex, FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entries[ (string) base64_decode($entry['path']) ] = [
                'type' => $entry['type'],
                'empty' => $entry['empty'] ?? null,
            ];
        }
        $this->assertSame([
            'first/second/file.txt' => [
                'type' => 'file',
                'empty' => null,
            ],
        ], $entries);
    }

    public function testLocalIndexRemainsInRawPathOrder(): void
    {
        $localIndex = $this->root . '/local-index.jsonl';
        $sortedUpdatesPath = $this->root . '/sorted-updates.jsonl';
        $this->writeJsonLines($localIndex, [
            [
                'path' => base64_encode('a-other'),
                'ctime' => 1,
                'size' => 3,
                'type' => 'file',
            ],
            [
                'path' => base64_encode('a/old.txt'),
                'ctime' => 1,
                'size' => 3,
                'type' => 'file',
            ],
        ]);
        $this->writeJsonLines($sortedUpdatesPath, [
            [
                'op' => '+',
                'path' => base64_encode('a/new.txt'),
                'ctime' => 2,
                'size' => 3,
                'type' => 'file',
            ],
            [
                'op' => '-',
                'path' => base64_encode('a/old.txt'),
            ],
        ]);

        merge_local_index_mutations(
            $localIndex,
            $sortedUpdatesPath
        );

        $this->assertSame(
            ['a-other', 'a/new.txt'],
            $this->readIndexPaths($localIndex)
        );
    }

    public function testDeletingTheLastChildDoesNotCreateAParentEntry(): void
    {
        $localIndex = $this->root . '/local-index.jsonl';
        $sortedUpdatesPath = $this->root . '/sorted-updates.jsonl';
        $this->writeJsonLines($localIndex, [[
            'path' => base64_encode('directory/old.txt'),
            'ctime' => 1,
            'size' => 3,
            'type' => 'file',
        ]]);
        $this->writeJsonLines($sortedUpdatesPath, [[
            'op' => '-',
            'path' => base64_encode('directory/old.txt'),
        ]]);

        merge_local_index_mutations(
            $localIndex,
            $sortedUpdatesPath
        );

        $this->assertSame([], $this->readIndexPaths($localIndex));
    }

    /**
     * @dataProvider localIndexMutationSetProvider
     */
    public function testMutationSetProducesOneSparseTree(
        array $operations,
        array $expected
    ): void {
        $localIndex = $this->root . '/local-index.jsonl';
        $sortedUpdatesPath = $this->root . '/sorted-updates.jsonl';
        $updates = [];
        foreach ($operations as [$op, $path, $type]) {
            $update = [
                'op' => $op,
                'path' => base64_encode($path),
            ];
            if ($type !== null) {
                $update['ctime'] = 2;
                $update['size'] = $type === 'dir' ? 0 : 4;
                $update['type'] = $type;
            }
            $updates[] = $update;
        }
        $this->writeJsonLines($sortedUpdatesPath, $updates);

        merge_local_index_mutations(
            $localIndex,
            $sortedUpdatesPath
        );

        $actual = [];
        foreach (file($localIndex, FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $actual[] = [
                'path' => base64_decode($entry['path']),
                'type' => $entry['type'],
                'empty' => $entry['empty'] ?? null,
            ];
        }
        $this->assertSame($expected, $actual);
    }

    public static function localIndexDescendantProvider(): array
    {
        return [
            'deletion removes descendants' => [
                [['-', null]],
                [],
            ],
            'file replaces descendants' => [
                [['+', 'file']],
                [[
                    'path' => 'directory',
                    'type' => 'file',
                    'empty' => null,
                ]],
            ],
            'empty directory replaces descendants' => [
                [['+', 'dir']],
                [[
                    'path' => 'directory',
                    'type' => 'dir',
                    'empty' => true,
                ]],
            ],
        ];
    }

    public static function localIndexMutationSetProvider(): array
    {
        $child = [[
            'path' => 'directory/child.txt',
            'type' => 'file',
            'empty' => null,
        ]];
        return [
            'parent deletion and child upsert retain the child' => [
                [
                    ['-', 'directory', null],
                    ['+', 'directory/child.txt', 'file'],
                ],
                $child,
            ],
            'parent upsert and child deletion retain the parent' => [
                [
                    ['+', 'directory', 'file'],
                    ['-', 'directory/child.txt', null],
                ],
                [[
                    'path' => 'directory',
                    'type' => 'file',
                    'empty' => null,
                ]],
            ],
        ];
    }

    private function writeJsonLines(string $path, array $entries): void
    {
        file_put_contents(
            $path,
            implode('', array_map(
                static fn(array $entry): string =>
                    json_encode(
                        $entry,
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ) . "\n",
                $entries
            ))
        );
    }

    /** @return list<string> */
    private function readIndexPaths(string $indexPath): array
    {
        $lines = file($indexPath, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        return array_map(
            static function (string $line): string {
                $entry = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                return (string) base64_decode($entry['path']);
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
