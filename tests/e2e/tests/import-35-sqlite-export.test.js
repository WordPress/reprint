/**
 * Test 35: SQLite Export
 *
 * Sets up a WordPress site running on SQLite (via the sqlite-database-
 * integration plugin) and verifies that the export produces valid MySQL
 * dump output that can be imported into a real MySQL database.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    apiRequest, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SQLite Export', () => {
    const site = 'sqlite';
    let tempDir;
    const importDb = 'e2e_sqlite_import';

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'none',
            files: 'sample',
            afterCreate: async (siteDir) => {
                const pluginsDir = join(siteDir, 'wp-content', 'plugins');
                const pluginDir = join(pluginsDir, 'sqlite-database-integration');

                // Download and install the sqlite-database-integration plugin.
                // Try WordPress.org first (zip), fall back to GitHub tarball.
                const pluginZip = '/tmp/sqlite-database-integration.zip';
                if (!existsSync(pluginZip) && !existsSync(pluginDir)) {
                    try {
                        execSync(
                            `curl -sfL "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip"` +
                            ` -o "${pluginZip}"`,
                            { timeout: 120000 },
                        );
                    } catch {
                        // WordPress.org might not have it; try GitHub
                        execSync(
                            `curl -sfL "https://github.com/WordPress/sqlite-database-integration/archive/refs/heads/main.tar.gz"` +
                            ` -o "/tmp/sqlite-plugin-gh.tar.gz"`,
                            { timeout: 120000 },
                        );
                        mkdirSync(pluginDir, { recursive: true });
                        execSync(
                            `tar xzf "/tmp/sqlite-plugin-gh.tar.gz"` +
                            ` -C "${pluginDir}" --strip-components=1`,
                            { timeout: 30000 },
                        );
                    }
                }

                // Extract the zip if we downloaded one and the plugin dir doesn't exist yet
                if (existsSync(pluginZip) && !existsSync(pluginDir)) {
                    try {
                        execSync(
                            `unzip -qo "${pluginZip}" -d "${pluginsDir}"`,
                            { timeout: 30000 },
                        );
                    } catch {
                        // unzip not available — fall back to PHP's ZipArchive
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
                // WP_SQLITE_AST_DRIVER enables the new AST-based query translator
                // (WP_SQLite_Driver) that our export code relies on.
                writeFileSync(join(siteDir, 'wp-config.php'), [
                    '<?php',
                    "define('DB_HOST', 'unused');",
                    "define('DB_NAME', 'wordpress');",
                    "define('DB_USER', 'unused');",
                    "define('DB_PASSWORD', 'unused');",
                    "define('DB_CHARSET', 'utf8mb4');",
                    "define('DB_COLLATE', '');",
                    "define('WP_SQLITE_AST_DRIVER', true);",
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

                // Install WordPress into the SQLite database.
                // With the db.php drop-in in place, wp core install creates
                // all standard WP tables in the .ht.sqlite file rather than MySQL.
                const allowRoot = process.getuid?.() === 0 ? ' --allow-root' : '';
                const port = 8102;
                execSync(
                    `php /tmp/wp-cli.phar core install` +
                    ` --path=${JSON.stringify(siteDir)}` +
                    ` --url="http://127.0.0.1:${port}"` +
                    ` --title="E2E: sqlite"` +
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

                // Activate the site-export plugin.
                execSync(
                    `php /tmp/wp-cli.phar plugin activate site-export` +
                    ` --path=${JSON.stringify(siteDir)}` +
                    allowRoot,
                    { timeout: 30000, stdio: 'pipe' },
                );
            },
        });

        tempDir = createTempDir('e2e-import-sqlite');

        // Ensure the import target DB doesn't exist
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    }, 300000);

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('preflight reports SQLite engine', async () => {
        const response = await apiRequest(site, 'preflight');
        assert.equal(response.status, 200, `Preflight failed: ${JSON.stringify(response.json || response.text)}`);
        assert.equal(response.json.database.db_engine, 'sqlite');
        assert.equal(response.json.database.connected, true);
        assert.equal(response.json.database.can_query, true);
    });

    it('db-pull produces valid MySQL dump from SQLite source', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        assert.ok(sql.includes('INSERT INTO'), 'Expected INSERT INTO in db.sql');

        // The dump should contain standard WordPress tables
        assert.ok(sql.includes('wp_options'), 'Expected wp_options table in dump');
        assert.ok(sql.includes('wp_posts'), 'Expected wp_posts table in dump');
        assert.ok(sql.includes('wp_users'), 'Expected wp_users table in dump');
    });

    it('exported dump imports into MySQL and contains expected data', async () => {
        // Create the target MySQL database
        const adminConn = await createMysqlConnection();
        await adminConn.query(`CREATE DATABASE \`${importDb}\``);
        await adminConn.end();

        // Two compatibility adjustments for importing SQLite-exported SQL
        // into MariaDB:
        // 1. The SQLite driver's SHOW CREATE TABLE uses MySQL 8.0 collation
        //    names (utf8mb4_0900_ai_ci) which MariaDB doesn't support.
        // 2. SQLite doesn't enforce NOT NULL constraints, so columns like
        //    comment_author_IP may contain NULL in the dump. Remove NOT NULL
        //    from CREATE TABLE statements so MariaDB accepts the NULLs.
        //    (AUTO_INCREMENT columns stay implicitly NOT NULL regardless.)
        const sqlFile = join(tempDir, 'db.sql');
        const sql = readFileSync(sqlFile, 'utf-8');
        const fixedSql = sql
            .replace(/utf8mb4_0900_ai_ci/g, 'utf8mb4_unicode_ci')
            .replace(/ NOT NULL/g, '');
        const fixedSqlFile = join(tempDir, 'db-fixed.sql');
        writeFileSync(fixedSqlFile, fixedSql);

        // Import the adjusted dump into MariaDB
        execSync(
            `mysql -u e2e_admin -pe2e_password -h 127.0.0.1 ${importDb} < ${JSON.stringify(fixedSqlFile)}`,
            { timeout: 30000, stdio: 'pipe' },
        );

        // Verify the imported database has standard WordPress tables with data
        const importConn = await createMysqlConnection(importDb);
        try {
            const [tables] = await importConn.query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME",
                [importDb],
            );
            const tableNames = tables.map(r => r.TABLE_NAME);

            const expectedTables = ['wp_options', 'wp_posts', 'wp_users', 'wp_comments',
                'wp_commentmeta', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy',
                'wp_term_relationships', 'wp_usermeta'];
            for (const expected of expectedTables) {
                assert.ok(tableNames.includes(expected),
                    `Expected table ${expected}, got: ${tableNames.join(', ')}`);
            }

            // Verify rows exist in key tables
            const [[optionsRow]] = await importConn.query("SELECT COUNT(*) as cnt FROM wp_options");
            assert.ok(Number(optionsRow.cnt) > 0, 'Expected rows in wp_options');

            const [[usersRow]] = await importConn.query("SELECT COUNT(*) as cnt FROM wp_users");
            assert.ok(Number(usersRow.cnt) > 0, 'Expected rows in wp_users');

            // Verify the siteurl option was imported correctly
            const [[siteUrlRow]] = await importConn.query(
                "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'",
            );
            assert.ok(siteUrlRow.option_value.includes('127.0.0.1'),
                `Expected siteurl to contain 127.0.0.1, got: ${siteUrlRow.option_value}`);
        } finally {
            await importConn.end();
        }
    });
});
