/**
 * Test 13: SHA1 Integrity via client.php
 * Tests that file hashes match after sync, including large binary files.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, assertTreesMatch, sha1File,
    assertRemoteIndexEntryCount, assertSiteMirror,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SHA1 Integrity', () => {
    const site = 'sha1-verify';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                const dataDir = join(siteDir, 'test-data');
                mkdirSync(join(dataDir, 'deep', 'nested', 'path'), { recursive: true });
                for (let i = 1; i <= 20; i++) {
                    writeFileSync(join(dataDir, `file-${i}.txt`), `File content number ${i} with some padding to make it non-trivial\n`);
                }
                writeFileSync(join(dataDir, 'large-binary.bin'), randomBytes(256 * 1024));
                writeFileSync(join(dataDir, 'deep', 'nested', 'path', 'deep-file.txt'), 'Deep nested content\n');
            },
        });
        tempDir = createTempDir('e2e-import-sha1');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
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

    it('all file hashes match source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('accounted for at least 3000 remote index entries', () => {
        assertRemoteIndexEntryCount(tempDir, importUrl());
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('at least 20 files with correct hashes', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);
        assert.ok(importedHashes.size >= 20, `Expected at least 20 files, got ${importedHashes.size}`);
    });

    it('large binary file (256KB) hash matches', () => {
        const sourcePath = join(getSiteDir(site), 'test-data', 'large-binary.bin');
        const importedPath = join(fsRootDir(tempDir), getSiteDir(site), 'test-data', 'large-binary.bin');

        assert.ok(existsSync(sourcePath), 'Expected source large-binary.bin to exist');
        assert.ok(existsSync(importedPath), 'Expected imported large-binary.bin to exist');

        const sourceHash = sha1File(sourcePath);
        const importedHash = sha1File(importedPath);
        assert.equal(sourceHash, importedHash, 'Large binary file hash mismatch');
    });
});
