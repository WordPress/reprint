<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests MySQL dump with binary data, FULLTEXT, and uncommon data types:
 * blob, enum, set, JSON, geometry, bit, year, spatial, numeric.
 */
class DataTypesTest extends MySQLDumpProducerTestBase
{
    public function testOnlyCompleteTextValuesReceiveRewriteMarker(): void
    {
        $this->pdo->exec(
            "CREATE TABLE rewrite_types (
                id INT PRIMARY KEY,
                text_value TEXT,
                binary_value BLOB,
                enum_value ENUM('https://old-site.com', 'other')
            )"
        );
        $statement = $this->pdo->prepare(
            "INSERT INTO rewrite_types VALUES (1, ?, ?, ?)"
        );
        $url = 'https://old-site.com/file';
        $statement->execute([$url, $url, 'https://old-site.com']);

        $sql = $this->getDumpSQL();
        $encoded_url = base64_encode($url);
        $encoded_enum = base64_encode('https://old-site.com');

        $this->assertStringContainsString(
            "FROM_BASE64(/*reprint:complete-text-v1*/CONCAT('{$encoded_url}',''))",
            $sql
        );
        $this->assertSame(
            1,
            substr_count(
                $sql,
                "/*reprint:complete-text-v1*/CONCAT('{$encoded_url}','')"
            )
        );
        $this->assertStringContainsString(
            "FROM_BASE64(CONCAT('{$encoded_url}',''))",
            $sql
        );
        $this->assertStringContainsString(
            "FROM_BASE64(CONCAT('{$encoded_enum}',''))",
            $sql
        );
    }

    public function testBlobTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE blobs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                tiny_blob TINYBLOB,
                regular_blob BLOB,
                medium_blob MEDIUMBLOB,
                long_blob LONGBLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO blobs (tiny_blob, regular_blob, medium_blob, long_blob) VALUES (?, ?, ?, ?)");

        // Binary data with all byte values
        $binaryData = '';
        for ($i = 0; $i < 256; $i++) {
            $binaryData .= chr($i);
        }

        // TINYBLOB max is 255 bytes, so use smaller data for it
        $tinyBlobData = substr($binaryData, 0, 200);
        $stmt->execute([$tinyBlobData, $binaryData, $binaryData, $binaryData]);
        $stmt->execute(["\x00\xFF", "\xDE\xAD\xBE\xEF", pack('H*', 'CAFEBABE'), pack('H*', '0123456789ABCDEF')]);

        $sql = $this->getDumpSQL();

        // Verify base64 encoding is used for binary data
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['blobs']);

        // Verify binary data integrity
        $row = $importPdo->query("SELECT regular_blob FROM blobs WHERE id = 1")->fetch();
        $this->assertEquals($binaryData, $row['regular_blob']);
    }

    public function testBinaryVarBinary(): void
    {
        $this->pdo->exec("
            CREATE TABLE binary_types (
                id INT PRIMARY KEY AUTO_INCREMENT,
                fixed_binary BINARY(16),
                var_binary VARBINARY(100)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO binary_types (fixed_binary, var_binary) VALUES (?, ?)");

        // UUIDs as binary
        $uuid1 = random_bytes(16);
        $uuid2 = random_bytes(16);

        $stmt->execute([$uuid1, "\x00\x01\x02\x03\x04"]);
        $stmt->execute([$uuid2, pack('H*', 'FEEDFACE')]);

        $sql = $this->getDumpSQL();

        // Verify base64 encoding
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['binary_types']);
    }

    public function testEmptyBinaryFields(): void
    {
        $this->pdo->exec("
            CREATE TABLE empty_binary (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data BLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO empty_binary (data) VALUES (?)");
        $stmt->execute(['']);
        $stmt->execute([null]);
        $stmt->execute(["\x00"]);

        $sql = $this->getDumpSQL();

        // Empty binary should be ''
        // NULL should be NULL
        // Single null byte should be base64-encoded
        $this->assertMatchesRegularExpression('/\(1,\'\'\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,NULL\)/', $sql);
        $this->assertMatchesRegularExpression('/\(3,FROM_BASE64\(/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['empty_binary']);
    }

    public function testEnumType(): void
    {
        $this->pdo->exec("
            CREATE TABLE enum_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                status ENUM('pending', 'active', 'inactive', 'deleted') NOT NULL DEFAULT 'pending',
                priority ENUM('low', 'medium', 'high')
            )
        ");

        $this->pdo->exec("
            INSERT INTO enum_test (status, priority) VALUES
            ('pending', 'low'),
            ('active', 'high'),
            ('inactive', NULL),
            ('deleted', 'medium')
        ");

        $sql = $this->getDumpSQL();

        // ENUM values should be base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['enum_test']);
    }

    public function testSetType(): void
    {
        $this->pdo->exec("
            CREATE TABLE set_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                permissions SET('read', 'write', 'execute', 'delete')
            )
        ");

        $this->pdo->exec("
            INSERT INTO set_test (permissions) VALUES
            ('read'),
            ('read,write'),
            ('read,write,execute'),
            ('read,execute,delete'),
            ('')
        ");

        $sql = $this->getDumpSQL();

        // SET values should be base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['set_test']);
    }

    public function testJsonType(): void
    {
        $this->pdo->exec("
            CREATE TABLE json_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data JSON
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO json_test (data) VALUES (?)");
        $stmt->execute(['{"name": "John", "age": 30}']);
        $stmt->execute(['["apple", "banana", "cherry"]']);
        $stmt->execute(['{"nested": {"key": "value"}}']);
        $stmt->execute(['null']);
        $stmt->execute([null]);

        $sql = $this->getDumpSQL();

        // JSON data should be base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['json_test']);

        // Verify JSON can be queried
        $row = $importPdo->query("SELECT JSON_EXTRACT(data, '$.name') as name FROM json_test WHERE id = 1")->fetch();
        $this->assertEquals('John', trim($row['name'], '"'));
    }

    public function testBitType(): void
    {
        $this->pdo->exec("
            CREATE TABLE bit_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                flags BIT(8),
                single_bit BIT(1)
            )
        ");

        $this->pdo->exec("
            INSERT INTO bit_test (flags, single_bit) VALUES
            (b'11111111', b'1'),
            (b'10101010', b'0'),
            (b'00000000', b'1')
        ");

        $sql = $this->getDumpSQL();

        // BIT should be output as numeric
        $this->assertMatchesRegularExpression('/\(1,255,1\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,170,0\)/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['bit_test']);
    }

    public function testGeometryTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE geo_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                point_col POINT,
                line_col LINESTRING,
                polygon_col POLYGON
            )
        ");

        $this->pdo->exec("
            INSERT INTO geo_test (point_col, line_col, polygon_col) VALUES
            (ST_GeomFromText('POINT(1 1)'), ST_GeomFromText('LINESTRING(0 0, 1 1, 2 2)'), ST_GeomFromText('POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))'))
        ");

        $sql = $this->getDumpSQL();

        // Geometry types should be handled (likely as binary)
        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $count = $importPdo->query("SELECT COUNT(*) FROM geo_test")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testFullTextIndex(): void
    {
        $this->pdo->exec("
            CREATE TABLE articles (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200),
                body TEXT,
                FULLTEXT KEY title_body (title, body)
            )
        ");

        $this->pdo->exec("
            INSERT INTO articles (title, body) VALUES
            ('MySQL Tutorial', 'This tutorial explains how to use MySQL for database management'),
            ('PHP Programming', 'Learn PHP programming from scratch with practical examples'),
            ('Web Development', 'Complete guide to modern web development with HTML, CSS, and JavaScript')
        ");

        $sql = $this->getDumpSQL();

        // Verify FULLTEXT index is in CREATE TABLE
        $this->assertMatchesRegularExpression('/FULLTEXT.*title_body/s', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['articles']);

        // Verify FULLTEXT search works
        $result = $importPdo->query("
            SELECT id FROM articles
            WHERE MATCH(title, body) AGAINST('MySQL' IN NATURAL LANGUAGE MODE)
        ")->fetch();
        $this->assertEquals(1, $result['id']);
    }

    public function testYearType(): void
    {
        $this->pdo->exec("
            CREATE TABLE year_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                year_col YEAR
            )
        ");

        $this->pdo->exec("
            INSERT INTO year_test (year_col) VALUES
            (2024),
            (1901),
            (2155),
            (0000)
        ");

        $sql = $this->getDumpSQL();

        // YEAR should be numeric
        $this->assertMatchesRegularExpression('/\(1,2024\)/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['year_test']);
    }

    public function testSpatialTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE spatial_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                geom GEOMETRY NOT NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO spatial_test (geom) VALUES
            (ST_GeomFromText('POINT(1 1)')),
            (ST_GeomFromText('LINESTRING(0 0, 1 1, 2 2)')),
            (ST_GeomFromText('POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))'))
        ");

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $count = $importPdo->query("SELECT COUNT(*) FROM spatial_test")->fetchColumn();
        $this->assertEquals(3, $count);
    }

    public function testAllNumericTypes(): void
    {
        $this->pdo->exec("
            CREATE TABLE all_numerics (
                id INT PRIMARY KEY AUTO_INCREMENT,
                tiny TINYINT,
                small SMALLINT,
                medium MEDIUMINT,
                regular INT,
                big BIGINT,
                a_decimal DECIMAL(10,2),
                a_numeric NUMERIC(8,3),
                a_float FLOAT,
                a_double DOUBLE,
                a_real REAL
            )
        ");

        $this->pdo->exec("
            INSERT INTO all_numerics VALUES
            (1, 127, 32767, 8388607, 2147483647, 9223372036854775807, 12345.67, 54321.123, 3.14159, 2.718281828459, 1.414),
            (2, -128, -32768, -8388608, -2147483648, -9223372036854775808, -999.99, -123.456, -1.5, -2.5, -3.5),
            (3, 0, 0, 0, 0, 0, 0.00, 0.000, 0.0, 0.0, 0.0)
        ");

        $sql = $this->getDumpSQL();

        // All numeric types should be unquoted
        $this->assertMatchesRegularExpression('/\(1,127,32767,8388607,2147483647,9223372036854775807/', $sql);
        $this->assertSQLContains('12345.67', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['all_numerics']);
    }

    public function testLargeBlob(): void
    {
        $this->pdo->exec("
            CREATE TABLE large_blob (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data MEDIUMBLOB
            )
        ");

        // Generate 1MB of binary data
        $largeData = random_bytes(1024 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO large_blob (data) VALUES (?)");
        $stmt->execute([$largeData]);

        $sql = $this->getDumpSQL();

        // Should contain base64 encoding
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $imported = $importPdo->query("SELECT data FROM large_blob WHERE id = 1")->fetchColumn();
        $this->assertEquals(strlen($largeData), strlen($imported), 'Large blob size should match');
        $this->assertEquals($largeData, $imported, 'Large blob content should match');
    }
}
