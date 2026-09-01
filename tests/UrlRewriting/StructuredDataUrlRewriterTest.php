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

    public function testJsonDecodesEscapedSourceHostBeforeUrlMatching(): void
    {
        $rewriter = $this->createRewriter();
        $input = '{"url":"https:\u002f\u002fold\u002dsite\u002ecom\u002fpage"}';

        $this->assertStringNotContainsString('old-site.com', $input);

        $result = json_decode($rewriter->rewrite($input), true);

        $this->assertSame('https://new-site.com/page', $result['url']);
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

    public function testSerializedPhpReachesJsonWithEscapedSourceHost(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize([
            'json' => '{"url":"https:\u002f\u002fold\u002dsite\u002ecom\u002fpage"}',
        ]);

        $this->assertStringNotContainsString('old-site.com', $input);

        $result = unserialize($rewriter->rewrite($input));
        $decoded_json = json_decode($result['json'], true);

        $this->assertSame('https://new-site.com/page', $decoded_json['url']);
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

    public function testBlockMarkupRewritesHtmlNestedInDiviBlockAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:divi/text {"module":{"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eRead \u003ca href=\u0022https:\/\/old-site.com\/about\/\u0022\u003emore\u003c\/a\u003e.\u003c\/p\u003e"}}}}} /-->';

        $result = $rewriter->rewrite($input, 'block_markup');

        $this->assertStringNotContainsString('old-site.com', $result);
        $this->assertSame(1, preg_match('/<!-- wp:divi\/text (.*) \/-->/', $result, $matches));
        $rewritten_attributes = json_decode($matches[1], true);
        $this->assertSame(
            '<p>Read <a href="https://new-site.com/about/">more</a>.</p>',
            $rewritten_attributes['module']['content']['innerContent']['desktop']['value']
        );
    }

    public function testNamespacedBlockAttributeStringsReuseStructuredFormatInference(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://much-longer.example',
        ]);
        $attributes = [
            'module' => [
                'values' => [
                    'html' => '<a href="#">Top</a><a href="https://old-site.com/html">HTML</a>',
                    'json' => '{"url":"https://old-site.com/json"}',
                    'css' => 'background-image: url(https://old-site.com/css.jpg)',
                    'serialized' => serialize(['url' => 'https://old-site.com/serialized']),
                    'serialized_css' => serialize([
                        'css' => 'background-image: url(https://old-site.com/serialized-css.jpg)',
                    ]),
                    'text' => 'Read https://old-site.com/text',
                ],
            ],
        ];
        $input = '<!-- wp:example/widget '
            . json_encode($attributes, JSON_HEX_TAG | JSON_HEX_AMP)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');

        $this->assertStringNotContainsString('old-site.com', $result);
        $this->assertSame(1, preg_match('/<!-- wp:example\/widget (.*) \/-->/', $result, $matches));
        $rewritten_attributes = json_decode($matches[1], true);
        $values = $rewritten_attributes['module']['values'];
        $this->assertSame(
            '<a href="#">Top</a><a href="https://much-longer.example/html">HTML</a>',
            $values['html']
        );
        $this->assertSame('https://much-longer.example/json', json_decode($values['json'], true)['url']);
        $this->assertSame('background-image: url(https://much-longer.example/css.jpg)', $values['css']);
        $this->assertSame('https://much-longer.example/serialized', unserialize($values['serialized'])['url']);
        $this->assertSame(
            'background-image: url(https://much-longer.example/serialized-css.jpg)',
            unserialize($values['serialized_css'])['css']
        );
        $this->assertSame('Read https://much-longer.example/text', $values['text']);
    }

    public function testNestedJsonIsCheckedBeforeTheBroadCssHint(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://much-longer.example',
        ]);
        $serialized_css = serialize([
            'css' => 'background:url(https://old-site.com/value)',
        ]);
        $json = json_encode([
            'serialized_css' => $serialized_css,
        ], JSON_UNESCAPED_SLASHES);
        $input = '<!-- wp:example/widget '
            . json_encode(['module' => ['value' => $json]], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');

        $this->assertSame(1, preg_match('/<!-- wp:example\/widget (.*) \/-->/', $result, $matches));
        $rewritten_attributes = json_decode($matches[1], true);
        $rewritten_json = json_decode($rewritten_attributes['module']['value'], true);
        $this->assertSame(
            'background:url(https://much-longer.example/value)',
            unserialize($rewritten_json['serialized_css'])['css']
        );
    }

    /**
     * Divi 5 stores values under element, part, option, breakpoint, and state
     * objects. It also uses arrays for values such as font styles and gradient
     * stops. Walk the whole tree without changing JSON scalars which cannot
     * hold URLs.
     */
    public function testDiviResponsiveAttributeTreeRewritesStringLeavesAndPreservesOtherJsonTypes(): void
    {
        $rewriter = $this->createRewriter();
        $attributes = [
            'module' => [
                'decoration' => [
                    'background' => [
                        'desktop' => [
                            'value' => [
                                'image' => [
                                    'url' => 'https://old-site.com/uploads/desktop.jpg',
                                ],
                                'gradient' => [
                                    'stops' => [
                                        ['color' => '#ffffff', 'position' => 0],
                                        ['color' => '#000000', 'position' => 100],
                                    ],
                                ],
                            ],
                            'hover' => [
                                'image' => [
                                    'url' => 'https://old-site.com/uploads/hover.jpg',
                                ],
                            ],
                        ],
                        'tablet' => [
                            'value' => [
                                'image' => [
                                    'url' => 'https://old-site.com/uploads/tablet.jpg',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'image' => [
                'innerContent' => [
                    'desktop' => [
                        'value' => [
                            'src' => 'https://old-site.com/uploads/content.jpg',
                            'id' => 123,
                            'enabled' => true,
                            'opacity' => 0.75,
                            'caption' => null,
                        ],
                    ],
                ],
            ],
            'title' => [
                'decoration' => [
                    'font' => [
                        'font' => [
                            'desktop' => [
                                'value' => [
                                    'style' => ['italic', 'underline'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $input = '<!-- wp:divi/image '
            . json_encode($attributes, JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/image');

        $this->assertSame(
            'https://new-site.com/uploads/desktop.jpg',
            $rewritten_attributes['module']['decoration']['background']['desktop']['value']['image']['url']
        );
        $this->assertSame(
            'https://new-site.com/uploads/hover.jpg',
            $rewritten_attributes['module']['decoration']['background']['desktop']['hover']['image']['url']
        );
        $this->assertSame(
            'https://new-site.com/uploads/tablet.jpg',
            $rewritten_attributes['module']['decoration']['background']['tablet']['value']['image']['url']
        );
        $this->assertSame(
            'https://new-site.com/uploads/content.jpg',
            $rewritten_attributes['image']['innerContent']['desktop']['value']['src']
        );
        $this->assertSame(
            $attributes['module']['decoration']['background']['desktop']['value']['gradient']['stops'],
            $rewritten_attributes['module']['decoration']['background']['desktop']['value']['gradient']['stops']
        );
        $rewritten_image_value = $rewritten_attributes['image']['innerContent']['desktop']['value'];
        $this->assertSame(123, $rewritten_image_value['id']);
        $this->assertTrue($rewritten_image_value['enabled']);
        $this->assertSame(0.75, $rewritten_image_value['opacity']);
        $this->assertNull($rewritten_image_value['caption']);
        $this->assertSame(
            ['italic', 'underline'],
            $rewritten_attributes['title']['decoration']['font']['font']['desktop']['value']['style']
        );
    }

    /**
     * A Divi code value can hold HTML, JSON in a script element, CSS, and
     * JavaScript at the same time. Only complete source URLs are changed.
     */
    public function testDiviCodeContentRewritesUrlsAcrossEmbeddedLanguages(): void
    {
        $rewriter = $this->createRewriter();
        $code = '<div data-api="https://old-site.com/api">Testing</div>'
            . '<script type="application/json">'
            . '[{"absolute":"https://old-site.com/from-json","count":2}]'
            . '</script>'
            . '<script>window.asset = "https://old-site.com/from-javascript.js";</script>'
            . '<style>.hero{background-image:url("https://old-site.com/from-css.jpg")}</style>';
        $input = '<!-- wp:divi/code '
            . json_encode([
                'content' => [
                    'innerContent' => [
                        'desktop' => ['value' => $code],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/code');
        $rewritten_code = $rewritten_attributes['content']['innerContent']['desktop']['value'];

        $this->assertStringNotContainsString('old-site.com', $rewritten_code);
        $this->assertStringContainsString('data-api="https://new-site.com/api"', $rewritten_code);
        $this->assertStringContainsString('https://new-site.com/from-json', $rewritten_code);
        $this->assertStringContainsString('https://new-site.com/from-javascript.js', $rewritten_code);
        $this->assertStringContainsString('https://new-site.com/from-css.jpg', $rewritten_code);
    }

    public function testDiviHtmlEntityHostRewritesWithoutLiteralSourceHostBytes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:divi/text '
            . json_encode([
                'content' => [
                    'innerContent' => [
                        'desktop' => [
                            'value' => '<a href="https://old&#45;site.com/entity-host">Entity host</a>',
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $this->assertStringNotContainsString('old-site.com', $input);

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/text');

        $this->assertSame(
            '<a href="https://new-site.com/entity-host">Entity host</a>',
            $rewritten_attributes['content']['innerContent']['desktop']['value']
        );
    }

    /**
     * Extensions may put one structured string inside another. Re-enter the
     * existing JSON and serialized-PHP parsers at every level instead of
     * stopping after the first valid outer format.
     */
    public function testDiviAttributeRewritesJsonInsideSerializedPhpInsideJson(): void
    {
        $rewriter = $this->createRewriter();
        $deepest_json = json_encode([
            'html' => '<a href="https://old-site.com/deep-html">Deep HTML</a>',
            'css' => 'background:url(https://old-site.com/deep-css.jpg)',
            'plain' => 'Read https://old-site.com/deep-text',
            'unchanged_scalars' => [7, 1.5, true, false, null],
        ], JSON_UNESCAPED_SLASHES);
        $serialized_php = serialize(['payload' => $deepest_json]);
        $outer_json = json_encode(['serialized' => $serialized_php], JSON_UNESCAPED_SLASHES);
        $input = '<!-- wp:divi/text '
            . json_encode([
                'content' => [
                    'advanced' => [
                        'extensionPayload' => [
                            'desktop' => ['value' => $outer_json],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/text');
        $rewritten_outer_json = json_decode(
            $rewritten_attributes['content']['advanced']['extensionPayload']['desktop']['value'],
            true
        );
        $rewritten_serialized_php = unserialize($rewritten_outer_json['serialized']);
        $rewritten_deepest_json = json_decode($rewritten_serialized_php['payload'], true);

        $this->assertSame(
            '<a href="https://new-site.com/deep-html">Deep HTML</a>',
            $rewritten_deepest_json['html']
        );
        $this->assertSame(
            'background:url(https://new-site.com/deep-css.jpg)',
            $rewritten_deepest_json['css']
        );
        $this->assertSame('Read https://new-site.com/deep-text', $rewritten_deepest_json['plain']);
        $this->assertSame([7, 1.5, true, false, null], $rewritten_deepest_json['unchanged_scalars']);
    }

    /**
     * Divi parent and child modules are nested block comments, rather than a
     * child block serialized as a JSON string. Each block still needs its own
     * attribute tree rewritten.
     */
    public function testNestedDiviParentAndChildBlocksRewriteIndependently(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"https://old-site.com/section.jpg"}}}}}}} -->'
            . '<!-- wp:divi/row -->'
            . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p><a href=\"https://old-site.com/child\">Child</a></p>"}}}} /-->'
            . '<!-- /wp:divi/row -->'
            . '<!-- /wp:divi/section -->';

        $result = $rewriter->rewrite($input, 'block_markup');

        $this->assertStringNotContainsString('old-site.com', $result);
        $section_attributes = $this->getBlockAttributes($result, 'wp:divi/section');
        $text_attributes = $this->getBlockAttributes($result, 'wp:divi/text');
        $this->assertSame(
            'https://new-site.com/section.jpg',
            $section_attributes['module']['decoration']['background']['desktop']['value']['image']['url']
        );
        $this->assertSame(
            '<p><a href="https://new-site.com/child">Child</a></p>',
            $text_attributes['content']['innerContent']['desktop']['value']
        );
    }

    #[DataProvider('diviInferredStringCases')]
    public function testDiviStringInferenceCoversRealisticFormats(string $input_value, string $expected_value): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:divi/text '
            . json_encode([
                'content' => [
                    'advanced' => [
                        'extensionPayload' => [
                            'desktop' => ['value' => $input_value],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/text');

        $this->assertSame(
            $expected_value,
            $rewritten_attributes['content']['advanced']['extensionPayload']['desktop']['value']
        );
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function diviInferredStringCases(): array
    {
        return [
            'HTML with encoded query data' => [
                '<p><a href="https://old-site.com/page?next=https%3A%2F%2Fold-site.com%2Finside">Link</a></p>',
                '<p><a href="https://new-site.com/page?next=https%3A%2F%2Fold-site.com%2Finside">Link</a></p>',
            ],
            'HTML custom element' => [
                '<divi-card data-src="https://old-site.com/card.jpg"></divi-card>',
                '<divi-card data-src="https://new-site.com/card.jpg"></divi-card>',
            ],
            'rich text begins with prose before its first tag' => [
                'Introduction <p><a href="https://old-site.com/after-prose">Read more</a></p>',
                'Introduction <p><a href="https://new-site.com/after-prose">Read more</a></p>',
            ],
            'JSON object' => [
                '{"url":"https://old-site.com/object"}',
                '{"url":"https://new-site.com/object"}',
            ],
            'JSON array' => [
                '["https://old-site.com/first",{"url":"https://old-site.com/second"},3,true,null]',
                '["https://new-site.com/first",{"url":"https://new-site.com/second"},3,true,null]',
            ],
            'JSON string scalar' => [
                '"https://old-site.com/scalar"',
                '"https://new-site.com/scalar"',
            ],
            'CSS with case and quoted URL' => [
                '.hero{background-image:URL("https://old-site.com/hero.jpg")}',
                '.hero{background-image:URL("https://new-site.com/hero.jpg")}',
            ],
            'serialized PHP array' => [
                serialize(['url' => 'https://old-site.com/serialized-array']),
                serialize(['url' => 'https://new-site.com/serialized-array']),
            ],
            'serialized PHP object' => [
                serialize( (object) ['url' => 'https://old-site.com/serialized-object'] ),
                serialize( (object) ['url' => 'https://new-site.com/serialized-object'] ),
            ],
            'Divi 4 shortcode retained by a converted module' => [
                '[et_pb_image src="https:\/\/old-site.com\/legacy.jpg"]',
                '[et_pb_image src="https:\/\/new-site.com\/legacy.jpg"]',
            ],
            'plain text' => [
                'Download https://old-site.com/guide.pdf.',
                'Download https://new-site.com/guide.pdf.',
            ],
            'malformed JSON falls back to cautious text' => [
                '{"url":"https://old-site.com/incomplete"',
                '{"url":"https://new-site.com/incomplete"',
            ],
            'malformed serialization falls back to cautious text' => [
                's:999:"https://old-site.com/incomplete";',
                's:999:"https://new-site.com/incomplete";',
            ],
            'JSON Unicode escape inside the source host' => [
                '{"url":"https:\/\/old\u002dsite.com\/unicode-host"}',
                '{"url":"https://new-site.com/unicode-host"}',
            ],
            'HTML character reference inside the source host' => [
                '<a href="https://old&#45;site.com/entity-host">Entity host</a>',
                '<a href="https://new-site.com/entity-host">Entity host</a>',
            ],
        ];
    }

    public function testDiviBlockRewritesRecursivelyEncodedJsonWithoutLiteralSourceHostBytes(): void
    {
        $rewriter = $this->createRewriter();
        $encoded_url = '"https:\u002f\u002fold\u002dsite\u002ecom\u002fdeep"';
        $nested_json = json_encode([
            'payload' => json_encode([
                'url' => $encoded_url,
            ], JSON_UNESCAPED_SLASHES),
        ], JSON_UNESCAPED_SLASHES);
        $input = '<!-- wp:divi/text '
            . json_encode([
                'content' => [
                    'advanced' => [
                        'extensionPayload' => [
                            'desktop' => ['value' => $nested_json],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $this->assertStringNotContainsString('old-site.com', $input);

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/text');
        $rewritten_outer_json = json_decode(
            $rewritten_attributes['content']['advanced']['extensionPayload']['desktop']['value'],
            true
        );
        $rewritten_inner_json = json_decode($rewritten_outer_json['payload'], true);

        $this->assertSame('"https://new-site.com/deep"', $rewritten_inner_json['url']);
    }

    /**
     * These cases describe known misses in the naive inference. The test
     * passes when the unsupported URL stays unchanged. This makes each miss
     * visible without making the whole suite red.
     */
    #[DataProvider('knownDiviInferenceLimitationCases')]
    public function testKnownDiviInferenceLimitationsRemainUnchanged(string $input_value): void
    {
        $rewriter = $this->createRewriter();
        $advanced_attributes = [
            'extensionPayload' => [
                'desktop' => ['value' => $input_value],
            ],
        ];
        $input = '<!-- wp:divi/text '
            . json_encode([
                'content' => [
                    'advanced' => $advanced_attributes,
                ],
            ], JSON_UNESCAPED_SLASHES)
            . ' /-->';

        $result = $rewriter->rewrite($input, 'block_markup');
        $rewritten_attributes = $this->getBlockAttributes($result, 'wp:divi/text');

        $this->assertSame(
            $input_value,
            $rewritten_attributes['content']['advanced']['extensionPayload']['desktop']['value']
        );
    }

    /**
     * @return array<string, array{0:string, 1?:bool}>
     */
    public static function knownDiviInferenceLimitationCases(): array
    {
        return [
            'relative HTML URL has no safe source base' => [
                '<a href="/about">About</a>',
            ],
            'base64 payload is not decoded' => [
                base64_encode('{"url":"https://old-site.com/base64"}'),
            ],
            'CSS escape splits the source host bytes' => [
                '.hero{background:url(https://old\\2d site.com/css-escape.jpg)}',
            ],
        ];
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
    public function testKnownStructuredUrlsUseTargetBase(
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
                '<a href="https://new.example/base/media/image.jpg">Image</a>[vc_video link="https:\/\/new.example\/base\/media\/video.mp4"]',
                ['https://old-site.com' => 'https://new.example/base'],
            ],
            'port' => [
                '<a href="https://old-site.com/media/image.jpg">Image</a>[vc_video link="https:\/\/old-site.com\/media\/video.mp4"]',
                '<a href="https://new.example:8443/media/image.jpg">Image</a>[vc_video link="https:\/\/new.example:8443\/media\/video.mp4"]',
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
        // A plain URL string is handled by the cautious text processor.
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
        $this->assertTrue(
            $rewriter->value_might_contain_source_domain('{"url":"https://old\u002dsite.com/page"}')
        );
        $this->assertTrue(
            $rewriter->value_might_contain_source_domain('<a href="https://old&#45;site.com/page">Link</a>')
        );
    }

    // --- cache bounds ---

    /** Reads a private member so the bound itself is asserted, not a proxy for it. */
    private function readPrivate(StructuredDataUrlRewriter $rewriter, string $member)
    {
        $property = new ReflectionProperty($rewriter, $member);
        $property->setAccessible(true);

        return $property->getValue($rewriter);
    }

    public function testOversizedValuesSkipTheCacheButAreStillRewritten(): void
    {
        $rewriter = $this->createRewriter();

        $unit = '<a href="https://old-site.com/page">Link</a>';
        $oversized = str_repeat($unit, (int) ceil(70000 / strlen($unit)));
        $this->assertGreaterThan(65536, strlen($oversized));

        $result = $rewriter->rewrite($oversized);

        $this->assertStringNotContainsString('old-site.com', $result);
        $this->assertSame(
            substr_count($oversized, 'old-site.com'),
            substr_count($result, 'new-site.com')
        );
        $this->assertSame([], $this->readPrivate($rewriter, 'value_rewrite_cache')['data']);

        // A repeat of the same oversized value must rewrite identically
        // even though nothing was cached for it.
        $this->assertSame($result, $rewriter->rewrite($oversized));
    }

    public function testBoundedCacheStaysWithinItsByteBudget(): void
    {
        $rewriter = $this->createRewriter();

        $reflection = new ReflectionClass($rewriter);
        $budget = $reflection->getConstant('URL_REWRITE_CACHE_MAX_TOTAL_BYTES');

        // Enough distinct URLs to overflow the budget several times over, so
        // eviction has to run. Driven through the URL cache because it shares
        // store_in_bounded_cache() with the value cache but has a far smaller
        // budget, keeping the test well inside PHP's default memory_limit.
        $markup = '';
        for ($i = 0; $i < 6000; $i++) {
            $markup .= '<a href="https://old-site.com/page-' . $i . '">x</a>';
        }

        $rewriter->rewrite($markup, 'block_markup');

        $cache = $this->readPrivate($rewriter, 'url_rewrite_cache');
        $this->assertLessThanOrEqual($budget, $cache['bytes']);
        $this->assertNotEmpty($cache['data']);
        $this->assertLessThan(6000, count($cache['data']), 'Expected eviction to drop the oldest entries.');
    }

    public function testInlineDataUriIsNotCachedByTheUrlCache(): void
    {
        $rewriter = $this->createRewriter();

        // An inline image is ordinary in post content, and its whole payload
        // would otherwise become the cache key.
        $data_uri = 'data:image/png;base64,' . base64_encode(str_repeat('p', 300000));
        $markup = '<figure><img src="' . $data_uri . '"/>'
            . '<a href="https://old-site.com/p">x</a></figure>';

        $result = $rewriter->rewrite($markup, 'block_markup');

        // The data: URI survives untouched and the real URL is still rewritten.
        $this->assertStringContainsString($data_uri, $result);
        $this->assertStringContainsString('https://new-site.com/p', $result);

        $this->assertLessThan(
            strlen($data_uri),
            $this->readPrivate($rewriter, 'url_rewrite_cache')['bytes'],
            'The data: URI payload must not be retained as a cache key.'
        );
    }

    public function testRewritingTheSameValueTwiceDoesNotDoubleCountCacheBytes(): void
    {
        $rewriter = $this->createRewriter();
        $value = '<a href="https://old-site.com/page">Link</a>';

        $rewriter->rewrite($value);
        $after_first = $this->readPrivate($rewriter, 'value_rewrite_cache')['bytes'];

        $rewriter->rewrite($value);

        $cache = $this->readPrivate($rewriter, 'value_rewrite_cache');
        $this->assertSame($after_first, $cache['bytes']);
        $this->assertCount(1, $cache['data']);
    }

    /**
     * Each URL appears twice, so the second occurrence is served from the URL
     * cache. A cached entry stores its parsed URL serialized, and a relative
     * URL cannot be re-derived from its raw form alone — the processor's base
     * is the source host — so this locks the round-trip for every shape.
     */
    public function testCachedUrlHitsRewriteIdenticallyForEveryUrlShape(): void
    {
        $shapes = [
            'absolute'          => ['https://old-site.com/uploads/a.jpg', 'https://new-site.com/uploads/a.jpg'],
            'relative path'     => ['/uploads/b.jpg', '/uploads/b.jpg'],
            'protocol-relative' => ['//old-site.com/uploads/c.jpg', '/uploads/c.jpg'],
            'relative no-slash' => ['uploads/d.jpg', '/uploads/d.jpg'],
            'relative dotted'   => ['../uploads/e.jpg', '/uploads/e.jpg'],
            'query embedded'    => ['/page?ref=https://old-site.com/x', '/page?ref=https://new-site.com/x'],
        ];

        $rewriter = $this->createRewriter();

        foreach ($shapes as $label => [$input, $expected]) {
            $markup = '<figure><img src="' . $input . '"/><img src="' . $input . '"/></figure>';
            $result = $rewriter->rewrite($markup, 'block_markup');

            $this->assertSame(
                '<figure><img src="' . $expected . '"/><img src="' . $expected . '"/></figure>',
                $result,
                "Cached hit diverged from the first rewrite for: {$label}"
            );
        }
    }

    public function testUrlCacheRetainsNoObjects(): void
    {
        $rewriter = $this->createRewriter();
        $rewriter->rewrite('<a href="https://old-site.com/page">x</a>', 'block_markup');

        $cache = $this->readPrivate($rewriter, 'url_rewrite_cache');
        $this->assertNotEmpty($cache['data']);

        foreach ($cache['data'] as $entry) {
            $parts = (array) $entry;

            foreach ($parts as $part) {
                $this->assertIsNotObject($part, 'The URL cache must hold no live object graph.');
            }
        }
    }

    /**
     * Read one block's parsed attribute object from rewritten markup.
     *
     * Using the production block processor here avoids adding another test
     * regex for namespaced block names and block-comment JSON.
     *
     * @return array<int|string, mixed> Parsed block attributes.
     */
    private function getBlockAttributes(string $markup, string $block_name): array
    {
        $processor = new StructuredBlockMarkupUrlProcessor($markup);
        while ($processor->next_token()) {
            if (
                '#block-comment' === $processor->get_token_type()
                && $block_name === $processor->get_block_name()
            ) {
                $attributes = $processor->get_block_attributes();
                if (is_array($attributes)) {
                    return $attributes;
                }
            }
        }

        $this->fail("Expected to find parsed attributes for block {$block_name}.");
    }
}
