/**
 * Test 46: shared theme symlink portability
 *
 * Reproduces a WP Cloud-like layout where a site theme entry in
 * wp-content/themes is a symlink to a shared absolute path.
 *
 * Source:
 *   wp-content/themes/indice -> /tmp/e2e-shared-themes/pub/indice
 *
 * The source symlink is valid and readable. After import, the target should
 * still have a working theme entry at wp-content/themes/indice.
 *
 * Current behavior: the importer rejects absolute symlink targets outside the
 * local fs-root, so the theme entry is missing or non-working on target.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, lstatSync, mkdirSync, readFileSync,
    rmSync, symlinkSync, writeFileSync,
} from 'node:fs';
import { execSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    createTempDir, cleanupTempDir, fsRootDir,
    getSiteDir, getSiteSecret, getSiteUrl, runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const SHARED_THEME_ROOT = '/tmp/e2e-shared-themes';
const SHARED_THEME_DIR = join(SHARED_THEME_ROOT, 'pub', 'indice');

describe('Import: Shared theme symlink remains working', () => {
    const site = 'wpcloud-shared-theme-symlink';
    let tempDir;
    let fallbackApiServer = null;

    function prepareSharedThemeTree() {
        rmSync(SHARED_THEME_ROOT, { recursive: true, force: true });
        mkdirSync(SHARED_THEME_DIR, { recursive: true });
        writeFileSync(
            join(SHARED_THEME_DIR, 'style.css'),
            '/* Shared indice theme */\n',
        );
        writeFileSync(
            join(SHARED_THEME_DIR, 'index.php'),
            '<?php // shared indice theme\n',
        );
    }

    async function ensureApiReachable() {
        const apiUrl = getSiteUrl(site);
        const apiPort = Number(new URL(apiUrl).port || 80);
        try {
            await fetch(apiUrl, { method: 'GET' });
            return;
        } catch {
            // If local infra does not expose this port yet, serve plugin API
            // directly via PHP built-in server for this test.
        }

        const docRoot = getSiteDir(site);
        fallbackApiServer = spawn('php', ['-S', `127.0.0.1:${apiPort}`, '-t', docRoot], {
            stdio: 'ignore',
        });

        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            if (fallbackApiServer.exitCode !== null) {
                throw new Error(`Fallback API server exited early with code ${fallbackApiServer.exitCode}`);
            }
            try {
                await fetch(apiUrl, { method: 'GET' });
                return;
            } catch {
                await sleep(100);
            }
        }

        throw new Error('Timed out waiting for fallback API server');
    }

    beforeAll(async () => {
        // Recreate this site from scratch so the copied plugin bundle always
        // matches the current workspace (including vendor runtime files).
        execSync(`sudo rm -rf "${getSiteDir(site)}"`, { timeout: 30000 });

        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                prepareSharedThemeTree();

                const themesDir = join(siteDir, 'wp-content', 'themes');
                mkdirSync(themesDir, { recursive: true });

                const indiceLocalPath = join(themesDir, 'indice');
                rmSync(indiceLocalPath, { recursive: true, force: true });
                symlinkSync(SHARED_THEME_DIR, indiceLocalPath);
            },
        });
        prepareSharedThemeTree();
        await ensureApiReachable();

        tempDir = createTempDir('e2e-wpcloud-shared-theme-symlink');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        rmSync(SHARED_THEME_ROOT, { recursive: true, force: true });
        if (fallbackApiServer && fallbackApiServer.exitCode === null) {
            fallbackApiServer.kill('SIGTERM');
        }
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('precondition: source has a working theme symlink', () => {
        const sourceIndice = join(getSiteDir(site), 'wp-content', 'themes', 'indice');
        assert.ok(lstatSync(sourceIndice).isSymbolicLink(), 'Source indice should be a symlink');
        assert.ok(
            existsSync(join(sourceIndice, 'style.css')),
            'Source symlink should resolve to a readable style.css',
        );
    });

    it('files-sync completes', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks'],
        });
        assert.equal(
            result.exitCode,
            0,
            `files-sync failed:\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`,
        );
    });

    it('target keeps a working wp-content/themes/indice entry', () => {
        const importedIndice = join(
            fsRootDir(tempDir),
            getSiteDir(site),
            'wp-content',
            'themes',
            'indice',
        );

        // Expected behavior: the theme entry should be present and working
        // after import, matching the source site's functional symlink.
        assert.ok(existsSync(importedIndice), 'Imported indice entry should exist');
        assert.ok(lstatSync(importedIndice).isSymbolicLink(), 'Imported indice should be a symlink');
        assert.ok(
            existsSync(join(importedIndice, 'style.css')),
            'Imported indice symlink should resolve to style.css',
        );
    });

    it('target can access the followed shared theme files', () => {
        const followedThemeStyle = join(
            fsRootDir(tempDir),
            SHARED_THEME_DIR,
            'style.css',
        );
        assert.ok(
            existsSync(followedThemeStyle),
            'Followed shared theme file should be present in fs-root',
        );
        assert.ok(
            readFileSync(followedThemeStyle, 'utf-8').includes('Shared indice theme'),
            'Followed shared theme file content should match source',
        );
    });
});
