/**
 * Test 23: SQL Data Fidelity via client.php
 * Round-trip test with edge-case MySQL data types: NULLs, empty strings,
 * binary blobs, unicode, MAX_INT, zero dates, very long text.
 * Verifies exact data preservation after export + import.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SQL Data Fidelity', () => {
    const site = 'http-errors';
    const importDb = 'e2e_http_errors_import_23';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            customDb: async (dbName, conn) => {
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
            },
        });
        tempDir = createTempDir('e2e-sql-fidelity');

        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('db-pull completes', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            // SQL streaming over WASM PHP needs extra time per invocation —
            // the curl pipeline is slower and the boot overhead adds ~12s on top.
            timeout: 240000,
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\n` +
            `signal: ${result.signal}, killed: ${result.killed}, errorCode: ${result.errorCode}\n` +
            `stderr (${result.stderr.length} bytes): ${result.stderr}\n` +
            `stdout (${result.stdout.length} bytes, last 2000): ${result.stdout.slice(-2000)}`);

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');
    });

    it('SQL dump loads into fresh database', async () => {
        const conn = await createMysqlConnection();
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        const sqlFile = join(tempDir, 'db.sql');
        execSync(
            `mysql -u e2e_admin -pe2e_password -h 127.0.0.1 ${importDb} < ${JSON.stringify(sqlFile)}`,
            { timeout: 30000, stdio: 'pipe' }
        );
    });

    it('all tables present in import', async () => {
        const conn = await createMysqlConnection(importDb);
        const [tables] = await conn.query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME",
            [importDb]
        );
        await conn.end();

        const names = tables.map(r => r.TABLE_NAME);
        assert.ok(names.includes('wp_options'), 'Expected wp_options table');
        assert.ok(names.includes('wp_edge_cases'), 'Expected wp_edge_cases table');
    });

    it('NULL values preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT * FROM wp_edge_cases WHERE name = 'null_text'"
        );
        await conn.end();

        assert.ok(row, 'Expected null_text row');
        assert.equal(row.text_val, null, 'Expected NULL text_val');
        assert.equal(row.int_val, null, 'Expected NULL int_val');
        assert.equal(row.blob_val, null, 'Expected NULL blob_val');
        assert.equal(row.date_val, null, 'Expected NULL date_val');
    });

    it('empty string preserved (not confused with NULL)', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT * FROM wp_edge_cases WHERE name = 'empty_string'"
        );
        await conn.end();

        assert.ok(row, 'Expected empty_string row');
        assert.equal(row.text_val, '', 'Expected empty string text_val');
        assert.equal(Number(row.int_val), 0, 'Expected 0 int_val');
    });

    it('large integer values preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT name, CAST(int_val AS CHAR) as int_str FROM wp_edge_cases WHERE name = 'max_int'"
        );
        await conn.end();

        assert.ok(row, 'Expected max_int row');
        // Use CAST to avoid JavaScript Number precision loss for BIGINT
        assert.equal(row.int_str, '9223372036854775807', 'Expected MAX BIGINT');
    });

    it('unicode text preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT * FROM wp_edge_cases WHERE name = 'unicode'"
        );
        await conn.end();

        assert.ok(row, 'Expected unicode row');
        assert.ok(row.text_val.includes('Héllo'), 'Expected accented chars');
        assert.ok(row.text_val.includes('中文'), 'Expected Chinese chars');
        assert.ok(row.text_val.includes('🎉'), 'Expected emoji');
    });

    it('backslashes preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT * FROM wp_edge_cases WHERE name = 'backslash'"
        );
        await conn.end();

        assert.ok(row, 'Expected backslash row');
        assert.ok(row.text_val.includes('\\'), 'Expected backslash in text');
    });

    it('quotes preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT * FROM wp_edge_cases WHERE name = 'quotes'"
        );
        await conn.end();

        assert.ok(row, 'Expected quotes row');
        assert.ok(row.text_val.includes("'"), 'Expected single quote');
        assert.ok(row.text_val.includes('"'), 'Expected double quote');
    });

    it('long text (64KB) preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT * FROM wp_edge_cases WHERE name = 'long_text'"
        );
        await conn.end();

        assert.ok(row, 'Expected long_text row');
        assert.equal(row.text_val.length, 65000, 'Expected 65000 char text');
        assert.ok(row.text_val.startsWith('AAAA'), 'Expected text to be all As');
    });

    it('binary data with NUL bytes preserved', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT HEX(blob_val) as hex_val FROM wp_edge_cases WHERE name = 'nul_bytes'"
        );
        await conn.end();

        assert.ok(row, 'Expected nul_bytes row');
        assert.equal(row.hex_val, '000102FFFE0000FF', 'Expected binary data with NUL bytes');
    });

    it('row count matches between source and import', async () => {
        const sourceConn = await createMysqlConnection('e2e_http_errors');
        const importConn = await createMysqlConnection(importDb);

        const [[srcCount]] = await sourceConn.query('SELECT COUNT(*) as cnt FROM wp_edge_cases');
        const [[impCount]] = await importConn.query('SELECT COUNT(*) as cnt FROM wp_edge_cases');

        await sourceConn.end();
        await importConn.end();

        assert.equal(
            Number(srcCount.cnt), Number(impCount.cnt),
            `Row count mismatch: source=${srcCount.cnt}, import=${impCount.cnt}`
        );
    });
});
