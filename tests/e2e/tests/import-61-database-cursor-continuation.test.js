/**
 * A request boundary after row 1 must resume with row 2 and leave the keyed
 * and unkeyed target tables matching the source.
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

describe('Import: database cursor continuation', { timeout: 180000 }, () => {
    const site = 'database-cursor-continuation';
    const importDb = 'e2e_database_cursor_continuation_import_61';
    const tables = ['aa_resume_keyed', 'ab_resume_unkeyed'];
    const orderedColumns = ['z_row_number', 'a_payload', 'm_marker'];
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_database, connection) => {
                await connection.query(
                    'CREATE TABLE `aa_resume_keyed` ('
                    + '`z_row_number` INT NOT NULL PRIMARY KEY, '
                    + '`a_payload` VARCHAR(64) NOT NULL, '
                    + '`m_marker` VARBINARY(8) NOT NULL) ENGINE=InnoDB'
                );
                await connection.query(
                    'CREATE TABLE `ab_resume_unkeyed` ('
                    + '`z_row_number` INT NOT NULL, '
                    + '`a_payload` VARCHAR(64) NOT NULL, '
                    + '`m_marker` VARBINARY(8) NOT NULL) ENGINE=InnoDB'
                );

                for (const table of tables) {
                    await connection.query(
                        `INSERT INTO \`${table}\` VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)`,
                        [
                            1, `${table}:first`, Buffer.from('00ff01', 'hex'),
                            2, `${table}:second`, Buffer.from('80fe02', 'hex'),
                            3, `${table}:third`, Buffer.from('c0af03', 'hex'),
                        ],
                    );
                }
            },
        });
        tempDir = createTempDir('e2e-database-cursor-continuation');

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.query(`CREATE DATABASE \`${importDb}\``);
        await connection.end();

        clearHookState(site);
        writeHookState(site, { sql_requests: 0, boundaries: {} });
        writeTestHooks(site, `
function _e2e_cursor_continuation_state() {
    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';
    $state = file_exists($state_file)
        ? json_decode(file_get_contents($state_file), true)
        : [];
    return [$state_file, is_array($state) ? $state : []];
}

function test_hook_after_gzip_init($gz, $boundary) {
    list($state_file, $state) = _e2e_cursor_continuation_state();
    $state['sql_requests'] = ($state['sql_requests'] ?? 0) + 1;
    file_put_contents($state_file, json_encode($state));
}

function test_hook_before_sql_batch(&$sql, $cursor) {
    list($state_file, $state) = _e2e_cursor_continuation_state();
    foreach (['aa_resume_keyed', 'ab_resume_unkeyed'] as $table) {
        if (
            !isset($state['boundaries'][$table])
            && strpos($sql, 'INSERT INTO \`' . $table . '\`') !== false
        ) {
            $state['boundaries'][$table] = true;
            file_put_contents($state_file, json_encode($state));
            usleep(1100000);
            return;
        }
    }
    file_put_contents($state_file, json_encode($state));
}
`);
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        for (const table of tables) {
            await connection.query(
                `DROP TABLE IF EXISTS \`${getDbName(site)}\`.\`${table}\``
            );
        }
        await connection.end();
    });

    it('resumes keyed and unkeyed tables from the last emitted row', async () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--sql-output=mysql',
                '--mysql-host=127.0.0.1',
                '--mysql-user=e2e_admin',
                '--mysql-password=e2e_password',
                `--mysql-database=${importDb}`,
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
            `Expected db-pull exit 0, got ${result.exitCode}\n`
                + `stderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const hookState = readHookState(site);
        assert.ok(hookState?.sql_requests >= 3, 'Expected a new request after both boundaries');
        assert.deepEqual(
            Object.keys(hookState?.boundaries ?? {}).sort(),
            tables,
            'Expected one saved boundary for each table',
        );

        for (const table of tables) {
            const source = await readTable(getDbName(site), table);
            const imported = await readTable(importDb, table);
            assert.deepEqual(imported.columns, orderedColumns, `${table} column order changed`);
            assert.deepEqual(imported, source, `${table} skipped, repeated, or changed a row`);
            assert.deepEqual(imported.rows.map((row) => row.z_row_number), [1, 2, 3]);
        }
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }
});

async function readTable(database, table) {
    const connection = await createMysqlConnection(database);
    try {
        const [columnRows] = await connection.query(`SHOW COLUMNS FROM \`${table}\``);
        const [rows] = await connection.query(
            `SELECT z_row_number, a_payload, HEX(m_marker) AS marker_hex `
                + `FROM \`${table}\` ORDER BY z_row_number`
        );
        return {
            columns: columnRows.map((row) => row.Field),
            rows,
        };
    } finally {
        await connection.end();
    }
}
