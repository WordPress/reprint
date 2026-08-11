<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRulesTest extends TestCase {
    /**
     * These are opaque text leaves only. Do not add complete PHP serializations,
     * JSON documents, or known block markup: StructuredDataUrlRewriter handles
     * data whose syntax it knows before this processor receives a text value.
     */
    public function testRewritesAnUnquotedCssUrlWithoutChangingItsTerminator(): void
    {
        $input = '.hero{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg);}';
        $expected = '.hero{background-image:url(https://destination.example/wp-content/uploads/2026/01/hero.jpg);}';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesAQuotedCssUrlWithoutChangingItsTerminator(): void
    {
        $input = '.hero{background-image:url("https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg");}';
        $expected = '.hero{background-image:url("https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg");}';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesTheWPBakeryVideoShortcodeUrl(): void
    {
        $input = '[vc_video link="https://source.example/wp-content/uploads/2026/01/video.mp4"]';
        $expected = '[vc_video link="https://destination.example/wp-content/uploads/2026/01/video.mp4"]';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesCssInAnEntityQuotedWPBakeryAttribute(): void
    {
        $input = '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(https://source.example/wp-content/uploads/2026/01/hero.jpg?id=8086) !important;}&#187;]';
        $expected = '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(https://destination.example/wp-content/uploads/2026/01/hero.jpg?id=8086) !important;}&#187;]';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesCssInAShortcodeInsideAnHtmlAttribute(): void
    {
        $input = '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{background-image:url(https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg)}&#187;]\'></div>';
        $expected = '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{background-image:url(https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg)}&#187;]\'></div>';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesASmartQuotedDiviUrl(): void
    {
        $input = '[et_pb_section background_image=”https://source.example/wp-content/uploads/2026/01/hero.jpg”]';
        $expected = '[et_pb_section background_image=”https://destination.example/wp-content/uploads/2026/01/hero.jpg”]';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesAnEscapedUrlAmongEscapedBlockMarkup(): void
    {
        $input = '[vc_column_text]<!-- \\/wp:post-content -->\\r\\n<p style=\\"text-align:center;\\"><img src=\\"https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg\\" /><!-- \\/wp:image -->[\\/vc_column_text]';
        $expected = '[vc_column_text]<!-- \\/wp:post-content -->\\r\\n<p style=\\"text-align:center;\\"><img src=\\"https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/hero.jpg\\" /><!-- \\/wp:image -->[\\/vc_column_text]';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesAThreeBackslashJsonEscapedUrl(): void
    {
        $input = '[builder_data value="{\\\"url\\\":\\\"https:\\\\\\/\\\\\\/source.example\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/01\\\\\\/hero.jpg\\\"}"]';
        $expected = '[builder_data value="{\\\"url\\\":\\\"https:\\\\\\/\\\\\\/destination.example\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/01\\\\\\/hero.jpg\\\"}"]';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesAUrlWithUserInformationAndPreservesItsSuffix(): void
    {
        $input = 'url("https://user:password@source.example/wp-content/uploads/logo.png?download=1#preview");';
        $expected = 'url("https://user:password@destination.example/wp-content/uploads/logo.png?download=1#preview");';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesASchemelessMarkdownUrl(): void
    {
        $input = '[Download](source.example/wp-content/uploads/logo.png)';
        $expected = '[Download](destination.example/wp-content/uploads/logo.png)';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesAUrlValuedQueryParameter(): void
    {
        $input = 'https://archive.example/export?redirect=https://source.example/wp-content/uploads/2026/01/hero.jpg';
        $expected = 'https://archive.example/export?redirect=https://destination.example/wp-content/uploads/2026/01/hero.jpg';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesEveryConfiguredOccurrenceInOneTextLeaf(): void
    {
        $input = 'url(https://source.example/wp-content/uploads/2026/01/a.jpg); url("https:\\/\\/source.example\\/wp-content\\/uploads\\/2026\\/01\\/b.jpg");';
        $expected = 'url(https://destination.example/wp-content/uploads/2026/01/a.jpg); url("https:\\/\\/destination.example\\/wp-content\\/uploads\\/2026\\/01\\/b.jpg");';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://destination.example'])
        );
    }

    public function testRewritesAConfiguredSourceIpv4AddressAndPort(): void
    {
        $input = 'url(https://127.0.0.1:8108/media/logo.png);';
        $expected = 'url(https://destination.example/media/logo.png);';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://127.0.0.1:8108' => 'https://destination.example'])
        );
    }

    public function testRewritesAConfiguredSourceIpv6AddressAndPort(): void
    {
        $input = 'url(https://[::1]:8108/media/logo.png);';
        $expected = 'url(https://destination.example/media/logo.png);';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://[::1]:8108' => 'https://destination.example'])
        );
    }

    public function testRewritesToAPunycodeTargetDomain(): void
    {
        $input = 'url("https:\\/\\/source.example\\/media\\/logo.png");';
        $expected = 'url("https:\\/\\/xn--bcher-kva.example\\/media\\/logo.png");';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example' => 'https://xn--bcher-kva.example'])
        );
    }

    public function testReplacesTheEntireLiteralSourceBase(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://destination.example/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
    }

    public function testReplacesTheEntireEscapedSourceBase(): void
    {
        $input = 'https:\\/\\/source.example\\/media\\/logo.png';
        $expected = 'https:\\/\\/destination.example\\/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotFallBackToDomainOnlyWhenTheTargetHasAPath(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example/assets'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetHasAPercentEncodedPath(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example/archive%2Fmedia'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetIsAnIpv4Address(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://192.0.2.1'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetIsAnIpv6Address(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://[2001:db8::1]'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetDomainHasUnicodeCharacters(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://bücher.example'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetPathHasUnicodeCharacters(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example/über-uns'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetHasAPort(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example:8443'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetHasUserInformation(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://user:password@destination.example'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetHasAQuery(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example?preview=1'])
        );
    }

    public function testDoesNotRewriteWhenTheTargetHasAFragment(): void
    {
        $input = 'https://source.example/media/logo.png';
        $expected = 'https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example#part'])
        );
    }

    public function testDoesNotRewriteWhenTheSourceDomainHasUnicodeCharacters(): void
    {
        $input = 'https://bücher.example/media/logo.png';
        $expected = 'https://bücher.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://bücher.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteWhenTheSourcePathHasUnicodeCharacters(): void
    {
        $input = 'https://source.example/über-uns/logo.png';
        $expected = 'https://source.example/über-uns/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/über-uns' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteWhenTheSourceHasUserInformation(): void
    {
        $input = 'https://user:password@source.example/media/logo.png';
        $expected = 'https://user:password@source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://user:password@source.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteWhenTheSourceHasAQuery(): void
    {
        $input = 'https://source.example/media?preview=1';
        $expected = 'https://source.example/media?preview=1';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media?preview=1' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteWhenTheSourceHasAFragment(): void
    {
        $input = 'https://source.example/media#part';
        $expected = 'https://source.example/media#part';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media#part' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteAUrlInALongerIdentifier(): void
    {
        $input = 'prefixhttps://source.example/media/logo.png';
        $expected = 'prefixhttps://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteAUrlFollowingAPlusSign(): void
    {
        $input = '+https://source.example/media/logo.png';
        $expected = '+https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteAUrlFollowingAHyphen(): void
    {
        $input = '-https://source.example/media/logo.png';
        $expected = '-https://source.example/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteCssHexadecimalEscapes(): void
    {
        $input = 'url(https\\3a \\2f \\2f source.example\\2f media\\2f logo.png)';
        $expected = 'url(https\\3a \\2f \\2f source.example\\2f media\\2f logo.png)';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
    }

    public function testDoesNotRewriteAnUnconfiguredSourcePort(): void
    {
        $input = 'https://source.example:8443/media/logo.png';
        $expected = 'https://source.example:8443/media/logo.png';

        $this->assertSame(
            $expected,
            $this->rewrite($input, ['https://source.example/media' => 'https://destination.example'])
        );
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
