<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';

class Base64ValueScannerTest extends TestCase
{
    /**
     * Collect all decoded values from the scanner without modifying them.
     *
     * @return string[]
     */
    private function collectValues(string $sql): array
    {
        $values = [];
        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $values[] = $scanner->get_value();
        }
        return $values;
    }

    public function testFindsSimpleFromBase64(): void
    {
        $value = 'hello world';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(1, FROM_BASE64('{$encoded}'), NULL);";

        $values = $this->collectValues($sql);

        $this->assertCount(1, $values);
        $this->assertEquals($value, $values[0]);
    }

    public function testFindsConvertWrappedValue(): void
    {
        $value = '{"key": "value"}';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $values = $this->collectValues($sql);

        $this->assertCount(1, $values);
        $this->assertEquals($value, $values[0]);
    }

    public function testFindsMixedValueTypes(): void
    {
        $text = 'some text';
        $json = '{"url": "https://example.com"}';
        $text_enc = base64_encode($text);
        $json_enc = base64_encode($json);

        $sql = "INSERT INTO t VALUES(1, FROM_BASE64('{$text_enc}'), NULL, 42, CONVERT(FROM_BASE64('{$json_enc}') USING utf8mb4));";

        $values = $this->collectValues($sql);

        $this->assertCount(2, $values);
        $this->assertEquals($text, $values[0]);
        $this->assertEquals($json, $values[1]);
    }

    public function testReturnsNoValuesForNoBase64(): void
    {
        $sql = "CREATE TABLE t (id INT, name VARCHAR(255));";
        $values = $this->collectValues($sql);
        $this->assertCount(0, $values);
    }

    public function testReturnsNoValuesForNullAndNumeric(): void
    {
        $sql = "INSERT INTO t VALUES(1, NULL, 3.14);";
        $values = $this->collectValues($sql);
        $this->assertCount(0, $values);
    }

    public function testMultipleValuesInOneStatement(): void
    {
        $v1 = 'alpha';
        $v2 = 'beta';
        $v3 = 'gamma';
        $sql = sprintf(
            "INSERT INTO t VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($v1),
            base64_encode($v2),
            base64_encode($v3)
        );

        $values = $this->collectValues($sql);

        $this->assertCount(3, $values);
        $this->assertEquals($v1, $values[0]);
        $this->assertEquals($v2, $values[1]);
        $this->assertEquals($v3, $values[2]);
    }

    public function testSetValueReplacesSimpleValue(): void
    {
        $old = 'old value';
        $new = 'new value';
        $encoded_old = base64_encode($old);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded_old}'));";

        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $scanner->set_value($new);
        }
        $modified = $scanner->get_result();

        $expected_encoded = base64_encode($new);
        $this->assertStringContainsString("FROM_BASE64('{$expected_encoded}')", $modified);
        $this->assertStringNotContainsString($encoded_old, $modified);
    }

    public function testSetValuePreservesConvertWrapper(): void
    {
        $old = '{"old": true}';
        $new = '{"new": true}';
        $encoded_old = base64_encode($old);
        $sql = "INSERT INTO t VALUES(CONVERT(FROM_BASE64('{$encoded_old}') USING utf8mb4));";

        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $scanner->set_value($new);
        }
        $modified = $scanner->get_result();

        $expected_encoded = base64_encode($new);
        $this->assertStringContainsString("CONVERT(FROM_BASE64('{$expected_encoded}') USING utf8mb4)", $modified);
    }

    public function testSetValueOnMultipleValues(): void
    {
        $v1 = 'first';
        $v2 = 'second';
        $sql = sprintf(
            "INSERT INTO t VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($v1),
            base64_encode($v2)
        );

        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $scanner->set_value(strtoupper($scanner->get_value()));
        }
        $modified = $scanner->get_result();

        // Verify both replacements worked by re-scanning
        $new_values = $this->collectValues($modified);
        $this->assertCount(2, $new_values);
        $this->assertEquals('FIRST', $new_values[0]);
        $this->assertEquals('SECOND', $new_values[1]);
    }

    public function testNoChangeIsByteIdentical(): void
    {
        $value = 'unchanged';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded}'));";

        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            // read but don't modify
            $scanner->get_value();
        }

        $this->assertSame($sql, $scanner->get_result());
    }

    public function testHandlesEmptyString(): void
    {
        $value = '';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded}'));";

        $values = $this->collectValues($sql);

        $this->assertCount(1, $values);
        $this->assertEquals('', $values[0]);
    }

    public function testHandlesBase64WithSpecialChars(): void
    {
        // Value that produces base64 with +, /, and = characters
        $value = str_repeat("\xff\xfe\xfd", 10);
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded}'));";

        $values = $this->collectValues($sql);

        $this->assertCount(1, $values);
        $this->assertEquals($value, $values[0]);
    }

    public function testEncodedPayloadCouldContainHttpSchemeUsesEncodedPayload(): void
    {
        $sql = sprintf(
            "INSERT INTO t VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('plain text'),
            base64_encode('https://example.com/zero'),
            base64_encode('xhttps://example.com/one'),
            base64_encode('xxhttps://example.com/two')
        );

        $scanner = new Base64ValueScanner($sql);

        $this->assertTrue($scanner->next_value());
        $this->assertFalse($scanner->encoded_payload_could_contain_http_scheme());

        $this->assertTrue($scanner->next_value());
        $this->assertTrue($scanner->encoded_payload_could_contain_http_scheme());

        $this->assertTrue($scanner->next_value());
        $this->assertTrue($scanner->encoded_payload_could_contain_http_scheme());

        $this->assertTrue($scanner->next_value());
        $this->assertTrue($scanner->encoded_payload_could_contain_http_scheme());
    }
}
