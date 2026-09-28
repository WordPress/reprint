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
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    compareDatabases, createMysqlConnection, assertPullPipelineComplete,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { createBinaryKeyData, createOversizedBinaryKeyData } from '../lib/binary-key-data.js';

// Also runs on a site whose PHP-FPM master has no pdo_mysql, where the export
// streams through WordPress's $wpdb. Arbitrary bytes in primary keys travel
// through the connection's own quoting on every cursor, which is where the two
// connections would diverge if they were going to.
describe.each([
    ['adversarial-database', 'e2e_adversarial_database_import_53'],
    ['adversarial-database-no-pdo-mysql', 'e2e_adversarial_no_pdo_mysql_import_53'],
])('Import: Adversarial database pull (%s)', { timeout: 300000 }, (site, importDb) => {
    const adversarialTables = [
        'aa_binary_primary_keys',
        'ab_composite_binary_primary_key',
        'ac_oversized_binary_primary_key',
    ];
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            customDb: async (_dbName, conn) => {
                await createBinaryKeyData(conn);
                await createOversizedBinaryKeyData(conn);
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
            readFileSync(join(pullStateDirectory(tempDir, importUrl()), 'state.json'), 'utf-8'),
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
            'last_pk_values',
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
    e2e_write_hook_state($state_file, $state);
}

function test_hook_before_sql_batch(&$sql, $cursor) {
    list($state_file, $state) = _e2e_adversarial_cursor_state();
    $checkpoint = json_decode($cursor, true);
    $table = $checkpoint['current_table'] ?? null;
    $contains_binary_checkpoint = false;

    if (_e2e_adversarial_cursor_has_binary_marker($checkpoint['last_pk_values'] ?? null)) {
        $contains_binary_checkpoint = true;
        if (in_array($table, [
            'aa_binary_primary_keys',
            'ab_composite_binary_primary_key',
            'ac_oversized_binary_primary_key',
        ], true)) {
            $state['cursor_tables']['last_pk_values'][$table] = true;
        }
    }

    $forced = $state['forced_partial_responses'] ?? 0;
    if ($contains_binary_checkpoint && $forced < 3) {
        $state['forced_partial_responses'] = $forced + 1;
        e2e_write_hook_state($state_file, $state);
        usleep(1100000);
        return;
    }

    e2e_write_hook_state($state_file, $state);
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
