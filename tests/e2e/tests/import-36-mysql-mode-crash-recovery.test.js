/**
 * Test 36: MySQL Mode Source Interruption
 *
 * When using --sql-output=mysql with short execution times, the server
 * may pause mid-query (x-query-complete: 0). The importer buffers the
 * partial SQL in memory while it requests the next response in the same
 * process. A different importer process cannot align that buffer and cursor
 * with the target database.
 *
 * This test forces many source-response cycles with --max-exec=1 and verifies
 * same-process completion.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: MySQL Mode Source Interruption', { timeout: 120000 }, () => {
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

    describe('same-process source retries with short --max-exec', () => {
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

        it('completes and matches the source database', async () => {
            // Use --max-exec=1 to force the server to pause frequently,
            // creating many source responses within one importer process.
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

        it('no db.sql on disk', () => {
            assert.ok(!existsSync(join(tempDir, 'db.sql')),
                'Expected no db.sql file when using --sql-output=mysql');
        });
    });

});
