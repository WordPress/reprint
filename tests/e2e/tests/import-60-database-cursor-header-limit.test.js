/**
 * A large non-key column value must not be copied into the next database
 * resume cursor. The test site deliberately returns 431 at its 8191-byte
 * X-Export-Cursor threshold. A later boundary covers offset continuation for
 * a table without a primary key.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection, writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: bounded database resume cursor', { timeout: 180000 }, () => {
    const site = 'database-cursor-header-limit';
    const sourceTable = 'aa_cursor_header_limit';
    const unkeyedTable = 'ab_cursor_offset_resume';
    const tables = [sourceTable, unkeyedTable];
    const importDb = 'e2e_database_cursor_header_limit_import_60';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_database, connection) => {
                await connection.query(
                    `CREATE TABLE \`${sourceTable}\` (`
                    + '`id` INT NOT NULL PRIMARY KEY, '
                    + '`payload` MEDIUMTEXT NOT NULL) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${sourceTable}\` (id, payload) VALUES (?, ?), (?, ?), (?, ?)`,
                    [
                        1, 'row before the large value',
                        2, `large value:${'x'.repeat(12 * 1024)}`,
                        3, 'row after the large value',
                    ],
                );
                await connection.query(
                    `CREATE TABLE \`${unkeyedTable}\` (`
                    + '`z_row_number` INT NOT NULL, '
                    + '`a_payload` VARCHAR(64) NOT NULL, '
                    + '`m_marker` VARBINARY(8) NOT NULL) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${unkeyedTable}\` VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)`,
                    [
                        1, 'unkeyed:first', Buffer.from('00ff01', 'hex'),
                        2, 'unkeyed:second', Buffer.from('80fe02', 'hex'),
                        3, 'unkeyed:third', Buffer.from('c0af03', 'hex'),
                    ],
                );
            },
        });
        tempDir = createTempDir('e2e-database-cursor-header-limit');

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.query(`CREATE DATABASE \`${importDb}\``);
        await connection.end();

        clearHookState(site);
        writeHookState(site, {
            sql_requests: 0,
            forced_boundaries: {},
            boundary_cursor_header_bytes: {},
        });
        writeTestHooks(site, `
function _e2e_cursor_header_limit_state() {
    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';
    $state = file_exists($state_file)
        ? json_decode(file_get_contents($state_file), true)
        : [];
    return [$state_file, is_array($state) ? $state : []];
}

function test_hook_after_gzip_init($gz, $boundary) {
    list($state_file, $state) = _e2e_cursor_header_limit_state();
    $state['sql_requests'] = ($state['sql_requests'] ?? 0) + 1;
    e2e_write_hook_state($state_file, $state);
}

function test_hook_before_sql_batch(&$sql, $cursor) {
    list($state_file, $state) = _e2e_cursor_header_limit_state();
    foreach (['${sourceTable}', '${unkeyedTable}'] as $table) {
        if (
            !isset($state['forced_boundaries'][$table])
            && strpos($sql, 'INSERT INTO \`' . $table . '\`') !== false
        ) {
            $state['forced_boundaries'][$table] = true;
            $state['boundary_cursor_header_bytes'][$table] = strlen(base64_encode($cursor));
            e2e_write_hook_state($state_file, $state);
            usleep(1100000);
            return;
        }
    }
    e2e_write_hook_state($state_file, $state);
}
`);
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.end();
    });

    it('returns 431 for a cursor header above the modeled host limit', async () => {
        const response = await fetch(getSiteUrl(site), {
            headers: { 'X-Export-Cursor': 'x'.repeat(8191) },
        });
        assert.equal(response.status, 431, 'Expected the test host to enforce its cursor limit');
    });

    it('keeps resume requests below the header limit and continues an unkeyed table', async () => {
        const result = runImporter(importUrl(), tempDir, 'pull-db', {
            secret: getSiteSecret(site),
            timeout: 120000,
            wallTimeout: 180000,
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
            `Expected pull-db exit 0, got ${result.exitCode}\n`
                + `stderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const hookState = readHookState(site);
        assert.deepEqual(
            Object.keys(hookState?.forced_boundaries ?? {}).sort(),
            tables,
            'Expected one forced request boundary for each table',
        );
        assert.ok(hookState?.sql_requests >= 3, 'Expected the SQL download to resume twice');
        assert.ok(
            hookState?.boundary_cursor_header_bytes?.[sourceTable] < 8191,
            `Expected a cursor header below 8191 bytes, got `
                + `${hookState?.boundary_cursor_header_bytes?.[sourceTable]}`,
        );

        const sourceKeyed = await readTable(getDbName(site), sourceTable, 'id');
        const importedKeyed = await readTable(importDb, sourceTable, 'id');
        assert.deepEqual(importedKeyed, sourceKeyed, 'The large row or a neighboring row changed');

        const sourceUnkeyed = await readTable(
            getDbName(site),
            unkeyedTable,
            'z_row_number',
        );
        const importedUnkeyed = await readTable(importDb, unkeyedTable, 'z_row_number');
        assert.deepEqual(
            importedUnkeyed,
            sourceUnkeyed,
            'The unkeyed table skipped, repeated, or changed a row',
        );
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }
});

async function readTable(database, table, orderBy) {
    const connection = await createMysqlConnection(database);
    try {
        const [columnRows] = await connection.query(`SHOW COLUMNS FROM \`${table}\``);
        const [rows] = await connection.query(
            `SELECT * FROM \`${table}\` ORDER BY \`${orderBy}\``
        );
        return {
            columns: columnRows.map((row) => row.Field),
            rows,
        };
    } finally {
        await connection.end();
    }
}
