/**
 * Test 08: Large Directory via import.php
 * Tests files-pull with 2000+ files.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, assertTreesMatch,
    assertFileCount, assertSiteMirror,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Large Directory', () => {
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
        tempDir = createTempDir('e2e-import-large');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-pull completes', { timeout: 120000 }, () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            timeout: 120000,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('fs-root has 2000+ files', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        const hashes = hashDirectory(importedRoot);
        assert.ok(hashes.size >= 2000, `Expected at least 2000 files, got ${hashes.size}`);
    });

    it('indexed at least 3000 files from remote', () => {
        assertFileCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('all file hashes match source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('spot-check file content matches content-NNNN pattern', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        // Check a few specific files
        for (const num of ['0001', '0500', '1000', '1999']) {
            const filePath = join(importedRoot, 'test-data', 'many-files', `file-${num}.txt`);
            if (existsSync(filePath)) {
                const content = readFileSync(filePath, 'utf-8');
                assert.ok(content.includes(`content-${num}`),
                    `Expected file-${num}.txt to contain content-${num}, got: ${content}`);
            }
        }
    });
});
