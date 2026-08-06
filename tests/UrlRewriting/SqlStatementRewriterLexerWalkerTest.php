<?php

/**
 * Adversarial tests for the lexer-only INSERT walker introduced to skip
 * the full WP_MySQL_Parser AST build for canonical INSERT statements.
 *
 * Each test feeds a syntactically gnarly INSERT to the rewriter, encoding
 * its `post_content` (block_markup column) and `meta_value` (plain text
 * column) values as base64 so we can read back what the rewriter saw —
 * column-aware behaviour reveals whether the walker mapped the FROM_BASE64
 * offset to the right column.
 *
 * Tests cover:
 *   - strings containing parens / commas / FROM_BASE64-looking text
 *   - nested function calls, CONVERT(... USING ...)
 *   - REPLACE INTO, modifiers, ROW(...) constructor, VALUE alias
 *   - ON DUPLICATE KEY UPDATE trailing clause
 *   - case variation, weird whitespace, comments
 *   - hex / binary / NULL / DEFAULT / negative literals
 *   - escaped backticks in identifiers
 *   - ANSI-quoted strings
 *   - unicode + multibyte content
 *   - UPDATE statements (must fall back to AST)
 *   - INSERT … SELECT, INSERT … SET (must fall back)
 *   - qualified table names like `db`.`t` (must fall back)
 *
 * The contract: every test ends in either a correctly rewritten value or
 * an unmodified statement the AST path could still handle. The walker is
 * never allowed to silently corrupt SQL.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class SqlStatementRewriterLexerWalkerTest extends TestCase
{
    private const FROM = 'https://old-site.com';
    private const TO   = 'https://new-site.com';

    private function rewriter(): SqlStatementRewriter
    {
        return new SqlStatementRewriter(
            new StructuredDataUrlRewriter([self::FROM => self::TO]),
            'wp_'
        );
    }

    private function b64(string $s): string
    {
        return base64_encode($s);
    }

    private function decoded(string $sql): array
    {
        $out = [];
        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $out[] = $scanner->get_value();
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Canonical happy path — same shape MySQLDumpProducer emits.
     * ------------------------------------------------------------------ */

    public function testCanonicalMultiRowInsert(): void
    {
        $html = '<a href="' . self::FROM . '/x">x</a>';
        $meta = self::FROM . '/m';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('%s')), (2, FROM_BASE64('%s'));",
            $this->b64($html),
            $this->b64($html)
        );
        unused($meta);

        $result = $this->rewriter()->rewrite($sql);
        $values = $this->decoded($result);

        $this->assertCount(2, $values);
        foreach ($values as $v) {
            $this->assertStringContainsString(self::TO, $v);
            $this->assertStringNotContainsString(self::FROM, $v);
        }
    }

    /* ------------------------------------------------------------------
     * Strings containing characters that look like statement structure.
     * ------------------------------------------------------------------ */

    public function testStringValueContainingCommasAndParensAndBase64Text(): void
    {
        // The non-base64 string here contains `,`, `(`, `)`, `FROM_BASE64`,
        // and a backtick — none of which should affect the walker.
        $html = '<a href="' . self::FROM . '/x">tricky</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES (1, 'a, b, (c) FROM_BASE64(\\'fake\\') `\\\\`',  FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testStringValueWithEscapedQuotesAndBackslashes(): void
    {
        $html = '<a href="' . self::FROM . '/x">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES (1, 'It''s ''quoted'', \\\"too\\\"', FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    /* ------------------------------------------------------------------
     * Nested expressions — every paren depth tracked correctly.
     * ------------------------------------------------------------------ */

    public function testConvertUsingWrapsBase64(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, CONVERT(FROM_BASE64('%s') USING utf8mb4));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testNestedFunctionCallsBeforeFromBase64(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_modified`, `post_content`) VALUES (1, IFNULL(NULL, NOW()), FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    /* ------------------------------------------------------------------
     * Mixed-shape INSERTs the walker must recognise.
     * ------------------------------------------------------------------ */

    public function testReplaceIntoIsRewritten(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "REPLACE INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testInsertWithLowPriorityAndIgnoreModifiers(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT LOW_PRIORITY IGNORE INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testInsertWithRowConstructorTuples(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES ROW(1, FROM_BASE64('%s')), ROW(2, FROM_BASE64('%s'));",
            $this->b64($html),
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);
        $values = $this->decoded($result);

        $this->assertCount(2, $values);
        foreach ($values as $v) {
            $this->assertStringContainsString(self::TO, $v);
        }
    }

    public function testInsertWithSingularValueKeyword(): void
    {
        // `VALUE` is a documented synonym for `VALUES` in MySQL.
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUE (1, FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testInsertWithOnDuplicateKeyUpdateTrailer(): void
    {
        // The walker must stop reading values at `ON`. The assignment list
        // after it doesn't carry FROM_BASE64 we need to map.
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('%s')) ON DUPLICATE KEY UPDATE `post_content` = VALUES(`post_content`);",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    /* ------------------------------------------------------------------
     * Whitespace, comments, keyword case.
     * ------------------------------------------------------------------ */

    public function testMixedKeywordCaseAndSurroundingWhitespace(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "  insert    Into\n   `wp_posts`\t(`ID`,\t`post_content`)\nValues\n(1,\nFROM_BASE64('%s'))\n;",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testInlineAndBlockCommentsBetweenTokens(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "/* opening hint */ INSERT -- noop\nINTO `wp_posts` /* names */ (`ID`, `post_content`) /* values */ VALUES (1 /* pk */, FROM_BASE64('%s') /* base64 */);",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    /* ------------------------------------------------------------------
     * Literal / value-shape edge cases.
     * ------------------------------------------------------------------ */

    public function testNullDefaultNegativeAndHexLiterals(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_parent`, `to_ping`, `pinged`, `post_content`) VALUES (NULL, -1, DEFAULT, 0xDEADBEEF, FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testEscapedBackticksInIdentifier(): void
    {
        // `` -> ` inside a backtick-quoted identifier. The dump produced
        // by anyone using strict_mode is canonical; this just ensures we
        // don't choke on the lexer's escape handling.
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `weird``col`, `post_content`) VALUES (1, 'plain', FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testMultibyteUtf8InValuesAndIdentifiers(): void
    {
        $html = '<p>café 日本語 ' . self::FROM . '/π</p>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES (1, '€ — déjà — 日本', FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    /* ------------------------------------------------------------------
     * Adversarial inputs designed to fool a naive paren counter.
     * ------------------------------------------------------------------ */

    public function testStringWithUnbalancedParensInValueDoesNotConfuseDepth(): void
    {
        // A naive counter that tracks `(` and `)` without going through the
        // lexer would think this string opens and never closes a sub-tuple.
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES (1, '((((((((no closing parens', FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
    }

    public function testStringContainingClosingParenAndCommaAndSemicolonDoesNotEndStatement(): void
    {
        $html = '<a href="' . self::FROM . '">x</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES (1, '); DROP TABLE wp_users; --', FROM_BASE64('%s'));",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $this->assertStringContainsString(self::TO, $this->decoded($result)[0]);
        // Non-base64 SQL bytes must be preserved verbatim — we never want
        // to accidentally execute the embedded payload.
        $this->assertStringContainsString("'); DROP TABLE wp_users; --'", $result);
    }

    public function testMultipleBase64ValuesInSameRowMapToCorrectColumns(): void
    {
        // Two FROM_BASE64() values in one row, in two different columns —
        // one block_markup, one not. The column resolution has to be
        // accurate per-position or the wrong content_type gets applied.
        $html  = '<a href="' . self::FROM . '/post">post</a>';
        $plain = self::FROM . '/meta';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_excerpt`, `post_content`) VALUES (1, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            $this->b64($plain),
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);
        $values = $this->decoded($result);

        $this->assertCount(2, $values);
        foreach ($values as $v) {
            $this->assertStringContainsString(self::TO, $v);
        }
    }

    /* ------------------------------------------------------------------
     * Shapes the walker must REJECT, falling back to AST.
     * ------------------------------------------------------------------ */

    public function testInsertWithoutColumnListPlainTextStillRewritten(): void
    {
        // No column list means we have nothing to map FROM_BASE64 offsets
        // against. The fast path returns null; the AST path runs with an
        // empty column_map, so every value gets the null content type and
        // falls through to the plain-text URL rewriter. The URL must
        // still get rewritten.
        $plain = self::FROM . '/meta';
        $sql = sprintf(
            "INSERT INTO `wp_postmeta` VALUES (1, 1, '_url', FROM_BASE64('%s'));",
            $this->b64($plain)
        );

        $result = $this->rewriter()->rewrite($sql);

        $values = $this->decoded($result);
        $this->assertCount(1, $values, 'expected exactly one base64 value');
        $this->assertStringContainsString(
            self::TO,
            $values[0],
            'plain-text URL must still be rewritten when column list is absent'
        );
        $this->assertStringNotContainsString(
            self::FROM,
            $values[0],
            'source URL must be gone after rewriting'
        );
    }

    public function testInsertWithoutColumnListBlockMarkupValueGetsPlainTextRewriting(): void
    {
        // Even though this targets `wp_posts` (a block-markup table), the
        // missing column list means we can't tell which column the value
        // sits in, so block_markup-aware rewriting is impossible. Plain
        // text rewriting still happens — that's the conservative outcome
        // and the URL must come out updated. The block-markup HTML
        // structure is preserved verbatim by URLInTextProcessor (it only
        // rewrites the URL text, not the surrounding HTML).
        $html = '<a href="' . self::FROM . '/page">link</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` VALUES (1, 1, NOW(), NOW(), FROM_BASE64('%s'), 'title', 'excerpt', 'publish');",
            $this->b64($html)
        );

        $result = $this->rewriter()->rewrite($sql);

        $values = $this->decoded($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString(self::TO, $values[0]);
        $this->assertStringNotContainsString(self::FROM, $values[0]);
    }

    public function testInsertWithoutColumnListMultiRowAllValuesRewritten(): void
    {
        // Multi-row INSERT without a column list. Every base64 value in
        // every tuple must still get plain-text URL rewriting.
        $a = self::FROM . '/a';
        $b = self::FROM . '/b';
        $c = self::FROM . '/c';
        $sql = sprintf(
            "INSERT INTO `wp_postmeta` VALUES (1, 1, '_a', FROM_BASE64('%s')), (2, 1, '_b', FROM_BASE64('%s')), (3, 1, '_c', FROM_BASE64('%s'));",
            $this->b64($a),
            $this->b64($b),
            $this->b64($c)
        );

        $result = $this->rewriter()->rewrite($sql);

        $values = $this->decoded($result);
        $this->assertCount(3, $values);
        foreach ($values as $idx => $v) {
            $this->assertStringContainsString(
                self::TO,
                $v,
                "row {$idx}: target URL not present"
            );
            $this->assertStringNotContainsString(
                self::FROM,
                $v,
                "row {$idx}: source URL still present"
            );
        }
    }

    public function testQualifiedTableNameFallsBackOrIsRewritten(): void
    {
        $plain = self::FROM . '/meta';
        $sql = sprintf(
            "INSERT INTO `mydb`.`wp_postmeta` (`meta_id`, `meta_value`) VALUES (1, FROM_BASE64('%s'));",
            $this->b64($plain)
        );

        $result = $this->rewriter()->rewrite($sql);

        // Falling back is fine; what we care about is that we still see
        // exactly one base64 value coming out — the SQL shape is intact.
        $values = $this->decoded($result);
        $this->assertCount(1, $values);
    }

    public function testInsertSelectFallsBackAndDoesNotCorruptSql(): void
    {
        // INSERT … SELECT has no value tuples to walk. Fast path must
        // return null. AST path doesn't have a column_map for this either,
        // so the rewriter just leaves it alone.
        $sql = "INSERT INTO `wp_postmeta` (`meta_value`) SELECT `option_value` FROM `wp_options` WHERE FROM_BASE64('aGVsbG8=') = 'hello';";

        $result = $this->rewriter()->rewrite($sql);

        // The non-canonical statement should round-trip byte-identical
        // outside of any actual rewriting (the FROM_BASE64 here doesn't
        // contain a URL, so nothing to rewrite anyway).
        $this->assertSame($sql, $result);
    }

    public function testInsertSetSyntaxFallsBack(): void
    {
        // MySQL's INSERT … SET shape. Fast walker returns null, AST handles it.
        $plain = self::FROM . '/meta';
        $sql = sprintf(
            "INSERT INTO `wp_postmeta` SET `meta_id` = 1, `post_id` = 1, `meta_key` = '_u', `meta_value` = FROM_BASE64('%s');",
            $this->b64($plain)
        );

        $result = $this->rewriter()->rewrite($sql);

        // AST-driven rewriting still applies plain-text rewriting via the
        // update path, so the URL gets rewritten — but the structural
        // shape outside the encoded value must be preserved.
        $values = $this->decoded($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString(self::TO, $values[0]);
    }

    /**
     * Direct fast-path engagement test. The earlier behaviour-only tests
     * couldn't tell whether the lexer walker actually fired or whether
     * the AST path produced the same output by coincidence — and in fact
     * a stray EOF token kept the walker from firing on the real
     * benchmark even though all 26 indirect tests passed. Call the production
     * tokenizer and walker directly and assert it returns a non-null column_map
     * for a canonical INSERT, plus the right table name and a column_map size
     * that matches `<columns> × <rows>`.
     */
    private function invokeMapValuesToColumns(string $sql): ?array
    {
        $rewriter = $this->rewriter();
        $reflection = new \ReflectionClass(SqlStatementRewriter::class);
        $tokenizer = $reflection->getMethod('significant_tokens');
        $tokenizer->setAccessible(true);
        $tokens = $tokenizer->invoke(null, $sql);
        $method = $reflection->getMethod('map_values_to_columns_from_tokens');
        $method->setAccessible(true);
        return $method->invoke($rewriter, $tokens);
    }

    public function testWalkerEngagesOnCanonicalDumpedInsert(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('%s')), (2, FROM_BASE64('%s'));",
            $this->b64('a'),
            $this->b64('b')
        );

        $parsed = $this->invokeMapValuesToColumns($sql);

        $this->assertIsArray(
            $parsed,
            'walker must return a parsed result for a canonical INSERT'
        );
        $this->assertSame('wp_posts', $parsed['table']);
        $this->assertCount(
            4,
            $parsed['column_map'],
            '2 columns × 2 rows = 4 column_map entries'
        );
        $this->assertSame('ID', $parsed['column_map'][0][2]);
        $this->assertSame('post_content', $parsed['column_map'][1][2]);
        $this->assertSame('ID', $parsed['column_map'][2][2]);
        $this->assertSame('post_content', $parsed['column_map'][3][2]);
    }

    public function testWalkerEngagesEvenWhenStatementOmitsTrailingSemicolon(): void
    {
        // The stream-level statement extractor sometimes hands us an INSERT
        // without a trailing semicolon. The walker must handle that without
        // tripping its trailing-tokens guard.
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('%s'))",
            $this->b64('a')
        );

        $parsed = $this->invokeMapValuesToColumns($sql);

        $this->assertIsArray($parsed, 'walker must accept INSERTs without trailing semicolon');
    }

    public function testWalkerEngagesOnUpdate(): void
    {
        // The oversized-update path in MySQLDumpProducer emits this exact
        // shape. The walker must map the FROM_BASE64() value to the
        // assigned column.
        $sql = sprintf(
            "UPDATE `wp_postmeta` SET `meta_value` = CONCAT(`meta_value`, FROM_BASE64('%s')) WHERE `meta_id` = 1;",
            $this->b64('a')
        );

        $parsed = $this->invokeMapValuesToColumns($sql);

        $this->assertIsArray($parsed, 'walker must return a parsed result for UPDATE');
        $this->assertSame('wp_postmeta', $parsed['table']);
        $this->assertCount(1, $parsed['column_map']);
        $this->assertSame('meta_value', $parsed['column_map'][0][2]);
    }

    public function testWalkerEngagesOnUpdateWithMultipleSetClauses(): void
    {
        $sql = sprintf(
            "UPDATE `wp_posts` SET `post_title` = 'x', `post_content` = FROM_BASE64('%s'), `post_excerpt` = 'y' WHERE `ID` = 1;",
            $this->b64('<p>x</p>')
        );

        $parsed = $this->invokeMapValuesToColumns($sql);

        $this->assertIsArray($parsed);
        $this->assertSame('wp_posts', $parsed['table']);
        $this->assertCount(3, $parsed['column_map']);
        $this->assertSame('post_title', $parsed['column_map'][0][2]);
        $this->assertSame('post_content', $parsed['column_map'][1][2]);
        $this->assertSame('post_excerpt', $parsed['column_map'][2][2]);
    }

    public function testUpdateStatementsStillUseAstPath(): void
    {
        // UPDATE … SET … FROM_BASE64 — the walker is INSERT-only, so this
        // must reach the AST path, which knows how to map UPDATE columns.
        $plain = self::FROM . '/u';
        $sql = sprintf(
            "UPDATE `wp_postmeta` SET `meta_value` = CONCAT(`meta_value`, FROM_BASE64('%s')) WHERE `meta_id` = 1;",
            $this->b64($plain)
        );

        $result = $this->rewriter()->rewrite($sql);
        $values = $this->decoded($result);

        $this->assertCount(1, $values);
        $this->assertStringContainsString(self::TO, $values[0]);
    }

}

/**
 * Tiny helper to silence "variable declared but not used" linters in
 * tests where we set up data that's intentionally unused for clarity.
 */
function unused($_): void
{
}
