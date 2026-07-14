<?php

use PHPUnit\Framework\TestCase;

final class MultipartStreamInputTest extends TestCase {

    /** @return resource */
    private function input(string $body) {
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        return $input;
    }

    public function testReadsBinaryPartsInBoundedCallerDrivenPieces(): void {
        $boundary = 'reprint-boundary';
        $body = '--' . $boundary . "\r\n"
            . "X-Chunk-Type: file\r\n"
            . "Content-Length: 3\r\n\r\n"
            . "a\0b\r\n"
            . '--' . $boundary . "\r\n"
            . "X-Chunk-Type: directory\r\n"
            . "Content-Length: 0\r\n\r\n"
            . "\r\n--" . $boundary . "--\r\n";
        $input = $this->input($body);
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, $boundary);
            $this->assertTrue($reader->next_part());
            $this->assertSame('file', $reader->get_current_headers()['x-chunk-type']);
            $this->assertSame(3, $reader->remaining_body_bytes());
            $this->assertSame('a' . "\0", $reader->read_body_piece(2));
            $this->assertSame('b', $reader->read_body_piece(2));
            $this->assertSame('', $reader->read_body_piece(2));

            $this->assertTrue($reader->next_part());
            $this->assertSame(0, $reader->remaining_body_bytes());
            $this->assertFalse($reader->next_part());
        } finally {
            fclose($input);
        }
    }

    public function testUnfoldsMultipartPartHeadersBeforeValidation(): void {
        $input = $this->input(
            "--x\r\n"
            . "X-Description: first\r\n"
            . " second\r\n"
            . "\tthird\r\n"
            . "Content-Length:\r\n"
            . " 3\r\n\r\n"
            . "abc\r\n--x--\r\n"
        );
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, 'x');

            $this->assertTrue($reader->next_part());
            $this->assertSame("first second\tthird", $reader->get_current_headers()['x-description']);
            $this->assertSame('3', $reader->get_current_headers()['content-length']);
            $this->assertSame('abc', $reader->read_body_piece(3));
            $this->assertFalse($reader->next_part());
        } finally {
            fclose($input);
        }
    }

    public function testHeaderLineCanArriveAcrossSeveralShortReads(): void {
        $scheme = 'multipart-short-read';
        $wrapper = new class() {

            /** @var resource|null */
            public $context;

            /** @var string Bytes exposed by the next opened stream. */
            public static $body = '';

            /** @var int Current byte in the configured body. */
            private $offset = 0;

            /** @var bool Whether the next wrapper read should report no progress. */
            private $pause_next_read = false;

            public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
                unset($path, $mode, $options, $opened_path);
                return true;
            }

            public function stream_read(int $count): string {
                if ($this->pause_next_read) {
                    $this->pause_next_read = false;
                    return '';
                }
                $piece = substr(self::$body, $this->offset, min(1, $count));
                $this->offset += strlen($piece);
                $this->pause_next_read = $piece !== '' && $piece !== "\n";
                return $piece;
            }

            public function stream_eof(): bool {
                return $this->offset >= strlen(self::$body);
            }

            /** @return array<string,int> */
            public function stream_stat(): array {
                return [];
            }
        };
        $wrapper_class = get_class($wrapper);
        $wrapper_class::$body = "--short\r\nX-Test: complete line\r\nContent-Length: 0\r\n\r\n";
        $this->assertTrue(stream_wrapper_register($scheme, $wrapper_class));
        $input = fopen($scheme . '://input', 'rb');
        try {
            $this->assertIsResource($input);
            $reader = new Site_Export_Multipart_Stream_Input($input, 'short');

            $this->assertTrue($reader->next_part());
            $this->assertSame('complete line', $reader->get_current_headers()['x-test']);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            stream_wrapper_unregister($scheme);
        }
    }

    public function testRejectsAContinuationBeforeAnyHeaderField(): void {
        $input = $this->input("--x\r\n orphan\r\nContent-Length: 0\r\n\r\n");
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, 'x');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('continuation');
            $reader->next_part();
        } finally {
            fclose($input);
        }
    }

    public function testContinuationLinesDoNotConsumeTheLogicalHeaderCount(): void {
        $input = $this->input(
            "--x\r\nX-Many: first\r\n"
            . str_repeat(" next\r\n", 40)
            . "Content-Length: 0\r\n\r\n"
        );
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, 'x');

            $this->assertTrue($reader->next_part());
            $this->assertSame('first' . str_repeat(' next', 40), $reader->get_current_headers()['x-many']);
        } finally {
            fclose($input);
        }
    }

    public function testContinuationLinesCountTowardTheAggregateHeaderLimit(): void {
        $input = $this->input(
            "--x\r\nX-Large: " . str_repeat('a', 8100) . "\r\n"
            . str_repeat(' ' . str_repeat('b', 8100) . "\r\n", 4)
            . "Content-Length: 0\r\n\r\n"
        );
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, 'x');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('headers exceed 32768 bytes');
            $reader->next_part();
        } finally {
            fclose($input);
        }
    }

    public function testCannotAdvanceUntilTheCurrentBodyIsDrained(): void {
        $input = $this->input("--x\r\nContent-Length: 1\r\n\r\na\r\n--x--\r\n");
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, 'x');
            $this->assertTrue($reader->next_part());
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Read or discard');
            $reader->next_part();
        } finally {
            fclose($input);
        }
    }

    public function testRejectsMissingPartLengthAndTruncatedBodies(): void {
        $missing_length = $this->input("--x\r\nX-Chunk-Type: file\r\n\r\na\r\n--x--\r\n");
        try {
            $reader = new Site_Export_Multipart_Stream_Input($missing_length, 'x');
            try {
                $reader->next_part();
                $this->fail('A multipart push part without Content-Length was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Content-Length', $exception->getMessage());
            }
        } finally {
            fclose($missing_length);
        }

        $truncated = $this->input("--x\r\nContent-Length: 4\r\n\r\nab");
        try {
            $reader = new Site_Export_Multipart_Stream_Input($truncated, 'x');
            $this->assertTrue($reader->next_part());
            $this->assertSame('ab', $reader->read_body_piece(4));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ended before its declared Content-Length');
            $reader->read_body_piece(4);
        } finally {
            fclose($truncated);
        }
    }

    public function testParsesOnlyMultipartMixedContentTypes(): void {
        $this->assertSame('quoted-boundary', Site_Export_Multipart_Stream_Input::boundary_from_content_type('multipart/mixed; boundary="quoted-boundary"'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected Content-Type multipart/mixed');
        Site_Export_Multipart_Stream_Input::boundary_from_content_type('multipart/form-data; boundary=x');
    }

    public function testRejectsAReaderRequestThatWouldBufferMoreThanOneBoundedPiece(): void {
        $input = $this->input("--x\r\nContent-Length: 1\r\n\r\na\r\n--x--\r\n");
        try {
            $reader = new Site_Export_Multipart_Stream_Input($input, 'x');
            $this->assertTrue($reader->next_part());
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('262144');
            $reader->read_body_piece(262145);
        } finally {
            fclose($input);
        }
    }
}
