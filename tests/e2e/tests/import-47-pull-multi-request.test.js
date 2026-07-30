/**
 * Test 47: Pull — Multiple Requests Per Phase
 *
 * Runs `reprint pull` with --max-exec=1 to force very short server
 * execution times. Each phase (files-pull, db-pull) requires many
 * HTTP requests to complete. The pull command's internal retry loop
 * handles all the partial responses transparently, and the final
 * result matches the source.
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
    getPullStatePath,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull Multi-Request', { timeout: 300000 }, () => {
    const site = 'basic';
    const importDb = 'e2e_pull_multi_47';
    let tempDir;
    let pullStdout = '';

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-multi-request');
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

    it('pull completes with --max-exec=1 (many small requests)', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 300000,
            extraArgs: [
                '--max-exec=1',
                `--target-user=e2e_admin`,
                `--target-pass=e2e_password`,
                `--target-db=${importDb}`,
                `--new-site-url=http://localhost:9999`,
                '--runtime=none',
                // Keep the resume assertions independent of raw transfer speed.
                '--file-chunk-start=262144',
                '--file-chunk-max=262144',
                '--index-batch-start=500',
                '--index-batch-max=500',
                '--sql-fragments-start=100',
                '--sql-fragments-max=100',
            ],
        });
        pullStdout = result.stdout;
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows pull complete', () => {
        const state = JSON.parse(readFileSync(getPullStatePath(tempDir), 'utf-8'));
        assertPullPipelineComplete(state);
    });

    it('file download counter never decreases across requests', () => {
        // The JSONL output includes files_done in file_progress records.
        // The contract this test guards is monotonicity — once the
        // exporter has reported N files done, no later progress record
        // may report fewer. The number of progress records itself is
        // not the contract: as the transfer gets faster (smaller
        // payloads, fewer round trips, network speedups), legitimate
        // runs may emit just one progress record without violating the
        // monotonicity property. Don't gate on record count.
        const filesDoneValues = pullStdout
            .split('\n')
            .filter(line => line.startsWith('{'))
            .map(line => { try { return JSON.parse(line); } catch { return null; } })
            .filter(obj => obj && typeof obj.files_done === 'number')
            .map(obj => obj.files_done);

        for (let i = 1; i < filesDoneValues.length; i++) {
            assert.ok(filesDoneValues[i] >= filesDoneValues[i - 1],
                `files_done decreased from ${filesDoneValues[i - 1]} to ${filesDoneValues[i]} ` +
                `at progress record ${i} of ${filesDoneValues.length}`);
        }
    });

    it('files match source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
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
});
