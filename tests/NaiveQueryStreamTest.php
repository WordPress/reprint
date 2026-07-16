<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../packages/reprint-importer/src/lib/mysql-query-stream/load.php';

class NaiveQueryStreamTest extends TestCase
{
    /**
     * Simulate the db-apply streaming loop: read a file in chunks, feed to
     * the query stream, extract queries one at a time. Return the extracted
     * queries and the resume-safe byte offset after each one.
     *
     * @param string $sql        Full SQL content (simulates a file).
     * @param int    $chunk_size How many bytes per fread() call.
     * @param int    $seek       Starting byte offset (simulates fseek on resume).
     * @param int    $limit      Stop after extracting this many queries (0 = all).
     * @return array {
     *     Streamed query result.
     *
     *     @type array $queries        Parsed queries.
     *     @type int   $bytes_consumed Cumulative bytes consumed.
     * }
     * @phpstan-return array{queries: string[], bytes_consumed: int}
     */
    private function streamQueries(
        string $sql,
        int $chunk_size,
        int $seek = 0,
        int $limit = 0,
    ): array {
        $stream = new WP_MySQL_Naive_Query_Stream();
        $pos = $seek;
        $queries = [];

        while ($pos < strlen($sql)) {
            $data = substr($sql, $pos, $chunk_size);
            $pos += strlen($data);
            $stream->append_sql($data);

            while ($stream->next_query()) {
                $queries[] = $stream->get_query();
                if ($limit > 0 && count($queries) >= $limit) {
                    return [
                        'queries' => $queries,
                        'bytes_consumed' => $seek + $stream->get_bytes_consumed(),
                    ];
                }
            }
        }

        $stream->mark_input_complete();
        while ($stream->next_query()) {
            $queries[] = $stream->get_query();
        }

        return [
            'queries' => $queries,
            'bytes_consumed' => $seek + $stream->get_bytes_consumed(),
        ];
    }

    public function testBytesConsumedTracksExtractedQueriesOnly(): void
    {
        $sql = "SELECT 1;\nSELECT 2;\nSELECT 3;\n";

        $stream = new WP_MySQL_Naive_Query_Stream();

        // Append the entire SQL at once. The buffer holds all of it.
        $stream->append_sql($sql);

        // Extract the first query — only its bytes should be counted.
        // The consumed offset lands right after the semicolon. Any
        // inter-query whitespace (\n) stays in the buffer until the next
        // query is extracted — that's correct, those bytes belong to the
        // next extraction.
        $this->assertTrue($stream->next_query());
        $this->assertStringContainsString('SELECT 1', $stream->get_query());
        $after_first = $stream->get_bytes_consumed();

        // Resuming from this offset should yield all remaining queries
        $this->assertStringContainsString("SELECT 2", substr($sql, $after_first));

        // Extract the second query
        $this->assertTrue($stream->next_query());
        $this->assertStringContainsString('SELECT 2', $stream->get_query());
        $after_second = $stream->get_bytes_consumed();
        $this->assertStringContainsString("SELECT 3", substr($sql, $after_second));
    }

    /**
     * Core resume test: split a file into two "sessions" by extracting some
     * queries, recording the byte offset, then starting a fresh stream from
     * that offset and verifying we get the remaining queries intact.
     */
    public function testResumeFromBytesConsumedOffset(): void
    {
        $sql = "INSERT INTO t VALUES(1);\nINSERT INTO t VALUES(2);\nINSERT INTO t VALUES(3);\n";

        // Session 1: extract the first query, record the resume point
        $session1 = $this->streamQueries($sql, chunk_size: 64 * 1024, limit: 1);
        $this->assertCount(1, $session1['queries']);
        $this->assertStringContainsString('VALUES(1)', $session1['queries'][0]);

        // Session 2: resume from the saved offset — should get the remaining queries
        $session2 = $this->streamQueries($sql, chunk_size: 64 * 1024, seek: $session1['bytes_consumed']);
        $this->assertCount(2, $session2['queries']);
        $this->assertStringContainsString('VALUES(2)', $session2['queries'][0]);
        $this->assertStringContainsString('VALUES(3)', $session2['queries'][1]);
    }

    /**
     * The actual bug scenario: a small chunk size causes the fread boundary
     * to fall in the middle of a query. total_bytes_read (the fread counter)
     * overshoots past the unconsumed buffer, so resuming from total_bytes_read
     * would lose the beginning of the next query.
     *
     * With the fix, bytes_consumed only counts fully extracted queries, so the
     * resume offset is always safe.
     */
    public function testResumeMidQueryChunkBoundary(): void
    {
        // Three queries, ~24 bytes each
        $q1 = "INSERT INTO t VALUES(1);\n";
        $q2 = "INSERT INTO t VALUES(2);\n";
        $q3 = "INSERT INTO t VALUES(3);\n";
        $sql = $q1 . $q2 . $q3;

        // Use a chunk size of 30 bytes. After reading the first chunk (bytes
        // 0-29), the buffer contains all of q1 plus the first 5 bytes of q2.
        // Extracting q1 leaves those 5 bytes unconsumed.
        //
        // total_bytes_read = 30 (what the old code would have saved)
        // bytes_consumed   = 25 (what we should actually save)
        $chunk_size = 30;

        $stream = new WP_MySQL_Naive_Query_Stream();
        $total_bytes_read = 0;

        // Read first chunk
        $chunk = substr($sql, 0, $chunk_size);
        $total_bytes_read += strlen($chunk);
        $stream->append_sql($chunk);

        // Extract the first query
        $this->assertTrue($stream->next_query());
        $this->assertStringContainsString('VALUES(1)', $stream->get_query());

        // At this point the old code would save total_bytes_read
        $bad_offset = $total_bytes_read;

        // The correct resume point: only count bytes from extracted queries
        $good_offset = $stream->get_bytes_consumed();

        // The bad offset overshoots — it includes buffered bytes from q2
        $this->assertGreaterThan($good_offset, $bad_offset);

        // Resuming from the BAD offset loses the start of q2
        $remaining_bad = substr($sql, $bad_offset);
        $this->assertStringNotContainsString('INSERT INTO t VALUES(2)', $remaining_bad,
            'Bug: resuming from total_bytes_read loses the start of the next query');

        // Resuming from the GOOD offset preserves q2 intact (possibly with
        // leading whitespace from the inter-query newline, which is fine)
        $remaining_good = substr($sql, $good_offset);
        $this->assertStringContainsString('INSERT INTO t VALUES(2)', $remaining_good,
            'Fix: resuming from bytes_consumed preserves the next query');

        // Full round-trip: resume from the good offset and get both remaining queries
        $session2 = $this->streamQueries($sql, chunk_size: $chunk_size, seek: $good_offset);
        $this->assertCount(2, $session2['queries']);
        $this->assertStringContainsString('VALUES(2)', $session2['queries'][0]);
        $this->assertStringContainsString('VALUES(3)', $session2['queries'][1]);
    }

    /**
     * When multiple queries fit in one chunk and we stop mid-batch, only
     * the extracted queries count toward bytes_consumed.
     */
    public function testBytesConsumedWithMultipleQueriesPerChunk(): void
    {
        $sql = "SELECT 1;\nSELECT 2;\nSELECT 3;\nSELECT 4;\nSELECT 5;\n";

        // Extract 3 queries, then resume and get the remaining 2
        $session1 = $this->streamQueries($sql, chunk_size: 64 * 1024, limit: 3);
        $this->assertCount(3, $session1['queries']);

        $session2 = $this->streamQueries($sql, chunk_size: 64 * 1024, seek: $session1['bytes_consumed']);
        $this->assertCount(2, $session2['queries']);
        $this->assertStringContainsString('SELECT 4', $session2['queries'][0]);
        $this->assertStringContainsString('SELECT 5', $session2['queries'][1]);
    }

    /**
     * Comments and whitespace between queries are consumed as part of the
     * next query's extraction — they don't create gaps in bytes_consumed.
     */
    public function testBytesConsumedIncludesInterQueryWhitespaceAndComments(): void
    {
        $sql = "SELECT 1;\n\n-- This is a comment\n\nSELECT 2;\n";

        $session1 = $this->streamQueries($sql, chunk_size: 64 * 1024, limit: 1);
        $session2 = $this->streamQueries($sql, chunk_size: 64 * 1024, seek: $session1['bytes_consumed']);
        $this->assertCount(1, $session2['queries']);
        $this->assertStringContainsString('SELECT 2', $session2['queries'][0]);
    }

    /**
     * After extracting all queries, bytes_consumed covers all query bytes.
     * Trailing whitespace after the last semicolon is NOT consumed (it's not
     * a query), so bytes_consumed may be less than the total input length.
     * That's fine — resuming from there just re-reads harmless whitespace.
     */
    public function testBytesConsumedAfterFullDrain(): void
    {
        // No trailing whitespace: bytes_consumed == input length
        $sql_no_trail = "SELECT 1;SELECT 2;";
        $result = $this->streamQueries($sql_no_trail, chunk_size: 64 * 1024);
        $this->assertCount(2, $result['queries']);
        $this->assertSame(strlen($sql_no_trail), $result['bytes_consumed']);

        // With trailing newline: bytes_consumed < input length (the \n stays)
        $sql_with_trail = "SELECT 1;\nSELECT 2;\n";
        $result = $this->streamQueries($sql_with_trail, chunk_size: 64 * 1024);
        $this->assertCount(2, $result['queries']);
        $this->assertLessThanOrEqual(strlen($sql_with_trail), $result['bytes_consumed']);
        // But it should be close — at most a newline behind
        $this->assertGreaterThan(strlen($sql_with_trail) - 2, $result['bytes_consumed']);
    }

    /**
     * Large queries that span multiple chunks: bytes_consumed is only updated
     * after the full query is extracted, not after each chunk is appended.
     */
    public function testLargeQuerySpanningMultipleChunks(): void
    {
        // A query larger than the chunk size
        $big_value = str_repeat('x', 200);
        $q1 = "INSERT INTO t VALUES('{$big_value}');\n";
        $q2 = "SELECT 1;\n";
        $sql = $q1 . $q2;

        // Chunk size 50: the big query spans 4+ chunks
        $session1 = $this->streamQueries($sql, chunk_size: 50, limit: 1);
        $this->assertCount(1, $session1['queries']);

        // Resume should land exactly at q2
        $session2 = $this->streamQueries($sql, chunk_size: 50, seek: $session1['bytes_consumed']);
        $this->assertCount(1, $session2['queries']);
        $this->assertStringContainsString('SELECT 1', $session2['queries'][0]);
    }

    /**
     * Simulate the full db-apply read→extract→save→resume cycle, proving
     * that total_bytes_read (the fread counter) is NOT a safe resume point
     * while bytes_consumed IS.
     *
     * This test does not depend on get_bytes_consumed() for the "old approach"
     * path — it reproduces the bug using only fread-style counting.
     */
    public function testOldBytesReadApproachCorruptsResumeWhileNewOneWorks(): void
    {
        // Three 25-byte queries. Chunk size 30 means the first chunk reads
        // all of q1 plus 5 bytes of q2.
        $q1 = "INSERT INTO t VALUES(1);\n";  // 25 bytes
        $q2 = "INSERT INTO t VALUES(2);\n";  // 25 bytes
        $q3 = "INSERT INTO t VALUES(3);\n";  // 25 bytes
        $sql = $q1 . $q2 . $q3;
        $chunk_size = 30;

        // --- Simulate the OLD db-apply approach (total_bytes_read) ---

        $stream = new WP_MySQL_Naive_Query_Stream();
        $old_total_bytes_read = 0;

        // fread chunk 1: 30 bytes
        $chunk = substr($sql, $old_total_bytes_read, $chunk_size);
        $old_total_bytes_read += strlen($chunk);
        $stream->append_sql($chunk);

        // Extract q1
        $this->assertTrue($stream->next_query());

        // Old code would save bytes_read = total_bytes_read = 30
        // Resume from 30 → the remaining file is "NSERT INTO t VALUES(2);\n..."
        $old_remaining = substr($sql, $old_total_bytes_read);
        $this->assertStringNotContainsString(
            'INSERT INTO t VALUES(2)',
            $old_remaining,
            'The old approach (total_bytes_read=30) loses the first 5 bytes of q2'
        );

        // --- Simulate the NEW db-apply approach (bytes_consumed) ---

        $new_bytes_read = $stream->get_bytes_consumed();

        // Resume from bytes_consumed → the remaining file starts before q2
        $new_remaining = substr($sql, $new_bytes_read);
        $this->assertStringContainsString(
            'INSERT INTO t VALUES(2)',
            $new_remaining,
            'The new approach (bytes_consumed) preserves q2 intact'
        );

        // Verify full round-trip: resume from the new offset and extract
        // both remaining queries successfully
        $stream2 = new WP_MySQL_Naive_Query_Stream();
        $stream2->append_sql($new_remaining);
        $stream2->mark_input_complete();

        $this->assertTrue($stream2->next_query());
        $this->assertStringContainsString('VALUES(2)', $stream2->get_query());
        $this->assertTrue($stream2->next_query());
        $this->assertStringContainsString('VALUES(3)', $stream2->get_query());
        $this->assertFalse($stream2->next_query());
    }
}
