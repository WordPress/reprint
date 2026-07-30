/**
 * Test 53: Adversarial database pull
 *
 * Runs the complete pull-db pipeline against tables whose primary keys contain
 * arbitrary bytes. Small SQL batches and short server budgets force those keys
 * through cursors across several requests. The target schema and rows must
 * match the source, including a composite key and an oversized binary row.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    compareDatabases, createMysqlConnection, assertPullPipelineComplete,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    getPullStatePath,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Adversarial database pull', { timeout: 300000 }, () => {
    const site = 'adversarial-database';
    const importDb = 'e2e_adversarial_database_import_53';
    const adversarialTables = [
        'aa_binary_primary_keys',
        'ab_composite_binary_primary_key',
        'ac_oversized_binary_primary_key',
    ];
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            customDb: async (_dbName, conn) => {
                await createAdversarialTables(conn);
            },
        });
        tempDir = createTempDir('e2e-adversarial-database-pull');

        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        clearHookState(site);
        writeHookState(site, {
            sql_requests: 0,
            forced_partial_responses: 0,
            cursor_tables: {},
        });
        writeTestHooks(site, cursorInspectionHooks(site));
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);

        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    it('preserves every hostile table through a multi-request pull-db', async () => {
        const result = runImporter(importUrl(), tempDir, 'pull-db', {
            secret: getSiteSecret(site),
            // PHP.wasm startup and SQL parsing add substantial overhead to
            // each importer invocation in the Playground E2E job.
            timeout: 240000,
            wallTimeout: 300000,
            maxResumeAttempts: 100,
            extraArgs: [
                '--target-host=127.0.0.1',
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${importDb}`,
                '--max-allowed-packet=1M',
                '--max-exec=1',
                '--sql-fragments-start=1',
                '--sql-fragments-min=1',
                '--sql-fragments-max=1',
            ],
        });
        assert.equal(
            result.exitCode,
            0,
            `Expected pull-db exit 0, got ${result.exitCode}\n` +
                `stderr (${result.stderr.length} bytes, last 4000): ` +
                `${result.stderr.slice(-4000)}\n` +
                `stdout (${result.stdout.length} bytes, last 4000): ` +
                `${result.stdout.slice(-4000)}`,
        );
        const importState = JSON.parse(
            readFileSync(getPullStatePath(tempDir), 'utf-8'),
        );
        assertPullPipelineComplete(importState, 'pull-db');

        const hookState = readHookState(site);
        assert.ok(hookState, 'Expected SQL cursor hook state');
        assert.ok(
            hookState.sql_requests >= 4,
            `Expected at least four SQL requests, got ${hookState.sql_requests}`,
        );
        assert.equal(
            hookState.forced_partial_responses,
            3,
            'Expected three resource-budget partial responses',
        );
        assertCursorCoveredTable(
            hookState,
            'last_pk_values',
            'aa_binary_primary_keys',
        );
        assertCursorCoveredTable(
            hookState,
            'last_pk_values',
            'ab_composite_binary_primary_key',
        );
        assertCursorCoveredTable(
            hookState,
            'current_row',
            'aa_binary_primary_keys',
        );
        assertCursorCoveredTable(
            hookState,
            'oversized_pk_values',
            'ac_oversized_binary_primary_key',
        );

        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(
            comparison.match && comparison.extraTables.length === 0,
            `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
                `extra=${JSON.stringify(comparison.extraTables)}, ` +
                `counts=${JSON.stringify(comparison.rowCounts)}`,
        );

        const sourceConn = await createMysqlConnection(getDbName(site));
        const importConn = await createMysqlConnection(importDb);
        try {
            for (const table of adversarialTables) {
                assert.equal(
                    await getCreateTable(sourceConn, table),
                    await getCreateTable(importConn, table),
                    `Schema mismatch for ${table}`,
                );
            }

            assert.deepEqual(
                await readSingleKeyRows(importConn),
                await readSingleKeyRows(sourceConn),
                'Single-column binary primary key rows changed',
            );
            assert.deepEqual(
                await readCompositeKeyRows(importConn),
                await readCompositeKeyRows(sourceConn),
                'Composite binary primary key rows changed',
            );
            assert.deepEqual(
                await readOversizedRows(importConn),
                await readOversizedRows(sourceConn),
                'Oversized binary primary key rows changed',
            );
        } finally {
            await sourceConn.end();
            await importConn.end();
        }
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }
});

async function createAdversarialTables(conn) {
    await conn.query(`
CREATE TABLE aa_binary_primary_keys (
    id VARBINARY(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    payload BLOB NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE ab_composite_binary_primary_key (
    tenant VARBINARY(32) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    suffix VARBINARY(32) NOT NULL,
    payload BLOB NOT NULL,
    PRIMARY KEY (tenant, sequence, suffix)
) ENGINE=InnoDB;

CREATE TABLE ac_oversized_binary_primary_key (
    id VARBINARY(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    payload LONGBLOB NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;
    `);

    const singleKeyRows = [
        [Buffer.alloc(0), 'empty key', Buffer.from('00ff80', 'hex')],
        [Buffer.from('00', 'hex'), 'NUL key', Buffer.from('000102ff', 'hex')],
        [Buffer.from('7f', 'hex'), 'ASCII boundary', Buffer.from('quotes-\'"\\\\\n')],
        [Buffer.from('80', 'hex'), 'continuation byte', bytePattern(257, 3)],
        [Buffer.from('c0af', 'hex'), 'overlong UTF-8', bytePattern(513, 7)],
        [Buffer.from('eda080', 'hex'), 'UTF-8 surrogate', bytePattern(1025, 11)],
        [Buffer.from('f0288cbc', 'hex'), 'invalid four-byte UTF-8', bytePattern(2049, 13)],
        [Buffer.from('fffefdfc0080', 'hex'), 'high and NUL bytes', bytePattern(4097, 17)],
    ];
    for (const row of singleKeyRows) {
        await conn.query(
            'INSERT INTO aa_binary_primary_keys (id, label, payload) VALUES (?, ?, ?)',
            row,
        );
    }

    const compositeKeyRows = [
        [Buffer.from('00ff', 'hex'), '0', Buffer.alloc(0), Buffer.from('first')],
        [Buffer.from('00ff', 'hex'), '18446744073709551615', Buffer.from('80', 'hex'), bytePattern(300, 19)],
        [Buffer.from('80', 'hex'), '42', Buffer.from('00ff00', 'hex'), bytePattern(600, 23)],
        [Buffer.from('fffe', 'hex'), '42', Buffer.from('c0af', 'hex'), bytePattern(900, 29)],
        [Buffer.from('fffe', 'hex'), '43', Buffer.from('fffefd', 'hex'), bytePattern(1200, 31)],
    ];
    for (const row of compositeKeyRows) {
        await conn.query(
            'INSERT INTO ab_composite_binary_primary_key ' +
                '(tenant, sequence, suffix, payload) VALUES (?, ?, ?, ?)',
            row,
        );
    }

    await conn.query(
        'INSERT INTO ac_oversized_binary_primary_key (id, label, payload) VALUES (?, ?, ?)',
        [
            Buffer.from('80ff00fe', 'hex'),
            'oversized binary row',
            bytePattern(2 * 1024 * 1024 + 17, 37),
        ],
    );
    await conn.query(
        'INSERT INTO ac_oversized_binary_primary_key (id, label, payload) VALUES (?, ?, ?)',
        [
            Buffer.from('fffefdfc', 'hex'),
            'row after oversized key',
            Buffer.from('00ff80fefdc0af', 'hex'),
        ],
    );
}

function cursorInspectionHooks(site) {
    return `
function _e2e_adversarial_cursor_state() {
    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';
    if (!file_exists($state_file)) {
        return [$state_file, []];
    }
    $state = json_decode(file_get_contents($state_file), true);
    return [$state_file, is_array($state) ? $state : []];
}

function _e2e_adversarial_cursor_has_binary_marker($value) {
    if (!is_array($value)) {
        return false;
    }
    if (array_key_exists('__binary__', $value)) {
        return true;
    }
    foreach ($value as $nested) {
        if (_e2e_adversarial_cursor_has_binary_marker($nested)) {
            return true;
        }
    }
    return false;
}

function test_hook_after_gzip_init($gz, $boundary) {
    list($state_file, $state) = _e2e_adversarial_cursor_state();
    $state['sql_requests'] = ($state['sql_requests'] ?? 0) + 1;
    file_put_contents($state_file, json_encode($state));
}

function test_hook_before_sql_batch(&$sql, $cursor) {
    list($state_file, $state) = _e2e_adversarial_cursor_state();
    $checkpoint = json_decode($cursor, true);
    $table = $checkpoint['current_table'] ?? null;
    $contains_binary_checkpoint = false;

    foreach (['last_pk_values', 'current_row', 'oversized_pk_values'] as $field) {
        if (_e2e_adversarial_cursor_has_binary_marker($checkpoint[$field] ?? null)) {
            $contains_binary_checkpoint = true;
            if (in_array($table, [
                'aa_binary_primary_keys',
                'ab_composite_binary_primary_key',
                'ac_oversized_binary_primary_key',
            ], true)) {
                $state['cursor_tables'][$field][$table] = true;
            }
        }
    }

    $forced = $state['forced_partial_responses'] ?? 0;
    if ($contains_binary_checkpoint && $forced < 3) {
        $state['forced_partial_responses'] = $forced + 1;
        file_put_contents($state_file, json_encode($state));
        usleep(1100000);
        return;
    }

    file_put_contents($state_file, json_encode($state));
}
`;
}

function assertCursorCoveredTable(hookState, field, table) {
    assert.equal(
        hookState.cursor_tables?.[field]?.[table],
        true,
        `Expected ${field} cursor coverage for ${table}`,
    );
}

async function getCreateTable(conn, table) {
    const [[row]] = await conn.query(`SHOW CREATE TABLE \`${table}\``);
    return row['Create Table'];
}

async function readSingleKeyRows(conn) {
    const [rows] = await conn.query(`
SELECT
    HEX(id) AS id_hex,
    label,
    HEX(payload) AS payload_hex
FROM aa_binary_primary_keys
ORDER BY id
    `);
    return rows;
}

async function readCompositeKeyRows(conn) {
    const [rows] = await conn.query(`
SELECT
    HEX(tenant) AS tenant_hex,
    CAST(sequence AS CHAR) AS sequence_text,
    HEX(suffix) AS suffix_hex,
    HEX(payload) AS payload_hex
FROM ab_composite_binary_primary_key
ORDER BY tenant, sequence, suffix
    `);
    return rows;
}

async function readOversizedRows(conn) {
    const [rows] = await conn.query(`
SELECT
    HEX(id) AS id_hex,
    label,
    OCTET_LENGTH(payload) AS payload_bytes,
    SHA2(payload, 256) AS payload_sha256,
    HEX(LEFT(payload, 16)) AS payload_prefix,
    HEX(RIGHT(payload, 16)) AS payload_suffix
FROM ac_oversized_binary_primary_key
ORDER BY id
    `);
    return rows;
}

function bytePattern(length, seed) {
    const bytes = Buffer.allocUnsafe(length);
    for (let index = 0; index < length; index++) {
        bytes[index] = (index * 131 + seed) % 256;
    }
    return bytes;
}
