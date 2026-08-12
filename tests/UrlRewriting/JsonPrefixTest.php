<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Reprint\Importer\UrlRewrite\is_valid_json_prefix;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class JsonPrefixTest extends TestCase {
    /**
     * A prefix of valid JSON must always remain compatible with a valid JSON
     * document, even where it ends between the bytes of an escape or token.
     *
     * @return array<string, array{string}>
     */
    public static function validDocuments(): array
    {
        return [
            'object with nested arrays' => ['{"site":{"pages":["home",{"title":"About"}]},"enabled":true}'],
            'escaped string' => ['{"quote":"\\\"","slash":"\\/","unicode":"\\u20ac"}'],
            'numbers' => ['[-0,0,12,-12.5,6e3,-4.2E-8]'],
            'whitespace' => [" \t\n{\r\n \"name\" : \"value\" \n}\t"],
            'scalar strings' => ['"https:\/\/old-site.com\/wp-content\/uploads"'],
            'literals' => ['[true,false,null]'],
        ];
    }

    /**
     * @dataProvider validDocuments
     */
    public function testAcceptsEveryPrefixOfValidJson(string $document): void
    {
        $length = strlen($document);
        for ($bytes = 0; $bytes <= $length; ++$bytes) {
            $this->assertTrue(is_valid_json_prefix($document, $bytes), "Failed at byte {$bytes}: {$document}");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPrefixes(): array
    {
        return [
            'non-json root' => ['[et_pb_section]'],
            'unmatched closing delimiter' => [']'],
            'unquoted object key' => ['{title:"value"}'],
            'object key lacks colon' => ['{"title" "value"}'],
            'object value is missing' => ['{"title":}'],
            'trailing object comma' => ['{"title":"value",}'],
            'trailing array comma' => ['["title",]'],
            'array values lack comma' => ['["title" "value"]'],
            'array uses colon' => ['["title":"value"]'],
            'invalid string escape' => ['"\\x"'],
            'invalid unicode escape' => ['"\\u12x4"'],
            'control byte in string' => ["\"line\nbreak\""],
            'invalid literal' => ['truX'],
            'literal followed by token' => ['true false'],
            'leading plus number' => ['+1'],
            'leading zero number' => ['01'],
            'number has no integer digits' => ['-.1'],
            'number has no fractional digits' => ['1.e2'],
            'number has invalid exponent' => ['1eX'],
            'number has invalid signed exponent' => ['1e+X'],
            'two decimal points' => ['1.2.3'],
            'text after complete string' => ['"title"x'],
            'text after complete container' => ['{}[]'],
            'wrong closer for object' => ['{"title":"value"]'],
            'wrong closer for array' => ['["value"}'],
        ];
    }

    /**
     * @dataProvider invalidPrefixes
     */
    public function testRejectsSyntaxAlreadyImpossibleInThePrefix(string $document): void
    {
        $this->assertFalse(is_valid_json_prefix($document, strlen($document)));
    }

    public function testIgnoresBytesAfterTheLimit(): void
    {
        $document = '{"content":"' . str_repeat('a', 100) . '" invalid}';

        $this->assertTrue(is_valid_json_prefix($document, 100));
        $this->assertFalse(is_valid_json_prefix($document, strlen($document)));
    }

    public function testDoesNotLookPastACompleteJsonPrefix(): void
    {
        $document = '{"title":"value"} shortcode';

        $this->assertTrue(is_valid_json_prefix($document, strlen('{"title":"value"}')));
        $this->assertFalse(is_valid_json_prefix($document, strlen($document)));
    }

    public function testRejectsAnInvalidByteAtTheLimit(): void
    {
        $document = '{"title":"\\u12x4"}';
        $invalid_byte = strpos($document, 'x');

        $this->assertNotFalse($invalid_byte);
        $this->assertTrue(is_valid_json_prefix($document, $invalid_byte));
        $this->assertFalse(is_valid_json_prefix($document, $invalid_byte + 1));
    }
}
