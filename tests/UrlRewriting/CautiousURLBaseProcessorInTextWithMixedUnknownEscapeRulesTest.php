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
            'URL-valued query parameter remains opaque' => [
                'https://archive.example/export?redirect=https://source.example/wp-content/uploads/2026/01/hero.jpg',
                'https://archive.example/export?redirect=https://source.example/wp-content/uploads/2026/01/hero.jpg',
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
            'escaped JSON in a shortcode attribute' => [
                '[builder config="{\\"image\\":\\"https:\\/\\/source.example\\/media\\/hero.jpg\\"}"]',
                '[builder config="{\\"image\\":\\"https:\\/\\/destination.example\\/media\\/hero.jpg\\"}"]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'multiply escaped JSON in an HTML attribute' => [
                '<div data-builder="{\\\"image\\\":\\\"https:\\\\\\/\\\\\\/source.example\\\\\\/media\\\\\\/hero.jpg\\\"}"></div>',
                '<div data-builder="{\\\"image\\\":\\\"https:\\\\\\/\\\\\\/destination.example\\\\\\/media\\\\\\/hero.jpg\\\"}"></div>',
                ['https://source.example' => 'https://destination.example'],
            ],
            'JSON-LD script text with escaped slashes' => [
                '<script type="application/ld+json">{"@context":"https://schema.org","url":"https:\\/\\/source.example\\/products\\/boat"}</script>',
                '<script type="application/ld+json">{"@context":"https://schema.org","url":"https:\\/\\/destination.example\\/products\\/boat"}</script>',
                ['https://source.example' => 'https://destination.example'],
            ],
            'entity-quoted JSON-LD in an HTML attribute' => [
                '<div data-schema="{&quot;url&quot;:&quot;https:\\/\\/source.example\\/products\\/boat&quot;}"></div>',
                '<div data-schema="{&quot;url&quot;:&quot;https:\\/\\/destination.example\\/products\\/boat&quot;}"></div>',
                ['https://source.example' => 'https://destination.example'],
            ],
            'escaped block markup in a shortcode text leaf' => [
                '[vc_column_text]<!-- wp:image {\\"url\\":\\"https:\\/\\/source.example\\/media\\/hero.jpg\\"} --><figure><img src=\\"https:\\/\\/source.example\\/media\\/hero.jpg\\"></figure><!-- \\/wp:image -->[/vc_column_text]',
                '[vc_column_text]<!-- wp:image {\\"url\\":\\"https:\\/\\/destination.example\\/media\\/hero.jpg\\"} --><figure><img src=\\"https:\\/\\/destination.example\\/media\\/hero.jpg\\"></figure><!-- \\/wp:image -->[/vc_column_text]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'CSS image-set with literal and escaped URLs' => [
                '[vc_column css=".hero{background-image:image-set(url(https://source.example/media/a.png) 1x,url(https:\\/\\/source.example\\/media\\/b.png) 2x);}"]',
                '[vc_column css=".hero{background-image:image-set(url(https://destination.example/media/a.png) 1x,url(https:\\/\\/destination.example\\/media\\/b.png) 2x);}"]',
                ['https://source.example' => 'https://destination.example'],
            ],
            'HTML source set with literal and escaped URLs' => [
                '<img srcset="https://source.example/media/a.png 1x, https:\\/\\/source.example\\/media\\/b.png 2x">',
                '<img srcset="https://destination.example/media/a.png 1x, https:\\/\\/destination.example\\/media\\/b.png 2x">',
                ['https://source.example' => 'https://destination.example'],
            ],
            'emoji and multibyte bytes after the configured base' => [
                'https://source.example/media/🚤.jpg?caption=🌊',
                'https://destination.example/media/🚤.jpg?caption=🌊',
                ['https://source.example' => 'https://destination.example'],
            ],
            'control and high bytes after the configured base' => [
                "https://source.example/media/logo.png?raw=\x1F\x7F\x80",
                "https://destination.example/media/logo.png?raw=\x1F\x7F\x80",
                ['https://source.example' => 'https://destination.example'],
            ],
            'source base path with accepted punctuation bytes' => [
                'https://source.example/media[old]{v1}!/logo.png',
                'https://destination.example/logo.png',
                ['https://source.example/media[old]{v1}!' => 'https://destination.example'],
            ],
            'HTTP source mapping preserves the candidate protocol' => [
                'http://source.example/media/logo.png',
                'http://destination.example/media/logo.png',
                ['http://source.example' => 'https://destination.example'],
            ],
            'exact scheme-less host beside a longer unrelated host' => [
                'not-the-right-source.com source.com/media/logo.png',
                'not-the-right-source.com destination.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'only standalone scheme-less host outside another URL path' => [
                'site.com/source.com/media/logo.png source.com/media/logo.png',
                'site.com/source.com/media/logo.png destination.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'embedded URL in an outer query remains opaque' => [
                'https://site.com/source.com/media?next=https://source.com/media/logo.png',
                'https://site.com/source.com/media?next=https://source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
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
            'CSS hexadecimal escapes preserve their spelling' => [
                'url(https\\3a \\2f \\2f source.example\\2f media\\2f logo.png)',
                'url(https\\3a \\2f \\2f destination.example\\2f logo.png)',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'source URL has an unconfigured port' => [
                'https://source.example:8443/media/logo.png',
                'https://source.example:8443/media/logo.png',
                ['https://source.example/media' => 'https://destination.example'],
            ],
            'HTTPS mapping does not match an HTTP URL' => [
                'http://source.com/media/logo.png',
                'http://source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'HTTPS mapping does not match an FTP URL' => [
                'ftp://source.com/media/logo.png',
                'ftp://source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'HTTPS mapping does not match a protocol-relative URL' => [
                '//source.com/media/logo.png',
                '//source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'host is a hyphenated suffix of another host' => [
                'https://not-the-right-source.com/media/logo.png',
                'https://not-the-right-source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'host is a subdomain of the configured host' => [
                'https://cdn.source.com/media/logo.png',
                'https://cdn.source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host is a prefix of a longer host' => [
                'https://source.com.evil.example/media/logo.png',
                'https://source.com.evil.example/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'candidate domain has an unconfigured port' => [
                'https://source.com:8443/media/logo.png',
                'https://source.com:8443/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'candidate IPv4 address has an unconfigured port' => [
                'https://192.0.2.1:8443/media/logo.png',
                'https://192.0.2.1:8443/media/logo.png',
                ['https://192.0.2.1' => 'https://destination.com'],
            ],
            'candidate IPv6 address has an unconfigured port' => [
                'https://[2001:db8::1]:8443/media/logo.png',
                'https://[2001:db8::1]:8443/media/logo.png',
                ['https://[2001:db8::1]' => 'https://destination.com'],
            ],
            'target domain contains an emoji' => [
                'https://source.example/media/logo.png',
                'https://source.example/media/logo.png',
                ['https://source.example' => 'https://🚤.example'],
            ],
            'source domain contains an emoji' => [
                'https://🚤.example/media/logo.png',
                'https://🚤.example/media/logo.png',
                ['https://🚤.example' => 'https://destination.example'],
            ],
            'configured source path contains an emoji' => [
                'https://source.example/🚤/logo.png',
                'https://source.example/🚤/logo.png',
                ['https://source.example/🚤' => 'https://destination.example'],
            ],
            'configured source path contains a space byte' => [
                'https://source.example/media archive/logo.png',
                'https://source.example/media archive/logo.png',
                ['https://source.example/media archive' => 'https://destination.example'],
            ],
            'configured source path contains a control byte' => [
                "https://source.example/media\x1Farchive/logo.png",
                "https://source.example/media\x1Farchive/logo.png",
                ["https://source.example/media\x1Farchive" => 'https://destination.example'],
            ],
            'configured source path contains a DEL byte' => [
                "https://source.example/media\x7Farchive/logo.png",
                "https://source.example/media\x7Farchive/logo.png",
                ["https://source.example/media\x7Farchive" => 'https://destination.example'],
            ],
            'configured source path contains a high byte' => [
                "https://source.example/media\x80archive/logo.png",
                "https://source.example/media\x80archive/logo.png",
                ["https://source.example/media\x80archive" => 'https://destination.example'],
            ],
            'protocol separators use two backslashes' => [
                'https:\\\\/\\\\/source.example\\\\/media\\\\/logo.png',
                'https:\\\\/\\\\/source.example\\\\/media\\\\/logo.png',
                ['https://source.example' => 'https://destination.example'],
            ],
            'percent-encoded protocol separators preserve their spelling' => [
                'https%3A%2F%2Fsource.example%2Fmedia%2Flogo.png',
                'https%3A%2F%2Fdestination.example%2Fmedia%2Flogo.png',
                ['https://source.example' => 'https://destination.example'],
            ],
            'protocol separators are HTML entities' => [
                'https&colon;&sol;&sol;source.example&sol;media&sol;logo.png',
                'https&colon;&sol;&sol;source.example&sol;media&sol;logo.png',
                ['https://source.example' => 'https://destination.example'],
            ],
            'configured host appears in another absolute URL path' => [
                'https://site.com/source.com/media/logo.png',
                'https://site.com/source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host appears in another scheme-less URL path' => [
                'site.com/source.com/media/logo.png',
                'site.com/source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host follows an escaped outer path separator' => [
                'https:\\/\\/site.com\\/source.com\\/media\\/logo.png',
                'https:\\/\\/site.com\\/source.com\\/media\\/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host follows a percent-encoded outer path separator' => [
                'https://site.com/path%2Fsource.com/media/logo.png',
                'https://site.com/path%2Fsource.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host is the username of another URL' => [
                'https://source.com@site.com/media/logo.png',
                'https://source.com@site.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host is part of the password of another URL' => [
                'https://user:source.com@site.com/media/logo.png',
                'https://user:source.com@site.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host is the prefix of a filename' => [
                'https://site.com/media/source.com.png',
                'https://site.com/media/source.com.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'candidate authority has a trailing dot' => [
                'https://source.com./media/logo.png',
                'https://source.com./media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'longer unrelated host appears in an outer query value' => [
                'https://site.com/?next=not-the-right-source.com/media/logo.png',
                'https://site.com/?next=not-the-right-source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
            ],
            'configured host follows another host inside an outer query value' => [
                'https://site.com/?path=site.com/source.com/media/logo.png',
                'https://site.com/?path=site.com/source.com/media/logo.png',
                ['https://source.com' => 'https://destination.com'],
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
