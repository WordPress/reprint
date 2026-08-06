<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

/**
 * Adversarial coverage for SQL URL-dispatch quick rejects.
 *
 * The rewriter short-circuits any SQL fragment whose body contains none of
 * `aHR0`, `dHA6`, `dHBz`, `dHRw` — the four base64 substrings produced when
 * "http://" or "https://" is encoded at any byte alignment 0/1/2 mod 3.
 *
 * Most tests here verify the prefilter property directly: for every column
 * value that carries a lowercase http/https URL, the encoded SQL contains at
 * least one of the four substrings. This is a pure base64-arithmetic claim and
 * is independent of how the rewriter recognises safe source-base boundaries.
 *
 * Behavioural tests run the full rewriter to confirm that lowercase absolute,
 * uppercase absolute, and relative URL forms all reach structured parsing.
 *
 * The four prefixes were chosen as the minimum set that covers every
 * combination of scheme × byte alignment:
 *
 *   alignment   "http://X"          "https://X"
 *   ─────────────────────────────────────────────
 *   0 mod 3     `aHR0` (htt)        `aHR0` (htt)
 *   1 mod 3     `dHA6` (tp:)        `dHBz` (tps)
 *   2 mod 3     `dHRw` (ttp)        `dHRw` (ttp)
 *
 * Drop any one of these and the alignment-shift fuzz starts producing
 * false negatives on real data — that's exactly what this file tries to
 * guard against.
 */
class SqlStatementRewriterPrefilterTest extends TestCase
{
    private const PREFIXES = ['aHR0', 'dHA6', 'dHBz', 'dHRw'];

    private function createRewriter(): SqlStatementRewriter
    {
        return new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
                'http://old-site.com'  => 'http://new-site.com',
            ]),
            'wp_'
        );
    }

    private function decodeFirstValue(string $sql): string
    {
        $scanner = new Base64ValueScanner($sql);
        $scanner->next_value();
        return $scanner->get_value();
    }

    private function buildInsertSql(string $value): string
    {
        return "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('"
            . base64_encode($value)
            . "'));";
    }

    private function statementHasAnyPrefix(string $sql): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (strpos($sql, $prefix) !== false) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // PREFILTER PROPERTY — for every lowercase http/https URL, the encoded
    // statement must contain at least one of the four prefilter substrings.
    // -----------------------------------------------------------------

    /**
     * Exhaustive: every byte alignment × every scheme × multiple padding
     * byte choices. For each, the encoded SQL must trip the prefilter.
     */
    public function testEveryAlignmentAndSchemeContainsAtLeastOnePrefix(): void
    {
        // Three padding-byte choices: printable ASCII, NUL (the rewriter
        // can choke on NULs but the prefilter is byte-blind — it must
        // still see the substring), and high-bit byte.
        $paddingBytes = ['x', "\0", "\xFF"];
        foreach ($paddingBytes as $padByte) {
            for ($alignment = 0; $alignment < 3; $alignment++) {
                $padding = str_repeat($padByte, $alignment);
                foreach (['http', 'https'] as $scheme) {
                    $value = $padding . $scheme . '://example.com/path';
                    $sql = $this->buildInsertSql($value);
                    $this->assertTrue(
                        $this->statementHasAnyPrefix($sql),
                        sprintf(
                            'Prefilter would FALSE-NEGATIVE: alignment=%d scheme=%s padByte=%s payload=%s',
                            $alignment,
                            $scheme,
                            bin2hex($padByte),
                            base64_encode($value)
                        )
                    );
                }
            }
        }
    }

    /**
     * UTF-8 prefix bytes shift alignment by 2, 3, or 4 depending on the
     * codepoint width. Make sure each width still produces a prefix hit.
     */
    public function testUtf8PrefixDoesNotBreakPrefilterCoverage(): void
    {
        $prefixes = [
            'cjk_3byte'        => "\xE4\xB8\xAD",                 // 中
            'emoji_4byte'      => "\xF0\x9F\x98\x80",             // 😀
            'two_codepoints_5' => "\xC3\xA9" . "\xE2\x98\x83",    // é + ☃
            'two_emoji_8byte'  => "\xF0\x9F\x98\x80\xF0\x9F\x99\x82", // 😀🙂
        ];
        foreach ($prefixes as $label => $pad) {
            foreach (['http', 'https'] as $scheme) {
                $value = $pad . ' ' . $scheme . '://example.com/x'; // space gives a clean URL boundary
                $sql = $this->buildInsertSql($value);
                $this->assertTrue(
                    $this->statementHasAnyPrefix($sql),
                    "UTF-8 case '{$label}' + {$scheme} produced no prefilter hit: " . base64_encode($value)
                );
            }
        }
    }

    /**
     * The encoded form of "http" alone — without `://` — is `aHR0cA==`
     * which still starts with `aHR0`. So even the bare scheme prefix at
     * offset 0 trips the prefilter. We don't ASSERT this is sufficient
     * for the rewriter (a bare "http" is not a URL) but the prefilter
     * doesn't need to know that.
     */
    public function testBareSchemeAtAlignmentZeroStillTripsPrefilter(): void
    {
        // Bare "http" at offset 0 mod 3.
        $sql = $this->buildInsertSql('http');
        $this->assertTrue($this->statementHasAnyPrefix($sql));
    }

    /**
     * Exhaustive fuzz: 200 random padding lengths × random URL paths × both
     * schemes. Padding bytes are chosen from a wide alphabet so each
     * iteration explores a different layout. The prefilter property must
     * hold for every iteration.
     */
    public function testFuzzPrefilterCoverage(): void
    {
        mt_srand(424242);
        $alphabet = "abcdefghijklmnopqrstuvwxyz0123456789 \t!@#$%^&*()-_=+[]{}";
        $alphabet_len = strlen($alphabet);
        $iterations = 200;

        for ($i = 0; $i < $iterations; $i++) {
            $pad_len = mt_rand(0, 10);
            $padding = '';
            for ($j = 0; $j < $pad_len; $j++) {
                $padding .= $alphabet[mt_rand(0, $alphabet_len - 1)];
            }
            $scheme = mt_rand(0, 1) === 0 ? 'http' : 'https';
            $path_len = mt_rand(1, 12);
            $path = '';
            for ($j = 0; $j < $path_len; $j++) {
                $path .= $alphabet[mt_rand(0, $alphabet_len - 1)];
            }

            $value = $padding . $scheme . '://example.com/' . $path;
            $sql = $this->buildInsertSql($value);

            $this->assertTrue(
                $this->statementHasAnyPrefix($sql),
                sprintf(
                    'Fuzz iteration %d falsified prefilter: pad_len=%d scheme=%s value=%s payload=%s',
                    $i,
                    $pad_len,
                    $scheme,
                    json_encode($value),
                    base64_encode($value)
                )
            );
        }
    }

    /**
     * Targeted fuzz with **all 256 possible single padding bytes**. If any
     * byte produces a value whose encoding doesn't trip the prefilter,
     * we have a hole in the analysis.
     */
    public function testEveryPaddingByteValuePreservesCoverage(): void
    {
        for ($byte = 0; $byte < 256; $byte++) {
            foreach ([0, 1, 2] as $alignment) {
                $padding = str_repeat(chr($byte), $alignment);
                foreach (['http', 'https'] as $scheme) {
                    $value = $padding . $scheme . '://example.com/x';
                    $sql = $this->buildInsertSql($value);
                    $this->assertTrue(
                        $this->statementHasAnyPrefix($sql),
                        sprintf(
                            'Hole: byte=0x%02X alignment=%d scheme=%s payload=%s',
                            $byte,
                            $alignment,
                            $scheme,
                            base64_encode($value)
                        )
                    );
                }
            }
        }
    }

    /**
     * Real-world envelope shapes: the URL is buried inside serialized PHP
     * or JSON at a non-zero offset. Confirms the prefilter property holds
     * for the formats the rewriter actually handles.
     */
    public function testPrefilterCoverageInsideStructuredEnvelopes(): void
    {
        for ($pad_len = 0; $pad_len < 12; $pad_len++) {
            $padding = str_repeat('p', $pad_len);
            $url = 'https://example.com/seg-' . $pad_len;

            $serialized = serialize(['k' => $padding . ' ' . $url]);
            $sql = $this->buildInsertSql($serialized);
            $this->assertTrue(
                $this->statementHasAnyPrefix($sql),
                "Serialized PHP envelope at pad_len={$pad_len} did not trip prefilter"
            );

            $json = json_encode(['k' => $padding . ' ' . $url]);
            $sql = $this->buildInsertSql($json);
            $this->assertTrue(
                $this->statementHasAnyPrefix($sql),
                "JSON envelope at pad_len={$pad_len} did not trip prefilter"
            );

            $html = '<p>' . $padding . ' <a href="' . $url . '">L</a></p>';
            $sql = $this->buildInsertSql($html);
            $this->assertTrue(
                $this->statementHasAnyPrefix($sql),
                "HTML envelope at pad_len={$pad_len} did not trip prefilter"
            );
        }
    }

    // -----------------------------------------------------------------
    // BEHAVIOURAL PARITY — the prefilter must not change observable output.
    // -----------------------------------------------------------------

    /**
     * End-to-end: URL at every alignment, with a clean (space-prefixed)
     * URL boundary so the freeform fallback consistently recognises the URL.
     * This catches the case where the prefilter wrongly short-circuits a
     * statement that should rewrite.
     *
     * @dataProvider cleanBoundaryAlignmentProvider
     */
    public function testEndToEndRewritesAtEveryAlignment(string $padding, string $scheme): void
    {
        $rewriter = $this->createRewriter();
        $value = $padding . $scheme . '://old-site.com/marker';
        $sql = $this->buildInsertSql($value);
        $expected = $padding . $scheme . '://new-site.com/marker';

        $this->assertSame($this->buildInsertSql($expected), $rewriter->rewrite($sql));
    }

    public static function cleanBoundaryAlignmentProvider(): array
    {
        // Padding ends in a space so the URL parser treats it as a token
        // boundary. Different padding lengths shift alignment.
        $cases = [];
        // alignment 0: empty padding
        $cases['align0_http']  = ['', 'http'];
        $cases['align0_https'] = ['', 'https'];
        // alignment 1: " " (single space)
        $cases['align1_http']  = [' ', 'http'];
        $cases['align1_https'] = [' ', 'https'];
        // alignment 2: "  " (two spaces)
        $cases['align2_http']  = ['  ', 'http'];
        $cases['align2_https'] = ['  ', 'https'];
        // alignment 0 again with longer space padding
        $cases['align0_3spaces_http'] = ['   ', 'http'];
        return $cases;
    }

    /**
     * Multi-row INSERT where each row puts the URL at a different
     * alignment. Statement-level prefilter sees it once; rewriter must
     * still rewrite every row.
     */
    public function testMultiRowInsertWithMixedAlignments(): void
    {
        $rewriter = $this->createRewriter();
        $rows = [];
        $expected_rows = [];
        for ($alignment = 0; $alignment < 3; $alignment++) {
            $padding = str_repeat(' ', $alignment);
            $value = $padding . 'https://old-site.com/row-' . $alignment;
            $rows[] = "($alignment, FROM_BASE64('" . base64_encode($value) . "'))";
            $expected_value = $padding . 'https://new-site.com/row-' . $alignment;
            $expected_rows[] = "($alignment, FROM_BASE64('" . base64_encode($expected_value) . "'))";
        }
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES " . implode(',', $rows) . ";";
        $expected_sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES "
            . implode(',', $expected_rows)
            . ";";

        $this->assertSame($expected_sql, $rewriter->rewrite($sql));
    }

    /**
     * URL inside serialized PHP at varying offsets, with a clean
     * delimiter so the leaf-rewriter recognises the URL.
     */
    public function testRewritesUrlInsideSerializedPhpAtVariousAlignments(): void
    {
        $rewriter = $this->createRewriter();
        for ($pad_len = 0; $pad_len < 12; $pad_len++) {
            // Padding ends with a space so URL extractors see a clean boundary.
            $padding = str_repeat('p', $pad_len) . ' ';
            $url     = 'https://old-site.com/seg-' . $pad_len;
            $blob    = serialize(['k' => $padding . $url]);
            $sql     = $this->buildInsertSql($blob);
            $expected_blob = serialize([
                'k' => $padding . 'https://new-site.com/seg-' . $pad_len,
            ]);

            $this->assertSame(
                $this->buildInsertSql($expected_blob),
                $rewriter->rewrite($sql),
                "pad_len={$pad_len} did not rewrite URL inside serialized PHP"
            );
        }
    }

    public function testRewritesUrlInsideJsonAtVariousAlignments(): void
    {
        $rewriter = $this->createRewriter();
        for ($pad_len = 0; $pad_len < 12; $pad_len++) {
            $padding = str_repeat('p', $pad_len) . ' ';
            $url     = 'https://old-site.com/json-' . $pad_len;
            $blob    = json_encode(['k' => $padding . $url]);
            $sql     = $this->buildInsertSql($blob);
            $expected_blob = json_encode([
                'k' => $padding . 'https://new-site.com/json-' . $pad_len,
            ]);

            $this->assertSame(
                $this->buildInsertSql($expected_blob),
                $rewriter->rewrite($sql),
                "pad_len={$pad_len} did not rewrite URL inside JSON"
            );
        }
    }

    public function testRewritesUrlInsideBlockMarkupAtVariousAlignments(): void
    {
        $rewriter = $this->createRewriter();
        for ($pad_len = 0; $pad_len < 12; $pad_len++) {
            $padding = str_repeat('p', $pad_len);
            $value   = $padding . '<!-- wp:paragraph --><p><a href="https://old-site.com/p-'
                . $pad_len . '">L</a></p><!-- /wp:paragraph -->';
            $sql = $this->buildInsertSql($value);
            $expected_value = $padding . '<!-- wp:paragraph --><p><a href="https://new-site.com/p-'
                . $pad_len . '">L</a></p><!-- /wp:paragraph -->';

            $this->assertSame(
                $this->buildInsertSql($expected_value),
                $rewriter->rewrite($sql),
                "pad_len={$pad_len} did not rewrite URL inside block markup"
            );
        }
    }

    // -----------------------------------------------------------------
    // NEGATIVE CASES — prefilter must leave these untouched.
    // -----------------------------------------------------------------

    /**
     * FROM_BASE64('') with no rewritable content. The earlier
     * "no FROM_BASE64" guard does not trigger (FROM_BASE64 is present),
     * so the prefilter is the only thing that can short-circuit.
     */
    public function testEmptyFromBase64DoesNotCrashOrChange(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64(''));";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * Long base64 payload that decodes to URL-less content. Confirms the
     * prefilter actually short-circuits — not just that the inner
     * per-value strpos catches it later.
     */
    public function testFromBase64WithoutHttpIsReturnedUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $value = "the quick brown fox jumps over the lazy dog 0123456789 abcdefg";
        $sql = $this->buildInsertSql($value);

        // Sanity: this payload genuinely contains none of the four
        // prefilter prefixes — otherwise the test isn't testing what we
        // think.
        foreach (self::PREFIXES as $prefix) {
            $this->assertFalse(
                strpos($sql, $prefix),
                "Test fixture is contaminated: contains prefix '{$prefix}'"
            );
        }
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * @dataProvider uppercaseUrlCases
     */
    public function testUppercaseHttpUrlIsRewritten(string $source_url, string $target_url): void
    {
        $rewriter = $this->createRewriter();
        $sql = $this->buildInsertSql($source_url);

        $this->assertSame($this->buildInsertSql($target_url), $rewriter->rewrite($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uppercaseUrlCases(): iterable
    {
        yield 'HTTP' => [
            'HTTP://OLD-SITE.COM/page',
            'HTTP://new-site.com/page',
        ];
        yield 'HTTPS' => [
            'HTTPS://OLD-SITE.COM/page',
            'HTTPS://new-site.com/page',
        ];
    }

    /**
     * @dataProvider relativeBlockUrlCases
     */
    public function testRelativeBlockUrlReachesStructuredRewriterThroughSql(
        string $source_reference,
        string $target_reference
    ): void {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com/old' => 'https://new-site.com/new',
            ]),
            'wp_'
        );
        $value = '<a href="' . $source_reference . '">Page</a>';
        $sql = $this->buildInsertSql($value);

        $expected_value = '<a href="' . $target_reference . '">Page</a>';

        $this->assertSame($this->buildInsertSql($expected_value), $rewriter->rewrite($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function relativeBlockUrlCases(): iterable
    {
        yield 'root-relative URL' => ['/old/page', '/new/page'];
        yield 'network-path URL' => [
            '//old-site.com/old/page',
            '//new-site.com/new/page',
        ];
    }

    public function testEscapedJsonSchemeAndHostnameReachStructuredRewriterThroughSql(): void
    {
        $input = '{"url":"\u0068ttps:\/\/old-\u0073ite.com\/article",'
            . '"label":"keep\u0020this"}';
        $expected = '{"url":"\u0068ttps:\/\/new-site.com\/article",'
            . '"label":"keep\u0020this"}';
        $sql = $this->buildInsertSql($input);

        $this->assertSame(
            $this->buildInsertSql($expected),
            $this->createRewriter()->rewrite($sql)
        );
    }

    /**
     * URL with a domain we don't have in the mapping. Prefilter SHOULD
     * match (the encoding contains 'aHR0' etc.) so the rewriter runs;
     * the rewriter then leaves the URL untouched because no mapping
     * applies. This proves the prefilter doesn't short-circuit
     * legitimate dispatches.
     */
    public function testUrlFromUnmappedDomainIsLeftAlone(): void
    {
        $rewriter = $this->createRewriter();
        $value = 'https://other-site.com/page';
        $sql = $this->buildInsertSql($value);
        $this->assertTrue(
            $this->statementHasAnyPrefix($sql),
            'Test premise broken: prefilter did not match the encoded https URL'
        );
        $rewritten = $rewriter->rewrite($sql);
        $decoded = $this->decodeFirstValue($rewritten);
        $this->assertSame($value, $decoded);
    }

    /**
     * Statement that contains a prefilter substring INSIDE a backticked
     * identifier and has no FROM_BASE64. The earlier `if` for FROM_BASE64
     * short-circuits first — we must not mistakenly process non-base64
     * statements.
     */
    public function testStatementWithoutFromBase64IsUnchangedRegardlessOfPrefix(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "CREATE TABLE `wp_x` (`my_aHR0_col` TEXT);";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * False positive — a base64 payload that decodes to URL-less content
     * but happens to contain `aHR0` because the source bytes contain
     * "htt" at offset 0 mod 3. The prefilter trips, the rewriter runs,
     * the leaf-level strpos('http') still rejects every value. Output
     * equals input.
     */
    public function testFalsePositivePassesThroughUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        // "htt" at offset 0 — should encode with `aHR0` in the payload.
        $value = "httle and tattle";
        $sql = $this->buildInsertSql($value);
        $this->assertNotFalse(
            strpos($sql, 'aHR0'),
            'Test premise broken: expected `aHR0` to appear in the encoded payload'
        );
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * The producer's emitted prelude / footer fragments contain no
     * FROM_BASE64 at all. The first guard already returns early, but
     * make sure prefilter doesn't accidentally match on something like
     * a comment containing "tp:" or "ttp" elsewhere.
     */
    public function testProducerPreludeIsLeftAlone(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n"
            . "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n"
            . "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY';\n"
            . "SET AUTOCOMMIT=0;\n";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }
}
