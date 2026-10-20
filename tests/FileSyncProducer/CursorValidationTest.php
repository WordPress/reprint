<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared file-producer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match file-producer tests.
namespace FileSyncProducerTests;

use WordPress\Reprint\Server\FileTreeProducer;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

final class CursorValidationTest extends FileSyncProducerTestBase
{
    /**
     * @dataProvider invalid_fields
     * @param array $fields {
     *     Cursor fields to replace.
     *     @type mixed $path  Optional base64 file path.
     *     @type mixed $bytes Optional byte position.
     * }
     * @param string $message Rejected field named by the error.
     */
    public function testInvalidCursorFieldsAreRejectedBeforeResuming(array $fields, string $message): void
    {
        $directory = $this->createTestDirectory('cursor-fields', ['test.txt' => 'test']);
        $cursor = array_replace([
            'phase' => 'streaming', 'path' => base64_encode($directory . '/test.txt'),
            'ctime' => filectime($directory . '/test.txt'), 'bytes' => 1,
        ], $fields);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new FileTreeProducer($directory, ['paths' => [], 'cursor' => json_encode($cursor)]);
    }

    /** @return array<string,array{array,string}> Replaced cursor fields and error text. */
    public static function invalid_fields(): array
    {
        return [
            'ftp URL' => [['path' => base64_encode('ftp://127.0.0.1/test.jpg')], 'File cursor path'],
            'file URL' => [['path' => base64_encode('file:///tmp/test.txt')], 'File cursor path'],
            'archive URL' => [['path' => base64_encode('phar:///tmp/test.phar/test.txt')], 'File cursor path'],
            'relative path' => [['path' => base64_encode('test.txt')], 'File cursor path'],
            'invalid base64' => [['path' => '!!!!'], 'File cursor path'],
            'non-string path' => [['path' => 123], 'File cursor path'],
            'empty path' => [['path' => ''], 'File cursor path'],
            'NUL in path' => [['path' => base64_encode("/tmp/test\0.txt")], 'File cursor path'],
            'negative offset' => [['bytes' => -1], 'File cursor bytes'],
            'fractional offset' => [['bytes' => 1.5], 'File cursor bytes'],
            'oversized offset' => [['bytes' => 1e19], 'File cursor bytes'],
            'numeric string offset' => [['bytes' => '1'], 'File cursor bytes'],
            'boolean offset' => [['bytes' => true], 'File cursor bytes'],
            'array offset' => [['bytes' => []], 'File cursor bytes'],
            'null offset' => [['bytes' => null], 'File cursor bytes'],
        ];
    }

    public function testCursorMustDecodeToAnArray(): void
    {
        $directory = $this->createTestDirectory('cursor-json', []);
        foreach (['null', 'true', '1', '"text"'] as $cursor) {
            try {
                new FileTreeProducer($directory, ['paths' => [], 'cursor' => $cursor]);
                $this->fail('A cursor must decode to an array.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('File cursor must be a JSON object or array', $error->getMessage());
            }
        }
    }

    public function testServerGeneratedCursorResumesAnAbsolutePathWithDotSegments(): void
    {
        $directory = $this->createTestDirectory('cursor-spelling', ['test.txt' => 'test']);
        $path = $directory . '/./test.txt';
        $first = new FileTreeProducer($directory, ['paths' => [$path], 'chunk_size' => 2]);
        $this->assertTrue($first->next_chunk());
        $this->assertSame('te', $first->get_current_chunk()['data']);
        $resumed = new FileTreeProducer($directory, [
            'paths' => [$path], 'chunk_size' => 2, 'cursor' => $first->get_reentrancy_cursor(),
        ]);
        $this->assertTrue($resumed->next_chunk());
        $this->assertSame('st', $resumed->get_current_chunk()['data']);
    }
}
