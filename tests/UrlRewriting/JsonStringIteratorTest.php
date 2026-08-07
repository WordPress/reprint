<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class JsonStringIteratorTest extends TestCase
{
    /**
     * @return string[]
     */
    private function collectValues(string $json): array
    {
        $values = [];
        $iter = new JsonStringIterator($json);
        while ($iter->next_value()) {
            $values[] = $iter->get_value();
        }
        return $values;
    }

    public function testCollectsOnlyStringLeafValues(): void
    {
        $json = json_encode([
            'title' => 'Hello',
            'nested' => [
                'url' => 'https://example.com',
                'count' => 3,
                'enabled' => true,
            ],
            'items' => ['first', null, 'second'],
        ], JSON_UNESCAPED_SLASHES);

        $this->assertSame(
            ['Hello', 'https://example.com', 'first', 'second'],
            $this->collectValues($json)
        );
    }

    public function testCollectsJsonStringScalar(): void
    {
        $json = json_encode('https://old-site.com/page', JSON_UNESCAPED_SLASHES);

        $this->assertSame(
            ['https://old-site.com/page'],
            $this->collectValues($json)
        );
    }

    public function testReportsValueNestingAndDecodedObjectMemberNames(): void
    {
        $json = '{"u\u0072l":"root","nested":{"child":"two"},'
            . '"items":["three",{"deep":"four"}]}';
        $iter = new JsonStringIterator($json);
        $values = [];

        while ($iter->next_value()) {
            $values[] = [
                $iter->get_value(),
                $iter->get_current_nesting_depth(),
                $iter->get_current_object_key(),
            ];
        }

        $this->assertSame(
            [
                ['root', 1, 'url'],
                ['two', 2, 'child'],
                ['three', 2, null],
                ['four', 3, 'deep'],
            ],
            $values
        );
    }

    public function testReportsNoObjectMemberNameForRootStringScalar(): void
    {
        $iter = new JsonStringIterator(' "root" ');

        $this->assertTrue($iter->next_value());
        $this->assertSame(0, $iter->get_current_nesting_depth());
        $this->assertNull($iter->get_current_object_key());
    }

    public function testNoChangeReturnsOriginalJson(): void
    {
        $json = '{"title":"Hello","items":["first","second"]}';
        $iter = new JsonStringIterator($json);

        while ($iter->next_value()) {
            $iter->get_value();
        }

        $this->assertSame($json, $iter->get_result());
    }

    public function testSetValueUpdatesNestedLeaf(): void
    {
        $json = '{"nested":{"url":"https://old-site.com/page"},"items":["keep"]}';
        $iter = new JsonStringIterator($json);

        while ($iter->next_value()) {
            $value = $iter->get_value();
            if ($value === 'https://old-site.com/page') {
                $iter->set_value('https://new-site.com/page');
                $this->assertSame('https://new-site.com/page', $iter->get_value());
            }
        }

        $decoded = json_decode($iter->get_result(), true);
        $this->assertSame('https://new-site.com/page', $decoded['nested']['url']);
        $this->assertSame(['keep'], $decoded['items']);
    }

    public function testSetValueUpdatesJsonStringScalar(): void
    {
        $iter = new JsonStringIterator('"https:\/\/old-site.com\/page"');

        $this->assertTrue($iter->next_value());
        $this->assertSame('https://old-site.com/page', $iter->get_value());

        $iter->set_value('https://new-site.com/page');

        $this->assertSame('https://new-site.com/page', json_decode($iter->get_result(), true));
    }

    public function testChangedValuePreservesUnchangedJsonRepresentation(): void
    {
        $input = '{"https:\/\/old-site.com\/key":"unchanged",'
            . '"value":"before\u0020https:\/\/old-site.com\/article\/\ud83d\ude00\u0021",'
            . '"duplicate":"first","duplicate":"second",'
            . '"identifier":18446744073709551615}';
        $expected = '{"https:\/\/old-site.com\/key":"unchanged",'
            . '"value":"before\u0020https:\/\/new-site.com\/article\/\ud83d\ude00\u0021",'
            . '"duplicate":"first","duplicate":"second",'
            . '"identifier":18446744073709551615}';
        $iter = new JsonStringIterator($input);

        while ($iter->next_value()) {
            $iter->set_value(str_replace('old-site.com', 'new-site.com', $iter->get_value()));
        }

        $this->assertSame($expected, $iter->get_result());
    }

    public function testHtmlSafeReplacementEncodesOnlyChangedDecodedBytes(): void
    {
        $input = '{"value":"prefix \u003C https:\/\/old-site.com\/path\/\ud83d\ude00 suffix"}';
        $expected = '{"value":"prefix \u003C https:\/\/new-site.com\/\u003Cpart\u003E\u0026x=1\/\ud83d\ude00 suffix"}';
        $iter = new JsonStringIterator($input, true);

        $this->assertTrue($iter->next_value());
        $iter->set_value('prefix < https://new-site.com/<part>&x=1/😀 suffix');

        $this->assertSame($expected, $iter->get_result());
    }

    public function testMalformedJsonIsMalformed(): void
    {
        $input = '{"broken":"https://old-site.com",}';
        $iter = new JsonStringIterator($input);

        $this->assertTrue($iter->is_malformed());
        $this->assertFalse($iter->next_value());
        $this->assertSame($input, $iter->get_result());
    }
}
