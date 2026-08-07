/**
 * Test 10: File Resume via client.php
 * Tests that files-pull can resume after a partial transfer.
 * Uses large-directory site (5000+ files) with --max-exec=10 to force short requests.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertRemoteIndexEntryCount, assertSiteMirror,
    fsRootDir, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Resume Files', { timeout: 180000 }, () => {
    const site = 'large-directory';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                const manyDir = join(siteDir, 'test-data', 'many-files');
                mkdirSync(manyDir, { recursive: true });
                for (let i = 1; i <= 2000; i++) {
                    const num = String(i).padStart(4, '0');
                    writeFileSync(join(manyDir, `file-${num}.txt`), `content-${num}`);
                }
            },
        });
        tempDir = createTempDir('e2e-import-resume-files');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-pull completes via multiple resumable requests', () => {
        // Use --max-exec=3 to force short server execution times,
        // which means each request only transfers a subset of files before returning partial.
        // The importer automatically resumes from the cursor.
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            timeout: 180000,
            extraArgs: ['--max-exec=10'],
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows complete', () => {
        const stateFile = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
        assert.ok(existsSync(stateFile), 'Expected state file to exist');

        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete', 'Expected status to be complete');
    });

    it('accounted for at least 3000 remote index entries', () => {
        assertRemoteIndexEntryCount(tempDir, importUrl());
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('all files present and correct after multi-request sync', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });
});
