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

    public function testMalformedJsonIsMalformed(): void
    {
        $iter = new JsonStringIterator('{"broken":');

        $this->assertTrue($iter->is_malformed());
        $this->assertFalse($iter->next_value());
    }
}
