/**
 * Test 35: SQL Output Modes
 * Tests --sql-output=stdout and --sql-output=mysql for db-pull.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection,
    pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SQL Output Modes', () => {
    const site = 'basic';

    beforeAll(async () => {
        await ensureSite(site);
    });

    describe('--sql-output=stdout', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-sql-stdout');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('streams SQL to stdout and produces no db.sql', () => {
            // Run preflight first (required by all non-preflight commands)
            const pfResult = runImporter(
                `${getSiteUrl(site)}&directory=${getSiteDir(site)}`,
                tempDir, 'preflight', { secret: getSiteSecret(site) },
            );
            assert.equal(pfResult.exitCode, 0, `Preflight failed: ${pfResult.stderr}`);

            // Run db-pull in stdout mode. We can't use runImporter's auto-resume
            // because stdout output is accumulated — instead we run php directly
            // and let the importer's own resume mechanism (exit code 2) handle
            // partial runs. The runImporter helper already handles this.
            const result = runImporter(
                `${getSiteUrl(site)}&directory=${getSiteDir(site)}`,
                tempDir, 'db-pull', {
                    secret: getSiteSecret(site),
                    extraArgs: ['--sql-output=stdout'],
                    skipPreflight: true,
                },
            );
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout (first 500): ${result.stdout.slice(0, 500)}`);

            // stdout should contain SQL statements
            assert.ok(result.stdout.includes('CREATE TABLE'),
                'Expected CREATE TABLE in stdout');
            assert.ok(result.stdout.includes('INSERT INTO'),
                'Expected INSERT INTO in stdout');

            // No db.sql should be on disk
            assert.ok(!existsSync(join(tempDir, 'db.sql')),
                'Expected no db.sql file when using --sql-output=stdout');
        });
    });

    describe('--sql-output=mysql', () => {
        let tempDir;
        const importDb = 'e2e_basic_import_35_mysql';

        beforeAll(async () => {
            tempDir = createTempDir('e2e-sql-mysql');
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.query(`CREATE DATABASE \`${importDb}\``);
            await conn.end();
        });

        afterAll(async () => {
            cleanupTempDir(tempDir);
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.end();
        });

        it('downloads SQL, applies it to MySQL, and matches source', async () => {
            const result = runImporter(
                `${getSiteUrl(site)}&directory=${getSiteDir(site)}`,
                tempDir, 'db-pull', {
                    secret: getSiteSecret(site),
                    extraArgs: [
                        '--sql-output=mysql',
                        `--mysql-database=${importDb}`,
                        '--mysql-host=127.0.0.1',
                        '--mysql-user=e2e_admin',
                        '--mysql-password=e2e_password',
                    ],
                },
            );
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            assert.ok(existsSync(join(tempDir, 'db.sql')),
                'Expected the confirmed db.sql used by --sql-output=mysql');

            // Compare imported database against source
            const comparison = await compareDatabases(getDbName(site), importDb);
            assert.ok(comparison.match,
                `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
                `counts=${JSON.stringify(comparison.rowCounts)}`);
        });

        it('state records the completed file and apply stages', () => {
            const stateFile = join(
                pullStateDirectory(
                    tempDir,
                    `${getSiteUrl(site)}&directory=${getSiteDir(site)}`,
                ),
                'state.json',
            );
            assert.ok(existsSync(stateFile), 'Expected state file to exist');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.sql_output, 'file',
                `Expected sql_output=file in state, got ${state.sql_output}`);
            assert.equal(state.pull_pipeline.started_by_command, 'pull-db');
            assert.equal(state.pull_pipeline.last_completed_stage, 'db-apply');
            assert.equal(state.active_resumable_command.command_name, 'db-apply');
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });
    });
});
