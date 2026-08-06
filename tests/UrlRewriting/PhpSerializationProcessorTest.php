<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class PhpSerializationProcessorTest extends TestCase
{
    /**
     * Run the processor with a transform callback on each value.
     * Returns the updated serialization.
     */
    private function processWithTransform(string $input, callable $transform): string
    {
        $p = new PhpSerializationProcessor($input);
        while ($p->next_value()) {
            $original = $p->get_value();
            $new = $transform($original);
            if ($new !== $original) {
                $p->set_value($new);
            }
        }
        return $p->get_updated_serialization();
    }

    /**
     * Run the processor without modifying any values.
     * Returns the updated serialization (should be byte-identical to input).
     */
    private function processIdentity(string $input): string
    {
        $p = new PhpSerializationProcessor($input);
        while ($p->next_value()) {
            // read but don't modify
            $p->get_value();
        }
        return $p->get_updated_serialization();
    }

    /**
     * Collect all string values the processor exposes, without modifying them.
     *
     * @return string[]
     */
    private function collectValues(string $input): array
    {
        $values = [];
        $p = new PhpSerializationProcessor($input);
        while ($p->next_value()) {
            $values[] = $p->get_value();
        }
        return $values;
    }

    // ---------------------------------------------------------------
    // Scalar types passed through unchanged
    // ---------------------------------------------------------------

    public function testIntegerPassthrough(): void
    {
        $input = serialize(42);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testNegativeIntegerPassthrough(): void
    {
        $input = serialize(-7);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testDoublePassthrough(): void
    {
        $input = serialize(3.14);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testBooleanTruePassthrough(): void
    {
        $input = serialize(true);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testBooleanFalsePassthrough(): void
    {
        $input = serialize(false);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testNullPassthrough(): void
    {
        $input = serialize(null);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testScalarTypesExposeNoValues(): void
    {
        $this->assertSame([], $this->collectValues(serialize(42)));
        $this->assertSame([], $this->collectValues(serialize(true)));
        $this->assertSame([], $this->collectValues(serialize(null)));
        $this->assertSame([], $this->collectValues(serialize(3.14)));
    }

    // ---------------------------------------------------------------
    // String value: same-length and different-length replacements
    // ---------------------------------------------------------------

    public function testStringReplacementSameLength(): void
    {
        $input = serialize('hello');
        $result = $this->processWithTransform($input, fn($v) => 'HELLO');
        $this->assertSame(serialize('HELLO'), $result);
        $this->assertSame('HELLO', unserialize($result));
    }

    public function testStringReplacementLongerValue(): void
    {
        $input = serialize('hi');
        $result = $this->processWithTransform($input, fn($v) => 'hello world');
        $this->assertSame(serialize('hello world'), $result);
        $this->assertSame('hello world', unserialize($result));
    }

    public function testStringReplacementShorterValue(): void
    {
        $input = serialize('hello world');
        $result = $this->processWithTransform($input, fn($v) => 'hi');
        $this->assertSame(serialize('hi'), $result);
        $this->assertSame('hi', unserialize($result));
    }

    // ---------------------------------------------------------------
    // Only values are exposed — not array keys or property names
    // ---------------------------------------------------------------

    public function testOnlyValuesExposedNotArrayKeys(): void
    {
        $input = serialize(['key1' => 'value1', 'key2' => 'value2']);
        $this->assertSame(['value1', 'value2'], $this->collectValues($input));
    }

    public function testOnlyValuesExposedNotIntegerKeys(): void
    {
        $input = serialize(['alpha', 'beta']);
        $this->assertSame(['alpha', 'beta'], $this->collectValues($input));
    }

    // ---------------------------------------------------------------
    // Arrays
    // ---------------------------------------------------------------

    public function testEmptyArray(): void
    {
        $input = serialize([]);
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testNumericKeyedArray(): void
    {
        $input = serialize(['a', 'b', 'c']);
        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $this->assertSame(['A', 'B', 'C'], unserialize($result));
    }

    public function testStringKeyedArray(): void
    {
        $input = serialize(['foo' => 'bar', 'baz' => 'qux']);
        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $unserialized = unserialize($result);
        // Keys preserved, values uppercased
        $this->assertSame(['foo' => 'BAR', 'baz' => 'QUX'], $unserialized);
    }

    public function testNestedArray(): void
    {
        $input = serialize([
            'level1' => [
                'level2' => 'deep_value',
            ],
            'flat' => 'shallow',
        ]);
        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $unserialized = unserialize($result);
        $this->assertSame('DEEP_VALUE', $unserialized['level1']['level2']);
        $this->assertSame('SHALLOW', $unserialized['flat']);
    }

    public function testArrayWithMixedValueTypes(): void
    {
        $input = serialize([
            'str' => 'hello',
            'int' => 42,
            'bool' => true,
            'null' => null,
            'float' => 1.5,
        ]);
        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $unserialized = unserialize($result);
        $this->assertSame('HELLO', $unserialized['str']);
        $this->assertSame(42, $unserialized['int']);
        $this->assertTrue($unserialized['bool']);
        $this->assertNull($unserialized['null']);
        $this->assertSame(1.5, $unserialized['float']);
    }

    // ---------------------------------------------------------------
    // Objects
    // ---------------------------------------------------------------

    public function testStdClassObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->url = 'https://example.com';
        $input = serialize($obj);

        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $unserialized = unserialize($result);
        $this->assertSame('TEST', $unserialized->name);
        $this->assertSame('HTTPS://EXAMPLE.COM', $unserialized->url);
    }

    public function testOnlyValuesExposedNotObjectPropertyNames(): void
    {
        $obj = new \stdClass();
        $obj->propname = 'propvalue';
        $input = serialize($obj);

        $this->assertSame(['propvalue'], $this->collectValues($input));
    }

    public function testNestedObjectInsideArray(): void
    {
        $obj = new \stdClass();
        $obj->value = 'inner';
        $input = serialize(['wrapper' => $obj]);

        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $unserialized = unserialize($result);
        $this->assertSame('INNER', $unserialized['wrapper']->value);
    }

    // ---------------------------------------------------------------
    // Private/protected property null-byte visibility markers
    // ---------------------------------------------------------------

    public function testPrivatePropertyNullByteMarkers(): void
    {
        // Manually construct serialized data with private property null-byte markers.
        // Private properties are stored as: \0ClassName\0propname
        // This is s:14:"\0MyClass\0secret"; as the property name.
        $input = 'O:7:"MyClass":1:{s:15:"' . "\0" . 'MyClass' . "\0" . 'secret";s:5:"hello";}';

        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $this->assertNotSame($input, $result); // value was changed
        // The property name should be unchanged, the value should be uppercased
        $this->assertStringContainsString('HELLO', $result);
        // Property name should still contain null bytes
        $this->assertStringContainsString("\0" . 'MyClass' . "\0" . 'secret', $result);
    }

    public function testProtectedPropertyNullByteMarkers(): void
    {
        // Protected properties are stored as: \0*\0propname
        $input = 'O:7:"MyClass":1:{s:9:"' . "\0" . '*' . "\0" . 'hidden";s:3:"val";}';

        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $this->assertStringContainsString('VAL', $result);
        $this->assertStringContainsString("\0" . '*' . "\0" . 'hidden', $result);
    }

    // ---------------------------------------------------------------
    // References
    // ---------------------------------------------------------------

    public function testValueReferencePassthrough(): void
    {
        // r:N; reference
        $input = 'a:2:{i:0;s:5:"hello";i:1;r:2;}';
        $this->assertSame($input, $this->processIdentity($input));
    }

    public function testPointerReferencePassthrough(): void
    {
        // R:N; reference
        $input = 'a:2:{i:0;s:5:"hello";i:1;R:2;}';
        $this->assertSame($input, $this->processIdentity($input));
    }

    // ---------------------------------------------------------------
    // Custom serializable (C:)
    // ---------------------------------------------------------------

    public function testCustomSerializablePassthrough(): void
    {
        $input = 'C:7:"MyClass":11:{hello world}';
        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        // Custom serializable payload is opaque — passed through unchanged
        $this->assertSame($input, $result);
    }

    // ---------------------------------------------------------------
    // Enum cases (E:)
    // ---------------------------------------------------------------

    public function testEnumPassthroughAllowsSurroundingStringReplacements(): void
    {
        $enum_identifier = 'Example\\Münich_Status:Published';
        $serialized_enum = 'E:' . strlen($enum_identifier) . ':"' . $enum_identifier . '";';
        $input = 'a:3:{'
            . serialize('before') . serialize('old')
            . serialize('status') . $serialized_enum
            . serialize('after') . serialize('keep')
            . '}';
        $expected = 'a:3:{'
            . serialize('before') . serialize('a longer replacement')
            . serialize('status') . $serialized_enum
            . serialize('after') . serialize('keep')
            . '}';

        $processor = new PhpSerializationProcessor($input);

        $this->assertFalse($processor->is_malformed());
        $this->assertTrue($processor->next_value());
        $this->assertSame('old', $processor->get_value());
        $processor->set_value('a longer replacement');
        $this->assertTrue($processor->next_value());
        $this->assertSame('keep', $processor->get_value());
        $this->assertFalse($processor->next_value());
        $this->assertSame($expected, $processor->get_updated_serialization());
    }

    /**
     * @dataProvider malformedEnumSerializationCases
     */
    public function testMalformedEnumSerializationIsRejected(string $input): void
    {
        $processor = new PhpSerializationProcessor($input);

        $this->assertTrue($processor->is_malformed());
        $this->assertFalse($processor->next_value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEnumSerializationCases(): iterable
    {
        yield 'empty identifier' => ['E:0:"";'];
        yield 'missing class and case separator' => ['E:3:"abc";'];
        yield 'empty class' => ['E:2:":A";'];
        yield 'empty case' => ['E:2:"A:";'];
        yield 'multiple separators' => ['E:5:"A:B:C";'];
        yield 'declared length exceeds payload' => ['E:4:"A:B";'];
        yield 'missing semicolon' => ['E:3:"A:B"'];
    }

    // ---------------------------------------------------------------
    // URL rewriting: URL replaced and s:N: updated
    // ---------------------------------------------------------------

    public function testUrlRewritingUpdatesLengthPrefix(): void
    {
        $data = ['siteurl' => 'https://old-site.com'];
        $input = serialize($data);

        $result = $this->processWithTransform($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.example.com', $v);
        });

        $this->assertNotSame($input, $result);
        $unserialized = unserialize($result);
        $this->assertSame('https://new-site.example.com', $unserialized['siteurl']);
        // Verify the s:N: prefix is correct (the new URL is longer)
        $this->assertStringContainsString('s:28:', $result);
    }

    public function testUrlRewritingInSerializedString(): void
    {
        $input = serialize('https://old-site.com/page');

        $result = $this->processWithTransform($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.com', $v);
        });

        $this->assertSame('https://new-site.com/page', unserialize($result));
    }

    // ---------------------------------------------------------------
    // Round-trip: unserialize(process(serialize($data))) matches
    // ---------------------------------------------------------------

    public function testRoundTripWithComplexStructure(): void
    {
        $data = [
            'settings' => [
                'siteurl' => 'https://old-site.com',
                'blogname' => 'My Blog',
                'count' => 42,
                'active' => true,
            ],
            'theme' => 'default',
        ];

        $input = serialize($data);
        $result = $this->processWithTransform($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.com', $v);
        });

        $unserialized = unserialize($result);
        $expected = $data;
        $expected['settings']['siteurl'] = 'https://new-site.com';
        $this->assertSame($expected, $unserialized);
    }

    // ---------------------------------------------------------------
    // No-change case is byte-identical
    // ---------------------------------------------------------------

    public function testNoChangeIsByteIdentical(): void
    {
        $data = [
            'key' => 'value with no URLs',
            'nested' => ['inner' => 'also no URLs'],
            'number' => 42,
        ];
        $input = serialize($data);

        $result = $this->processIdentity($input);
        $this->assertSame($input, $result, 'When no changes are made, output must be byte-identical to input');
    }

    public function testNoChangeWithUrlReplaceThatDoesNotMatch(): void
    {
        $data = ['site' => 'https://unmatched-domain.com'];
        $input = serialize($data);

        // Transform that only replaces a specific domain that's not in the data
        $result = $this->processWithTransform($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.com', $v);
        });

        $this->assertSame($input, $result, 'When no values change, output must be byte-identical');
    }

    // ---------------------------------------------------------------
    // Malformed input
    // ---------------------------------------------------------------

    public function testMalformedInputIsMalformed(): void
    {
        $p = new PhpSerializationProcessor('not serialized');
        $this->assertTrue($p->is_malformed());
        $this->assertFalse($p->next_value());
    }

    public function testTruncatedStringIsMalformed(): void
    {
        $p = new PhpSerializationProcessor('s:10:"short";');
        $this->assertTrue($p->is_malformed());
        $this->assertFalse($p->next_value());
    }

    public function testMissingClosingBraceIsMalformed(): void
    {
        $p = new PhpSerializationProcessor('a:1:{i:0;s:3:"foo";');
        $this->assertTrue($p->is_malformed());
    }

    public function testTrailingGarbageIsMalformed(): void
    {
        $p = new PhpSerializationProcessor('s:3:"foo";GARBAGE');
        $this->assertTrue($p->is_malformed());
    }

    public function testEmptyStringIsMalformed(): void
    {
        $p = new PhpSerializationProcessor('');
        $this->assertTrue($p->is_malformed());
    }

    public function testMalformedInputReturnsOriginalFromGetUpdatedSerialization(): void
    {
        $input = 'not serialized at all';
        $p = new PhpSerializationProcessor($input);
        $this->assertTrue($p->is_malformed());
        $this->assertSame($input, $p->get_updated_serialization());
    }

    // ---------------------------------------------------------------
    // Strings containing quotes, semicolons, null bytes
    // ---------------------------------------------------------------

    public function testStringWithEmbeddedQuotes(): void
    {
        $input = serialize('He said "hello" to her');
        $result = $this->processIdentity($input);
        $this->assertSame($input, $result);
        $this->assertSame('He said "hello" to her', unserialize($result));
    }

    public function testStringWithEmbeddedSemicolons(): void
    {
        $input = serialize('a:1:{fake;data;}');
        $result = $this->processIdentity($input);
        $this->assertSame($input, $result);
        $this->assertSame('a:1:{fake;data;}', unserialize($result));
    }

    public function testStringWithNullBytes(): void
    {
        $value = "before\0after";
        $input = serialize($value);
        $result = $this->processIdentity($input);
        $this->assertSame($input, $result);
        $this->assertSame($value, unserialize($result));
    }

    public function testStringThatLooksLikeSerializedPhp(): void
    {
        // A string value that contains what looks like serialized PHP but is just text
        $value = 's:5:"inner";';
        $input = serialize($value);
        $result = $this->processWithTransform($input, fn($v) => strtoupper($v));
        $this->assertSame('S:5:"INNER";', unserialize($result));
    }
}
