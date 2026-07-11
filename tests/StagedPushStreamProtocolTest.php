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

    public function testParserReadsAndDiscardsBoundedPayloads(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $first_header = rtrim(
            Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header('first.txt', 0, 5, 5, true),
            "\n"
        ) . "\r\n";
        fwrite(
            $stream,
            $first_header
            . 'abcde'
            . Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header('second.txt', 0, 2, 2, true)
            . 'fg'
        );
        rewind($stream);

        try {
            $parser = new Site_Export_Staged_Push_Stream_Parser($stream);

            $this->assertTrue($parser->next_frame());
            $this->assertSame(
                ['artifact_id' => 'first.txt', 'offset' => 0, 'bytes' => 5, 'total_bytes' => 5, 'final' => true],
                Site_Export_Staged_Push_Stream_Protocol::decode_chunk_frame($parser->get_current_frame())
            );
            $this->assertSame('ab', $parser->read_payload_piece(2));
            $this->assertSame('cde', $parser->read_payload_piece(8));

            $this->assertTrue($parser->next_frame());
            $this->assertSame(
                ['artifact_id' => 'second.txt', 'offset' => 0, 'bytes' => 2, 'total_bytes' => 2, 'final' => true],
                Site_Export_Staged_Push_Stream_Protocol::decode_chunk_frame($parser->get_current_frame())
            );
            $parser->discard_payload_bytes(2, 1);
            $this->assertFalse($parser->next_frame());
        } finally {
            fclose($stream);
        }
    }

    public function testParserDoesNotAdvanceBeforeTheCurrentPayloadIsConsumed(): void
    {
        $next_header = Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header('second.txt', 0, 0, 0, true);
        $stream = fopen('php://temp', 'w+b');
        fwrite(
            $stream,
            Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header('first.txt', 0, 1, 1, true)
            . 'x'
            . $next_header
        );
        rewind($stream);

        try {
            $parser = new Site_Export_Staged_Push_Stream_Parser($stream);
            $this->assertTrue($parser->next_frame());

            try {
                $parser->next_frame();
                $this->fail('Expected parser to require the current payload first.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Read or discard the current staged push stream frame payload before reading another frame.',
                    $exception->getMessage()
                );
            }

            $this->assertSame('x' . $next_header, stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testParserRejectsATruncatedPayload(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite(
            $stream,
            Site_Export_Staged_Push_Stream_Protocol::encode_chunk_header('short.txt', 0, 2, 2, true) . 'x'
        );
        rewind($stream);

        try {
            $parser = new Site_Export_Staged_Push_Stream_Parser($stream);
            $this->assertTrue($parser->next_frame());
            $this->assertSame('x', $parser->read_payload_piece(2));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('payload ended before its declared byte count');
            $parser->read_payload_piece(2);
        } finally {
            fclose($stream);
        }
    }

    public function testApplyChangeHeadersRoundTrip(): void
    {
        $changes = [
            ['type' => 'directory', 'path' => 'directory'],
            ['type' => 'symlink', 'path' => 'link', 'target' => "raw-\xff"],
            ['type' => 'delete', 'path' => 'deleted'],
        ];

        foreach ($changes as $change) {
            $header = Site_Export_Staged_Push_Stream_Protocol::encode_apply_change_header($change);
            $frame = Site_Export_Staged_Push_Stream_Protocol::decode_frame_header(rtrim($header, "\n"));
            $this->assertSame($change, Site_Export_Staged_Push_Stream_Protocol::decode_apply_change_frame($frame));
        }
    }
}
