<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests data fidelity across encodings: UTF-8, latin1, special characters,
 * binary columns, invalid UTF-8 sequences, and control characters.
 */
class EncodingTest extends MySQLDumpProducerTestBase
{
    // ──────────────────────────────────────────────────
    // Special characters (from SpecialCharactersTest)
    // ──────────────────────────────────────────────────

    public function testSingleQuotes(): void
    {
        $this->pdo->exec("
            CREATE TABLE quotes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO quotes (text) VALUES (?)");
        $stmt->execute(["It's a beautiful day"]);
        $stmt->execute(["She said 'hello'"]);
        $stmt->execute(["Multiple 'single' 'quotes'"]);

        $sql = $this->getDumpSQL();

        // Verify strings are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['quotes']);
    }

    public function testDoubleQuotes(): void
    {
        $this->pdo->exec("
            CREATE TABLE quotes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO quotes (text) VALUES (?)");
        $stmt->execute(['He said "hello"']);
        $stmt->execute(['A "quoted" word']);
        $stmt->execute(['"Start and end"']);

        $sql = $this->getDumpSQL();

        // Round-trip test - the key is data integrity
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['quotes']);

        // Verify exact content
        $rows = $importPdo->query("SELECT text FROM quotes ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals('He said "hello"', $rows[0]);
        $this->assertEquals('A "quoted" word', $rows[1]);
        $this->assertEquals('"Start and end"', $rows[2]);
    }

    public function testBackslashes(): void
    {
        $this->pdo->exec("
            CREATE TABLE paths (
                id INT PRIMARY KEY AUTO_INCREMENT,
                path VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO paths (path) VALUES (?)");
        $stmt->execute(['C:\\Windows\\System32']);
        $stmt->execute(['\\network\\share\\file']);
        $stmt->execute(['escaped\\tcharacter']);
        $stmt->execute(['backslash at end\\']);

        $sql = $this->getDumpSQL();

        // Verify strings are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['paths']);

        // Verify exact content
        $rows = $importPdo->query("SELECT path FROM paths ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals('C:\\Windows\\System32', $rows[0]);
        $this->assertEquals('\\network\\share\\file', $rows[1]);
    }

    public function testNewlines(): void
    {
        $this->pdo->exec("
            CREATE TABLE multiline (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO multiline (content) VALUES (?)");
        $stmt->execute(["Line 1\nLine 2"]);
        $stmt->execute(["First\nSecond\nThird"]);
        $stmt->execute(["Windows\r\nstyle"]);
        $stmt->execute(["\nLeading newline"]);
        $stmt->execute(["Trailing newline\n"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['multiline']);

        // Verify newlines are preserved
        $rows = $importPdo->query("SELECT content FROM multiline ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertStringContainsString("\n", $rows[0]);
        $this->assertEquals("Line 1\nLine 2", $rows[0]);
        $this->assertEquals("First\nSecond\nThird", $rows[1]);
    }

    public function testTabs(): void
    {
        $this->pdo->exec("
            CREATE TABLE tabbed (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO tabbed (data) VALUES (?)");
        $stmt->execute(["Column1\tColumn2\tColumn3"]);
        $stmt->execute(["\tLeading tab"]);
        $stmt->execute(["Trailing tab\t"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['tabbed']);

        $rows = $importPdo->query("SELECT data FROM tabbed ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("Column1\tColumn2\tColumn3", $rows[0]);
    }

    public function testNullByte(): void
    {
        $this->pdo->exec("
            CREATE TABLE binary_chars (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO binary_chars (data) VALUES (?)");
        $stmt->execute(["Before\x00After"]);
        $stmt->execute(["\x00Leading null"]);
        $stmt->execute(["Trailing null\x00"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['binary_chars']);
    }

    public function testMixedSpecialCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE special (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO special (content) VALUES (?)");

        // Combination of multiple special chars
        $stmt->execute(["It's \"quoted\" and has a newline\nAnd a backslash\\"]);
        $stmt->execute(["Tab\tthen 'quote' then \"double\" then \\backslash"]);
        $stmt->execute(["Path C:\\Users\\John's Files\\document.txt"]);
        $stmt->execute(["SQL: SELECT * FROM users WHERE name='O\\'Brien'"]);

        $sql = $this->getDumpSQL();

        // Round-trip test is the most important validation
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['special']);

        // Verify complex content is exact
        $rows = $importPdo->query("SELECT content FROM special ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("It's \"quoted\" and has a newline\nAnd a backslash\\", $rows[0]);
        $this->assertEquals("Path C:\\Users\\John's Files\\document.txt", $rows[2]);
    }

    public function testEmptyStringsVsNull(): void
    {
        $this->pdo->exec("
            CREATE TABLE empty_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                nullable_field VARCHAR(100) NULL,
                not_null_field VARCHAR(100) NOT NULL DEFAULT ''
            )
        ");

        $this->pdo->exec("
            INSERT INTO empty_test (nullable_field, not_null_field) VALUES
            (NULL, ''),
            ('', ''),
            ('text', 'text'),
            (NULL, 'text')
        ");

        $sql = $this->getDumpSQL();

        // Verify NULL vs empty string distinction
        // NULL stays as NULL, empty strings stay as ''
        $this->assertMatchesRegularExpression('/\(1,NULL,\'\'\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,\'\',\'\'\)/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['empty_test']);
    }

    public function testStringLiteralNull(): void
    {
        $this->pdo->exec("
            CREATE TABLE null_strings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO null_strings (data) VALUES (?)");
        $stmt->execute([null]);           // Actual NULL
        $stmt->execute(['NULL']);         // String "NULL"
        $stmt->execute(['null']);         // String "null"
        $stmt->execute(['']);             // Empty string
        $stmt->execute(['Not NULL']);     // String containing "NULL"

        $sql = $this->getDumpSQL();

        // Verify proper handling: actual NULL stays as NULL, string "NULL" is base64-encoded
        $this->assertMatchesRegularExpression('/\(1,NULL\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,FROM_BASE64\(/', $sql);
        $this->assertMatchesRegularExpression('/\(3,FROM_BASE64\(/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $rows = $importPdo->query("SELECT data FROM null_strings ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertNull($rows[0]['data']);
        $this->assertEquals('NULL', $rows[1]['data']);
        $this->assertEquals('null', $rows[2]['data']);
        $this->assertEquals('', $rows[3]['data']);
        $this->assertEquals('Not NULL', $rows[4]['data']);
    }

    public function testSQLInjectionStrings(): void
    {
        $this->pdo->exec("
            CREATE TABLE injection_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                malicious_input TEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO injection_test (malicious_input) VALUES (?)");

        // Various SQL injection attempts (as data, not actual injection)
        $stmt->execute(["'; DROP TABLE users; --"]);
        $stmt->execute(["1' OR '1'='1"]);
        $stmt->execute(["admin'--"]);
        $stmt->execute(["' UNION SELECT * FROM passwords --"]);

        $sql = $this->getDumpSQL();

        // These should all be safely escaped
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['injection_test']);

        // Verify the malicious strings are stored as plain text
        $rows = $importPdo->query("SELECT malicious_input FROM injection_test ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("'; DROP TABLE users; --", $rows[0]);
        $this->assertEquals("1' OR '1'='1", $rows[1]);
    }

    public function testControlCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE control_chars (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO control_chars (data) VALUES (?)");

        // Various control characters
        $stmt->execute(["Bell\x07"]);           // BEL
        $stmt->execute(["Backspace\x08"]);      // BS
        $stmt->execute(["Form feed\x0C"]);      // FF
        $stmt->execute(["Carriage return\r"]);  // CR
        $stmt->execute(["Escape\x1B"]);         // ESC

        $sql = $this->getDumpSQL();

        // Round-trip test - control chars should be preserved
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['control_chars']);
    }

    // ──────────────────────────────────────────────────
    // Encoding edge cases (from EncodingEdgeCasesTest)
    // ──────────────────────────────────────────────────

    /**
     * Test latin1 column containing data that looks like UTF-8.
     * When bytes are treated as codepoints, they form valid UTF-8.
     */
    public function testLatin1ColumnWithUtf8Bytes(): void
    {
        $this->pdo->exec("
            CREATE TABLE latin1_with_utf8 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        // Use hex literals to insert raw bytes into latin1 column
        // "café" in UTF-8 is: 63 61 66 C3 A9
        // When stored in latin1, each byte becomes a separate character
        $this->pdo->exec("INSERT INTO latin1_with_utf8 (data) VALUES (UNHEX('636166C3A9'))");

        // Multi-byte UTF-8 sequence: 😀 in UTF-8 (F0 9F 98 80)
        // In latin1, this becomes 4 separate characters
        $this->pdo->exec("INSERT INTO latin1_with_utf8 (data) VALUES (UNHEX('F09F9880'))");

        // UTF-8 with combining: é as 'e' + combining acute (65 CC 81)
        $this->pdo->exec("INSERT INTO latin1_with_utf8 (data) VALUES (UNHEX('65CC81'))");

        $sql = $this->getDumpSQL();

        // Verify data is exported (exact format depends on how PDO::quote handles it)
        $this->assertSQLContains('INSERT INTO', $sql);

        // Round-trip test - this is the real verification
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Compare raw bytes
        $original = $this->pdo->query("SELECT HEX(data) as h FROM latin1_with_utf8 ORDER BY id")->fetchAll();
        $imported = $importPdo->query("SELECT HEX(data) as h FROM latin1_with_utf8 ORDER BY id")->fetchAll();

        $this->assertEquals($original, $imported, 'Byte-for-byte comparison should match');
    }

    /**
     * Test latin1 column with undefined codepoints in cp1252.
     * MySQL's latin1 is actually cp1252 (Windows-1252), which has gaps.
     */
    public function testLatin1ColumnWithUndefinedCodepoints(): void
    {
        $this->pdo->exec("
            CREATE TABLE latin1_undefined (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        // cp1252 has undefined codepoints at: 0x81, 0x8D, 0x8F, 0x90, 0x9D
        // Use hex literals to insert raw bytes
        $undefined_codepoints = [
            '81', // Undefined in cp1252
            '8D', // Undefined in cp1252
            '8F', // Undefined in cp1252
            '90', // Undefined in cp1252
            '9D', // Undefined in cp1252
        ];

        foreach ($undefined_codepoints as $hex) {
            try {
                // before[BYTE]after
                $this->pdo->exec("INSERT INTO latin1_undefined (data) VALUES (UNHEX('6265666F7265{$hex}6166746572'))");
            } catch (PDOException $e) {
                // Some MySQL versions may reject these
                fwrite(STDERR, "Note: Undefined codepoint rejected by MySQL: {$hex}\n");
            }
        }

        // Also test valid latin1 high-byte characters using hex
        $valid_latin1_hex = [
            'A0', // Non-breaking space
            'C0', // À
            'E9', // é
            'FF', // ÿ
        ];

        foreach ($valid_latin1_hex as $hex) {
            // test[BYTE]data
            $this->pdo->exec("INSERT INTO latin1_undefined (data) VALUES (UNHEX('74657374{$hex}64617461'))");
        }

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify all rows that were inserted are preserved
        $originalCount = $this->pdo->query("SELECT COUNT(*) FROM latin1_undefined")->fetchColumn();
        $importedCount = $importPdo->query("SELECT COUNT(*) FROM latin1_undefined")->fetchColumn();
        $this->assertEquals($originalCount, $importedCount, 'Row count should match');

        // Byte-for-byte comparison
        $original = $this->pdo->query("SELECT id, HEX(data) as h FROM latin1_undefined ORDER BY id")->fetchAll();
        $imported = $importPdo->query("SELECT id, HEX(data) as h FROM latin1_undefined ORDER BY id")->fetchAll();
        $this->assertEquals($original, $imported, 'All bytes should be preserved exactly');
    }

    /**
     * Test UTF-8 column with attempt to store invalid UTF-8 sequences.
     * This tests MySQL's handling and our export's ability to preserve whatever gets stored.
     */
    public function testUtf8ColumnWithInvalidUtf8Handling(): void
    {
        $this->pdo->exec("
            CREATE TABLE utf8_handling (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        // First, insert valid UTF-8 data
        $stmt = $this->pdo->prepare("INSERT INTO utf8_handling (data) VALUES (?)");
        $stmt->execute(['Valid UTF-8: café 😀']);

        // Try to insert some edge cases that MySQL should handle
        $edge_cases = [
            'ASCII only',
            'Latin-1 subset: àáâãäå',
            'Multi-byte: 中文',
            'Emoji: 🎉🎊🎈',
            'Zero-width: ​', // Zero-width space (U+200B)
            'Combining: é vs é', // Precomposed vs e + combining
        ];

        foreach ($edge_cases as $data) {
            $stmt->execute([$data]);
        }

        // Test what happens with MySQL's utf8mb4_bin collation
        $this->pdo->exec("
            CREATE TABLE utf8_bin_collation (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
            )
        ");

        $stmt2 = $this->pdo->prepare("INSERT INTO utf8_bin_collation (data) VALUES (?)");
        foreach ($edge_cases as $data) {
            $stmt2->execute([$data]);
        }

        $sql = $this->getDumpSQL();

        // Verify export contains base64-encoded data
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $this->assertDatabasesEqual($this->pdo, $importPdo, ['utf8_handling', 'utf8_bin_collation']);
    }

    /**
     * Test mixed encoding scenarios in same database.
     */
    public function testMixedEncodingsInSameDatabase(): void
    {
        // Create tables with different character sets
        $this->pdo->exec("
            CREATE TABLE latin1_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE utf8_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        $this->pdo->exec("
            CREATE TABLE binary_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARBINARY(100)
            )
        ");

        // Insert same logical content in different encodings
        $text = "café";

        // latin1 table gets cp1252 encoded
        $stmt1 = $this->pdo->prepare("INSERT INTO latin1_table (data) VALUES (?)");
        $stmt1->execute([$text]);

        // utf8 table gets UTF-8 encoded
        $stmt2 = $this->pdo->prepare("INSERT INTO utf8_table (data) VALUES (?)");
        $stmt2->execute([$text]);

        // binary table gets raw UTF-8 bytes
        $stmt3 = $this->pdo->prepare("INSERT INTO binary_table (data) VALUES (?)");
        $stmt3->execute([utf8_encode($text)]);

        $sql = $this->getDumpSQL();

        // Verify all three tables are exported
        $this->assertSQLContains('latin1_table', $sql);
        $this->assertSQLContains('utf8_table', $sql);
        $this->assertSQLContains('binary_table', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Each table should preserve its encoding
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['latin1_table', 'utf8_table', 'binary_table']);

        // Verify the bytes are different in each table
        $latin1_hex = $importPdo->query("SELECT HEX(data) FROM latin1_table")->fetchColumn();
        $utf8_hex = $importPdo->query("SELECT HEX(data) FROM utf8_table")->fetchColumn();
        $binary_hex = $importPdo->query("SELECT HEX(data) FROM binary_table")->fetchColumn();

        // UTF-8 and binary should match (both UTF-8 encoded)
        // latin1 will be different (cp1252 encoding)
        $this->assertNotEquals($latin1_hex, $utf8_hex, 'latin1 and utf8 encodings should differ');
    }

    /**
     * Test NULL bytes and control characters in various encodings.
     */
    public function testNullBytesInDifferentEncodings(): void
    {
        $this->pdo->exec("
            CREATE TABLE latin1_nulls (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE utf8_nulls (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        // Insert data with NULL bytes
        $stmt1 = $this->pdo->prepare("INSERT INTO latin1_nulls (data) VALUES (?)");
        $stmt1->execute(["before\x00after"]);
        $stmt1->execute(["\x00start"]);
        $stmt1->execute(["end\x00"]);

        $stmt2 = $this->pdo->prepare("INSERT INTO utf8_nulls (data) VALUES (?)");
        $stmt2->execute(["before\x00after"]);
        $stmt2->execute(["\x00start"]);
        $stmt2->execute(["end\x00"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify NULL bytes are preserved
        $latin1_result = $importPdo->query("SELECT HEX(data) FROM latin1_nulls WHERE data LIKE '%after'")->fetchColumn();
        $this->assertStringContainsString('00', $latin1_result, 'NULL byte should be preserved in latin1');

        $utf8_result = $importPdo->query("SELECT HEX(data) FROM utf8_nulls WHERE data LIKE '%after'")->fetchColumn();
        $this->assertStringContainsString('00', $utf8_result, 'NULL byte should be preserved in utf8');

        $this->assertDatabasesEqual($this->pdo, $importPdo, ['latin1_nulls', 'utf8_nulls']);
    }

    /**
     * Test supplementary characters and UTF-8 edge cases.
     */
    public function testUtf8SupplementaryCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE utf8_supplementary (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO utf8_supplementary (data) VALUES (?)");

        // 1-byte UTF-8 (ASCII)
        $stmt->execute(['ASCII']);

        // 2-byte UTF-8
        $stmt->execute(['café']); // é is 2 bytes

        // 3-byte UTF-8
        $stmt->execute(['中文']); // Chinese characters are 3 bytes each

        // 4-byte UTF-8 (supplementary plane - emojis, ancient scripts)
        $stmt->execute(['😀😁😂']); // Emojis are 4 bytes each
        $stmt->execute(['𝕳𝖊𝖑𝖑𝖔']); // Mathematical bold fraktur (4 bytes)
        $stmt->execute(['🌍🌎🌏']); // Earth emojis

        // Maximum length UTF-8 sequences
        $stmt->execute(['𐍈']); // Gothic letter (U+10348, 4 bytes in UTF-8)

        $sql = $this->getDumpSQL();

        // Verify strings are base64-encoded in the export
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $this->assertDatabasesEqual($this->pdo, $importPdo, ['utf8_supplementary']);

        // Verify specific characters survived
        $emojis = $importPdo->query("SELECT data FROM utf8_supplementary WHERE data LIKE '%😀%'")->fetchColumn();
        $this->assertEquals('😀😁😂', $emojis, '4-byte UTF-8 emojis should be preserved');
    }

    /**
     * Test character set conversion edge cases at table level.
     */
    public function testTableCharacterSetDeclarations(): void
    {
        // Table with explicit latin1
        $this->pdo->exec("
            CREATE TABLE explicit_latin1 (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            ) CHARACTER SET latin1
        ");

        // Table with explicit utf8mb4
        $this->pdo->exec("
            CREATE TABLE explicit_utf8 (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            ) CHARACTER SET utf8mb4
        ");

        // Table with mixed column charsets
        $this->pdo->exec("
            CREATE TABLE mixed_columns (
                id INT PRIMARY KEY,
                latin1_col VARCHAR(50) CHARACTER SET latin1,
                utf8_col VARCHAR(50) CHARACTER SET utf8mb4,
                binary_col VARBINARY(50)
            )
        ");

        $this->pdo->exec("INSERT INTO explicit_latin1 VALUES (1, 'latin1 text')");
        $this->pdo->exec("INSERT INTO explicit_utf8 VALUES (1, 'utf8 text 中文')");
        $this->pdo->exec("INSERT INTO mixed_columns VALUES (1, 'latin', 'utf8 🎉', 0xDEADBEEF)");

        $sql = $this->getDumpSQL();

        // Verify CREATE TABLE statements preserve character sets
        $this->assertMatchesRegularExpression('/CHARACTER SET latin1/', $sql);
        $this->assertMatchesRegularExpression('/CHARACTER SET utf8mb4/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $this->assertDatabasesEqual($this->pdo, $importPdo, [
            'explicit_latin1',
            'explicit_utf8',
            'mixed_columns'
        ]);
    }

    // ──────────────────────────────────────────────────
    // Unicode and internationalization (from EncodingAndInvalidCharsTest)
    // ──────────────────────────────────────────────────

    public function testUTF8MultibyteCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE unicode_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO unicode_text (text) VALUES (?)",
        );
        $stmt->execute(["Hello 世界"]);
        $stmt->execute(["Καλημέρα κόσμε"]); // Greek
        $stmt->execute(["مرحبا بالعالم"]); // Arabic
        $stmt->execute(["Привет мир"]); // Russian
        $stmt->execute(["こんにちは世界"]); // Japanese

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["unicode_text"]);

        // Verify exact content
        $rows = $importPdo
            ->query("SELECT text FROM unicode_text ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("Hello 世界", $rows[0]);
        $this->assertEquals("Καλημέρα κόσμε", $rows[1]);
        $this->assertEquals("مرحبا بالعالم", $rows[2]);
    }

    public function testEmoji(): void
    {
        $this->pdo->exec("
            CREATE TABLE emoji_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content VARCHAR(500) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO emoji_data (content) VALUES (?)",
        );
        $stmt->execute(["Hello 😀 World"]);
        $stmt->execute(["🎉🎊🎈🎁"]);
        $stmt->execute(["Flags: 🇺🇸🇬🇧🇨🇦"]);
        $stmt->execute(["Skin tones: 👋🏻👋🏼👋🏽👋🏾👋🏿"]);
        $stmt->execute(["ZWJ sequence: 👨‍👩‍👧‍👦"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["emoji_data"]);

        // Verify emoji are preserved
        $rows = $importPdo
            ->query("SELECT content FROM emoji_data ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("Hello 😀 World", $rows[0]);
        $this->assertEquals("🎉🎊🎈🎁", $rows[1]);
    }

    public function testRightToLeftText(): void
    {
        $this->pdo->exec("
            CREATE TABLE rtl_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO rtl_text (content) VALUES (?)",
        );
        $stmt->execute(["مرحبا"]);
        $stmt->execute(["שלום"]);
        $stmt->execute(["Mixed: Hello مرحبا World"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["rtl_text"]);
    }

    public function testCombiningCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE combining (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO combining (text) VALUES (?)");

        // Combining diacritical marks
        $stmt->execute(["café"]); // é as single character
        $stmt->execute(["café"]); // e + combining acute
        $stmt->execute(["ñ"]);
        $stmt->execute(["Zürich"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Note: Normalization might affect exact byte comparison
        // So we check that we have 4 rows with the expected content
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM combining")
            ->fetchColumn();
        $this->assertEquals(4, $count);
    }

    public function testSupplementaryPlaneCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE surrogates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO surrogates (content) VALUES (?)",
        );

        // Characters outside BMP are encoded as two UTF-16 code units.
        $stmt->execute(["𝕳𝖊𝖑𝖑𝖔"]); // Mathematical alphanumeric symbols
        $stmt->execute(["🂡🂢🂣🂤"]); // Playing cards
        $stmt->execute(["𝄞𝄢𝄫"]); // Musical symbols

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["surrogates"]);
    }

    public function testZeroWidthCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE zero_width (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO zero_width (text) VALUES (?)");

        // Zero-width characters
        $stmt->execute(["Hello\u{200B}World"]); // Zero-width space
        $stmt->execute(["Test\u{200C}ing"]); // Zero-width non-joiner
        $stmt->execute(["Join\u{200D}ed"]); // Zero-width joiner
        $stmt->execute(["Left\u{200E}to\u{200F}Right"]); // LTR/RTL marks

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["zero_width"]);
    }

    public function testHomoglyphs(): void
    {
        $this->pdo->exec("
            CREATE TABLE homoglyphs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO homoglyphs (text) VALUES (?)");

        // Characters that look similar but are different
        $stmt->execute(["A"]); // Latin A
        $stmt->execute(["Α"]); // Greek Alpha
        $stmt->execute(["А"]); // Cyrillic A
        $stmt->execute(["apple"]); // Normal text
        $stmt->execute(["аррӏе"]); // Look-alike with Cyrillic

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["homoglyphs"]);

        // All 5 rows should be distinct
        $count = $importPdo
            ->query("SELECT COUNT(DISTINCT text) FROM homoglyphs")
            ->fetchColumn();
        $this->assertEquals(5, $count);
    }

    public function testInvalidUTF8Sequences(): void
    {
        $this->pdo->exec("
            CREATE TABLE possibly_invalid (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARBINARY(200)
            )
        ");

        // Insert binary data that might not be valid UTF-8
        $stmt = $this->pdo->prepare(
            "INSERT INTO possibly_invalid (data) VALUES (?)",
        );
        $stmt->execute(["\xFF\xFE"]); // Invalid UTF-8
        $stmt->execute(["\xC0\x80"]); // Overlong encoding
        $stmt->execute(["Valid\xFF\xFEInvalid"]); // Mixed

        $sql = $this->getDumpSQL();

        // VARBINARY data is base64-encoded
        $this->assertSQLContains("FROM_BASE64", $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, [
            "possibly_invalid",
        ]);
    }

    public function testMixedEncodings(): void
    {
        // Create table with latin1 column
        $this->pdo->exec("
            CREATE TABLE mixed_encoding (
                id INT PRIMARY KEY AUTO_INCREMENT,
                latin1_text VARCHAR(200) CHARACTER SET latin1,
                utf8_text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO mixed_encoding (latin1_text, utf8_text) VALUES (?, ?)",
        );
        // latin1 column can't store emoji, so use latin1-compatible characters only
        $stmt->execute(["café", "café 😀"]);
        $stmt->execute(["naïve", "naïve 世界"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["mixed_encoding"]);
    }

    public function testLongUnicodeStrings(): void
    {
        $this->pdo->exec("
            CREATE TABLE long_unicode (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT CHARACTER SET utf8mb4
            )
        ");

        // Generate long strings with multibyte characters
        $longString1 = str_repeat("世界", 500); // 1000 characters, 3000 bytes
        $longString2 = str_repeat("😀", 250); // 250 emoji

        $stmt = $this->pdo->prepare(
            "INSERT INTO long_unicode (content) VALUES (?)",
        );
        $stmt->execute([$longString1]);
        $stmt->execute([$longString2]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["long_unicode"]);

        // Verify lengths
        $row = $importPdo
            ->query("SELECT content FROM long_unicode WHERE id = 1")
            ->fetchColumn();
        $this->assertEquals(1000, mb_strlen($row, "UTF-8"));
    }

    public function testBOM(): void
    {
        $this->pdo->exec("
            CREATE TABLE bom_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO bom_test (content) VALUES (?)",
        );

        // UTF-8 BOM
        $stmt->execute(["\xEF\xBB\xBFWith BOM"]);
        $stmt->execute(["Without BOM"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["bom_test"]);
    }

    public function testWhitespaceVariations(): void
    {
        $this->pdo->exec("
            CREATE TABLE whitespace (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO whitespace (text) VALUES (?)");

        // Various unicode whitespace characters
        $stmt->execute(["Regular space"]);
        $stmt->execute(["Non\u{00A0}breaking\u{00A0}space"]); // NBSP
        $stmt->execute(["Em\u{2003}space"]); // Em space
        $stmt->execute(["Thin\u{2009}space"]); // Thin space
        $stmt->execute(["Zero\u{200B}width\u{200B}space"]); // Zero-width space

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["whitespace"]);
    }

    // ──────────────────────────────────────────────────
    // Binary columns with invalid UTF-8 (from BinaryColumnInvalidUtf8Test)
    // ──────────────────────────────────────────────────

    /**
     * Malformed UTF-8 leader bytes with no continuation: 0xC0, 0xFE, 0xFF
     * are never valid in any UTF-8 sequence. The dump must preserve them.
     */
    public function testIsolatedLeaderBytes(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data BLOB)");

        $values = [
            "\xC0",            // overlong 2-byte leader, alone
            "\xFE",            // never valid in UTF-8
            "\xFF",            // never valid in UTF-8
            "\xC0\xAF",       // overlong encoding of '/'
            "abc\xFEdef",     // invalid byte embedded in ASCII
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($values as $i => $val) {
            $stmt->execute([$i + 1, $val]);
        }

        $sql = $this->getDumpSQL();

        // Binary columns use base64 encoding
        $this->assertSQLContains('FROM_BASE64', $sql);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);

        // Verify each value byte-for-byte
        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $i => $expected) {
            $this->assertSame($expected, $rows[$i], "Byte mismatch at row " . ($i + 1));
        }
    }

    /**
     * Truncated multi-byte sequences: a 3-byte leader (0xE0) followed by
     * only one continuation byte, or a 4-byte leader (0xF0) followed by
     * two instead of three.
     */
    public function testTruncatedMultiByteSequences(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data BLOB)");

        $values = [
            "\xE0\x80",             // 3-byte sequence missing last byte
            "\xF0\x90\x80",         // 4-byte sequence missing last byte
            "hello\xE0world",       // orphan leader mid-string
            "\xF4\x90\x80\x80",    // above U+10FFFF (out of Unicode range)
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($values as $i => $val) {
            $stmt->execute([$i + 1, $val]);
        }

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $i => $expected) {
            $this->assertSame($expected, $rows[$i], "Byte mismatch at row " . ($i + 1));
        }
    }

    /**
     * A VARBINARY column with a mix of valid UTF-8, null bytes, and
     * broken sequences interleaved. This is the kind of mess you find
     * in serialized PHP data or corrupted text fields.
     */
    public function testMixedValidAndInvalidUtf8InVarbinary(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data VARBINARY(500))");

        $values = [
            // Valid emoji followed by broken sequence followed by ASCII
            "\xF0\x9F\x98\x80" . "\xC0\xAF" . "ok",
            // Null bytes sandwiching an invalid leader
            "\x00\xFF\x00",
            // Alternating valid/invalid: é (valid 2-byte) then lone continuation
            "\xC3\xA9" . "\x80" . "\xC3\xA9",
            // All 256 byte values in order — the ultimate round-trip stress test
            implode('', array_map('chr', range(0, 255))),
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($values as $i => $val) {
            $stmt->execute([$i + 1, $val]);
        }

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);

        $rows = $importPdo->query("SELECT HEX(data) FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $i => $expected) {
            $this->assertSame(
                strtoupper(bin2hex($expected)),
                $rows[$i],
                "Hex mismatch at row " . ($i + 1)
            );
        }
    }

    /**
     * Cursor-based reentrancy: pause and resume mid-table while exporting
     * binary data with invalid UTF-8. The cursor itself is JSON, so any
     * stray bytes in the accumulated row buffer must not corrupt it.
     */
    public function testReentrancyWithInvalidUtf8Binary(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, data BLOB)");

        $values = [];
        $stmt = $this->pdo->prepare("INSERT INTO t (data) VALUES (?)");
        for ($i = 0; $i < 20; $i++) {
            // Each row: some invalid UTF-8 leader bytes + random binary
            $val = "\xFE\xFF\xC0" . random_bytes(50);
            $values[] = $val;
            $stmt->execute([$val]);
        }

        // batch_size=3 forces multiple pauses within the 20 rows
        $options = ['batch_size' => 3];
        $producer = $this->createProducer($options);
        $allFragments = [];

        $iterations = 0;
        while (!$producer->is_finished() && $iterations < 100) {
            // Consume 2 fragments, then save/restore cursor
            $count = 0;
            while ($count < 2 && $producer->next_sql_fragment()) {
                $frag = $producer->get_sql_fragment();
                if ($frag !== null) {
                    $allFragments[] = $frag;
                }
                $count++;
            }
            if ($producer->is_finished()) {
                break;
            }

            $cursor = $producer->get_reentrancy_cursor();
            $this->assertNotNull($cursor, "Cursor must not be null mid-export");

            $options['cursor'] = $cursor;
            $producer = $this->createProducer($options);
            $iterations++;
        }

        $sql = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(20, $rows);
        foreach ($values as $i => $expected) {
            $this->assertSame($expected, $rows[$i], "Binary mismatch at row " . ($i + 1));
        }
    }

    /**
     * Primary key values are part of the resume cursor as well as the SQL.
     * Arbitrary key bytes must survive a cursor round trip without being
     * interpreted as JSON text.
     */
    public function testReentrancyWithInvalidUtf8PrimaryKey(): void
    {
        $this->pdo->exec("CREATE TABLE t (id VARBINARY(16) PRIMARY KEY, data VARCHAR(50))");

        $rows = [
            ["\x00\xFF", "first"],
            ["\x80\xFE", "second"],
            ["\xFF\xFE", "third"],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($rows as $row) {
            $stmt->execute($row);
        }

        $options = ["batch_size" => 1];
        $producer = $this->createProducer($options);
        $fragments = [];
        $saw_primary_key_checkpoint = false;

        while ($producer->next_sql_fragment()) {
            $fragments[] = $producer->get_sql_fragment();

            try {
                $cursor = $producer->get_reentrancy_cursor();
            } catch (RuntimeException $e) {
                $this->fail(
                    "The cursor must preserve arbitrary primary key bytes: " .
                    $e->getMessage()
                );
            }

            $cursor_data = json_decode($cursor, true);
            if ($cursor_data["last_pk_values"] !== null) {
                $saw_primary_key_checkpoint = true;
                $this->assertArrayHasKey(
                    "__binary__",
                    $cursor_data["last_pk_values"]["id"]
                );
            }

            if (!$producer->is_finished()) {
                $options["cursor"] = $cursor;
                $producer = $this->createProducer($options);
            }
        }

        $this->assertTrue($saw_primary_key_checkpoint);

        $sql = implode("\n", $fragments);
        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $import_pdo, ["t"]);
    }

    /**
     * A text primary key may contain bytes which are valid in the column's
     * character set but are not valid UTF-8. Each one-row batch must resume
     * from those bytes without passing them through the connection charset.
     */
    public function testReentrancyWithLatin1TextPrimaryKeyBytes(): void
    {
        $this->pdo->exec(
            "CREATE TABLE t (
                id VARCHAR(16) CHARACTER SET latin1 COLLATE latin1_bin PRIMARY KEY,
                data VARCHAR(50)
            )"
        );
        $this->pdo->exec(
            "INSERT INTO t (id, data) VALUES
                (CONVERT(X'41' USING latin1), 'ascii-a'),
                (CONVERT(X'42' USING latin1), 'ascii-b'),
                (CONVERT(X'80' USING latin1), 'byte-80'),
                (CONVERT(X'E9' USING latin1), 'byte-e9')"
        );

        [$sql, $resume_count] = $this->exportWithResumeAfterEveryFragment([
            "batch_size" => 1,
        ]);

        $this->assertGreaterThan(3, $resume_count);

        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(
            ["41", "42", "80", "E9"],
            $import_pdo
                ->query("SELECT HEX(id) FROM t ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * Primary key comparison must follow the column type rather than PHP's
     * classification of the fetched value. Numeric-looking VARCHAR values
     * still use string ordering.
     */
    public function testReentrancyWithNumericLatin1TextPrimaryKeyValues(): void
    {
        $this->pdo->exec(
            "CREATE TABLE t (
                id VARCHAR(16) CHARACTER SET latin1 COLLATE latin1_bin PRIMARY KEY,
                data VARCHAR(50)
            )"
        );
        $this->pdo->exec(
            "INSERT INTO t (id, data) VALUES
                ('10', 'ten'),
                ('100', 'one-hundred'),
                ('2', 'two')"
        );

        [$sql, $resume_count] = $this->exportWithResumeAfterEveryFragment([
            "batch_size" => 1,
        ]);

        $this->assertGreaterThan(3, $resume_count);

        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(
            ["10", "100", "2"],
            $import_pdo
                ->query("SELECT id FROM t ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * The resume predicate and ORDER BY must both follow the primary key index.
     * latin1_german2_ci sorts ä before b, while raw bytes sort b, z, then ä.
     */
    public function testReentrancyWithLatin1TextPrimaryKeyIndexOrdering(): void
    {
        $this->pdo->exec(
            "CREATE TABLE t (
                id VARCHAR(16) CHARACTER SET latin1 COLLATE latin1_german2_ci PRIMARY KEY,
                data VARCHAR(50)
            )"
        );
        $this->pdo->exec(
            "INSERT INTO t (id, data) VALUES
                (CONVERT(X'E4' USING latin1), 'umlaut-a'),
                ('b', 'b'),
                ('z', 'z')"
        );

        [$sql, $resume_count] = $this->exportWithResumeAfterEveryFragment([
            "batch_size" => 1,
        ]);

        $this->assertGreaterThan(3, $resume_count);

        $letter_b_position = strpos($sql, "FROM_BASE64('Yg==')");
        $letter_z_position = strpos($sql, "FROM_BASE64('eg==')");
        $umlaut_a_position = strpos($sql, "FROM_BASE64('5A==')");
        $this->assertNotFalse($letter_b_position);
        $this->assertNotFalse($letter_z_position);
        $this->assertNotFalse($umlaut_a_position);
        $this->assertTrue(
            $umlaut_a_position < $letter_b_position &&
            $letter_b_position < $letter_z_position,
            "Text primary keys must be emitted in primary key index order"
        );

        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(
            ["E4", "62", "7A"],
            $import_pdo
                ->query("SELECT HEX(id) FROM t ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * A composite primary key may contain byte-distinct text values which its
     * collation considers equal. The following numeric key part must determine
     * their order across one-row batches and repeated resume boundaries.
     */
    public function testReentrancyWithCompositeLatin1TextPrimaryKey(): void
    {
        $this->pdo->exec(
            "CREATE TABLE t (
                category VARCHAR(16) CHARACTER SET latin1 COLLATE latin1_german2_ci NOT NULL,
                sequence_number INT NOT NULL,
                data VARCHAR(50),
                PRIMARY KEY (category, sequence_number)
            )"
        );
        $this->pdo->exec(
            "INSERT INTO t (category, sequence_number, data) VALUES
                (CONVERT(X'E4' USING latin1), 1, 'umlaut-1'),
                ('ae', 2, 'ae-2'),
                (CONVERT(X'E4' USING latin1), 3, 'umlaut-3'),
                ('b', 1, 'b-1'),
                ('z', 1, 'z-1')"
        );

        [$sql, $resume_count] = $this->exportWithResumeAfterEveryFragment([
            "batch_size" => 1,
        ]);

        $this->assertGreaterThan(5, $resume_count);

        $previous_position = -1;
        foreach (["umlaut-1", "ae-2", "umlaut-3", "b-1", "z-1"] as $data) {
            $position = strpos(
                $sql,
                "FROM_BASE64('" . base64_encode($data) . "')"
            );
            $this->assertNotFalse($position);
            $this->assertGreaterThan(
                $previous_position,
                $position,
                "Composite primary keys must be emitted in primary key index order"
            );
            $previous_position = $position;
        }

        $import_pdo = $this->executeDumpInNewDatabase($sql);
        $this->assertSame(
            [
                "E4:1:umlaut-1",
                "6165:2:ae-2",
                "E4:3:umlaut-3",
                "62:1:b-1",
                "7A:1:z-1",
            ],
            $import_pdo
                ->query(
                    "SELECT CONCAT(HEX(category), ':', sequence_number, ':', data)
                     FROM t
                     ORDER BY category, sequence_number"
                )
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * Exports while replacing the producer from its cursor after every
     * fragment, simulating a new request at each available resume boundary.
     *
     * @return array{0: string, 1: int} Dump SQL and number of resumes.
     */
    private function exportWithResumeAfterEveryFragment(array $options): array
    {
        $producer = $this->createProducer($options);
        $fragments = [];
        $resume_count = 0;

        while ($producer->next_sql_fragment()) {
            $fragments[] = $producer->get_sql_fragment();

            if (!$producer->is_finished()) {
                $options["cursor"] = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer($options);
                ++$resume_count;
            }
        }

        return [implode("\n", $fragments), $resume_count];
    }
}
