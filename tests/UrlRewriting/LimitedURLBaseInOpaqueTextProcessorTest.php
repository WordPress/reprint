<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class LimitedURLBaseInOpaqueTextProcessorTest extends TestCase {
    private const SOURCE_URL = 'https://source.example/media';
    private const SAME_PATH_TARGET_URL = 'http://destination.example/media';
    private const DIFFERENT_PATH_TARGET_URL = 'http://destination.example/assets';

    /**
     * @param array<string, string> $mapping
     */
    private function rewrite(string $text, array $mapping): string
    {
        $processor = new LimitedURLBaseInOpaqueTextProcessor($text, $mapping);

        while ($processor->next_url()) {
            $processor->replace_url_base();
        }

        return $processor->get_updated_text();
    }

    /**
     * @dataProvider escapedSchemeAndPathCases
     */
    public function testSamePathMappingReplacesOnlyTheDomain(
        string $scheme,
        string $path
    ): void {
        $input = 'url(' . $scheme . 'source.example' . $path . ');';
        $expected = 'url(' . $scheme . 'destination.example' . $path . ');';

        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapedSchemeAndPathCases(): iterable
    {
        yield 'literal scheme and path' => ['https://', '/media/logo.png'];
        yield 'escaped scheme slashes and path slashes' => ['https:\\/\\/', '\\/media\\/logo.png'];
        yield 'escaped scheme colon' => ['https\\://', '/media/logo.png'];
        yield 'all scheme separators escaped' => ['https\\:\\/\\/', '\\/media\\/logo.png'];
    }

    public function testLiteralBaseCanReplaceADifferentInitialPath(): void
    {
        $input = 'url(https://source.example/media/logo%2Fraw.png);';
        $expected = 'url(https://destination.example/assets/logo%2Fraw.png);';

        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::DIFFERENT_PATH_TARGET_URL])
        );
    }

    public function testEscapedBaseDoesNotGenerateATargetPath(): void
    {
        $input = 'url(https:\\/\\/source.example\\/media\\/logo.png);';

        $this->assertSame(
            $input,
            $this->rewrite($input, [self::SOURCE_URL => self::DIFFERENT_PATH_TARGET_URL])
        );
    }

    /**
     * @dataProvider cssUrlCases
     */
    public function testRewritesCssUrlDomainWithoutTouchingClosingSyntax(
        string $input,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cssUrlCases(): iterable
    {
        yield 'unquoted URL function ending with parenthesis and semicolon' => [
            '.hero{background-image:url(https://source.example/media/logo.png);}',
            '.hero{background-image:url(https://destination.example/media/logo.png);}',
        ];

        yield 'quoted URL function ending with quote parenthesis and semicolon' => [
            '.hero{background-image:url("https:\\/\\/source.example\\/media\\/logo.png");}',
            '.hero{background-image:url("https:\\/\\/destination.example\\/media\\/logo.png");}',
        ];
    }

    public function testRewritesAQuotedShortcodeAttributeWithoutChangingItsPathBytes(): void
    {
        $input = '[vc_single_image href="https://source.example/media/logo.png"]';
        $expected = '[vc_single_image href="https://destination.example/media/logo.png"]';

        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    public function testRewritesToAPunycodeTargetDomain(): void
    {
        $input = 'url("https:\\/\\/source.example\\/media\\/logo.png");';
        $expected = 'url("https:\\/\\/xn--bcher-kva.example\\/media\\/logo.png");';

        $this->assertSame(
            $expected,
            $this->rewrite(
                $input,
                [self::SOURCE_URL => 'https://xn--bcher-kva.example/media']
            )
        );
    }

    /**
     * @dataProvider sourceIpAndPortCases
     */
    public function testRewritesAConfiguredSourceIpAddressAndPort(
        string $source_url,
        string $input,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->rewrite($input, [$source_url => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sourceIpAndPortCases(): iterable
    {
        yield 'IPv4 with port' => [
            'https://127.0.0.1:8108/media',
            'url(https://127.0.0.1:8108/media/logo.png);',
            'url(https://destination.example/media/logo.png);',
        ];

        yield 'IPv6 with port' => [
            'https://[::1]:8108/media',
            'url(https://[::1]:8108/media/logo.png);',
            'url(https://destination.example/media/logo.png);',
        ];
    }

    public function testRewritesCssInASiteBuilderShortcodeInsideAnHtmlAttribute(): void
    {
        $input = '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{'
            . 'background-image:url(https\\:\\/\\/source.example\\/media\\/logo.png)}&#187;]\'></div>';
        $expected = '<div data-builder-shortcode=\'[vc_column css=&#187;.vc_custom{'
            . 'background-image:url(https\\:\\/\\/destination.example\\/media\\/logo.png)}&#187;]\'></div>';

        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    public function testRewritesMultipleBasesWithoutTouchingTheirSuffixes(): void
    {
        $input = 'url(https://source.example/media/first.png);'
            . 'url("https:\\/\\/source.example\\/media\\/second.png");';
        $expected = 'url(https://destination.example/media/first.png);'
            . 'url("https:\\/\\/destination.example\\/media\\/second.png");';

        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    public function testRewritesAUrlValuedOuterQuery(): void
    {
        $input = 'https://archive.example/?url=https://source.example/media/logo.png';

        $this->assertSame(
            'https://archive.example/?url=https://destination.example/media/logo.png',
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @dataProvider schemeLessTextUrlCases
     */
    public function testRewritesASchemeLessTextUrl(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function schemeLessTextUrlCases(): iterable
    {
        yield 'Markdown link destination' => [
            '[Download](source.example/media/logo.png)',
            '[Download](destination.example/media/logo.png)',
        ];

        yield 'quoted URL with query and fragment' => [
            '"source.example/media/logo.png?download=1#preview"',
            '"destination.example/media/logo.png?download=1#preview"',
        ];
    }

    /**
     * @dataProvider userInformationCases
     */
    public function testLeavesUserInformationAndSuffixBytesUntouched(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function userInformationCases(): iterable
    {
        yield 'literal protocol' => [
            'url("https://user:password@source.example/media/logo.png?download=1#preview");',
            'url("https://user:password@destination.example/media/logo.png?download=1#preview");',
        ];

        yield 'escaped protocol' => [
            'url("https:\\/\\/user:password@source.example\\/media\\/logo.png?download=1#preview");',
            'url("https:\\/\\/user:password@destination.example\\/media\\/logo.png?download=1#preview");',
        ];
    }

    /**
     * @dataProvider nonMatchingUrlCases
     */
    public function testLeavesNonMatchingUrlsUntouched(string $input): void
    {
        $this->assertSame(
            $input,
            $this->rewrite($input, [self::SOURCE_URL => self::SAME_PATH_TARGET_URL])
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMatchingUrlCases(): iterable
    {
        yield 'wrong initial path' => ['https://source.example/media-old/logo.png'];
        yield 'wrong protocol' => ['http://source.example/media/logo.png'];
        yield 'different domain case' => ['https://SOURCE.example/media/logo.png'];
        yield 'source authority in an unsupported URL path' => [
            'https://other.example/source.example/media/logo.png',
        ];
        yield 'source authority in a CSS identifier' => [
            '.source.example/media/logo.png { color: red; }',
        ];
    }

    /**
     * @dataProvider unsupportedMappingCases
     */
    public function testLeavesUnsupportedTargetBasesUntouched(string $target_url): void
    {
        $input = 'https://source.example/media/logo.png';

        $this->assertSame(
            $input,
            $this->rewrite($input, [self::SOURCE_URL => $target_url])
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedMappingCases(): iterable
    {
        yield 'IPv6 target' => ['https://[2001:db8::1]/media'];
        yield 'IPv4 target' => ['https://192.0.2.1/media'];
        yield 'Unicode target domain' => ['https://bücher.example/media'];
        yield 'Unicode target path' => ['https://destination.example/über-uns'];
        yield 'target port' => ['https://destination.example:8443/media'];
        yield 'target fragment' => ['https://destination.example/media#part'];
        yield 'target query' => ['https://destination.example/media?preview=1'];
    }
}
