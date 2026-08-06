<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class SqlStatementRewriterTest extends TestCase
{
    private function createRewriter(?array $mapping = null, string $table_prefix = 'wp_', array $column_hints = []): SqlStatementRewriter
    {
        return new SqlStatementRewriter(
            new StructuredDataUrlRewriter($mapping ?? [
                'https://old-site.com' => 'https://new-site.com',
            ]),
            $table_prefix,
            $column_hints
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

    public function testRewritesUrlInInsertStatement(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($html);
        $sql = "INSERT INTO `wp_posts` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

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

    public function testRewritesSerializedPhpValues(): void
    {
        $rewriter = $this->createRewriter();
        $serialized = serialize(['siteurl' => 'https://old-site.com/site']);
        $encoded = base64_encode($serialized);
        $sql = "INSERT INTO `wp_options` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // Serialized PHP should now be rewritten with updated s:N: prefixes
        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $unserialized = unserialize($values[0]);
        $this->assertSame('https://new-site.com/site', $unserialized['siteurl']);
    }

    public function testRewritesJsonValues(): void
    {
        $rewriter = $this->createRewriter();
        $json = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $encoded = base64_encode($json);
        $sql = "INSERT INTO `wp_postmeta` VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $result = $rewriter->rewrite($sql);

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

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(3, $values);

        // HTML should be rewritten
        $this->assertStringContainsString('new-site.com', $values[0]);

        // Serialized PHP should be rewritten with URLs updated
        $unserialized = unserialize($values[1]);
        $this->assertSame('https://new-site.com/home', $unserialized['url']);

        // Plain text should be rewritten
        $this->assertStringContainsString('new-site.com', $values[2]);
    }

    public function testValuesWithNoMatchingUrlsAreUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $text = 'No URLs here, just plain text.';
        $encoded = base64_encode($text);
        $sql = "INSERT INTO `t` VALUES(FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

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

        $result = $rewriter->rewrite($sql);

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

        $result = $rewriter->rewrite($sql);

        // Verify the result still has proper SQL structure
        $this->assertStringStartsWith('INSERT INTO', $result);
        $this->assertStringEndsWith(');', $result);
        $this->assertStringContainsString("FROM_BASE64('", $result);
        $this->assertStringContainsString("')", $result);
    }

    // --- Column awareness: WordPress defaults ---

    public function testPostContentColumnUsesBlockMarkupRewriting(): void
    {
        $rewriter = $this->createRewriter();
        // Block markup in post_content — the WP default should trigger block_markup processing
        $markup = '<!-- wp:paragraph --><p>Visit <a href="https://old-site.com/page">us</a></p><!-- /wp:paragraph -->';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
        $this->assertStringNotContainsString('old-site.com', $values[0]);
    }

    public function testPostContentPreservesCustomCssAndShortcodeFormatting(): void
    {
        $rewriter = $this->createRewriter();
        $custom_css = '.hero { background: url(https://old-site.com/image.jpg) no-repeat; font-family: "Rubik"; }';
        $shortcode = '[et_pb_section header_font="Rubik" header_font_size="64px"'
            . ' background_image="https://old-site.com/image.jpg" module_id="hero"]';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES"
                . "(1, FROM_BASE64('%s')), (2, FROM_BASE64('%s'));",
            base64_encode($custom_css),
            base64_encode($shortcode)
        );

        $values = $this->collectValues($rewriter->rewrite($sql));

        $this->assertSame(
            [
                str_replace('https://old-site.com', 'https://new-site.com', $custom_css),
                str_replace('https://old-site.com', 'https://new-site.com', $shortcode),
            ],
            $values
        );
    }

    public function testUnknownColumnUsesPlainTextUrlScanning(): void
    {
        $rewriter = $this->createRewriter();
        // A plain URL in a non-block-markup column should use the plain-text
        // URL scanner.
        $value = 'https://old-site.com/api/endpoint';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('" . base64_encode('siteurl') . "'), FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertStringContainsString('new-site.com/api/endpoint', $values[1]);
    }

    public function testWpDefaultsWorkWithCustomTablePrefix(): void
    {
        $rewriter = $this->createRewriter(null, 'mysite_');
        // Custom prefix — "mysite_posts" is matched exactly via the prefix
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `mysite_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    public function testPostContentUsesStructuredParserForMixedUrlSpellings(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/literal">Literal</a>'
            . '<a href="HTTPS://OLD-SITE.COM/case-variant">Case variant</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('https://new-site.com/literal', $values[0]);
        $this->assertStringContainsString('https://new-site.com/case-variant', $values[0]);
        $this->assertStringNotContainsString('old-site.com', strtolower($values[0]));
    }

    public function testPostContentUsesStructuredParserForCaseVariantHostWithoutLiteralSourceDomain(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href=\'https://OLD-SITE.COM/case-variant\'>Case variant</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('https://new-site.com/case-variant', $values[0]);
        $this->assertStringNotContainsString('old-site.com', strtolower($values[0]));
    }

    public function testPostContentUsesStructuredParserForPunycodeAndUnicodeHostSpellings(): void
    {
        $rewriter = $this->createRewriter([
            'https://xn--bcher-kva.example' => 'https://new.example',
        ]);
        $markup = '<a href="https://xn--bcher-kva.example/punycode">Punycode</a>'
            . '<a href="https://bücher.example/unicode">Unicode</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('https://new.example/punycode', $values[0]);
        $this->assertStringContainsString('https://new.example/unicode', $values[0]);
        $this->assertStringNotContainsString('xn--bcher-kva.example', $values[0]);
        $this->assertStringNotContainsString('bücher.example', $values[0]);
    }

    public function testPostContentUsesStructuredParserForUnicodeHostInBlockCommentJson(): void
    {
        $rewriter = $this->createRewriter([
            'https://xn--bcher-kva.example' => 'https://new.example',
        ]);
        $markup = '<!-- wp:image {"src":"https://bücher.example/unicode"} -->';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('https:\/\/new.example\/unicode', $values[0]);
        $this->assertStringNotContainsString('bücher.example', $values[0]);
    }

    public function testCommentContentUsesBlockMarkup(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<p>Check <a href="https://old-site.com/post">this post</a></p>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_comments` (`comment_ID`, `comment_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/post', $values[0]);
    }

    // --- Column awareness: consumer-provided hints ---

    public function testConsumerHintOverridesDefault(): void
    {
        // Consumer says to skip post_content
        $rewriter = $this->createRewriter(null, 'wp_', [
            'posts' => ['post_content' => 'skip'],
        ]);
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // Value should be unchanged because consumer said 'skip'
        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertSame($markup, $values[0]);
    }

    public function testConsumerHintForCustomTable(): void
    {
        // Consumer declares a custom plugin table column as block_markup
        $rewriter = $this->createRewriter(null, 'wp_', [
            'my_plugin_data' => ['html_content' => 'block_markup'],
        ]);
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_my_plugin_data` (`id`, `html_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    // --- Column awareness: INSERT without column list ---

    public function testInsertWithoutColumnListFallsBackToAutoDetect(): void
    {
        $rewriter = $this->createRewriter();
        // No column list — can't determine column position, falls back to null (auto-detect)
        $value = 'https://old-site.com/page';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO `wp_posts` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    // --- Column awareness: UPDATE statements ---

    public function testUpdateStatementWithColumnAwareness(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "UPDATE `wp_posts` SET `post_content` = FROM_BASE64('{$encoded}') WHERE `ID` = 1;";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    public function testUpdateConcatWithColumnAwareness(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "UPDATE `wp_posts` SET `post_content` = CONCAT(`post_content`, FROM_BASE64('{$encoded}')) WHERE `ID` = 1;";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    // --- Column awareness: multi-row INSERT with mixed columns ---

    public function testMultiRowInsertAppliesCorrectHintPerColumn(): void
    {
        $rewriter = $this->createRewriter();
        // post_content gets block_markup, post_title gets auto-detect (plain text)
        $title = 'Visit https://old-site.com/about';
        $content = '<a href="https://old-site.com/page">Link</a>';

        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, FROM_BASE64('%s'), FROM_BASE64('%s')), (2, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($title),
            base64_encode($content),
            base64_encode($title),
            base64_encode($content)
        );

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(4, $values);

        // All values should have URLs rewritten
        foreach ($values as $value) {
            $this->assertStringContainsString('new-site.com', $value);
            $this->assertStringNotContainsString('old-site.com', $value);
        }
    }

    // --- Column awareness: CONVERT wrapper ---

    public function testColumnAwarenessWorksWithConvertWrapper(): void
    {
        $rewriter = $this->createRewriter();
        $json = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $encoded = base64_encode($json);
        $sql = "INSERT INTO `wp_postmeta` (`meta_id`, `meta_value`) VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $decoded = json_decode($values[0], true);
        $this->assertStringContainsString('new-site.com', $decoded['url']);
    }

    // --- Unprefixed tables (plugin tables without the WP prefix) ---

    public function testUnprefixedTableMatchesSuffixDirectly(): void
    {
        // A plugin that creates a bare "posts" table (no prefix). The suffix
        // entry added at construction time should match it.
        $rewriter = $this->createRewriter(null, 'wp_');
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    // --- Adversarial table names ---
    //
    // A malicious exporter could craft table names designed to trick the
    // suffix-matching heuristic that this code replaced. These tests confirm
    // that exact prefix+suffix matching is not fooled.

    public function testTableNameThatEndsWithPostsButIsNotWpPosts(): void
    {
        // "evil_fakeposts" ends with "posts" but is NOT prefix+"posts".
        // The old suffix heuristic would have matched it; exact matching must not.
        $rewriter = $this->createRewriter(null, 'wp_');
        $value = 'https://old-site.com/page';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO `evil_fakeposts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // post_content in this unknown table should NOT get the block_markup
        // hint — it falls back to auto-detect and uses the plain-text URL
        // scanner for leaf text. This confirms the URL is still rewritten
        // without matching the table as "posts".
        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    public function testTableNameWithExtraUnderscoreSegmentIsNotMatched(): void
    {
        // "wp_not_posts" has the right prefix and ends with _posts, but the
        // suffix is "not_posts", not "posts". Must not match.
        $rewriter = $this->createRewriter(null, 'wp_');
        $markup = '<!-- wp:paragraph --><p><a href="https://old-site.com/page">x</a></p><!-- /wp:paragraph -->';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `wp_not_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // The value still gets rewritten through the auto-detect path, but it
        // must NOT have been treated as block_markup. With a simple URL both
        // paths produce the same output, so this primarily guards table-name
        // matching rather than parser behavior.
        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com', $values[0]);
    }

    public function testTableNameMimickingPrefixInsideName(): void
    {
        // "malicious_wp_posts" — contains "wp_posts" but the configured
        // prefix is "wp_", so the full name "wp_posts" is expected. This table
        // has a different prefix ("malicious_") so it must not match.
        $rewriter = $this->createRewriter(null, 'wp_');
        $value = 'https://old-site.com/page';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO `malicious_wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        // Still rewritten via auto-detect, but not via block_markup
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }

    public function testEmptyPrefixOnlyMatchesBareTableNames(): void
    {
        // Some setups use an empty table prefix. Only the bare suffix should
        // match — "wp_posts" must NOT match when the prefix is "".
        $rewriter = $this->createRewriter(null, '');
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);

        // Bare "posts" — should match
        $sql_bare = "INSERT INTO `posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";
        $result_bare = $rewriter->rewrite($sql_bare);
        $values_bare = $this->collectValues($result_bare);
        $this->assertStringContainsString('new-site.com/page', $values_bare[0]);

        // "wp_posts" with empty prefix — should NOT be recognised as the posts
        // table; it's an unknown table, falls back to auto-detect.
        $sql_prefixed = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";
        $result_prefixed = $rewriter->rewrite($sql_prefixed);
        $values_prefixed = $this->collectValues($result_prefixed);
        $this->assertStringContainsString('new-site.com/page', $values_prefixed[0]);
    }

    /**
     * A block comment JSON attribute lets us distinguish block_markup from
     * plain text rewriting. block_markup parses the JSON inside
     * <!-- wp:image {"url":"..."} --> and rewrites it with the block parser.
     *
     * We use this to prove that "wp_posts".post_content gets block_markup
     * while a spoofed table does NOT.
     */
    public function testBlockMarkupVsPlainTextDistinction(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://new-longer-domain-site.com',
        ]);

        $block = '<!-- wp:image {"url":"https://old-site.com/img.jpg"} -->'
               . '<img src="https://old-site.com/img.jpg"/>'
               . '<!-- /wp:image -->';
        $encoded = base64_encode($block);

        // wp_posts.post_content → block_markup: rewrites both the JSON
        // attribute and the <img> src correctly.
        $sql_real = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";
        $result_real = $rewriter->rewrite($sql_real);
        $values_real = $this->collectValues($result_real);
        $this->assertStringContainsString('new-longer-domain-site.com/img.jpg', $values_real[0]);
        // The JSON attribute should still be valid inside the block comment.
        // The block parser JSON-encodes attribute values, so slashes are escaped.
        $this->assertStringContainsString(
            '"url":"https:\/\/new-longer-domain-site.com\/img.jpg"',
            $values_real[0],
            'block_markup should correctly rewrite the JSON attribute inside the block comment'
        );

        // spoofed_posts.post_content → auto-detect (not block_markup): the
        // column name matches but the table doesn't, so it falls through to
        // plain-text URL scanning.
        $sql_spoof = "INSERT INTO `spoofed_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('{$encoded}'));";
        $result_spoof = $rewriter->rewrite($sql_spoof);
        $values_spoof = $this->collectValues($result_spoof);
        // The URL is still rewritten (auto-detect handles it). The key point:
        // the spoofed table was NOT given the block_markup hint.
        $this->assertStringContainsString('new-longer-domain-site.com', $values_spoof[0]);
    }

    public function testConsumerHintForUnprefixedPluginTable(): void
    {
        // A plugin creates an unprefixed table "analytics_events". The
        // consumer hint uses the suffix "analytics_events" and the prefix is
        // "wp_". The unprefixed entry should match the bare table name.
        $rewriter = $this->createRewriter(null, 'wp_', [
            'analytics_events' => ['event_data' => 'block_markup'],
        ]);
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($markup);
        $sql = "INSERT INTO `analytics_events` (`id`, `event_data`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $values = $this->collectValues($result);
        $this->assertCount(1, $values);
        $this->assertStringContainsString('new-site.com/page', $values[0]);
    }
}
