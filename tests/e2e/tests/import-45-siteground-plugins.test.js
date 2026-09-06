/**
 * Test 45: Migrate the complete source-host plugin inventory.
 *
 * The source contains every named exclusion, nested plugin files, the copied
 * WP Engine loader, and unrelated files with similar names. Check that files-pull
 * omits excluded paths, db-apply deactivates excluded regular plugins, and
 * apply-runtime removes stale copies without changing unrelated files. Finally,
 * boot the imported WordPress site against its target database.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { basename, dirname, join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, createMysqlConnection, pullStateDirectory, fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const CLIENT_PATH = process.env.CLIENT_PATH || join(PROJECT_ROOT, 'packages', 'reprint-client', 'bin', 'reprint-client');

// Keep the expected paths independent of excluded_plugins(): removing a rule
// must make this test fail rather than silently remove its source fixture too.
const EXCLUDED_PATHS = [
    'wp-content/plugins/nginx-helper',
    'wp-content/plugins/redis-cache',
    'wp-content/plugins/breeze',
    'wp-content/plugins/object-cache-pro',
    'wp-content/plugins/wp-rocket',
    'wp-content/plugins/w3-total-cache',
    'wp-content/plugins/servebolt-optimizer',
    'wp-content/plugins/a2-optimized-wp',
    'wp-content/plugins/boldgrid-backup',
    'wp-content/plugins/litespeed-cache',
    'wp-content/plugins/aruba-hispeed-cache',
    'wp-content/mu-plugins/aruba-wpchecker.php',
    'wp-content/mu-plugins/aruba-wpchecker',
    'wp-content/mu-plugins/kinsta-mu-plugins.php',
    'wp-content/mu-plugins/kinsta-mu-plugins',
    'wp-content/mu-plugins/ionos-core.php',
    'wp-content/mu-plugins/ionos-core',
    'wp-content/mu-plugins/stretch-extra.php',
    'wp-content/mu-plugins/stretch-extra',
    'wp-content/plugins/ionos-essentials',
    'wp-content/plugins/ionos-wpdev-caddy',
    'wp-content/mu-plugins/pcm-extend-batcache.php',
    'wp-content/mu-plugins/pcm-exclude-pages-from-batcache.php',
    'wp-content/plugins/pressable-cache-management',
    'wp-content/plugins/pressable-onepress-login',
    'wp-content/mu-plugins/gd-system-plugin.php',
    'wp-content/mu-plugins/gd-system-plugin',
    'wp-content/plugins/bluehost-wordpress-plugin',
    'wp-content/mu-plugins/endurance-page-cache.php',
    'wp-content/mu-plugins/endurance-browser-cache.php',
    'wp-content/plugins/wp-plugin-hostgator',
    'wp-content/plugins/hostinger',
    'wp-content/plugins/hostinger-easy-onboarding',
    'wp-content/mu-plugins/hostinger-mu-plugin.php',
    'wp-content/mu-plugins/nexcess-mapps.php',
    'wp-content/mu-plugins/nexcess-mapps',
    'wp-content/mu-plugins/cdn-cache-management.php',
    'wp-content/plugins/spinupwp',
    'wp-content/mu-plugins/vip-go-mu-plugins',
    'wp-content/plugins/wp-engine-smart-plugin-manager',
    'wp-content/mu-plugins/wpengine-common',
    'wp-content/mu-plugins/slt-force-strong-passwords.php',
    'wp-content/mu-plugins/force-strong-passwords',
    'wp-content/mu-plugins/stop-long-comments.php',
    'wp-content/mu-plugins/wpe-cache-plugin',
    'wp-content/mu-plugins/wpe-cache-plugin.php',
    'wp-content/mu-plugins/wpe-update-source-selector',
    'wp-content/mu-plugins/wpe-update-source-selector.php',
    'wp-content/mu-plugins/wpe-wp-sign-on-plugin',
    'wp-content/mu-plugins/wpe-wp-sign-on-plugin.php',
    'wp-content/mu-plugins/wpengine-security-auditor.php',
    'wp-content/plugins/sg-cachepress',
    'wp-content/plugins/sg-security',
    'wp-content/mu-plugins/wpcomsh',
    'wp-content/mu-plugins/wpcomsh-dev',
    'wp-content/mu-plugins/wpcomsh-loader.php',
    // The copied WP Engine loader still requires its package after moving hosts.
    'wp-content/mu-plugins/mu-plugin.php',
];
const EXCLUDED_REGULAR_PLUGINS = EXCLUDED_PATHS
    .filter(path => path.startsWith('wp-content/plugins/'))
    .map(path => `${basename(path)}/${basename(path)}.php`);
const KEPT_PLUGIN = 'reprint-kept-plugin/reprint-kept-plugin.php';
const KEPT_FILES = new Map([
    [`wp-content/plugins/${KEPT_PLUGIN}`, "<?php\n/* Plugin Name: Kept plugin */\ndefine('REPRINT_KEPT_PLUGIN', true);"],
    ['wp-content/mu-plugins/custom.php', "<?php define('REPRINT_KEPT_MU_PLUGIN', true);"],
    // Pantheon's loader requires its package even outside Pantheon.
    ['wp-content/mu-plugins/loader.php', "<?php require_once WPMU_PLUGIN_DIR . '/pantheon-mu-plugin/pantheon.php';"],
    ['wp-content/mu-plugins/pantheon-mu-plugin/pantheon.php', "<?php define('REPRINT_PANTHEON_FIXTURE_LOADED', true);"],
    // An ordinary source host does not opt into generic cache drop-in removal.
    ['wp-content/object-cache.php', '<?php // Custom object-cache drop-in'],
    ['wp-content/advanced-cache.php', '<?php // Custom advanced-cache drop-in'],
    ['wp-content/uploads/host-plugin-example.txt', 'Uploaded content must survive.'],
    ...EXCLUDED_PATHS.map(path => [
        path.endsWith('.php') ? `${path}-keep` : `${path}-keep/nested/file.txt`,
        `A similar name is not an excluded path: ${path}`,
    ]),
]);

describe.each([
    ['other', 'siteground-plugins'],
    ['wpengine', 'wpengine-plugin-inventory'],
])('Import: complete source-host plugin inventory (%s)', (host, site) => {
    const excludedPaths = host === 'wpengine'
        ? [...EXCLUDED_PATHS, 'wp-content/object-cache.php', 'wp-content/advanced-cache.php']
        : EXCLUDED_PATHS;
    const keptFiles = new Map([...KEPT_FILES].filter(([path]) => !excludedPaths.includes(path)));
    let tempDir;
    let runtimeDir;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'standard',
            files: 'sample',
            afterCreate: async (siteDir, dbName) => {
                writeExcludedPluginFiles(siteDir, excludedPaths);
                for (const [path, contents] of keptFiles) {
                    mkdirSync(dirname(join(siteDir, path)), { recursive: true });
                    writeFileSync(join(siteDir, path), contents);
                }

                if (host === 'wpengine') {
                    // Give the source request WP Engine's working-directory layout.
                    // Preflight reads the real cwd; no saved host state is injected.
                    const sourceWorkingDirectory = `/nas/content/live/${site}`;
                    execFileSync('sudo', ['mkdir', '-p', sourceWorkingDirectory]);
                    const configPath = join(siteDir, 'wp-config.php');
                    writeFileSync(configPath, readFileSync(configPath, 'utf-8').replace(
                        '<?php', `<?php\nchdir('${sourceWorkingDirectory}');`,
                    ));
                }

                // Activate every excluded regular plugin and the custom plugin so they appear
                // in active_plugins when the DB is exported.
                const { createConnection } = await import('mysql2/promise');
                const conn = await createConnection({
                    host: '127.0.0.1',
                    user: 'e2e_admin',
                    password: 'e2e_password',
                    database: dbName,
                });
                const [rows] = await conn.query(
                    "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'"
                );
                let plugins = [];
                if (rows.length > 0 && rows[0].option_value) {
                    // Parse PHP serialized array using a simple regex approach.
                    // We know the format is a:N:{...} with s:LEN:"value"; entries.
                    const raw = rows[0].option_value;
                    const matches = [...raw.matchAll(/s:\d+:"([^"]+)"/g)];
                    plugins = matches.map(m => m[1]);
                }
                plugins.push(...EXCLUDED_REGULAR_PLUGINS, KEPT_PLUGIN);

                // Serialize as PHP array: a:N:{i:0;s:LEN:"value";...}
                const entries = plugins.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('');
                const serialized = `a:${plugins.length}:{${entries}}`;

                await conn.query(
                    "UPDATE wp_options SET option_value = ? WHERE option_name = 'active_plugins'",
                    [serialized]
                );
                await conn.end();
            },
        });

        tempDir = createTempDir('e2e-siteground-plugins');
        runtimeDir = join(tempDir, 'runtime');
    }, 120000);

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('preflight selects the host from server details, not plugin filenames', () => {
        const result = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `preflight failed:\n${result.stderr}`);

        const state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, importUrl()), 'state.json'),
            'utf-8',
        ));
        assert.equal(state.webhost, host,
            `Expected webhost '${host}', got '${state.webhost}'`);
    });

    it('files-pull omits the excluded plugins', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `files-pull failed:\n${result.stderr}`);

        const pulledSite = join(fsRootDir(tempDir), getSiteDir(site));
        for (const path of excludedPaths) {
            assert.ok(existsSync(join(getSiteDir(site), path)), `Source fixture is missing: ${path}`);
            assert.ok(!existsSync(join(pulledSite, path)), `${path} should not be downloaded`);
        }
        for (const [path, contents] of keptFiles) {
            assert.equal(readFileSync(join(pulledSite, path), 'utf-8'), contents, `${path} should be downloaded unchanged`);
        }
    });

    it('db-pull downloads the SQL dump', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `db-pull failed:\n${result.stderr}`);
        assert.ok(existsSync(join(tempDir, 'db.sql')), 'db.sql should exist');
    });

    describe('db-apply deactivates every excluded regular plugin', () => {
        beforeAll(async () => {
            // Create the target database before db-apply connects to it.
            const importDb = `${getDbName(site)}_import`;
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.query(`CREATE DATABASE \`${importDb}\``);
            await conn.end();

            const sourceDomain = new URL(getSiteUrl(site)).origin;
            const result = runImporter(importUrl(), tempDir, 'db-apply', {
                secret: getSiteSecret(site),
                extraArgs: [
                    '--target-engine=mysql',
                    `--target-db=${getDbName(site)}_import`,
                    '--target-user=e2e_admin',
                    '--target-pass=e2e_password',
                    '--target-host=127.0.0.1',
                    '--rewrite-url', sourceDomain, `http://127.0.0.1:9999`,
                ],
            });
            assert.equal(result.exitCode, 0, `db-apply failed:\n${result.stderr}`);
        });

        it('only excluded regular plugins are removed from active_plugins', () => {
            const importDb = `${getDbName(site)}_import`;

            // Query using PHP to use the same MySQL driver as the importer.
            const raw = execFileSync('php', ['-r', `
                \$pdo = new PDO('mysql:host=127.0.0.1;dbname=${importDb};charset=utf8mb4', 'e2e_admin', 'e2e_password');
                \$stmt = \$pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'");
                echo \$stmt->fetchColumn();
            `], { encoding: 'utf-8', timeout: 10000 }).trim();

            assert.ok(raw.length > 0, 'active_plugins option should exist');
            for (const plugin of EXCLUDED_REGULAR_PLUGINS) {
                assert.ok(!raw.includes(plugin), `Excluded plugin is still active: ${plugin}`);
            }
            // Confirm non-host plugins survived in active_plugins too.
            assert.ok(raw.includes(KEPT_PLUGIN), 'The custom plugin should stay active');
            assert.ok(raw.includes('reprint-server'), 'Reprint Server should stay active');
        });

        it('audit log records the deactivations', () => {
            const auditLog = readFileSync(join(tempDir, 'audit.log'), 'utf-8');
            for (const plugin of EXCLUDED_REGULAR_PLUGINS) {
                assert.ok(auditLog.includes(`deactivated plugin ${plugin}`),
                    `Audit log should record deactivation of ${plugin}`);
            }
        });
    });

    describe('apply-runtime removes every excluded path', () => {
        beforeAll(() => {
            const flatDir = join(tempDir, 'flattened');
            const flatResult = runImporter(importUrl(), tempDir, 'flat-docroot', {
                secret: getSiteSecret(site),
                extraArgs: [`--flatten-to=${flatDir}`],
            });
            assert.equal(flatResult.exitCode, 0,
                `flat-docroot failed:\n${flatResult.stderr}`);

            // Simulate copies left by an older import. Download filtering
            // cannot remove files which already exist locally.
            writeExcludedPluginFiles(flatDir, excludedPaths);

            execFileSync('php', [
                CLIENT_PATH,
                'apply-runtime',
                importUrl(),
                `--state-dir=${tempDir}`,
                `--flat-document-root=${flatDir}`,
                `--runtime=php-builtin`,
                `--output-dir=${runtimeDir}`,
                `--port=9999`,
            ], {
                encoding: 'utf-8',
                timeout: 30000,
            });
        });

        it('all excluded paths and their nested files are absent after migration', () => {
            const flatDir = join(tempDir, 'flattened');
            for (const path of excludedPaths) {
                assert.ok(!existsSync(join(flatDir, path)), `${path} should be absent after migration`);
                assert.ok(existsSync(join(getSiteDir(site), path)), `Migration must not remove the source path: ${path}`);
            }
            const state = JSON.parse(readFileSync(
                join(pullStateDirectory(tempDir, importUrl()), 'state.json'), 'utf-8',
            ));
            assert.deepEqual(state.apply.remote_paths_removed_from_local_site.sort(), [...excludedPaths].sort(),
                'The source fixtures must cover the complete exclusion list');
        });

        it('unrelated paths, including similar names, are unchanged after migration', () => {
            const flatDir = join(tempDir, 'flattened');
            for (const [path, contents] of keptFiles) {
                assert.equal(readFileSync(join(flatDir, path), 'utf-8'), contents, `${path} should survive migration unchanged`);
            }
            assert.ok(existsSync(join(flatDir, 'wp-content/plugins/reprint-server')));
        });

        it('the Pantheon loader can load its package after cleanup', () => {
            const muPluginsDir = join(tempDir, 'flattened', 'wp-content', 'mu-plugins');
            const output = execFileSync('php', ['-r', `
                define('WPMU_PLUGIN_DIR', $argv[1]);
                require WPMU_PLUGIN_DIR . '/loader.php';
                echo REPRINT_PANTHEON_FIXTURE_LOADED ? 'loaded' : 'missing';
            `, muPluginsDir], { encoding: 'utf-8', timeout: 10000 });
            assert.equal(output, 'loaded');
        });

        it('the migrated WordPress site boots with the retained plugins and target database', () => {
            const flatDir = join(tempDir, 'flattened');
            const output = execFileSync('php', ['-r', `
                $_SERVER['DOCUMENT_ROOT'] = $argv[1];
                $_SERVER['REQUEST_URI'] = '/wp-load.php';
                require $argv[2];
                echo json_encode(array(
                    'database' => DB_NAME,
                    'active_plugins' => get_option('active_plugins'),
                    'kept_plugin' => defined('REPRINT_KEPT_PLUGIN'),
                    'kept_mu_plugin' => defined('REPRINT_KEPT_MU_PLUGIN'),
                    'pantheon_package' => defined('REPRINT_PANTHEON_FIXTURE_LOADED'),
                ));
            `, flatDir, join(runtimeDir, 'runtime.php')], { encoding: 'utf-8', timeout: 30000 });
            const boot = JSON.parse(output);
            assert.equal(boot.database, `${getDbName(site)}_import`);
            assert.ok(boot.kept_plugin && boot.kept_mu_plugin && boot.pantheon_package);
            assert.ok(boot.active_plugins.includes(KEPT_PLUGIN));
            for (const plugin of EXCLUDED_REGULAR_PLUGINS) {
                assert.ok(!boot.active_plugins.includes(plugin), `${plugin} should not be active after boot`);
            }
        });

        it('the copied WP Engine loader and package are removed together', () => {
            const muPluginsDir = join(tempDir, 'flattened', 'wp-content', 'mu-plugins');
            const output = execFileSync('php', ['-r', `
                define('WPMU_PLUGIN_DIR', $argv[1]);
                foreach (glob(WPMU_PLUGIN_DIR . '/*.php') as $plugin) {
                    require $plugin;
                }
                echo 'loaded';
            `, muPluginsDir], { encoding: 'utf-8', timeout: 10000 });
            assert.equal(output, 'loaded');
            assert.ok(!existsSync(join(muPluginsDir, 'mu-plugin.php')));
            assert.ok(!existsSync(join(muPluginsDir, 'wpengine-common')));
        });
    });
});

// Use harmless PHP fixtures, not the real host services. The same complete tree
// is installed on the source and as stale local files before apply-runtime.
// Regular plugins have valid plugin headers so WordPress recognizes them.
function writeExcludedPluginFiles(root, paths) {
    for (const path of paths) {
        const absolutePath = join(root, path);
        if (path.endsWith('.php')) {
            mkdirSync(dirname(absolutePath), { recursive: true });
            const contents = path.endsWith('/mu-plugin.php')
                ? "<?php require_once __DIR__ . '/wpengine-common/plugin.php';"
                : '<?php // Source-host MU-plugin fixture';
            writeFileSync(absolutePath, contents);
        } else {
            mkdirSync(join(absolutePath, 'nested/assets/empty'), { recursive: true });
            const entry = path.startsWith('wp-content/plugins/') ? basename(path) : 'plugin';
            writeFileSync(join(absolutePath, `${entry}.php`), `<?php\n/* Plugin Name: ${entry} fixture */\n`);
            writeFileSync(join(absolutePath, 'nested/assets/style.css'), 'body { color: red; }');
            writeFileSync(join(absolutePath, 'nested/payload.bin'), Buffer.alloc(128 * 1024, 0xa5));
        }
    }
}
