/**
 * Test 51: --only + --remap onto a managed Atomic docroot (v1 composition)
 *
 * The committed Docker twin of the real "first migration onto a managed Atomic
 * site" flow, plus the scoped re-sync that follows it. It exercises the v1
 * invocation —
 *
 *   files-pull --only :wp-content: --remap :wp-content: :fs-root:/wp-content \
 *     --no-follow-symlinks
 *
 * — against a fs-root pre-populated with the realistic managed hosting layout
 * (shared read-only wordpress/ tree + hosting symlinks; see
 * lib/atomic-hosting-fixture.js), then a second, narrower scoped run on the
 * same target.
 *
 * Topology note: the e2e source ABSPATH is /srv/e2e-sites/<site>, so without
 * --remap the source's wp-content would reconstruct at
 * fs-root/srv/e2e-sites/<site>/wp-content (nested). `--remap :wp-content:
 * :fs-root:/wp-content` flattens it to fs-root/wp-content — which is why the
 * managed fixture here lives at the fs-root ROOT (the docroot), unlike test 37.
 *
 * Phase 1 — first migration (--only :wp-content:). Verifies the four contracts
 * of the composition:
 *   1. Core excluded (--only)        — no wp-admin/wp-includes pulled.
 *   2. Direct placement (--remap)    — wp-content at fs-root/wp-content, no nesting.
 *   3. Managed layer preserved       — hosting symlinks intact, read-only dirs
 *                                      don't crash, shared content untouched.
 *   4. New userland lands + collisions skip — custom content written; source
 *                                      files colliding with the managed layer
 *                                      are skipped (target wins).
 *
 * Phase 2 — scoped delta re-sync (--only :wp-content:/plugins). Verifies the
 * re-sync behavior against the same managed target:
 *   A. Delta-delete   — a file the SOURCE removed that is IN the current scope
 *                       and was previously synced is deleted on the target.
 *   B. Scope-survival — content synced by the earlier, broader run but OUTSIDE
 *                       the current scope is NOT deleted (the delete-drain is
 *                       guarded by in_scope(); the remote index is a union across
 *                       scoped runs).
 *   plus a control: an in-scope file the source still has is retained.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, writeFileSync, mkdirSync, lstatSync, readlinkSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    readAuditLog, fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { buildAtomicHostingFixture, unlockSharedDir } from '../lib/atomic-hosting-fixture.js';

describe('Import: --only + --remap onto a managed Atomic docroot', () => {
    const site = 'only-remap-managed';
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                const wpContent = join(remoteSiteDir, 'wp-content');

                // custom-plugin — imported in phase 1, then REMOVED from the
                // source before the phase 2 delta (in-scope deletion).
                const pluginDir = join(wpContent, 'plugins', 'custom-plugin');
                mkdirSync(pluginDir, { recursive: true });
                writeFileSync(join(pluginDir, 'custom-plugin.php'),
                    '<?php /* REMOTE custom plugin – imported, then removed from source */');

                // keep-plugin — in scope on both runs, stays on the source;
                // must be retained through the delta (control).
                const keepDir = join(wpContent, 'plugins', 'keep-plugin');
                mkdirSync(keepDir, { recursive: true });
                writeFileSync(join(keepDir, 'keep-plugin.php'),
                    '<?php /* REMOTE keep plugin – stays on source */');

                // custom-theme — imported in phase 1, OUT of scope in phase 2;
                // must survive the narrower re-sync.
                const themeDir = join(wpContent, 'themes', 'custom-theme');
                mkdirSync(themeDir, { recursive: true });
                writeFileSync(join(themeDir, 'style.css'),
                    '/* REMOTE custom theme – imported, out of scope on the delta run */');

                const uploadsDir = join(wpContent, 'uploads', '2025', '06');
                mkdirSync(uploadsDir, { recursive: true });
                writeFileSync(join(uploadsDir, 'new.jpg'),
                    'REMOTE upload – should be imported');

                // Collision with the managed akismet symlink: the remote ships
                // its own akismet (with a readme the shared copy lacks). Neither
                // should overwrite or write through the managed symlink.
                const akismetDir = join(wpContent, 'plugins', 'akismet');
                mkdirSync(akismetDir, { recursive: true });
                writeFileSync(join(akismetDir, 'akismet.php'),
                    '<?php /* REMOTE akismet – should NOT appear locally */');
                writeFileSync(join(akismetDir, 'readme.txt'),
                    'Remote Akismet readme – should NOT appear locally');
            },
        });
        siteDir = getSiteDir(site);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    // Shared remap and symlink flags; the --only scope varies per run.
    const remap = ['--remap', ':wp-content:', ':fs-root:/wp-content'];
    const symlinkOptions = ['--no-follow-symlinks'];

    let tempDir;
    let fsRoot;
    let wpShared;

    beforeAll(() => {
        tempDir = createTempDir('e2e-only-remap-managed');
        fsRoot = fsRootDir(tempDir);
        // Managed layer lives at the fs-root ROOT — that's where
        // `--remap :wp-content: :fs-root:/wp-content` places the pulled content.
        ({ wpShared } = buildAtomicHostingFixture(fsRoot));
    });

    afterAll(() => {
        unlockSharedDir(wpShared);
        cleanupTempDir(tempDir);
    });

    const tgtPlugin = (name) => join(fsRoot, 'wp-content', 'plugins', name);
    const tgtTheme = (name) => join(fsRoot, 'wp-content', 'themes', name);

    // ================================================================
    // Phase 1 — first migration: --only :wp-content:
    // ================================================================
    it('files-pull completes with --only + --remap and default local preservation', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--only', ':wp-content:', ...remap, ...symlinkOptions],
        });
        assert.equal(
            result.exitCode, 0,
            `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
    });

    // -- 1. Core excluded (--only) --------------------------------
    it('source WP core is never pulled', () => {
        assert.ok(!existsSync(join(fsRoot, 'wp-admin')),
            'wp-admin should not be pulled (--only :wp-content:)');
        assert.ok(!existsSync(join(fsRoot, 'wp-includes')),
            'wp-includes should not be pulled (--only :wp-content:)');
    });

    // -- 2. Direct placement (--remap) ----------------------------
    it('wp-content lands at fs-root/wp-content, not nested', () => {
        assert.ok(existsSync(join(fsRoot, 'wp-content')),
            'wp-content should exist at the fs-root root');
        // No source-abspath nesting: nothing reconstructed under /srv/...
        assert.ok(!existsSync(join(fsRoot, 'srv')),
            'content should be remapped, not nested under srv/e2e-sites/...');
    });

    it('new userland content is written under the remapped wp-content', () => {
        assert.ok(existsSync(join(tgtPlugin('custom-plugin'), 'custom-plugin.php')),
            'custom plugin should be imported');
        assert.ok(existsSync(join(tgtPlugin('keep-plugin'), 'keep-plugin.php')),
            'keep plugin should be imported');
        assert.ok(existsSync(join(tgtTheme('custom-theme'), 'style.css')),
            'custom theme should be imported');
        assert.ok(existsSync(join(fsRoot, 'wp-content', 'uploads', '2025', '06', 'new.jpg')),
            'uploaded media should be imported');
    });

    // -- 3. Managed layer preserved -------------------------------
    it('managed hosting symlinks stay intact', () => {
        assert.ok(lstatSync(join(fsRoot, '__wp__')).isSymbolicLink(), '__wp__ symlink preserved');
        assert.equal(readlinkSync(join(fsRoot, '__wp__')), '../wordpress/core/latest');
        assert.ok(lstatSync(join(fsRoot, 'wp-load.php')).isSymbolicLink(), 'wp-load.php symlink preserved');
        assert.ok(lstatSync(join(fsRoot, 'wp-content', 'plugins', 'jetpack')).isSymbolicLink(),
            'jetpack symlink preserved');
        assert.ok(lstatSync(join(fsRoot, 'wp-content', 'object-cache.php')).isSymbolicLink(),
            'object-cache.php drop-in symlink preserved');
    });

    it('shared read-only managed content is not modified', () => {
        assert.equal(
            readFileSync(join(wpShared, 'plugins/akismet/latest/akismet.php'), 'utf-8'),
            '<?php // shared akismet',
        );
    });

    // -- 4. Collisions skip (target wins) -------------------------
    it('source files colliding with the managed layer are skipped', () => {
        // akismet is a managed symlink; the remote's akismet.php must not win.
        assert.equal(
            readFileSync(join(fsRoot, 'wp-content', 'plugins', 'akismet', 'akismet.php'), 'utf-8'),
            '<?php // shared akismet',
            'managed akismet.php should win over the remote version',
        );
        // The remote's readme.txt must not be written through the symlink into
        // the shared (read-only) pool.
        assert.ok(!existsSync(join(wpShared, 'plugins/akismet/latest/readme.txt')),
            'remote readme.txt must not leak into the shared akismet dir');
    });

    it('audit log records PRESERVE-LOCAL skips', () => {
        const audit = readAuditLog(tempDir);
        assert.ok(audit.includes('PRESERVE-LOCAL'),
            'expected PRESERVE-LOCAL skip entries in the audit log');
    });

    // ================================================================
    // Phase 2 — scoped delta re-sync: --only :wp-content:/plugins
    // ================================================================
    it('removes custom-plugin from source, then aborts to force a fresh delta', () => {
        // The source tree is owned by nginx:nginx (site-setup chowns it), so
        // mutate it via sudo — matching the delta pattern in import-03.
        execSync(`sudo rm -rf ${JSON.stringify(join(siteDir, 'wp-content', 'plugins', 'custom-plugin'))}`);
        assert.ok(!existsSync(join(siteDir, 'wp-content', 'plugins', 'custom-plugin')),
            'precondition: source custom-plugin removed');
        const abort = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
            autoResume: false,
        });
        assert.equal(abort.exitCode, 0, `abort expected exit 0\nstderr: ${abort.stderr}`);
    });

    it('scoped delta re-sync completes (--only :wp-content:/plugins)', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--only', ':wp-content:/plugins', ...remap, ...symlinkOptions],
        });
        assert.equal(
            result.exitCode, 0,
            `delta re-sync expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
    });

    // A — delta delete
    it('delta-deletes the in-scope plugin the source removed', () => {
        assert.ok(!existsSync(tgtPlugin('custom-plugin')),
            'custom-plugin should be deleted (in scope, removed from source)');
    });

    // control — in-scope, still on source
    it('retains the in-scope plugin the source still has', () => {
        assert.ok(existsSync(join(tgtPlugin('keep-plugin'), 'keep-plugin.php')),
            'keep-plugin should be retained (in scope, still on source)');
    });

    // B — scope survival
    it('preserves the out-of-scope theme synced by the earlier broader run', () => {
        assert.ok(existsSync(join(tgtTheme('custom-theme'), 'style.css')),
            'custom-theme should survive (out of scope in the delta run — delete-drain skips it)');
    });

    it('managed layer stays intact through the delta', () => {
        assert.ok(lstatSync(join(fsRoot, '__wp__')).isSymbolicLink(), '__wp__ symlink intact');
        assert.ok(lstatSync(tgtPlugin('akismet')).isSymbolicLink(), 'akismet managed symlink intact');
        assert.ok(lstatSync(join(fsRoot, 'wp-content', 'object-cache.php')).isSymbolicLink(),
            'object-cache.php drop-in symlink intact');
    });
});
