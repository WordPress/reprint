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

    public function testStructuredEqualNonDefaultSourcePortIsAcceptedAndPreservesSuffix(): void
    {
        $input = '<a href="https://source.example:8443/my%20blog/article">Article</a>';

        $this->assertSame(
            '<a href="https://destination.example/article">Article</a>',
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . ':8443/my%20blog' => self::TARGET_ORIGIN]
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
        $expected = '<!-- wp:image {"url":"https://destination.example/article"} /-->';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/żółć' => self::TARGET_ORIGIN]
            )
        );
    }

    public function testStructuredReplacementUsesTheMatchedMappingsSourceBase(): void
    {
        $input = '<!-- wp:image {"url":"https://assets.example/files/image.jpg"} /-->';
        $expected = '<!-- wp:image {"url":"https://cdn.example/static/image.jpg"} /-->';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [
                    self::SOURCE_ORIGIN => self::TARGET_ORIGIN,
                    'https://assets.example/files' => 'https://cdn.example/static',
                ]
            )
        );
    }

    public function testStructuredReplacementPrefersMostSpecificOverlappingSourceBase(): void
    {
        $input = '<a href="https://source.example/blog/post">Post</a>';
        $expected = '<a href="https://blog-destination.example/articles/post">Post</a>';
        $mappings = [
            self::SOURCE_ORIGIN => self::TARGET_ORIGIN,
            self::SOURCE_ORIGIN . '/blog' => 'https://blog-destination.example/articles',
        ];

        foreach (
            [
                'broad mapping first' => $mappings,
                'specific mapping first' => array_reverse($mappings, true),
            ] as $label => $mapping
        ) {
            $this->assertSame(
                $expected,
                $this->rewriteBlockMarkup($input, $mapping),
                $label
            );
        }
    }

    /**
     * @dataProvider urlReferenceFormCases
     */
    public function testStructuredReplacementPreservesUrlReferenceForm(
        string $source_reference,
        string $target_reference
    ): void {
        $input = '<a href="' . $source_reference . '">Page</a>';
        $expected = '<a href="' . $target_reference . '">Page</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN => self::TARGET_ORIGIN]
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urlReferenceFormCases(): iterable
    {
        yield 'absolute URL' => [
            'https://source.example/dir/page',
            'https://destination.example/dir/page',
        ];
        yield 'network-path reference' => [
            '//source.example/dir/page',
            '//destination.example/dir/page',
        ];
        yield 'root-relative reference' => [
            '/dir/page',
            '/dir/page',
        ];
        yield 'path-relative reference' => [
            'dir/page',
            'dir/page',
        ];
    }

    /**
     * @dataProvider emptyQueryAndFragmentMarkerCases
     */
    public function testStructuredReplacementPreservesEmptyQueryAndFragmentMarkers(
        string $source_url,
        string $target_url
    ): void {
        $input = '<a href="' . $source_url . '">Page</a>';
        $expected = '<a href="' . $target_url . '">Page</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN => self::TARGET_ORIGIN]
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyQueryAndFragmentMarkerCases(): iterable
    {
        yield 'path with empty query' => [
            'https://source.example/path?',
            'https://destination.example/path?',
        ];
        yield 'path with empty fragment' => [
            'https://source.example/path#',
            'https://destination.example/path#',
        ];
        yield 'path with empty query and fragment' => [
            'https://source.example/path?#',
            'https://destination.example/path?#',
        ];
        yield 'origin with empty query' => [
            'https://source.example?',
            'https://destination.example?',
        ];
        yield 'origin with empty fragment' => [
            'https://source.example#',
            'https://destination.example#',
        ];
        yield 'origin with empty query and fragment' => [
            'https://source.example?#',
            'https://destination.example?#',
        ];
    }

    /**
     * @dataProvider relativeEmptyQueryAndFragmentMarkerCases
     */
    public function testStructuredRelativeReplacementPreservesEmptyQueryAndFragmentMarkers(
        string $source_reference,
        string $target_reference
    ): void {
        $input = '<a href="' . $source_reference . '">Page</a>';
        $expected = '<a href="' . $target_reference . '">Page</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function relativeEmptyQueryAndFragmentMarkerCases(): iterable
    {
        yield 'root-relative empty query' => ['/base/path?', '/target/path?'];
        yield 'root-relative empty fragment' => ['/base/path#', '/target/path#'];
        yield 'root-relative empty query and fragment' => ['/base/path?#', '/target/path?#'];
    }

    /**
     * @dataProvider pathRelativeReferenceCases
     */
    public function testMappedPathRelativeReferenceRetainsItsRelativePrefix(
        string $source_reference,
        string $target_reference
    ): void {
        $input = '<a href="' . $source_reference . '">Page</a>';
        $expected = '<a href="' . $target_reference . '">Page</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathRelativeReferenceCases(): iterable
    {
        yield 'plain relative path' => ['base/page', 'target/page'];
        yield 'current-directory relative path' => ['./base/page', './target/page'];
        yield 'parent-directory relative path' => ['../base/page', '../target/page'];
    }

    /**
     * @dataProvider rawUnmatchedSuffixCases
     */
    public function testStructuredReplacementPreservesRawUnmatchedSuffixBytes(
        string $source_suffix,
        string $target_suffix
    ): void {
        $input = '<a href="' . self::SOURCE_ORIGIN . '/base' . $source_suffix . '">Page</a>';
        $expected = '<a href="' . self::TARGET_ORIGIN . '/target' . $target_suffix . '">Page</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rawUnmatchedSuffixCases(): iterable
    {
        yield 'percent escape spelling' => [
            '/%2f%2F/%257e',
            '/%2f%2F/%257e',
        ];
        yield 'query and fragment spelling' => [
            '/path?next=%2f%2F%25#part=%2e%2E',
            '/path?next=%2f%2F%25#part=%2e%2E',
        ];
        yield 'query separators plus signs and empty fields' => [
            '/path?a=1+2&&empty=&a=%2f;semi#frag+%2E',
            '/path?a=1+2&&empty=&a=%2f;semi#frag+%2E',
        ];
        yield 'dot segments' => [
            '/a/../b/./c',
            '/a/../b/./c',
        ];
        yield 'Unicode path bytes' => [
            '/żółć',
            '/żółć',
        ];
        yield 'literal space' => [
            '/a b',
            '/a b',
        ];
        yield 'backslash' => [
            '/a\\b',
            '/a\\b',
        ];
        yield 'tab byte' => [
            "/a\tb",
            "/a\tb",
        ];
        yield 'CRLF bytes' => [
            "/a\r\nb",
            "/a\r\nb",
        ];
        yield 'NUL byte' => [
            "/a\0b",
            "/a\0b",
        ];
        yield 'invalid high byte' => [
            "/a\xFFb",
            "/a\xFFb",
        ];
    }

    public function testProtocolRelativeAuthorityIsPreservedAcrossStructuredContainers(): void
    {
        $input = '<!-- wp:image {"url":"//source.example/assets/block.png"} /-->'
            . '<div style="background:url(//source.example/assets/style.png)">'
            . '<img src="//source.example/assets/tag.png">'
            . '</div>';
        $expected = '<!-- wp:image {"url":"//cdn.example/static/block.png"} /-->'
            . '<div style="background:url(//cdn.example/static/style.png)">'
            . '<img src="//cdn.example/static/tag.png">'
            . '</div>';

        $result = $this->rewriteBlockMarkup(
            $input,
            [
                self::SOURCE_ORIGIN . '/assets' => 'https://cdn.example/static',
            ]
        );

        $this->assertSame($expected, $result);
    }

    public function testRelativeBlockAttributeWithoutRawSourceDomainIsRewritten(): void
    {
        $input = '<!-- wp:image {"url":"/base/image.png"} /-->';
        $expected = '<!-- wp:image {"url":"/target/image.png"} /-->';
        $result = $this->rewriteBlockMarkup(
            $input,
            [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
        );

        $this->assertSame($expected, $result);
    }

    /**
     * @dataProvider rootRelativeKnownHtmlUrlAttributeCases
     */
    public function testRootRelativeKnownHtmlUrlAttributeWithoutRawSourceDomainIsRewritten(
        string $input,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rootRelativeKnownHtmlUrlAttributeCases(): iterable
    {
        yield 'form action' => [
            '<form action="/base/submit">Submit</form>',
            '<form action="/target/submit">Submit</form>',
        ];
        yield 'video poster' => [
            '<video poster="/base/poster.jpg"></video>',
            '<video poster="/target/poster.jpg"></video>',
        ];
        yield 'blockquote cite' => [
            '<blockquote cite="/base/source">Quote</blockquote>',
            '<blockquote cite="/target/source">Quote</blockquote>',
        ];
    }

    public function testStructuredSourceProtocolMustMatchExactly(): void
    {
        $input = '<a href="http://source.example/base/article">Article</a>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    public function testChangedProtocolUsesTargetMappingSpelling(): void
    {
        $input = '<a href="HTTP://SOURCE.EXAMPLE/base/article">Article</a>';
        $expected = '<a href="https://destination.example/target/article">Article</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [
                    'http://source.example/base'
                        => self::TARGET_ORIGIN . '/target',
                ]
            )
        );
    }

    /**
     * @dataProvider normalizedDefaultPortCases
     */
    public function testStructuredDefaultPortsAreComparedAfterNormalization(
        string $source_mapping,
        string $input,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [$source_mapping => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function normalizedDefaultPortCases(): iterable
    {
        yield 'mapping has explicit default port' => [
            self::SOURCE_ORIGIN . ':443/base',
            '<a href="https://source.example/base/article">Article</a>',
            '<a href="https://destination.example/target/article">Article</a>',
        ];
        yield 'input has explicit default port' => [
            self::SOURCE_ORIGIN . '/base',
            '<a href="https://source.example:443/base/article">Article</a>',
            '<a href="https://destination.example/target/article">Article</a>',
        ];
        yield 'HTTP mapping has explicit default port' => [
            'http://source.example:80/base',
            '<a href="http://source.example/base/article">Article</a>',
            '<a href="https://destination.example/target/article">Article</a>',
        ];
        yield 'HTTP input has explicit default port' => [
            'http://source.example/base',
            '<a href="http://source.example:80/base/article">Article</a>',
            '<a href="https://destination.example/target/article">Article</a>',
        ];
    }

    public function testMostSpecificMappingWinsAfterDefaultPortNormalization(): void
    {
        $input = '<a href="https://source.example/a/post">Post</a>';
        $expected = '<a href="https://specific.example/post">Post</a>';
        $mappings = [
            self::SOURCE_ORIGIN . ':443' => 'https://broad.example',
            self::SOURCE_ORIGIN . '/a' => 'https://specific.example',
        ];

        foreach (
            [
                'normalized broad mapping first' => $mappings,
                'specific mapping first' => array_reverse($mappings, true),
            ] as $label => $mapping
        ) {
            $this->assertSame(
                $expected,
                $this->rewriteBlockMarkup($input, $mapping),
                $label
            );
        }
    }

    public function testStructuredSourcePathMatchingIsCaseSensitive(): void
    {
        $input = '<a href="https://source.example/Blog/article">Article</a>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/blog' => self::TARGET_ORIGIN]
            )
        );
    }

    /**
     * @dataProvider percentEncodedSeparatorCases
     */
    public function testPercentEncodedSeparatorsDoNotFormSourcePathBoundaries(
        string $encoded_separator
    ): void {
        $input = '<a href="https://source.example/base'
            . $encoded_separator
            . 'article">Article</a>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN]
            )
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function percentEncodedSeparatorCases(): iterable
    {
        yield 'encoded forward slash' => ['%2F'];
        yield 'encoded backslash' => ['%5C'];
    }

    public function testMatchedEncodedDotSegmentRetainsItsRawSpelling(): void
    {
        $input = '<a href="https://source.example/base/%2E/article">Article</a>';
        $expected = '<a href="https://destination.example/target/%2E/article">Article</a>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    public function testEncodedParentSegmentEscapingSourceBaseFailsClosed(): void
    {
        $input = '<a href="https://source.example/base/%2E%2E/secret">Secret</a>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    public function testStructuredNoMatchIsByteIdentical(): void
    {
        $input = '<A  CLASS = \'keep\'  HREF = \'HTTPS://SOURCE.EXAMPLE/Other/%2f?a=%2F#%2E\' >'
            . 'Article</A>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN]
            )
        );
    }

    public function testMalformedUrlTokenFailsClosed(): void
    {
        $input = '<a href="https://source.example:bad/path">Malformed URL</a>';

        $this->assertSame(
            $input,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN => self::TARGET_ORIGIN]
            )
        );
    }

    /**
     * @dataProvider unsupportedMappingComponentCases
     *
     * @param array<string, string> $mapping Source URL to target URL mapping.
     */
    public function testUnsupportedMappingComponentsCannotBePartiallyApplied(
        array $mapping,
        string $input
    ): void {
        try {
            $rewriter = new StructuredDataUrlRewriter($mapping);
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertSame($input, $rewriter->rewrite_known_block_markup_value($input));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function unsupportedMappingComponentCases(): iterable
    {
        yield 'source query' => [
            [
                self::SOURCE_ORIGIN . '/base?tenant=one'
                    => self::TARGET_ORIGIN . '/target',
            ],
            '<a href="https://source.example/base?tenant=two">Page</a>',
        ];
        yield 'source fragment' => [
            [
                self::SOURCE_ORIGIN . '/base#one'
                    => self::TARGET_ORIGIN . '/target',
            ],
            '<a href="https://source.example/base#two">Page</a>',
        ];
        yield 'target query' => [
            [
                self::SOURCE_ORIGIN . '/base'
                    => self::TARGET_ORIGIN . '/target?tenant=one',
            ],
            '<a href="https://source.example/base?tenant=two">Page</a>',
        ];
        yield 'target fragment' => [
            [
                self::SOURCE_ORIGIN . '/base'
                    => self::TARGET_ORIGIN . '/target#one',
            ],
            '<a href="https://source.example/base#two">Page</a>',
        ];
        yield 'source credentials' => [
            [
                'https://user:password@source.example/base'
                    => self::TARGET_ORIGIN . '/target',
            ],
            '<a href="https://source.example/base/page">Page</a>',
        ];
        yield 'target credentials' => [
            [
                self::SOURCE_ORIGIN . '/base'
                    => 'https://user:password@destination.example/target',
            ],
            '<a href="https://source.example/base/page">Page</a>',
        ];
    }

    public function testNetworkPathReferencePreservesMappedAuthorityPorts(): void
    {
        $input = '<img src="//source.example:8443/assets/image.png">';
        $expected = '<img src="//cdn.example:9443/static/image.png">';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [
                    'https://source.example:8443/assets'
                        => 'https://cdn.example:9443/static',
                ]
            )
        );
    }

    public function testSuccessfulRewritePreservesHtmlAttributeRepresentation(): void
    {
        $input = "<A  HREF = '/base/a' DATA-X='Keep'>Article</A>";
        $expected = "<A  HREF = '/target/a' DATA-X='Keep'>Article</A>";

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    public function testSuccessfulRewritePreservesHtmlCharacterReferenceSpelling(): void
    {
        $input = '<A  HREF = "https://source.example/a?x=&#x31;&#38;y=%2f" '
            . 'DATA-X=Keep>x</A>';
        $expected = '<A  HREF = "https://destination.example/a?x=&#x31;&#38;y=%2f" '
            . 'DATA-X=Keep>x</A>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN => self::TARGET_ORIGIN]
            )
        );
    }

    public function testHtmlCharacterReferenceEscapedSourceHostPreservesWrapperBytes(): void
    {
        $input = "<A  HREF = 'https://sour&#x63;e&#46;example/base/a?x=&#x31;&#38;y=%2f' "
            . "DATA-X=Keep>x</A>";
        $expected = "<A  HREF = 'https://destination.example/target/a?x=&#x31;&#38;y=%2f' "
            . "DATA-X=Keep>x</A>";

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    public function testSuccessfulInlineStyleRewritePreservesAttributeRepresentation(): void
    {
        $input = '<DIV  STYLE = \'color:red; background:url(&quot;'
            . 'https://source.example/a?x=%2f&quot;); --label:"A  B"\' '
            . 'DATA-X=Keep>x</DIV>';
        $expected = '<DIV  STYLE = \'color:red; background:url(&quot;'
            . 'https://destination.example/a?x=%2f&quot;); --label:"A  B"\' '
            . 'DATA-X=Keep>x</DIV>';

        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN => self::TARGET_ORIGIN]
            )
        );
    }

    /**
     * @dataProvider relativeAttributeMemberOrderCases
     */
    public function testRelativeBlockAttributeCacheIsIsolatedByAttributeContext(
        bool $known_attribute_first
    ): void {
        $relative_url = '/base/image.png';
        $attributes = $known_attribute_first
            ? ['url' => $relative_url, 'label' => $relative_url]
            : ['label' => $relative_url, 'url' => $relative_url];
        $expected_attributes = $known_attribute_first
            ? ['url' => '/target/image.png', 'label' => $relative_url]
            : ['label' => $relative_url, 'url' => '/target/image.png'];
        $input = '<!-- wp:image '
            . json_encode($attributes, JSON_UNESCAPED_SLASHES)
            . ' /-->';
        $expected = '<!-- wp:image '
            . json_encode($expected_attributes, JSON_UNESCAPED_SLASHES)
            . ' /-->';
        $result = $this->rewriteBlockMarkup(
            $input,
            [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
        );
        $processor = new BlockMarkupProcessor($result);

        $this->assertSame($expected, $result);
        $this->assertTrue($processor->next_block_delimiter());
        $this->assertSame('/target/image.png', $processor->get_block_attribute('url'));
        $this->assertSame($relative_url, $processor->get_block_attribute('label'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function relativeAttributeMemberOrderCases(): iterable
    {
        yield 'known URL attribute first' => [true];
        yield 'unknown attribute first' => [false];
    }

    /**
     * @dataProvider relativeHtmlAttributeOrderCases
     */
    public function testRelativeHtmlUrlCacheIsIsolatedFromNonUrlAttributes(
        string $input,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->rewriteBlockMarkup(
                $input,
                [self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function relativeHtmlAttributeOrderCases(): iterable
    {
        yield 'known URL attribute first' => [
            '<a href="/base/image.png" data-note="/base/image.png">Image</a>',
            '<a href="/target/image.png" data-note="/base/image.png">Image</a>',
        ];
        yield 'unknown attribute first' => [
            '<a data-note="/base/image.png" href="/base/image.png">Image</a>',
            '<a data-note="/base/image.png" href="/target/image.png">Image</a>',
        ];
    }

    public function testLargeCachePopulationDoesNotChangeRewriteSemantics(): void
    {
        $rewriter = new StructuredDataUrlRewriter([
            self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target',
        ]);
        $first_input = '<a href="/base/0">Page</a>';
        $first_expected = '<a href="/target/0">Page</a>';

        $this->assertSame(
            $first_expected,
            $rewriter->rewrite_known_block_markup_value($first_input)
        );

        // Populate the rewriter with many unique values and verify each result.
        for ($index = 1; $index <= 5000; ++$index) {
            $input = '<a href="/base/' . $index . '">Page</a>';
            $expected = '<a href="/target/' . $index . '">Page</a>';

            $this->assertSame(
                $expected,
                $rewriter->rewrite_known_block_markup_value($input),
                'Unique value ' . $index
            );
        }

        $this->assertSame(
            $first_expected,
            $rewriter->rewrite_known_block_markup_value($first_input)
        );
    }
}
