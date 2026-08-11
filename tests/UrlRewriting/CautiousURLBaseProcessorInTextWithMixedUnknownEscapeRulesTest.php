<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRulesTest extends TestCase {
    /**
     * These are opaque text leaves only. Do not add complete PHP serializations,
     * JSON documents, or known block markup: StructuredDataUrlRewriter handles
     * data whose syntax it knows before this processor receives a text value.
     *
     * @param array<string, string> $mapping
     */
    #[DataProvider('supported_cases')]
    public function testRewritesSupportedText(
        string $input,
        string $expected,
        array $mapping
    ): void {
        $this->assertSame($expected, $this->rewrite($input, $mapping));
    }

    /**
     * @param array<string, string> $mapping
     */
    #[DataProvider('unsupported_cases')]
    public function testLeavesUnsupportedTextUnchanged(
        string $input,
        string $expected,
        array $mapping
    ): void {
        $this->assertSame($expected, $this->rewrite($input, $mapping));
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:array<string, string>}>
     */
    public static function supported_cases(): array
    {
        return [
            'unquoted CSS URL preserves its terminator' => [
                '.hero{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg);}',
                '.hero{background-image:url(https://destination.example/wp-content/uploads/2026/01/hero.jpg);}',
                ['https://source.example' => 'https://destination.example'],
            ],
            'quoted CSS URL preserves its terminator' => [
                '.hero{background-image:url("https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg");}',
                '.hero{background-image:url("https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg");}',
                ['https://source.example' => 'https://destination.example'],
            ],
            'WPBakery video shortcode URL' => [
                '[vc_video link="https://source.example/wp-content/uploads/2026/01/video.mp4"]',
                '[vc_video link="https://destination.example/wp-content/uploads/2026/01/video.mp4"]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'CSS in an entity-quoted WPBakery attribute' => [
                '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg?id=8086) !important;}&#187;]',
                '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(https://destination.example/wp-content/uploads/2026/01/hero.jpg?id=8086) !important;}&#187;]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'CSS in a shortcode inside an HTML attribute' => [
                '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{background-image:url(https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg)}&#187;]\'></div>',
                '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{background-image:url(https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg)}&#187;]\'></div>',
                ['https://source.example' => 'https://destination.example'],
            ],
            'smart-quoted Divi URL' => [
                '[et_pb_section background_image=”https://source.example/wp-content/uploads/2026/01/hero.jpg”]',
                '[et_pb_section background_image=”https://destination.example/wp-content/uploads/2026/01/hero.jpg”]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'escaped URL among escaped block markup' => [
                '[vc_column_text]<!-- \\/wp:post-content -->\\r\\n<p style=\\"text-align:center;\\"><img src=\\"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg\\" /><!-- \\/wp:image -->[\\/vc_column_text]',
                '[vc_column_text]<!-- \\/wp:post-content -->\\r\\n<p style=\\"text-align:center;\\"><img src=\\"https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg\\" /><!-- \\/wp:image -->[\\/vc_column_text]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'three-backslash JSON-escaped URL' => [
                '[builder_data value="{\\\"url\\\":\\\"https:\\\\\\/\\\\\\/source.example\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/01\\\\\\/hero.jpg\\\"}"]',
                '[builder_data value="{\\\"url\\\":\\\"https:\\\\\\/\\\\\\/destination.example\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/01\\\\\\/hero.jpg\\\"}"]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'username, password, and suffix remain unchanged' => [
                'url("https://user:password@source.example/wp-content/uploads/logo.png?download=1#preview");',
                'url("https://user:password@destination.example/wp-content/uploads/logo.png?download=1#preview");',
                ['https://source.example' => 'https://destination.example'],
            ],
            'scheme-less Markdown URL' => [
                '[Download](source.example/wp-content/uploads/logo.png)',
                '[Download](destination.example/wp-content/uploads/logo.png)',
                ['https://source.example' => 'https://destination.example'],
            ],
            'scheme and authority ignore case' => [
                'HTTPS://SoUrCe.ExAmPlE/media/logo.png',
                'HTTPS://destination.example/media/logo.png',
                ['https://source.example' => 'https://destination.example'],
            ],
            'URL-valued query parameter' => [
                'https://archive.example/export?redirect=https://source.example/wp-content/uploads/2026/01/hero.jpg',
                'https://archive.example/export?redirect=https://destination.example/wp-content/uploads/2026/01/hero.jpg',
                ['https://source.example' => 'https://destination.example'],
            ],
            'every configured occurrence in one text leaf' => [
                'url(https://source.example/wp-content/uploads/2026/01/a.jpg); url("https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/b.jpg");',
                'url(https://destination.example/wp-content/uploads/2026/01/a.jpg); url("https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/b.jpg");',
                ['https://source.example' => 'https://destination.example'],
            ],
            'configured source IPv4 address and port' => [
                'url(https://127.0.0.1:8108/media/logo.png);',
                'url(https://destination.example/media/logo.png);',
                ['https://127.0.0.1:8108' => 'https://destination.example'],
            ],
            'configured source IPv6 address and port' => [
                'url(https://[::1]:8108/media/logo.png);',
                'url(https://destination.example/media/logo.png);',
                ['https://[::1]:8108' => 'https://destination.example'],
            ],
            'Punycode target domain' => [
                'url("https:\\/\\/source.example\\/media\\/logo.png");',
                'url("https:\\/\\/xn--bcher-kva.example\\/media\\/logo.png");',
                ['https://source.example' => 'https://xn--bcher-kva.example'],
            ],
            'complete literal source base' => [
                'https://source.example/media/logo.png',
                'https://destination.example/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'complete escaped source base' => [
                'https:\\/\\/source.example\\/media\\/logo.png',
                'https:\\/\\/destination.example\\/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
        ];
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:array<string, string>}>
     */
    public static function unsupported_cases(): array
    {
        return [
            'configured source path differs by case' => [
                'https://SOURCE.EXAMPLE/Media/logo.png',
                'https://SOURCE.EXAMPLE/Media/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'target has a path' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example/assets'],
            ],
            'target has a percent-encoded path' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example/archive%2Fmedia'],
            ],
            'target is an IPv4 address' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://192.0.2.1'],
            ],
            'target is an IPv6 address' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://[2001:db8::1]'],
            ],
            'target domain contains Unicode characters' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://bücher.example'],
            ],
            'target path contains Unicode characters' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example/über-uns'],
            ],
            'target has a port' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example:8443'],
            ],
            'target has a username and password' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://user:password@destination.example'],
            ],
            'target has a query' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example?preview=1'],
            ],
            'target has a fragment' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example#part'],
            ],
            'source domain contains Unicode characters' => [
                'https://bücher.example/media/logo.png',
                'https://bücher.example/media/logo.png',
                ['https://bücher.example/media' => 'https://destination.example'],
            ],
            'source path contains Unicode characters' => [
                'https://source.example/über-uns/logo.png',
                'https://source.example/über-uns/logo.png',
                ['https://source.example/über-uns' => 'https://destination.example'],
            ],
            'source mapping has a username and password' => [
                'https://user:password@source.example/media/logo.png',
                'https://user:password@source.example/media/logo.png',
                ['https://user:password@source.example/media' => 'https://destination.example'],
            ],
            'source mapping has a query' => [
                'https://source.example/media?preview=1',
                'https://source.example/media?preview=1',
                ['https://source.example/media?preview=1' => 'https://destination.example'],
            ],
            'source mapping has a fragment' => [
                'https://source.example/media#part',
                'https://source.example/media#part',
                ['https://source.example/media#part' => 'https://destination.example'],
            ],
            'URL is part of a longer identifier' => [
                'prefixhttps://source.example/media/logo.png',
                'prefixhttps://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'URL follows a plus sign' => [
                '+https://source.example/media/logo.png',
                '+https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'URL follows a hyphen' => [
                '-https://source.example/media/logo.png',
                '-https://source.example/media/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'CSS uses hexadecimal escapes' => [
                'url(https\\3a \\2f \\2f source.example\\2f media\\2f logo.png)',
                'url(https\\3a \\2f \\2f source.example\\2f media\\2f logo.png)',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'source URL has an unconfigured port' => [
                'https://source.example:8443/media/logo.png',
                'https://source.example:8443/media/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
        ];
    }

    /**
     * @param array<string, string> $mapping
     */
    private function rewrite(string $text, array $mapping): string
    {
        $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules($text, $mapping);

        while ($processor->next_url()) {
            $processor->replace_url_base();
        }

        return $processor->get_updated_text();
    }
}
