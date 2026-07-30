/**
 * Test 04: Files Index via import.php
 * Tests files-index command produces .reprint/pull/remote-index.jsonl.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    countJsonlLines,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Files Index', () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-files-index');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-index produces .reprint/pull/remote-index.jsonl', () => {
        const result = runImporter(importUrl(), tempDir, 'files-index', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const indexFile = join(tempDir, '.reprint/pull/remote-index.jsonl');
        assert.ok(existsSync(indexFile), 'Expected .reprint/pull/remote-index.jsonl to exist');

        const lines = readFileSync(indexFile, 'utf-8').trim().split('\n').filter(l => l);
        assert.ok(lines.length > 0, 'Expected at least one index entry');

        // Entries should include paths from the site directory
        const entries = lines.map(l => JSON.parse(l));
        const hasPaths = entries.some(e => {
            const path = e.path || e.file || Object.values(e).find(v => typeof v === 'string' && v.includes('/'));
            return typeof path === 'string' && path.length > 0;
        });
        assert.ok(hasPaths, `Expected entries with file paths, got: ${JSON.stringify(entries.slice(0, 2))}`);

        const directories = entries.filter(e => e.type === 'dir');
        assert.ok(directories.length > 0, 'Expected directory entries in remote index');
        for (const directory of directories) {
            assert.equal(
                typeof directory.empty,
                'boolean',
                `Expected directory emptiness in remote index: ${JSON.stringify(directory)}`,
            );
        }
    });

    it('remote index has at least 3000 entries', () => {
        const indexFile = join(tempDir, '.reprint/pull/remote-index.jsonl');
        const count = countJsonlLines(indexFile);
        assert.ok(count >= 3000,
            `Expected at least 3000 entries in remote index, got ${count}`);
    });
});
