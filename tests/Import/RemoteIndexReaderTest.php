<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/RemoteIndexReadFailureStream.php';

final class RemoteIndexReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/remote-index-reader-'
            . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->root . '/' . $entry);
            }
        }
        rmdir($this->root);
    }

    public function testSequentialReadReturnsDecodedEntriesAndSkipsBlankLines(): void
    {
        $remoteIndexPath = $this->root . '/remote-index.jsonl';
        file_put_contents(
            $remoteIndexPath,
            $this->indexLine('/site/first.txt', 10, 5, 'file')
                . "\n\n"
                . $this->indexLine('/site/second', 20, 0, 'dir')
                . "\n"
        );

        $reader = new \RemoteIndexReader($remoteIndexPath);
        $reader->open();

        $this->assertSame(
            [
                'path' => '/site/first.txt',
                'ctime' => 10,
                'size' => 5,
                'type' => 'file',
            ],
            $reader->next_entry()
        );
        $this->assertSame(
            [
                'path' => '/site/second',
                'ctime' => 20,
                'size' => 0,
                'type' => 'dir',
            ],
            $reader->next_entry()
        );
        $this->assertNull($reader->next_entry());
        $reader->close();
    }

    public function testMissingFileIsAnEmptyReaderAtByteOffsetZero(): void
    {
        $reader = new \RemoteIndexReader($this->root . '/missing.jsonl');
        $reader->open();

        $this->assertNull($reader->next_entry());
        $this->assertSame(0, $reader->byte_offset());

        $reader->close();
        $reader->close();
    }

    public function testByteOffsetResumeRepeatsAndSkipsNoEntries(): void
    {
        $remoteIndexPath = $this->root . '/remote-index.jsonl';
        file_put_contents(
            $remoteIndexPath,
            $this->indexLine('/site/first.txt', 10, 5, 'file') . "\n"
                . $this->indexLine('/site/second.txt', 20, 6, 'file') . "\n"
                . $this->indexLine('/site/third.txt', 30, 7, 'file') . "\n"
        );

        $firstReader = new \RemoteIndexReader($remoteIndexPath);
        $firstReader->open();
        $firstEntry = $firstReader->next_entry();
        $secondEntry = $firstReader->next_entry();
        $byteOffset = $firstReader->byte_offset();
        $firstReader->close();

        $resumedReader = new \RemoteIndexReader($remoteIndexPath);
        $resumedReader->open();
        $resumedReader->seek_to_byte_offset($byteOffset);
        $thirdEntry = $resumedReader->next_entry();
        $this->assertNull($resumedReader->next_entry());
        $resumedReader->close();

        $this->assertSame(
            ['/site/first.txt', '/site/second.txt', '/site/third.txt'],
            [
                $firstEntry['path'] ?? null,
                $secondEntry['path'] ?? null,
                $thirdEntry['path'] ?? null,
            ]
        );
    }

    public function testInvalidLineIsConsumedBeforeTheException(): void
    {
        $remoteIndexPath = $this->root . '/remote-index.jsonl';
        file_put_contents(
            $remoteIndexPath,
            "not-json\n"
                . $this->indexLine('/site/valid.txt', 10, 5, 'file')
                . "\n"
        );

        $reader = new \RemoteIndexReader($remoteIndexPath);
        $reader->open();
        try {
            $reader->next_entry();
            $this->fail('Expected the invalid index line to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Invalid index line format', $exception->getMessage());
        }

        $this->assertSame(
            '/site/valid.txt',
            $reader->next_entry()['path'] ?? null
        );
        $reader->close();
    }

    public function testLineDecoderRejectsABlankRecord(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid index line format');

        \RemoteIndexReader::decode_index_line("\n");
    }

    public function testReadFailureDoesNotLookLikeEndOfFile(): void
    {
        $scheme = 'failingremoteindex';
        $this->assertTrue(
            stream_wrapper_register(
                $scheme,
                RemoteIndexReadFailureStream::class
            )
        );
        $reader = new \RemoteIndexReader($scheme . '://index');
        try {
            $reader->open();
            $reader->next_entry();
            $this->fail('Expected the remote index read failure to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Failed to read the remote index file',
                $exception->getMessage()
            );
        } finally {
            $reader->close();
            stream_wrapper_unregister($scheme);
        }
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
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
