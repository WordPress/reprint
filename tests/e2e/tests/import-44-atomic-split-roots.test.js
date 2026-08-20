/**
 * Test 44: file_index with Atomic-style split roots
 *
 * Simulates the wp.com Atomic directory layout where ABSPATH (__wp__)
 * is a child of the document root, and the site's actual wp-content
 * lives at the document root level.
 *
 * The exporter must traverse BOTH roots:
 *   - __wp__/ for WordPress core files
 *   - siteDir/ for the site's wp-content (plugins, themes, uploads)
 *
 * Before the fix (#111), should_skip_index_root() pruned siteDir from
 * the traversal list because it's a parent of __wp__, causing the entire
 * site wp-content to be missing from the index.
 *
 * This test skips full WordPress boot — it loads only the server plugin
 * and exercises the file_index endpoint directly.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readdirSync,
    mkdirSync, writeFileSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    createTempDir, cleanupTempDir,
    getSiteUrl, getSiteDir,
    apiRequest,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Atomic-style split roots (__wp__ + document root)', () => {
    const site = 'atomic-split-roots';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'none',
            files: 'none',
            afterCreate: async (siteDir) => {
                const wpDir = join(siteDir, '__wp__');
                mkdirSync(wpDir, { recursive: true });

                // Move the entire WordPress install into __wp__
                for (const entry of readdirSync(siteDir)) {
                    if (entry === '__wp__' || entry === '.e2e-provisioned') {
                        continue;
                    }
                    execSync(`mv "${join(siteDir, entry)}" "${join(wpDir, entry)}"`);
                }

                // Create document root files
                writeFileSync(join(siteDir, 'wp-load.php'), '<?php /* stub */ ?>');
                writeFileSync(join(siteDir, 'wp-config.php'), '<?php /* stub config */ ?>');

                // index.php loads Reprint Server directly, bypassing WordPress.
                // lib.php's namespaced request handler authenticates
                // via HMAC and dispatches the request — same path as the
                // WordPress plugin, just without WP bootstrapped.
                writeFileSync(join(siteDir, 'index.php'), `<?php
define('ABSPATH', __DIR__ . '/__wp__/');
define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', __DIR__ . '/wp-content/plugins/reprint-server/');
define('WordPress\\Reprint\\Server\\Plugin\\SECRET_FILE', constant('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR') . 'secret.php');
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(\$file) { return rtrim(dirname(\$file), '/') . '/'; }
}
require_once constant('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR') . 'lib.php';
\\WordPress\\Reprint\\Server\\Plugin\\handle_api_request();
`);

                // Create site's own wp-content
                mkdirSync(join(siteDir, 'wp-content', 'plugins', 'reprint-server'), { recursive: true });
                mkdirSync(join(siteDir, 'wp-content', 'themes'), { recursive: true });

                // Copy server plugin
                const pluginSrc = join(wpDir, 'wp-content', 'plugins', 'reprint-server');
                if (existsSync(pluginSrc)) {
                    execSync(`cp -a "${pluginSrc}/." "${join(siteDir, 'wp-content', 'plugins', 'reprint-server')}/"`)
                }
                writeFileSync(
                    join(siteDir, 'wp-content', 'plugins', 'reprint-server', 'secret.php'),
                    `<?php return 'test-secret-${site}';\n`
                );

                // Marker files in site's wp-content
                writeFileSync(join(siteDir, 'wp-content', 'plugins', 'hello.php'),
                    '<?php /* Plugin Name: Hello */ ?>');
                writeFileSync(join(siteDir, 'wp-content', 'themes', 'site-theme.txt'),
                    'site theme marker');
            },
        });

        tempDir = createTempDir('e2e-atomic-split-roots');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('file_index includes files from both __wp__ and the document root', async () => {
        const wpDir = `${getSiteDir(site)}/__wp__`;
        const docRoot = getSiteDir(site);

        // Build the URL with array-style directory[] params manually,
        // then pass it to apiRequest via the url option.
        const url = `${getSiteUrl(site)}&directory%5B%5D=${encodeURIComponent(wpDir)}&directory%5B%5D=${encodeURIComponent(docRoot)}`;
        const response = await apiRequest(site, 'file_index', {
            list_dir: wpDir,
            follow_symlinks: '1',
            batch_size: '50000',
        }, { url });

        assert.equal(response.status, 200,
            `file_index failed: ${JSON.stringify(response.json || response.text || '').slice(0, 500)}`);

        // Collect all paths from index_batch chunks
        const paths = [];
        for (const chunk of response.chunks || []) {
            if (chunk.type !== 'index_batch') continue;
            const entries = chunk.json || [];
            for (const entry of (Array.isArray(entries) ? entries : [entries])) {
                const rawPath = entry.path;
                if (rawPath) {
                    paths.push(Buffer.from(rawPath, 'base64').toString('utf-8'));
                }
            }
        }

        assert.ok(paths.length > 0, 'Expected paths in file_index response');

        // WordPress core files from __wp__ should be present
        const hasWpAdmin = paths.some(p => p.includes('__wp__/wp-admin'));
        assert.ok(hasWpAdmin,
            `Index should include wp-admin from __wp__. Sample paths: ${paths.slice(0, 5).join(', ')}`);

        const hasWpIncludes = paths.some(p => p.includes('__wp__/wp-includes'));
        assert.ok(hasWpIncludes, 'Index should include wp-includes from __wp__');

        // Site's wp-content from the document root MUST be present.
        // This is the critical assertion — before the fix, the exporter
        // pruned the document root and these files were completely missing.
        const sitePluginPaths = paths.filter(p =>
            p.includes('/wp-content/plugins/hello.php') && !p.includes('__wp__')
        );
        assert.ok(sitePluginPaths.length > 0,
            'Site plugin hello.php must appear in index outside __wp__');

        const siteThemePaths = paths.filter(p =>
            p.includes('/wp-content/themes/site-theme.txt') && !p.includes('__wp__')
        );
        assert.ok(siteThemePaths.length > 0,
            'Site theme marker must appear in index outside __wp__');
    });
});
