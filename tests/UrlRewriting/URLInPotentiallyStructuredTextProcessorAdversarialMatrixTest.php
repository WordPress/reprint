<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class URLInPotentiallyStructuredTextProcessorAdversarialMatrixTest extends TestCase {
    private const SOURCE_URL = 'https://source.example/wp-content/uploads';
    private const TARGET_URL = 'https://destination.example/wp-content/uploads';

    /**
     * This cross-product is the lexical contract for the opaque processor:
     * every accepted protocol spelling, every accepted source-path spelling,
     * four export wrappers, and four forms of trailing URL bytes. It checks
     * the output byte-for-byte instead of decoding any part of the value.
     *
     * @dataProvider acceptedUrlSpellingMatrix
     */
    public function testRewritesEveryAcceptedUrlSpellingWithoutChangingOtherBytes(
        string $prefix,
        string $scheme,
        string $path,
        string $suffix
    ): void {
        $input = $prefix . $scheme . 'source.example' . $path . $suffix;

        $this->assertSame(
            $prefix . $scheme . 'destination.example' . $path . $suffix,
            $this->rewrite($input)
        );
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function acceptedUrlSpellingMatrix(): iterable
    {
        $wrappers = [
            'CSS unquoted URL' => ['.hero{background-image:url(', ');}'],
            'CSS quoted URL' => ['.hero{background-image:url("', '");}'],
            'WPBakery entity quoted CSS' => [
                '[vc_column css=&#187;.vc_custom{background-image:url(', ')}&#187;]',
            ],
            'shortcode inside HTML attribute' => [
                '<div data-builder-shortcode=\'[vc_column css=&#187;.x{background:url(', ')}&#187;]\'></div>',
            ],
        ];
        $suffixes = [
            'literal child path' => '/2026/01/hero.jpg?ver=1#mark',
            'escaped child path' => '\\/2026\\/01\\/hero.jpg?ver=1#mark',
            'query immediately after configured base' => '?download=1&amp;width=800',
            'fragment immediately after configured base' => '#media',
        ];

        foreach ([false, true] as $colon_escaped) {
            foreach ([false, true] as $first_slash_escaped) {
                foreach ([false, true] as $second_slash_escaped) {
                    $scheme = 'https'
                        . ( $colon_escaped ? '\\' : '' ) . ':'
                        . ( $first_slash_escaped ? '\\' : '' ) . '/'
                        . ( $second_slash_escaped ? '\\' : '' ) . '/';
                    $scheme_name = sprintf(
                        'colon-%s-first-slash-%s-second-slash-%s',
                        $colon_escaped ? 'escaped' : 'literal',
                        $first_slash_escaped ? 'escaped' : 'literal',
                        $second_slash_escaped ? 'escaped' : 'literal'
                    );

                    foreach ([false, true] as $leading_path_slash_escaped) {
                        foreach ([false, true] as $inner_path_slash_escaped) {
                            $path = ( $leading_path_slash_escaped ? '\\/' : '/' )
                                . 'wp-content'
                                . ( $inner_path_slash_escaped ? '\\/' : '/' )
                                . 'uploads';
                            $path_name = sprintf(
                                'leading-path-%s-inner-path-%s',
                                $leading_path_slash_escaped ? 'escaped' : 'literal',
                                $inner_path_slash_escaped ? 'escaped' : 'literal'
                            );

                            foreach ($wrappers as $wrapper_name => [$prefix, $end]) {
                                foreach ($suffixes as $suffix_name => $suffix) {
                                    yield $wrapper_name . ' | ' . $scheme_name . ' | '
                                        . $path_name . ' | ' . $suffix_name => [
                                            $prefix,
                                            $scheme,
                                            $path,
                                            $suffix . $end,
                                        ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @dataProvider nearMisses
     */
    public function testLeavesNearMissesByteIdentical(string $input): void
    {
        $this->assertSame($input, $this->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nearMisses(): iterable
    {
        yield 'scheme has a leading identifier byte' => [
            'xhttps://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'uppercase scheme' => [
            'HTTPS://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'wrong scheme' => [
            'http://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'escaped source path has two backslashes' => [
            'url(https://source.example\\\\/wp-content/uploads/2026/01/hero.jpg);',
        ];
        yield 'source domain uses a percent encoded dot' => [
            'https://source%2Eexample/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'source domain has a trailing dot' => [
            'https://source.example./wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'source URL has an unconfigured port' => [
            'https://source.example:8443/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'configured path has a doubled slash' => [
            'https://source.example/wp-content//uploads/2026/01/hero.jpg',
        ];
        yield 'configured path is only a prefix of a longer segment' => [
            'https://source.example/wp-content/uploads-old/2026/01/hero.jpg',
        ];
        yield 'configured path is followed by a percent byte' => [
            'https://source.example/wp-content/uploads%2F2026%2F01%2Fhero.jpg',
        ];
        yield 'configured path is followed by an equals sign' => [
            'https://source.example/wp-content/uploads=not-a-boundary',
        ];
        yield 'configured path is followed by at sign' => [
            'https://source.example/wp-content/uploads@not-a-boundary',
        ];
        yield 'source URL appears in a JavaScript identifier' => [
            'prefixhttps://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'CSS hexadecimal escapes require a CSS parser' => [
            'url(https\\3a \\2f \\2f source.example\\2f wp-content\\2f uploads\\2f hero.jpg)',
        ];
        yield 'URI percent escapes require a URI decoder' => [
            'https:%2F%2Fsource.example%2Fwp-content%2Fuploads%2Fhero.jpg',
        ];
    }

    /**
     * @dataProvider acceptedLeftBoundaries
     */
    public function testRewritesAfterAnAcceptedLeftBoundary(string $input): void
    {
        $this->assertSame(
            str_replace('source.example', 'destination.example', $input),
            $this->rewrite($input)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedLeftBoundaries(): iterable
    {
        yield 'scheme follows an equals sign' => [
            'href=https://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
        yield 'source URL appears in an outer query value' => [
            'https://archive.example/?url=https://source.example/wp-content/uploads/2026/01/hero.jpg',
        ];
    }

    /**
     * @dataProvider literalTargetPathMappings
     */
    public function testReplacesOnlyALiteralConfiguredPathWhenTargetPathDiffers(
        string $target_url,
        string $target_path
    ): void {
        $input = '[vc_row css=".x{background:url(https://source.example/wp-content/uploads/2026/01/hero.jpg);}"]';

        $this->assertSame(
            '[vc_row css=".x{background:url(https://destination.example'
            . $target_path . '/2026/01/hero.jpg);}"]',
            $this->rewrite($input, [self::SOURCE_URL => $target_url])
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalTargetPathMappings(): iterable
    {
        yield 'different literal directory' => [
            'https://destination.example/assets',
            '/assets',
        ];
        yield 'root target path' => [
            'https://destination.example',
            '',
        ];
        yield 'percent encoded configured target path' => [
            'https://destination.example/archive%2Fmedia',
            '/archive%2Fmedia',
        ];
    }

    public function testPreservesArbitraryBytesOutsideTheReplacedDomain(): void
    {
        $input = "\x00prefix\xFF https:\\/\\/source.example\\/wp-content\\/uploads\\/hero.jpg?caption=Grüße\x00";
        $expected = "\x00prefix\xFF https:\\/\\/destination.example\\/wp-content\\/uploads\\/hero.jpg?caption=Grüße\x00";

        $this->assertSame($expected, $this->rewrite($input));
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
