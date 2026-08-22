<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartProcessorTest extends TestCase {

    public function testProcessesBinaryPartsAtEveryPossibleSingleSplit(): void {
        $boundary = 'split-boundary';
        $binary = implode('', array_map('chr', range(0, 255)))
            . "\r\n--{$boundary}\r\ninside\r\n";
        $message = $this->multipart($boundary, [
            [
                'headers' => ['X-Chunk-Type' => 'file'],
                'body' => $binary,
            ],
            [
                'headers' => ['X-Chunk-Type' => 'directory'],
                'body' => '',
            ],
        ]);

        $message_bytes = strlen($message);
        for ($split = 1; $split < $message_bytes; ++$split) {
            $parts = $this->collect_parts($boundary, [
                substr($message, 0, $split),
                substr($message, $split),
            ]);
            $this->assertSame($binary, $parts[0]['body'], 'Failed at byte split ' . $split . '.');
            $this->assertSame('file', $parts[0]['headers']['x-chunk-type']);
            $this->assertSame('', $parts[1]['body']);
            $this->assertSame('directory', $parts[1]['headers']['x-chunk-type']);
        }
    }

    public function testEmitsLargeBodiesInBoundedPiecesWithoutWaitingForPartEnd(): void {
        $boundary = 'large';
        $body = str_repeat('0123456789abcdef', 65536);
        $message = $this->multipart($boundary, [[
            'headers' => ['X-Chunk-Type' => 'file'],
            'body' => $body,
        ]]);
        $processor = new \WordPress\Reprint\Server\MultipartProcessor($boundary);
        $body_bytes = 0;
        $body_tokens = 0;
        $largest_token = 0;
        $message_bytes = strlen($message);
        for ($offset = 0; $offset < $message_bytes; $offset += 8192) {
            $processor->append_bytes(substr($message, $offset, 8192));
            while ($processor->next_token()) {
                if ($processor->get_token_type() !== \WordPress\Reprint\Server\MultipartProcessor::TOKEN_BODY) {
                    continue;
                }
                $piece = $processor->get_current_body_piece();
                $body_bytes += strlen($piece);
                ++$body_tokens;
                $largest_token = max($largest_token, strlen($piece));
            }
        }
        $processor->finish_input();

        $this->assertSame(strlen($body), $body_bytes);
        $this->assertGreaterThan(1, $body_tokens);
        $this->assertLessThanOrEqual(
            \WordPress\Reprint\Server\MultipartProcessor::MAX_INPUT_FRAGMENT_BYTES,
            $largest_token
        );
    }

    public function testUnfoldsHeadersSplitOneByteAtATime(): void {
        $message = "--x\r\n"
            . "X-Description: first\r\n"
            . " second\r\n"
            . "\tthird\r\n"
            . "Content-Length:\r\n"
            . " 3\r\n\r\n"
            . "abc\r\n--x--\r\n";
        $fragments = str_split($message, 1);
        $parts = $this->collect_parts('x', $fragments);

        $this->assertSame("first second\tthird", $parts[0]['headers']['x-description']);
        $this->assertSame('3', $parts[0]['headers']['content-length']);
        $this->assertSame('abc', $parts[0]['body']);
    }

    public function testClosingBoundaryWithoutPartsIsComplete(): void {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('empty');
        $processor->append_bytes("--empty--\r\n");

        $this->assertFalse($processor->next_token());
        $this->assertTrue($processor->is_complete());
        $this->assertFalse($processor->paused_at_incomplete_input());
        $processor->finish_input();
    }

    public function testPauseAndResumeStatesDistinguishIncompleteInputFromCompletion(): void {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('pause');
        $processor->append_bytes('--pau');

        $this->assertFalse($processor->next_token());
        $this->assertTrue($processor->paused_at_incomplete_input());
        $this->assertFalse($processor->is_complete());

        $processor->append_bytes("se--\r\n");
        $this->assertFalse($processor->next_token());
        $this->assertFalse($processor->paused_at_incomplete_input());
        $this->assertTrue($processor->is_complete());
    }

    public function testParsesOnlyValidatedMultipartMixedContentTypes(): void {
        $this->assertSame(
            'plain-boundary',
            \WordPress\Reprint\Server\MultipartProcessor::boundary_from_content_type(
                'multipart/mixed; boundary=plain-boundary'
            )
        );
        $this->assertSame(
            'quoted-boundary',
            \WordPress\Reprint\Server\MultipartProcessor::boundary_from_content_type(
                'Multipart/Mixed; charset=binary; boundary="quoted-boundary"'
            )
        );

        try {
            \WordPress\Reprint\Server\MultipartProcessor::boundary_from_content_type('multipart/form-data; boundary=x');
            $this->fail('A non-multipart/mixed media type was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Expected Content-Type multipart/mixed', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than one boundary');
        \WordPress\Reprint\Server\MultipartProcessor::boundary_from_content_type(
            'multipart/mixed; boundary=one; boundary=two'
        );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function invalid_content_types(): array {
        return [
            'missing boundary' => ['multipart/mixed; charset=binary', 'requires a non-empty boundary'],
            'empty boundary' => ['multipart/mixed; boundary=""', 'requires a non-empty boundary'],
            'unsafe boundary' => ['multipart/mixed; boundary="line break"', 'unsupported characters'],
            'oversized boundary' => ['multipart/mixed; boundary=' . str_repeat('x', 71), 'between 1 and 70 bytes'],
        ];
    }

    #[DataProvider('invalid_content_types')]
    public function testRejectsInvalidMultipartMixedBoundaries(string $content_type, string $expected_message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected_message);
        \WordPress\Reprint\Server\MultipartProcessor::boundary_from_content_type($content_type);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function invalid_grammar(): array {
        return [
            'bare LF' => ["--x\nContent-Length: 0\n\n--x--\n", 'CRLF'],
            'missing content length' => ["--x\r\nX-Test: value\r\n\r\nbody\r\n--x--\r\n", 'Content-Length'],
            'non-numeric content length' => ["--x\r\nContent-Length: unknown\r\n\r\n", 'Content-Length'],
            'negative content length' => ["--x\r\nContent-Length: -1\r\n\r\n", 'Content-Length'],
            'duplicate content length' => ["--x\r\nContent-Length: 0\r\nContent-Length: 1\r\n\r\n", 'repeats header'],
            'malformed header' => ["--x\r\nNot-A-Header\r\nContent-Length: 0\r\n\r\n\r\n--x--\r\n", 'Malformed'],
            'invalid header name' => ["--x\r\nBad Name: value\r\nContent-Length: 0\r\n\r\n\r\n--x--\r\n", 'invalid header name'],
            'whitespace before colon' => ["--x\r\nX-Test : value\r\nContent-Length: 0\r\n\r\n\r\n--x--\r\n", 'invalid header name'],
            'control byte before length' => ["--x\r\nContent-Length:\0 0\r\n\r\n\r\n--x--\r\n", 'Content-Length'],
            'extra body byte before boundary' => ["--x\r\nContent-Length: 2\r\n\r\nabc\r\n--x--\r\n", 'followed by CRLF'],
            'preamble' => ["not multipart\r\n--x--\r\n", 'Expected multipart boundary'],
            'trailing data' => ["--x--\r\nnot an epilogue", 'after the closing boundary'],
        ];
    }

    #[DataProvider('invalid_grammar')]
    public function testRejectsFormsOutsideTheReprintGrammar(string $message, string $expected_message): void {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes($message);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected_message);
        while ($processor->next_token()) {
            $processor->get_token_type();
        }
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function truncated_messages(): array {
        return [
            'opening boundary' => ['--x', 'closing boundary'],
            'header block' => ["--x\r\nContent-Len", 'header block'],
            'part body' => ["--x\r\nContent-Length: 4\r\n\r\nab", '2 bytes remain'],
            'separator after body' => ["--x\r\nContent-Length: 2\r\n\r\nab", 'CRLF and boundary'],
            'closing boundary' => ["--x\r\nContent-Length: 0\r\n\r\n\r\n--x", 'closing boundary'],
        ];
    }

    #[DataProvider('truncated_messages')]
    public function testFinishInputRejectsEveryIncompleteState(string $message, string $expected_message): void {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes($message);
        while ($processor->next_token()) {
            $processor->get_token_type();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected_message);
        $processor->finish_input();
    }

    public function testRejectsAContinuationBeforeAHeaderAndBoundsTheAggregate(): void {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes("--x\r\n orphan\r\nContent-Length: 0\r\n\r\n");
        try {
            while ($processor->next_token()) {
                $processor->get_token_type();
            }
            $this->fail('An orphan header continuation was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('continuation', $exception->getMessage());
        }

        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $message = "--x\r\nX-Large: " . str_repeat('a', 8100) . "\r\n"
            . str_repeat(' ' . str_repeat('b', 8100) . "\r\n", 4)
            . "Content-Length: 0\r\n\r\n";
        $message_bytes = strlen($message);
        for ($offset = 0; $offset < $message_bytes; $offset += 8192) {
            $processor->append_bytes(substr($message, $offset, 8192));
            try {
                while ($processor->next_token()) {
                    $processor->get_token_type();
                }
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('headers exceed 32768 bytes', $exception->getMessage());
                return;
            }
        }
        $this->fail('An aggregate header block beyond 32768 bytes was accepted.');
    }

    public function testAcceptsHeaderBoundsExactlyAndRejectsOneByteOrOneHeaderMore(): void {
        $header_prefix = 'X-Large: ';
        $maximum_line = $header_prefix . str_repeat('a', 8192 - strlen($header_prefix));
        $parts = $this->collect_parts('x', [
            "--x\r\n{$maximum_line}\r\nContent-Length: 0\r\n\r\n\r\n--x--\r\n",
        ]);
        $this->assertSame(8192 - strlen($header_prefix), strlen($parts[0]['headers']['x-large']));

        $maximum_aggregate_headers = 'X-A: ' . str_repeat('a', 8187) . "\r\n"
            . ' ' . str_repeat('b', 8191) . "\r\n"
            . ' ' . str_repeat('c', 8191) . "\r\n"
            . ' ' . str_repeat('d', 8162) . "\r\n"
            . "Content-Length: 0\r\n\r\n";
        $parts = $this->collect_parts('x', [
            "--x\r\n" . $maximum_aggregate_headers . "\r\n--x--\r\n",
        ]);
        $this->assertArrayHasKey('x-a', $parts[0]['headers']);

        $headers = [];
        for ($header = 0; $header < 31; ++$header) {
            $headers['X-Header-' . $header] = 'value';
        }
        $parts = $this->collect_parts('x', [$this->multipart('x', [[
            'headers' => $headers,
            'body' => '',
        ]])]);
        $this->assertCount(32, $parts[0]['headers']);

        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes(
            "--x\r\n" . $maximum_line . "a\r\nContent-Length: 0\r\n\r\n"
        );
        try {
            while ($processor->next_token()) {
                $processor->get_token_type();
            }
            $this->fail('A physical header line one byte above the limit was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exceeds 8192 bytes', $exception->getMessage());
        }

        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes(
            "--x\r\n" . str_replace(str_repeat('d', 8162), str_repeat('d', 8163), $maximum_aggregate_headers)
        );
        try {
            while ($processor->next_token()) {
                $processor->get_token_type();
            }
            $this->fail('A header block one byte above the aggregate limit was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('headers exceed 32768 bytes', $exception->getMessage());
        }

        $headers['X-Header-31'] = 'value';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than 32 headers');
        $this->collect_parts('x', [$this->multipart('x', [[
            'headers' => $headers,
            'body' => '',
        ]])]);
    }

    public function testRequiresEachAppendedFragmentToBeDrainedAndBounded(): void {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes('--x');
        try {
            $processor->append_bytes("--\r\n");
            $this->fail('A second fragment was accepted before the first was drained.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('next_token', $exception->getMessage());
        }

        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes(
            "--x\r\nContent-Length: "
            . \WordPress\Reprint\Server\MultipartProcessor::MAX_INPUT_FRAGMENT_BYTES
            . "\r\n\r\n"
        );
        $this->assertTrue($processor->next_token());
        $this->assertSame(\WordPress\Reprint\Server\MultipartProcessor::TOKEN_PART_START, $processor->get_token_type());
        $this->assertFalse($processor->next_token());
        $processor->append_bytes(str_repeat('a', \WordPress\Reprint\Server\MultipartProcessor::MAX_INPUT_FRAGMENT_BYTES));
        $this->assertTrue($processor->next_token());
        $this->assertSame(
            \WordPress\Reprint\Server\MultipartProcessor::MAX_INPUT_FRAGMENT_BYTES,
            strlen($processor->get_current_body_piece())
        );
        $this->assertTrue($processor->next_token());
        $this->assertSame(\WordPress\Reprint\Server\MultipartProcessor::TOKEN_PART_END, $processor->get_token_type());
        $this->assertFalse($processor->next_token());
        $processor->append_bytes("\r\n--x--\r\n");
        $this->assertFalse($processor->next_token());
        $processor->finish_input();

        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage( (string) \WordPress\Reprint\Server\MultipartProcessor::MAX_INPUT_FRAGMENT_BYTES);
        $processor->append_bytes(str_repeat('a', \WordPress\Reprint\Server\MultipartProcessor::MAX_INPUT_FRAGMENT_BYTES + 1));
    }

    public function testRejectsContentLengthOutsideTheRuntimeIntegerRange(): void {
        $too_large = (string) PHP_INT_MAX . '0';
        $processor = new \WordPress\Reprint\Server\MultipartProcessor('x');
        $processor->append_bytes("--x\r\nContent-Length: {$too_large}\r\n\r\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integer range');
        while ($processor->next_token()) {
            $processor->get_token_type();
        }
    }

    /**
     * Collects complete parts while feeding caller-selected transport fragments.
     *
     * @param string[] $fragments Raw input fragments in wire order.
     * @return array<int,array{headers:array<string,string>,body:string}>
     */
    private function collect_parts(string $boundary, array $fragments): array {
        $processor = new \WordPress\Reprint\Server\MultipartProcessor($boundary);
        $parts = [];
        $current = null;
        foreach ($fragments as $fragment) {
            $processor->append_bytes($fragment);
            while ($processor->next_token()) {
                $token_type = $processor->get_token_type();
                if ($token_type === \WordPress\Reprint\Server\MultipartProcessor::TOKEN_PART_START) {
                    $current = [
                        'headers' => $processor->get_current_headers(),
                        'body' => '',
                    ];
                } elseif ($token_type === \WordPress\Reprint\Server\MultipartProcessor::TOKEN_BODY) {
                    $current['body'] .= $processor->get_current_body_piece();
                } elseif ($token_type === \WordPress\Reprint\Server\MultipartProcessor::TOKEN_PART_END) {
                    $parts[] = $current;
                    $current = null;
                }
            }
        }
        $processor->finish_input();
        return $parts;
    }

    /**
     * Builds the canonical Reprint multipart form with a length on every part.
     *
     * @param array<int,array{headers:array<string,string>,body:string}> $parts
     */
    private function multipart(string $boundary, array $parts): string {
        $message = '';
        foreach ($parts as $part) {
            $message .= '--' . $boundary . "\r\n";
            foreach ($part['headers'] as $name => $value) {
                $message .= $name . ': ' . $value . "\r\n";
            }
            $message .= 'Content-Length: ' . strlen($part['body']) . "\r\n\r\n";
            $message .= $part['body'] . "\r\n";
        }
        return $message . '--' . $boundary . "--\r\n";
    }
}
