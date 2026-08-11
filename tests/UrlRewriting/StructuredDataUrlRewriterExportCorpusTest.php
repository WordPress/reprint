<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class StructuredDataUrlRewriterExportCorpusTest extends TestCase {
    private const SOURCE_URL = 'https://source.example/wp-content/uploads';
    private const TARGET_URL = 'https://destination.example';

    /**
     * @dataProvider structuredSiteBuilderExports
     *
     * @param array<int, string> $suffixes
     */
    public function testRewritesUrlsInKnownStructuredSiteBuilderExports(
        string $format,
        string $input,
        array $suffixes,
        ?string $content_type = null
    ): void {
        $output = $this->createRewriter()->rewrite($input, $content_type);

        $value_to_inspect = $output;

        if ($format === 'json') {
            $this->assertNotNull(json_decode($output, true));
        }

        if ($format === 'serialized_php') {
            $unserialized = @unserialize($output);
            $this->assertNotFalse($unserialized);
            $value_to_inspect = json_encode($unserialized, JSON_UNESCAPED_SLASHES);
        }

        $this->assertStringNotContainsString('source.example', $value_to_inspect);
        $this->assertSame(
            count($suffixes),
            substr_count($value_to_inspect, 'destination.example')
        );
        foreach ($suffixes as $suffix) {
            $this->assertStringContainsString(
                substr($suffix, strrpos($suffix, '/') + 1),
                $value_to_inspect
            );
        }
    }

    /**
     * @return iterable<string, array{string, string, array<int, string>, string|null}>
     */
    public static function structuredSiteBuilderExports(): iterable
    {
        yield 'Elementor document with image and background controls' => [
            'json',
            '{"title":"Home","type":"page","version":"0.4","content":['
            . '{"id":"a1b2c3","elType":"section","settings":{'
            . '"background_background":"classic","background_image":{'
            . '"url":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg?ver=1"}}},'
            . '{"id":"d4e5f6","elType":"widget","widgetType":"image","settings":{'
            . '"image":{"url":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/logo.svg#mark"}}}]}',
            ['/wp-content/uploads/2026/01/hero.jpg?ver=1', '/wp-content/uploads/2026/01/logo.svg#mark'],
            null,
        ];

        yield 'Elementor JSON containing a WPBakery shortcode leaf' => [
            'json',
            '{"content":"[vc_row css=\\".vc_custom{background:url(https:\\\\/\\\\/source.example\\\\/wp-content\\\\/uploads\\\\/2026\\\\/01\\\\/hero.jpg)}\\"]"}',
            ['/wp-content/uploads/2026/01/hero.jpg'],
            null,
        ];

        yield 'top-level WPBakery shortcode' => [
            'shortcode',
            '[read_more_button url="https://source.example/wp-content/uploads/2026/01/read-more/" top_margin="page_margin_top"]',
            ['/wp-content/uploads/2026/01/read-more/'],
            null,
        ];

        yield 'JSON array beginning with null before a URL string' => [
            'json',
            '[null,"https://source.example/wp-content/uploads/2026/01/read-more/"]',
            ['/wp-content/uploads/2026/01/read-more/'],
            null,
        ];

        yield 'HTML data-settings attribute containing JSON Unicode escapes' => [
            'html',
            '<div data-settings=\'{"image":"https:\\u002F\\u002Fsource.example\\u002Fwp-content\\u002Fuploads\\u002F2026\\u002F01\\u002Fhero.jpg"}\'></div>',
            ['/wp-content/uploads/2026/01/hero.jpg'],
            null,
        ];

        yield 'JSON-LD script in block markup' => [
            'block_markup',
            '<script type="application/ld+json; charset=utf-8">'
            . '{"@context":"https://source.example/wp-content/uploads/schema",'
            . '"image":{"url":"https://source.example/wp-content/uploads/2026/01/hero.jpg"}}'
            . '</script>',
            ['/wp-content/uploads/schema', '/wp-content/uploads/2026/01/hero.jpg'],
            StructuredDataUrlRewriter::BLOCK_MARKUP,
        ];

        yield 'Breakdance style JSON with a nested CSS declaration' => [
            'json',
            '{"data":{"tree":{"root":{"type":"section","properties":{'
            . '"background":{"image":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/cover.webp"},'
            . '"custom_css":".root{mask:url(https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/mask.svg#shape)}"}}}}}',
            ['/wp-content/uploads/2026/01/cover.webp', '/wp-content/uploads/2026/01/mask.svg#shape'],
            null,
        ];

        yield 'SiteOrigin style widget configuration' => [
            'json',
            '{"widgets":[{"panels_info":{"class":"SiteOrigin_Widget_Image_Widget"},'
            . '"image":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/panel.jpg"},'
            . '{"panels_info":{"class":"SiteOrigin_Widget_Hero_Widget"},'
            . '"frames":[{"background":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/frame.jpg"}]}]}',
            ['/wp-content/uploads/2026/01/panel.jpg', '/wp-content/uploads/2026/01/frame.jpg'],
            null,
        ];

        yield 'Beaver Builder serialized layout with nested module settings' => [
            'serialized_php',
            serialize([
                'nodes' => [
                    'node-a' => [
                        'type' => 'photo',
                        'settings' => [
                            'photo_src' => 'https://source.example/wp-content/uploads/2026/01/photo.jpg',
                            'link_url' => 'https://source.example/wp-content/uploads/2026/01/read-more.pdf?download=1',
                        ],
                    ],
                    'node-b' => [
                        'type' => 'html',
                        'settings' => [
                            'html' => '<div style="background:url(https:\/\/source.example\/wp-content\/uploads\/2026\/01\/texture.png);"></div>',
                        ],
                    ],
                ],
            ]),
            [
                '/wp-content/uploads/2026/01/photo.jpg',
                '/wp-content/uploads/2026/01/read-more.pdf?download=1',
                '/wp-content/uploads/2026/01/texture.png',
            ],
            null,
        ];

        yield 'Avada serialized options containing raw CSS' => [
            'serialized_php',
            serialize([
                'fusion_builder' => [
                    'background_image' => 'https://source.example/wp-content/uploads/2026/01/cover.jpg',
                    'custom_css' => '.fusion-builder-row{background-image:url(https://source.example/wp-content/uploads/2026/01/row.jpg);}',
                ],
            ]),
            ['/wp-content/uploads/2026/01/cover.jpg', '/wp-content/uploads/2026/01/row.jpg'],
            null,
        ];

        yield 'Gutenberg block markup with HTML image and JSON block attributes' => [
            'block_markup',
            '<!-- wp:cover {"url":"https://source.example/wp-content/uploads/2026/01/cover.jpg",'
            . '"id":42,"dimRatio":50} --><div class="wp-block-cover">'
            . '<img src="https://source.example/wp-content/uploads/2026/01/cover.jpg" alt="">'
            . '</div><!-- /wp:cover -->',
            ['/wp-content/uploads/2026/01/cover.jpg', '/wp-content/uploads/2026/01/cover.jpg'],
            StructuredDataUrlRewriter::BLOCK_MARKUP,
        ];

        yield 'serialized Gutenberg markup retained as a string leaf' => [
            'serialized_php',
            serialize([
                'post_content' => '<!-- wp:image {"id":12} --><figure class="wp-block-image">'
                    . '<img src="https://source.example/wp-content/uploads/2026/01/photo.jpg" alt="">'
                    . '</figure><!-- /wp:image -->',
            ]),
            ['/wp-content/uploads/2026/01/photo.jpg'],
            StructuredDataUrlRewriter::BLOCK_MARKUP,
        ];
    }

    public function testLeavesMalformedBuilderPayloadsByteIdentical(): void
    {
        $rewriter = $this->createRewriter();
        $values = [
            '{"content":[{"settings":{"image":{"url":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg"}}},]}',
            'a:1:{s:7:"content";s:999:"https://source.example/wp-content/uploads/2026/01/hero.jpg";}',
        ];

        foreach ($values as $value) {
            $this->assertSame($value, $rewriter->rewrite($value));
        }
    }

    public function testLeavesMalformedJsonInADataSettingsAttributeByteIdentical(): void
    {
        $input = '<div data-settings=\'{"image":"https:\\u002F\\u002Fsource.example\\u002Fwp-content\\u002Fuploads\\u002F2026\\u002F01\\u002Fhero.jpg",}\'></div>';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testLeavesMalformedJsonLdScriptByteIdentical(): void
    {
        $input = '<script type="application/ld+json">'
            . '{"@context":"https://source.example/wp-content/uploads/schema",}'
            . '</script>';

        $this->assertSame(
            $input,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    public function testWritesUnicodeDestinationDomainsAsPunycodeInAnElementorDocument(): void
    {
        $input = '{"content":[{"settings":{"image":{"url":"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/logo.png"}}}]}';
        $output = ( new StructuredDataUrlRewriter([
            self::SOURCE_URL => 'https://xn--bcher-kva.example',
        ]) )->rewrite($input);

        $this->assertStringContainsString('xn--bcher-kva.example', $output);
        $this->assertStringNotContainsString('bücher.example', $output);
        $this->assertSame(
            'https://xn--bcher-kva.example/wp-content/uploads/2026/01/logo.png',
            json_decode($output, true)['content'][0]['settings']['image']['url']
        );
    }

    private function createRewriter(): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter([self::SOURCE_URL => self::TARGET_URL]);
    }
}
