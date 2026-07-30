/**
 * Test 18: Full Import Round-Trip via import.php
 * End-to-end test that exports a site (files + SQL), imports the SQL into
 * a fresh database, and verifies data integrity by comparing the original
 * and imported databases.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertSiteMirror, createMysqlConnection,
    compareDatabases, getDbName,
    fsRootDir,
    getPullStatePath,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Full Round-Trip', () => {
    const site = 'basic';
    const importDbName = 'e2e_basic_import_render';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-render');

        // Drop import DB if it exists from a previous run
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDbName}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        // Clean up import DB
        try {
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDbName}\``);
            await conn.end();
        } catch (e) {
            // Ignore cleanup errors
        }
    });

    it('files-sync completes', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const stateFile = getPullStatePath(tempDir);
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });

    it('db-sync completes', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'db-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
    });

    it('imported files form a valid WordPress site', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertSiteMirror(importedRoot);
    });

    it('imported files match source site', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('SQL dump loads into a fresh database', async () => {
        const conn = await createMysqlConnection();
        await conn.query(`CREATE DATABASE \`${importDbName}\``);
        await conn.end();

        const sqlFile = join(tempDir, 'db.sql');
        // Use mysql CLI to load the dump — handles all SQL edge cases correctly
        execSync(
            `mysql -u e2e_admin -pe2e_password -h 127.0.0.1 ${importDbName} < ${JSON.stringify(sqlFile)}`,
            { timeout: 30000 }
        );
    });

    it('imported database matches source database', async () => {
        const sourceDb = getDbName(site);
        const comparison = await compareDatabases(sourceDb, importDbName);

        assert.ok(
            comparison.match,
            `Database mismatch:\n` +
            `  missing tables: ${comparison.missingTables.join(', ')}\n` +
            `  extra tables: ${comparison.extraTables.join(', ')}\n` +
            `  row count mismatches: ${JSON.stringify(
                Object.entries(comparison.rowCounts).filter(([_, v]) => !v.match)
            )}`
        );
    });

    it('imported database contains the correct blogname', async () => {
        const importConn = await createMysqlConnection(importDbName);
        const [[row]] = await importConn.query(
            "SELECT option_value FROM wp_options WHERE option_name = 'blogname'"
        );
        await importConn.end();

        assert.ok(row, 'Expected blogname option in imported database');
        assert.equal(row.option_value, 'E2E: basic', 'Expected blogname to be E2E: basic');
    });
});
