<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing import test namespace.
namespace ImportTests;

use MultipartPush;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-multipart-push.php';

final class MultipartPushSourceReadTest extends TestCase {
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/multipart-push-read-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/source', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testSourceGrowthCannotMakeAPartExceedItsSnapshottedTotal(): void
    {
        $path = $this->root . '/source/growing.bin';
        file_put_contents($path, 'abcdef');
        $push = new MultipartPush([
            'base_url' => 'https://example.com/',
            'source_root' => $this->root . '/source',
            'state_dir' => $this->root . '/state',
            'secret' => 'test-secret',
        ]);
        $readFilePiece = new ReflectionMethod(MultipartPush::class, 'read_file_piece');

        $piece = $readFilePiece->invoke($push, $path, 2, 4, 3);

        self::assertSame('c', $piece, 'A source that grew after lstat() exceeded the total declared in its multipart headers.');
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
