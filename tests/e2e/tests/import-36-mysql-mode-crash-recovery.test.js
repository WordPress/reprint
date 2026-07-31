/**
 * Test 36: MySQL Mode Crash Recovery
 *
 * When using --sql-output=mysql with short execution times, the server
 * may pause mid-query (x-query-complete: 0). The importer buffers the
 * partial SQL in memory and persists it to pull/sql-buffer on disk as each
 * chunk arrives. A resumed SQL request reloads its persisted buffer. A fresh
 * download removes a stale buffer.
 *
 * This test forces many resume cycles with --max-exec=1, verifies the
 * database is correct after completion, and confirms pull/sql-buffer is
 * cleaned up.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection,
    readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: MySQL Mode Crash Recovery', { timeout: 120000 }, () => {
    const site = 'basic';

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
    ];

    describe('resume with short --max-exec completes correctly', () => {
        let tempDir;
        const importDb = 'e2e_basic_import_36_resume';

        beforeAll(async () => {
            tempDir = createTempDir('e2e-mysql-crash-recovery');
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

        it('completes via multiple resume cycles and database matches source', async () => {
            // Use --max-exec=1 to force the server to pause frequently,
            // creating many resume cycles. auto-resume handles exit code 2.
            const result = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: [...mysqlArgs(importDb), '--max-exec=1'],
                maxResumeAttempts: 200,
                wallTimeout: 90000,
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}`);

            const comparison = await compareDatabases(getDbName(site), importDb);
            assert.ok(comparison.match,
                `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
                `counts=${JSON.stringify(comparison.rowCounts)}`);
        });

        it('pull/sql-buffer is cleaned up after completion', () => {
            assert.ok(!existsSync(join(tempDir, 'pull/sql-buffer')),
                'Expected pull/sql-buffer to be cleaned up after successful completion');
        });

        it('no db.sql on disk', () => {
            assert.ok(!existsSync(join(tempDir, 'db.sql')),
                'Expected no db.sql file when using --sql-output=mysql');
        });
    });

    describe('pre-seeded pull/sql-buffer is removed on a fresh run', () => {
        let tempDir;
        const importDb = 'e2e_basic_import_36_seeded';

        beforeAll(async () => {
            tempDir = createTempDir('e2e-mysql-seeded-buffer');
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

        it('does not execute SQL from a stale pull/sql-buffer', { timeout: 300000 }, () => {
            // Run preflight so db-pull can proceed
            runImporter(importUrl(), tempDir, 'preflight', {
                secret: getSiteSecret(site),
            });

            // Seed invalid SQL before a fresh db-pull. Restoring this buffer
            // would make the first query fail.
            const bufferFile = join(tempDir, 'pull/sql-buffer');
            writeFileSync(bufferFile, 'THIS IS NOT SQL;\n');

            const result = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArgs(importDb),
                skipPreflight: true,
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}`);

            const audit = readAuditLog(tempDir);
            assert.ok(!audit.includes('from pull/sql-buffer'),
                'Expected a fresh db-pull not to restore pull/sql-buffer');

            // Buffer should be cleaned up
            assert.ok(!existsSync(bufferFile),
                'Expected pull/sql-buffer to be removed after completion');
        });
    });
});
