/**
 * Test 43: SQL Stream Crash Recovery
 *
 * Simulates a PHP crash (exit(1)) after emitting a complete multipart
 * part which ends midway through an INSERT, but before emitting the next
 * part. Every SQL output mode must resume the REST stream from that part's
 * cursor without repeating or skipping its SQL.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection,
    readAuditLog,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SQL Stream Crash Recovery', { timeout: 300000 }, () => {
    const site = 'sql-crash';
    const payloadTable = 'wp_reprint_crash_payload';
    let sourcePayloads;

    beforeAll(async () => {
        await ensureSite(site);
        const conn = await createMysqlConnection();
        await conn.query(
            `DROP TABLE IF EXISTS \`${getDbName(site)}\`.\`${payloadTable}\``
        );
        // An incompressible row forces the incomplete multipart part
        // through HTTP buffering before the exporter exits.
        await conn.query(
            `CREATE TABLE \`${getDbName(site)}\`.\`${payloadTable}\` (` +
            '`id` bigint unsigned NOT NULL, ' +
            '`payload` longblob NOT NULL, ' +
            'PRIMARY KEY (`id`))'
        );
        sourcePayloads = [randomBytes(512 * 1024), randomBytes(512 * 1024)];
        await conn.query(
            `INSERT INTO \`${getDbName(site)}\`.\`${payloadTable}\` ` +
            '(`id`, `payload`) VALUES (?, ?), (?, ?)',
            [1, sourcePayloads[0], 2, sourcePayloads[1]]
        );
        await conn.end();

        // Let one incomplete INSERT part reach the client, then kill PHP
        // before the following part is emitted.
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = file_exists($state_file)',
            '        ? json_decode(file_get_contents($state_file), true)',
            '        : [];',
            '',
            '    if (!empty($state[\'crash_before_batch\'])) {',
            '        $state[\'crash_before_batch\'] = false;',
            '        $state[\'crashed\'] = true;',
            '        file_put_contents($state_file, json_encode($state));',
            '        exit(1);',
            '    }',
            '',
            '    $trimmed = rtrim($sql);',
            '    $large_incomplete_part = strlen($sql) > 512 * 1024',
            '        && $trimmed !== \'\'',
            '        && substr($trimmed, -1) === \',\';',
            '    if (empty($state[\'crashed\']) && $large_incomplete_part) {',
            '        $state[\'incomplete_batch_emitted\'] = true;',
            '        $state[\'crash_before_batch\'] = true;',
            '    }',
            '    file_put_contents($state_file, json_encode($state));',
            '}',
        ].join('\n'));
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        const conn = await createMysqlConnection();
        await conn.query(
            `DROP TABLE IF EXISTS \`${getDbName(site)}\`.\`${payloadTable}\``
        );
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function sqlOutputArgs(mode, db) {
        const args = [
            `--sql-output=${mode}`,
            // Emit one producer fragment per multipart part so the hook can
            // stop immediately after a partial INSERT statement.
            '--sql-fragments-start=1',
            '--sql-fragments-max=1',
            '--sql-fragments-min=1',
        ];
        if (mode === 'mysql') {
            args.push(
                `--mysql-database=${db}`,
                '--mysql-host=127.0.0.1',
                '--mysql-user=e2e_admin',
                '--mysql-password=e2e_password',
            );
        }
        return args;
    }

    for (const mode of ['file', 'stdout', 'mysql']) {
        it(`resumes the REST stream in ${mode} mode without skipping an INSERT row`, async () => {
            const tempDir = createTempDir(`e2e-sql-stream-crash-${mode}`);
            const importDb = `e2e_sql_crash_import_43_${mode}`;

            clearHookState(site);
            writeHookState(site, {});

            if (mode === 'mysql') {
                const conn = await createMysqlConnection();
                await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
                await conn.query(`CREATE DATABASE \`${importDb}\``);
                await conn.end();
            }

            try {
                // autoResume=false requires this one importer invocation to
                // recover instead of starting another process.
                const result = runImporter(importUrl(), tempDir, 'db-sync', {
                    secret: getSiteSecret(site),
                    extraArgs: sqlOutputArgs(mode, importDb),
                    autoResume: false,
                    timeout: 270000,
                });

                assert.equal(result.exitCode, 0,
                    `Expected ${mode} mode to complete in one invocation, got ${result.exitCode}\n` +
                    `stdout: ${result.stdout}\nstderr: ${result.stderr}`);

                const hookState = readHookState(site);
                assert.ok(hookState, 'Hook state file should exist');
                assert.equal(hookState.incomplete_batch_emitted, true,
                    'Expected an incomplete SQL part to be emitted before the crash');
                assert.equal(hookState.crashed, true,
                    'Expected the exporter to crash before the following SQL part');

                if (mode === 'mysql') {
                    const comparison = await compareDatabases(getDbName(site), importDb);
                    assert.ok(comparison.match,
                        `Database mismatch after crash recovery: ` +
                        `missing=${JSON.stringify(comparison.missingTables)}, ` +
                        `counts=${JSON.stringify(comparison.rowCounts)}`);

                    const sourceConn = await createMysqlConnection(getDbName(site));
                    const importConn = await createMysqlConnection(importDb);
                    const rowQuery =
                        `SELECT id, OCTET_LENGTH(payload) AS payload_bytes, ` +
                        `SHA2(payload, 256) AS payload_sha256 ` +
                        `FROM \`${payloadTable}\` ORDER BY id`;
                    const [sourceRows] = await sourceConn.query(rowQuery);
                    const [importRows] = await importConn.query(rowQuery);
                    await sourceConn.end();
                    await importConn.end();
                    assert.deepEqual(importRows, sourceRows,
                        'Expected every split INSERT row and payload to reach MySQL');
                } else {
                    const sql = mode === 'file'
                        ? readFileSync(join(tempDir, 'db.sql'), 'utf8')
                        : result.stdout;
                    for (const [index, payload] of sourcePayloads.entries()) {
                        const encodedPayload = payload.toString('base64');
                        const occurrences = sql.split(encodedPayload).length - 1;
                        assert.equal(
                            occurrences,
                            1,
                            `Expected payload ${index + 1} exactly once in ${mode} output`,
                        );
                    }
                }

                const stateFile = join(tempDir, '.import-state.json');
                assert.ok(existsSync(stateFile), 'Expected state file to exist');
                const state = JSON.parse(readFileSync(stateFile, 'utf8'));
                assert.equal(state.active_resumable_command.completion_state, 'complete',
                    `Expected status=complete, got ${state.active_resumable_command.completion_state}`);

                const audit = readAuditLog(tempDir);
                assert.match(
                    audit,
                    /SQL RETRY \| resuming source request/,
                    `Expected ${mode} mode to retry the interrupted REST request`,
                );

                assert.ok(!existsSync(join(tempDir, '.sql-buffer')),
                    'Expected .sql-buffer to be absent after successful completion');
            } finally {
                cleanupTempDir(tempDir);
                if (mode === 'mysql') {
                    const conn = await createMysqlConnection();
                    await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
                    await conn.end();
                }
            }
        });
    }
});
