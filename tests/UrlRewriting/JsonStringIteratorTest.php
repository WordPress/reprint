<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

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

    public function testCollectsDecodedValuesWithoutVisitingObjectMemberNames(): void
    {
        $json = <<<'JSON'
{"https:\/\/old-site.com\/key":"https:\/\/old-site.com\/value","escapes":"quote: \" backslash: \\ slash: \/ controls: \b\f\n\r\t unicode: \u00e9 pair: \uD83D\uDE00"}
JSON;

        $this->assertSame(
            [
                'https://old-site.com/value',
                "quote: \" backslash: \\ slash: / controls: \x08\x0C\n\r\t unicode: é pair: 😀",
            ],
            $this->collectValues($json)
        );
    }

    public function testChangedValuePreservesAllOtherJsonBytes(): void
    {
        $json = <<<'JSON'
{
  "url" : "https:\/\/old-site.com\/article",
  "duplicate": "first",
  "duplicate": "second",
  "large": 18446744073709551615,
  "negativeZero": -0,
  "decimal": 1.2300e+09,
  "emptyObject": {},
  "emptyArray": [],
  "flags": [true, false, null],
  "literalUnicode": "żółć",
  "escapedUnicode": "\u00e9",
  "surrogatePair": "\uD83D\uDE00",
  "nested": {"items": [1, {"keep": "unchanged"}]}
}
JSON;
        $iter = new JsonStringIterator($json);

        while ($iter->next_value()) {
            if ($iter->get_value() === 'https://old-site.com/article') {
                $iter->set_value('https://new-site.com/article');
            }
        }

        $this->assertSame(
            str_replace('old-site.com', 'new-site.com', $json),
            $iter->get_result()
        );
    }

    public function testChangedStringPreservesUnchangedEscapesWithinThatString(): void
    {
        $json = <<<'JSON'
{"value":"prefix \u00e9 \uD83D\uDE00 https:\/\/old-site.com\/post suffix\/"}
JSON;
        $expected = <<<'JSON'
{"value":"prefix \u00e9 \uD83D\uDE00 https:\/\/new-site.com\/post suffix\/"}
JSON;
        $iter = new JsonStringIterator($json);

        $this->assertTrue($iter->next_value());
        $iter->set_value(str_replace('old-site.com', 'new-site.com', $iter->get_value()));

        $this->assertSame($expected, $iter->get_result());
    }

    public function testChangesMultipleNestedValuesWithDifferentReplacementLengths(): void
    {
        $json = '{"first":"alpha","nested":[{"second":"beta"}],"tail":"keep"}';
        $expected = '{"first":"x","nested":[{"second":"a much longer replacement"}],"tail":"keep"}';
        $iter = new JsonStringIterator($json);

        while ($iter->next_value()) {
            if ($iter->get_value() === 'alpha') {
                $iter->set_value('x');
            } elseif ($iter->get_value() === 'beta') {
                $iter->set_value('a much longer replacement');
            }
        }

        $this->assertSame($expected, $iter->get_result());
    }

    public function testReplacementIsEncodedAsValidJson(): void
    {
        $json = '{  "value" : "old" , "tail" : 1 }';
        $replacement = "quote \" backslash \\ newline\n tab\t nul\0 slash / unicode żółć pair 😀";
        $iter = new JsonStringIterator($json);

        $this->assertTrue($iter->next_value());
        $iter->set_value($replacement);

        $result = $iter->get_result();
        $decoded = json_decode($result, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame($replacement, $decoded['value']);
        $this->assertSame(1, $decoded['tail']);
        $this->assertStringStartsWith('{  "value" : ', $result);
        $this->assertStringEndsWith(' , "tail" : 1 }', $result);
    }

    public function testJsonStringScalarPreservesOuterWhitespace(): void
    {
        $json = " \n\t\"https:\\/\\/old-site.com\\/page\"\r ";
        $expected = " \n\t\"https:\\/\\/new-site.com\\/page\"\r ";
        $iter = new JsonStringIterator($json);

        $this->assertTrue($iter->next_value());
        $iter->set_value('https://new-site.com/page');

        $this->assertSame($expected, $iter->get_result());
    }

    public function testValidContainersWithoutStringValuesRemainUnchanged(): void
    {
        $json = " {\n  \"values\" : [18446744073709551615, -0, 1.2300e+09, true, false, null, {}, []]\n} ";
        $iter = new JsonStringIterator($json);

        $this->assertFalse($iter->is_malformed());
        $this->assertFalse($iter->next_value());
        $this->assertSame($json, $iter->get_result());
    }

    public function testMalformedJsonIsMalformed(): void
    {
        $iter = new JsonStringIterator('{"broken":');

        $this->assertTrue($iter->is_malformed());
        $this->assertFalse($iter->next_value());
    }

    /**
     * @dataProvider malformedJsonProvider
     */
    public function testMalformedJsonFailsClosed(string $json): void
    {
        $iter = new JsonStringIterator($json);

        $this->assertTrue($iter->is_malformed());
        $this->assertFalse($iter->next_value());
        $this->assertSame($json, $iter->get_result());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedJsonProvider(): iterable
    {
        yield 'malformed tail after valid string value' => [
            '{"url":"https://old-site.com","nested":[1,]}',
        ];
        yield 'truncated nested structure' => [
            '{"url":"https://old-site.com","nested":[1',
        ];
        yield 'missing member colon' => [
            '{"url" "https://old-site.com"}',
        ];
        yield 'missing array comma' => [
            '["https://old-site.com" "tail"]',
        ];
        yield 'invalid string escape' => [
            '"bad\q"',
        ];
        yield 'raw control byte' => [
            '"raw' . "\x01" . 'control"',
        ];
        yield 'invalid UTF-8' => [
            '"' . "\xFF" . '"',
        ];
        yield 'lone high surrogate' => [
            '"\uD800"',
        ];
        yield 'lone low surrogate' => [
            '"\uDC00"',
        ];
        yield 'two root values' => [
            '"https://old-site.com" true',
        ];
    }
}
