<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class URLInPotentiallyStructuredTextProcessorExportCorpusTest extends TestCase {
    private const SOURCE_URL = 'https://source.example/wp-content/uploads';
    private const TARGET_URL = 'https://destination.example/wp-content/uploads';

    /**
     * These values are deliberately opaque text leaves. They model strings
     * selected by a structured-data rewriter after a database export has
     * passed through a page builder, a shortcode, or an HTML entity layer.
     * Do not add complete PHP serializations, JSON documents, or known block
     * markup here: their owning processors must preserve those formats. The
     * only permitted textual change is the source authority.
     *
     * @dataProvider opaqueExportFragments
     */
    public function testRewritesOnlyTheDomainInOpaqueExportFragments(string $input): void
    {
        $this->assertSame(
            str_replace('source.example', 'destination.example', $input),
            $this->rewrite($input)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function opaqueExportFragments(): iterable
    {
        // Visual Composer and WPBakery values often put CSS inside a shortcode
        // attribute, sometimes after another escaping or entity-encoding pass.
        yield 'WPBakery CSS URL with a literal terminator' => [
            '[vc_row css=".vc_custom_1768649957493{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg);}"]',
        ];
        yield 'WPBakery CSS URL with a quoted terminator' => [
            '[vc_row css=".vc_custom_1768649957493{background-image:url(\'https://source.example/wp-content/uploads/2026/01/hero.jpg\');}"]',
        ];
        yield 'WPBakery CSS URL ending in a quote parenthesis semicolon' => [
            '[vc_row css=".vc_custom{background-image:url(\"https://source.example/wp-content/uploads/2026/01/hero.jpg\");}"]',
        ];
        yield 'WPBakery entity quoted CSS attribute' => [
            '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg?id=8086) !important;}&#187;]',
        ];
        yield 'WPBakery shortcode nested in an HTML attribute' => [
            '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{background-image:url(https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg)}&#187;]\'></div>',
        ];
        yield 'WPBakery escaped column text containing escaped block markup' => [
            '[vc_column_text]<!-- \\/wp:post-content -->\\r\\n<p style=\\"text-align:center;\\">'
            . '<img src=\\"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg\\" />'
            . '<!-- \\/wp:image -->[\\/vc_column_text]',
        ];
        yield 'WPBakery video shortcode URL' => [
            '[vc_video link="https://source.example/wp-content/uploads/2026/01/video.mp4"]',
        ];
        yield 'WPBakery single image URL with a query suffix' => [
            '[vc_single_image image="42" img_size="full" href="https://source.example/wp-content/uploads/2026/01/photo.jpg?download=1"]',
        ];
        yield 'WPBakery background CSS in a nested row' => [
            '[vc_row_inner css=".vc_custom{background:url(https://source.example/wp-content/uploads/2026/01/texture.svg) center / cover no-repeat;}"]',
        ];

        // Divi stores nested shortcodes with many unrelated attributes and,
        // in exports, the quote bytes can already be HTML entities.
        yield 'Divi section background image' => [
            '[et_pb_section fb_built="1" background_image="https://source.example/wp-content/uploads/2026/01/garage-floor.jpg" global_colors_info="{}"]',
        ];
        yield 'Divi image module URL' => [
            '[et_pb_image src="https://source.example/wp-content/uploads/2026/01/logo.png" title_text="Logo" _builder_version="4.23.1"]',
        ];
        yield 'Divi video overlay image URL' => [
            '[et_pb_video src="https://source.example/wp-content/uploads/2026/01/film.mp4" image_src="https://source.example/wp-content/uploads/2026/01/poster.jpg"]',
        ];
        yield 'Divi CSS URL in a module attribute' => [
            '[et_pb_code code=".hero{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg);}"]',
        ];

        // Avada, Themify, and similar builders leave their shortcode syntax
        // in post_content when the builder plugin is unavailable on export.
        yield 'Avada container background image' => [
            '[fusion_builder_container type="flex" background_image="https://source.example/wp-content/uploads/2026/01/cover.jpg"][fusion_builder_row][/fusion_builder_row][/fusion_builder_container]',
        ];
        yield 'Avada image frame URL' => [
            '[fusion_imageframe image_id="18" max_width="" style_type="bottomshadow" src="https://source.example/wp-content/uploads/2026/01/frame.png"]',
        ];
        yield 'Avada CSS URL in a generated class' => [
            '[fusion_text]<style>.fusion-body .fusion-builder-row{background-image:url(https://source.example/wp-content/uploads/2026/01/row.jpg)}</style>[/fusion_text]',
        ];
        yield 'Themify row background image' => [
            '[themify_row background_image="https://source.example/wp-content/uploads/2026/01/row.jpg"][col-full][/col-full][/themify_row]',
        ];
        yield 'Themify image module URL' => [
            '[themify_image src="https://source.example/wp-content/uploads/2026/01/image.webp" w="1200" h="800"]',
        ];

        // The trailing bytes are intentionally varied. A replacement must not
        // absorb a CSS terminator, an HTML delimiter, a query, or a fragment.
        yield 'inline style URL followed by a semicolon' => [
            '<div style="background:url(https://source.example/wp-content/uploads/2026/01/hero.jpg);"></div>',
        ];
        yield 'inline style URL followed by a quote parenthesis semicolon' => [
            '<div style="background:url(\"https://source.example/wp-content/uploads/2026/01/hero.jpg\");"></div>',
        ];
        yield 'CSS image-set with two URLs' => [
            '.hero{background-image:image-set(url(https://source.example/wp-content/uploads/2026/01/hero.png) 1x,url(https://source.example/wp-content/uploads/2026/01/hero@2x.png) 2x);}',
        ];
        yield 'CSS URL with a fragment' => [
            'mask:url(https://source.example/wp-content/uploads/2026/01/sprite.svg#star);',
        ];
        yield 'CSS URL with a percent encoded slash in the suffix' => [
            'background:url(https://source.example/wp-content/uploads/2026/01/raw%2Ffile.png);',
        ];
        yield 'HTML image URL with an HTML entity suffix' => [
            '<img src="https://source.example/wp-content/uploads/2026/01/photo.jpg?width=800&amp;height=600" alt="">',
        ];
        yield 'HTML source set candidates separated by a comma and space' => [
            '<img srcset="https://source.example/wp-content/uploads/2026/01/photo.jpg 1x, https://source.example/wp-content/uploads/2026/01/photo@2x.jpg 2x">',
        ];
        yield 'Markdown image destination' => [
            '![A beach](https://source.example/wp-content/uploads/2026/01/beach.jpg "Beach")',
        ];
        yield 'Markdown autolink destination' => [
            '<https://source.example/wp-content/uploads/2026/01/readme.pdf>',
        ];
        yield 'plain text URL followed by punctuation' => [
            'Download https://source.example/wp-content/uploads/2026/01/brochure.pdf, then share it.',
        ];
        yield 'URL-valued query parameter after an equals sign' => [
            'https://archive.example/export?redirect=https://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'two literal URLs in one text leaf' => [
            'https://source.example/wp-content/uploads/2026/01/a.jpg https://source.example/wp-content/uploads/2026/01/b.jpg',
        ];
        yield 'two escaped URLs in one text leaf' => [
            'https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/a.jpg https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/b.jpg',
        ];
        yield 'escaped colon and slashes' => [
            'url(https\\:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg);',
        ];
        yield 'only the first protocol slash escaped' => [
            'url(https:\\//source.example/wp-content/uploads/2026/01/hero.jpg);',
        ];
        yield 'only the second protocol slash escaped' => [
            'url(https:/\\/source.example/wp-content/uploads/2026/01/hero.jpg);',
        ];
        yield 'only the colon escaped' => [
            'url(https\\://source.example/wp-content/uploads/2026/01/hero.jpg);',
        ];
        yield 'escaped colon and first protocol slash' => [
            'url(https\\:\\//source.example/wp-content/uploads/2026/01/hero.jpg);',
        ];
        yield 'escaped colon and second protocol slash' => [
            'url(https\\:/\\/source.example/wp-content/uploads/2026/01/hero.jpg);',
        ];
    }

    /**
     * @dataProvider escapedProtocolSpellings
     */
    public function testRewritesRecognizableEscapedProtocolSpellings(string $input): void
    {
        $this->assertSame(
            str_replace('source.example', 'destination.example', $input),
            $this->rewrite($input)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapedProtocolSpellings(): iterable
    {
        yield 'doubly escaped JSON protocol in a shortcode attribute' => [
            '[builder_data value="{\\\"url\\\":\\\"https:\\\\\\/\\\\\\/source.example\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/01\\\\\\/hero.jpg\\\"}"]',
        ];
        yield 'smart quoted Divi export attribute' => [
            '[et_pb_section background_image=”https://source.example/wp-content/uploads/2026/01/hero.jpg”]',
        ];
    }

    /**
     * @dataProvider opaqueValuesWhichMustRemainByteIdentical
     */
    public function testLeavesUnsupportedOrAmbiguousOpaqueValuesByteIdentical(string $input): void
    {
        $this->assertSame($input, $this->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function opaqueValuesWhichMustRemainByteIdentical(): iterable
    {
        yield 'source domain in a CSS identifier' => [
            '.source.example-widget{background:none;}',
        ];
        yield 'source domain in a JavaScript property name' => [
            'window.source.example = { url: "not a URL" };',
        ];
        yield 'CSS hexadecimal escaped separators' => [
            'url(https\\3a \\2f \\2f source.example\\2f wp-content\\2f uploads\\2f 2026\\2f 01\\2f hero.jpg)',
        ];
        yield 'percent encoded protocol separators' => [
            'https:%2F%2Fsource.example%2Fwp-content%2Fuploads%2F2026%2F01%2Fhero.jpg',
        ];
        yield 'different source path prefix' => [
            'https://source.example/wp-content/upload-store/2026/01/hero.jpg',
        ];
        yield 'source path followed by an identifier byte' => [
            'https://source.example/wp-content/uploads-old/2026/01/hero.jpg',
        ];
        yield 'source URL embedded in an identifier' => [
            'prefixhttps://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'source URL following a plus sign' => [
            '+https://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'source URL following a hyphen' => [
            '-https://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
    }

    /**
     * @dataProvider escapedBaseSpellings
     */
    public function testNeverCreatesEscapesForADifferentTargetPath(string $scheme, string $path): void
    {
        $input = 'url(' . $scheme . 'source.example' . $path . '/2026/01/hero.jpg);';

        $this->assertSame(
            $input,
            $this->rewrite(
                $input,
                [self::SOURCE_URL => 'https://destination.example/assets']
            )
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapedBaseSpellings(): iterable
    {
        yield 'escaped colon' => ['https\\://', '/wp-content/uploads'];
        yield 'escaped first protocol slash' => ['https:\\//', '/wp-content/uploads'];
        yield 'escaped second protocol slash' => ['https:/\\/', '/wp-content/uploads'];
        yield 'escaped protocol slashes' => ['https:\\/\\/', '/wp-content/uploads'];
        yield 'fully escaped protocol' => ['https\\:\\/\\/', '/wp-content/uploads'];
        yield 'escaped first source path slash' => ['https://', '\\/wp-content/uploads'];
        yield 'escaped all source path slashes' => ['https://', '\\/wp-content\\/uploads'];
    }

    /**
     * @dataProvider urlSuffixes
     */
    public function testPreservesEverySuffixByteAfterTheQualifiedBase(string $suffix): void
    {
        $input = 'https://source.example/wp-content/uploads' . $suffix;

        $this->assertSame(
            'https://destination.example/wp-content/uploads' . $suffix,
            $this->rewrite($input)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlSuffixes(): iterable
    {
        yield 'end of value' => [''];
        yield 'literal path' => ['/2026/01/hero.jpg'];
        yield 'escaped path' => ['\\/2026\\/01\\/hero.jpg'];
        yield 'query string' => ['?download=1&width=800'];
        yield 'fragment' => ['#image'];
        yield 'semicolon' => [';'];
        yield 'CSS closing parenthesis' => [');'];
        yield 'square bracket' => [']'];
        yield 'curly bracket' => ['}'];
        yield 'HTML closing angle bracket' => ['>'];
        yield 'single quote' => ["'"];
        yield 'double quote' => ['"'];
        yield 'full stop' => ['.'];
        yield 'comma' => [','];
        yield 'newline' => ["\nnext value"];
    }

    public function testUsesTheLongestMatchingBaseForOverlappingUploadMappings(): void
    {
        $input = 'url(https://source.example/wp-content/uploads/2026/01/hero.jpg);';

        $this->assertSame(
            'url(https://uploads.destination.example/wp-content/uploads/2026/01/hero.jpg);',
            $this->rewrite(
                $input,
                [
                    'https://source.example/wp-content' => 'https://content.destination.example/wp-content',
                    self::SOURCE_URL => 'https://uploads.destination.example/wp-content/uploads',
                ]
            )
        );
    }

    private function rewrite(string $text, ?array $mapping = null): string
    {
        $processor = new URLInPotentiallyStructuredTextProcessor(
            $text,
            $mapping ?? [self::SOURCE_URL => self::TARGET_URL]
        );

        while ($processor->next_url()) {
            $processor->replace_url_base();
        }

        return $processor->get_updated_text();
    }
}
