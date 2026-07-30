/**
 * Test 36: MySQL Mode Crash Recovery
 *
 * When using --sql-output=mysql with short execution times, the server
 * may pause mid-query (x-query-complete: 0). The importer buffers the
 * partial SQL in memory and persists it to .sql-buffer on disk as each
 * chunk arrives. If the process dies at any point, the next run reloads
 * whatever was accumulated.
 *
 * This test forces many resume cycles with --max-exec=1, verifies the
 * database is correct after completion, and confirms .sql-buffer is
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

        it('.sql-buffer is cleaned up after completion', () => {
            assert.ok(!existsSync(join(tempDir, '.sql-buffer')),
                'Expected .sql-buffer to be cleaned up after successful completion');
        });

        it('no db.sql on disk', () => {
            assert.ok(!existsSync(join(tempDir, 'db.sql')),
                'Expected no db.sql file when using --sql-output=mysql');
        });
    });

    describe('pre-seeded .sql-buffer is loaded on resume', () => {
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

        it('loads .sql-buffer from disk and logs recovery', { timeout: 300000 }, () => {
            // Run preflight so db-pull can proceed
            runImporter(importUrl(), tempDir, 'preflight', {
                secret: getSiteSecret(site),
            });

            // Seed a .sql-buffer file before running db-pull.
            // The content is a harmless SQL comment that won't affect execution
            // — the point is to verify the importer reads it and logs recovery.
            const bufferFile = join(tempDir, '.sql-buffer');
            writeFileSync(bufferFile, '-- pre-seeded buffer\n');

            // Run a fresh db-pull — the importer should detect the buffer file
            const result = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArgs(importDb),
                skipPreflight: true,
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}`);

            // Verify recovery was logged
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('CRASH RECOVERY') && audit.includes('.sql-buffer'),
                'Expected audit log to mention .sql-buffer crash recovery');

            // Buffer should be cleaned up
            assert.ok(!existsSync(bufferFile),
                'Expected .sql-buffer to be removed after completion');
        });
    });
});
