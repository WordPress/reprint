<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\JsonArrayStreamParser;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/protocol/class-json-array-stream-parser.php';

final class JsonArrayStreamParserTest extends TestCase {
    public function testEveryByteBoundaryPreservesStringsNestingAndScalarValues(): void {
        $values = [['path' => 'quote" slash\\ braces{}[] comma, café', 'nested' => [1, [false, null]]], [], 'string', 12.5, true, null];
        $json = " \n" . json_encode($values) . "\r\n";
        $length = strlen($json);
        foreach (range(1, 31) as $chunk_bytes) {
            $received = [];
            $parser = new JsonArrayStreamParser(static function ($value) use (&$received): void { $received[] = $value;
});
            for ($offset = 0; $offset < $length; $offset += $chunk_bytes) {
                $parser->feed(substr($json, $offset, $chunk_bytes));
            }
            $parser->finish();
            $this->assertSame($values, $received);
        }
    }

    /**
     * @dataProvider valid_arrays
     * @param list<mixed> $expected Decoded values in their original order.
     */
    public function testAcceptsExplicitJsonAcrossEveryByteBoundary(string $json, array $expected): void {
        foreach ($this->chunkings($json) as $boundary => $chunks) {
            $this->assertSame($expected, $this->parse_chunks($chunks), $boundary);
        }
    }

    public function testEmitsTheFirstEntryWithoutWaitingForTheRestOfTheArray(): void {
        $received = [];
        $parser = new JsonArrayStreamParser(static function ($value) use (&$received): void { $received[] = $value;
});
        $parser->feed('[{"path":"first"},');
        $this->assertSame([['path' => 'first']], $received);
        $parser->feed('{"path":"second"}]');
        $parser->finish();
        $this->assertSame([['path' => 'first'], ['path' => 'second']], $received);
    }

    public function testAcceptsAnEmptyArray(): void {
        $parser = new JsonArrayStreamParser(static function (): void { self::fail('An empty array has no entries.');
});
        $parser->feed('[ ]');
        $parser->finish();
        $this->addToAssertionCount(1);
    }

    /** @dataProvider invalid_arrays */
    public function testRejectsMalformedOrIncompleteJson(string $json): void {
        foreach ($this->chunkings($json) as $boundary => $chunks) {
            try {
                $this->parse_chunks($chunks);
            } catch (\RuntimeException $exception) {
                $this->addToAssertionCount(1);
                continue;
            }
            $this->fail('Accepted malformed JSON with ' . $boundary . '.');
        }
    }

    /** @return array<string, array{string, list<mixed>}> Literal JSON and its decoded values. */
    public static function valid_arrays(): array {
        // Keep the JSON literal: json_encode() would escape raw UTF-8 and hide
        // whether a network chunk can end inside a multibyte character.
        return [
            'raw UTF-8 characters' => ['["café","写真","😀"]', ['café', '写真', '😀']],
            'raw UTF-8 object key' => ['[{"写真":"😀"}]', [['写真' => '😀']]],
            'combining character' => ["[\"e\xcc\x81\"]", ["e\xcc\x81"]],
            'raw Unicode line separators' => ["[\"\xe2\x80\xa8\xe2\x80\xa9\"]", ["\xe2\x80\xa8\xe2\x80\xa9"]],
            'escaped Unicode characters' => ['["\u0000\u00E9\u5199\u771F"]', ["\0é写真"]],
            'BMP characters around surrogate range' => ['["\uD7FF\uE000\uFFFF"]', ["\xed\x9f\xbf\xee\x80\x80\xef\xbf\xbf"]],
            'surrogate pairs' => ['["\uD83D\uDE00","\ud834\udd1e","\uDBFF\uDFFF"]', ['😀', '𝄞', "\xf4\x8f\xbf\xbf"]],
            'escaped structural characters' => ['["\u0022\u005C\u005B\u005D\u007B\u007D\u002C"]', ['"\\[]{},']],
            'string escapes' => ['["\"","\\\\","\/","\b","\f","\n","\r","\t"]', ['"', '\\', '/', "\x08", "\x0c", "\n", "\r", "\t"]],
            'backslashes before quotes' => [
                <<<'JSON'
["\\","\\\"","\\\\","\\\\\""]
JSON,
                ['\\', '\\"', '\\\\', '\\\\"'],
            ],
            'nested containers and punctuation in strings' => [
                '[{"nested":[{"text":",[]{}\"","value":[false,null]}]}]',
                [['nested' => [['text' => ',[]{}"', 'value' => [false, null]]]]],
            ],
            'empty containers' => ['[{},[],{"nested":[]}]', [[], [], ['nested' => []]]],
            'number syntax' => ['[0,-0,12,-34,1.25,-0.5,1e3,1E+3,-2.5e-2]', [0, 0, 12, -34, 1.25, -0.5, 1000.0, 1000.0, -0.025]],
            'literal values' => ['[true,false,null]', [true, false, null]],
            'JSON whitespace' => [" \t\r\n[ \t1 ,\r\n true,\t null ] \r\n", [1, true, null]],
            'escaped duplicate object key' => ['[{"\u0061":1,"a":2}]', [['a' => 2]]],
            'empty and numeric object keys' => ['[{"":0,"0":"zero","01":"leading"}]', [['' => 0, 0 => 'zero', '01' => 'leading']]],
            'byte order mark inside string' => ["[\"\xef\xbb\xbf\"]", ["\xef\xbb\xbf"]],
        ];
    }

    /** @return array<string, array{string}> Invalid complete or truncated bodies. */
    public static function invalid_arrays(): array {
        $invalid_arrays = [
            'empty' => '', 'object root' => '{}', 'leading comma' => '[,{}]',
            'trailing comma' => '[{},]', 'missing comma' => '[{}{}]',
            'missing close' => '[{}', 'unfinished string' => '["abc',
            'mismatched container' => '[{]]', 'invalid value' => '[no]',
            'trailing content' => '[{}]x', 'invalid escape' => '["\\q"]',
            'scalar root' => 'true', 'second array' => '[][1]',
            'doubled comma' => '[1,,2]', 'nested trailing comma' => '[{"a":[1,]}]',
            'adjacent strings' => '["a" "b"]', 'adjacent literals' => '[truefalse]',
            'single quoted string' => "['text']", 'unquoted object key' => '[{a:1}]',
            'missing colon' => '[{"a" 1}]', 'doubled colon' => '[{"a"::1}]',
            'uppercase literal' => '[True]', 'undefined literal' => '[undefined]',
            'leading plus' => '[+1]', 'leading zero' => '[01]', 'negative leading zero' => '[-01]',
            'leading decimal point' => '[.1]', 'trailing decimal point' => '[1.]',
            'unfinished exponent' => '[1e]', 'exponent sign without digits' => '[1e+]',
            'hexadecimal number' => '[0x10]', 'NaN literal' => '[NaN]', 'Infinity literal' => '[Infinity]',
            'Unicode escape without digits' => '["\u"]',
            'short Unicode escape' => '["\u123"]',
            'Unicode escape truncated at EOF' => '["\u123',
            'surrogate pair truncated at EOF' => '["\uD83D\uDE',
            'non-hex Unicode escape' => '["\u12x4"]',
            'uppercase Unicode escape marker' => '["\U0041"]',
            'braced Unicode escape' => '["\u{41}"]',
            'unpaired high surrogate' => '["\uD800"]',
            'unpaired low surrogate' => '["\uDC00"]',
            'reversed surrogate pair' => '["\uDC00\uD800"]',
            'high surrogate followed by ordinary character' => '["\uD800a"]',
            'high surrogate followed by BMP escape' => '["\uD800\u0061"]',
            'two high surrogates' => '["\uD800\uD800"]',
            'surrogate pair followed by unpaired surrogate' => '["\uD83D\uDE00\uDC00"]',
            'unfinished escape' => "[\"abc\\",
            'invalid UTF-8 continuation' => "[\"\xc3(\"]",
            'truncated UTF-8 character' => "[\"\xf0\x9f\x98\"]",
            'overlong UTF-8 quote' => "[\"\xc0\xa2\"]",
            'UTF-8 encoded surrogate' => "[\"\xed\xa0\x80\"]",
            'UTF-8 beyond Unicode range' => "[\"\xf4\x90\x80\x80\"]",
            'UTF-8 byte order mark before array' => "\xef\xbb\xbf[]",
            'UTF-16 little endian' => "[\0]\0",
            'UTF-16 big endian' => "\0[\0]",
            'UTF-32 little endian' => "[\0\0\0]\0\0\0",
            'UTF-32 big endian' => "\0\0\0[\0\0\0]",
            'vertical tab before array' => "\x0b[]",
            'form feed after array' => "[]\x0c",
            'non-breaking space between entries' => "[1,\xc2\xa0true]",
        ];
        foreach (range(0, 31) as $byte) {
            $invalid_arrays['unescaped control byte ' . $byte] = '["' . chr($byte) . '"]';
        }
        return array_map(static function ($json): array {
            return [$json];
        }, $invalid_arrays);
    }

    /** @return iterable<string, list<string>> Byte-at-a-time input and every two-chunk split, including empty feeds. */
    private function chunkings(string $json): iterable {
        yield 'byte-at-a-time input' => str_split($json);
        $length = strlen($json);
        for ($split = 0; $split <= $length; ++$split) {
            yield 'split at byte ' . $split => [substr($json, 0, $split), '', substr($json, $split)];
        }
    }

    /**
     * @param list<string> $chunks Consecutive slices of one JSON array.
     * @return list<mixed> Decoded array values.
     */
    private function parse_chunks(array $chunks): array {
        $received = [];
        $parser = new JsonArrayStreamParser(static function ($value) use (&$received): void {
            $received[] = $value;
        });
        foreach ($chunks as $chunk) {
            $parser->feed($chunk);
        }
        $parser->finish();
        return $received;
    }
}
