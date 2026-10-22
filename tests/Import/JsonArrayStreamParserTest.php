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
        $parser = new JsonArrayStreamParser(static function (): void {});
        $this->expectException(\RuntimeException::class);
        $parser->feed($json);
        $parser->finish();
    }

    /** @return array<string, array{string}> Invalid complete or truncated bodies. */
    public static function invalid_arrays(): array {
        return array_map(static function ($json): array { return [$json]; }, [
            'empty' => '', 'object root' => '{}', 'leading comma' => '[,{}]',
            'trailing comma' => '[{},]', 'missing comma' => '[{}{}]',
            'missing close' => '[{}', 'unfinished string' => '["abc',
            'mismatched container' => '[{]]', 'invalid value' => '[no]',
            'trailing content' => '[{}]x', 'invalid escape' => '["\\q"]',
        ]);
    }
}
