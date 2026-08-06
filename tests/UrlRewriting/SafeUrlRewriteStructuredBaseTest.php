<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

/**
 * Defines exact source-base matching for URLs parsed from block markup.
 *
 * Structured replacement must preserve the unmatched path suffix, reject
 * different ports and longer path segments, and use the selected mapping's
 * source base.
 */
class SafeUrlRewriteStructuredBaseTest extends TestCase {
    private const SOURCE_ORIGIN = 'https://source.example';
    private const TARGET_ORIGIN = 'https://destination.example';

    /**
     * Rewrite known block markup with the production structured parser.
     *
     * @param array<string, string> $mapping Source URL to target URL mapping.
     */
    private function rewriteBlockMarkup(string $input, array $mapping): string
    {
        $rewriter = new StructuredDataUrlRewriter($mapping);

        return $rewriter->rewrite_known_block_markup_value($input);
    }

    public function testStructuredSourceSubpathDoesNotMatchLongerPathSegment(): void
    {
        $input = '<a href="https://source.example/blogger/article">Article</a>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/blog' => self::TARGET_ORIGIN]
            )
        );
    }

    public function testStructuredSourcePortMustMatchExactly(): void
    {
        $input = '<!-- wp:image {"url":"https://source.example:9443/blog/image.jpg"} /-->';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . ':8443/blog' => self::TARGET_ORIGIN]
            )
        );
    }

    public function testStructuredPercentEncodedSourceSubpathPreservesUnmatchedSuffix(): void
    {
        $input = '<a href="https://source.example/my%20blog/article">Article</a>';

        $this->assertSame(
            '<a href="https://destination.example/article">Article</a>',
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/my%20blog' => self::TARGET_ORIGIN]
            )
        );
    }

    public function testStructuredUnicodeSourceSubpathPreservesUnmatchedSuffix(): void
    {
        $input = '<!-- wp:image {"url":"https://source.example/żółć/article"} /-->';
        $result = $this->rewriteBlockMarkup(
            $input,
            [self::SOURCE_ORIGIN . '/żółć' => self::TARGET_ORIGIN]
        );
        $processor = new BlockMarkupProcessor($result);

        $this->assertTrue($processor->next_block_delimiter());
        $this->assertSame(
            self::TARGET_ORIGIN . '/article',
            $processor->get_block_attribute('url')
        );
    }

    public function testStructuredReplacementUsesTheMatchedMappingsSourceBase(): void
    {
        $input = '<!-- wp:image {"url":"https://assets.example/files/image.jpg"} /-->';
        $result = $this->rewriteBlockMarkup(
            $input,
            [
                self::SOURCE_ORIGIN => self::TARGET_ORIGIN,
                'https://assets.example/files' => 'https://cdn.example/static',
            ]
        );
        $processor = new BlockMarkupProcessor($result);

        $this->assertTrue($processor->next_block_delimiter());
        $this->assertSame(
            'https://cdn.example/static/image.jpg',
            $processor->get_block_attribute('url')
        );
    }

    public function testStructuredReplacementPrefersMostSpecificOverlappingSourceBase(): void
    {
        $input = '<a href="https://source.example/blog/post">Post</a>';

        $this->assertSame(
            '<a href="https://blog-destination.example/articles/post">Post</a>',
            $this->rewriteBlockMarkup(
                $input,
                [
                    self::SOURCE_ORIGIN => self::TARGET_ORIGIN,
                    self::SOURCE_ORIGIN . '/blog' => 'https://blog-destination.example/articles',
                ]
            )
        );
    }
}
