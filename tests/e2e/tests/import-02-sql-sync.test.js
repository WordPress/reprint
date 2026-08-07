/**
 * Test 02: SQL Sync via client.php
 * Tests db-pull and db-index commands produce correct output.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SQL Sync', () => {
    const site = 'basic';
    let tempDir;
    const importDb = 'e2e_basic_import_02';

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-sql');
        // Ensure import DB doesn't exist
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

    it('db-pull completes and produces db.sql', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        assert.ok(sql.includes('INSERT INTO'), 'Expected INSERT INTO in db.sql');
    });

    it('imported database matches source', async () => {
        const conn = await createMysqlConnection();
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        const sqlFile = join(tempDir, 'db.sql');
        execSync(`mysql -u e2e_admin -pe2e_password -h 127.0.0.1 ${importDb} < ${JSON.stringify(sqlFile)}`, {
            timeout: 30000,
            stdio: 'pipe',
        });

        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });

    it('db-index produces db-tables.jsonl with table names', () => {
        const pfDir = createTempDir('e2e-import-sqlpf');
        try {
            const result = runImporter(importUrl(), pfDir, 'db-index', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}`);

            const tablesFile = join(pfDir, 'db-tables.jsonl');
            assert.ok(existsSync(tablesFile), 'Expected db-tables.jsonl to exist');

            const lines = readFileSync(tablesFile, 'utf-8').trim().split('\n').filter(l => l);
            assert.ok(lines.length > 0, 'Expected at least one table entry');

            const tables = lines.map(l => JSON.parse(l));
            const tableNames = tables.map(t => t.table || t.name || t.TABLE_NAME || Object.values(t)[0]);
            assert.ok(tableNames.some(n => typeof n === 'string' && n.includes('wp_')),
                `Expected wp_ prefixed table names, got: ${JSON.stringify(tableNames.slice(0, 3))}`);
        } finally {
            cleanupTempDir(pfDir);
        }
    });

    it('re-running db-pull without --abort fails with useful message', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code');
        const output = result.stdout + result.stderr;
        assert.ok(output.includes('--abort'), `Expected message mentioning --abort, got: ${output}`);
    });
});
