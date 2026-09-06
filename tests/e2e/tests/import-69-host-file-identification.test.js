/** Exercise real preflight and cleanup with platform, customer, and copied host files. */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { ensureSite } from '../lib/site-setup.js';
import { apiRequest, getSiteDir, getSiteUrl, pullStateDirectory } from '../lib/test-helpers.js';
import { withMigratedWordPress } from '../lib/migrated-wordpress.js';

describe('Import: identify host files before removal', () => {
    const sites = ['wpengine-recognized-loader', 'wpengine-customer-loader', 'wpengine-copied-plugins'];
    const portablePlugin = `<?php
/** Plugin Name: Portable customer cache */
add_action('wp_footer', function () { echo '<p>PORTABLE_PLUGIN_RUNNING</p>'; });
`;
    const customerLoader = `<?php
/** Plugin Name: Customer site tools */
add_action('wp_footer', function () { echo '<p>CUSTOMER_LOADER_RUNNING</p>'; });
`;

    const legacyPlugin = Buffer.from(`<?php
/** Plugin Name: Café customer tools */
add_action('wp_footer', function () { echo '<p>LEGACY_CUSTOMER_RUNNING</p>'; });
`, 'latin1');

    beforeAll(async () => {
        for (const site of sites) {
            await ensureSite(site, {
                files: 'none',
                afterCreate: async (siteDirectory) => {
                    const muPlugins = join(siteDirectory, 'wp-content/mu-plugins');
                    mkdirSync(join(muPlugins, 'wpengine-common'), { recursive: true });
                    const recognized = site === sites[0];
                    const bootstrap = recognized
                        ? `<?php
if (strpos(ABSPATH, '/nas/') !== 0) { throw new RuntimeException('PLATFORM_LOADER_LEFT_ON_TARGET'); }
add_action('wp_footer', function () { echo '<p>PLATFORM_LOADER_RUNNING</p>'; });
`
                        : `<?php
add_action('wp_footer', function () { echo '<p>CUSTOMER_DEPENDENCY_RUNNING</p>'; });
`;
                    writeFileSync(join(muPlugins, 'wpengine-common/bootstrap.php'), bootstrap);
                    writeFileSync(join(muPlugins, 'mu-plugin.php'), customerLoader
                        + (recognized ? '' : "require_once __DIR__ . '/wpengine-common/bootstrap.php';\n"));
                    if (recognized) {
                        writeFileSync(join(muPlugins, 'renamed-platform-loader.php'), `<?php
/** Plugin Name: WP Engine System */
$private_value = 'MU_PLUGIN_CODE_MUST_NOT_ENTER_PREFLIGHT';
require_once __DIR__ . '/wpengine-common/bootstrap.php';
`);
                    }
                    if (site === sites[2]) {
                        writeFileSync(join(muPlugins, 'legacy-customer.php'), legacyPlugin);
                    }
                    const plugins = join(siteDirectory, 'wp-content/plugins/portable-cache');
                    mkdirSync(plugins, { recursive: true });
                    writeFileSync(join(plugins, 'portable-cache.php'), portablePlugin);
                },
                afterPermissions: async (siteDirectory) => {
                    if (site !== sites[2]) {
                        // Keep nginx's configured entry path, but put WordPress
                        // on a real WP Engine-style filesystem path. Preflight
                        // must discover it over HTTP; no saved state is edited.
                        const parent = site === sites[0] ? '/nas/content/live' : '/nas/wp/www';
                        const physicalDirectory = join(parent, `e2e-${site}`);
                        execFileSync('sudo', ['mkdir', '-p', parent]);
                        execFileSync('sudo', ['mv', siteDirectory, physicalDirectory]);
                        execFileSync('sudo', ['ln', '-s', physicalDirectory, siteDirectory]);
                    }
                    execFileSync(process.env.E2E_WP_CLI_PHP_BINARY || 'php', [
                        '/tmp/wp-cli.phar', 'plugin', 'activate', 'portable-cache', `--path=${realpathSync(siteDirectory)}`,
                        ...(process.getuid?.() === 0 ? ['--allow-root'] : []),
                    ], { timeout: 60000, stdio: 'pipe' });
                },
            });
        }
    });

    it('removes the renamed platform loader but keeps customer code and an active portable plugin', async () => {
        const source = await fetch(new URL(getSiteUrl(sites[0])).origin);
        assert.equal(source.status, 200);
        assert.ok((await source.text()).includes('PLATFORM_LOADER_RUNNING'), 'The source platform loader must actually run');
        await withMigratedWordPress(sites[0], ({ temporaryDirectory, flatDirectory, importUrl, html }) => {
            const state = JSON.parse(readFileSync(join(pullStateDirectory(temporaryDirectory, importUrl), 'state.json'), 'utf8'));
            assert.equal(state.webhost, 'wpengine');
            const plugins = state.preflight.data.wp_content.roots.flatMap(root => root.mu_plugins);
            assert.equal(plugins.find(plugin => plugin.name === 'renamed-platform-loader.php').headers.name, 'WP Engine System');
            assert.ok(!JSON.stringify(state.preflight.data).includes('MU_PLUGIN_CODE_MUST_NOT_ENTER_PREFLIGHT'));
            const muPlugins = join(flatDirectory, 'wp-content/mu-plugins');
            assert.ok(!existsSync(join(muPlugins, 'renamed-platform-loader.php')));
            assert.ok(!existsSync(join(muPlugins, 'wpengine-common')));
            assert.equal(readFileSync(join(muPlugins, 'mu-plugin.php'), 'utf8'), customerLoader);
            assert.equal(readFileSync(join(flatDirectory, 'wp-content/plugins/portable-cache/portable-cache.php'), 'utf8'), portablePlugin);
            assert.ok(html.includes('CUSTOMER_LOADER_RUNNING'));
            assert.ok(html.includes('PORTABLE_PLUGIN_RUNNING'), 'The portable plugin must stay active, not merely remain on disk');
            assert.ok(!html.includes('PLATFORM_LOADER_RUNNING'));
            assert.ok(existsSync(join(getSiteDir(sites[0]), 'wp-content/mu-plugins/renamed-platform-loader.php')));
        });
    }, 240000);

    it('keeps an unknown loader and its dependency together on WP Engine', async () => {
        await withMigratedWordPress(sites[1], ({ temporaryDirectory, flatDirectory, importUrl, html }) => {
            const state = JSON.parse(readFileSync(join(pullStateDirectory(temporaryDirectory, importUrl), 'state.json'), 'utf8'));
            assert.equal(state.webhost, 'wpengine');
            for (const path of ['mu-plugin.php', 'wpengine-common/bootstrap.php']) {
                assert.equal(readFileSync(join(flatDirectory, 'wp-content/mu-plugins', path), 'utf8'),
                    readFileSync(join(getSiteDir(sites[1]), 'wp-content/mu-plugins', path), 'utf8'));
            }
            assert.ok(html.includes('CUSTOMER_LOADER_RUNNING'));
            assert.ok(html.includes('CUSTOMER_DEPENDENCY_RUNNING'), 'Keeping a loader without its required file would break this request');
            assert.ok(html.includes('PORTABLE_PLUGIN_RUNNING'));
        });
    }, 240000);

    it('does not mistake copied WP Engine filenames on another host for the current host', async () => {
        await withMigratedWordPress(sites[2], ({ temporaryDirectory, flatDirectory, importUrl, html }) => {
            const state = JSON.parse(readFileSync(join(pullStateDirectory(temporaryDirectory, importUrl), 'state.json'), 'utf8'));
            assert.equal(state.webhost, 'other');
            assert.ok(existsSync(join(flatDirectory, 'wp-content/mu-plugins/wpengine-common/bootstrap.php')));
            assert.ok(html.includes('CUSTOMER_LOADER_RUNNING'));
            assert.ok(html.includes('CUSTOMER_DEPENDENCY_RUNNING'));
            assert.ok(html.includes('PORTABLE_PLUGIN_RUNNING'));
        });
    }, 240000);

    it('migrates a working MU plugin whose public name contains Windows-1252 bytes', async () => {
        const site = sites[2];
        const sourcePath = join(getSiteDir(site), 'wp-content/mu-plugins/legacy-customer.php');
        const source = await fetch(new URL(getSiteUrl(site)).origin);
        assert.equal(source.status, 200);
        assert.ok((await source.text()).includes('LEGACY_CUSTOMER_RUNNING'));
        const preflight = await apiRequest(site, 'preflight');
        assert.equal(preflight.status, 200, JSON.stringify(preflight.json));
        const plugins = preflight.json.wp_content.roots.flatMap(root => root.mu_plugins);
        assert.deepEqual(plugins.find(entry => entry.name === 'legacy-customer.php').headers, []);
        await withMigratedWordPress(site, ({ flatDirectory, html }) => {
            assert.deepEqual(readFileSync(join(flatDirectory, 'wp-content/mu-plugins/legacy-customer.php')), legacyPlugin);
            assert.ok(html.includes('LEGACY_CUSTOMER_RUNNING'));
            assert.deepEqual(readFileSync(sourcePath), legacyPlugin);
        });
    }, 240000);
});
