<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class StructuredDataUrlRewriterTest extends TestCase
{
    private function createRewriter(?array $mapping = null): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter($mapping ?? [
            'https://old-site.com' => 'https://new-site.com',
        ]);
    }

    public function testSplicesLiteralBaseBytesWithoutChangingTheSuffix(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com/shop' => 'https://new-site.com',
        ]);
        $input = '<a href="https://old-site.com/shop/a/%2F/../b?next=%2f#part=%2E">Link</a>';
        $expected = '<a href="https://new-site.com/a/%2F/../b?next=%2f#part=%2E">Link</a>';

        $this->assertSame($expected, $rewriter->rewrite($input));
    }

    public function testPreservesArbitrarySuffixBytes(): void
    {
        $suffix = "/path\0\xff%2f";

        $this->assertSame(
            'https://new-site.com' . $suffix,
            $this->createRewriter()->rewrite('https://old-site.com' . $suffix)
        );
    }

    public function testNormalizesOneRootSlashOnEitherBase(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com/' => 'https://new-site.com/',
        ]);

        $this->assertSame(
            'https://new-site.com/path',
            $rewriter->rewrite('https://old-site.com/path')
        );
    }

    public function testAcceptsSafeAsciiPathPunctuation(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com/scope:slug/a;b@c' => 'https://new-site.com',
        ]);

        $this->assertSame(
            'https://new-site.com/page',
            $rewriter->rewrite('https://old-site.com/scope:slug/a;b@c/page')
        );
    }

    public function testSourcePathRequiresAnExactEndBoundary(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com/shop' => 'https://new-site.com',
        ]);
        $input = implode(' ', [
            'https://old-site.com/shop',
            'https://old-site.com/shop/child',
            'https://old-site.com/shop?view=all',
            'https://old-site.com/shop#details',
            'https://old-site.com/shopper',
            'https://old-site.com/shop;param',
            'https://old-site.com/shop,more',
            'https://old-site.com/shop(copy)',
            'https://old-site.com/shop.more',
            'https://old-site.com/shop%2Fmore',
        ]);
        $expected = implode(' ', [
            'https://new-site.com',
            'https://new-site.com/child',
            'https://new-site.com?view=all',
            'https://new-site.com#details',
            'https://old-site.com/shopper',
            'https://old-site.com/shop;param',
            'https://old-site.com/shop,more',
            'https://old-site.com/shop(copy)',
            'https://old-site.com/shop.more',
            'https://old-site.com/shop%2Fmore',
        ]);

        $this->assertSame($expected, $rewriter->rewrite($input));
    }

    public function testSourceOriginRequiresExactBoundaries(): void
    {
        $input = 'xhttps://old-site.com/path https://old-site.com.evil/path '
            . 'https://archive.example/?url=https://old-site.com/path';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testRewritesEveryLiteralUrlInMinifiedJson(): void
    {
        $input = '["https://old-site.com/a","https://old-site.com/b"]';
        $expected = '["https://new-site.com/a","https://new-site.com/b"]';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testUsesTheMostSpecificMappingWithoutCascading(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://middle.example',
            'https://old-site.com/shop' => 'https://shop.example',
            'https://middle.example' => 'https://final.example',
        ]);
        $input = 'https://old-site.com/shop/item https://old-site.com/other '
            . 'https://middle.example/direct';
        $expected = 'https://shop.example/item https://middle.example/other '
            . 'https://final.example/direct';

        $this->assertSame($expected, $rewriter->rewrite($input));
    }

    public function testDoesNotDecodeAlternateSourceSpellings(): void
    {
        $input = 'HTTPS://OLD-SITE.COM/path https:\/\/old-site.com\/path '
            . 'https://old&#45;site.com/path';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testCopiesJsonBytesAroundALiteralMatchExactly(): void
    {
        $input = "{\n"
            . '  "url" : "https://old-site.com/a/%2F/../b?next=%2f",' . "\n"
            . '  "escaped" : "https:\/\/old-site.com\/untouched",' . "\n"
            . '  "unicode" : "\u0061",' . "\n"
            . '  "large" : 12345678901234567890' . "\n"
            . '}';
        $expected = "{\n"
            . '  "url" : "https://new-site.com/a/%2F/../b?next=%2f",' . "\n"
            . '  "escaped" : "https:\/\/old-site.com\/untouched",' . "\n"
            . '  "unicode" : "\u0061",' . "\n"
            . '  "large" : 12345678901234567890' . "\n"
            . '}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testLeavesCompletePhpSerializationUnchanged(): void
    {
        $input = serialize([
            'url' => 'https://old-site.com/path',
            'nested' => serialize('https://old-site.com/other'),
        ]);

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testLeavesSerializationEmbeddedInJsonUnchanged(): void
    {
        $serialized = serialize('prefix https://old-site.com/path');
        $input = json_encode(['data' => $serialized], JSON_UNESCAPED_SLASHES);

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testLeavesMultiplyEscapedSerializationUnchanged(): void
    {
        $serialized = serialize('prefix https://old-site.com/path');
        $once_encoded = json_encode($serialized, JSON_UNESCAPED_SLASHES);
        $input = json_encode($once_encoded, JSON_UNESCAPED_SLASHES);

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
        $this->assertSame(
            'prefix https://old-site.com/path',
            unserialize(json_decode(json_decode($input, true), true))
        );
    }

    public function testSerializationShapedTextFailsClosed(): void
    {
        $input = 'note s:999: then https://old-site.com/path';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testReturnsUnmatchedValuesByteIdentically(): void
    {
        $rewriter = $this->createRewriter();

        $this->assertSame('', $rewriter->rewrite(''));
        $this->assertSame('no URL', $rewriter->rewrite('no URL'));
        $this->assertSame(
            'https://different-site.com/path',
            $rewriter->rewrite('https://different-site.com/path')
        );
    }

    /**
     * @dataProvider unsupportedLiteralMappingCases
     */
    public function testRejectsMappingsOutsideTheLiteralContract(
        string $source_url,
        string $target_url,
        string $message
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new StructuredDataUrlRewriter([$source_url => $target_url]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unsupportedLiteralMappingCases(): iterable
    {
        yield 'non-ASCII source' => [
            'https://bücher.example',
            'https://new-site.com',
            'source URL must contain only printable ASCII bytes',
        ];
        yield 'target path' => [
            'https://old-site.com',
            'https://new-site.com/subsite',
            'target URL must be an origin without a path',
        ];
        yield 'source query' => [
            'https://old-site.com?view=all',
            'https://new-site.com',
            'source URL must be an absolute ASCII HTTP URL',
        ];
        yield 'target credentials' => [
            'https://old-site.com',
            'https://user@example.com',
            'target URL must be an absolute ASCII HTTP URL',
        ];
        yield 'source path with trailing slash' => [
            'https://old-site.com/shop/',
            'https://new-site.com',
            'source URL path must not end with a slash',
        ];
        yield 'uppercase source scheme' => [
            'HTTPS://old-site.com',
            'https://new-site.com',
            'source URL must be an absolute ASCII HTTP URL',
        ];
        yield 'empty domain label' => [
            'https://old..example/path',
            'https://new-site.com',
            'source URL must be an absolute ASCII HTTP URL',
        ];
        yield 'hyphen-only domain label' => [
            'https://-/path',
            'https://new-site.com',
            'source URL must be an absolute ASCII HTTP URL',
        ];
        yield 'quote in source path' => [
            'https://old-site.com/path"',
            'https://new-site.com',
            'source URL path contains unsupported bytes',
        ];
        yield 'backslash in source path' => [
            'https://old-site.com/path\\segment',
            'https://new-site.com',
            'source URL path contains unsupported bytes',
        ];
        yield 'malformed percent escape in source path' => [
            'https://old-site.com/path%2',
            'https://new-site.com',
            'source URL path contains unsupported bytes',
        ];
        yield 'parenthesis in source path' => [
            'https://old-site.com/path(value)',
            'https://new-site.com',
            'source URL path contains unsupported bytes',
        ];
    }
}
