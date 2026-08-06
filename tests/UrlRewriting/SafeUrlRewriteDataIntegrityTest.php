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
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
