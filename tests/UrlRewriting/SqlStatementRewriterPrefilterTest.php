<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

/**
 * End-to-end coverage for URL representations inside decoded SQL values.
 */
class SqlStatementRewriterPrefilterTest extends TestCase
{

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

    /**
     * URL representations at every base64 alignment must reach rewriting.
     *
     * @dataProvider cleanBoundaryAlignmentProvider
     */
    public function testEndToEndRewritesAtEveryAlignment(string $padding, string $scheme): void
    {
        $rewriter = $this->createRewriter();
        $value = $padding . $scheme . '://old-site.com/marker';
        $sql = $this->buildInsertSql($value);

        $rewritten = $rewriter->rewrite($sql);
        $decoded = $this->decodeFirstValue($rewritten);

        $this->assertStringContainsString('new-site.com/marker', $decoded);
        $this->assertStringNotContainsString('old-site.com', $decoded);
        // Padding bytes survive verbatim.
        if ($padding !== '') {
            $this->assertStringStartsWith($padding, $decoded);
        }
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
     * Multi-row INSERT where each row puts the URL at a different alignment.
     */
    public function testMultiRowInsertWithMixedAlignments(): void
    {
        $rewriter = $this->createRewriter();
        $rows = [];
        for ($alignment = 0; $alignment < 3; $alignment++) {
            $padding = str_repeat(' ', $alignment);
            $value = $padding . 'https://old-site.com/row-' . $alignment;
            $rows[] = "($alignment, FROM_BASE64('" . base64_encode($value) . "'))";
        }
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES " . implode(',', $rows) . ";";

        $rewritten = $rewriter->rewrite($sql);

        $scanner = new Base64ValueScanner($rewritten);
        $found = [];
        while ($scanner->next_value()) {
            $found[] = $scanner->get_value();
        }
        $this->assertCount(3, $found);
        for ($i = 0; $i < 3; $i++) {
            $this->assertStringContainsString("new-site.com/row-{$i}", $found[$i]);
            $this->assertStringNotContainsString('old-site.com', $found[$i]);
        }
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

            $rewritten = $rewriter->rewrite($sql);
            $decoded   = $this->decodeFirstValue($rewritten);
            $unser     = unserialize($decoded);
            $this->assertIsArray($unser, "pad_len={$pad_len} produced invalid serialized output");
            $this->assertSame(
                $padding . 'https://new-site.com/seg-' . $pad_len,
                $unser['k'],
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

            $rewritten = $rewriter->rewrite($sql);
            $decoded   = $this->decodeFirstValue($rewritten);
            $obj       = json_decode($decoded, true);
            $this->assertIsArray($obj, "pad_len={$pad_len} produced invalid JSON output");
            $this->assertSame(
                $padding . 'https://new-site.com/json-' . $pad_len,
                $obj['k'],
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

            $rewritten = $rewriter->rewrite($sql);
            $decoded   = $this->decodeFirstValue($rewritten);
            $this->assertStringContainsString(
                'new-site.com/p-' . $pad_len,
                $decoded,
                "pad_len={$pad_len} did not rewrite URL inside block markup"
            );
            $this->assertStringNotContainsString('old-site.com', $decoded);
        }
    }

    /**
     * FROM_BASE64('') with no rewritable content remains valid and unchanged.
     */
    public function testEmptyFromBase64DoesNotCrashOrChange(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64(''));";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * A decoded value with no configured source representation is unchanged.
     */
    public function testFromBase64WithoutHttpIsReturnedUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $value = "the quick brown fox jumps over the lazy dog 0123456789 abcdefg";
        $sql = $this->buildInsertSql($value);

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

    public function testEscapedJsonSchemeAndHostnameReachStructuredRewriter(): void
    {
        $input = '{"url":"\\u0068ttps:\/\/old-\\u0073ite.com\/article",'
            . '"label":"keep\\u0020this"}';
        $expected = '{"url":"\\u0068ttps:\/\/new-\\u0073ite.com\/article",'
            . '"label":"keep\\u0020this"}';

        $this->assertSame(
            $this->buildInsertSql($expected),
            $this->createRewriter()->rewrite($this->buildInsertSql($input))
        );
    }

    public function testRelativeBlockUrlReachesStructuredRewriter(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com/old' => 'https://new-site.com/new',
            ])
        );
        $input = '<!-- wp:image {"url":"/old/page"} /-->';
        $expected = '<!-- wp:image {"url":"/new/page"} /-->';

        $this->assertSame(
            $this->buildInsertSql($expected),
            $rewriter->rewrite($this->buildInsertSql($input))
        );
    }

    public function testHtmlEntityEncodedHostnameReachesStructuredRewriter(): void
    {
        $input = '<a href="https://&#111;ld-site.com/page">Page</a>';
        $expected = '<a href="https://new-site.com/page">Page</a>';

        $this->assertSame(
            $this->buildInsertSql($expected),
            $this->createRewriter()->rewrite($this->buildInsertSql($input))
        );
    }

    public function testNamedHtmlReferenceEncodedHostnameReachesStructuredRewriter(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old.example' => 'https://new.example',
            ])
        );
        $input = '<a href="https://old&period;example/page">Page</a>';
        $expected = '<a href="https://new.example/page">Page</a>';

        $this->assertSame(
            $this->buildInsertSql($expected),
            $rewriter->rewrite($this->buildInsertSql($input))
        );
    }

    public function testCssEscapedHostnameReachesStructuredRewriter(): void
    {
        $input = '.x{background:url("https://\6f ld-site.com/page")}';
        $expected = '.x{background:url("https://new-site.com/page")}';

        $this->assertSame(
            $this->buildInsertSql($expected),
            $this->createRewriter()->rewrite($this->buildInsertSql($input))
        );
    }

    public function testUnicodeEscapedIdnHostnameReachesStructuredRewriter(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://xn--bcher-kva.example' => 'https://new.example',
            ])
        );
        $input = '<!-- wp:image {"url":"//b\u00fccher.example/page"} /-->';
        $expected = '<!-- wp:image {"url":"//new.example/page"} /-->';

        $this->assertSame(
            $this->buildInsertSql($expected),
            $rewriter->rewrite($this->buildInsertSql($input))
        );
    }

    /**
     * A URL from an unmapped domain remains unchanged after decoding.
     */
    public function testUrlFromUnmappedDomainIsLeftAlone(): void
    {
        $rewriter = $this->createRewriter();
        $value = 'https://other-site.com/page';
        $sql = $this->buildInsertSql($value);
        $rewritten = $rewriter->rewrite($sql);
        $decoded = $this->decodeFirstValue($rewritten);
        $this->assertSame($value, $decoded);
    }

    /**
     * A statement without FROM_BASE64 remains unchanged.
     */
    public function testStatementWithoutFromBase64IsUnchangedRegardlessOfPrefix(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "CREATE TABLE `wp_x` (`my_aHR0_col` TEXT);";
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * URL-like decoded text without a complete mapped URL remains unchanged.
     */
    public function testUrlLikeDecodedTextPassesThroughUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $value = "httle and tattle";
        $sql = $this->buildInsertSql($value);
        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    /**
     * Producer prelude fragments contain no decoded values and remain unchanged.
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
