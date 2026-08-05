<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

/**
 * Defines the URL-rewrite boundary between structured values and ambiguous text.
 *
 * Failure taxonomy:
 * - structured PHP, JSON, and known block attributes keep using their parsers;
 * - ambiguous CSS and shortcode bytes change only at the literal source base;
 * - an exact source path matches, while a longer path segment does not;
 * - a trailing slash in a source base retains the path separator;
 * - percent-encoded and Unicode source paths retain the unmatched suffix bytes;
 * - an outer URL query containing the source URL is not rewritten;
 * - serialized PHP embedded in text is left unchanged;
 * - target paths remain unsupported by the ambiguous-text fallback.
 */
class SafeUrlRewriteFallbackTest extends TestCase
{
    private const SOURCE_URL = 'https://source.example';
    private const TARGET_URL = 'https://destination.example';

    /**
     * @param array<string, string>|null $mapping URL mapping, or null for the default mapping.
     */
    private function createRewriter(?array $mapping = null): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter($mapping ?? [
            self::SOURCE_URL => self::TARGET_URL,
        ]);
    }

    /**
     * Rewrite one value through the production SQL statement path.
     *
     * @param array<string, string>|null $mapping URL mapping, or null for the default mapping.
     */
    private function rewriteSqlValue(
        string $value,
        string $table,
        string $column,
        ?array $mapping = null
    ): string {
        $rewriter = new SqlStatementRewriter($this->createRewriter($mapping));
        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (FROM_BASE64('%s'));",
            $table,
            $column,
            base64_encode($value)
        );

        $scanner = new Base64ValueScanner($rewriter->rewrite($sql));
        $this->assertTrue($scanner->next_value(), 'Expected one rewritten SQL value.');
        $rewritten_value = $scanner->get_value();
        $this->assertFalse($scanner->next_value(), 'Expected exactly one rewritten SQL value.');

        return $rewritten_value;
    }

    public function testTopLevelSerializedPhpContinuesToUpdateDeclaredStringLengths(): void
    {
        $input = serialize([
            'url' => self::SOURCE_URL . '/image.png',
            'label' => 'unchanged',
        ]);

        $result = $this->createRewriter()->rewrite($input);

        $this->assertSame(
            [
                'url' => self::TARGET_URL . '/image.png',
                'label' => 'unchanged',
            ],
            unserialize($result)
        );
    }

    public function testTopLevelJsonContinuesToRewriteEscapedUrlStringValues(): void
    {
        $input = '{"url":"https:\/\/source.example\/image.png","label":"unchanged"}';

        $result = $this->createRewriter()->rewrite($input);

        $this->assertSame(
            [
                'url' => self::TARGET_URL . '/image.png',
                'label' => 'unchanged',
            ],
            json_decode($result, true)
        );
    }

    public function testKnownBlockAttributeContinuesToUseStructuredUrlReplacement(): void
    {
        $input = '<!-- wp:image {"url":"https:\/\/source.example\/image.png"} -->'
            . '<img src="https://source.example/image.png">'
            . '<!-- /wp:image -->';

        $result = $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP);

        $this->assertStringContainsString('"url":"https:\/\/destination.example\/image.png"', $result);
        $this->assertStringContainsString('src="https://destination.example/image.png"', $result);
        $this->assertStringNotContainsString('source.example', $result);
    }

    public function testOuterUrlQueryContainingSourceUrlRemainsUnchanged(): void
    {
        $input = 'Archive: https://archive.example/?url=https://source.example/article';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider ambiguousTextCases
     */
    public function testAmbiguousTextChangesOnlyAtLiteralSourceBase(string $input): void
    {
        $expected = str_replace(self::SOURCE_URL, self::TARGET_URL, $input);

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousTextCases(): iterable
    {
        yield 'unquoted minified CSS URL' => [
            '.hero{background:url(https://source.example/image.png);font-size:64px}',
        ];
        yield 'quoted minified CSS URL' => [
            '.hero{background:url("https://source.example/image.png");font-size:64px}',
        ];
        yield 'HTML-entity quoted CSS URL' => [
            '.hero{background:url(&quot;https://source.example/image.png&quot;);font-size:64px}',
        ];
        yield 'two minified CSS URLs' => [
            '.first{background:url(https://source.example/first.png)}'
                . '.second{background:url(https://source.example/second.png)}',
        ];
        yield 'shortcode attributes' => [
            '[builder image="https://source.example/image.png";font="64px"]',
        ];
    }

    /**
     * @dataProvider ambiguousPostContentCases
     */
    public function testAmbiguousPostContentChangesOnlyAtLiteralSourceBase(string $input): void
    {
        $expected = str_replace(self::SOURCE_URL, self::TARGET_URL, $input);

        $this->assertSame(
            $expected,
            $this->rewriteSqlValue($input, 'wp_posts', 'post_content')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousPostContentCases(): iterable
    {
        yield 'quoted minified CSS' => [
            '.hero{background:url("https://source.example/image.png");font-size:64px}',
        ];
        yield 'HTML-entity quoted minified CSS' => [
            '.hero{background:url(&quot;https://source.example/image.png&quot;);font-size:64px}',
        ];
        yield 'shortcode' => [
            '[builder image="https://source.example/image.png";font="64px"]',
        ];
    }

    public function testExactSourceSubpathRetainsTheUnmatchedPathSuffix(): void
    {
        $rewriter = $this->createRewriter([
            'https://source.example/blog' => self::TARGET_URL,
        ]);

        $this->assertSame(
            self::TARGET_URL . '/article',
            $rewriter->rewrite('https://source.example/blog/article')
        );
    }

    public function testTrailingSlashOnSourceOriginRetainsThePathSeparator(): void
    {
        $rewriter = $this->createRewriter([
            'https://source.example/' => self::TARGET_URL,
        ]);

        $this->assertSame(
            self::TARGET_URL . '/article',
            $rewriter->rewrite('https://source.example/article')
        );
    }

    public function testTrailingSlashOnSourceSubpathRetainsThePathSeparator(): void
    {
        $rewriter = $this->createRewriter([
            'https://source.example/blog/' => self::TARGET_URL,
        ]);

        $this->assertSame(
            self::TARGET_URL . '/article',
            $rewriter->rewrite('https://source.example/blog/article')
        );
    }

    public function testSourceSubpathDoesNotMatchLongerPathSegment(): void
    {
        $input = 'https://source.example/blogger/article';
        $rewriter = $this->createRewriter([
            'https://source.example/blog' => self::TARGET_URL,
        ]);

        $this->assertSame($input, $rewriter->rewrite($input));
    }

    public function testPercentEncodedSourceSubpathRetainsTheUnmatchedPathSuffix(): void
    {
        $rewriter = $this->createRewriter([
            'https://source.example/my%20blog' => self::TARGET_URL,
        ]);

        $this->assertSame(
            self::TARGET_URL . '/article',
            $rewriter->rewrite('https://source.example/my%20blog/article')
        );
    }

    public function testUnicodeSourceSubpathRetainsTheUnmatchedPathSuffix(): void
    {
        $rewriter = $this->createRewriter([
            'https://source.example/żółć' => self::TARGET_URL,
        ]);

        $this->assertSame(
            self::TARGET_URL . '/article',
            $rewriter->rewrite('https://source.example/żółć/article')
        );
    }

    public function testUnicodeSourceHostMatchesPunycodeHostWithoutChangingSuffixBytes(): void
    {
        $rewriter = $this->createRewriter([
            'https://münich.example/blog' => self::TARGET_URL,
        ]);

        $this->assertSame(
            self::TARGET_URL . '/my%20post',
            $rewriter->rewrite('https://xn--mnich-kva.example/blog/my%20post')
        );
    }

    public function testPunycodeSourceHostMatchesUnicodeHostInBlockText(): void
    {
        $input = '<p>https://münich.example/blog/żółć</p>';

        $this->assertSame(
            '<p>' . self::TARGET_URL . '/żółć</p>',
            $this->rewriteSqlValue(
                $input,
                'wp_posts',
                'post_content',
                ['https://xn--mnich-kva.example/blog' => self::TARGET_URL]
            )
        );
    }

    public function testAmbiguousTextLeavesTargetSubpathMappingUnchanged(): void
    {
        $input = 'https://source.example/article';
        $rewriter = $this->createRewriter([
            self::SOURCE_URL => self::TARGET_URL . '/store',
        ]);

        $this->assertSame($input, $rewriter->rewrite($input));
    }

    public function testStructuredBlockAttributeStillSupportsTargetSubpathMapping(): void
    {
        $input = '<a href="https://source.example/article">Article</a>';
        $rewriter = $this->createRewriter([
            self::SOURCE_URL => self::TARGET_URL . '/store',
        ]);

        $this->assertSame(
            '<a href="https://destination.example/store/article">Article</a>',
            $rewriter->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    public function testSerializedPhpEmbeddedInFreeformTextRemainsUnchanged(): void
    {
        $input = 'Code: a:1:{s:3:"url";s:32:"https://source.example/image.png";}';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testSerializedPhpEmbeddedInBlockTextRemainsUnchanged(): void
    {
        $input = '<!-- wp:code --><pre><code>'
            . 'a:1:{s:3:"url";s:32:"https://source.example/image.png";}'
            . '</code></pre><!-- /wp:code -->';

        $this->assertSame(
            $input,
            $this->rewriteSqlValue($input, 'wp_posts', 'post_content')
        );
    }

		public function testEmbeddedSerializationOnlySkipsItsOwnBlockTextNode(): void
    {
        $input = '<p>https://source.example/before.png</p>'
            . '<pre><code>a:1:{s:3:"url";s:32:"https://source.example/image.png";}</code></pre>'
            . '<p>https://source.example/after.png</p>';
        $expected = '<p>https://destination.example/before.png</p>'
            . '<pre><code>a:1:{s:3:"url";s:32:"https://source.example/image.png";}</code></pre>'
            . '<p>https://destination.example/after.png</p>';

        $this->assertSame(
            $expected,
            $this->rewriteSqlValue($input, 'wp_posts', 'post_content')
        );
    }
}
