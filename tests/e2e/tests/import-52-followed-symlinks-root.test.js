/**
 * Test 52: followed symlinks root — --follow-symlinks=<dir> places escaping paths under a local root (ARC-1814).
 *
 * Covers the {in-scope, escaping} x {relative, absolute} matrix of directory
 * symlinks under --include + --remap: escaping targets are consolidated into the
 * local followed symlinks root (nested by source path, deduped), in-scope paths are left in place.
 * (Escaping *file* symlinks are a known limitation — not covered here.)
 *
 * Run: files-pull --include :wp-content: --remap :wp-content: :fs-root:/wp-content
 *                 --follow-symlinks=:fs-root:/.followed-symlinks-root
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

const SHARED_ROOT = '/tmp/e2e-followed-symlinks-root';
const SHARED_DIR = join(SHARED_ROOT, 'pub', 'indice');
const FOLLOWED_SYMLINKS_STYLE = join('.followed-symlinks-root', SHARED_ROOT, 'pub', 'indice', 'style.css');

describe('Import: local followed symlinks root (--follow-symlinks=<dir>) places escaping paths', () => {
    const site = 'followed-symlinks-root';
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
                    // escaping (target outside wp-content) — uses the local followed symlinks root
                    ['indice', SHARED_DIR],                              // escaping, absolute
                    ['indice2', SHARED_DIR],                             // escaping, absolute (dedup)
                    ['esc-rel', relative(themes, SHARED_DIR)],           // escaping, relative
                    // in-scope (target inside wp-content) — must NOT use the local followed symlinks root
                    ['local', './indice-real'],                         // in-scope, relative
                    ['abs-local', join(themes, 'indice-real')],         // in-scope, absolute
                ]) {
                    const p = join(themes, name);
                    rmSync(p, { recursive: true, force: true });
                    symlinkSync(target, p);
                }
                // A plugin that symlinks into themes — for the narrow `--include
                // :wp-content:/plugins` run, themes/indice-real is OUTSIDE the
                // --include scope but INSIDE the remapped wp-content, so remap (checked
                // before the local followed symlinks root) should place it at wp-content/themes.
                const plugin = join(siteDir, 'wp-content', 'plugins', 'cross-plugin');
                mkdirSync(plugin, { recursive: true });
                writeFileSync(join(plugin, 'cross-plugin.php'), '<?php // cross plugin\n');
                symlinkSync('../../themes/indice-real', join(plugin, 'theme-rel'));       // relative
                symlinkSync(join(themes, 'indice-real'), join(plugin, 'theme-abs'));      // absolute
            },
        });
        prepareSharedTree();
        tempDir = createTempDir('e2e-followed-symlinks-root');
        tempDir2 = createTempDir('e2e-followed-symlinks-root-narrow');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        cleanupTempDir(tempDir2);
        rmSync(SHARED_ROOT, { recursive: true, force: true });
    });

    const importUrl = () => `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    const fsRoot = () => fsRootDir(tempDir);
    const fsRoot2 = () => fsRootDir(tempDir2);

    it('files-pull completes with --follow-symlinks=<dir>', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--include', ':wp-content:',
                '--remap', ':wp-content:', ':fs-root:/wp-content',
                '--follow-symlinks=:fs-root:/.followed-symlinks-root',
            ],
        });
        assert.equal(result.exitCode, 0, `sync failed:\n${result.stdout}\n${result.stderr}`);
    });

    it('escaping path content lands under the local followed symlinks root, nested by source path', () => {
        assert.ok(existsSync(join(fsRoot(), FOLLOWED_SYMLINKS_STYLE)),
            `expected followed symlinks content at ${join(fsRoot(), FOLLOWED_SYMLINKS_STYLE)}`);
        assert.ok(readFileSync(join(fsRoot(), FOLLOWED_SYMLINKS_STYLE), 'utf-8').includes('Shared indice theme'));
    });

    it('escaping content is not left at its source path in the docroot', () => {
        assert.ok(!existsSync(join(fsRoot(), 'tmp')),
            'with a local followed symlinks root set, escaping content must not land at fs-root/tmp');
    });

    it('the escaping symlink resolves into the local followed symlinks root', () => {
        const link = join(fsRoot(), 'wp-content', 'themes', 'indice');
        assert.ok(lstatSync(link).isSymbolicLink(), 'indice is a symlink');
        assert.ok(existsSync(join(link, 'style.css')), 'indice resolves to followed symlinks style.css');
    });

    it('dedups: two symlinks to one path share one local followed symlinks copy', () => {
        const link2 = join(fsRoot(), 'wp-content', 'themes', 'indice2');
        assert.ok(existsSync(join(link2, 'style.css')), 'indice2 also resolves');
        assert.ok(existsSync(join(fsRoot(), '.followed-symlinks-root', SHARED_ROOT, 'pub', 'indice')));
    });

    it('escaping RELATIVE symlink resolves through the local followed symlinks root', () => {
        const link = join(fsRoot(), 'wp-content', 'themes', 'esc-rel');
        assert.ok(lstatSync(link).isSymbolicLink(), 'esc-rel is a symlink');
        assert.ok(existsSync(join(link, 'style.css')), 'esc-rel resolves to the followed symlinks theme');
        assert.ok(readFileSync(join(link, 'style.css'), 'utf-8').includes('Shared indice theme'));
    });

    it('in-scope symlinks (relative AND absolute) are left in place', () => {
        for (const name of ['local', 'abs-local']) {
            const link = join(fsRoot(), 'wp-content', 'themes', name);
            assert.ok(lstatSync(link).isSymbolicLink(), `${name} stays a symlink`);
            assert.ok(existsSync(join(link, 'style.css')), `${name} resolves within wp-content`);
            assert.ok(readFileSync(join(link, 'style.css'), 'utf-8').includes('in-scope local theme'),
                `${name} resolves to the in-scope theme`);
        }
        assert.ok(!existsSync(join(fsRoot(), '.followed-symlinks-root', 'wp-content')),
            'in-scope paths must not use the local followed symlinks root');
    });

    // ── Narrow --include: a plugin symlinks into themes (in wp-content, out of --include) ──
    // themes is OUTSIDE --include :wp-content:/plugins but INSIDE the remapped
    // wp-content, so remap (checked before followed-symlink placement) must place it
    // at wp-content/themes for both relative and absolute spellings.
    it('narrow --include :wp-content:/plugins files-pull completes', () => {
        const result = runImporter(importUrl(), tempDir2, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--include', ':wp-content:/plugins',
                '--remap', ':wp-content:', ':fs-root:/wp-content',
                '--follow-symlinks=:fs-root:/.followed-symlinks-root',
            ],
        });
        assert.equal(result.exitCode, 0, `sync failed:\n${result.stdout}\n${result.stderr}`);
    });

    it('cross-scope theme path lands under wp-content/themes through remap', () => {
        // Followed from a plugin symlink; themes is out of --include but in wp-content.
        const themeStyle = join(fsRoot2(), 'wp-content', 'themes', 'indice-real', 'style.css');
        assert.ok(existsSync(themeStyle), `expected remapped theme at ${themeStyle}`);
        assert.ok(readFileSync(themeStyle, 'utf-8').includes('in-scope local theme'));
        // It is not under the local followed symlinks root because remap wins.
        assert.ok(!existsSync(join(fsRoot2(), '.followed-symlinks-root')),
            'a path inside remapped wp-content must not use the local followed symlinks root');
    });

    it('both relative and absolute plugin→theme symlinks resolve', () => {
        for (const name of ['theme-rel', 'theme-abs']) {
            const link = join(fsRoot2(), 'wp-content', 'plugins', 'cross-plugin', name);
            assert.ok(lstatSync(link).isSymbolicLink(), `${name} is a symlink`);
            assert.ok(readFileSync(join(link, 'style.css'), 'utf-8').includes('in-scope local theme'),
                `${name} resolves to the remapped theme content`);
        }
    });

    it('narrow --include pulls only plugins — unfollowed themes are absent', () => {
        // themes/indice-real is present (followed via the plugin symlink), but the
        // other themes (indice, esc-rel, …) live outside --include=plugins and are not
        // reachable, so they must not be pulled.
        assert.ok(existsSync(join(fsRoot2(), 'wp-content', 'themes', 'indice-real')),
            'the followed theme is present');
        assert.ok(!existsSync(join(fsRoot2(), 'wp-content', 'themes', 'indice')),
            'unfollowed, out-of-scope themes are not pulled');
    });
});
