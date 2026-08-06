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
     * @dataProvider outerUrlContainingParenthesizedSourceUrlCases
     */
    public function testParenthesizedSourceLookingSubstringInsideOuterUrlRemainsUnchanged(
        string $outer_url
    ): void {
        $this->assertSame($outer_url, $this->createRewriter()->rewrite($outer_url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outerUrlContainingParenthesizedSourceUrlCases(): iterable
    {
        yield 'outer URL query value' => [
            'https://archive.example/?url=(https://source.example/article)',
        ];
        yield 'outer URL path segment' => [
            'https://archive.example/redirect/(https://source.example/article)',
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

        $result = $this->createRewriter()->rewrite(
            $input,
            StructuredDataUrlRewriter::BLOCK_MARKUP
        );
        $processor = new BlockMarkupProcessor($result);

        $this->assertTrue($processor->next_block_delimiter());
        $this->assertSame(
            [
                'url' => self::TARGET_URL . '/image.png',
                'label' => 'unchanged',
            ],
            $processor->get_block_attribute('settings')
        );
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

    public function testStandaloneCssUrlUsesStructuredUrlMatching(): void
    {
        $input = '.hero{background-image:url("HTTPS://SOURCE.EXAMPLE/image.png");color:red}';

        $result = $this->createRewriter()->rewrite($input);
        $processor = new CSSURLProcessor($result);

        $this->assertTrue($processor->next_url());
        $this->assertSame(self::TARGET_URL . '/image.png', $processor->get_raw_url());
        $this->assertFalse($processor->next_url());
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
            ],
            $result['rewritten_urls']
        );
    }
}
