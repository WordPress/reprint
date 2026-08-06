<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WordPress\DataLiberation\URL\CSSURLProcessor;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

/**
 * Defines safety boundaries and recursive format handling still required by
 * the conservative URL-rewrite design.
 */
class SafeUrlRewriteFallbackRecursionTest extends TestCase {
    private const SOURCE_URL = 'https://source.example';
    private const TARGET_URL = 'https://destination.example';

    private function createRewriter(): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter([
            self::SOURCE_URL => self::TARGET_URL,
        ]);
    }

    /**
     * @dataProvider outerUrlContainingDelimitedSourceUrlCases
     */
    public function testDelimitedSourceLookingSubstringInsideOuterUrlRemainsUnchanged(
        string $outer_url
    ): void {
        $this->assertSame($outer_url, $this->createRewriter()->rewrite($outer_url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outerUrlContainingDelimitedSourceUrlCases(): iterable
    {
        yield 'outer URL query value' => [
            'https://archive.example/?url=(https://source.example/article)',
        ];
        yield 'outer URL path segment' => [
            'https://archive.example/redirect/(https://source.example/article)',
        ];
        yield 'signed outer URL' => [
            'https://archive.example/redirect/(https://source.example/file)'
                . '?expires=1785364498&signature=abc123',
        ];
        yield 'data URI payload' => [
            'data:text/plain,(https://source.example/article)',
        ];
        yield 'mailto body' => [
            'mailto:reader@example.com?body=(https://source.example/article)',
        ];
        yield 'square-bracketed query value' => [
            'https://archive.example/?next=[https://source.example/article]&signature=x',
        ];
        yield 'brace-delimited query value' => [
            'https://archive.example/?next={https://source.example/article}&signature=x',
        ];
        yield 'quoted query value' => [
            'https://archive.example/?next="https://source.example/article"&signature=x',
        ];
    }

    public function testNestedOrdinaryBlockAttributeUrlIsRewrittenStructurally(): void
    {
        $input = '<!-- wp:example ' . json_encode([
            'settings' => [
                'url' => self::SOURCE_URL . '/image.png',
                'label' => 'unchanged',
            ],
        ], JSON_UNESCAPED_SLASHES) . ' /-->';
        $expected = '<!-- wp:example ' . json_encode([
            'settings' => [
                'url' => self::TARGET_URL . '/image.png',
                'label' => 'unchanged',
            ],
        ], JSON_UNESCAPED_SLASHES) . ' /-->';

        $result = $this->createRewriter()->rewrite(
            $input,
            StructuredDataUrlRewriter::BLOCK_MARKUP
        );
        $processor = new BlockMarkupProcessor($result);

        $this->assertSame($expected, $result);
        $this->assertTrue($processor->next_block_delimiter());
        $this->assertSame(
            [
                'url' => self::TARGET_URL . '/image.png',
                'label' => 'unchanged',
            ],
            $processor->get_block_attribute('settings')
        );
    }

    public function testNestedBlockAttributeJsonStringIsRewrittenRecursively(): void
    {
        $source_payload = '{"url":"https:\/\/source.example\/image.png",'
            . '"label":"keep\u0020this"}';
        $target_payload = '{"url":"https:\/\/destination.example\/image.png",'
            . '"label":"keep\u0020this"}';
        $input = '<!-- wp:example ' . json_encode([
            'settings' => [
                'payload' => $source_payload,
            ],
        ]) . ' /-->';
        $expected = '<!-- wp:example ' . json_encode([
            'settings' => [
                'payload' => $target_payload,
            ],
        ]) . ' /-->';

        $result = $this->createRewriter()->rewrite(
            $input,
            StructuredDataUrlRewriter::BLOCK_MARKUP
        );
        $processor = new BlockMarkupProcessor($result);

        $this->assertSame($expected, $result);
        $this->assertTrue($processor->next_block_delimiter());
        $settings = $processor->get_block_attribute('settings');
        $this->assertIsArray($settings);
        $this->assertSame($target_payload, $settings['payload']);
    }

    public function testSerializedPhpInGenericShortcodeIsRewrittenStructurally(): void
    {
        $source_serialization = serialize([
            'url' => self::SOURCE_URL . '/image.png',
            'label' => 'unchanged',
        ]);
        $target_serialization = serialize([
            'url' => self::TARGET_URL . '/image.png',
            'label' => 'unchanged',
        ]);
        $input = "[builder settings='{$source_serialization}']";
        $expected = "[builder settings='{$target_serialization}']";

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testGenericShortcodeRewritePreservesEveryUnclaimedByte(): void
    {
        $input = '[builder  id = \'Keep\'   image = "https://source.example/image.png"'
            . '  settings = \'{"url":"https:\/\/source.example\/page",'
            . '"label":"keep\u0020this"}\' data-note = "A  B" /]';
        $expected = '[builder  id = \'Keep\'   image = "https://destination.example/image.png"'
            . '  settings = \'{"url":"https:\/\/destination.example\/page",'
            . '"label":"keep\u0020this"}\' data-note = "A  B" /]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testUnclosedGenericShortcodeAttributeFailsClosed(): void
    {
        $input = '[builder id="keep" image="https://source.example/image.png data-note=keep]';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedJsonInGenericShortcodeAttributeFailsClosed(): void
    {
        $input = '[builder settings=\'{"url":"https://source.example/page",}\' '
            . 'image="https://source.example/sibling"]';
        $expected = '[builder settings=\'{"url":"https://source.example/page",}\' '
            . 'image="https://destination.example/sibling"]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider delimitedPhpSerializationCases
     */
    public function testDelimitedPhpSerializationInGenericShortcodeIsRewrittenStructurally(
        string $source_serialization,
        string $target_serialization
    ): void {
        $input = "[builder settings='{$source_serialization}']";
        $expected = "[builder settings='{$target_serialization}']";

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function delimitedPhpSerializationCases(): iterable
    {
        yield 'serialized string' => [
            serialize(self::SOURCE_URL . '/image.png'),
            serialize(self::TARGET_URL . '/image.png'),
        ];

        $source_object = (object) [
            'url' => self::SOURCE_URL . '/image.png',
            'label' => 'unchanged',
        ];
        $target_object = clone $source_object;
        $target_object->url = self::TARGET_URL . '/image.png';
        yield 'serialized object' => [
            serialize($source_object),
            serialize($target_object),
        ];
    }

    public function testOpaqueCustomSerializationInGenericShortcodeRemainsUnchanged(): void
    {
        $payload = self::SOURCE_URL . '/image.png';
        $serialization = 'C:4:"Demo":' . strlen($payload) . ':{' . $payload . '}';
        $input = "[builder settings='{$serialization}' "
            . 'image="https://source.example/sibling"]';
        $expected = "[builder settings='{$serialization}' "
            . 'image="https://destination.example/sibling"]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedSerializationInGenericShortcodeFailsClosed(): void
    {
        $serialization = 's:999:"' . self::SOURCE_URL . '/image.png";';
        $input = "[builder settings='{$serialization}' "
            . 'image="https://source.example/sibling"]';
        $expected = "[builder settings='{$serialization}' "
            . 'image="https://destination.example/sibling"]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider opaqueTextSerializationCases
     */
    public function testSerializationInsideOpaqueTextRemainsByteIdentical(
        string $serialization
    ): void {
        $input = 'Prefix ' . $serialization . ' suffix';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function opaqueTextSerializationCases(): iterable
    {
        $url = self::SOURCE_URL . '/image.png';

        yield 'serialized string' => [serialize($url)];
        yield 'serialized object' => [serialize( (object) ['url' => $url] )];
        yield 'custom serialization' => [
            'C:4:"Demo":' . strlen($url) . ':{' . $url . '}',
        ];
    }

    public function testEmbeddedSerializationProtectsOnlyItsOwnOpaqueTextSpan(): void
    {
        $serialization = serialize([
            'url' => self::SOURCE_URL . '/inside.png',
        ]);
        $input = 'Before https://source.example/before.png; payload='
            . $serialization
            . '; after https://source.example/after.png.';
        $expected = 'Before https://destination.example/before.png; payload='
            . $serialization
            . '; after https://destination.example/after.png.';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider malformedOpaqueTextSerializationCases
     */
    public function testMalformedSerializationInsideOpaqueTextFailsClosed(
        string $serialization
    ): void {
        $input = 'Prefix ' . $serialization . ' suffix';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedOpaqueTextSerializationCases(): iterable
    {
        $url = self::SOURCE_URL . '/image.png';

        yield 'serialized string with an invalid length' => [
            's:999:"' . $url . '";',
        ];
        yield 'serialized object with a truncated value' => [
            'O:8:"stdClass":1:{s:3:"url";s:999:"' . $url . '";}',
        ];
        yield 'custom serialization with an invalid payload length' => [
            'C:4:"Demo":999:{' . $url . '}',
        ];
    }

    public function testJsonInGenericShortcodeRewritesValuesButNotMemberNames(): void
    {
        $source_json = '{"https:\/\/source.example\/key":"unchanged",'
            . '"url":"https:\/\/source.example\/image.png"}';
        $target_json = '{"https:\/\/source.example\/key":"unchanged",'
            . '"url":"https:\/\/destination.example\/image.png"}';
        $input = "[builder settings='{$source_json}']";
        $expected = "[builder settings='{$target_json}']";

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonMemberNameInsideOpaqueTextRemainsUnchanged(): void
    {
        $input = 'Prefix {"https://source.example/key":"unchanged"} suffix';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testEmbeddedJsonProtectsOnlyItsOwnOpaqueTextSpan(): void
    {
        $json = '{"url":"https://source.example/inside",'
            . '"label":"keep\u0020this"}';
        $input = 'Before https://source.example/before; payload='
            . $json
            . '; after https://source.example/after.';
        $expected = 'Before https://destination.example/before; payload='
            . $json
            . '; after https://destination.example/after.';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testCssNestedInJsonRewritesOnlyUrlTokens(): void
    {
        $input = '{"css":"/* https://source.example/comment */'
            . '.hero{content:\'https://source.example/content\';'
            . 'background:url(https://source.example/image.png)}",'
            . '"label":"keep\u0020this"}';
        $expected = '{"css":"/* https://source.example/comment */'
            . '.hero{content:\'https://source.example/content\';'
            . 'background:url(https://destination.example/image.png)}",'
            . '"label":"keep\u0020this"}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testCssNestedInSerializedPhpRewritesOnlyUrlTokens(): void
    {
        $source_css = '/* https://source.example/comment */'
            . '.hero{content:"https://source.example/content";'
            . 'background:url(https://source.example/image.png)}';
        $target_css = '/* https://source.example/comment */'
            . '.hero{content:"https://source.example/content";'
            . 'background:url(https://destination.example/image.png)}';
        $input = serialize(['css' => $source_css, 'label' => 'unchanged']);
        $expected = serialize(['css' => $target_css, 'label' => 'unchanged']);

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testStandaloneCssUrlUsesStructuredUrlMatching(): void
    {
        $input = '.hero{background-image:url("HTTPS://SOURCE.EXAMPLE/image.png");color:red}';
        $expected = '.hero{background-image:url("HTTPS://destination.example/image.png");color:red}';

        $result = $this->createRewriter()->rewrite($input);
        $processor = new CSSURLProcessor($result);

        $this->assertSame($expected, $result);
        $this->assertTrue($processor->next_url());
        $this->assertSame('HTTPS://destination.example/image.png', $processor->get_raw_url());
        $this->assertFalse($processor->next_url());
    }

    public function testStandaloneCssRewritesOnlyUrlTokens(): void
    {
        $input = '/* https://source.example/same */'
            . '.card{--label:"https://source.example/same";'
            . 'content:"https://source.example/same";'
            . 'background:url("https://source.example/same")}';
        $expected = '/* https://source.example/same */'
            . '.card{--label:"https://source.example/same";'
            . 'content:"https://source.example/same";'
            . 'background:url("https://destination.example/same")}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testStandaloneCssRewritePreservesUrlEscapeSpelling(): void
    {
        $rewriter = new StructuredDataUrlRewriter([
            self::SOURCE_URL . '/base' => self::TARGET_URL . '/target',
        ]);
        $input = '.x{background:url("https://source.example/base/caf\00e9?x=%2f")}';
        $expected = '.x{background:url("https://destination.example/target/caf\00e9?x=%2f")}';

        $this->assertSame($expected, $rewriter->rewrite($input));
    }

    public function testStandaloneCssEscapedSourceHostIsRewrittenExactly(): void
    {
        $input = '.x{background:url("https://sour\63 e.example/image.png?next=%2f")}';
        $expected = '.x{background:url("https://destination.example/image.png?next=%2f")}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider malformedCssCases
     */
    public function testMalformedCssFailsClosed(string $input): void
    {
        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCssCases(): iterable
    {
        yield 'unterminated comment' => [
            '/* https://source.example/comment',
        ];
        yield 'unterminated string' => [
            '.hero{content:"https://source.example/content; color:red}',
        ];
        yield 'unterminated url function' => [
            '.hero{background:url(https://source.example/image.png',
        ];
    }

    public function testMalformedCssChildStaysOpaqueWhileValidSiblingRewrites(): void
    {
        $malformed_css = '.hero{background:url(https://source.example/broken.png';
        $input = json_encode([
            'css' => $malformed_css,
            'url' => self::SOURCE_URL . '/sibling.png',
        ], JSON_UNESCAPED_SLASHES);
        $expected = json_encode([
            'css' => $malformed_css,
            'url' => self::TARGET_URL . '/sibling.png',
        ], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testRootRelativeStandaloneCssUrlUsesSourcePathMapping(): void
    {
        $rewriter = new StructuredDataUrlRewriter([
            self::SOURCE_URL . '/base' => self::TARGET_URL . '/target',
        ]);
        $input = '.hero{background:url(/base/image.png)}';
        $expected = '.hero{background:url(/target/image.png)}';

        $this->assertSame($expected, $rewriter->rewrite($input));
    }

    public function testRawStyleElementTextUsesCssUrlParser(): void
    {
        $input = '<style>/* https://source.example/comment */'
            . '.hero{background:url("HTTPS://SOURCE.EXAMPLE/image.png")}</style>';
        $expected = '<style>/* https://source.example/comment */'
            . '.hero{background:url("HTTPS://destination.example/image.png")}</style>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    public function testRootRelativeRawStyleElementUrlUsesSourcePathMapping(): void
    {
        $rewriter = new StructuredDataUrlRewriter([
            self::SOURCE_URL . '/base' => self::TARGET_URL . '/target',
        ]);
        $input = '<style>.hero{background:url(/base/image.png)}</style>';
        $expected = '<style>.hero{background:url(/target/image.png)}</style>';

        $this->assertSame(
            $expected,
            $rewriter->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    public function testOuterUrlInCssTokenRemainsByteIdentical(): void
    {
        $input = '.hero{background:url("https://archive.example/file?next='
            . '(https://source.example/image.png)&signature=abc123")}';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider mostSpecificMappingContainerCases
     */
    public function testMostSpecificMappingWinsInCoveredRecursiveContainersRegardlessOfInsertionOrder(
        string $input,
        string $expected,
        ?string $content_type
    ): void {
        $mappings = [
            'broad mapping first' => [
                self::SOURCE_URL => self::TARGET_URL,
                self::SOURCE_URL . '/blog' => 'https://blog-destination.example/articles',
            ],
            'specific mapping first' => [
                self::SOURCE_URL . '/blog' => 'https://blog-destination.example/articles',
                self::SOURCE_URL => self::TARGET_URL,
            ],
        ];

        foreach ($mappings as $label => $mapping) {
            $rewriter = new StructuredDataUrlRewriter($mapping);
            $this->assertSame(
                $expected,
                $rewriter->rewrite($input, $content_type),
                $label
            );
        }
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function mostSpecificMappingContainerCases(): iterable
    {
        $source_url = self::SOURCE_URL . '/blog/post';
        $target_url = 'https://blog-destination.example/articles/post';

        yield 'JSON' => [
            '{"url":"https:\/\/source.example\/blog\/post"}',
            '{"url":"https:\/\/blog-destination.example\/articles\/post"}',
            null,
        ];
        yield 'serialized PHP' => [
            serialize(['url' => $source_url]),
            serialize(['url' => $target_url]),
            null,
        ];
        yield 'standalone CSS' => [
            '.hero{background:url(' . $source_url . ')}',
            '.hero{background:url(' . $target_url . ')}',
            null,
        ];
        yield 'block-comment JSON' => [
            '<!-- wp:example {"url":"https:\/\/source.example\/blog\/post"} /-->',
            '<!-- wp:example '
                . '{"url":"https:\/\/blog-destination.example\/articles\/post"} /-->',
            StructuredDataUrlRewriter::BLOCK_MARKUP,
        ];
    }

    public function testCommentsAndMalformedBlockJsonStayExactWhileAdjacentAnchorRewrites(): void
    {
        $input = '<!-- ordinary https://source.example/comment -->'
            . '<!-- wp:example {"url":"https://source.example/broken",} /-->'
            . '<a href="https://source.example/article">Article</a>';
        $expected = '<!-- ordinary https://source.example/comment -->'
            . '<!-- wp:example {"url":"https://source.example/broken",} /-->'
            . '<a href="https://destination.example/article">Article</a>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    /**
     * @dataProvider opaqueHtmlRawTextElementCases
     */
    public function testOpaqueHtmlRawTextStaysExactWhileAdjacentUrlAttributesRewrite(
        string $element_name
    ): void
    {
        $input = '<img src="https://source.example/before.png">'
            . '<' . $element_name . '>https://source.example/opaque</' . $element_name . '>'
            . '<a href="https://source.example/article">Article</a>';
        $expected = '<img src="https://destination.example/before.png">'
            . '<' . $element_name . '>https://source.example/opaque</' . $element_name . '>'
            . '<a href="https://destination.example/article">Article</a>';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function opaqueHtmlRawTextElementCases(): iterable
    {
        yield 'script' => ['script'];
        yield 'textarea' => ['textarea'];
        yield 'title' => ['title'];
        yield 'xmp' => ['xmp'];
        yield 'iframe' => ['iframe'];
        yield 'noembed' => ['noembed'];
        yield 'noframes' => ['noframes'];
    }

    public function testDeepMixedPhpJsonPhpCssRecursionPreservesExactBytes(): void
    {
        $source_css = '/* https://source.example/comment */'
            . '.hero{content:"https://source.example/opaque";'
            . 'background:url("https://source.example/blog/image.png?next=%2f")}';
        $target_css = '/* https://source.example/comment */'
            . '.hero{content:"https://source.example/opaque";'
            . 'background:url("https://destination.example/blog/image.png?next=%2f")}';
        $source_inner_php = serialize([
            'css' => $source_css,
            'label' => 'unchanged',
        ]);
        $target_inner_php = serialize([
            'css' => $target_css,
            'label' => 'unchanged',
        ]);
        $source_json = json_encode([
            'payload' => $source_inner_php,
            'label' => 'keep',
        ]);
        $target_json = json_encode([
            'payload' => $target_inner_php,
            'label' => 'keep',
        ]);
        $input = serialize([
            'document' => $source_json,
            'tail' => 'unchanged',
        ]);
        $expected = serialize([
            'document' => $target_json,
            'tail' => 'unchanged',
        ]);

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    /**
     * @dataProvider malformedBlockMarkupCases
     */
    public function testMalformedBlockMarkupFailsClosed(string $input): void
    {
        $this->assertSame(
            $input,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBlockMarkupCases(): iterable
    {
        yield 'unterminated quoted URL attribute' => [
            '<a href="https://source.example/article',
        ];
        yield 'unterminated ordinary comment' => [
            '<!-- ordinary https://source.example/comment',
        ];
    }

    public function testIdnHostVariantsAreRewrittenWithoutGlobalIntlFunctions(): void
    {
        $autoload_path = realpath(__DIR__ . '/../../vendor/autoload.php');
        $url_rewrite_loader_path = realpath(
            __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php'
        );
        $this->assertIsString($autoload_path);
        $this->assertIsString($url_rewrite_loader_path);

        $script = <<<'PHP'
        require $argv[1];
        require $argv[2];

        $target_url = 'https://destination.example';
        $unicode_source_rewriter = new StructuredDataUrlRewriter([
            'https://münich.example/blog' => $target_url,
        ]);
        $punycode_source_rewriter = new StructuredDataUrlRewriter([
            'https://xn--mnich-kva.example/blog' => $target_url,
        ]);
        $later_mapping_rewriter = new StructuredDataUrlRewriter([
            'https://source.example' => $target_url,
            'https://münich.example/blog' => 'https://cdn.example/static',
        ]);

        fwrite(STDOUT, json_encode([
            'idn_functions_available' => function_exists('idn_to_ascii')
                || function_exists('idn_to_utf8'),
            'rewritten_urls' => [
                $unicode_source_rewriter->rewrite(
                    'https://xn--mnich-kva.example/blog/punycode'
                ),
                $punycode_source_rewriter->rewrite(
                    'https://münich.example/blog/unicode'
                ),
                $later_mapping_rewriter->rewrite(
                    'https://xn--mnich-kva.example/blog/file'
                ),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        PHP;

        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'disable_functions=idn_to_ascii,idn_to_utf8',
                '-r',
                $script,
                $autoload_path,
                $url_rewrite_loader_path,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, (string) $stdout . (string) $stderr);
        $result = json_decode( (string) $stdout, true );
        $this->assertIsArray($result, (string) $stderr);
        $this->assertFalse($result['idn_functions_available']);
        $this->assertSame(
            [
                self::TARGET_URL . '/punycode',
                self::TARGET_URL . '/unicode',
                'https://cdn.example/static/file',
            ],
            $result['rewritten_urls']
        );
    }
}
