<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\apply_local_index_updates;

require_once __DIR__ . '/../../importer/import.php';

final class IndexUpdateFunctionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir()
            . '/index-update-functions-'
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
    public function testLocalIndexUpdateDeterminesWhetherDescendantsRemain(
        array $operations,
        array $expected
    ): void {
        $baseIndex = $this->root . '/base.jsonl';
        $updatesPath = $this->root . '/updates.jsonl';
        $this->writeJsonLines($baseIndex, [
            [
                'path' => base64_encode('directory'),
                'ctime' => 1,
                'size' => 0,
                'type' => 'dir',
                'empty' => false,
            ],
            [
                'path' => base64_encode('directory/old.txt'),
                'ctime' => 1,
                'size' => 3,
                'type' => 'file',
            ],
        ]);
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
        $this->writeJsonLines($updatesPath, $updates);

        apply_local_index_updates($baseIndex, $updatesPath);

        $actual = [];
        foreach (file($baseIndex, FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $actual[] = [
                'path' => base64_decode($entry['path']),
                'type' => $entry['type'],
                'empty' => $entry['empty'] ?? null,
            ];
        }
        $this->assertSame($expected, $actual);
    }

    public function testLocalIndexUpdateAddsParentDirectories(): void
    {
        $localIndex = $this->root . '/local-index.jsonl';
        $updatesPath = $this->root . '/updates.jsonl';
        $this->writeJsonLines($updatesPath, [[
            'op' => 'F',
            'path' => base64_encode('first/second/file.txt'),
            'ctime' => 7,
            'size' => 4,
            'type' => 'file',
        ]]);

        apply_local_index_updates($localIndex, $updatesPath);

        $entries = [];
        foreach (file($localIndex, FILE_IGNORE_NEW_LINES) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entries[ (string) base64_decode($entry['path']) ] = [
                'type' => $entry['type'],
                'empty' => $entry['empty'] ?? null,
            ];
        }
        $this->assertSame([
            'first' => ['type' => 'dir', 'empty' => false],
            'first/second' => ['type' => 'dir', 'empty' => false],
            'first/second/file.txt' => [
                'type' => 'file',
                'empty' => null,
            ],
        ], $entries);
    }

    /**
     * @dataProvider localIndexOperationOrderProvider
     */
    public function testLaterOperationsReplaceEarlierSubtrees(
        array $operations,
        array $expected
    ): void {
        $localIndex = $this->root . '/local-index.jsonl';
        $updatesPath = $this->root . '/updates.jsonl';
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
        $this->writeJsonLines($updatesPath, $updates);

        apply_local_index_updates($localIndex, $updatesPath);

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
                [['D', null]],
                [],
            ],
            'file replaces descendants' => [
                [['F', 'file']],
                [[
                    'path' => 'directory',
                    'type' => 'file',
                    'empty' => null,
                ]],
            ],
            'directory retains descendants' => [
                [['F', 'dir']],
                [
                    [
                        'path' => 'directory',
                        'type' => 'dir',
                        'empty' => false,
                    ],
                    [
                        'path' => 'directory/old.txt',
                        'type' => 'file',
                        'empty' => null,
                    ],
                ],
            ],
            'deletion followed by directory still removes old descendants' => [
                [['D', null], ['F', 'dir']],
                [[
                    'path' => 'directory',
                    'type' => 'dir',
                    'empty' => true,
                ]],
            ],
        ];
    }

    public static function localIndexOperationOrderProvider(): array
    {
        $directoryAndChild = [
            [
                'path' => 'directory',
                'type' => 'dir',
                'empty' => false,
            ],
            [
                'path' => 'directory/child.txt',
                'type' => 'file',
                'empty' => null,
            ],
        ];
        return [
            'later file replaces earlier child' => [
                [
                    ['F', 'directory/child.txt', 'file'],
                    ['F', 'directory', 'file'],
                ],
                [[
                    'path' => 'directory',
                    'type' => 'file',
                    'empty' => null,
                ]],
            ],
            'later child replaces earlier file with a directory' => [
                [
                    ['F', 'directory', 'file'],
                    ['F', 'directory/child.txt', 'file'],
                ],
                $directoryAndChild,
            ],
            'later deletion removes earlier child' => [
                [
                    ['F', 'directory/child.txt', 'file'],
                    ['D', 'directory', null],
                ],
                [],
            ],
            'later child recreates an earlier deleted directory' => [
                [
                    ['D', 'directory', null],
                    ['F', 'directory/child.txt', 'file'],
                ],
                $directoryAndChild,
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
