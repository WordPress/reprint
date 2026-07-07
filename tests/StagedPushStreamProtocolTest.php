<?php

use PHPUnit\Framework\TestCase;

final class StagedPushStreamProtocolTest extends TestCase
{
    public function testChunkHeadersRoundTripThroughTheSharedCodec(): void
    {
        $line = Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header(
            'wp-content/uploads/photo.jpg',
            10,
            5,
            20,
            false
        );

        $this->assertSame([
            'artifact_id' => 'wp-content/uploads/photo.jpg',
            'offset' => 10,
            'bytes' => 5,
            'total_bytes' => 20,
            'final' => false,
        ], Site_Export_Staged_Push_Stream_Protocol::decode_chunk_header(rtrim($line, "\n")));
    }

    public function testChunkHeaderValidationRejectsRangesOutsideTheDeclaredFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('range_exceeds_total');

        Site_Export_Staged_Push_Stream_Protocol::decode_chunk_header(json_encode([
            'type' => 'chunk',
            'artifact_id' => 'file.txt',
            'offset' => 8,
            'bytes' => 4,
            'total_bytes' => 10,
            'final' => true,
        ], JSON_UNESCAPED_SLASHES));
    }

    public function testReadAndDiscardUseTheSameBoundedStreamHelpers(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "abc\ndefghijk");
        rewind($stream);

        $this->assertSame('abc', Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream));
        $this->assertSame('def', Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, 3));
        $this->assertTrue(Site_Export_Staged_Push_Stream_Protocol::discard_exactly($stream, 3, 2));
        $this->assertSame('jk', Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, 2));

        fclose($stream);
    }
}
