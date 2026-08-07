<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

/**
 * Verifies that URL rewriting changes only the intended source base bytes.
 */
class StructuredDataUrlRewriterSafetyTest extends TestCase {
    private const SOURCE_ORIGIN = 'https://source.example';
    private const TARGET_ORIGIN = 'https://destination.example';

    /**
     * @param array<string, string>|null $mapping URL mapping, or null for the default mapping.
     */
    private function createRewriter(?array $mapping = null): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter($mapping ?? [
            self::SOURCE_ORIGIN => self::TARGET_ORIGIN,
        ]);
    }

    /**
     * @dataProvider jsonRepresentationCases
     */
    public function testJsonRewritePreservesEveryUnrelatedByte(
        string $input,
        string $expected
    ): void {
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function jsonRepresentationCases(): iterable
    {
        yield 'nested empty object and existing escapes' => [
            '{"url":"https:\/\/source.example\/article", "settings":{"nested":{}},'
                . '"label":"keep\u0020this"}',
            '{"url":"https:\/\/destination.example\/article", "settings":{"nested":{}},'
                . '"label":"keep\u0020this"}',
        ];

        yield 'integer beyond PHP integer range' => [
            '{"url":"https://source.example/article","identifier":18446744073709551615}',
            '{"url":"https://destination.example/article","identifier":18446744073709551615}',
        ];

        yield 'numeric object member names' => [
            '{"0":"https://source.example/article","1":"unchanged"}',
            '{"0":"https://destination.example/article","1":"unchanged"}',
        ];

        yield 'duplicate object members' => [
            '{"url":"https://source.example/article","label":"first","label":"second"}',
            '{"url":"https://destination.example/article","label":"first","label":"second"}',
        ];

        yield 'two mapped bases separated by an escape' => [
            '{"value":"https:\/\/source.example\/a\u0020https:\/\/source.example\/b"}',
            '{"value":"https:\/\/destination.example\/a\u0020https:\/\/destination.example\/b"}',
        ];
    }

    public function testMalformedTopLevelJsonRemainsUnchanged(): void
    {
        $input = '{"url":"https://source.example/article",'
            . '"label":"keep\u0020this",}';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testJsonRewriteLeavesMemberNamesAndUnchangedEscapesByteExact(): void
    {
        $input = '{"https:\/\/source.example\/key":"unchanged",'
            . '"value":"before\\u0020https:\/\/source.example\/article'
            . '\/\\ud83d\\ude00\\u0021"}';
        $expected = '{"https:\/\/source.example\/key":"unchanged",'
            . '"value":"before\\u0020https:\/\/destination.example\/article'
            . '\/\\ud83d\\ude00\\u0021"}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonRewriteDoesNotDropLargeSetsOfUrlChanges(): void
    {
        $source_urls = array_fill(0, 100, self::SOURCE_ORIGIN . '/item');
        $target_urls = array_fill(0, 100, self::TARGET_ORIGIN . '/item');
        $input = json_encode(['value' => implode(' ', $source_urls)], JSON_UNESCAPED_SLASHES);
        $expected = json_encode(['value' => implode(' ', $target_urls)], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonWrappedSerializationRewritesEveryUrlWithinLimitedMemory(): void
    {
        $source_value = serialize(array_fill(0, 100, self::SOURCE_ORIGIN . '/item'));
        $target_value = serialize(array_fill(0, 100, self::TARGET_ORIGIN . '/item'));
        $input = json_encode(['value' => $source_value], JSON_UNESCAPED_SLASHES);
        $expected = json_encode(['value' => $target_value], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);

        $result = $this->createRewriter()->rewrite($input);
        $decoded_result = json_decode($result, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray($decoded_result);
        $this->assertSame($target_value, $decoded_result['value']);
        $this->assertSame(
            array_fill(0, 100, self::TARGET_ORIGIN . '/item'),
            unserialize($decoded_result['value'])
        );
        $this->assertSame($expected, $result);
    }

    public function testMalformedNestedJsonRemainsOpaqueWhileSiblingRewrites(): void
    {
        $malformed_child = '{"url":"https://source.example/inside",}';
        $input = json_encode([
            'payload' => $malformed_child,
            'url' => self::SOURCE_ORIGIN . '/sibling',
        ], JSON_UNESCAPED_SLASHES);
        $expected = json_encode([
            'payload' => $malformed_child,
            'url' => self::TARGET_ORIGIN . '/sibling',
        ], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testSerializedObjectContainingEnumRewritesAsValidSerialization(): void
    {
        $enum_identifier = 'Reprint_Url_Rewrite_Safety_Status:Published';
        $source_url = self::SOURCE_ORIGIN . '/article';
        $target_url = self::TARGET_ORIGIN . '/article';
        $input = 'O:8:"stdClass":2:{s:3:"url";s:' . strlen($source_url)
            . ':"' . $source_url . '";s:6:"status";E:' . strlen($enum_identifier)
            . ':"' . $enum_identifier . '";}';
        $expected = 'O:8:"stdClass":2:{s:3:"url";s:' . strlen($target_url)
            . ':"' . $target_url . '";s:6:"status";E:' . strlen($enum_identifier)
            . ':"' . $enum_identifier . '";}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedTopLevelSerializationRemainsUnchanged(): void
    {
        $input = 's:999:"https://source.example/inside";';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedSerializedEnumRemainsUnchanged(): void
    {
        $input = 'E:36:"https://source.example:Published";';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider malformedSerializationGrammarCases
     */
    public function testMalformedSerializationGrammarRemainsUnchanged(string $input): void
    {
        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSerializationGrammarCases(): iterable
    {
        $url = self::SOURCE_ORIGIN . '/page';
        $serialized_url = 's:' . strlen($url) . ':"' . $url . '";';

        yield 'invalid double token' => [
            'a:2:{s:3:"bad";d:not-a-double;s:3:"url";' . $serialized_url . '}',
        ];
        yield 'boolean array key' => [
            'a:1:{b:1;' . $serialized_url . '}',
        ];
        yield 'zero reference identifier' => [
            'a:2:{i:0;r:0;i:1;' . $serialized_url . '}',
        ];
        yield 'forward reference identifier' => [
            'a:2:{i:0;r:999;i:1;' . $serialized_url . '}',
        ];
        yield 'object reference to scalar value' => [
            'a:2:{i:0;' . $serialized_url . 'i:1;r:2;}',
        ];
        yield 'pointer reference to a reference slot' => [
            'a:4:{i:0;s:4:"seed";i:1;R:2;i:2;R:3;i:3;'
                . $serialized_url . '}',
        ];
        yield 'object reference to a pointer slot' => [
            'a:4:{i:0;O:8:"stdClass":0:{}i:1;R:2;i:2;r:3;i:3;'
                . $serialized_url . '}',
        ];
        yield 'empty ordinary object class' => [
            'a:2:{i:0;O:0:"":0:{}i:1;' . $serialized_url . '}',
        ];
        yield 'invalid ordinary object class' => [
            'a:2:{i:0;O:7:"Bad-Cls":0:{}i:1;' . $serialized_url . '}',
        ];
        yield 'empty custom object class' => [
            'a:2:{i:0;C:0:"":0:{}i:1;' . $serialized_url . '}',
        ];
        yield 'invalid custom object class' => [
            'a:2:{i:0;C:7:"Bad-Cls":0:{}i:1;' . $serialized_url . '}',
        ];
        yield 'lowercase infinity' => [
            'a:2:{i:0;d:inf;i:1;' . $serialized_url . '}',
        ];
        yield 'lowercase not a number' => [
            'a:2:{i:0;d:nan;i:1;' . $serialized_url . '}',
        ];
    }

    public function testMalformedNestedSerializationRemainsOpaqueWhileSiblingRewrites(): void
    {
        $malformed_child = 's:999:"https://source.example/inside";';
        $input = json_encode([
            'payload' => $malformed_child,
            'url' => self::SOURCE_ORIGIN . '/sibling',
        ], JSON_UNESCAPED_SLASHES);
        $expected = json_encode([
            'payload' => $malformed_child,
            'url' => self::TARGET_ORIGIN . '/sibling',
        ], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider embeddedSerializationCases
     */
    public function testEmbeddedSerializationIsNotCorrupted(
        string $serialization
    ): void {
        $input = 'Before https://source.example/before; payload='
            . $serialization
            . '; after https://source.example/after.';
        $expected = 'Before https://destination.example/before; payload='
            . $serialization
            . '; after https://destination.example/after.';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedEmbeddedStringWithSemicolonRemainsOpaque(): void
    {
        $serialization = 's:999:"note; https://source.example/inside";';
        $input = 'payload=' . $serialization . ' after https://source.example/after';
        $expected = 'payload=' . $serialization . ' after https://destination.example/after';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedEmbeddedContainerIgnoresBraceInsideStringPayload(): void
    {
        $serialization = 'a:1:{s:3:"key";s:999:"} https://source.example/inside";}';
        $input = 'payload=' . $serialization . ' after https://source.example/outside';
        $expected = 'payload=' . $serialization
            . ' after https://destination.example/outside';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedCustomSerializationPayloadIsOpaque(): void
    {
        $serialization = 'C:1:"X":999:{opaque } https://source.example/inside}';
        $input = 'payload=' . $serialization . ' after https://source.example/outside';
        $expected = 'payload=' . $serialization
            . ' after https://destination.example/outside';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function embeddedSerializationCases(): iterable
    {
        yield 'valid serialization' => [
            serialize(['url' => self::SOURCE_ORIGIN . '/inside']),
        ];
        yield 'malformed serialization' => [
            's:999:"https://source.example/inside";',
        ];
    }

    public function testLiteralSourcePathMatchesOnlyAtAPathBoundary(): void
    {
        $input = 'https://source.example/blog/article '
            . 'https://source.example/blogger/article';
        $expected = 'https://destination.example/articles/article '
            . 'https://source.example/blogger/article';

        $this->assertSame(
            $expected,
            $this->createRewriter([
                self::SOURCE_ORIGIN . '/blog' => self::TARGET_ORIGIN . '/articles',
            ])->rewrite($input)
        );
    }

    public function testBackslashTraversalDoesNotRemainUnderMappedPath(): void
    {
        $url = 'https://source.example/blog\\..\\outside';
        $rewriter = $this->createRewriter([
            self::SOURCE_ORIGIN . '/blog' => self::TARGET_ORIGIN . '/new',
        ]);

        $this->assertSame($url, $rewriter->rewrite($url));
        $markup = '<a href="' . $url . '">Outside</a>';
        $this->assertSame($markup, $rewriter->rewrite_known_block_markup_value($markup));
    }

    public function testRootRelativeUrlUsesDesignatedFirstSourceOrigin(): void
    {
        $input = '<a href="/base/deep/page">Page</a>';
        $rewriter = $this->createRewriter([
            'https://one.example/base' => 'https://destination.example/one',
            'https://two.example/base/deep' => 'https://destination.example/two',
        ]);

        $this->assertSame(
            '<a href="/one/deep/page">Page</a>',
            $rewriter->rewrite_known_block_markup_value($input)
        );
    }

    public function testRootRelativeUrlIncludesTargetSubpathForOriginMapping(): void
    {
        $rewriter = $this->createRewriter([
            self::SOURCE_ORIGIN => self::TARGET_ORIGIN . '/subsite',
        ]);

        $this->assertSame(
            '<a href="/subsite/page">Page</a>',
            $rewriter->rewrite_known_block_markup_value('<a href="/page">Page</a>')
        );
    }

    public function testNetworkPathUrlUsesDesignatedSourceScheme(): void
    {
        $input = '<a href="//source.example/base/page">Page</a>';
        $rewriter = $this->createRewriter([
            'https://source.example/base' => 'https://destination.example/secure',
            'http://source.example/base' => 'http://destination.example/insecure',
        ]);

        $this->assertSame(
            '<a href="//destination.example/secure/page">Page</a>',
            $rewriter->rewrite_known_block_markup_value($input)
        );
    }

    public function testNetworkPathUrlDoesNotUseOppositeSourceScheme(): void
    {
        $input = '<a href="//source.example/base/page">Page</a>';
        $rewriter = $this->createRewriter([
            'https://base.example' => 'https://destination.example/base',
            'http://source.example/base' => 'http://destination.example/insecure',
        ]);

        $this->assertSame($input, $rewriter->rewrite_known_block_markup_value($input));
    }

    /**
     * @dataProvider ownedUrlPathPunctuationCases
     */
    public function testOwnedUrlPathPunctuationRemainsPartOfThePath(string $input): void
    {
        $rewriter = $this->createRewriter([
            self::SOURCE_ORIGIN . '/blog' => self::TARGET_ORIGIN . '/new',
        ]);

        $this->assertSame($input, $rewriter->rewrite_known_block_markup_value($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownedUrlPathPunctuationCases(): iterable
    {
        foreach (['.', ',', ';param', '(copy)'] as $suffix) {
            $url = self::SOURCE_ORIGIN . '/blog' . $suffix;
            yield 'HTML ' . $suffix => ['<a href="' . $url . '">Page</a>'];
            yield 'CSS ' . $suffix => ['<style>.x{background:url("' . $url . '")}</style>'];
            yield 'block ' . $suffix => [
                '<!-- wp:image {"url":"' . $url . '"} /-->',
            ];
        }
    }

    public function testLiteralSourcePortMustMatchExactly(): void
    {
        $input = 'https://source.example:8443/blog/exact '
            . 'https://source.example:9443/blog/different '
            . 'https://source.example/blog/missing';
        $expected = 'https://destination.example/articles/exact '
            . 'https://source.example:9443/blog/different '
            . 'https://source.example/blog/missing';

        $this->assertSame(
            $expected,
            $this->createRewriter([
                self::SOURCE_ORIGIN . ':8443/blog' => self::TARGET_ORIGIN . '/articles',
            ])->rewrite($input)
        );
    }

    public function testLiteralReplacementPreservesRawUnmatchedSuffixBytes(): void
    {
        $input = self::SOURCE_ORIGIN . '/blog/%2F/a/../b/./c?next=%2f#part=%2E';
        $expected = self::TARGET_ORIGIN . '/articles/%2F/a/../b/./c?next=%2f#part=%2E';

        $this->assertSame(
            $expected,
            $this->createRewriter([
                self::SOURCE_ORIGIN . '/blog' => self::TARGET_ORIGIN . '/articles',
            ])->rewrite($input)
        );
    }

    public function testLiteralReplacementDoesNotRescanReplacementText(): void
    {
        $input = 'https://source.example/first https://middle.example/second';
        $expected = 'https://middle.example/first https://destination.example/second';
        $mapping = [
            'https://source.example' => 'https://middle.example',
            'https://middle.example' => 'https://destination.example',
        ];

        foreach (
            [
                'source mapping first' => $mapping,
                'middle mapping first' => array_reverse($mapping, true),
            ] as $label => $ordered_mapping
        ) {
            $this->assertSame(
                $expected,
                $this->createRewriter($ordered_mapping)->rewrite($input),
                $label
            );
        }
    }

    public function testStandaloneUrlEndingInSemicolonUsesLiteralFallback(): void
    {
        $this->assertSame(
            'https://destination.example/page;',
            $this->createRewriter()->rewrite('https://source.example/page;')
        );
    }

    public function testShortcodeNestedStructuresRewriteWithoutRebuildingTheToken(): void
    {
        $serialized_source = serialize(['url' => self::SOURCE_ORIGIN . '/serialized']);
        $serialized_target = serialize(['url' => self::TARGET_ORIGIN . '/serialized']);
        $input = '[builder  settings = \'' . $serialized_source . '\'  payload = \'{'
            . '"url":"https:\/\/source.example\/json","label":"keep\\u0020this"}'
            . '\' data-note = "A  B" /]';
        $expected = '[builder  settings = \'' . $serialized_target . '\'  payload = \'{'
            . '"url":"https:\/\/destination.example\/json","label":"keep\\u0020this"}'
            . '\' data-note = "A  B" /]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider shortcodeTagNameCases
     */
    public function testWordPressShortcodeTagNamesOwnTheirNestedAttributeValues(
        string $tag_name
    ): void {
        $serialized_source = serialize(['url' => self::SOURCE_ORIGIN . '/serialized']);
        $serialized_target = serialize(['url' => self::TARGET_ORIGIN . '/serialized']);
        $input = '[' . $tag_name . ' payload=\'{"url":"https:\/\/source.example\/json",'
            . '"label":"keep\\u0020this"}\' settings=\'' . $serialized_source . '\']';
        $expected = '[' . $tag_name . ' payload=\'{"url":"https:\/\/destination.example\/json",'
            . '"label":"keep\\u0020this"}\' settings=\'' . $serialized_target . '\']';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortcodeTagNameCases(): iterable
    {
        yield 'leading digit' => ['1builder'];
        yield 'period' => ['foo.bar'];
        yield 'colon' => ['foo:bar'];
    }

    public function testUnquotedShortcodeAttributeUrlRewritesWithoutChangingTheToken(): void
    {
        $input = '[builder image=https://source.example/image?size=large data-note=keep]';
        $expected = '[builder image=https://destination.example/image?size=large data-note=keep]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testApostropheInBracketedProseDoesNotBlockOutsideUrlRewrites(): void
    {
        $input = 'before https://source.example/a [See John\'s site] '
            . 'after https://source.example/b';
        $expected = 'before https://destination.example/a [See John\'s site] '
            . 'after https://destination.example/b';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testOnlyAttributeValueOpeningQuotesOwnShortcodeValueSpans(): void
    {
        $input = '[builder author=John\'s image="https://source.example/image"]';
        $expected = '[builder author=John\'s image="https://destination.example/image"]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedShortcodeRemainsUnchanged(): void
    {
        $input = '[builder image="https://source.example/image data-note=keep]';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedLeadingDigitShortcodeRemainsUnchanged(): void
    {
        $input = '[1builder payload=\'{"url":"https://source.example/inside"}';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedClaimedShortcodeKeepsTheWholeValueUnchanged(): void
    {
        $input = 'before https://source.example/before '
            . '[builder image="https://source.example/inside] '
            . 'after https://source.example/after';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testMostSpecificLiteralMappingWinsRegardlessOfInsertionOrder(): void
    {
        $input = self::SOURCE_ORIGIN . '/blog/post';
        $expected = 'https://blog-destination.example/articles/post';
        $mapping = [
            self::SOURCE_ORIGIN => self::TARGET_ORIGIN,
            self::SOURCE_ORIGIN . '/blog' => 'https://blog-destination.example/articles',
        ];

        foreach (
            [
                'broad mapping first' => $mapping,
                'specific mapping first' => array_reverse($mapping, true),
            ] as $label => $ordered_mapping
        ) {
            $this->assertSame(
                $expected,
                $this->createRewriter($ordered_mapping)->rewrite($input),
                $label
            );
        }
    }

    /**
     * @dataProvider minifiedCssCases
     */
    public function testMinifiedCssRewritePreservesEveryOtherByte(
        string $input,
        string $expected
    ): void {
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function minifiedCssCases(): iterable
    {
        yield 'unquoted URL' => [
            '.hero{background:url(https://source.example/image.png);font-size:64px}',
            '.hero{background:url(https://destination.example/image.png);font-size:64px}',
        ];
        yield 'quoted URL' => [
            '.hero{background:url("https://source.example/image.png");font-size:64px}',
            '.hero{background:url("https://destination.example/image.png");font-size:64px}',
        ];
        yield 'HTML-entity quoted URL' => [
            '.hero{background:url(&quot;https://source.example/image.png&quot;);font-size:64px}',
            '.hero{background:url(&quot;https://destination.example/image.png&quot;);font-size:64px}',
        ];
        yield 'two adjacent rules' => [
            '.first{background:url(https://source.example/first.png)}'
                . '.second{background:url(https://source.example/second.png)}',
            '.first{background:url(https://destination.example/first.png)}'
                . '.second{background:url(https://destination.example/second.png)}',
        ];
        yield 'nested rule at-rule' => [
            '@media screen {.x{background:url(https://source.example/image)} '
                . '/* https://source.example/comment */}',
            '@media screen {.x{background:url(https://destination.example/image)} '
                . '/* https://source.example/comment */}',
        ];
        yield 'nested selector string' => [
            '@media screen {.x[data-url="https://source.example/text"]{color:red}}',
            '@media screen {.x[data-url="https://source.example/text"]{color:red}}',
        ];
        yield 'semicolon at-rule' => [
            '@import url(https://source.example/style.css); '
                . '/* https://source.example/comment */',
            '@import url(https://destination.example/style.css); '
                . '/* https://source.example/comment */',
        ];
        yield 'quoted import' => [
            '@import "https://source.example/style.css";',
            '@import "https://destination.example/style.css";',
        ];
        yield 'quoted import with media condition' => [
            '@import "https://source.example/print.css" print;',
            '@import "https://destination.example/print.css" print;',
        ];
        yield 'image-set string and URL function' => [
            '.x{background-image:image-set("https://source.example/a" 1x,'
                . 'url(https://source.example/b) 2x)}',
            '.x{background-image:image-set("https://destination.example/a" 1x,'
                . 'url(https://destination.example/b) 2x)}',
        ];
        yield 'image-set string in a declaration without a semicolon' => [
            'background-image:image-set("https://source.example/a" 1x)',
            'background-image:image-set("https://destination.example/a" 1x)',
        ];
        yield 'image-set metadata string is not a URL' => [
            '.x{background-image:image-set("https://source.example/a" '
                . 'type("https://source.example/not-url") 1x)}',
            '.x{background-image:image-set("https://destination.example/a" '
                . 'type("https://source.example/not-url") 1x)}',
        ];
        yield 'quoted URL function with comments' => [
            '.x{background:url("https://source.example/image"/**/)}',
            '.x{background:url("https://destination.example/image"/**/)}',
        ];
        yield 'import spelling in a custom property is not a URL context' => [
            '--text: @import "https://source.example/not-url"; background:'
                . 'url(https://source.example/image)',
            '--text: @import "https://source.example/not-url"; background:'
                . 'url(https://destination.example/image)',
        ];
        yield 'block-valued custom property' => [
            '.x{--tokens:{ value: https://source.example/not-a-url };background:'
                . 'url(https://source.example/image)}',
            '.x{--tokens:{ value: https://source.example/not-a-url };background:'
                . 'url(https://destination.example/image)}',
        ];
        yield 'non-ASCII custom property' => [
            '.x{--münchen:yes;background:url(https://source.example/image);'
                . '/* https://source.example/comment */}',
            '.x{--münchen:yes;background:url(https://destination.example/image);'
                . '/* https://source.example/comment */}',
        ];
        yield 'nested qualified rule' => [
            '.card{color:red;& .child{background:'
                . 'url(https://source.example/image)}'
                . '/* https://source.example/comment */}',
            '.card{color:red;& .child{background:'
                . 'url(https://destination.example/image)}'
                . '/* https://source.example/comment */}',
        ];
        yield 'leading attribute selector with custom property' => [
            '[foo] .x{--logo: https://source.example/not-url; background:'
                . 'url(https://source.example/image)}',
            '[foo] .x{--logo: https://source.example/not-url; background:'
                . 'url(https://destination.example/image)}',
        ];
        yield 'leading attribute selector with comment' => [
            '[foo] .x{/* https://source.example/comment */ background:'
                . 'url(https://source.example/image)}',
            '[foo] .x{/* https://source.example/comment */ background:'
                . 'url(https://destination.example/image)}',
        ];
        yield 'attribute selector comment is not a shortcode value' => [
            '[foo/* https://source.example/not-url */] .x{background:'
                . 'url(https://source.example/image)}',
            '[foo/* https://source.example/not-url */] .x{background:'
                . 'url(https://destination.example/image)}',
        ];
        foreach (['=', '~=', '|=', '^=', '$=', '*='] as $operator) {
            yield 'spaced attribute selector ' . $operator . ' is not a shortcode' => [
                '[foo ' . $operator . ' "https://source.example/not-url"] '
                    . '.x{background:url(https://source.example/image)}',
                '[foo ' . $operator . ' "https://source.example/not-url"] '
                    . '.x{background:url(https://destination.example/image)}',
            ];
        }
        yield 'attribute selector modifier is not a shortcode' => [
            '[foo = "https://source.example/not-url" i] '
                . '.x{background:url(https://source.example/image)}',
            '[foo = "https://source.example/not-url" i] '
                . '.x{background:url(https://destination.example/image)}',
        ];
        yield 'CSS rule followed by shortcode' => [
            '.x{/* https://source.example/comment */ background:'
                . 'url(https://source.example/style)} '
                . '[foo image=https://source.example/shortcode]',
            '.x{/* https://source.example/comment */ background:'
                . 'url(https://destination.example/style)} '
                . '[foo image=https://destination.example/shortcode]',
        ];
        yield 'shortcode followed by CSS rule' => [
            '[foo image=https://source.example/shortcode] '
                . '.x{/* https://source.example/comment */ background:'
                . 'url(https://source.example/style)}',
            '[foo image=https://destination.example/shortcode] '
                . '.x{/* https://source.example/comment */ background:'
                . 'url(https://destination.example/style)}',
        ];
        yield 'shortcode between CSS rules' => [
            '.x{background:url(https://source.example/a)} '
                . '[foo image=https://source.example/shortcode] '
                . '.y{background:url(https://source.example/b)}',
            '.x{background:url(https://destination.example/a)} '
                . '[foo image=https://destination.example/shortcode] '
                . '.y{background:url(https://destination.example/b)}',
        ];
    }

    public function testCssRewriteChangesOnlyUrlTokenValues(): void
    {
        $input = '/* https://source.example/comment */'
            . '.hero{content:"https://source.example/content";'
            . 'background:url(https://source.example/image.png?next=%2f)}';
        $expected = '/* https://source.example/comment */'
            . '.hero{content:"https://source.example/content";'
            . 'background:url(https://destination.example/image.png?next=%2f)}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider cssWithoutUrlTokenCases
     */
    public function testCssWithoutUrlTokenRemainsUnchanged(string $input): void
    {
        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cssWithoutUrlTokenCases(): iterable
    {
        yield 'comment' => ['/* https://source.example/comment */'];
        yield 'content string' => [
            '.label{content:"https://source.example/text"}',
        ];
        yield 'custom-property declaration list' => [
            '--logo: "https://source.example/text"; color:red',
        ];
        yield 'unquoted custom-property declaration list' => [
            '--logo: https://source.example/text; color:red',
        ];
        yield 'custom-property declaration without semicolon' => [
            '--logo: https://source.example/text',
        ];
        yield 'declaration list comment' => [
            'color:red; /* https://source.example/comment */',
        ];
        yield 'non-ASCII custom-property declaration list' => [
            '--😀:"https://source.example/text"; color:red',
        ];
        yield 'element attribute selector' => [
            'a[data-url="https://source.example/not-url"]',
        ];
        yield 'class attribute selector' => [
            '.x[data-url="https://source.example/not-url"]',
        ];
        yield 'standalone attribute selector' => [
            '[data-url="https://source.example/not-url"]',
        ];
        yield 'attribute selector containing a comment' => [
            '[foo/* https://source.example/not-url */]',
        ];
        yield 'whitespace-valued custom property in a rule' => [
            'a{--empty: ;--text: https://source.example/not-url}',
        ];
        yield 'attribute selector and whitespace-valued custom property' => [
            '[x]{--empty: ;--text: https://source.example/not-url}',
        ];
        yield 'image-set string outside an image option' => [
            '.x{background-image:image-set(1x "https://source.example/not-url")}',
        ];
    }

    /**
     * @dataProvider cssLikePlainTextCases
     */
    public function testCssLikePlainTextFallsBackToLiteralRewriting(
        string $input,
        string $expected
    ): void {
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cssLikePlainTextCases(): iterable
    {
        yield 'at-prefixed prose' => [
            '@alice visit https://source.example/page',
            '@alice visit https://destination.example/page',
        ];
        yield 'comment followed by prose' => [
            '/* note */ visit https://source.example/page',
            '/* note */ visit https://destination.example/page',
        ];
        yield 'URL function phrase followed by prose' => [
            'Use url(example) then https://source.example/page',
            'Use url(example) then https://destination.example/page',
        ];
        yield 'braced prose' => [
            'The set {a, b} includes https://source.example/page',
            'The set {a, b} includes https://destination.example/page',
        ];
        yield 'URL inside balanced braced prose' => [
            'The link is {https://source.example/page} today',
            'The link is {https://destination.example/page} today',
        ];
        yield 'URL inside template braces' => [
            'Template {{https://source.example/page}} trailing '
                . 'https://source.example/out',
            'Template {{https://destination.example/page}} trailing '
                . 'https://destination.example/out',
        ];
        yield 'blockquote braced prose' => [
            '> Quote {https://source.example/page}',
            '> Quote {https://destination.example/page}',
        ];
        yield 'heading braced prose' => [
            '# The link {https://source.example/page}',
            '# The link {https://destination.example/page}',
        ];
        yield 'sentence-marker braced prose' => [
            '. Sentence {https://source.example/page}',
            '. Sentence {https://destination.example/page}',
        ];
        yield 'label braced prose' => [
            ': Label {https://source.example/page}',
            ': Label {https://destination.example/page}',
        ];
        yield 'ampersand braced prose' => [
            '& text {https://source.example/page}',
            '& text {https://destination.example/page}',
        ];
        yield 'mapped URL function followed by prose' => [
            'Use url(https://source.example/inside) then https://source.example/outside',
            'Use url(https://destination.example/inside) then '
                . 'https://destination.example/outside',
        ];
        yield 'foreign URL function followed by prose' => [
            'Use url("javascript:alert(\'https://source.example/inside\')") then '
                . 'https://source.example/outside',
            'Use url("javascript:alert(\'https://source.example/inside\')") then '
                . 'https://destination.example/outside',
        ];
        yield 'complete CSS rule followed by prose' => [
            '.x{color:red} then https://source.example/page',
            '.x{color:red} then https://destination.example/page',
        ];
        yield 'mapped CSS rule followed by prose' => [
            '.x{background:url(https://source.example/in)} then '
                . 'https://source.example/out',
            '.x{background:url(https://destination.example/in)} then '
                . 'https://destination.example/out',
        ];
        yield 'foreign-scheme CSS rule followed by prose' => [
            '.x{background:url("javascript:alert(https://source.example/in)")} then '
                . 'https://source.example/out',
            '.x{background:url("javascript:alert(https://source.example/in)")} then '
                . 'https://destination.example/out',
        ];
        yield 'CSS string and URL followed by prose' => [
            '.x{content:"https://source.example/text";background:'
                . 'url(https://source.example/in)} then https://source.example/out',
            '.x{content:"https://source.example/text";background:'
                . 'url(https://destination.example/in)} then '
                . 'https://destination.example/out',
        ];
        yield 'CSS comment followed by prose' => [
            '.x{/* https://source.example/comment */color:red} then '
                . 'https://source.example/out',
            '.x{/* https://source.example/comment */color:red} then '
                . 'https://destination.example/out',
        ];
        yield 'semicolon at-rule followed by prose' => [
            '@import url(https://source.example/style.css); '
                . '/* https://source.example/comment */ then https://source.example/out',
            '@import url(https://destination.example/style.css); '
                . '/* https://source.example/comment */ then '
                . 'https://destination.example/out',
        ];
        yield 'selector followed by prose' => [
            'a[data-url="https://source.example/not-url"] trailing '
                . 'https://source.example/out',
            'a[data-url="https://source.example/not-url"] trailing '
                . 'https://destination.example/out',
        ];
        yield 'CSS rule and selector followed by prose' => [
            '.x{color:red} [data-url="https://source.example/not-url"] trailing '
                . 'https://source.example/out',
            '.x{color:red} [data-url="https://source.example/not-url"] trailing '
                . 'https://destination.example/out',
        ];
        yield 'valid CSS before prose with unmatched punctuation' => [
            '.x{background:url(https://source.example/css)} then (see '
                . 'https://source.example/prose',
            '.x{background:url(https://destination.example/css)} then (see '
                . 'https://destination.example/prose',
        ];
        yield 'valid CSS before shortcode punctuation' => [
            '.x{background:url(https://source.example/css)} '
                . '[foo image=https://source.example/a)] tail '
                . 'https://source.example/prose',
            '.x{background:url(https://destination.example/css)} '
                . '[foo image=https://destination.example/a)] tail '
                . 'https://destination.example/prose',
        ];
    }

    public function testUrlFunctionPhraseInBlockTextDoesNotSuppressLiteralFallback(): void
    {
        $input = '<p>Use url(example) then https://source.example/page</p>';
        $expected = '<p>Use url(example) then https://destination.example/page</p>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testCssRuleInBlockTextDoesNotSuppressTrailingLiteralFallback(): void
    {
        $input = '<p>.x{color:red} then https://source.example/page</p>';
        $expected = '<p>.x{color:red} then https://destination.example/page</p>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testCssRewritePreservesEscapesBetweenTwoMappedBases(): void
    {
        $input = '.asset{src:url("https://source.example/a\20 '
            . 'https://source.example/b")}';
        $expected = '.asset{src:url("https://destination.example/a\20 '
            . 'https://destination.example/b")}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedQuotedCssUrlRemainsUnchanged(): void
    {
        $input = '.asset{src:url("https://source.example/a" garbage)}';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider malformedCssCases
     */
    public function testMalformedCssRemainsUnchanged(string $input): void
    {
        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCssCases(): iterable
    {
        yield 'unterminated comment' => ['/* https://source.example/comment'];
        yield 'unterminated selector comment' => [
            '.x/* https://source.example/comment',
        ];
        yield 'unterminated rule with selector URL' => [
            'a[data-url="https://source.example/not-url"]{color:red',
        ];
        yield 'mismatched selector bracket with URL' => [
            'a[data-url="https://source.example/not-url"{color:red}',
        ];
        yield 'extra selector closer with URL' => [
            'a[data-url="https://source.example/not-url"]){color:red}',
        ];
        yield 'broken URL token after selector URL' => [
            'a[data-url="https://source.example/not-url"]{background:'
                . 'url("https://source.example/broken)',
        ];
        yield 'bad URL token with a leading comment' => [
            '.x{background:url(/**/"https://source.example/not-url"/**/)}',
        ];
        yield 'unterminated string' => [
            '.hero{content:"https://source.example/content; color:red}',
        ];
        yield 'unterminated URL function' => [
            '.hero{background:url(https://source.example/image.png',
        ];
    }

    public function testMalformedCssChildStaysOpaqueWhileJsonSiblingRewrites(): void
    {
        $malformed_css = '.hero{background:url(https://source.example/broken.png';
        $input = json_encode([
            'css' => $malformed_css,
            'url' => self::SOURCE_ORIGIN . '/sibling.png',
        ], JSON_UNESCAPED_SLASHES);
        $expected = json_encode([
            'css' => $malformed_css,
            'url' => self::TARGET_ORIGIN . '/sibling.png',
        ], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testBlockMarkupRewritePreservesUnrelatedTokenBytes(): void
    {
        $input = '<!-- ordinary https://source.example/comment -->'
            . '<!-- wp:example {"url":"https:\/\/source.example\/article", '
            . '"settings":{"nested":{}},"identifier":18446744073709551615,'
            . '"label":"first","label":"second"} /-->'
            . '<a  href = \'https://source.example/page\'>Page</a>';
        $expected = '<!-- ordinary https://source.example/comment -->'
            . '<!-- wp:example {"url":"https:\/\/destination.example\/article", '
            . '"settings":{"nested":{}},"identifier":18446744073709551615,'
            . '"label":"first","label":"second"} /-->'
            . '<a  href = \'https://destination.example/page\'>Page</a>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testMalformedBlockJsonStaysOpaqueWhileValidSiblingRewrites(): void
    {
        $input = '<!-- wp:example {"url":"https://source.example/broken",} /-->'
            . '<a href="https://source.example/article">Article</a>';
        $expected = '<!-- wp:example {"url":"https://source.example/broken",} /-->'
            . '<a href="https://destination.example/article">Article</a>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testButtonBrowsingContextNameIsNotRewrittenAsAUrl(): void
    {
        $input = '<!-- wp:button {"linkTarget":"/old","url":"/old"} /-->';
        $expected = '<!-- wp:button {"linkTarget":"/old","url":"/new"} /-->';
        $rewriter = $this->createRewriter([
            self::SOURCE_ORIGIN . '/old' => self::TARGET_ORIGIN . '/new',
        ]);

        $this->assertSame($expected, $rewriter->rewrite_known_block_markup_value($input));
    }

    public function testStyleElementUsesCssSpansWithoutChangingRawText(): void
    {
        $input = '<style>\r\n/* https://source.example/comment */'
            . '.hero{content:"https://source.example/content";'
            . 'background:url("https://source.example/image.png")}\r\n</style>';
        $expected = '<style>\r\n/* https://source.example/comment */'
            . '.hero{content:"https://source.example/content";'
            . 'background:url("https://destination.example/image.png")}\r\n</style>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testStyleElementRewritesQuotedImportUrl(): void
    {
        $input = '<style>@import "https://source.example/style.css" screen;</style>';
        $expected = '<style>@import "https://destination.example/style.css" screen;</style>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testInlineStyleDoesNotTreatCustomPropertyImportTextAsAUrl(): void
    {
        $input = '<div style=\'--text: @import "https://source.example/not-url"; '
            . 'background:url(https://source.example/image)\'></div>';
        $expected = '<div style=\'--text: @import "https://source.example/not-url"; '
            . 'background:url(https://destination.example/image)\'></div>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testInlineStyleRewritesImageSetStringUrl(): void
    {
        $input = '<div style=\'background-image:image-set("https://source.example/a" 1x)\'>'
            . '</div>';
        $expected = '<div style=\'background-image:image-set("https://destination.example/a" 1x)\'>'
            . '</div>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testHtmlAttributePreservesEntityBetweenTwoMappedBases(): void
    {
        $input = '<applet archive="https://source.example/a&#32;'
            . 'https://source.example/b"></applet>';
        $expected = '<applet archive="https://destination.example/a&#32;'
            . 'https://destination.example/b"></applet>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testSrcsetRewritesEachUrlAndPreservesDescriptorsAndEntities(): void
    {
        $input = '<picture><source srcset="https://source.example/a.webp 1x, '
            . '/b.webp 2x"><img srcset="https://source.example/a.jpg?x=1&amp;y=2 320w, '
            . 'https://other.example/b.jpg 640w"></picture>';
        $expected = '<picture><source srcset="https://destination.example/subsite/a.webp 1x, '
            . '/subsite/b.webp 2x"><img srcset="https://destination.example/subsite/a.jpg'
            . '?x=1&amp;y=2 320w, https://other.example/b.jpg 640w"></picture>';

        $this->assertSame(
            $expected,
            $this->createRewriter([
                self::SOURCE_ORIGIN => self::TARGET_ORIGIN . '/subsite',
            ])->rewrite_known_block_markup_value($input)
        );
    }

    /**
     * @dataProvider otherSchemePayloadCases
     */
    public function testOtherSchemePayloadRemainsUnchanged(string $input): void
    {
        $this->assertSame(
            $input,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherSchemePayloadCases(): iterable
    {
        yield 'HTML URL attribute' => [
            '<a href="javascript:alert(&quot;https://source.example/x&quot;)">Link</a>',
        ];
        yield 'CSS URL token' => [
            '.x{background:url("javascript:alert(\'https://source.example/x\')")}',
        ];
        yield 'HTML URL attribute with payload whitespace' => [
            '<a href="javascript:alert( &quot;https://source.example/x&quot; )">Link</a>',
        ];
        yield 'CSS URL token with payload whitespace' => [
            '.x{background:url("javascript:alert( \'https://source.example/x\' )")}',
        ];
        yield 'block URL attribute' => [
            '<!-- wp:image {"url":"javascript:alert( '
                . '\\"https://source.example/x\\" )"} /-->',
        ];
        yield 'mapped text inside another HTTP HTML URL' => [
            '<a href="https://other.example/?note= '
                . '&quot;https://source.example/x&quot;">Link</a>',
        ];
        yield 'mapped text inside another HTTP CSS URL' => [
            '.x{background:url("https://other.example/note= '
                . '\'https://source.example/x\'")}',
        ];
        yield 'mapped text inside another HTTP block URL' => [
            '<!-- wp:image {"url":"https://other.example/note= '
                . '\'https://source.example/x\'"} /-->',
        ];
    }

    public function testPathRelativeHtmlUrlWithoutDocumentBaseRemainsUnchanged(): void
    {
        $input = '<a href="base/page">Page</a>';

        $this->assertSame(
            $input,
            $this->createRewriter([
                self::SOURCE_ORIGIN . '/base' => self::TARGET_ORIGIN . '/target',
            ])->rewrite_known_block_markup_value($input)
        );
    }

    public function testInlineStylePreservesEntitiesBetweenMultipleUrlTokens(): void
    {
        $input = '<div style="background:url(&#34;https://source.example/a&#34;);'
            . 'mask:url(&#34;https://source.example/b&#34;)"></div>';
        $expected = '<div style="background:url(&#34;https://destination.example/a&#34;);'
            . 'mask:url(&#34;https://destination.example/b&#34;)"></div>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testBlockMarkupRewritesEveryOwnedSyntaxInOneValue(): void
    {
        $input = '<!-- wp:image {"url":"https:\/\/source.example\/block"} -->'
            . '<a href="https://source.example/link">Link</a>'
            . '<style>.x{background:url(https://source.example/style)}</style>'
            . '<!-- /wp:image -->';
        $expected = '<!-- wp:image {"url":"https:\/\/destination.example\/block"} -->'
            . '<a href="https://destination.example/link">Link</a>'
            . '<style>.x{background:url(https://destination.example/style)}</style>'
            . '<!-- /wp:image -->';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }

    public function testIncompleteBlockMarkupRemainsUnchanged(): void
    {
        $input = '<a href="https://source.example/article';

        $this->assertSame(
            $input,
            $this->createRewriter()->rewrite_known_block_markup_value($input)
        );
    }
}
