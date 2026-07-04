/**
 * Test 52: push-files — staged chunk upload and rename-apply
 *
 * The reverse direction: the local CLI pushes a tree INTO the served site's
 * staged artifact store, then staged_apply moves the verified transfer into
 * the target root by rename. The target serves lib.php WP-less (same
 * bootstrap as test 44), which is exactly how hosts that route the API from
 * their own index.php run it.
 *
 * Nuances covered:
 *  - full push to applied, content-identical, including nested paths, an
 *    empty file, and a multi-chunk file
 *  - idempotent re-push (cache-skips, apply reports already applied)
 *  - resume after the client loses ALL local state (server is the truth)
 *  - resume when a prior apply already moved one staged artifact
 *  - a same-size edit re-pushes (size checks alone cannot see it)
 *  - --only pushes a subset and applies only that subset
 *  - wrong secret fails fast without staging a byte
 *  - cross-device staging (tmpfs vs disk) rejects before any upload
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, readdirSync, rmSync,
    mkdirSync, writeFileSync, utimesSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir, runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

/** Writes the WP-less API router that serves the staged endpoints. */
function writePushRouter(siteDir, stagingDir) {
    writeFileSync(join(siteDir, 'index.php'), `<?php
define('ABSPATH', __DIR__ . '/');
define('SITE_EXPORT_PLUGIN_DIR', __DIR__ . '/wp-content/plugins/site-export/');
define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');
define('SITE_EXPORT_STAGING_DIR', '${stagingDir}');
define('SITE_EXPORT_APPLY_ROOT', __DIR__ . '/applied-site');
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(\$file) { return rtrim(dirname(\$file), '/') . '/'; }
}
require_once SITE_EXPORT_PLUGIN_DIR . 'lib.php';
_site_export_handle_api_request();
`);
}

function provisionOptions(site, stagingDir) {
    return {
        db: 'none',
        files: 'none',
        afterCreate: async (siteDir) => {
            mkdirSync(join(siteDir, 'applied-site'), { recursive: true });
            writePushRouter(siteDir, stagingDir);
            writeFileSync(
                join(siteDir, 'wp-content', 'plugins', 'site-export', 'secret.php'),
                `<?php return 'test-secret-${site}';\n`
            );
        },
        afterPermissions: async (siteDir) => {
            // PHP-FPM must write staging and the apply root.
            execSync(`sudo mkdir -p "${join(siteDir, 'applied-site')}"`);
            execSync(`sudo chmod -R 0777 "${join(siteDir, 'applied-site')}"`);
        },
    };
}

describe('Import: push-files stages and applies a local tree', () => {
    const site = 'push-target';
    let siteDir;
    let appliedRoot;
    let tempDir;
    let sourceDir;

    function runPush(extraArgs = [], options = {}) {
        return runImporter(getSiteUrl(site), tempDir, 'push-files', {
            secret: getSiteSecret(site),
            extraArgs,
            // push-files needs no preflight (its apply probe is the
            // capability check), and this target serves lib.php WP-less,
            // where the pull preflight legitimately reports not-ok.
            skipPreflight: true,
            ...options,
        });
    }

    function freshWorkspace() {
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
        tempDir = createTempDir('e2e-push-files');
        sourceDir = fsRootDir(tempDir);
        mkdirSync(join(sourceDir, 'wp-content', 'uploads'), { recursive: true });
        mkdirSync(join(sourceDir, 'wp-content', 'plugins', 'my-plugin'), { recursive: true });
        writeFileSync(join(sourceDir, 'index.php'), '<?php // pushed root');
        writeFileSync(join(sourceDir, 'empty.txt'), '');
        writeFileSync(join(sourceDir, 'wp-content', 'plugins', 'my-plugin', 'my-plugin.php'), '<?php // v1');
        // Several append steps at the endpoint's 256 KiB buffer.
        writeFileSync(join(sourceDir, 'wp-content', 'uploads', 'big.bin'), 'reprint!'.repeat(80000));
    }

    function resetTarget() {
        // Root perms on staging content vary with PHP-FPM's umask; sudo
        // keeps the reset deterministic.
        execSync(`sudo rm -rf "${join(siteDir, '.push-staging')}"`);
        execSync(`sudo rm -rf "${appliedRoot}"`);
        execSync(`sudo mkdir -p "${appliedRoot}"`);
        execSync(`sudo chmod 0777 "${appliedRoot}"`);
    }

    beforeAll(async () => {
        await ensureSite(site, provisionOptions(site, join(getSiteDir(site), '.push-staging')));
        siteDir = getSiteDir(site);
        appliedRoot = join(siteDir, 'applied-site');
    }, 300000);

    afterAll(() => {
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
    });

    it('pushes a tree to applied, content-identical', () => {
        freshWorkspace();
        resetTarget();

        const result = runPush(['--apply']);

        assert.equal(result.exitCode, 0, `push failed:\n${result.stderr}\n${result.stdout}`);
        assert.ok(result.stdout.includes('push_apply'), 'apply summary expected in output');
        assert.equal(
            readFileSync(join(appliedRoot, 'index.php'), 'utf-8'),
            '<?php // pushed root'
        );
        assert.equal(
            readFileSync(join(appliedRoot, 'wp-content', 'uploads', 'big.bin'), 'utf-8'),
            'reprint!'.repeat(80000),
            'multi-chunk file must arrive byte-identical'
        );
        assert.equal(readFileSync(join(appliedRoot, 'empty.txt'), 'utf-8'), '');

        // The transfer was consumed: nothing left under staging files/.
        const stagedFiles = join(siteDir, '.push-staging', 'files');
        const leftovers = existsSync(stagedFiles) ? readdirSync(stagedFiles) : [];
        assert.deepEqual(leftovers, [], 'apply consumes the staged transfer');
    }, 120000);

    it('re-pushing the identical tree is an idempotent no-op', () => {
        const result = runPush(['--apply']);

        assert.equal(result.exitCode, 0, `re-push failed:\n${result.stderr}\n${result.stdout}`);
        const summary = JSON.parse(result.stdout.slice(result.stdout.lastIndexOf('{\n')));
        assert.equal(summary.status, 'complete');
    }, 120000);

    it('resumes after the client loses all local state', () => {
        freshWorkspace();
        resetTarget();

        // Stage without applying, then lose every client-side record — the
        // done cache, the sizer state, everything.
        const staged = runPush();
        assert.equal(staged.exitCode, 0, `staging failed:\n${staged.stderr}\n${staged.stdout}`);
        rmSync(join(tempDir, '.push-state.json'), { force: true });
        rmSync(join(tempDir, '.push-verified.jsonl'), { force: true });

        // The rerun re-confirms every artifact against the store (status +
        // finalize short-circuits, no byte re-uploads) and applies.
        const resumed = runPush(['--apply']);

        assert.equal(resumed.exitCode, 0, `resume failed:\n${resumed.stderr}\n${resumed.stdout}`);
        assert.equal(
            readFileSync(join(appliedRoot, 'wp-content', 'plugins', 'my-plugin', 'my-plugin.php'), 'utf-8'),
            '<?php // v1'
        );
    }, 120000);

    it('finishes an apply window with one file already moved', () => {
        freshWorkspace();
        resetTarget();

        const staged = runPush();
        assert.equal(staged.exitCode, 0, `staging failed:\n${staged.stderr}\n${staged.stdout}`);

        // Simulate a kill after the first rename but before apply could consume
        // its verified marker. The rerun must treat that artifact as already
        // applied and continue with the still-staged files.
        execSync(
            `sudo mv "${join(siteDir, '.push-staging', 'files', 'index.php')}" "${join(appliedRoot, 'index.php')}"`
        );

        const result = runPush(['--apply']);

        assert.equal(result.exitCode, 0, `apply rerun failed:\n${result.stderr}\n${result.stdout}`);
        const summary = JSON.parse(result.stdout.slice(result.stdout.lastIndexOf('{\n')));
        assert.equal(summary.status, 'complete');
        assert.equal(summary.already_applied, 1);
        assert.equal(
            readFileSync(join(appliedRoot, 'wp-content', 'plugins', 'my-plugin', 'my-plugin.php'), 'utf-8'),
            '<?php // v1'
        );
    }, 120000);

    it('a same-size edit re-pushes instead of cache-skipping', () => {
        freshWorkspace();
        resetTarget();
        assert.equal(runPush(['--apply']).exitCode, 0);

        // Same byte length, different content — invisible to size checks.
        const edited = join(sourceDir, 'wp-content', 'plugins', 'my-plugin', 'my-plugin.php');
        writeFileSync(edited, '<?php // v2');
        const later = new Date(Date.now() + 5000);
        utimesSync(edited, later, later);

        const result = runPush(['--apply']);

        assert.equal(result.exitCode, 0, `${result.stderr}\n${result.stdout}`);
        assert.equal(
            readFileSync(join(appliedRoot, 'wp-content', 'plugins', 'my-plugin', 'my-plugin.php'), 'utf-8'),
            '<?php // v2',
            'the target must see the same-size edit'
        );
    }, 120000);

    it('--only pushes and applies just the selected subtree', () => {
        freshWorkspace();
        resetTarget();

        const result = runPush(['--apply', '--only=wp-content/uploads']);

        assert.equal(result.exitCode, 0, `${result.stderr}\n${result.stdout}`);
        assert.ok(existsSync(join(appliedRoot, 'wp-content', 'uploads', 'big.bin')));
        assert.ok(
            !existsSync(join(appliedRoot, 'index.php')),
            'out-of-scope files must not apply'
        );
    }, 120000);

    it('a wrong secret fails fast without staging a byte', () => {
        freshWorkspace();
        resetTarget();

        const result = runImporter(getSiteUrl(site), tempDir, 'push-files', {
            secret: 'not-the-secret',
            extraArgs: ['--apply'],
            autoResume: false,
            skipPreflight: true,
        });

        assert.equal(result.exitCode, 1);
        assert.ok(
            (result.stdout + result.stderr).includes('auth_failed'),
            `expected auth_failed:\n${result.stdout}\n${result.stderr}`
        );
        const stagedFiles = join(siteDir, '.push-staging', 'files');
        const staged = existsSync(stagedFiles) ? readdirSync(stagedFiles) : [];
        assert.deepEqual(staged, [], 'no artifact may stage without the secret');
    }, 120000);
});

describe('Import: push-files refuses cross-device staging up front', () => {
    const site = 'push-target-xdev';
    // tmpfs — a different stat device than /srv on the CI runner.
    const xdevStaging = '/dev/shm/reprint-e2e-xdev-staging';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, provisionOptions(site, xdevStaging));
        tempDir = createTempDir('e2e-push-xdev');
        mkdirSync(fsRootDir(tempDir), { recursive: true });
        writeFileSync(join(fsRootDir(tempDir), 'index.php'), '<?php // must never upload');
    }, 300000);

    afterAll(() => {
        cleanupTempDir(tempDir);
        // PHP-FPM owns the staging scaffolding it created (0700 nginx).
        execSync(`sudo rm -rf "${xdevStaging}"`);
    });

    it.skipIf(!existsSync('/dev/shm'))(
        'rejects before any byte is uploaded',
        () => {
            const result = runImporter(getSiteUrl(site), tempDir, 'push-files', {
                secret: getSiteSecret(site),
                extraArgs: ['--apply'],
                autoResume: false,
                skipPreflight: true,
            });

            assert.equal(result.exitCode, 1, `${result.stdout}\n${result.stderr}`);
            assert.ok(
                (result.stdout + result.stderr).includes('cross_device'),
                `expected cross_device:\n${result.stdout}\n${result.stderr}`
            );
            // The probe rejected before the transfer began: staging holds
            // no artifact bytes.
            const files = join(xdevStaging, 'files');
            const staged = existsSync(files) ? readdirSync(files) : [];
            assert.deepEqual(staged, [], 'nothing may upload into doomed staging');
        },
        120000
    );
});
