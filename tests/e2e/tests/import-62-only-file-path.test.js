/** Test 62: `--only` accepts a single file, not just a directory (issue #539). */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: files-pull --only <file>', { timeout: 180000 }, () => {
    const site = 'only-file-path';
    let tempDir;
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                writeFileSync(join(remoteSiteDir, 'single-file.php'), '<?php // the only file\n');
            },
        });
        siteDir = getSiteDir(site);
        tempDir = createTempDir('e2e-only-file-path');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    it('files-pull completes when --only names one file', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--only', join(siteDir, 'single-file.php')],
        });
        assert.equal(
            result.exitCode, 0,
            `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
    });

    it('the named file is pulled with its contents intact', () => {
        const pulled = join(fsRootDir(tempDir), siteDir, 'single-file.php');
        assert.ok(existsSync(pulled), `Expected the named file at ${pulled}`);
        assert.equal(readFileSync(pulled, 'utf-8'), '<?php // the only file\n');
    });

    it('nothing else from the site is pulled', () => {
        const importedRoot = join(fsRootDir(tempDir), siteDir);
        assert.ok(!existsSync(join(importedRoot, 'wp-admin')),
            'wp-admin must not be pulled when --only names one file');
        assert.ok(!existsSync(join(importedRoot, 'wp-content')),
            'wp-content must not be pulled when --only names one file');
    });
});
