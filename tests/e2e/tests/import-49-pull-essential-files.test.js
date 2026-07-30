/**
 * Test 49: Pull --filter=essential-files
 *
 * Verifies that `reprint pull` accepts `--filter=essential-files`,
 * completes the main pull pipeline, imports the database, and leaves
 * the skipped-file tail recorded in pull state for later retrieval.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir, assertPullPipelineComplete, compareDatabases, createMysqlConnection, getDbName,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const UPLOAD_FILES = [
    'wp-content/uploads/2024/01/photo.jpg',
    'wp-content/uploads/2024/01/banner.png',
    'wp-content/uploads/2024/01/document.pdf',
    'wp-content/uploads/2024/06/summer.jpg',
];

describe('Import: Pull essential-files', { timeout: 180000 }, () => {
    const site = 'defer-uploads';
    const importDb = 'e2e_pull_filter_49';
    let tempDir;
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                const uploadsDir = join(remoteSiteDir, 'wp-content', 'uploads', '2024', '01');
                mkdirSync(uploadsDir, { recursive: true });
                writeFileSync(join(uploadsDir, 'photo.jpg'), randomBytes(4096));
                writeFileSync(join(uploadsDir, 'banner.png'), randomBytes(2048));
                writeFileSync(join(uploadsDir, 'document.pdf'), randomBytes(1024));

                const uploadsDir2 = join(remoteSiteDir, 'wp-content', 'uploads', '2024', '06');
                mkdirSync(uploadsDir2, { recursive: true });
                writeFileSync(join(uploadsDir2, 'summer.jpg'), randomBytes(3072));
            },
        });
        siteDir = getSiteDir(site);
        tempDir = createTempDir('e2e-pull-filter');

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
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    it('pull completes with --filter=essential-files', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--filter=essential-files',
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${importDb}`,
                '--new-site-url=http://localhost:9999',
                '--runtime=none',
            ],
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state records deferred files in the pull metadata', () => {
        const stateFile = join(tempDir, '.reprint/pull/state.json');
        assert.ok(existsSync(stateFile), 'Expected .reprint/pull/state.json to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assertPullPipelineComplete(state);
        assert.equal(state.pull_pipeline.files_filter, 'essential-files');
        assert.equal(state.pull_pipeline.skipped_pending, true);
    });

    it('skipped fetch list remains on disk', () => {
        const skippedList = join(tempDir, '.reprint/pull/skipped-fetch-list.jsonl');
        assert.ok(existsSync(skippedList), 'Expected skipped fetch list to exist');
        assert.ok(readFileSync(skippedList, 'utf-8').trim().length > 0,
            'Expected skipped fetch list to be non-empty');
    });

    it('uploads were deferred from the fs-root', () => {
        const importedRoot = join(fsRootDir(tempDir), siteDir);
        for (const file of UPLOAD_FILES) {
            assert.ok(!existsSync(join(importedRoot, file)),
                `Expected deferred upload to be absent: ${file}`);
        }
    });

    it('database still matches the source site', async () => {
        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });
});
