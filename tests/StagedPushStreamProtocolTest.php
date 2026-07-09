<?php

use PHPUnit\Framework\TestCase;

final class StagedPushStreamProtocolTest extends TestCase
{
    public function testChunkHeadersDecodeFromJsonLines(): void
    {
        $line = json_encode([
            'type' => 'chunk',
            'artifact_id' => base64_encode('wp-content/uploads/photo.jpg'),
            'offset' => 10,
            'bytes' => 5,
            'total_bytes' => 20,
            'final' => false,
        ], JSON_UNESCAPED_SLASHES);

        // The wire carries the id base64-encoded; decoding hands back the
        // raw path.
        $this->assertSame([
            'artifact_id' => 'wp-content/uploads/photo.jpg',
            'offset' => 10,
            'bytes' => 5,
            'total_bytes' => 20,
            'final' => false,
        ], Site_Export_Staged_Push_Stream_Protocol::decode_chunk_header($line));
    }

    /**
     * @dataProvider reservedArtifactIdProvider
     */
    public function testReservedNamespaceGuardsEveryReprintIdButTheManifest(string $artifact_id, bool $forbidden): void
    {
        $this->assertSame(
            $forbidden,
            Site_Export_Staged_Push_Stream_Protocol::is_reserved_sender_artifact_id($artifact_id)
        );
    }

    public static function reservedArtifactIdProvider(): array
    {
        return [
            'the deletion manifest is the one allowed reserved id' => ['.reprint/deletions.jsonl', false],
            'a sibling in the namespace is forbidden' => ['.reprint/evil.jsonl', true],
            'a nested path in the namespace is forbidden' => ['.reprint/sub/dir/file', true],
            'the bare namespace segment is forbidden' => ['.reprint', true],
            'a real file that merely starts with the segment is allowed' => ['.reprintfoo', false],
            'a nested .reprint below a real dir is allowed' => ['wp-content/.reprint/x', false],
            'an ordinary site file is allowed' => ['wp-content/uploads/photo.jpg', false],
        ];
    }

    public function testChunkHeaderValidationRejectsRangesOutsideTheDeclaredFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('offset 8 and 4 payload bytes, which exceeds total_bytes 10');

        Site_Export_Staged_Push_Stream_Protocol::decode_chunk_header(json_encode([
            'type' => 'chunk',
            'artifact_id' => base64_encode('file.txt'),
            'offset' => 8,
            'bytes' => 4,
            'total_bytes' => 10,
            'final' => true,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @dataProvider invalidChunkHeaderProvider
     */
    public function testChunkHeaderValidationNamesTheExactInvalidField(array $header, string $expected_message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected_message);

        Site_Export_Staged_Push_Stream_Protocol::decode_chunk_header(json_encode($header, JSON_UNESCAPED_SLASHES));
    }

    public static function invalidChunkHeaderProvider(): array
    {
        return [
            'missing type' => [
                ['artifact_id' => base64_encode('file.txt'), 'offset' => 0, 'bytes' => 1, 'total_bytes' => 1, 'final' => false],
                'Missing staged push stream frame field "type".',
            ],
            'empty artifact id' => [
                ['type' => 'chunk', 'artifact_id' => '', 'offset' => 0, 'bytes' => 1, 'total_bytes' => 1, 'final' => false],
                'Expected staged push stream frame field "artifact_id" to be base64 of a non-empty path; received string "".',
            ],
            'artifact id that is not base64' => [
                ['type' => 'chunk', 'artifact_id' => '!!!not-base64!!!', 'offset' => 0, 'bytes' => 1, 'total_bytes' => 1, 'final' => false],
                'Expected staged push stream frame field "artifact_id" to be base64 of a non-empty path; received string "!!!not-base64!!!".',
            ],
            'negative offset' => [
                ['type' => 'chunk', 'artifact_id' => base64_encode('file.txt'), 'offset' => -1, 'bytes' => 1, 'total_bytes' => 1, 'final' => false],
                'Expected staged push stream frame field "offset" to be a non-negative integer; received integer -1.',
            ],
            'string byte count' => [
                ['type' => 'chunk', 'artifact_id' => base64_encode('file.txt'), 'offset' => 0, 'bytes' => '1', 'total_bytes' => 1, 'final' => false],
                'Expected staged push stream frame field "bytes" to be a non-negative integer; received string "1".',
            ],
            'missing final flag' => [
                ['type' => 'chunk', 'artifact_id' => base64_encode('file.txt'), 'offset' => 0, 'bytes' => 1, 'total_bytes' => 1],
                'Missing staged push stream frame field "final".',
            ],
        ];
    }

    public function testReadAndDiscardUseTheSameBoundedStreamHelpers(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "abc\ndefghijk");
        rewind($stream);

        $this->assertSame("abc\n", fgets($stream));
        $this->assertSame('def', Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, 3));
        $this->assertTrue(Site_Export_Staged_Push_Stream_Protocol::discard_exactly($stream, 3, 2));
        $this->assertSame('jk', Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, 2));

        fclose($stream);
    }
}
