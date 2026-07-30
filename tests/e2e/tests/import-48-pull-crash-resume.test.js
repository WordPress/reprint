/**
 * Test 48: Pull — Abort and Resume
 *
 * Tests pull's pipeline state tracking and resumption behavior:
 *
 * 1. Run pull to completion (all stages: preflight → files → db → apply)
 * 2. Abort the completed pull (--abort resets pipeline state)
 * 3. Re-run pull → performs delta sync (re-index, diff, re-download db)
 * 4. Verify the re-pull completes and data matches
 *
 * The internal crash-retry mechanism (server crashes during SQL
 * streaming) is tested by import-43. This test focuses on the
 * higher-level pull pipeline state machine: does pull correctly skip
 * completed stages and pick up where it left off?
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, assertSiteMirror,
    fsRootDir, assertPullPipelineComplete,
    compareDatabases, createMysqlConnection, getDbName,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull Abort and Resume', { timeout: 300000 }, () => {
    const site = 'basic';
    const importDb = 'e2e_pull_resume_48';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-resume');
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

    it('initial pull completes', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assertPullPipelineComplete(state);
    });

    it('--abort clears pull pipeline state', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            extraArgs: ['--abort'],
        });
        assert.equal(result.exitCode, 0,
            `Expected abort exit 0, got ${result.exitCode}`);

        // The completed-stage marker should be cleared.
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull_pipeline.last_completed_stage, null,
            'Expected pull_pipeline.last_completed_stage to be null after abort');

        // Local index should be preserved (for delta sync)
        assert.ok(existsSync(join(tempDir, '.import-index.jsonl')),
            'Expected local index to be preserved after abort');
    });

    it('re-pull after abort performs delta sync', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected re-pull exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assertPullPipelineComplete(state);
    });

    it('files still match source after re-pull', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('imported files form valid WordPress structure', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('database matches source after re-pull', async () => {
        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch after re-pull: ` +
            `missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });

    it('second re-pull without abort also works (auto delta)', () => {
        // Running pull again after it completed all recorded stages should
        // auto-reset and perform another delta sync.
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected auto re-pull exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assertPullPipelineComplete(state);
    });
});
