<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class SiteBuilderPostContentClassificationTest extends TestCase {
    /**
     * This corpus states the desired result for post_content values that mix
     * builders, shortcodes, HTML, CSS, JSON, PHP serialization, and nested
     * encodings. Several cases are intentionally red until classification can
     * select the owning processor for each embedded value.
     *
     * @param string $input Original post_content value.
     * @param string $expected Post_content with only the mapped host changed.
     */
    #[DataProvider('site_builder_post_content_cases')]
    public function testPostContentRewritesSiteBuilderValues(
        string $input,
        string $expected
    ): void {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $encoded_input = base64_encode($input);
        $sql = "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, FROM_BASE64('{$encoded_input}'));";

        $scanner = new Base64ValueScanner($rewriter->rewrite($sql));
        $this->assertTrue($scanner->next_value());
        $this->assertSame($expected, $scanner->get_value());
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function site_builder_post_content_cases(): array
    {
        $rewrite_domain = static function (string $input): array {
            return [$input, str_replace('old-site.com', 'new-site.com', $input)];
        };

        return [
            'HTML image attribute' => $rewrite_domain(
                '<img src="https://old-site.com/uploads/logo.png">'
            ),
            'block comment image attribute' => $rewrite_domain(
                '<!-- wp:image {"url":"https://old-site.com/uploads/logo.png"} /-->'
            ),
            'Divi 4 background image shortcode' => $rewrite_domain(
                '[et_pb_section background_image="https://old-site.com/uploads/hero.jpg"][/et_pb_section]'
            ),
            'WPBakery escaped video shortcode' => $rewrite_domain(
                '[vc_video link="https:\\/\\/old-site.com\\/uploads\\/tour.mp4"]'
            ),
            'Elementor JSON document' => $rewrite_domain(
                '{"version":"0.4","content":[{"elType":"widget","settings":{"image":{"url":"https://old-site.com/uploads/logo.png"}}}]}'
            ),
            'serialized PHP array' => $rewrite_domain(
                'a:1:{s:5:"image";s:37:"https://old-site.com/uploads/logo.png";}'
            ),
            'SiteOrigin JSON in an HTML input value' => $rewrite_domain(
                '<input type="hidden" value="{&quot;url&quot;:&quot;https:\\/\\/old-site.com\\/uploads\\/logo.png&quot;}">'
            ),
            'Elementor JSON in a data-settings attribute' => $rewrite_domain(
                '<div data-settings="{&quot;background_background&quot;:&quot;classic&quot;,&quot;background_image&quot;:{&quot;url&quot;:&quot;https://old-site.com/uploads/hero.jpg&quot;}}"></div>'
            ),
            'Beaver Builder JSON in a data-node attribute' => $rewrite_domain(
                '<div class="fl-module" data-node="{&quot;photo&quot;:&quot;https:\\/\\/old-site.com\\/uploads\\/photo.jpg&quot;}"></div>'
            ),
            'Divi shortcode in a block attribute' => $rewrite_domain(
                '<!-- wp:reprint/shortcode {"content":"[et_pb_image src=\\\"https:\\/\\/old-site.com\\/uploads\\/logo.png\\\"]"} /-->'
            ),
            'WPBakery shortcode in a block attribute' => $rewrite_domain(
                '<!-- wp:reprint/shortcode {"content":"[vc_video link=\\\"https://old-site.com/uploads/tour.mp4\\\"]"} /-->'
            ),
            'Kadence block attribute containing a shortcode' => $rewrite_domain(
                '<!-- wp:kadence/advancedbtn {"link":"[vc_video link=\\\"https:\\/\\/old-site.com\\/uploads\\/tour.mp4\\\"]"} /-->'
            ),
            'Spectra block attribute with percent-encoded URL' => $rewrite_domain(
                '<!-- wp:uagb/image {"url":"https%3A%2F%2Fold-site.com%2Fuploads%2Flogo.png"} /-->'
            ),
            'Divi CSS URL with percent-encoded separators' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https%3A%2F%2Fold-site.com%2Fuploads%2Fhero.jpg)"][/et_pb_section]'
            ),
            'Divi CSS URL with hexadecimal escapes' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https\\3a \\2f \\2f old-site.com\\2f uploads\\2f hero.jpg)"][/et_pb_section]'
            ),
            'Divi CSS URL with HTML entities' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https&#58;//old-site.com/uploads/hero.jpg)"][/et_pb_section]'
            ),
            'WPBakery CSS URL with hexadecimal escapes' => $rewrite_domain(
                '[vc_column css=".vc_custom{background-image:url(https\\3a \\2f \\2f old-site.com\\2f uploads\\2f hero.jpg)}"]'
            ),
            'WPBakery raw HTML Base64 body' => [
                '[vc_raw_html]' . base64_encode('<a href="https://old-site.com/manual.pdf">Manual</a>') . '[/vc_raw_html]',
                '[vc_raw_html]' . base64_encode('<a href="https://new-site.com/manual.pdf">Manual</a>') . '[/vc_raw_html]',
            ],
            'Divi Base64 module payload' => [
                '[et_pb_code]' . base64_encode('{"url":"https://old-site.com/uploads/hero.jpg"}') . '[/et_pb_code]',
                '[et_pb_code]' . base64_encode('{"url":"https://new-site.com/uploads/hero.jpg"}') . '[/et_pb_code]',
            ],
            'Elementor Base64 document in an attribute' => [
                '<div data-elementor-data="' . base64_encode('{"url":"https://old-site.com/uploads/hero.jpg"}') . '"></div>',
                '<div data-elementor-data="' . base64_encode('{"url":"https://new-site.com/uploads/hero.jpg"}') . '"></div>',
            ],
            'serialized PHP string containing a Base64 document' => [
                serialize(['builder' => base64_encode('{"url":"https://old-site.com/uploads/hero.jpg"}')]),
                serialize(['builder' => base64_encode('{"url":"https://new-site.com/uploads/hero.jpg"}')]),
            ],
            'JSON document containing a Base64 shortcode' => [
                json_encode(['content' => base64_encode('[et_pb_image src="https://old-site.com/uploads/logo.png"]')]),
                json_encode(['content' => base64_encode('[et_pb_image src="https://new-site.com/uploads/logo.png"]')]),
            ],
            'Avada shortcode JSON attribute' => $rewrite_domain(
                '[fusion_builder_container settings="{&quot;background_image&quot;:&quot;https:\\/\\/old-site.com\\/uploads\\/hero.jpg&quot;}"][/fusion_builder_container]'
            ),
            'Themify JSON shortcode attribute' => $rewrite_domain(
                '[themify_box settings="{&quot;image&quot;:&quot;https://old-site.com/uploads/hero.jpg&quot;}"]content[/themify_box]'
            ),
            'Oxygen shortcode JSON attribute' => $rewrite_domain(
                '[ct_section options="{&quot;background-image&quot;:&quot;https:\\/\\/old-site.com\\/uploads\\/hero.jpg&quot;}"][/ct_section]'
            ),
            'Brizy compact JSON document' => $rewrite_domain(
                '{"data":[{"type":"image","value":"https:\\/\\/old-site.com\\/uploads\\/hero.jpg"}]}'
            ),
            'serialized Elementor document with a shortcode leaf' => $rewrite_domain(
                'a:1:{s:7:"content";s:62:"[et_pb_image src=\"https:\\/\\/old-site.com\\/uploads\\/logo.png\"]";}'
            ),
            'JSON document with a shortcode leaf' => $rewrite_domain(
                '{"content":"[vc_video link=\\\"https:\\/\\/old-site.com\\/uploads\\/tour.mp4\\\"]"}'
            ),
            'serialized PHP object with a URL property' => $rewrite_domain(
                'O:8:"stdClass":1:{s:3:"url";s:37:"https://old-site.com/uploads/logo.png";}'
            ),
            'serialized PHP object with private builder data' => $rewrite_domain(
                'O:11:"BuilderState":1:{s:16:"\\0BuilderState\\0url";s:37:"https://old-site.com/uploads/logo.png";}'
            ),
            'JSON document with Unicode URL separators' => $rewrite_domain(
                '{"url":"https\\u003A\\u002F\\u002Fold-site.com\\u002Fuploads\\u002Flogo.png"}'
            ),
            'JSON document with an escaped source host' => $rewrite_domain(
                '{"url":"https://old\\u002dsite\\u002ecom/uploads/logo.png"}'
            ),
            'Gutenberg HTML comment containing entity-quoted JSON' => $rewrite_domain(
                '<!-- wp:html --><div data-config="{&quot;url&quot;:&quot;https:\\/\\/old-site.com\\/uploads\\/logo.png&quot;}"></div><!-- /wp:html -->'
            ),
            'shortcode body with a percent-encoded HTML link' => $rewrite_domain(
                '[vc_column_text]%3Ca%20href%3D%22https%3A%2F%2Fold-site.com%2Fmanual.pdf%22%3EManual%3C%2Fa%3E[/vc_column_text]'
            ),
            'shortcode body with a Base64 HTML link' => [
                '[vc_column_text]' . base64_encode('<a href="https://old-site.com/manual.pdf">Manual</a>') . '[/vc_column_text]',
                '[vc_column_text]' . base64_encode('<a href="https://new-site.com/manual.pdf">Manual</a>') . '[/vc_column_text]',
            ],
            'HTML data URI containing a Base64 SVG link' => [
                '<img src="data:image/svg+xml;base64,' . base64_encode('<svg><image href="https://old-site.com/logo.png"/></svg>') . '">',
                '<img src="data:image/svg+xml;base64,' . base64_encode('<svg><image href="https://new-site.com/logo.png"/></svg>') . '">',
            ],
            'JSON string whose URL is percent encoded' => $rewrite_domain(
                '{"url":"https%3A%2F%2Fold-site.com%2Fuploads%2Flogo.png"}'
            ),
            'serialized PHP URL with HTML entities' => $rewrite_domain(
                'a:1:{s:3:"url";s:46:"https&#58;//old-site.com/uploads/logo.png";}'
            ),
            'Elementor style attribute with a hexadecimal escaped URL' => $rewrite_domain(
                '<div style="background-image:url(https\\3a \\2f \\2f old-site.com\\2f uploads\\2f hero.jpg)"></div>'
            ),
            'Divi module URL in an HTML comment' => $rewrite_domain(
                '<!-- [et_pb_image src="https://old-site.com/uploads/logo.png"] -->'
            ),
        ];
    }
}
