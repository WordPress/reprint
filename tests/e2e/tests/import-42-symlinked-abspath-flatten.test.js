/**
 * Test 42: flat-docroot when ABSPATH goes through a symlink
 *
 * Simulates a WordPress.com Atomic-like environment where ABSPATH is
 * accessed through a symlink. On Atomic, /wordpress is a symlink, so
 * ABSPATH (e.g. /wordpress/core/6.9.4/) resolves to a different real path.
 *
 * Setup:
 * - Standard WP install at siteDir
 * - siteDir/wp-core is a symlink pointing to siteDir itself
 * - index.php pre-defines ABSPATH as siteDir/wp-core/ (through the symlink)
 * - realpath(ABSPATH) resolves to siteDir (the real path)
 *
 * Before the fix, the exporter reported the unresolved symlink path as
 * abspath. The importer would download files under the resolved real path
 * but flat-docroot would look at the unresolved symlink path in
 * fs-root and fail.
 *
 * The fix: the exporter now applies realpath() to ABSPATH, matching the
 * convention used for all other paths (content_dir, plugins_dir, etc.).
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, writeFileSync,
    symlinkSync,
} from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Flat Document Root with symlinked ABSPATH', () => {
    const site = 'symlinked-abspath';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                // Create a symlink that points back to the site directory itself.
                // This means siteDir/wp-core/wp-load.php is the same file as
                // siteDir/wp-load.php — just accessed through a symlink.
                symlinkSync('.', join(siteDir, 'wp-core'));

                // Rewrite index.php to pre-define ABSPATH through the symlink
                // before wp-load.php can define it.  This mirrors Atomic's
                // custom bootstrap where ABSPATH goes through a symlink.
                //
                // With ABSPATH pre-defined, wp-load.php skips its own
                // definition and finds wp-config.php at ABSPATH/wp-config.php
                // (which resolves through the symlink back to siteDir).
                writeFileSync(join(siteDir, 'index.php'), `<?php
define('WP_USE_THEMES', true);
define('ABSPATH', __DIR__ . '/wp-core/');
require ABSPATH . 'wp-blog-header.php';
`);
            },
        });

        tempDir = createTempDir('e2e-symlinked-abspath');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('preflight reports resolved abspath, not the symlink path', () => {
        const result = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `preflight failed:\n${result.stderr}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.reprint/pull/state.json'), 'utf-8'));
        const pathsUrls = state.preflight?.data?.database?.wp?.paths_urls;
        assert.ok(pathsUrls, 'Expected paths_urls in preflight data');

        // The critical assertion: abspath should be the resolved real path
        // (the site directory), NOT the unresolved symlink path with /wp-core/.
        assert.ok(
            !pathsUrls.abspath?.includes('/wp-core'),
            `abspath should NOT contain the unresolved symlink path '/wp-core', ` +
            `got: ${pathsUrls.abspath}`,
        );
        assert.ok(
            pathsUrls.abspath?.includes('symlinked-abspath'),
            `Expected abspath to contain the site directory name, ` +
            `got: ${pathsUrls.abspath}`,
        );
    });

    it('files-pull completes with follow_symlinks', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks'],
        });
        assert.equal(result.exitCode, 0, `files-pull failed:\n${result.stderr}`);
    });

    it('WP core files exist at the resolved path in fs-root', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.reprint/pull/state.json'), 'utf-8'));
        const abspath = state.preflight?.data?.database?.wp?.paths_urls?.abspath;
        assert.ok(abspath, 'abspath not found in state');

        const localAbspath = join(fsRootDir(tempDir), abspath);
        assert.ok(
            existsSync(localAbspath),
            `ABSPATH directory should exist at resolved path: ${localAbspath}`,
        );
        assert.ok(
            existsSync(join(localAbspath, 'wp-admin')),
            'wp-admin should exist under resolved abspath',
        );
        assert.ok(
            existsSync(join(localAbspath, 'wp-includes')),
            'wp-includes should exist under resolved abspath',
        );
    });

    describe('flat-docroot', () => {
        let flattenTo;

        beforeAll(() => {
            flattenTo = join(tempDir, 'flattened');
            const result = runImporter(importUrl(), tempDir, 'flat-docroot', {
                secret: getSiteSecret(site),
                extraArgs: [`--flatten-to=${flattenTo}`],
            });
            assert.equal(
                result.exitCode, 0,
                `flat-docroot failed:\n${result.stderr}\n${result.stdout}`,
            );
        });

        it('creates a working flattened layout', () => {
            assert.ok(existsSync(join(flattenTo, 'wp-load.php')), 'wp-load.php should exist');
            assert.ok(existsSync(join(flattenTo, 'index.php')), 'index.php should exist');
        });

        it('wp-admin is traversable', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-admin', 'admin.php')),
                'wp-admin/admin.php should be accessible through flatten-to',
            );
        });

        it('wp-includes is traversable', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-includes', 'version.php')),
                'wp-includes/version.php should be accessible through flatten-to',
            );
        });

        it('wp-content is accessible', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-content')),
                'wp-content should be accessible through flatten-to',
            );
        });
    });
});
