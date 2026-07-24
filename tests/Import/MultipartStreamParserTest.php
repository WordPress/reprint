<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\MultipartStreamParser;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/protocol/class-multipart-stream-parser.php';

/**
 * Rigorous test suite for the MultipartStreamParser.
 *
 * Tests cover: normal operation, byte-at-a-time feeding, boundary splitting,
 * mixed line endings, binary payloads, empty parts, header edge cases,
 * bodies that look like boundaries, and the 64MB buffer overflow guard.
 */
class MultipartStreamParserTest extends TestCase
{
    /**
     * Collect all events emitted by the parser into an ArrayObject.
     * Returns [$events, $handler] — pass $handler to the constructor,
     * read $events like an array after parsing.
     */
    private function make_collector(): array
    {
        $events = new \ArrayObject();
        $handler = function (array $event) use ($events) {
            $events[] = $event;
        };
        return [$events, $handler];
    }

    /**
     * Build a complete multipart response string from an array of parts.
     * Each part is ["headers" => [...], "body" => "..."].
     * Uses \r\n line endings by default.
     */
    private function build_multipart(string $boundary, array $parts, string $eol = "\r\n"): string
    {
        $out = "";
        foreach ($parts as $part) {
            $out .= "--{$boundary}{$eol}";
            foreach ($part["headers"] ?? [] as $name => $value) {
                $out .= "{$name}: {$value}{$eol}";
            }
            $out .= $eol; // blank line separating headers from body
            $out .= $part["body"] ?? "";
            $out .= $eol;
        }
        $out .= "--{$boundary}--{$eol}";
        return $out;
    }

    /**
     * Reassemble the body of a specific part from the collected events.
     * Part index is 0-based. Each "complete" event ends a part.
     */
    private function get_part_body($events, int $part_index): string
    {
        $bodies = [];
        $current_body = "";
        foreach ($events as $event) {
            if ($event["type"] === "body") {
                $current_body .= $event["data"];
            } elseif ($event["type"] === "complete") {
                $bodies[] = $current_body;
                $current_body = "";
            }
        }
        return $bodies[$part_index] ?? "";
    }

    /**
     * Get the headers from the "complete" event for a specific part.
     */
    private function get_part_headers($events, int $part_index): array
    {
        $complete_count = 0;
        foreach ($events as $event) {
            if ($event["type"] === "complete") {
                if ($complete_count === $part_index) {
                    return $event["headers"];
                }
                $complete_count++;
            }
        }
        return [];
    }

    /**
     * Count completed parts from the events.
     */
    private function count_completed_parts($events): int
    {
        $count = 0;
        foreach ($events as $event) {
            if ($event["type"] === "complete") {
                $count++;
            }
        }
        return $count;
    }

    // ─── Basic operation ────────────────────────────────────────────

    public function testSinglePartWithContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("BOUNDARY", $handler);

        $data = $this->build_multipart("BOUNDARY", [
            ["headers" => ["Content-Length" => "5"], "body" => "hello"],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("hello", $this->get_part_body($events, 0));
    }

    public function testSinglePartWithoutContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("BOUNDARY", $handler);

        $data = $this->build_multipart("BOUNDARY", [
            ["headers" => ["X-Custom" => "yes"], "body" => "world"],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("world", $this->get_part_body($events, 0));
    }

    public function testMultipleParts(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("B", $handler);

        $data = $this->build_multipart("B", [
            ["headers" => ["Content-Length" => "1"], "body" => "A"],
            ["headers" => ["Content-Length" => "2"], "body" => "BC"],
            ["headers" => ["Content-Length" => "3"], "body" => "DEF"],
        ]);
        $parser->feed($data);

        $this->assertSame(3, $this->count_completed_parts($events));
        $this->assertSame("A", $this->get_part_body($events, 0));
        $this->assertSame("BC", $this->get_part_body($events, 1));
        $this->assertSame("DEF", $this->get_part_body($events, 2));
    }

    public function testHeadersAreLowercased(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("B", $handler);

        $data = $this->build_multipart("B", [
            ["headers" => ["Content-Type" => "text/plain", "X-CUSTOM-HEADER" => "val"], "body" => "x"],
        ]);
        $parser->feed($data);

        $headers = $this->get_part_headers($events, 0);
        $this->assertSame("text/plain", $headers["content-type"]);
        $this->assertSame("val", $headers["x-custom-header"]);
    }

    public function testHeaderValuePreservesTrailingSpaces(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("B", $handler);

        // Header value with trailing spaces — only leading whitespace is trimmed
        $raw = "--B\r\nX-Test:  value with trailing  \r\n\r\nbody\r\n--B--\r\n";
        $parser->feed($raw);

        $headers = $this->get_part_headers($events, 0);
        $this->assertSame("value with trailing  ", $headers["x-test"]);
    }

    // ─── Byte-at-a-time feeding (splits everywhere) ────────────────

    public function testByteAtATimeFeeding(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("XYZ", $handler);

        $data = $this->build_multipart("XYZ", [
            ["headers" => ["Content-Length" => "3"], "body" => "abc"],
            ["headers" => ["Content-Length" => "3"], "body" => "def"],
        ]);

        // Feed one byte at a time — the cruelest possible scenario
        for ($i = 0; $i < strlen($data); $i++) {
            $parser->feed($data[$i]);
        }

        $this->assertSame(2, $this->count_completed_parts($events));
        $this->assertSame("abc", $this->get_part_body($events, 0));
        $this->assertSame("def", $this->get_part_body($events, 1));
    }

    public function testByteAtATimeWithoutContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("XYZ", $handler);

        $data = $this->build_multipart("XYZ", [
            ["headers" => [], "body" => "no-cl-here"],
        ]);

        for ($i = 0; $i < strlen($data); $i++) {
            $parser->feed($data[$i]);
        }

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("no-cl-here", $this->get_part_body($events, 0));
    }

    // ─── Boundary splitting across feeds ────────────────────────────

    public function testBoundarySplitAcrossTwoFeeds(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("SPLIT", $handler);

        $data = $this->build_multipart("SPLIT", [
            ["headers" => ["Content-Length" => "4"], "body" => "data"],
        ]);

        // Split right in the middle of the closing boundary "--SPLIT--"
        $boundary_pos = strpos($data, "--SPLIT--");
        $this->assertNotFalse($boundary_pos);
        $split_point = $boundary_pos + 4; // mid-boundary

        $parser->feed(substr($data, 0, $split_point));
        $parser->feed(substr($data, $split_point));

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("data", $this->get_part_body($events, 0));
    }

    public function testRandomChunkSizes(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("RND", $handler);

        $body1 = str_repeat("X", 1000);
        $body2 = str_repeat("Y", 500);
        $data = $this->build_multipart("RND", [
            ["headers" => ["Content-Length" => (string)strlen($body1)], "body" => $body1],
            ["headers" => ["Content-Length" => (string)strlen($body2)], "body" => $body2],
        ]);

        // Feed in random-sized chunks between 1 and 17 bytes
        mt_srand(42);
        $offset = 0;
        while ($offset < strlen($data)) {
            $chunk_size = mt_rand(1, 17);
            $parser->feed(substr($data, $offset, $chunk_size));
            $offset += $chunk_size;
        }

        $this->assertSame(2, $this->count_completed_parts($events));
        $this->assertSame($body1, $this->get_part_body($events, 0));
        $this->assertSame($body2, $this->get_part_body($events, 1));
    }

    // ─── Line ending variants ───────────────────────────────────────

    public function testLfOnlyLineEndings(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("B", $handler);

        // Build manually with \n only
        $data = $this->build_multipart("B", [
            ["headers" => ["Content-Length" => "2"], "body" => "ok"],
        ], "\n");
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("ok", $this->get_part_body($events, 0));
    }

    public function testMixedLineEndingsAcrossParts(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("MIX", $handler);

        // First part uses \r\n, second uses \n
        $data = "--MIX\r\nContent-Length: 1\r\n\r\na\r\n--MIX\nContent-Length: 1\n\nb\n--MIX--\n";
        $parser->feed($data);

        $this->assertSame(2, $this->count_completed_parts($events));
        $this->assertSame("a", $this->get_part_body($events, 0));
        $this->assertSame("b", $this->get_part_body($events, 1));
    }

    // ─── Binary payloads ────────────────────────────────────────────

    public function testBinaryBodyWithContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("BIN", $handler);

        // Body contains \r\n, \n, null bytes, and high bytes
        $binary = "\x00\x01\r\n\n\xff\xfe--BIN\r\nfake boundary inside body";
        $data = $this->build_multipart("BIN", [
            ["headers" => ["Content-Length" => (string)strlen($binary)], "body" => $binary],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame($binary, $this->get_part_body($events, 0));
    }

    public function testBodyContainingBoundaryStringWithContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("TRAP", $handler);

        // The body itself contains "--TRAP" — with Content-Length it must not
        // be interpreted as a boundary delimiter
        $sneaky = "--TRAP\r\n--TRAP--\r\n--TRAP\r\nContent-Length: 0\r\n\r\n";
        $data = $this->build_multipart("TRAP", [
            ["headers" => ["Content-Length" => (string)strlen($sneaky)], "body" => $sneaky],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame($sneaky, $this->get_part_body($events, 0));
    }

    public function testNullBytesInBody(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("NUL", $handler);

        $body = "\x00\x00\x00\x00\x00";
        $data = $this->build_multipart("NUL", [
            ["headers" => ["Content-Length" => "5"], "body" => $body],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame($body, $this->get_part_body($events, 0));
    }

    // ─── Empty and zero-length parts ────────────────────────────────

    public function testEmptyBodyWithContentLengthZero(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("EMPTY", $handler);

        $data = "--EMPTY\r\nContent-Length: 0\r\n\r\n\r\n--EMPTY--\r\n";
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("", $this->get_part_body($events, 0));
    }

    public function testEmptyBodyWithoutContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("EMPTY2", $handler);

        $data = "--EMPTY2\r\nX-Info: empty\r\n\r\n\r\n--EMPTY2--\r\n";
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("", $this->get_part_body($events, 0));
    }

    public function testMultipleEmptyParts(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("E", $handler);

        $data = "--E\r\nContent-Length: 0\r\n\r\n\r\n--E\r\nContent-Length: 0\r\n\r\n\r\n--E\r\nContent-Length: 0\r\n\r\n\r\n--E--\r\n";
        $parser->feed($data);

        $this->assertSame(3, $this->count_completed_parts($events));
    }

    // ─── No headers at all ──────────────────────────────────────────

    public function testPartWithNoHeaders(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("NH", $handler);

        // Boundary followed immediately by blank line (no headers), then body
        $data = "--NH\r\n\r\nbare body\r\n--NH--\r\n";
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("bare body", $this->get_part_body($events, 0));
        $this->assertSame([], $this->get_part_headers($events, 0));
    }

    // ─── Header edge cases ──────────────────────────────────────────

    public function testHeaderWithColonInValue(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("HC", $handler);

        $data = "--HC\r\nX-Url: http://example.com:8080/path\r\nContent-Length: 1\r\n\r\nx\r\n--HC--\r\n";
        $parser->feed($data);

        $headers = $this->get_part_headers($events, 0);
        $this->assertSame("http://example.com:8080/path", $headers["x-url"]);
    }

    public function testDuplicateHeaderLastWins(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("DH", $handler);

        $data = "--DH\r\nX-Val: first\r\nX-Val: second\r\nContent-Length: 1\r\n\r\nx\r\n--DH--\r\n";
        $parser->feed($data);

        $headers = $this->get_part_headers($events, 0);
        $this->assertSame("second", $headers["x-val"]);
    }

    public function testHeaderWithNoValue(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("NV", $handler);

        // "X-Empty:" with nothing after the colon
        $data = "--NV\r\nX-Empty:\r\nContent-Length: 1\r\n\r\ny\r\n--NV--\r\n";
        $parser->feed($data);

        $headers = $this->get_part_headers($events, 0);
        $this->assertSame("", $headers["x-empty"]);
    }

    public function testMalformedHeaderLineWithoutColon(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("ML", $handler);

        // A line without a colon should be silently ignored
        $data = "--ML\r\nNot-A-Header\r\nContent-Length: 2\r\n\r\nok\r\n--ML--\r\n";
        $parser->feed($data);

        $headers = $this->get_part_headers($events, 0);
        $this->assertArrayNotHasKey("not-a-header", $headers);
        $this->assertSame("2", $headers["content-length"]);
        $this->assertSame("ok", $this->get_part_body($events, 0));
    }

    // ─── Large body streaming ───────────────────────────────────────

    public function testLargeBodyWithContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("LG", $handler);

        // 1MB body, fed in 8KB chunks
        $body = str_repeat("A", 1024 * 1024);
        $data = $this->build_multipart("LG", [
            ["headers" => ["Content-Length" => (string)strlen($body)], "body" => $body],
        ]);

        $chunk_size = 8192;
        $offset = 0;
        while ($offset < strlen($data)) {
            $parser->feed(substr($data, $offset, $chunk_size));
            $offset += $chunk_size;
        }

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame($body, $this->get_part_body($events, 0));
    }

    public function testLargeBodyWithoutContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("LG2", $handler);

        $body = str_repeat("B", 256 * 1024);
        $data = $this->build_multipart("LG2", [
            ["headers" => [], "body" => $body],
        ]);

        $chunk_size = 4096;
        $offset = 0;
        while ($offset < strlen($data)) {
            $parser->feed(substr($data, $offset, $chunk_size));
            $offset += $chunk_size;
        }

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame($body, $this->get_part_body($events, 0));
    }

    // ─── Garbage before the first boundary ──────────────────────────

    public function testPreambleBeforeFirstBoundary(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("PRE", $handler);

        // Per RFC 2046, anything before the first boundary is the preamble
        // and should be ignored
        $data = "This is a preamble that should be ignored.\r\n--PRE\r\nContent-Length: 3\r\n\r\nfoo\r\n--PRE--\r\n";
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("foo", $this->get_part_body($events, 0));
    }

    // ─── Closing boundary stops parsing ─────────────────────────────

    public function testDataAfterClosingBoundaryIsIgnored(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("CLOSE", $handler);

        $data = "--CLOSE\r\nContent-Length: 2\r\n\r\nhi\r\n--CLOSE--\r\ntrailing garbage\r\n--CLOSE\r\nContent-Length: 1\r\n\r\nx\r\n--CLOSE--\r\n";
        $parser->feed($data);

        // Only the first part before the closing boundary should be parsed
        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("hi", $this->get_part_body($events, 0));
    }

    // ─── Incomplete data (simulating a truncated response) ──────────

    public function testIncompleteResponseNoCrash(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("INC", $handler);

        // Feed just the boundary and headers but cut off mid-body
        $data = "--INC\r\nContent-Length: 1000\r\n\r\nonly a few bytes";
        $parser->feed($data);

        // No completed parts — body is still being assembled
        $this->assertSame(0, $this->count_completed_parts($events));
    }

    public function testIncompleteHeaders(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("IH", $handler);

        // Boundary found, but headers are cut off mid-line
        $parser->feed("--IH\r\nContent-Len");

        $this->assertSame(0, $this->count_completed_parts($events));

        // Now finish it
        $parser->feed("gth: 2\r\n\r\nab\r\n--IH--\r\n");
        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("ab", $this->get_part_body($events, 0));
    }

    // ─── Buffer overflow guard ──────────────────────────────────────

    public function testBufferOverflowThrows(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("OVF", $handler);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("64MB");

        // Feed a single chunk larger than 64MB in one call.
        // The parser checks buffer size at the start of feed() before
        // parsing, so a single oversized feed triggers the guard.
        $parser->feed(str_repeat("x", 65 * 1024 * 1024));
    }

    // ─── Boundary that is a substring of the body ───────────────────

    public function testBoundarySubstringInBodyWithoutContentLength(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("AB", $handler);

        // Body contains "--A" which is a prefix of "--AB" but not the full boundary.
        // Without Content-Length, the parser must not mistake it for the boundary.
        $data = "--AB\r\nX: 1\r\n\r\n--A is not the boundary\r\n--AB--\r\n";
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("--A is not the boundary", $this->get_part_body($events, 0));
    }

    // ─── Long boundary string ───────────────────────────────────────

    public function testVeryLongBoundary(): void
    {
        $boundary = str_repeat("x", 70); // RFC allows up to 70 chars
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser($boundary, $handler);

        $data = $this->build_multipart($boundary, [
            ["headers" => ["Content-Length" => "4"], "body" => "test"],
        ]);

        // Feed in small chunks to stress boundary detection
        $chunk_size = 13;
        $offset = 0;
        while ($offset < strlen($data)) {
            $parser->feed(substr($data, $offset, $chunk_size));
            $offset += $chunk_size;
        }

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("test", $this->get_part_body($events, 0));
    }

    // ─── Content-Length of exactly 0 followed by real content ───────

    public function testZeroContentLengthFollowedByNonEmptyPart(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("ZC", $handler);

        $data = "--ZC\r\nContent-Length: 0\r\n\r\n\r\n--ZC\r\nContent-Length: 5\r\n\r\nhello\r\n--ZC--\r\n";
        $parser->feed($data);

        $this->assertSame(2, $this->count_completed_parts($events));
        $this->assertSame("", $this->get_part_body($events, 0));
        $this->assertSame("hello", $this->get_part_body($events, 1));
    }

    // ─── Body containing every byte value (0x00–0xFF) ───────────────

    public function testAllByteValuesInBody(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("ALL", $handler);

        $body = "";
        for ($i = 0; $i < 256; $i++) {
            $body .= chr($i);
        }

        $data = $this->build_multipart("ALL", [
            ["headers" => ["Content-Length" => (string)strlen($body)], "body" => $body],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame($body, $this->get_part_body($events, 0));
    }

    // ─── Multiple feeds that each carry exactly one part ────────────

    public function testFeedingExactlyOnePartPerCall(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("SEQ", $handler);

        $parser->feed("--SEQ\r\nContent-Length: 1\r\n\r\na\r\n");
        $this->assertSame(1, $this->count_completed_parts($events));

        $parser->feed("--SEQ\r\nContent-Length: 1\r\n\r\nb\r\n");
        $this->assertSame(2, $this->count_completed_parts($events));

        $parser->feed("--SEQ--\r\n");
        $this->assertSame(2, $this->count_completed_parts($events));

        $this->assertSame("a", $this->get_part_body($events, 0));
        $this->assertSame("b", $this->get_part_body($events, 1));
    }

    // ─── Body events are streamed, not buffered ─────────────────────

    public function testBodyEventsAreStreamedIncrementally(): void
    {
        $body_chunks = [];
        $handler = function (array $event) use (&$body_chunks) {
            if ($event["type"] === "body") {
                $body_chunks[] = $event["data"];
            }
        };
        $parser = new MultipartStreamParser("STR", $handler);

        // Feed headers + start of body
        $parser->feed("--STR\r\nContent-Length: 10\r\n\r\n");
        $parser->feed("12345");
        $this->assertCount(1, $body_chunks, "First body chunk should have been emitted");
        $this->assertSame("12345", $body_chunks[0]);

        $parser->feed("67890");
        $this->assertCount(2, $body_chunks, "Second body chunk should have been emitted");
        $this->assertSame("67890", $body_chunks[1]);

        $parser->feed("\r\n--STR--\r\n");
    }

    // ─── Degenerate boundary that looks like MIME boilerplate ───────

    public function testBoundaryWithDashesAndNumbers(): void
    {
        $boundary = "----=_Part_123_456.789";
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser($boundary, $handler);

        $data = $this->build_multipart($boundary, [
            ["headers" => ["Content-Length" => "7"], "body" => "payload"],
        ]);
        $parser->feed($data);

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("payload", $this->get_part_body($events, 0));
    }

    // ─── Empty feed calls ───────────────────────────────────────────

    public function testEmptyFeedDoesNotBreakState(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("EF", $handler);

        $parser->feed("");
        $parser->feed("");
        $parser->feed("--EF\r\nContent-Length: 1\r\n\r\na\r\n");
        $parser->feed("");
        $parser->feed("--EF--\r\n");

        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("a", $this->get_part_body($events, 0));
    }

    // ─── Partial boundary at buffer edge ────────────────────────────

    public function testPartialClosingBoundaryAtBufferEdge(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("EDGE", $handler);

        // Feed body, then closing boundary split so "--EDGE" arrives but
        // the "--" suffix is in the next feed. The parser must wait for
        // more data before deciding.
        $parser->feed("--EDGE\r\nContent-Length: 3\r\n\r\nfoo\r\n--EDGE");
        // At this point the parser has seen "--EDGE" but doesn't know
        // if it's a closing "--EDGE--" or a new part "--EDGE\r\n".
        // No new part should have been emitted yet beyond the first.
        $this->assertSame(1, $this->count_completed_parts($events));

        $parser->feed("--\r\n");
        // Now the closing boundary is complete — still just 1 part
        $this->assertSame(1, $this->count_completed_parts($events));
        $this->assertSame("foo", $this->get_part_body($events, 0));
    }

    // ─── Body with \r\n that matches boundary line pattern ──────────

    public function testBodyEndingWithCrLfBeforeBoundary(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("CRLF", $handler);

        // Body is exactly "line1\r\nline2" — the \r\n before the next
        // boundary is a transport delimiter, not part of the body.
        // With Content-Length, the body should be exact.
        $body = "line1\r\nline2";
        $data = $this->build_multipart("CRLF", [
            ["headers" => ["Content-Length" => (string)strlen($body)], "body" => $body],
        ]);
        $parser->feed($data);

        $this->assertSame($body, $this->get_part_body($events, 0));
    }

    // ─── Many small parts (stress test) ─────────────────────────────

    public function testOneHundredParts(): void
    {
        [$events, $handler] = $this->make_collector();
        $parser = new MultipartStreamParser("MANY", $handler);

        $parts = [];
        for ($i = 0; $i < 100; $i++) {
            $parts[] = [
                "headers" => ["Content-Length" => (string)strlen("part$i"), "X-Index" => (string)$i],
                "body" => "part$i",
            ];
        }

        $data = $this->build_multipart("MANY", $parts);

        // Feed in 37-byte chunks (a prime number, to misalign with everything)
        $offset = 0;
        while ($offset < strlen($data)) {
            $parser->feed(substr($data, $offset, 37));
            $offset += 37;
        }

        $this->assertSame(100, $this->count_completed_parts($events));
        $this->assertSame("part0", $this->get_part_body($events, 0));
        $this->assertSame("part99", $this->get_part_body($events, 99));
    }
}
