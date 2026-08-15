<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\WPURL;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class StructuredBlockMarkupUrlProcessorTest extends TestCase {
    public function testNextUrlInCurrentTokenReturnsFalseWithoutAStructuredUrl(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor('Text without structured URLs');

        $this->assertFalse($processor->next_url_in_current_token());
    }

    #[DataProvider('structuredUrlDiscoveryProvider')]
    public function testFindsStructuredUrl(
        string $expectedRawUrl,
        string $expectedAbsoluteUrl,
        string $markup,
        ?string $baseUrl = 'https://wordpress.org'
    ): void {
        $processor = new StructuredBlockMarkupUrlProcessor($markup, $baseUrl);

        $this->assertTrue($processor->next_url());
        $this->assertSame($expectedRawUrl, $processor->get_raw_url());
        $this->assertSame($expectedAbsoluteUrl, $processor->get_parsed_url()->toString());
    }

    public static function structuredUrlDiscoveryProvider(): array
    {
        return [
            'HTML attribute' => [
                'https://wordpress.org',
                'https://wordpress.org/',
                '<a href="https://wordpress.org">',
            ],
            'relative block attribute' => [
                '/wp-content/image.png',
                'https://wordpress.org/wp-content/image.png',
                '<!-- wp:image {"url":"/wp-content/image.png"} -->',
            ],
            'second block attribute' => [
                'https://mysite.com/wp-content/image.png',
                'https://mysite.com/wp-content/image.png',
                '<!-- wp:image {"class":"wp-bold","url":"https://mysite.com/wp-content/image.png"} -->',
            ],
            'empty relative HTML attribute with a base' => [
                '',
                'https://wordpress.org/',
                '<a href=""></a>',
                'https://wordpress.org/',
            ],
            'empty HTML attribute without a base is skipped' => [
                'https://developer.w.org',
                'https://developer.w.org/',
                '<a href=""></a><a href="https://developer.w.org"></a>',
                null,
            ],
            'non-URL attribute is skipped' => [
                'https://developer.w.org',
                'https://developer.w.org/',
                '<a class="http://example.com" href="https://developer.w.org"></a>',
                null,
            ],
        ];
    }

    #[DataProvider('unsupportedNestedBlockAttributeProvider')]
    public function testDoesNotReportNestedBlockAttributeUrls(string $markup): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor($markup, 'https://wordpress.org');

        $this->assertFalse($processor->next_url());
    }

    public static function unsupportedNestedBlockAttributeProvider(): array
    {
        return [
            'nested object' => [
                '<!-- wp:image {"meta":{"src":"https://mysite.com/image.png"}} -->',
            ],
            'nested array' => [
                '<!-- wp:image {"srcs":["https://mysite.com/image.png"]} -->',
            ],
        ];
    }

    #[DataProvider('relativeHtmlUrlProvider')]
    public function testResolvesHtmlUrlsAgainstTheBase(
        string $expected,
        string $markup,
        string $baseUrl
    ): void {
        $processor = new StructuredBlockMarkupUrlProcessor($markup, $baseUrl);

        $this->assertTrue($processor->next_url());
        $this->assertSame($expected, $processor->get_parsed_url()->toString());
    }

    public static function relativeHtmlUrlProvider(): array
    {
        return [
            'file relative to origin' => [
                'https://wordpress.org/nodejs-development-environment.md',
                '<a href="nodejs-development-environment.md">',
                'https://wordpress.org',
            ],
            'path relative to origin' => [
                'https://wordpress.org/docs/page.html',
                '<a href="docs/page.html">',
                'https://wordpress.org',
            ],
            'absolute URL ignores base' => [
                'https://example.com/page.html',
                '<a href="https://example.com/page.html">',
                'https://wordpress.org',
            ],
        ];
    }

    public function testFindsOnlyStructuredUrls(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            'Visit https://text.example/ignored' .
            '<a href="https://html.example/page">Link</a>' .
            '<div style="background:url(https://css.example/image.png)"></div>' .
            '<!-- wp:image {"url":"https://block.example/image.png"} /-->'
        );

        $urls = [];
        while ($processor->next_url()) {
            $urls[] = $processor->get_raw_url();
        }

        $this->assertSame(
            [
                'https://html.example/page',
                'https://css.example/image.png',
                'https://block.example/image.png',
            ],
            $urls
        );
    }

    public function testReportsEveryUrlAcrossAttributesAndTags(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            '<img longdesc="https://first.example" src="https://second.example/image.png">' .
            '<a href="https://third.example"></a>'
        );
        $urls = [];

        while ($processor->next_url()) {
            $urls[] = $processor->get_raw_url();
        }

        $this->assertSame(
            [
                'https://second.example/image.png',
                'https://first.example',
                'https://third.example',
            ],
            $urls
        );
        $this->assertFalse($processor->next_url());
    }

    #[DataProvider('structuredUrlProvider')]
    public function testSetsStructuredUrls(string $markup, string $expected): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor($markup);

        $this->assertTrue($processor->next_url());
        $this->assertTrue(
            $processor->set_url(
                'https://new.example/replacement',
                WPURL::parse('https://new.example/replacement')
            )
        );
        $this->assertSame($expected, $processor->get_updated_html());
    }

    public static function structuredUrlProvider(): array
    {
        return [
            'HTML attribute' => [
                '<a href="https://old.example/original"></a>',
                '<a href="https://new.example/replacement"></a>',
            ],
            'CSS url()' => [
                '<div style="background:url(https://old.example/original)"></div>',
                '<div style="background:url(&quot;https://new.example/replacement&quot;)"></div>',
            ],
            'block attribute' => [
                '<!-- wp:image {"url":"https://old.example/original"} /-->',
                '<!-- wp:image {"url":"https:\/\/new.example\/replacement"} /-->',
            ],
        ];
    }

    #[DataProvider('baseReplacementProvider')]
    public function testReplacesStructuredUrlBases(string $markup, string $expected): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            $markup,
            'http://old.example/media'
        );

        $this->assertTrue($processor->next_url());
        $this->assertTrue($processor->replace_base_url('https://new.example/assets'));
        $this->assertSame($expected, $processor->get_updated_html());
    }

    public static function baseReplacementProvider(): array
    {
        return [
            'HTML attribute' => [
                '<a href="http://old.example/media/file"></a>',
                '<a href="https://new.example/assets/file"></a>',
            ],
            'CSS url()' => [
                '<div style="background:url(http://old.example/media/file)"></div>',
                '<div style="background:url(&quot;https://new.example/assets/file&quot;)"></div>',
            ],
            'block attribute' => [
                '<!-- wp:image {"url":"http://old.example/media/file"} /-->',
                '<!-- wp:image {"url":"https:\/\/new.example\/assets\/file"} /-->',
            ],
        ];
    }

    #[DataProvider('cssUrlDetectionProvider')]
    public function testDetectsCssUrls(
        string $expectedUrl,
        string $markup,
        ?string $baseUrl = 'https://example.com'
    ): void {
        $processor = new StructuredBlockMarkupUrlProcessor($markup, $baseUrl);

        $this->assertTrue($processor->next_url());
        $this->assertSame($expectedUrl, $processor->get_raw_url());
    }

    public static function cssUrlDetectionProvider(): array
    {
        return [
            'quoted URL' => [
                'https://wordpress.org)',
                '<div style="background:url(&quot;https://wordpress.org)&quot;)"></div>',
            ],
            'URL in a comment is skipped' => [
                'https://fallback.example',
                '<div style="/*background:url(https://ignored.example)*/background:url(https://fallback.example)"></div>',
            ],
            'URL-like text in a string is skipped' => [
                'https://real.example',
                '<div style="content:&quot;url(https://ignored.example)&quot;;background:url(https://real.example)"></div>',
            ],
            'unquoted URL with encoded space' => [
                'https://wordpress.org/%20/image.png',
                '<div style="background:url(https://wordpress.org/%20/image.png)"></div>',
            ],
            'single-quoted URL' => [
                'https://example.com/image.png',
                '<div style="background:url(\'https://example.com/image.png\')"></div>',
            ],
            'whitespace inside url()' => [
                'https://example.com/image.png',
                '<div style="background:url(  &quot;https://example.com/image.png&quot;  )"></div>',
            ],
            'relative URL' => [
                '/images/bg.png',
                '<div style="background:url(&quot;/images/bg.png&quot;)"></div>',
            ],
            'escaped quotes in a quoted URL' => [
                'https://example.com/path"with"quotes',
                '<div style="background:url(&quot;https://example.com/path\&quot;with\&quot;quotes&quot;)"></div>',
            ],
            'first of multiple URLs' => [
                'https://example.com/bg1.png',
                '<div style="background:url(https://example.com/bg1.png),url(https://example.com/bg2.png)"></div>',
            ],
            'case-insensitive URL function' => [
                'https://example.com/image.png',
                '<div style="background:URL(https://example.com/image.png)"></div>',
            ],
            'CSS hexadecimal escape' => [
                'https://example.com/image.png',
                '<div style="background:url(https://example.com/im\61ge.png)"></div>',
            ],
        ];
    }

    #[DataProvider('cssUrlReplacementProvider')]
    public function testReplacesCssUrls(
        string $markup,
        string $newUrl,
        string $expected,
        ?string $baseUrl = null
    ): void {
        $processor = new StructuredBlockMarkupUrlProcessor($markup, $baseUrl);

        $this->assertTrue($processor->next_url());
        $this->assertTrue($processor->set_url($newUrl, WPURL::parse($newUrl, $baseUrl)));
        $this->assertSame($expected, $processor->get_updated_html());
    }

    public static function cssUrlReplacementProvider(): array
    {
        return [
            'quoted URL' => [
                '<div style="background:url(&quot;https://old.example/image.png&quot;)"></div>',
                'https://new.example/image.png',
                '<div style="background:url(&quot;https://new.example/image.png&quot;)"></div>',
            ],
            'unquoted URL' => [
                '<div style="background:url(https://old.example/image.png)"></div>',
                'https://new.example/image.png',
                '<div style="background:url(&quot;https://new.example/image.png&quot;)"></div>',
            ],
            'single-quoted URL' => [
                '<div style="background:url(\'https://old.example/image.png\')"></div>',
                'https://new.example/image.png',
                '<div style="background:url(&quot;https://new.example/image.png&quot;)"></div>',
            ],
            'relative URL' => [
                '<div style="background:url(&quot;/old/path.png&quot;)"></div>',
                '/new/path.png',
                '<div style="background:url(&quot;/new/path.png&quot;)"></div>',
                'https://example.com',
            ],
            'escaped URL' => [
                '<div style="background:url(&quot;https://example.com/im\61ge.png&quot;)"></div>',
                'https://new.example/image.png',
                '<div style="background:url(&quot;https://new.example/image.png&quot;)"></div>',
            ],
        ];
    }

    public function testReplacesMultipleCssUrls(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            '<div style="background:url(https://old.example/one.png),url(https://old.example/two.png)"></div>'
        );

        $this->assertTrue($processor->next_url());
        $this->assertSame('https://old.example/one.png', $processor->get_raw_url());
        $this->assertTrue(
            $processor->set_url(
                'https://new.example/one.png',
                WPURL::parse('https://new.example/one.png')
            )
        );

        $this->assertTrue($processor->next_url());
        $this->assertSame('https://old.example/two.png', $processor->get_raw_url());
        $this->assertTrue(
            $processor->set_url(
                'https://new.example/two.png',
                WPURL::parse('https://new.example/two.png')
            )
        );

        $this->assertFalse($processor->next_url());
        $this->assertSame(
            '<div style="background:url(&quot;https://new.example/one.png&quot;),' .
            'url(&quot;https://new.example/two.png&quot;)"></div>',
            $processor->get_updated_html()
        );
    }

    public function testReplacesCssAndRegularAttributeUrls(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            '<img src="https://old.example/image.png" ' .
            'style="border-image:url(https://old.example/border.png)">'
        );
        $foundUrls = [];

        while ($processor->next_url()) {
            $foundUrls[] = $processor->get_raw_url();
            $this->assertTrue(
                $processor->set_url(
                    'https://new.example/replacement.png',
                    WPURL::parse('https://new.example/replacement.png')
                )
            );
        }

        $this->assertSame(
            [
                'https://old.example/border.png',
                'https://old.example/image.png',
            ],
            $foundUrls
        );
        $this->assertSame(
            '<img src="https://new.example/replacement.png" ' .
            'style="border-image:url(&quot;https://new.example/replacement.png&quot;)">',
            $processor->get_updated_html()
        );
    }

    public function testReplacesOpaqueTokenUrlBasesAfterStructuredUrls(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            '<a href="https://old.example/image.jpg" data-shortcode=' .
            '"[video src=\'https://old.example/video.mp4\']">Link</a>'
        );
        $mapping = new CautiousURLBaseRewriteMapping(
            [
                'https://old.example' => 'https://new.example',
            ]
        );

        $this->assertTrue($processor->next_token());
        while ($processor->next_url_in_current_token()) {
            $result = WPURL::replace_base_url(
                $processor->get_parsed_url(),
                [
                    'old_base_url' => WPURL::parse('https://old.example'),
                    'new_base_url' => WPURL::parse('https://new.example'),
                    'raw_url'      => $processor->get_raw_url(),
                    'is_relative'  => false,
                ]
            );
            $this->assertNotFalse($result);
            $processor->set_url( (string) $result, $result->new_url );
        }
        $this->assertTrue($processor->replace_url_bases_in_current_token($mapping));

        $this->assertSame(
            '<a href="https://new.example/image.jpg" data-shortcode=' .
            '"[video src=\'https://new.example/video.mp4\']">Link</a>',
            $processor->get_updated_html()
        );
    }

    public function testArchiveUrlListUsesTheOpaqueTokenPass(): void
    {
        $processor = new StructuredBlockMarkupUrlProcessor(
            '<applet archive="https://old.example/one.jar https://old.example/two.jar"></applet>'
        );
        $mapping = new CautiousURLBaseRewriteMapping(
            [
                'https://old.example' => 'https://new.example',
            ]
        );

        $this->assertTrue($processor->next_token());
        $this->assertFalse($processor->next_url_in_current_token());
        $this->assertTrue($processor->replace_url_bases_in_current_token($mapping));
        $this->assertSame(
            '<applet archive="https://new.example/one.jar https://new.example/two.jar"></applet>',
            $processor->get_updated_html()
        );
    }
}
