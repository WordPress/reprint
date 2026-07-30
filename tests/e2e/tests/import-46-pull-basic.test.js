/**
 * Test 46: Pull — Happy Path
 *
 * Runs `reprint pull` end-to-end with database import and --runtime=none
 * (no server start). Verifies that a single pull command downloads files, pulls the SQL dump, applies
 * it to a local MySQL database, and that a second pull performs a
 * delta sync without errors.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, assertSiteMirror,
    fsRootDir, assertPullPipelineComplete, compareDatabases, createMysqlConnection, getDbName,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull Basic', { timeout: 180000 }, () => {
    const site = 'basic';
    const importDb = 'e2e_pull_basic_46';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-basic');
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

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    const pullArgs = () => [
        `--target-user=e2e_admin`,
        `--target-pass=e2e_password`,
        `--target-db=${importDb}`,
        `--new-site-url=http://localhost:9999`,
        '--runtime=none',
    ];

    it('pull completes successfully', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows pull complete', () => {
        const stateFile = join(tempDir, '.import-state.json');
        assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assertPullPipelineComplete(state);
    });

    it('files match source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('imported files form valid WordPress structure', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('database matches source', async () => {
        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });

    it('re-running pull performs delta sync', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0 on re-pull, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assertPullPipelineComplete(state);
    });
});
