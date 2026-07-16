<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../packages/reprint-importer/src/lib/mysql-query-stream/load.php';

class FastQueryStreamTest extends TestCase
{
    /**
     * Helper that drains every query out of the given parser and returns
     * the queries plus the final cumulative bytes_consumed value.
     *
     * @return array {
     *     Drained stream result.
     *
     *     @type array  $queries        Parsed queries.
     *     @type int    $bytes_consumed Cumulative bytes consumed.
     *     @type string $state          Final parser state.
     * }
     * @phpstan-return array{queries: list<string>, bytes_consumed: int, state: string}
     */
    private function drain($stream, string $sql): array
    {
        $stream->append_sql($sql);
        $stream->mark_input_complete();
        $queries = [];
        while ($stream->next_query()) {
            $queries[] = $stream->get_query();
        }
        return [
            'queries' => $queries,
            'bytes_consumed' => $stream->get_bytes_consumed(),
            'state' => $stream->get_state(),
        ];
    }

    public function testProducesSameBoundariesAsNaiveOnValidInput(): void
    {
        $sql = "SELECT 1; INSERT INTO t (a, b) VALUES (1, 'two'); UPDATE t SET a = 2 WHERE b = 'two';";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
        $this->assertSame($naive['bytes_consumed'], $fast['bytes_consumed']);
    }

    public function testHandlesQuotedStringsWithSemicolons(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('foo;bar'); SELECT 1;";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
        // Two queries — the fast scanner must skip the embedded ';'.
        $this->assertCount(2, $fast['queries']);
    }

    public function testHandlesDoubledSingleQuoteEscape(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('it''s; really'); SELECT 1;";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
    }

    public function testHandlesBackslashEscapeInsideString(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('foo\\'bar; still'); SELECT 1;";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
    }

    public function testHandlesBacktickIdentifiers(): void
    {
        $sql = "INSERT INTO `weird;name` (a) VALUES (1); SELECT 1;";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
    }

    public function testHandlesLineComments(): void
    {
        $sql = "-- this is; a line comment\nSELECT 1; # also a comment;\nSELECT 2;";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
    }

    public function testHandlesBlockComments(): void
    {
        $sql = "/* skip; me */ SELECT 1; /* skip\n; me too */ SELECT 2;";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
    }

    public function testIncrementalAppendProducesSameBoundaries(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('foo;bar'); SELECT 1;";
        $fast = new WP_MySQL_FastQueryStream();
        // Drip-feed one byte at a time — exercises the scan_cursor
        // resume logic across append_sql() calls.
        $queries = [];
        for ($i = 0; $i < strlen($sql); $i++) {
            $fast->append_sql($sql[$i]);
            while ($fast->next_query()) {
                $queries[] = $fast->get_query();
            }
        }
        $fast->mark_input_complete();
        while ($fast->next_query()) {
            $queries[] = $fast->get_query();
        }
        $this->assertCount(2, $queries);
        $this->assertSame("INSERT INTO t (a) VALUES ('foo;bar');", $queries[0]);
    }

    public function testFinalStatementWithoutTrailingSemicolon(): void
    {
        $sql = "SELECT 1; SELECT 2";
        $fast  = $this->drain(new WP_MySQL_FastQueryStream(), $sql);
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast['queries']);
    }

    public function testFallbackFiresOnUnterminatedString(): void
    {
        // An unterminated single-quoted string after mark_input_complete()
        // is the canonical case where the fast scanner can't make progress.
        // The fallback should pick up the surviving buffer and try the
        // lexer-based parser, which has the same problem — but the
        // important property is that the fallback installs and the
        // error logger is invoked exactly once.
        $sql = "SELECT 'unterminated";
        $fast = new WP_MySQL_FastQueryStream();
        $errors = [];
        $fast->set_error_logger(function (array $err) use (&$errors) {
            $errors[] = $err;
        });
        $fast->append_sql($sql);
        $fast->mark_input_complete();
        // First next_query() detects "input complete + paused" and
        // installs the fallback before forwarding the call.
        $fast->next_query();
        $this->assertTrue($fast->has_fallen_back());
        $this->assertCount(1, $errors);
        $this->assertSame('input complete but parser paused', $errors[0]['reason']);
        // The byte_offset in the error is where the fast parser
        // stopped consuming — at the start of the unterminated string.
        $this->assertSame(0, $errors[0]['byte_offset']);
    }

    public function testBytesConsumedIsMonotonicAcrossFallback(): void
    {
        // First two statements are valid and consumed by the fast
        // parser; an unterminated string follows. After fallback is
        // installed, get_bytes_consumed() must keep advancing — it
        // can't reset to the fallback parser's local count.
        $sql = "SELECT 1; SELECT 2; SELECT 'unterminated";
        $fast = new WP_MySQL_FastQueryStream();
        $fast->append_sql($sql);
        $fast->mark_input_complete();
        $fast->next_query();
        $fast->next_query();
        $bytes_before_fallback = $fast->get_bytes_consumed();
        $this->assertGreaterThan(0, $bytes_before_fallback);
        // Trigger the fallback by asking for the next query — fast
        // parser is paused on the unterminated string, fallback installs.
        $fast->next_query();
        $this->assertTrue($fast->has_fallen_back());
        // bytes_consumed must not have decreased.
        $this->assertGreaterThanOrEqual($bytes_before_fallback, $fast->get_bytes_consumed());
    }

    public function testNoFallbackOnPlainComplexFixture(): void
    {
        // A real-world-ish dump fragment exercising many of the boundary
        // cases the parser handles. No malformed input — fallback must
        // not fire.
        $sql = <<<'SQL'
SET autocommit=0;
CREATE TABLE `posts` (`id` INT, `content` LONGTEXT) ENGINE=InnoDB;
INSERT INTO `posts` (`id`, `content`) VALUES
  (1, 'hello'),
  (2, 'with;semicolon'),
  (3, 'with `backtick'),
  (4, 'doubled '' quote'),
  (5, FROM_BASE64('aGVsbG8='));
-- a comment with a ; in it
INSERT INTO `posts` (`id`, `content`) VALUES (6, '/* not a comment */');
COMMIT;
SQL;
        $fast = new WP_MySQL_FastQueryStream();
        $fast->set_error_logger(function (array $err) {
            $this->fail('fallback unexpectedly fired: ' . $err['message']);
        });
        $fast->append_sql($sql);
        $fast->mark_input_complete();
        $fast_queries = [];
        while ($fast->next_query()) {
            $fast_queries[] = $fast->get_query();
        }
        $naive = $this->drain(new WP_MySQL_Naive_Query_Stream(), $sql);
        $this->assertSame($naive['queries'], $fast_queries);
        $this->assertFalse($fast->has_fallen_back());
    }
}
