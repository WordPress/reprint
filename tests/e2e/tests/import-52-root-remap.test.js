/**
 * Test 52: root remap — --remap / places escaping followed targets (ARC-1814).
 *
 * Covers the {in-scope, escaping} x {relative, absolute} matrix of directory
 * symlinks under --only + --remap: escaping targets are placed under the
 * dot directory (nested by source path, deduped), in-scope targets are left in place.
 * (Escaping *file* symlinks are a known limitation — not covered here.)
 *
 * Run: files-sync --only :wp-content: --remap :wp-content: :fs-root:/wp-content
 *                 --follow-symlinks --remap / :fs-root:/.symlinks
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, lstatSync, mkdirSync, readFileSync, rmSync, symlinkSync, writeFileSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join, relative } from 'node:path';
import {
    createTempDir, cleanupTempDir, fsRootDir,
    getSiteDir, getSiteSecret, getSiteUrl, runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const SHARED_ROOT = '/tmp/e2e-root-remap';
const SHARED_DIR = join(SHARED_ROOT, 'pub', 'indice');
const DOT_DIRECTORY_STYLE = join('.symlinks', SHARED_ROOT, 'pub', 'indice', 'style.css');

describe('Import: root remap (--remap /) places escaping followed targets', () => {
    const site = 'root-remap';
    let tempDir;
    let tempDir2;

    function prepareSharedTree() {
        rmSync(SHARED_ROOT, { recursive: true, force: true });
        mkdirSync(SHARED_DIR, { recursive: true });
        writeFileSync(join(SHARED_DIR, 'style.css'), '/* Shared indice theme */\n');
        writeFileSync(join(SHARED_DIR, 'index.php'), '<?php // shared indice theme\n');
    }

    beforeAll(async () => {
        execSync(`sudo rm -rf "${getSiteDir(site)}"`, { timeout: 30000 });
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                prepareSharedTree();
                const themes = join(siteDir, 'wp-content', 'themes');
                mkdirSync(join(themes, 'indice-real'), { recursive: true });
                writeFileSync(join(themes, 'indice-real', 'style.css'), '/* in-scope local theme */\n');
                // The 2x2 matrix of {in-scope, escaping} x {relative, absolute}:
                for (const [name, target] of [
                    // escaping (target outside wp-content) — goes to the catch-all remap
                    ['indice', SHARED_DIR],                              // escaping, absolute
                    ['indice2', SHARED_DIR],                             // escaping, absolute (dedup)
                    ['esc-rel', relative(themes, SHARED_DIR)],           // escaping, relative
                    // in-scope (target inside wp-content) — must NOT use the catch-all remap
                    ['local', './indice-real'],                         // in-scope, relative
                    ['abs-local', join(themes, 'indice-real')],         // in-scope, absolute
                ]) {
                    const p = join(themes, name);
                    rmSync(p, { recursive: true, force: true });
                    symlinkSync(target, p);
                }
                // A plugin that symlinks into themes — for the narrow `--only
                // :wp-content:/plugins` run, themes/indice-real is OUTSIDE the
                // --only scope but INSIDE the remapped wp-content, so remap (checked
                // before the catch-all) should place it at wp-content/themes, not the dot directory.
                const plugin = join(siteDir, 'wp-content', 'plugins', 'cross-plugin');
                mkdirSync(plugin, { recursive: true });
                writeFileSync(join(plugin, 'cross-plugin.php'), '<?php // cross plugin\n');
                symlinkSync('../../themes/indice-real', join(plugin, 'theme-rel'));       // relative
                symlinkSync(join(themes, 'indice-real'), join(plugin, 'theme-abs'));      // absolute
            },
        });
        prepareSharedTree();
        tempDir = createTempDir('e2e-root-remap');
        tempDir2 = createTempDir('e2e-root-remap-narrow');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        cleanupTempDir(tempDir2);
        rmSync(SHARED_ROOT, { recursive: true, force: true });
    });

    const importUrl = () => `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    const fsRoot = () => fsRootDir(tempDir);
    const fsRoot2 = () => fsRootDir(tempDir2);

    it('files-sync completes with --follow-symlinks and --remap /', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--only', ':wp-content:',
                '--remap', ':wp-content:', ':fs-root:/wp-content',
                '--follow-symlinks',
                '--remap', '/', ':fs-root:/.symlinks',
            ],
        });
        assert.equal(result.exitCode, 0, `sync failed:\n${result.stdout}\n${result.stderr}`);
    });

    it('escaping target content lands in the dot directory, nested by source path', () => {
        assert.ok(existsSync(join(fsRoot(), DOT_DIRECTORY_STYLE)),
            `expected dot-directory content at ${join(fsRoot(), DOT_DIRECTORY_STYLE)}`);
        assert.ok(readFileSync(join(fsRoot(), DOT_DIRECTORY_STYLE), 'utf-8').includes('Shared indice theme'));
    });

    it('escaping content is not left at its source path in the docroot', () => {
        assert.ok(!existsSync(join(fsRoot(), 'tmp')),
            'with a root catch-all remap, escaping content must not land at fs-root/tmp');
    });

    it('the escaping symlink resolves into the dot directory', () => {
        const link = join(fsRoot(), 'wp-content', 'themes', 'indice');
        assert.ok(lstatSync(link).isSymbolicLink(), 'indice is a symlink');
        assert.ok(existsSync(join(link, 'style.css')), 'indice resolves to dot-directory style.css');
    });

    it('dedups: two symlinks to one target share one dot directory copy', () => {
        const link2 = join(fsRoot(), 'wp-content', 'themes', 'indice2');
        assert.ok(existsSync(join(link2, 'style.css')), 'indice2 also resolves');
        assert.ok(existsSync(join(fsRoot(), '.symlinks', SHARED_ROOT, 'pub', 'indice')));
    });

    it('escaping RELATIVE symlink resolves (into the dot directory)', () => {
        const link = join(fsRoot(), 'wp-content', 'themes', 'esc-rel');
        assert.ok(lstatSync(link).isSymbolicLink(), 'esc-rel is a symlink');
        assert.ok(existsSync(join(link, 'style.css')), 'esc-rel resolves to the dot-directory theme');
        assert.ok(readFileSync(join(link, 'style.css'), 'utf-8').includes('Shared indice theme'));
    });

    it('in-scope symlinks (relative AND absolute) are left in place, not catch-all remapped', () => {
        for (const name of ['local', 'abs-local']) {
            const link = join(fsRoot(), 'wp-content', 'themes', name);
            assert.ok(lstatSync(link).isSymbolicLink(), `${name} stays a symlink`);
            assert.ok(existsSync(join(link, 'style.css')), `${name} resolves within wp-content`);
            assert.ok(readFileSync(join(link, 'style.css'), 'utf-8').includes('in-scope local theme'),
                `${name} resolves to the in-scope theme`);
        }
        assert.ok(!existsSync(join(fsRoot(), '.symlinks', 'wp-content')),
            'in-scope targets must not use the catch-all remap');
    });

    // ── Narrow --only: a plugin symlinks into themes (in wp-content, out of --only) ──
    // themes is OUTSIDE --only :wp-content:/plugins but INSIDE the remapped
    // wp-content, so remap (checked before the catch-all) must place it at wp-content/themes,
    // NOT the dot directory — for both relative and absolute spellings.
    it('narrow --only :wp-content:/plugins files-sync completes', () => {
        const result = runImporter(importUrl(), tempDir2, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--only', ':wp-content:/plugins',
                '--remap', ':wp-content:', ':fs-root:/wp-content',
                '--follow-symlinks',
                '--remap', '/', ':fs-root:/.symlinks',
            ],
        });
        assert.equal(result.exitCode, 0, `sync failed:\n${result.stdout}\n${result.stderr}`);
    });

    it('cross-scope theme target lands under wp-content/themes (remapped), not the dot directory', () => {
        // Followed from a plugin symlink; themes is out of --only but in wp-content.
        const themeStyle = join(fsRoot2(), 'wp-content', 'themes', 'indice-real', 'style.css');
        assert.ok(existsSync(themeStyle), `expected remapped theme at ${themeStyle}`);
        assert.ok(readFileSync(themeStyle, 'utf-8').includes('in-scope local theme'));
        // It is NOT in the dot directory (the wp-content remap wins for in-wp-content targets).
        assert.ok(!existsSync(join(fsRoot2(), '.symlinks')),
            'a target inside the remapped wp-content must not use the catch-all remap');
    });

    it('both relative and absolute plugin→theme symlinks resolve', () => {
        for (const name of ['theme-rel', 'theme-abs']) {
            const link = join(fsRoot2(), 'wp-content', 'plugins', 'cross-plugin', name);
            assert.ok(lstatSync(link).isSymbolicLink(), `${name} is a symlink`);
            assert.ok(readFileSync(join(link, 'style.css'), 'utf-8').includes('in-scope local theme'),
                `${name} resolves to the remapped theme content`);
        }
    });

    it('narrow --only pulls only plugins — unfollowed themes are absent', () => {
        // themes/indice-real is present (followed via the plugin symlink), but the
        // other themes (indice, esc-rel, …) live outside --only=plugins and are not
        // reachable, so they must not be pulled.
        assert.ok(existsSync(join(fsRoot2(), 'wp-content', 'themes', 'indice-real')),
            'the followed theme is present');
        assert.ok(!existsSync(join(fsRoot2(), 'wp-content', 'themes', 'indice')),
            'unfollowed, out-of-scope themes are not pulled');
    });
});
