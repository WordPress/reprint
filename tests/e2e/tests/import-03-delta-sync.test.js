/**
 * Test 03: Delta File Sync via import.php
 * Tests files-pull after initial sync, with and without changes.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertRemoteIndexEntryCount, assertSiteMirror,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Delta Sync', () => {
    const site = 'file-changes';
    let tempDir;
    const addedFile = join(getSiteDir(site), 'test-data', 'delta-test-added.txt');

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-delta');
        // Clean up any leftover test file
        try { execSync(`sudo rm -f ${JSON.stringify(addedFile)}`); } catch {}
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        try { execSync(`sudo rm -f ${JSON.stringify(addedFile)}`); } catch {}
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-pull completes', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('accounted for at least 3000 remote index entries', () => {
        assertRemoteIndexEntryCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('abort + re-sync with no changes completes (delta)', () => {
        // Abort so we can re-run
        const abort = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(abort.exitCode, 0);

        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        // Hashes should still match
        assertTreesMatch(getSiteDir(site), join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('files-pull picks up new file via delta', () => {
        // Abort previous completion so we can run a delta
        const abort = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(abort.exitCode, 0);

        // Add a file on the source
        execSync(`echo "delta test content" | sudo tee ${JSON.stringify(addedFile)} > /dev/null`);
        execSync(`sudo chown nginx:nginx ${JSON.stringify(addedFile)}`);

        // Run files-pull — delta detects and downloads the new file
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        // The new file should appear in the output
        const importedPath = join(fsRootDir(tempDir), getSiteDir(site), 'test-data', 'delta-test-added.txt');
        assert.ok(existsSync(importedPath), 'Expected delta-test-added.txt in output');

        // Clean up added file
        execSync(`sudo rm -f ${JSON.stringify(addedFile)}`);
    });

    it('files-pull on fresh dir without preflight fails with useful error', () => {
        const freshDir = createTempDir('e2e-import-delta-fresh');
        try {
            const result = runImporter(importUrl(), freshDir, 'files-pull', {
                secret: getSiteSecret(site),
                skipPreflight: true,
            });
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code');
            const output = result.stdout + result.stderr;
            assert.ok(
                output.includes('preflight'),
                `Expected message about needing preflight, got: ${output}`
            );
        } finally {
            cleanupTempDir(freshDir);
        }
    });
});
