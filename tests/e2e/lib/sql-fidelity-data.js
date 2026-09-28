export async function createSqlFidelityData(conn) {
    await conn.query(`
    CREATE TABLE wp_edge_cases (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        text_val TEXT,
        int_val BIGINT,
        float_val DOUBLE,
        blob_val BLOB,
        date_val DATETIME,
        ts_val TIMESTAMP NULL DEFAULT NULL,
        enum_val ENUM('a','b','c') DEFAULT NULL,
        set_val SET('x','y','z') DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    `);

    // Insert edge-case rows using parameterized queries
    await conn.query(
        `INSERT INTO wp_edge_cases (name, text_val, int_val, float_val, blob_val, date_val, ts_val, enum_val, set_val) VALUES
        ('null_text', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
        ('empty_string', '', 0, 0.0, '', '0000-00-00 00:00:00', NULL, 'a', 'x'),
        ('max_int', 'max', 9223372036854775807, 1.7976931348623157e+308, X'DEADBEEF', '9999-12-31 23:59:59', '2038-01-19 03:14:07', 'c', 'x,y,z'),
        ('negative', 'neg', -9223372036854775808, -1.7976931348623157e+308, X'00FF00FF', '2000-01-01 00:00:01', '2000-01-01 00:00:01', 'b', 'y'),
        ('unicode', 'Héllo Wörld 中文 🎉🚀', 42, 3.14, X'CAFEBABE', NOW(), NOW(), 'a', 'x,z'),
        ('backslash', 'path\\\\to\\\\file', 1, 1.0, X'5C5C', NOW(), NOW(), NULL, NULL),
        ('quotes', 'it''s a "test"', 2, 2.0, X'2227', NOW(), NOW(), NULL, NULL),
        ('newlines', 'line1\\nline2\\rline3\\r\\nline4', 3, 3.0, X'0A0D0A', NOW(), NOW(), NULL, NULL)`
    );

    // Long text (64KB)
    const longText = 'A'.repeat(65000);
    await conn.query(
        'INSERT INTO wp_edge_cases (name, text_val) VALUES (?, ?)',
        ['long_text', longText]
    );

    // Binary with NUL bytes
    const binaryData = Buffer.from([0x00, 0x01, 0x02, 0xFF, 0xFE, 0x00, 0x00, 0xFF]);
    await conn.query(
        'INSERT INTO wp_edge_cases (name, blob_val) VALUES (?, ?)',
        ['nul_bytes', binaryData]
    );
}
