<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- This regression requires a real enum and its PHPUnit test in one file.
enum Reprint_Safe_Url_Rewrite_Data_Integrity_Status {
    case Published;
}

class SafeUrlRewriteDataIntegrityTest extends TestCase {
    private const SOURCE_URL = 'https://source.example';
    private const TARGET_URL = 'https://destination.example';

    private function createRewriter(): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter([
            self::SOURCE_URL => self::TARGET_URL,
        ]);
    }

    public function testSerializedObjectContainingEnumUpdatesStringLength(): void
    {
        $input_value = (object) [
            'url' => self::SOURCE_URL . '/article',
            'status' => Reprint_Safe_Url_Rewrite_Data_Integrity_Status::Published,
        ];
        $expected_value = clone $input_value;
        $expected_value->url = self::TARGET_URL . '/article';

        $result = $this->createRewriter()->rewrite(serialize($input_value));

        $this->assertSame(serialize($expected_value), $result);
        $this->assertEquals($expected_value, unserialize($result));
    }

    public function testJsonRewritePreservesNestedEmptyObject(): void
    {
        $input = '{"url":"https://source.example/article","settings":{"nested":{}}}';
        $expected = '{"url":"https://destination.example/article","settings":{"nested":{}}}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonRewritePreservesIntegerBeyondPhpIntMax(): void
    {
        $input = '{"url":"https://source.example/article","identifier":18446744073709551615}';
        $expected = '{"url":"https://destination.example/article","identifier":18446744073709551615}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonRewritePreservesObjectWithNumericMemberNames(): void
    {
        $input = '{"0":"https://source.example/article","1":"unchanged"}';
        $expected = '{"0":"https://destination.example/article","1":"unchanged"}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonRewritePreservesDuplicateUnrelatedMembers(): void
    {
        $input = '{"url":"https://source.example/article","label":"first","label":"second",'
            . '"settings":{"keep":true}}';
        $expected = '{"url":"https://destination.example/article","label":"first","label":"second",'
            . '"settings":{"keep":true}}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testJsonRewriteNeverChangesObjectMemberNames(): void
    {
        $input = '{"https:\/\/source.example\/same":"https:\/\/source.example\/same"}';
        $expected = '{"https:\/\/source.example\/same":"https:\/\/destination.example\/same"}';

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedTopLevelJsonFailsClosed(): void
    {
        $input = '{"url":"https://source.example/article",'
            . '"label":"keep\u0020this",}';

        $this->assertSame($input, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedJsonChildStaysOpaqueWhileValidSiblingRewrites(): void
    {
        $malformed_child = '{"url":"https://source.example/inside",}';
        $input = json_encode([
            'payload' => $malformed_child,
            'url' => self::SOURCE_URL . '/sibling',
        ], JSON_UNESCAPED_SLASHES);
        $expected = json_encode([
            'payload' => $malformed_child,
            'url' => self::TARGET_URL . '/sibling',
        ], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testMalformedSerializationChildStaysOpaqueWhileValidSiblingRewrites(): void
    {
        $malformed_child = 's:999:"' . self::SOURCE_URL . '/inside";';
        $input = json_encode([
            'payload' => $malformed_child,
            'url' => self::SOURCE_URL . '/sibling',
        ], JSON_UNESCAPED_SLASHES);
        $expected = json_encode([
            'payload' => $malformed_child,
            'url' => self::TARGET_URL . '/sibling',
        ], JSON_UNESCAPED_SLASHES);

        $this->assertIsString($input);
        $this->assertIsString($expected);
        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testEscapedJsonSchemeAndHostnameReachStructuredRewriter(): void
    {
        $input = " \n{\"url\":\"\\u0068\\u0074\\u0074\\u0070\\u0073:\\/\\/"
            . "sour\\u0063e\\u002eexample\\/article?next=%2f#part=%2E\","
            . "\"label\":\"keep\\u0020this\"}\t";
        $expected = " \n{\"url\":\"\\u0068\\u0074\\u0074\\u0070\\u0073:\\/\\/destination.example"
            . "\\/article?next=%2f#part=%2E\",\"label\":\"keep\\u0020this\"}\t";

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testSerializedPhpRewriteNeverChangesStructuralKeys(): void
    {
        $input = serialize([
            self::SOURCE_URL . '/same' => self::SOURCE_URL . '/same',
        ]);
        $expected = serialize([
            self::SOURCE_URL . '/same' => self::TARGET_URL . '/same',
        ]);

        $this->assertSame($expected, $this->createRewriter()->rewrite($input));
    }

    public function testBlockCommentJsonRewritePreservesUnrelatedValues(): void
    {
        $input = '<!-- wp:example '
            . '{"url":"https:\/\/source.example\/article","settings":{"nested":{}},'
            . '"identifier":18446744073709551615} /-->';
        $expected = '<!-- wp:example '
            . '{"url":"https:\/\/destination.example\/article","settings":{"nested":{}},'
            . '"identifier":18446744073709551615} /-->';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    public function testBlockCommentJsonRewritePreservesChangedStringSuffixEscapes(): void
    {
        $input = '<!-- wp:example '
            . '{"url":"https:\/\/source.example\/caf\u00e9?marker=\u003c"} /-->';
        $expected = '<!-- wp:example '
            . '{"url":"https:\/\/destination.example\/caf\u00e9?marker=\u003c"} /-->';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    public function testBlockCommentJsonRewriteNeverChangesMemberNames(): void
    {
        $input = '<!-- wp:example '
            . '{"https:\/\/source.example\/same":'
            . '"https:\/\/source.example\/same"} /-->';
        $expected = '<!-- wp:example '
            . '{"https:\/\/source.example\/same":'
            . '"https:\/\/destination.example\/same"} /-->';

        $this->assertSame(
            $expected,
            $this->createRewriter()->rewrite($input, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
