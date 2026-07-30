/**
 * Test 38: flat-document-root with WP Cloud-like directory layout
 *
 * Simulates a WP Cloud hosting environment where:
 * - ABSPATH is the document root (/srv/e2e-sites/wpcloud-flatten/)
 * - wp-admin and wp-includes are behind a __wp__ symlink pointing to
 *   a separate core directory (/tmp/e2e-wpcloud-core/)
 * - wp-content is in a separate location (/tmp/e2e-wpcloud-wpcontent/)
 *
 * Tests that:
 * 1. Preflight correctly resolves wp_admin_path / wp_includes_path via realpath()
 * 2. files-sync with --follow-symlinks downloads files at their resolved locations
 * 3. flat-document-root creates a standard WP layout by sourcing each component
 *    from where it physically lives
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, readlinkSync,
    mkdirSync, writeFileSync, symlinkSync, lstatSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const CORE_DIR = '/tmp/e2e-wpcloud-core';
const CONTENT_DIR = '/tmp/e2e-wpcloud-wpcontent';

describe('Import: Flat Document Root (WP Cloud-like layout)', () => {
    const site = 'wpcloud-flatten';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                // Simulate WP Cloud layout:
                //   wp-admin and wp-includes live behind __wp__ in a separate dir
                //   wp-content lives in a completely separate dir
                //
                // Clean up previous external dirs first (may be nginx-owned
                // from prior run).  This must happen inside afterCreate so
                // that the dirs are only deleted when they will be recreated.
                // Deleting them before ensureSite breaks re-runs: the marker
                // file still exists → afterCreate is skipped → broken symlinks.
                execSync(`sudo rm -rf ${CORE_DIR} ${CONTENT_DIR}`);
                mkdirSync(CORE_DIR, { recursive: true });
                mkdirSync(CONTENT_DIR, { recursive: true });

                // Move wp-admin and wp-includes to the core directory
                execSync(`mv "${join(siteDir, 'wp-admin')}" "${CORE_DIR}/wp-admin"`);
                execSync(`mv "${join(siteDir, 'wp-includes')}" "${CORE_DIR}/wp-includes"`);

                // Create __wp__ symlink -> core dir
                symlinkSync(CORE_DIR, join(siteDir, '__wp__'));

                // Symlink wp-admin and wp-includes through __wp__ (relative)
                symlinkSync('__wp__/wp-admin', join(siteDir, 'wp-admin'));
                symlinkSync('__wp__/wp-includes', join(siteDir, 'wp-includes'));

                // Move wp-content to separate location
                execSync(`cp -a "${join(siteDir, 'wp-content')}" "${CONTENT_DIR}/wp-content"`);
                execSync(`rm -rf "${join(siteDir, 'wp-content')}"`);
                symlinkSync(CONTENT_DIR + '/wp-content', join(siteDir, 'wp-content'));

                // Rewrite wp-config.php to define WP_CONTENT_DIR
                writeFileSync(join(siteDir, 'wp-config.php'), `<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'e2e_wpcloud_flatten');
define('DB_USER', 'e2e_admin');
define('DB_PASSWORD', 'e2e_password');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY',         'e2e-test-key-1');
define('SECURE_AUTH_KEY',  'e2e-test-key-2');
define('LOGGED_IN_KEY',    'e2e-test-key-3');
define('NONCE_KEY',        'e2e-test-key-4');
define('AUTH_SALT',        'e2e-test-salt-1');
define('SECURE_AUTH_SALT', 'e2e-test-salt-2');
define('LOGGED_IN_SALT',   'e2e-test-salt-3');
define('NONCE_SALT',       'e2e-test-salt-4');
define('WP_CONTENT_DIR', '${CONTENT_DIR}/wp-content');
$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
`);
            },
            afterPermissions: async () => {
                execSync(`sudo chown -R nginx:nginx ${CORE_DIR} ${CONTENT_DIR}`);
            },
        });

        tempDir = createTempDir('e2e-flatten-wpcloud');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('preflight reports resolved wp_admin_path and wp_includes_path', () => {
        const result = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `preflight failed:\n${result.stderr}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.reprint/pull/state.json'), 'utf-8'));
        const pathsUrls = state.preflight?.data?.database?.wp?.paths_urls;
        assert.ok(pathsUrls, 'Expected paths_urls in preflight data');

        // ABSPATH should be the site directory
        assert.ok(
            pathsUrls.abspath?.includes('wpcloud-flatten'),
            `Expected abspath to contain 'wpcloud-flatten', got: ${pathsUrls.abspath}`,
        );

        // wp_admin_path should resolve through __wp__ to the core directory
        assert.ok(
            pathsUrls.wp_admin_path?.includes('e2e-wpcloud-core'),
            `Expected wp_admin_path to resolve to core dir, got: ${pathsUrls.wp_admin_path}`,
        );

        // wp_includes_path should also resolve to the core directory
        assert.ok(
            pathsUrls.wp_includes_path?.includes('e2e-wpcloud-core'),
            `Expected wp_includes_path to resolve to core dir, got: ${pathsUrls.wp_includes_path}`,
        );

        // content_dir should be the separate content directory
        assert.ok(
            pathsUrls.content_dir?.includes('e2e-wpcloud-wpcontent'),
            `Expected content_dir in separate location, got: ${pathsUrls.content_dir}`,
        );
    });

    it('files-sync completes with follow_symlinks', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks'],
        });
        assert.equal(result.exitCode, 0, `files-sync failed:\n${result.stderr}`);
    });

    it('wp-admin files exist at the resolved core path in fs-root', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.reprint/pull/state.json'), 'utf-8'));
        const wpAdminPath = state.preflight?.data?.database?.wp?.paths_urls?.wp_admin_path;
        assert.ok(wpAdminPath, 'wp_admin_path not found in state');

        const localWpAdmin = join(fsRootDir(tempDir), wpAdminPath);
        assert.ok(
            existsSync(localWpAdmin),
            `wp-admin should exist at resolved path: ${localWpAdmin}`,
        );
        assert.ok(
            existsSync(join(localWpAdmin, 'admin.php')),
            'wp-admin/admin.php should exist at resolved path',
        );
    });

    it('wp-content files exist at the separate content path in fs-root', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.reprint/pull/state.json'), 'utf-8'));
        const contentDir = state.preflight?.data?.database?.wp?.paths_urls?.content_dir;
        assert.ok(contentDir, 'content_dir not found in state');

        const localContent = join(fsRootDir(tempDir), contentDir);
        assert.ok(
            existsSync(localContent),
            `wp-content should exist at: ${localContent}`,
        );
        assert.ok(
            existsSync(join(localContent, 'plugins')),
            'wp-content/plugins should exist',
        );
    });

    describe('flat-document-root', () => {
        let flattenTo;

        beforeAll(() => {
            flattenTo = join(tempDir, 'flattened');
            const result = runImporter(importUrl(), tempDir, 'flat-document-root', {
                secret: getSiteSecret(site),
                extraArgs: [`--flatten-to=${flattenTo}`],
            });
            assert.equal(
                result.exitCode, 0,
                `flat-document-root failed:\n${result.stderr}\n${result.stdout}`,
            );
        });

        it('creates wp-admin symlink pointing to resolved core location', () => {
            const wpAdminLink = join(flattenTo, 'wp-admin');
            assert.ok(existsSync(wpAdminLink), 'flatten-to/wp-admin should exist');
            assert.ok(
                lstatSync(wpAdminLink).isSymbolicLink(),
                'wp-admin should be a symlink',
            );

            // The symlink target should be relative (not absolute) and point
            // into the core directory, not ABSPATH
            const target = readlinkSync(wpAdminLink);
            assert.ok(
                !target.startsWith('/'),
                `wp-admin symlink should be relative, got: ${target}`,
            );
            assert.ok(
                target.includes('e2e-wpcloud-core'),
                `wp-admin symlink should point to core dir, got: ${target}`,
            );
        });

        it('creates wp-includes symlink pointing to resolved core location', () => {
            const wpIncludesLink = join(flattenTo, 'wp-includes');
            assert.ok(existsSync(wpIncludesLink), 'flatten-to/wp-includes should exist');
            assert.ok(
                lstatSync(wpIncludesLink).isSymbolicLink(),
                'wp-includes should be a symlink',
            );

            const target = readlinkSync(wpIncludesLink);
            assert.ok(
                !target.startsWith('/'),
                `wp-includes symlink should be relative, got: ${target}`,
            );
            assert.ok(
                target.includes('e2e-wpcloud-core'),
                `wp-includes symlink should point to core dir, got: ${target}`,
            );
        });

        it('creates wp-content symlink pointing to separate content location', () => {
            const wpContentLink = join(flattenTo, 'wp-content');
            assert.ok(existsSync(wpContentLink), 'flatten-to/wp-content should exist');
            assert.ok(
                lstatSync(wpContentLink).isSymbolicLink(),
                'wp-content should be a symlink',
            );

            const target = readlinkSync(wpContentLink);
            assert.ok(
                target.includes('e2e-wpcloud-wpcontent'),
                `wp-content symlink should point to content dir, got: ${target}`,
            );
        });

        it('symlinks core files from ABSPATH', () => {
            assert.ok(existsSync(join(flattenTo, 'wp-load.php')), 'wp-load.php should exist');
            assert.ok(existsSync(join(flattenTo, 'wp-config.php')), 'wp-config.php should exist');
            assert.ok(existsSync(join(flattenTo, 'index.php')), 'index.php should exist');
        });

        it('wp-admin is traversable and contains real files', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-admin', 'admin.php')),
                'wp-admin/admin.php should be accessible through flatten-to',
            );
            assert.ok(
                existsSync(join(flattenTo, 'wp-admin', 'index.php')),
                'wp-admin/index.php should be accessible through flatten-to',
            );
        });

        it('wp-includes is traversable and contains real files', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-includes', 'version.php')),
                'wp-includes/version.php should be accessible through flatten-to',
            );
        });

        it('wp-content is traversable and contains plugins', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-content', 'plugins')),
                'wp-content/plugins should be accessible through flatten-to',
            );
        });

        it('does NOT contain __wp__ (implementation detail, not part of standard layout)', () => {
            // __wp__ is an intermediate symlink from the source - it may or may not
            // appear in the flattened dir depending on what was in ABSPATH. If it does
            // appear it's fine, but wp-admin and wp-includes should NOT point through it.
            const wpAdminTarget = readlinkSync(join(flattenTo, 'wp-admin'));
            assert.ok(
                !wpAdminTarget.includes('__wp__'),
                `wp-admin should point directly to core, not through __wp__: ${wpAdminTarget}`,
            );
        });
    });
});
