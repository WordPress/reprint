<?php

use PHPUnit\Framework\TestCase;

final class StagedPushStreamProtocolTest extends TestCase
{
    /**
     * @dataProvider metadataOperationProvider
     */
    public function testMetadataOperationHeadersRoundTripRawBytes(array $operation): void
    {
        $header = Site_Export_Staged_Push_Stream_Protocol::encode_operation_header($operation);

        $this->assertStringEndsWith("\n", $header);
        $this->assertSame(
            $operation,
            Site_Export_Staged_Push_Stream_Protocol::decode_operation_header(rtrim($header, "\n"))
        );
    }

    public static function metadataOperationProvider(): array
    {
        return [
            'directory' => [[
                'type' => 'directory',
                'operation_index' => 0,
                'path' => 'wp-content/uploads',
            ]],
            'delete' => [[
                'type' => 'delete',
                'operation_index' => 1,
                'path' => "cache/invalid-utf8-\xff",
            ]],
            'symlink with arbitrary-byte target' => [[
                'type' => 'symlink',
                'operation_index' => 2,
                'path' => 'current',
                'target' => "releases/build-\xfe",
            ]],
            'symlink with empty target for filesystem validation' => [[
                'type' => 'symlink',
                'operation_index' => 3,
                'path' => 'empty-target',
                'target' => '',
            ]],
        ];
    }

    public function testMaximumPathAndSymlinkTargetFitOneBoundedHeader(): void
    {
        $operation = [
            'type' => 'symlink',
            'operation_index' => 0,
            'path' => str_repeat('p', Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES),
            'target' => str_repeat('t', Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES),
        ];

        $header = Site_Export_Staged_Push_Stream_Protocol::encode_operation_header($operation);

        $this->assertLessThanOrEqual(Site_Export_Staged_Push_Stream_Protocol::MAX_HEADER_BYTES + 1, strlen($header));
        $this->assertSame(
            $operation,
            Site_Export_Staged_Push_Stream_Protocol::decode_operation_header(rtrim($header, "\n"))
        );
    }

    public function testPathBeyondSharedRawByteLimitIsRejectedBeforeEncoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds 8192 raw bytes');

        Site_Export_Staged_Push_Stream_Protocol::encode_operation_header([
            'type' => 'delete',
            'operation_index' => 0,
            'path' => str_repeat('p', Site_Export_Staged_Push_Stream_Protocol::MAX_PATH_BYTES + 1),
        ]);
    }

    public function testFileHeaderDerivesPayloadBytesWithoutBufferingThemIntoJson(): void
    {
        $operation = [
            'type' => 'file',
            'operation_index' => 4,
            'path' => 'wp-content/uploads/photo.jpg',
            'revision' => 7,
            'offset' => 10,
            'total_bytes' => 15,
            'restart' => false,
            'payload' => "a\0bcd",
        ];

        $header = Site_Export_Staged_Push_Stream_Protocol::encode_operation_header($operation);
        $this->assertStringNotContainsString($operation['payload'], $header);
        $this->assertSame([
            'type' => 'file',
            'operation_index' => 4,
            'path' => 'wp-content/uploads/photo.jpg',
            'revision' => 7,
            'offset' => 10,
            'bytes' => 5,
            'total_bytes' => 15,
            'restart' => false,
        ], Site_Export_Staged_Push_Stream_Protocol::decode_operation_header(rtrim($header, "\n")));
    }

    public function testZeroByteFileFrameIsAllowedOnlyAtEndOfFile(): void
    {
        $header = Site_Export_Staged_Push_Stream_Protocol::encode_operation_header([
            'type' => 'file',
            'operation_index' => 0,
            'path' => 'empty.txt',
            'revision' => 0,
            'offset' => 0,
            'total_bytes' => 0,
            'restart' => true,
            'payload' => '',
        ]);

        $this->assertSame(0, Site_Export_Staged_Push_Stream_Protocol::decode_operation_header(
            rtrim($header, "\n")
        )['bytes']);
    }

    /**
     * @dataProvider invalidHeaderProvider
     */
    public function testOperationHeaderValidationNamesTheViolatedCondition(array $header, string $expected_message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected_message);

        Site_Export_Staged_Push_Stream_Protocol::decode_operation_header(
            json_encode($header, JSON_UNESCAPED_SLASHES)
        );
    }

    public static function invalidHeaderProvider(): array
    {
        $file = [
            'type' => 'file',
            'operation_index' => 0,
            'path' => base64_encode('file.txt'),
            'revision' => 1,
            'offset' => 0,
            'bytes' => 1,
            'total_bytes' => 1,
            'restart' => false,
        ];

        return [
            'old generic chunk type' => [
                array_merge($file, ['type' => 'chunk']),
                'field "type" to be "directory", "file", "symlink", or "delete"',
            ],
            'missing operation index' => [
                ['type' => 'directory', 'path' => base64_encode('dir')],
                'Missing staged push stream frame field "operation_index".',
            ],
            'numeric-string operation index' => [
                ['type' => 'directory', 'operation_index' => '0', 'path' => base64_encode('dir')],
                'field "operation_index" to be a non-negative integer; received string "0"',
            ],
            'invalid path base64' => [
                ['type' => 'delete', 'operation_index' => 0, 'path' => '!!!'],
                'field "path" to be base64 of a non-empty path',
            ],
            'missing symlink target' => [
                ['type' => 'symlink', 'operation_index' => 0, 'path' => base64_encode('link')],
                'Missing staged push stream frame field "target".',
            ],
            'directory file field' => [
                ['type' => 'directory', 'operation_index' => 0, 'path' => base64_encode('dir'), 'bytes' => 0],
                'Unexpected staged push stream frame field "bytes" for operation type "directory".',
            ],
            'file range beyond declared size' => [
                array_merge($file, ['offset' => 1, 'bytes' => 1]),
                'declares offset 1 and 1 payload bytes, which exceeds total_bytes 1',
            ],
            'zero payload before eof' => [
                array_merge($file, ['bytes' => 0, 'total_bytes' => 1]),
                'zero payload bytes must be positioned at total_bytes',
            ],
            'restart away from offset zero' => [
                array_merge($file, ['offset' => 1, 'bytes' => 0, 'restart' => true]),
                'field "restart" may be true only when offset is 0',
            ],
            'string revision' => [
                array_merge($file, ['revision' => '1']),
                'field "revision" to be a non-negative integer; received string "1"',
            ],
        ];
    }

    public function testJsonArrayIsNotAcceptedAsAnOperationObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected staged push stream frame header to be a JSON object.');

        Site_Export_Staged_Push_Stream_Protocol::decode_operation_header('[]');
    }

    public function testSenderRejectsFieldsThatDoNotBelongToTheOperationType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected staged push stream frame field "payload" for operation type "delete".');

        Site_Export_Staged_Push_Stream_Protocol::encode_operation_header([
            'type' => 'delete',
            'operation_index' => 0,
            'path' => 'old.txt',
            'payload' => '',
        ]);
    }

    public function testHeaderReaderHandlesLfCrLfAndCleanEof(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "one\nsecond\r\n");
        rewind($stream);

        $this->assertSame('one', Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream));
        $this->assertSame('second', Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream));
        $this->assertNull(Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream));

        fclose($stream);
    }

    public function testHeaderReaderRejectsAnUnterminatedHeader(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'truncated');
        rewind($stream);

        try {
            Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream);
            $this->fail('Expected the unterminated header to be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('ended before its LF terminator', $error->getMessage());
        } finally {
            fclose($stream);
        }
    }

    public function testHeaderReaderRejectsBeforeBufferingPastItsLimit(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "12345\nremaining");
        rewind($stream);

        try {
            Site_Export_Staged_Push_Stream_Protocol::read_header_line($stream, 4);
            $this->fail('Expected the oversized header to be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Staged push stream frame header exceeds 4 bytes.', $error->getMessage());
            $this->assertSame("\n", fread($stream, 1));
        } finally {
            fclose($stream);
        }
    }

    public function testReadExactlyUsesTheRequestedBoundedPayloadLength(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'abcde');
        rewind($stream);

        $this->assertSame('abc', Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, 3));
        $this->assertSame('de', Site_Export_Staged_Push_Stream_Protocol::read_exactly($stream, 2));

        fclose($stream);
    }
}
