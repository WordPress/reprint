/**
 * Test 16: Custom WP Content via client.php
 * Tests file sync and SQL sync for site with non-standard wp-content directory.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { cpSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertRemoteIndexEntryCount, assertSiteMirror,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Custom WP Content', () => {
    const site = 'custom-wp-content';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                const customPlugin = join(siteDir, 'custom-content', 'plugins', 'site-export');
                const srcPlugin = join(siteDir, 'wp-content', 'plugins', 'site-export');
                mkdirSync(join(siteDir, 'custom-content', 'plugins'), { recursive: true });
                cpSync(srcPlugin, customPlugin, { recursive: true });
            },
        });
        tempDir = createTempDir('e2e-import-custom-wp');
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

    it('files downloaded correctly', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('accounted for at least 3000 remote index entries', () => {
        assertRemoteIndexEntryCount(tempDir, importUrl());
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('db-pull completes with valid dump', () => {
        const sqlDir = createTempDir('e2e-import-custom-wp-sql');
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
