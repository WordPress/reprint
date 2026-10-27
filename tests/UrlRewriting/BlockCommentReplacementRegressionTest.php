<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class BlockCommentReplacementRegressionTest extends TestCase
{
    #[DataProvider('blockCounts')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTopLevelAndNestedUrlsUseOneBlockCommentReplacement(int $count, string $target): void
    {
        ini_set('memory_limit', '64M');
        $attributes = [
            'svgURL' => 'https://source.test/a.svg',
            'imageSizes' => ['full' => ['source_url' => 'https://source.test/a.svg']],
            'label' => 'Keep this text',
            'unrelated' => 'https://other.test/b.svg',
            'enabled' => true,
            'width' => 42,
        ];
        $expected_attributes = $attributes;
        $expected_attributes['svgURL'] = $target . '/a.svg';
        $expected_attributes['imageSizes']['full']['source_url'] = $target . '/a.svg';
        $input = '<p>before</p>' . str_repeat(
            '<p><!-- wp:safe-svg/svg-icon ' . json_encode($attributes, JSON_UNESCAPED_SLASHES) . ' /--></p>',
            $count
        ) . '<p>after</p>';
        $expected = '<p>before</p>' . str_repeat(
            '<p><!-- wp:safe-svg/svg-icon ' . json_encode($expected_attributes) . ' /--></p>',
            $count
        ) . '<p>after</p>';

        $rewriter = new StructuredDataUrlRewriter(['https://source.test' => $target]);
        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    public static function blockCounts(): array
    {
        return [
            'one block' => [1, 'https://target.test'],
            'two blocks' => [2, 'https://target.test'],
            'three blocks' => [3, 'https://target.test'],
            'six blocks' => [6, 'https://target.test'],
            'many blocks' => [100, 'https://target.test'],
            'longer target' => [3, 'https://much-longer-target.example/site'],
            'shorter target' => [3, 'https://t.test'],
        ];
    }

    public function testEmbeddedQueryUrlUsesOneBlockCommentReplacement(): void
    {
        $input = '<!-- wp:x/y {"url":"https://source.test/a?next=https://source.test/b"} /-->';
        $expected = '<!-- wp:x/y ' . json_encode([
            'url' => 'https://target.test/a?next=https://target.test/b',
        ]) . ' /-->';
        $rewriter = new StructuredDataUrlRewriter(['https://source.test' => 'https://target.test']);

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    public function testIdentityUrlMappingStillUsesOneBlockCommentReplacement(): void
    {
        $attributes = [
            'url' => 'https://source.test/a',
            'nested' => ['url' => 'https://other.test/b'],
        ];
        $input = '<!-- wp:x/y ' . json_encode($attributes) . ' /-->';
        $attributes['nested']['url'] = 'https://target.test/b';
        $expected = '<!-- wp:x/y ' . json_encode($attributes) . ' /-->';
        $rewriter = new StructuredDataUrlRewriter([
            'https://source.test' => 'https://source.test',
            'https://other.test' => 'https://target.test',
        ]);

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }

    public function testKnownRelativeFieldKeepsItsUrlRuleAlongsideNestedValues(): void
    {
        $attributes = [
            'url' => '/about',
            'unknown' => '/leave-this-alone',
            'nested' => ['url' => 'https://source.test/b'],
            'https://source.test/key' => 'https://other.test/value',
        ];
        $input = '<!-- wp:navigation-link ' . json_encode($attributes) . ' /-->';
        $attributes['url'] = '/about';
        $attributes['nested']['url'] = 'https://target.test/b';
        $expected = '<!-- wp:navigation-link ' . json_encode($attributes) . ' /-->';
        $rewriter = new StructuredDataUrlRewriter(['https://source.test' => 'https://target.test']);

        $this->assertSame($expected, $rewriter->rewrite($input, 'block_markup'));
    }
}
