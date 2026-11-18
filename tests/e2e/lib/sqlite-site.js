/**
 * WordPress source sites that store their data with the SQLite Database
 * Integration plugin, for test 35.
 */
import { existsSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';
import { getSiteUrl } from './test-helpers.js';
import { ensureSite } from './site-setup.js';
import { convertToMultisite } from './multisite-setup.js';

export async function ensureSqliteSite(site, pluginVersion, multisite = false) {
    await ensureSite(site, {
        db: 'none',
        files: 'sample',
        afterCreate: async (siteDir) => {
            const pluginsDir = join(siteDir, 'wp-content', 'plugins');
            const pluginDir = join(pluginsDir, 'sqlite-database-integration');
            const pluginZip = `/tmp/sqlite-database-integration-${pluginVersion}.zip`;

            // Pin the plugin version so this test always exercises the intended driver.
            // Test files run in parallel, so download to a private name and
            // rename it into place. No file can unzip a partial download.
            if (!existsSync(pluginZip)) {
                execSync(
                    `curl -sfL "https://downloads.wordpress.org/plugin/` +
                    `sqlite-database-integration.${pluginVersion}.zip" -o "${pluginZip}.${process.pid}"` +
                    ` && mv "${pluginZip}.${process.pid}" "${pluginZip}"`,
                    { timeout: 120000 },
                );
            }
            if (!existsSync(pluginDir)) {
                try {
                    execSync(
                        `unzip -qo "${pluginZip}" -d "${pluginsDir}"`,
                        { timeout: 30000 },
                    );
                } catch {
                    // unzip is not present on every runner, so use PHP's ZipArchive there.
                    execSync(
                        `php -r "\\$z = new ZipArchive;` +
                        ` \\$z->open('${pluginZip}');` +
                        ` \\$z->extractTo('${pluginsDir}');` +
                        ` \\$z->close();"`,
                        { timeout: 30000 },
                    );
                }
            }

            // Create the db.php drop-in that activates the SQLite backend.
            // This mimics what the plugin writes when activated through the
            // WordPress admin UI (based on the plugin's db.copy template).
            writeFileSync(join(siteDir, 'wp-content', 'db.php'), [
                '<?php',
                "define('SQLITE_DB_DROPIN_VERSION', '1.8.0');",
                "$sqlite_plugin_implementation_folder_path = __DIR__ . '/plugins/sqlite-database-integration';",
                "if (!defined('DATABASE_TYPE')) define('DATABASE_TYPE', 'sqlite');",
                "if (!defined('DB_ENGINE')) define('DB_ENGINE', 'sqlite');",
                "require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php';",
                '',
            ].join('\n'));

            // Create the database directory where the .ht.sqlite file will live.
            mkdirSync(join(siteDir, 'wp-content', 'database'), { recursive: true });

            // Write a full wp-config.php with SQLite constants.
            // DB_HOST/DB_NAME/DB_USER/DB_PASSWORD are required by WordPress's
            // config structure but are unused when the db.php drop-in is active.
            writeFileSync(join(siteDir, 'wp-config.php'), [
                '<?php',
                "define('DB_HOST', 'unused');",
                "define('DB_NAME', 'wordpress');",
                "define('DB_USER', 'unused');",
                "define('DB_PASSWORD', 'unused');",
                "define('DB_CHARSET', 'utf8mb4');",
                // WP-CLI can spawn HTTP cron requests before multisite conversion
                // and SQLite permissions are ready. Keep this source stable.
                "define('DISABLE_WP_CRON', true);",
                // The multisite matrix also imports into MariaDB, which does
                // not support MySQL 8's default utf8mb4_0900_ai_ci collation.
                `define('DB_COLLATE', '${multisite ? 'utf8mb4_unicode_ci' : ''}');`,
                "define('AUTH_KEY',         'e2e-test-key-1');",
                "define('SECURE_AUTH_KEY',  'e2e-test-key-2');",
                "define('LOGGED_IN_KEY',    'e2e-test-key-3');",
                "define('NONCE_KEY',        'e2e-test-key-4');",
                "define('AUTH_SALT',        'e2e-test-salt-1');",
                "define('SECURE_AUTH_SALT', 'e2e-test-salt-2');",
                "define('LOGGED_IN_SALT',   'e2e-test-salt-3');",
                "define('NONCE_SALT',       'e2e-test-salt-4');",
                "$table_prefix = 'wp_';",
                "if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');",
                "require_once ABSPATH . 'wp-settings.php';",
                '',
            ].join('\n'));

            // The installer's permalink probe reaches FPM before wp-config.php
            // becomes a multisite config. Do not retain that intermediate code.
            writeFileSync(join(siteDir, '.user.ini'), 'opcache.enable=0\n');

            // Install WordPress into the SQLite database.
            // With the db.php drop-in in place, wp core install creates
            // all standard WP tables in the .ht.sqlite file rather than MySQL.
            const allowRoot = process.getuid?.() === 0 ? ' --allow-root' : '';
            const port = new URL(getSiteUrl(site)).port;
            execSync(
                `php /tmp/wp-cli.phar core install` +
                ` --path=${JSON.stringify(siteDir)}` +
                ` --url="http://127.0.0.1:${port}"` +
                ` --title="E2E: ${site}"` +
                ` --admin_user=admin` +
                ` --admin_password=password` +
                ` --admin_email=admin@example.com` +
                ` --skip-email` +
                allowRoot,
                { timeout: 60000, stdio: 'pipe' },
            );

            // Finish any pending DB upgrade before the site is served.
            // On the Playground CLI job, the first PHP-FPM request can otherwise
            // race WordPress's upgrade flow and briefly leave the fresh SQLite
            // site in maintenance mode.
            execSync(
                `php /tmp/wp-cli.phar core update-db` +
                ` --path=${JSON.stringify(siteDir)}` +
                allowRoot,
                { timeout: 60000, stdio: 'pipe' },
            );
            rmSync(join(siteDir, '.maintenance'), { force: true });

            // Activate the Reprint Server plugin.
            execSync(
                `php /tmp/wp-cli.phar plugin activate reprint-server` +
                ` --path=${JSON.stringify(siteDir)}` +
                allowRoot,
                { timeout: 30000, stdio: 'pipe' },
            );
            if (multisite) {
                convertToMultisite(siteDir, getSiteUrl(site));
            }
        },
        afterPermissions: async (siteDir) => {
            // HTTP runs as nginx; WP-CLI runs as the CI user. Both must write
            // this fixture's SQLite database and create its journal files.
            execSync(`sudo chmod -R a+rwX ${JSON.stringify(join(siteDir, 'wp-content', 'database'))}`);
        },
    });
}
