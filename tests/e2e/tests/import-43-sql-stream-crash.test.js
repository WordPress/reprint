/**
 * Test 43: SQL Stream Crash Recovery (mysql mode)
 *
 * Simulates a PHP crash (exit(1)) mid-SQL-stream using a test hook on
 * test_hook_before_sql_batch. When PHP dies mid-stream, the multipart
 * response is truncated and no completion chunk is sent. Before this
 * fix, the importer would throw "Buffered SQL was never executed" and
 * fail fatally. Now it treats the missing completion chunk as an
 * interrupted response, saves partial state, and resumes on the next run.
 *
 * Note: The .sql-buffer file only contains data when the crash
 * interrupts a partial SQL statement (mid-chunk). With exit(1), PHP
 * dies before writing the next batch, so all previously received SQL
 * was already executed and the buffer is typically empty. The key
 * assertion is that the importer exits partial (code 2) and resumes
 * successfully — not that the buffer file has data.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
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

describe('Import: SQL Stream Crash Recovery', { timeout: 120000 }, () => {
    const site = 'sql-crash';

    beforeAll(async () => {
        await ensureSite(site);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    const mysqlArgs = (db) => [
        '--sql-output=mysql',
        `--mysql-database=${db}`,
        '--mysql-host=127.0.0.1',
        '--mysql-user=e2e_admin',
        '--mysql-password=e2e_password',
        // Force small batches so the standard WP install produces 3+
        // SQL batches. The default (1000 fragments/batch) fits everything
        // in one batch, so the crash hook on batch 3 would never fire.
        '--sql-fragments-start=5',
        '--sql-fragments-max=5',
        '--sql-fragments-min=5',
    ];

    describe('PHP crash mid-SQL-stream recovers via resume', () => {
        let tempDir;
        const importDb = 'e2e_sql_crash_import_43';

        beforeAll(async () => {
            tempDir = createTempDir('e2e-sql-stream-crash');
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.query(`CREATE DATABASE \`${importDb}\``);
            await conn.end();

            clearHookState(site);

            // Deploy a hook that kills PHP after 3 SQL batches. This
            // simulates a real PHP crash (max_execution_time, OOM, fatal
            // error) — the response is truncated mid-gzip and no completion
            // chunk is sent.
            writeTestHooks(site, [
                'function test_hook_before_sql_batch(&$sql, $cursor) {',
                `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
                '    $state = file_exists($state_file)',
                '        ? json_decode(file_get_contents($state_file), true)',
                '        : [];',
                '    $count = ($state[\'batch_count\'] ?? 0) + 1;',
                '    $state[\'batch_count\'] = $count;',
                '    file_put_contents($state_file, json_encode($state));',
                '',
                '    // Kill PHP on the 3rd SQL batch to simulate a crash.',
                '    // Some data has already been flushed to the client,',
                '    // so the importer will have partial SQL in its buffer.',
                '    if ($count === 3) {',
                '        exit(1);',
                '    }',
                '}',
            ].join('\n'));
            writeHookState(site, { batch_count: 0 });
        });

        afterAll(async () => {
            removeTestHooks(site);
            clearHookState(site);
            cleanupTempDir(tempDir);
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.end();
        });

        it('first run exits partial (not fatal) after crash', () => {
            // Disable auto-resume so we can inspect the first-run behavior.
            // The importer should NOT throw "Buffered SQL was never executed".
            // Instead it should save state and exit with code 2 (partial).
            const result = runImporter(importUrl(), tempDir, 'db-sync', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArgs(importDb),
                autoResume: false,
            });

            // Exit code 2 = partial (retryable). Exit code 1 = fatal error.
            // Before the fix, this would be 1 with "Buffered SQL was never executed".
            assert.equal(result.exitCode, 2,
                `Expected exit code 2 (partial), got ${result.exitCode}\n` +
                `stdout: ${result.stdout}\nstderr: ${result.stderr}`);

            // Verify the hook actually fired
            const state = readHookState(site);
            assert.ok(state, 'Hook state file should exist');
            assert.ok(state.batch_count >= 3,
                `Expected batch_count >= 3, got ${state.batch_count}`);
        });

        it('state was saved for resume', () => {
            // The importer should have persisted its state so the next
            // run can resume. The cursor may be null if nginx buffered
            // the entire response and no multipart chunks reached the
            // client before the crash — in that case resume starts from
            // scratch, which is correct.
            const stateFile = join(tempDir, '.import-state.json');
            assert.ok(existsSync(stateFile), 'Expected state file to exist');
            const state = JSON.parse(readFileSync(stateFile, 'utf8'));
            assert.equal(state.active_resumable_command.completion_state, 'partial',
                `Expected status=partial, got ${state.active_resumable_command.completion_state}`);
        });

        it('audit log records the interrupted response', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(
                audit.includes('INTERRUPTED RESPONSE') ||
                audit.includes('BUFFER PRESERVED') ||
                audit.includes('BUFFER NOT FLUSHED') ||
                audit.includes('missing completion chunk'),
                'Expected audit log to record the interrupted response'
            );
        });

        it('resume completes after removing crash hook', { timeout: 300000 }, async () => {
            // Remove the crashing hook so subsequent requests succeed.
            removeTestHooks(site);

            const result = runImporter(importUrl(), tempDir, 'db-sync', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArgs(importDb),
                maxResumeAttempts: 50,
                wallTimeout: 270000,
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0 on resume, got ${result.exitCode}\n` +
                `stderr: ${result.stderr}`);
        });

        it('database matches source after recovery', async () => {
            const comparison = await compareDatabases(getDbName(site), importDb);
            assert.ok(comparison.match,
                `Database mismatch after crash recovery: ` +
                `missing=${JSON.stringify(comparison.missingTables)}, ` +
                `counts=${JSON.stringify(comparison.rowCounts)}`);
        });

        it('.sql-buffer is cleaned up after completion', () => {
            assert.ok(!existsSync(join(tempDir, '.sql-buffer')),
                'Expected .sql-buffer to be cleaned up after successful completion');
        });

        it('audit log shows successful completion after crash', () => {
            const audit = readAuditLog(tempDir);
            // The CRASH RECOVERY entry only appears when .sql-buffer had
            // data to reload. With exit(1) between batches, the buffer is
            // typically empty. Either way, the INTERRUPTED RESPONSE entry
            // from the first run proves the crash was detected.
            assert.ok(
                audit.includes('INTERRUPTED RESPONSE') ||
                audit.includes('CRASH RECOVERY'),
                'Expected audit log to mention crash detection or recovery'
            );
        });
    });
});
