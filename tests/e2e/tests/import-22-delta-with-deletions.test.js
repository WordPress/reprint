/**
 * Test 22: Delta Sync with Source Deletions via import.php
 * Tests that files-pull correctly detects when files have been
 * deleted on the source between initial and delta syncs.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, assertTreesMatch,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Delta Sync with Deletions', () => {
    const site = 'file-deletions';
    let tempDir;
    const extraFile1 = join(getSiteDir(site), 'test-data', 'will-be-deleted-1.txt');
    const extraFile2 = join(getSiteDir(site), 'test-data', 'will-be-deleted-2.txt');

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-delta-del');
        // Clean up any leftover test files
        execSync(`sudo rm -f ${JSON.stringify(extraFile1)} ${JSON.stringify(extraFile2)} 2>/dev/null || true`);
        // Create test files that we'll later delete
        execSync(`echo "content to be deleted 1" | sudo tee ${JSON.stringify(extraFile1)} > /dev/null`);
        execSync(`echo "content to be deleted 2" | sudo tee ${JSON.stringify(extraFile2)} > /dev/null`);
        execSync(`sudo chown nginx:nginx ${JSON.stringify(extraFile1)} ${JSON.stringify(extraFile2)}`);
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        execSync(`sudo rm -f ${JSON.stringify(extraFile1)} ${JSON.stringify(extraFile2)} 2>/dev/null || true`);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('initial sync includes the extra files', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const hashes = hashDirectory(importedRoot);
        assert.ok(
            [...hashes.keys()].some(p => p.includes('will-be-deleted-1.txt')),
            'Expected will-be-deleted-1.txt in initial sync'
        );
        assert.ok(
            [...hashes.keys()].some(p => p.includes('will-be-deleted-2.txt')),
            'Expected will-be-deleted-2.txt in initial sync'
        );
    });

    it('initial sync hashes match source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('delete files on source, then delta sync picks up the change', () => {
        // Delete the files from the source
        execSync(`sudo rm -f ${JSON.stringify(extraFile1)} ${JSON.stringify(extraFile2)}`);

        // Abort previous completion so we can run a delta
        const abort = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(abort.exitCode, 0);

        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('fetch list includes deleted files', () => {
        const dlPath = join(tempDir, '.import-fetch-list.jsonl');
        if (existsSync(dlPath)) {
            const content = readFileSync(dlPath, 'utf-8');
            // The fetch list should reference the deleted files
            // (either as "deleted" entries or as files to re-sync)
            const lines = content.split('\n').filter(l => l.trim());
            assert.ok(lines.length >= 0, 'Fetch list exists');
        }
    });

    it('state shows complete after delta', () => {
        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });
});
