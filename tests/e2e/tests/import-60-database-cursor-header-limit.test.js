/**
 * A large non-key column value must not be copied into the next database
 * resume cursor. The test site deliberately returns 431 at its 8191-byte
 * X-Export-Cursor threshold.
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
            forced_boundaries: 0,
            boundary_cursor_header_bytes: null,
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
    file_put_contents($state_file, json_encode($state));
}

function test_hook_before_sql_batch(&$sql, $cursor) {
    list($state_file, $state) = _e2e_cursor_header_limit_state();
    if (
        ($state['forced_boundaries'] ?? 0) === 0
        && strpos($sql, 'INSERT INTO \`${sourceTable}\`') !== false
    ) {
        $state['forced_boundaries'] = 1;
        $state['boundary_cursor_header_bytes'] = strlen(base64_encode($cursor));
        file_put_contents($state_file, json_encode($state));
        usleep(1100000);
        return;
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
        await connection.end();
    });

    it('returns 431 for a cursor header above the modeled host limit', async () => {
        const response = await fetch(getSiteUrl(site), {
            headers: { 'X-Export-Cursor': 'x'.repeat(8191) },
        });
        assert.equal(response.status, 431, 'Expected the test host to enforce its cursor limit');
    });

    it('keeps the next request below the cursor header limit', async () => {
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
        assert.equal(hookState?.forced_boundaries, 1, 'Expected one forced request boundary');
        assert.ok(hookState?.sql_requests >= 2, 'Expected the SQL download to resume');
        assert.ok(
            hookState?.boundary_cursor_header_bytes < 8191,
            `Expected a cursor header below 8191 bytes, got ${hookState?.boundary_cursor_header_bytes}`,
        );

        const sourceRows = await readRows(getDbName(site));
        const importedRows = await readRows(importDb);
        assert.deepEqual(importedRows, sourceRows, 'The large row or a neighboring row changed');
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }
});

async function readRows(database) {
    const connection = await createMysqlConnection(database);
    try {
        const [rows] = await connection.query(
            'SELECT id, payload FROM `aa_cursor_header_limit` ORDER BY id'
        );
        return rows;
    } finally {
        await connection.end();
    }
}
