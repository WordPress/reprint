/** Test 64: `--only` preserves a selected symlinked directory. */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, lstatSync, mkdirSync, readFileSync, readlinkSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: files-pull --only <symlinked directory>', { timeout: 180000 }, () => {
    const site = 'only-symlinked-directory-root';
    const linkTarget = '../../shared/akismet-5.7';
    let tempDir;
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                const target = join(remoteSiteDir, 'shared', 'akismet-5.7');
                const link = join(remoteSiteDir, 'wp-content', 'plugins', 'akismet');
                mkdirSync(target, { recursive: true });
                writeFileSync(join(target, 'akismet.php'), '<?php // akismet\n');
                rmSync(link, { recursive: true, force: true });
                symlinkSync(linkTarget, link);
            },
        });
        siteDir = getSiteDir(site);
        tempDir = createTempDir('e2e-only-symlinked-directory-root');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    it('files-pull completes when --only names the symlink', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--only', join(siteDir, 'wp-content', 'plugins', 'akismet')],
        });
        assert.equal(
            result.exitCode, 0,
            `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
    });

    it('recreates the selected symlink with its original target spelling', () => {
        const importedRoot = join(fsRootDir(tempDir), siteDir);
        const link = join(importedRoot, 'wp-content', 'plugins', 'akismet');
        assert.ok(lstatSync(link).isSymbolicLink(), `Expected symlink at ${link}`);
        assert.equal(readFileSync(join(link, 'akismet.php'), 'utf-8'), '<?php // akismet\n');
        assert.equal(readlinkSync(link), linkTarget);
    });

    it('pulls the symlink target without unrelated site files', () => {
        const importedRoot = join(fsRootDir(tempDir), siteDir);
        assert.ok(existsSync(join(importedRoot, 'shared', 'akismet-5.7', 'akismet.php')));
        assert.ok(!existsSync(join(importedRoot, 'wp-admin')));
        assert.ok(!existsSync(join(importedRoot, 'wp-includes')));
    });
});
