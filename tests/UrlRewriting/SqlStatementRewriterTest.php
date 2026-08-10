<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class SqlStatementRewriterTest extends TestCase
{
    private function createRewriter(?array $mapping = null): SqlStatementRewriter
    {
        return new SqlStatementRewriter(
            new StructuredDataUrlRewriter($mapping ?? [
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
    }

    /**
     * Collect all decoded values from a SQL statement using Base64ValueScanner.
     *
     * @return string[]
     */
    private function collectValues(string $sql): array
    {
        $values = [];
        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $values[] = $scanner->get_value();
        }
        return $values;
    }

    /** Mark direct test fixtures as producer-confirmed complete text values. */
    private function rewriteCompleteText(
        SqlStatementRewriter $rewriter,
        string $sql
    ): string {
        $marked_sql = preg_replace_callback(
            "~FROM_BASE64\\('([A-Za-z0-9+/=]*)'\\)~",
            static function (array $matches): string {
                return "FROM_BASE64(/*reprint:complete-text-v1*/CONCAT('"
                    . $matches[1] . "',''))";
            },
            $sql
        );
        $this->assertNotNull($marked_sql);
        return $rewriter->rewrite($marked_sql);
    }

    public function testRewritesUrlInInsertStatement(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($html);
        $sql = "INSERT INTO `wp_posts` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        // Verify the rewritten SQL contains new-site.com
        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com', $values[0]);
        $this->assertStringNotContainsString('old-site.com', $values[0]);
    }

    public function testPassesThroughDdlStatements(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "CREATE TABLE `wp_posts` (id INT, content TEXT);";
        $this->assertEquals($sql, $rewriter->rewrite($sql));
    }

    public function testPassesThroughStatementsWithNoBase64(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "INSERT INTO `wp_posts` VALUES(1, NULL, 42);";
        $this->assertEquals($sql, $rewriter->rewrite($sql));
    }

    public function testLeavesSerializedPhpValuesUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $serialized = serialize(['siteurl' => 'https://old-site.com/site']);
        $encoded = base64_encode($serialized);
        $sql = "INSERT INTO `wp_options` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame($serialized, $values[0]);
    }

    public function testUnmarkedBinaryPayloadIsByteIdentical(): void
    {
        $rewriter = $this->createRewriter();
        $binary = "\x89PNG\0 https://old-site.com/file \0\xff";
        $sql = "INSERT INTO `t` (`blob`) VALUES(FROM_BASE64(CONCAT('"
            . base64_encode($binary) . "','')));";

        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    public function testUnmarkedBinaryPayloadIsUnchangedBesideMarkedText(): void
    {
        $rewriter = $this->createRewriter();
        $text = 'https://old-site.com/page';
        $binary = "\x89PNG\0 https://old-site.com/file \0\xff";
        $encoded_binary = base64_encode($binary);
        $sql = "INSERT INTO `t` (`text`, `blob`) VALUES("
            . "FROM_BASE64(/*reprint:complete-text-v1*/CONCAT('"
            . base64_encode($text) . "','')), "
            . "FROM_BASE64(CONCAT('{$encoded_binary}','')));";

        $result = $rewriter->rewrite($sql);

        $this->assertStringContainsString(base64_encode('https://new-site.com/page'), $result);
        $this->assertStringContainsString($encoded_binary, $result);
    }

    public function testLegacyUnmarkedTextIsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $encoded = base64_encode('https://old-site.com/page');
        $sql = "INSERT INTO `t` (`text`) VALUES(FROM_BASE64('{$encoded}'));";

        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    public function testRewritesJsonValues(): void
    {
        $rewriter = $this->createRewriter();
        $json = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $encoded = base64_encode($json);
        $sql = "INSERT INTO `wp_postmeta` VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $decoded = json_decode($values[0], true);
        $this->assertStringContainsString('new-site.com', $decoded['url']);
    }

    public function testHandlesMixedValueTypes(): void
    {
        $rewriter = $this->createRewriter();

        $html = '<p>Visit <a href="https://old-site.com">us</a></p>';
        $serialized = serialize(['url' => 'https://old-site.com/home']);
        $plain = 'https://old-site.com/about';

        $sql = sprintf(
            "INSERT INTO `t` VALUES(1, FROM_BASE64('%s'), FROM_BASE64('%s'), NULL, FROM_BASE64('%s'));",
            base64_encode($html),
            base64_encode($serialized),
            base64_encode($plain)
        );

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(3, $values);

        // HTML should be rewritten
        $this->assertStringContainsString('new-site.com', $values[0]);

        // Serialized PHP remains opaque so its byte-length prefixes stay valid.
        $this->assertSame($serialized, $values[1]);

        // Plain text should be rewritten
        $this->assertStringContainsString('new-site.com', $values[2]);
    }

    public function testValuesWithNoMatchingUrlsAreUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $text = 'No URLs here, just plain text.';
        $encoded = base64_encode($text);
        $sql = "INSERT INTO `t` VALUES(FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertEquals($text, $values[0]);
    }

    public function testRewritesMultipleRowInsert(): void
    {
        $rewriter = $this->createRewriter();
        $url1 = 'https://old-site.com/page1';
        $url2 = 'https://old-site.com/page2';

        $sql = sprintf(
            "INSERT INTO `t` VALUES(1, FROM_BASE64('%s')), (2, FROM_BASE64('%s'));",
            base64_encode($url1),
            base64_encode($url2)
        );

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(2, $values);
        $this->assertStringContainsString('new-site.com/page1', $values[0]);
        $this->assertStringContainsString('new-site.com/page2', $values[1]);
    }

    public function testResultIsValidSql(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<img src="https://old-site.com/img.jpg"/>';
        $encoded = base64_encode($html);
        $sql = "INSERT INTO `wp_posts` (`id`, `content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        // Verify the result still has proper SQL structure
        $this->assertStringStartsWith('INSERT INTO', $result);
        $this->assertStringEndsWith(');', $result);
        $this->assertStringContainsString(
            'FROM_BASE64(/*reprint:complete-text-v1*/CONCAT(',
            $result
        );
        $this->assertStringContainsString("',''))", $result);
    }

    // --- Literal behavior across SQL columns ---

    public function testPostContentUsesLiteralBaseSpliceWithoutNormalizingSuffix(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com/shop' => 'https://new-site.com',
        ]);
        $markup = '<img src="https://old-site.com/shop/a/%2F/../b?next=%2f#part=%2E">';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertSame(
            '<img src="https://new-site.com/a/%2F/../b?next=%2f#part=%2E">',
            $values[0]
        );
    }

    public function testUnknownColumnUsesPlainTextUrlScanning(): void
    {
        $rewriter = $this->createRewriter();
        // A plain URL in an arbitrary column uses the literal writer.
        $value = 'https://old-site.com/api/endpoint';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('" . base64_encode('siteurl') . "'), FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertStringContainsString('new-site.com/api/endpoint', $values[1]);
    }

    public function testPostContentOnlyRewritesTheExactSourceSpelling(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/literal">Literal</a>'
            . '<a href="HTTPS://OLD-SITE.COM/case-variant">Case variant</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame(
            '<a href="https://new-site.com/literal">Literal</a>'
                . '<a href="HTTPS://OLD-SITE.COM/case-variant">Case variant</a>',
            $values[0]
        );
    }

    public function testPostContentLeavesCaseVariantSourceUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href=\'https://OLD-SITE.COM/case-variant\'>Case variant</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame($markup, $values[0]);
    }

    public function testPostContentDoesNotConvertUnicodeHostSpellings(): void
    {
        $rewriter = $this->createRewriter([
            'https://xn--bcher-kva.example' => 'https://new.example',
        ]);
        $markup = '<a href="https://xn--bcher-kva.example/punycode">Punycode</a>'
            . '<a href="https://bücher.example/unicode">Unicode</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame(
            '<a href="https://new.example/punycode">Punycode</a>'
                . '<a href="https://bücher.example/unicode">Unicode</a>',
            $values[0]
        );
    }

    public function testPostContentLeavesUnicodeHostInBlockCommentUnchanged(): void
    {
        $rewriter = $this->createRewriter([
            'https://xn--bcher-kva.example' => 'https://new.example',
        ]);
        $markup = '<!-- wp:image {"src":"https://bücher.example/unicode"} -->';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame($markup, $values[0]);
    }

    public function testCommentContentUsesLiteralWriter(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<p>Check <a href="https://old-site.com/post">this post</a></p>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_comments` (`comment_ID`, `comment_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/post', $values[0]);
    }

    // --- INSERT without a column list ---

    public function testInsertWithoutColumnListUsesLiteralWriter(): void
    {
        $rewriter = $this->createRewriter();
        $value = 'https://old-site.com/page';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO `wp_posts` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    // --- UPDATE statements ---

    public function testUpdateStatementUsesLiteralWriter(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "UPDATE `wp_posts` SET `post_content` = FROM_BASE64('{$encoded}') WHERE `ID` = 1;";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    public function testUnmarkedUpdateConcatFragmentIsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "UPDATE `wp_posts` SET `post_content` = CONCAT(`post_content`, FROM_BASE64('{$encoded}')) WHERE `ID` = 1;";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame($markup, $values[0]);
    }

    public function testChunkBoundaryCannotCreateAPartialPathReplacement(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com/shop' => 'https://new-site.com',
        ]);
        $chunk = 'https://old-site.com/shop';
        $sql = "UPDATE `t` SET `value` = CONCAT(`value`, FROM_BASE64(CONCAT('"
            . base64_encode($chunk) . "',''))) WHERE `id` = 1;";

        $this->assertSame($sql, $rewriter->rewrite($sql));
    }

    // --- Multi-row INSERT with mixed columns ---

    public function testMultiRowInsertRewritesEveryLiteralUrl(): void
    {
        $rewriter = $this->createRewriter();
        // Every decoded value uses the same literal writer.
        $title = 'Visit https://old-site.com/about';
        $content = '<a href="https://old-site.com/page">Link</a>';

        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, FROM_BASE64('%s'), FROM_BASE64('%s')), (2, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($title),
            base64_encode($content),
            base64_encode($title),
            base64_encode($content)
        );

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(4, $values);

        // All values should have URLs rewritten
        foreach ($values as $value) {
            $this->assertStringContainsString('new-site.com', $value);
            $this->assertStringNotContainsString('old-site.com', $value);
        }
    }

    // --- CONVERT wrapper ---

    public function testConvertWrapperUsesLiteralWriter(): void
    {
        $rewriter = $this->createRewriter();
        $json = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $encoded = base64_encode($json);
        $sql = "INSERT INTO `wp_postmeta` (`meta_id`, `meta_value`) VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $result = $this->rewriteCompleteText($rewriter, $sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $decoded = json_decode($values[0], true);
        $this->assertStringContainsString('new-site.com', $decoded['url']);
    }

    public function testTableNameDoesNotChangeLiteralWriter(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://new-longer-domain-site.com',
        ]);

        $block = '<!-- wp:image {"url":"https://old-site.com/img.jpg"} -->'
               . '<img src="https://old-site.com/img.jpg"/>'
               . '<!-- /wp:image -->';
        $encoded = base64_encode($block);

        $sql_real = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";
        $result_real = $this->rewriteCompleteText($rewriter, $sql_real);
        $values_real = $this->collectValues($result_real);
        $expected = '<!-- wp:image {"url":"https://new-longer-domain-site.com/img.jpg"} -->'
            . '<img src="https://new-longer-domain-site.com/img.jpg"/>'
            . '<!-- /wp:image -->';
        $this->assertSame($expected, $values_real[0]);

        $sql_spoof = "INSERT INTO `spoofed_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";
        $result_spoof = $this->rewriteCompleteText($rewriter, $sql_spoof);
        $values_spoof = $this->collectValues($result_spoof);
        $this->assertSame($expected, $values_spoof[0]);
    }
}
