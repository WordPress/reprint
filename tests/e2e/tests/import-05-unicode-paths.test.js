/**
 * Test 05: Unicode/Emoji Paths via import.php
 * Tests file sync and SQL sync with unicode filenames.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, assertTreesMatch,
    assertRemoteIndexEntryCount, assertSiteMirror,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Unicode Paths', () => {
    const site = 'emoji-paths';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                const dataDir = join(siteDir, 'test-data');
                mkdirSync(join(dataDir, 'dir-with-dashes'), { recursive: true });
                writeFileSync(join(dataDir, 'fire.txt'), 'emoji file');
                writeFileSync(join(dataDir, 'rocket-file.txt'), 'rocket content');
                writeFileSync(join(dataDir, 'file with spaces.txt'), 'spaces');
                writeFileSync(join(dataDir, 'dir-with-dashes', 'inner.txt'), 'dashed');
                // Node handles UTF-8 natively — no shell/printf needed
                writeFileSync(join(dataDir, 'caf\u00e9.txt'), 'unicode content');
                writeFileSync(join(dataDir, '\u4e2d\u6587.txt'), 'chinese');
                writeFileSync(join(dataDir, '\u{1F525}\u{1F680}.txt'), 'emoji content');
                writeFileSync(join(dataDir, 'file\nwith\nnewlines.txt'), 'newline content');
                // Invalid UTF-8 filename: use Buffer for the path
                const invalidPath = Buffer.concat([
                    Buffer.from(join(dataDir, 'invalid')),
                    Buffer.from([0xff, 0xfe]),
                    Buffer.from('utf8.txt'),
                ]);
                writeFileSync(invalidPath, 'invalid utf8');
            },
        });
        tempDir = createTempDir('e2e-import-unicode');
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

    it('files with emoji, accented chars, spaces, and Chinese chars exist', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        // Check for files with special names
        const importedHashes = hashDirectory(importedRoot);

        // Check some known unicode filenames exist in imported set
        const importedPaths = [...importedHashes.keys()];

        // café.txt (accented)
        assert.ok(
            importedPaths.some(p => p.includes('caf\u00e9')),
            `Expected café.txt in imported files, got paths containing 'caf': ${importedPaths.filter(p => p.includes('caf')).join(', ')}`
        );

        // Chinese characters
        assert.ok(
            importedPaths.some(p => p.includes('\u4e2d\u6587')),
            `Expected Chinese filename in imported files`
        );

        // File with spaces
        assert.ok(
            importedPaths.some(p => p.includes('file with spaces')),
            `Expected 'file with spaces.txt' in imported files`
        );
    });

    it('file hashes match source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('accounted for at least 3000 remote index entries', () => {
        assertRemoteIndexEntryCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('db-pull completes with valid dump', () => {
        const sqlDir = createTempDir('e2e-import-unicode-sql');
        try {
            const result = runImporter(importUrl(), sqlDir, 'db-pull', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}`);

            const sqlFile = join(sqlDir, 'db.sql');
            assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

            const sql = readFileSync(sqlFile, 'utf-8');
            assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        } finally {
            cleanupTempDir(sqlDir);
        }
    });
});
