<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class StructuredDataUrlRewriterTest extends TestCase
{
    private function createRewriter(?array $mapping = null): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter($mapping ?? [
            'https://old-site.com' => 'https://new-site.com',
        ]);
    }

    // --- HTML content ---

    public function testRewritesUrlInHrefAttribute(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://old-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/page', $result);
        $this->assertStringNotContainsString('old-site.com', $result);
    }

    public function testRewritesUrlInImgSrc(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<img src="https://old-site.com/wp-content/uploads/photo.jpg" />';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/wp-content/uploads/photo.jpg', $result);
    }

    public function testRewritesMultipleHtmlAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://old-site.com/page1">Link 1</a><a href="https://old-site.com/page2">Link 2</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/page1', $result);
        $this->assertStringContainsString('https://new-site.com/page2', $result);
    }

    // --- Block markup ---

    public function testRewritesBlockMarkupJsonAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:image {"src":"https://old-site.com/img.jpg"} --><figure><img src="https://old-site.com/img.jpg"/></figure><!-- /wp:image -->';
        $result = $rewriter->rewrite($input);
        $this->assertStringNotContainsString('old-site.com', $result);
        $this->assertStringContainsString('new-site.com', $result);
    }

    // --- Plain text with URLs ---

    public function testRewritesBareUrlInText(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'Visit us at https://old-site.com/about for more info.';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/about', $result);
    }

    public function testDoesNotRewriteNonUrlsInText(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'Visit us at do-you-knowhttps://old-site.com/about for more info.';
        $result = $rewriter->rewrite($input);
        $this->assertEquals($input, $result);
    }

    /**
     * A mapped URL nested in another URL's query string or fragment is rewritten.
     *
     * `?redirect_to=`, `?return_url=` and friends point at the site being migrated and
     * have to follow it. The cost is that a deliberate reference to the old address —
     * an archive link, say — moves too. Matching the base as bytes cannot tell those
     * apart, and following the site is the more common intent.
     *
     * Both content types must agree here. Block markup reached this behaviour first,
     * when its text tokens moved to cautious base replacement.
     */
    public function testRewritesUrlNestedInAnotherUrl(): void
    {
        $rewriter = $this->createRewriter();

        $cases = [
            'Visit us at https://webarchive.org?url=https://old-site.com/about for more info.'
                => 'Visit us at https://webarchive.org?url=https://new-site.com/about for more info.',
            'https://other.example/login?redirect_to=https://old-site.com/account'
                => 'https://other.example/login?redirect_to=https://new-site.com/account',
            'https://other.example/p#next=https://old-site.com/y'
                => 'https://other.example/p#next=https://new-site.com/y',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertEquals(
                $expected,
                $rewriter->rewrite($input, StructuredDataUrlRewriter::PLAIN_TEXT),
                'plain text'
            );
            $this->assertEquals(
                $expected,
                $rewriter->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP),
                'block markup'
            );
        }
    }

    /**
     * A percent-encoded nested URL is left alone by both content types — decoding it
     * would mean guessing the enclosing format's escaping rules.
     */
    public function testDoesNotRewritePercentEncodedNestedUrl(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'https://other.example/go?to=https%3A%2F%2Fold-site.com%2Fx';

        $this->assertEquals($input, $rewriter->rewrite($input, StructuredDataUrlRewriter::PLAIN_TEXT));
        $this->assertEquals($input, $rewriter->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP));
    }

    // --- JSON content ---

    public function testRewritesUrlsInJsonStringValues(): void
    {
        $rewriter = $this->createRewriter();
        $input = json_encode([
            'home' => 'https://old-site.com',
            'logo' => 'https://old-site.com/wp-content/uploads/logo.png',
        ], JSON_UNESCAPED_SLASHES);

        $result = $rewriter->rewrite($input);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertStringContainsString('new-site.com', $decoded['home']);
        $this->assertStringContainsString('new-site.com/wp-content/uploads/logo.png', $decoded['logo']);
    }

    public function testRewritesUrlsInNestedJson(): void
    {
        $rewriter = $this->createRewriter();
        $input = json_encode([
            'settings' => [
                'url' => 'https://old-site.com/api',
                'nested' => [
                    'image' => 'https://old-site.com/img.jpg',
                ],
            ],
            'count' => 42,
            'active' => true,
        ], JSON_UNESCAPED_SLASHES);

        $result = $rewriter->rewrite($input);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertStringContainsString('new-site.com', $decoded['settings']['url']);
        $this->assertStringContainsString('new-site.com', $decoded['settings']['nested']['image']);
        $this->assertEquals(42, $decoded['count']);
        $this->assertTrue($decoded['active']);
    }

    public function testRewritesUrlInJsonStringScalar(): void
    {
        $rewriter = $this->createRewriter();
        $input = '"https:\/\/old-site.com\/api"';

        $result = $rewriter->rewrite($input);

        $this->assertSame('https://new-site.com/api', json_decode($result, true));
    }

    public function testJsonOutputUsesUnescapedSlashes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '{"url":"https://old-site.com/path"}';
        $result = $rewriter->rewrite($input);
        // Should not contain escaped slashes like \/
        $this->assertStringNotContainsString('\\/', $result);
    }

    // --- Serialized PHP ---

    public function testRewritesUrlInSerializedArray(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize([
            'siteurl' => 'https://old-site.com/site',
            'blogname' => 'My Old Site',
        ]);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize($result);
        $this->assertSame('https://new-site.com/site', $unserialized['siteurl']);
        $this->assertSame('My Old Site', $unserialized['blogname']);
    }

    public function testRewritesUrlInSerializedString(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize('https://old-site.com/page');
        $result = $rewriter->rewrite($input);
        $this->assertSame('https://new-site.com/page', unserialize($result));
    }

    public function testPreservesRootSlashInSerializedPhp(): void
    {
        $rewriter = $this->createRewriter([
            'http://old-site.com/' => 'http://much-longer-destination.example',
        ]);
        $input = serialize(['siteurl' => 'http://old-site.com/']);

        $result = $rewriter->rewrite($input);

        $this->assertSame(
            'http://much-longer-destination.example/',
            unserialize($result)['siteurl']
        );
        $this->assertStringContainsString(
            's:' . strlen('http://much-longer-destination.example/') . ':',
            $result
        );
    }

    public function testRewritesUrlsInDoubleSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $inner = serialize(['url' => 'https://old-site.com/deep']);
        $input = serialize($inner);
        $result = $rewriter->rewrite($input);
        $inner_result = unserialize($result);
        $deep_result = unserialize($inner_result);
        $this->assertSame('https://new-site.com/deep', $deep_result['url']);
    }

    public function testRewritesJsonInsideSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $json_value = json_encode(['link' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $input = serialize(['config' => $json_value]);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize($result);
        $decoded = json_decode($unserialized['config'], true);
        $this->assertSame('https://new-site.com/api', $decoded['link']);
    }

    public function testSerializedPhpWithNoUrlsIsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize([
            'setting' => 'no urls here',
            'count' => 42,
            'nested' => ['inner' => 'also no urls'],
        ]);
        $result = $rewriter->rewrite($input);
        $this->assertSame($input, $result, 'Serialized PHP with no matching URLs should be byte-identical');
    }

    public function testMalformedSerializedPhpFallsBackToText(): void
    {
        $rewriter = $this->createRewriter();
        // SerializedPhpFormat::is_serialized() triggers on 's:...' ending with ';'
        // but this is truncated/malformed — the walker will return false,
        // falling back to text rewriting
        $input = 's:999:"https://old-site.com";';
        $result = $rewriter->rewrite($input);
        // Should have attempted text rewriting, replacing the URL
        $this->assertStringContainsString('new-site.com', $result);
    }

    // --- Base64 ---

    // Base64 processing is temporarily disabled for performance.
    // These tests document the expected behavior when it's re-enabled.

    public function testRewritesBase64EncodedHtml(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    public function testRewritesBase64EncodedJson(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    public function testRewritesBase64EncodedSerializedPhp(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    public function testRewritesBase64EncodedBlockMarkup(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    // --- Combinations: formats nested inside other formats ---

    public function testBase64InsideSerializedPhp(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    public function testSerializedPhpInsideJson(): void
    {
        $rewriter = $this->createRewriter();
        $serialized = serialize(['url' => 'https://old-site.com/deep']);
        $input = json_encode(['data' => $serialized], JSON_UNESCAPED_SLASHES);
        $result = $rewriter->rewrite($input);
        $json_decoded = json_decode($result, true);
        $unserialized = unserialize($json_decoded['data']);
        $this->assertSame('https://new-site.com/deep', $unserialized['url']);
    }

    public function testBase64InsideJsonInsideSerializedPhp(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    public function testBase64WithNoUrlsIsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        // JSON with no matching URLs, base64-encoded
        $json = json_encode(['key' => 'no urls here'], JSON_UNESCAPED_SLASHES);
        $input = base64_encode($json);
        $result = $rewriter->rewrite($input);
        $this->assertSame($input, $result, 'Base64 with no matching URLs should be byte-identical');
    }

    public function testShortBase64LikeStringNotDecoded(): void
    {
        $rewriter = $this->createRewriter();
        // "TRUE" is valid base64 but too short to be treated as encoded data
        $result = $rewriter->rewrite('TRUE');
        $this->assertSame('TRUE', $result);
    }

    // --- No-change cases ---

    public function testValueWithNoUrlsReturnsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'Just a regular string with no URLs.';
        $result = $rewriter->rewrite($input);
        $this->assertEquals($input, $result);
    }

    public function testEmptyStringReturnsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $this->assertEquals('', $rewriter->rewrite(''));
    }

    public function testUrlFromDifferentDomainIsNotRewritten(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://other-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('other-site.com', $result);
        $this->assertStringNotContainsString('new-site.com', $result);
    }

    // --- Multiple URL mappings ---

    public function testMultipleUrlMappings(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://new-site.com',
            'https://cdn.old-site.com' => 'https://cdn.new-site.com',
        ]);
        $input = '<img src="https://cdn.old-site.com/img.jpg"/><a href="https://old-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('cdn.new-site.com', $result);
        $this->assertStringContainsString('new-site.com/page', $result);
    }

    // --- Content type hint: 'skip' ---

    public function testSkipContentTypeReturnsValueUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'https://old-site.com/page';
        $result = $rewriter->rewrite($input, 'skip');
        $this->assertSame($input, $result, "'skip' hint should return the value unchanged");
    }

    public function testSkipContentTypeWorksOnSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize(['url' => 'https://old-site.com/page']);
        $result = $rewriter->rewrite($input, 'skip');
        $this->assertSame($input, $result);
    }

    // --- Content type hint: 'block_markup' ---

    public function testBlockMarkupHintUsesStructuredBlockParser(): void
    {
        $rewriter = $this->createRewriter();
        // Block markup with JSON attribute — the block parser handles the JSON
        // inside the block comment.
        $input = '<!-- wp:image {"src":"https://old-site.com/img.jpg"} --><figure><img src="https://old-site.com/img.jpg"/></figure><!-- /wp:image -->';
        $result = $rewriter->rewrite($input, 'block_markup');
        $this->assertStringNotContainsString('old-site.com', $result);
        $this->assertStringContainsString('new-site.com', $result);
    }

    public function testBlockMarkupHintRewritesHtmlAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://old-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input, 'block_markup');
        $this->assertStringContainsString('https://new-site.com/page', $result);
    }

    public function testBlockMarkupUsesCautiousRewriterForOpaqueUrlContexts(): void
    {
        $rewriter = $this->createRewriter();
        $cases = [
            'srcset candidates' => [
                '<img srcset="https://old-site.com/one.jpg 1x, https://old-site.com/two.jpg 2x">',
                '<img srcset="https://new-site.com/one.jpg 1x, https://new-site.com/two.jpg 2x">',
            ],
            'exact and cautious URLs in one token' => [
                '<img src="https://old-site.com/main.jpg" srcset="https://old-site.com/one.jpg 1x, https://old-site.com/two.jpg 2x">',
                '<img src="https://new-site.com/main.jpg" srcset="https://new-site.com/one.jpg 1x, https://new-site.com/two.jpg 2x">',
            ],
            'source srcset candidates' => [
                '<source srcset="https://old-site.com/one.webp 1x, https://old-site.com/two.webp 2x">',
                '<source srcset="https://new-site.com/one.webp 1x, https://new-site.com/two.webp 2x">',
            ],
            'style element body' => [
                '<style>.hero{background-image:url(https://old-site.com/hero.jpg)}</style>',
                '<style>.hero{background-image:url(https://new-site.com/hero.jpg)}</style>',
            ],
            'meta content attribute' => [
                '<meta property="og:image" content="https://old-site.com/social.jpg">',
                '<meta property="og:image" content="https://new-site.com/social.jpg">',
            ],
            'object archive attribute' => [
                '<object archive="https://old-site.com/one.jar https://old-site.com/two.jar"></object>',
                '<object archive="https://new-site.com/one.jar https://new-site.com/two.jar"></object>',
            ],
            'applet archive attribute' => [
                '<applet archive="https://old-site.com/one.jar https://old-site.com/two.jar"></applet>',
                '<applet archive="https://new-site.com/one.jar https://new-site.com/two.jar"></applet>',
            ],
            'script element body' => [
                '<script>window.asset="https://old-site.com/asset.js";</script>',
                '<script>window.asset="https://new-site.com/asset.js";</script>',
            ],
            'nested block JSON attribute' => [
                '<!-- wp:reprint/example {"settings":{"shortcode":"[vc_video link=\\"https:\\/\\/old-site.com\\/video.mp4\\"]"}} /-->',
                '<!-- wp:reprint/example {"settings":{"shortcode":"[vc_video link=\\"https:\\/\\/new-site.com\\/video.mp4\\"]"}} /-->',
            ],
        ];

        foreach ($cases as $description => [$input, $expected]) {
            $this->assertSame(
                $expected,
                $rewriter->rewrite_known_block_markup_value($input),
                $description
            );
        }
    }

    #[DataProvider('block_markup_text_node_cases')]
    public function testBlockMarkupTextNodesUseCautiousUrlBaseReplacement(
        string $input,
        string $expected
    ): void {
        $rewriter = $this->createRewriter();

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function block_markup_text_node_cases(): array
    {
        return [
            'core shortcode block body' => [
                '<!-- wp:shortcode -->[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]<!-- /wp:shortcode -->',
                '<!-- wp:shortcode -->[vc_video link="https:\/\/new-site.com\/media\/video.mp4"]<!-- /wp:shortcode -->',
            ],
            'shortcode text inside a core HTML block' => [
                '<!-- wp:html --><p>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]</p><!-- /wp:html -->',
                '<!-- wp:html --><p>[vc_video link="https:\/\/new-site.com\/media\/video.mp4"]</p><!-- /wp:html -->',
            ],
            'Elementor-style HTML containing shortcode text' => [
                '<section data-builder="elementor"><p>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]</p></section>',
                '<section data-builder="elementor"><p>[vc_video link="https:\/\/new-site.com\/media\/video.mp4"]</p></section>',
            ],
            'pure WPBakery shortcode record' => [
                '[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '[vc_video link="https:\/\/new-site.com\/media\/video.mp4"]',
            ],
            'pure Divi 4 shortcode record' => [
                '[et_pb_section background_image=”https:\/\/old-site.com\/media\/hero.jpg”][/et_pb_section]',
                '[et_pb_section background_image=”https:\/\/new-site.com\/media\/hero.jpg”][/et_pb_section]',
            ],
            'Divi critical CSS preserves quoted values and URL terminator' => [
                '[et_pb_section custom_css_main_element=\'font-size:"64px";font-family:"Rubik";line-height:"1.1em";background:url(https:\\/\\/old-site.com\\/wp-content\\/uploads\\/2017\\/01\\/wanderlust.jpg) no-repeat center center fixed;\'][/et_pb_section]',
                '[et_pb_section custom_css_main_element=\'font-size:"64px";font-family:"Rubik";line-height:"1.1em";background:url(https:\\/\\/new-site.com\\/wp-content\\/uploads\\/2017\\/01\\/wanderlust.jpg) no-repeat center center fixed;\'][/et_pb_section]',
            ],
            'entity-quoted CSS in a WPBakery shortcode attribute' => [
                '[vc_column css=&#187;.vc_custom{background-image:url(https:\/\/old-site.com\/media\/hero.jpg?id=8086) !important;}&#187;]',
                '[vc_column css=&#187;.vc_custom{background-image:url(https:\/\/new-site.com\/media\/hero.jpg?id=8086) !important;}&#187;]',
            ],
            'ordinary prose in a block-markup column' => [
                'Download https:\/\/old-site.com\/media\/guide.pdf for the full guide.',
                'Download https:\/\/new-site.com\/media\/guide.pdf for the full guide.',
            ],
        ];
    }

    /**
     * @param array<string, string> $mapping
     */
    #[DataProvider('structured_target_base_cases')]
    public function testKnownStructuredUrlsAcceptTargetsWhichOpaqueTextCannotSafelyWrite(
        string $input,
        string $expected,
        array $mapping
    ): void {
        $rewriter = $this->createRewriter($mapping);

        $this->assertSame($expected, $rewriter->rewrite_known_block_markup_value($input));
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:array<string, string>}>
     */
    public static function structured_target_base_cases(): array
    {
        return [
            'trailing slash' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://new.example/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://new.example/'],
            ],
            'initial path' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://new.example/base/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://new.example/base'],
            ],
            'port' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://new.example:8443/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://new.example:8443'],
            ],
            'IPv4 address' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://192.0.2.1/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://192.0.2.1'],
            ],
            'IPv6 address' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://[2001:db8::1]/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://[2001:db8::1]'],
            ],
            'Unicode domain' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://xn--bcher-kva.example/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://bücher.example'],
            ],
        ];
    }

    public function testBlockMarkupCautiouslyRewritesEncodedSiteOriginInputValue(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<input type="hidden" value="{&quot;instance&quot;:{&quot;url&quot;:&quot;https:\/\/old-site.com\/media\/hero.jpg&quot;}}">';
        $expected = '<input type="hidden" value="{&quot;instance&quot;:{&quot;url&quot;:&quot;https:\/\/new-site.com\/media\/hero.jpg&quot;}}">';

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    public function testBlockMarkupTextOffsetFollowsAnEarlierStructuredReplacement(): void
    {
        $rewriter = $this->createRewriter([
            'https://very-long-source.example' => 'https://new.example',
        ]);
        $input = '<a href="https://very-long-source.example/page">'
            . '[vc_video link="https:\/\/very-long-source.example\/media\/video.mp4"]'
            . '</a>';
        $expected = '<a href="https://new.example/page">'
            . '[vc_video link="https:\/\/new.example\/media\/video.mp4"]'
            . '</a>';

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    public function testBlockMarkupStillUsesTheCssUrlProcessorForStyleAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<div style="background-image:url(https://old-site.com/media/hero.jpg)"></div>';
        $expected = '<div style="background-image:url(&quot;https://new-site.com/media/hero.jpg&quot;)"></div>';

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    public function testKnownBlockMarkupRewritesEscapedBlockJsonAndHtml(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:image {"src":"https:\/\/old-site.com\/img.jpg"} -->'
            . '<figure><img src="https://old-site.com/img.jpg"/></figure>'
            . '<!-- /wp:image -->';

        $result = $rewriter->rewrite_known_block_markup_value($input);

        $this->assertStringContainsString('https:\/\/new-site.com\/img.jpg', $result);
        $this->assertStringContainsString('src="https://new-site.com/img.jpg"', $result);
        $this->assertStringNotContainsString('old-site.com', $result);
    }

    public function testKnownBlockMarkupCautiouslyRewritesEmbeddedQueryUrl(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://webarchive.org?url=https://old-site.com/about">Archive</a>';
        $expected = '<a href="https://webarchive.org?url=https://new-site.com/about">Archive</a>';

        $this->assertSame($expected, $rewriter->rewrite_known_block_markup_value($input));
    }

    public function testKnownBlockMarkupRewritesMixedLiteralAndCaseVariantUrls(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://old-site.com/literal">Literal</a>'
            . '<a href="HTTPS://OLD-SITE.COM/case-variant">Case variant</a>';

        $result = $rewriter->rewrite_known_block_markup_value($input);

        $this->assertStringContainsString('https://new-site.com/literal', $result);
        $this->assertStringContainsString('https://new-site.com/case-variant', $result);
        $this->assertStringNotContainsString('old-site.com', strtolower($result));
    }

    public function testKnownBlockMarkupRewritesCaseVariantHostWithoutLiteralSourceDomain(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href=\'https://OLD-SITE.COM/case-variant\'>Case variant</a>';

        $result = $rewriter->rewrite_known_block_markup_value($input);

        $this->assertStringContainsString('https://new-site.com/case-variant', $result);
        $this->assertStringNotContainsString('old-site.com', strtolower($result));
    }

    public function testKnownBlockMarkupRewritesPunycodeAndUnicodeHostSpellings(): void
    {
        $rewriter = $this->createRewriter([
            'https://xn--bcher-kva.example' => 'https://new.example',
        ]);
        $input = '<a href="https://xn--bcher-kva.example/punycode">Punycode</a>'
            . '<a href="https://bücher.example/unicode">Unicode</a>';

        $result = $rewriter->rewrite_known_block_markup_value($input);

        $this->assertStringContainsString('https://new.example/punycode', $result);
        $this->assertStringContainsString('https://new.example/unicode', $result);
        $this->assertStringNotContainsString('xn--bcher-kva.example', $result);
        $this->assertStringNotContainsString('bücher.example', $result);
    }

    public function testKnownBlockMarkupRewritesUnicodeHostInBlockCommentJson(): void
    {
        $rewriter = $this->createRewriter([
            'https://xn--bcher-kva.example' => 'https://new.example',
        ]);
        $input = '<!-- wp:image {"src":"https://bücher.example/unicode"} -->';

        $result = $rewriter->rewrite_known_block_markup_value($input);

        $this->assertStringContainsString('https:\/\/new.example\/unicode', $result);
        $this->assertStringNotContainsString('bücher.example', $result);
    }

    public function testKnownBlockMarkupRewritesEscapedJsonAndCaseVariantHtmlTogether(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:image {"src":"https:\/\/old-site.com\/img.jpg"} -->'
            . '<figure><img src="HTTPS://OLD-SITE.COM/img.jpg"/></figure>'
            . '<!-- /wp:image -->';

        $result = $rewriter->rewrite_known_block_markup_value($input);

        $this->assertStringContainsString('https:\/\/new-site.com\/img.jpg', $result);
        $this->assertStringContainsString('src="https://new-site.com/img.jpg"', $result);
        $this->assertStringNotContainsString('old-site.com', strtolower($result));
    }

    public function testBlockMarkupCautiouslyRewritesUrlInAnUnknownAttribute(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<div data-note="https://old-site.com/not-a-url-attribute">Content</div>';

        $plain_result = $rewriter->rewrite($input);
        $block_result = $rewriter->rewrite($input, 'block_markup');

        $this->assertStringContainsString('https://new-site.com/not-a-url-attribute', $plain_result);
        $this->assertSame(
            '<div data-note="https://new-site.com/not-a-url-attribute">Content</div>',
            $block_result
        );
    }

    // --- Content type hint: null (default) uses plain text URL scanning ---

    public function testDefaultHintUsesPlainTextUrlScanning(): void
    {
        $rewriter = $this->createRewriter();
        // A plain URL string is handled by URLInTextProcessor.
        $input = 'Visit https://old-site.com/about for more.';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/about', $result);
        $this->assertStringNotContainsString('old-site.com', $result);
    }

    public function testDefaultHintStillHandlesSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        // Serialized PHP is auto-detected regardless of content type hint
        $input = serialize(['url' => 'https://old-site.com/page']);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize($result);
        $this->assertSame('https://new-site.com/page', $unserialized['url']);
    }

    public function testDefaultHintStillHandlesJson(): void
    {
        $rewriter = $this->createRewriter();
        $input = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $result = $rewriter->rewrite($input);
        $decoded = json_decode($result, true);
        $this->assertSame('https://new-site.com/api', $decoded['url']);
    }

    // --- Content type hint propagation through nested formats ---

    public function testBlockMarkupHintPropagatesThroughSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        // Serialized PHP containing a block markup string — the block_markup
        // hint should propagate so the inner text gets the block parser.
        $markup = '<!-- wp:image {"src":"https://old-site.com/img.jpg"} --><figure><img src="https://old-site.com/img.jpg"/></figure><!-- /wp:image -->';
        $input = serialize(['content' => $markup]);
        $result = $rewriter->rewrite($input, 'block_markup');
        $unserialized = unserialize($result);
        $this->assertStringNotContainsString('old-site.com', $unserialized['content']);
        $this->assertStringContainsString('new-site.com', $unserialized['content']);
    }

    public function testBlockMarkupHintPropagatesThroughJson(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<a href="https://old-site.com/page">Link</a>';
        $input = json_encode(['html' => $markup], JSON_UNESCAPED_SLASHES);
        $result = $rewriter->rewrite($input, 'block_markup');
        $decoded = json_decode($result, true);
        $this->assertStringContainsString('new-site.com/page', $decoded['html']);
        $this->assertStringNotContainsString('old-site.com', $decoded['html']);
    }

    public function testBlockMarkupHintPropagatesThroughBase64(): void
    {
        $this->markTestSkipped('Base64 processing is temporarily disabled for performance.');
    }

    public function testValueMightContainSourceDomainIgnoresSchemeEscaping(): void
    {
        $rewriter = $this->createRewriter();

        $this->assertTrue(
            $rewriter->value_might_contain_source_domain('{"url":"https:\/\/old-site.com\/page"}')
        );
        $this->assertTrue(
            $rewriter->value_might_contain_source_domain('<a href="HTTPS://OLD-SITE.COM/page">Link</a>')
        );
        $this->assertFalse(
            $rewriter->value_might_contain_source_domain('<a href="https://other-site.com/page">Link</a>')
        );
    }
}
