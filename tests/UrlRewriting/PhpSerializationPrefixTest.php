<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class PhpSerializationPrefixTest extends TestCase {
    /**
     * @return array<string, array{string}>
     */
    public static function serializedValues(): array
    {
        $object = new stdClass();
        $object->url = 'https://old-site.com';
        $object->settings = ['enabled' => true];

        $recursive = [];
        $recursive['self'] = &$recursive;

        return [
            'null' => [serialize(null)],
            'boolean' => [serialize(true)],
            'integer' => [serialize(-123)],
            'float' => [serialize(-4.2e-8)],
            'binary string' => [serialize("quote\";\0bytes")],
            'nested arrays' => [serialize(['url' => 'https://old-site.com', 'items' => ['one', 'two']])],
            'object' => [serialize($object)],
            'reference' => [serialize($recursive)],
            'nan' => [serialize(NAN)],
        ];
    }

    /**
     * @dataProvider serializedValues
     */
    public function testAcceptsEveryPrefixOfSerializedValues(string $serialized): void
    {
        $length = strlen($serialized);
        for ($bytes = 0; $bytes <= $length; ++$bytes) {
            $this->assertTrue(
                PhpSerializationProcessor::is_valid_prefix($serialized, $bytes),
                "Failed at byte {$bytes}: {$serialized}"
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPrefixes(): array
    {
        return [
            'unknown type' => ['x:1;'],
            'string length lacks colon' => ['s:3x"foo";'],
            'string is shorter than declared' => ['s:3:"ab";'],
            'string closing quote is missing' => ['s:3:"abc;'],
            'string closing semicolon is missing' => ['s:3:"abc"x'],
            'integer has no digits' => ['i:;'],
            'integer is not terminated' => ['i:12x'],
            'boolean has an invalid value' => ['b:2;'],
            'boolean is not terminated' => ['b:1x'],
            'null is not terminated' => ['Nx'],
            'array length lacks opener' => ['a:1:x'],
            'array ends before its entry' => ['a:1:{}'],
            'array has no closing brace' => ['a:0:{x'],
            'object class name length is wrong' => ['O:3:"No":0:{}'],
            'object property count lacks opener' => ['O:8:"stdClass":1:x'],
            'object ends before its property' => ['O:8:"stdClass":1:{}'],
            'custom payload closing brace is wrong' => ['C:3:"Foo":3:{abcx'],
            'reference has no number' => ['R:;'],
            'reference is not terminated' => ['r:2x'],
            'trailing data' => ['i:1;shortcode'],
        ];
    }

    /**
     * @dataProvider invalidPrefixes
     */
    public function testRejectsSyntaxAlreadyImpossibleInThePrefix(string $serialized): void
    {
        $this->assertFalse(
            PhpSerializationProcessor::is_valid_prefix($serialized, strlen($serialized))
        );
    }

    public function testIgnoresMalformedBytesAfterTheLimit(): void
    {
        $serialized = 's:120:"' . str_repeat('a', 120) . '"x';

        $this->assertTrue(PhpSerializationProcessor::is_valid_prefix($serialized, 100));
        $this->assertFalse(PhpSerializationProcessor::is_valid_prefix($serialized, strlen($serialized)));
    }

    public function testRejectsAnInvalidByteAtTheLimit(): void
    {
        $serialized = 's:3:"abcx';
        $invalid_byte = strpos($serialized, 'x');

        $this->assertNotFalse($invalid_byte);
        $this->assertTrue(PhpSerializationProcessor::is_valid_prefix($serialized, $invalid_byte));
        $this->assertFalse(PhpSerializationProcessor::is_valid_prefix($serialized, $invalid_byte + 1));
    }
}
