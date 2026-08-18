/**
 * Test 61: Preflight with wp-config.php above ABSPATH
 *
 * WordPress supports wp-config.php one directory above its core files.
 * Root discovery must keep walking after finding wp-load.php so the parent
 * configuration directory is also reported to the client.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    mkdirSync,
    readdirSync,
    renameSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';
import {
    apiRequest,
    getSiteDir,
    getSiteUrl,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Preflight with wp-config.php above ABSPATH', () => {
    const site = 'parent-wp-config';

    beforeAll(async () => {
        const siteUrl = new URL(getSiteUrl(site)).origin;
        await ensureSite(site, {
            customDb: async (_dbName, connection) => {
                await connection.query(
                    "UPDATE wp_options SET option_value = ? WHERE option_name = 'home'",
                    [siteUrl],
                );
                await connection.query(
                    "UPDATE wp_options SET option_value = ? WHERE option_name = 'siteurl'",
                    [`${siteUrl}/wordpress`],
                );
            },
            afterCreate: async (siteDir) => {
                const wordpressDir = join(siteDir, 'wordpress');
                mkdirSync(wordpressDir);

                for (const entry of readdirSync(siteDir)) {
                    if (entry === 'wordpress' || entry === 'wp-config.php') {
                        continue;
                    }
                    renameSync(join(siteDir, entry), join(wordpressDir, entry));
                }

                writeFileSync(
                    join(siteDir, 'index.php'),
                    `<?php
define( 'WP_USE_THEMES', true );
require __DIR__ . '/wordpress/wp-blog-header.php';
`,
                );
                // Make the post-provision file move visible to PHP-FPM immediately.
                writeFileSync(
                    join(siteDir, '.user.ini'),
                    'opcache.revalidate_freq=0\n',
                );
            },
        });
    });

    it('reports both the WordPress core and its parent configuration root', async () => {
        const siteDir = getSiteDir(site);
        const wordpressDir = join(siteDir, 'wordpress');
        const response = await apiRequest(site, 'preflight');

        assert.equal(
            response.status,
            200,
            `Expected HTTP 200, got ${response.status}: ${JSON.stringify(response.json || response.text)}`,
        );
        assert.equal(response.json.ok, true, `Preflight failed: ${response.json.error}`);
        assert.equal(response.json.database.connected, true, 'Expected WordPress database connection');
        assert.equal(response.json.database.wp.paths_urls.abspath, wordpressDir);

        const wordpressRoot = response.json.wp_detect.roots.find(
            (root) => root.path === wordpressDir,
        );
        assert.ok(wordpressRoot, `Expected a WordPress core root at ${wordpressDir}`);
        assert.equal(wordpressRoot.wp_load, true);
        assert.equal(wordpressRoot.wp_config, false);

        const configRoot = response.json.wp_detect.roots.find(
            (root) => root.path === siteDir,
        );
        assert.ok(configRoot, `Expected a parent configuration root at ${siteDir}`);
        assert.equal(configRoot.wp_load, false);
        assert.equal(configRoot.wp_config, true);
        assert.equal(configRoot.wp_config_path, join(siteDir, 'wp-config.php'));
    });
});
