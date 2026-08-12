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
            'Base64 shortcode body containing block markup' => [
                '[et_pb_code]' . base64_encode('<!-- wp:image {"url":"https://old-site.com/uploads/logo.png"} /-->') . '[/et_pb_code]',
                '[et_pb_code]' . base64_encode('<!-- wp:image {"url":"https://new-site.com/uploads/logo.png"} /-->') . '[/et_pb_code]',
            ],
            'Base64 shortcode body containing CSS' => [
                '[vc_raw_html]' . base64_encode('.hero{background:url(https://old-site.com/uploads/hero.jpg)}') . '[/vc_raw_html]',
                '[vc_raw_html]' . base64_encode('.hero{background:url(https://new-site.com/uploads/hero.jpg)}') . '[/vc_raw_html]',
            ],
            'Base64 shortcode body containing JSON-LD' => [
                '[et_pb_code]' . base64_encode('{"@context":"https://schema.org","image":"https://old-site.com/uploads/logo.png"}') . '[/et_pb_code]',
                '[et_pb_code]' . base64_encode('{"@context":"https://schema.org","image":"https://new-site.com/uploads/logo.png"}') . '[/et_pb_code]',
            ],
            'JSON document containing block markup' => $rewrite_domain(
                '{"content":"<!-- wp:image {\\"url\\":\\"https:\\/\\/old-site.com\\/uploads\\/logo.png\\"} /-->"}'
            ),
            'JSON document containing HTML and a shortcode' => $rewrite_domain(
                '{"html":"<p>[et_pb_image src=\\"https:\\/\\/old-site.com\\/uploads\\/logo.png\\"]</p>"}'
            ),
            'serialized PHP array containing block markup' => [
                serialize(['content' => '<!-- wp:image {"url":"https://old-site.com/uploads/logo.png"} /-->']),
                serialize(['content' => '<!-- wp:image {"url":"https://new-site.com/uploads/logo.png"} /-->']),
            ],
            'serialized PHP array containing HTML and a shortcode' => [
                serialize(['content' => '<p>[vc_video link="https://old-site.com/uploads/tour.mp4"]</p>']),
                serialize(['content' => '<p>[vc_video link="https://new-site.com/uploads/tour.mp4"]</p>']),
            ],
            'block markup with shortcode CSS and JSON-LD' => $rewrite_domain(
                '<!-- wp:html --><style>.hero{background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg)}</style><p>[et_pb_image src="https:\\/\\/old-site.com\\/uploads\\/logo.png"]</p><script type="application/ld+json">{"image":"https:\\/\\/old-site.com\\/uploads\\/logo.png"}</script><!-- /wp:html -->'
            ),
            'CSS between Divi opener and closer' => $rewrite_domain(
                '[et_pb_section] .hero{background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg) no-repeat center} [/et_pb_section]'
            ),
            'CSS between WPBakery opener and closer' => $rewrite_domain(
                '[vc_column_text].hero{background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg)}[/vc_column_text]'
            ),
            'Divi CSS preserves Unicode string escapes' => $rewrite_domain(
                '[et_pb_section custom_css_main_element=\'font-family:"R\\00fc bik";content:"\\1f6a4 ";background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg) no-repeat center center fixed;\'][/et_pb_section]'
            ),
            'Divi CSS preserves a literal Unicode character' => $rewrite_domain(
                '[et_pb_section custom_css_main_element=\'font-family:"Rubik 🚤";background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg)\'][/et_pb_section]'
            ),
            'Divi CSS URL with six-digit Unicode escapes' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https\\00003a\\00002f\\00002fold-site.com\\00002fuploads\\00002fhero.jpg)"][/et_pb_section]'
            ),
            'Divi CSS URL with uppercase Unicode escapes' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https\\3A \\2F \\2F old-site.com\\2F uploads\\2F hero.jpg)"][/et_pb_section]'
            ),
            'Divi CSS URL with an escaped source host' => [
                '[et_pb_section custom_css_main_element="background:url(https\\3a \\2f \\2f old\\2dsite\\2ecom\\2f uploads\\2f hero.jpg)"][/et_pb_section]',
                '[et_pb_section custom_css_main_element="background:url(https\\3a \\2f \\2f new-site.com\\2f uploads\\2f hero.jpg)"][/et_pb_section]',
            ],
            'Divi CSS URL with Unicode escapes in its path' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https:\\/\\/old-site.com\\/uploads\\/hero\\00002ejpg)"][/et_pb_section]'
            ),
            'Divi CSS URL with a Unicode-escaped query value' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg?caption=\\1f6a4 )"][/et_pb_section]'
            ),
            'Divi CSS comment beside a rewritten URL' => $rewrite_domain(
                '[et_pb_section custom_css_main_element="/* fallback \\1f6a4 */ background:url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg)"][/et_pb_section]'
            ),
            'HTML style CSS with a literal URL and Unicode escapes' => $rewrite_domain(
                '<div style="font-family: R\\00fc bik; background-image: url(https:\\/\\/old-site.com\\/uploads\\/hero.jpg)"></div>'
            ),
            'JSON-LD script beside a shortcode in block markup' => $rewrite_domain(
                '<!-- wp:html --><script type="application/ld+json">{"image":"https:\\/\\/old-site.com\\/uploads\\/logo.png"}</script>[vc_video link="https:\\/\\/old-site.com\\/uploads\\/tour.mp4"]<!-- /wp:html -->'
            ),
        ];
    }
}
