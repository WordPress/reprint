/**
 * Test 09: SQL Resume via import.php
 * Tests that db-pull resumes correctly when using short max_execution_time.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Resume SQL', { timeout: 120000 }, () => {
    const site = 'basic';
    let tempDir;
    const importDb = 'e2e_basic_import_09';

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-resume-sql');
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('db-pull completes via multiple resumable requests', () => {
        // Use --max-exec=1 to force short server execution times.
        // The SQL dump spans multiple requests, each resuming from cursor.
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--max-exec=1'],
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const stateFile = join(tempDir, 'pull/state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete', 'Expected status to be complete');
    });

    it('db.sql is valid', () => {
        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        assert.ok(sql.includes('INSERT INTO'), 'Expected INSERT INTO in db.sql');
    });

    it('imported database matches source', async () => {
        const sqlFile = join(tempDir, 'db.sql');
        const conn = await createMysqlConnection();
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        execSync(`mysql -u e2e_admin -pe2e_password -h 127.0.0.1 ${importDb} < ${JSON.stringify(sqlFile)}`, {
            timeout: 30000,
            stdio: 'pipe',
        });

        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });
});
