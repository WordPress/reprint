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
