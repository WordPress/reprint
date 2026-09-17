/**
 * Test 35: SQLite Export
 *
 * Sets up WordPress sites running on SQLite Database Integration 2.x and 3.x,
 * verifies that both export, and imports the 3.x dump into a real MySQL
 * database.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { execSync, spawn } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    apiRequest, createMysqlConnection, queryMysqlOnSqlite,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { convertToMultisite, ensureMultisite, runWp } from '../lib/multisite-setup.js';

async function ensureSqliteSite(site, pluginVersion, multisite = false) {
    await ensureSite(site, {
        db: 'none',
        files: 'sample',
        afterCreate: async (siteDir) => {
            const pluginsDir = join(siteDir, 'wp-content', 'plugins');
            const pluginDir = join(pluginsDir, 'sqlite-database-integration');
            const pluginZip = `/tmp/sqlite-database-integration-${pluginVersion}.zip`;

            // Pin the plugin version so this test always exercises the intended driver.
            if (!existsSync(pluginZip)) {
                execSync(
                    `curl -sfL "https://downloads.wordpress.org/plugin/` +
                    `sqlite-database-integration.${pluginVersion}.zip" -o "${pluginZip}"`,
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

describe('Import: SQLite Export', () => {
    const site = 'sqlite';
    const legacySite = 'sqlite-legacy';
    let tempDir;
    let legacyTempDir;
    const importDb = 'e2e_sqlite_import';

    beforeAll(async () => {
        await ensureSqliteSite(site, '3.0.0');
        await ensureSqliteSite(legacySite, '2.2.23');

        tempDir = createTempDir('e2e-import-sqlite');
        legacyTempDir = createTempDir('e2e-import-sqlite-legacy');

        // Ensure the import target DB doesn't exist
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    }, 300000);

    afterAll(async () => {
        cleanupTempDir(tempDir);
        cleanupTempDir(legacyTempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl(siteName = site) {
        return `${getSiteUrl(siteName)}&directory=${getSiteDir(siteName)}`;
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

    it('db-pull supports SQLite Database Integration 2.2.23', () => {
        const result = runImporter(importUrl(legacySite), legacyTempDir, 'db-pull', {
            secret: getSiteSecret(legacySite),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const sql = readFileSync(join(legacyTempDir, 'db.sql'), 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        assert.ok(sql.includes('INSERT INTO'), 'Expected INSERT INTO in db.sql');
        assert.ok(sql.includes('wp_options'), 'Expected wp_options table in dump');
        assert.ok(sql.includes('wp_posts'), 'Expected wp_posts table in dump');
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
    it('keeps ordinary imports on the existing URL rewriting path', () => {
        runWp(getSiteDir(site), ['eval', `
            global $wpdb;
            $wpdb->update($wpdb->posts, array(
                'post_content' => '<a href="https://source.test/page"></a><style>.hero{background:url(//source.test/photo.png)}</style>'
            ), array('ID' => 1));
        `]);
        const directory = createTempDir('e2e-ordinary-sqlite-rewrite');
        try {
            const target = join(directory, 'target.sqlite');
            const result = runImporter(importUrl(), directory, 'pull-db', {
                secret: getSiteSecret(site), autoResume: false,
                extraArgs: ['--target-engine=sqlite', `--target-sqlite-path=${target}`,
                    '--rewrite-url', 'https://source.test', 'https://target.test'],
            });
            assert.equal(result.exitCode, 0, result.stdout + result.stderr);
            const rows = queryMysqlOnSqlite(target, 'SELECT post_content FROM wp_posts WHERE ID=1');
            assert.equal(rows[0].post_content, '<a href="https://target.test/page"></a><style>.hero{background:url(//target.test/photo.png)}</style>');
        } finally { cleanupTempDir(directory); }
    });

});


// Separate fixtures keep these conversions out of the single-site export tests.
describe.each([
    ['multisite-sqlite', '3.0.0'],
    ['multisite-sqlite-legacy', '2.2.23'],
    ['multisite-paths-mysql', 'mysql'],
])('Multisite child paths: %s (%s)', (site, version) => {
    let fixture;
    beforeAll(async () => {
        if (version !== 'mysql') await ensureSqliteSite(site, version, true);
        else await ensureMultisite(site);
        fixture = JSON.parse(readFileSync(join(getSiteDir(site), '.multisite-layer.json'), 'utf8'));

    });

    it.each(['sqlite', 'mysql'])('migrates site 7 to a %s single-site target', async (engine) => {
        const directory = createTempDir(`e2e-${site}-to-${engine}`);
        const documentRoot = join(directory, 'site');
        const targetUrl = 'http://127.0.0.1:9697';
        const databaseName = 'e2e_selected_sqlite_target';
        const sqlitePath = join(directory, 'target.sqlite');
        let server;
        let serverLog = '';
        const connection = await createMysqlConnection();
        try {
            runWp(getSiteDir(site), ['eval', "wp_update_post(['ID'=>100, 'post_content'=>'Only site 7']);"], fixture.sites[7].url);
            if (engine === 'mysql') {
                await connection.query(`DROP DATABASE IF EXISTS ${databaseName}`);
                await connection.query(`CREATE DATABASE ${databaseName}`);
            }
            const targetArgs = engine === 'sqlite'
                ? ['--target-engine=sqlite', `--target-sqlite-path=${sqlitePath}`, `--target-db=${databaseName}`]
                : ['--target-engine=mysql', '--target-host=127.0.0.1', '--target-user=e2e_admin', '--target-pass=e2e_password', `--target-db=${databaseName}`];
            const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, directory, 'pull', {
                secret: getSiteSecret(site), skipPreflight: true, autoResume: false,
                timeout: 240000, wallTimeout: 300000,
                extraArgs: [...targetArgs, `--new-site-url=${targetUrl}`, '--site-admin=shared',
                    '--runtime=php-builtin', '--start-runtime=none', `--flatten-to=${documentRoot}`],
            });
            assert.equal(result.exitCode, 0, result.stdout + result.stderr);
            writeFileSync(join(documentRoot, 'sqlite-check.php'), `<?php
                require __DIR__ . '/wp-load.php';
                $upload = wp_upload_bits('new-target.txt', null, 'New target upload');
                echo json_encode([
                    'multisite' => is_multisite(), 'prefix' => $wpdb->prefix,
                    'users_table' => $wpdb->users, 'tables' => $wpdb->get_col('SHOW TABLES'),
                    'users' => $wpdb->get_col("SELECT user_login FROM {$wpdb->users} ORDER BY user_login"),
                    'administrator' => user_can(get_user_by('login', 'shared'), 'manage_options'),
                    'content' => get_post(100)->post_content, 'media' => wp_get_attachment_url(200),
                    'new_upload' => $upload, 'sqlite' => isset($GLOBALS['@pdo']),
                ]);
            `);
            server = spawn(process.env.E2E_WP_CLI_PHP_BINARY || 'php', [
                '-S', '127.0.0.1:9697', '-t', documentRoot, join(directory, 'runtime/runtime.php'),
            ], { stdio: ['ignore', 'pipe', 'pipe'] });
            server.stdout.on('data', data => { serverLog = (serverLog + data).slice(-16000); });
            server.stderr.on('data', data => { serverLog = (serverLog + data).slice(-16000); });
            let response;
            for (let attempt = 0; attempt < 100; ++attempt) {
                try { response = await fetch(`${targetUrl}/sqlite-check.php`); break; }
                catch { await sleep(100); }
            }
            assert.ok(response, serverLog);
            const body = await response.text();
            assert.equal(response.status, 200, body + serverLog);
            const target = JSON.parse(body);
            const prefix = version === 'mysql' ? 'network_' : 'wp_';
            assert.equal(target.multisite, false);
            assert.equal(target.sqlite, engine === 'sqlite');
            assert.equal(target.prefix, `${prefix}7_`);
            assert.equal(target.users_table, `${prefix}users`);
            assert.ok(!target.tables.includes(`${prefix}8_posts`));
            assert.ok(!target.users.includes('sibling-member'));
            assert.ok(target.users.includes('shop-member'));
            assert.equal(target.administrator, true);
            assert.equal(target.content, 'Only site 7');
            assert.equal(target.new_upload.error, false);
            assert.ok(target.media.startsWith(`${targetUrl}/wp-content/uploads/sites/7/`));
            assert.equal(await (await fetch(target.media)).text(), 'Media on site 7');
            assert.equal(await (await fetch(target.new_upload.url)).text(), 'New target upload');
        } finally {
            if (server && server.exitCode === null) {
                const stopped = new Promise(resolve => server.once('exit', resolve));
                server.kill();
                await stopped;
            }
            if (engine === 'mysql') await connection.query(`DROP DATABASE IF EXISTS ${databaseName}`);
            await connection.end();
            cleanupTempDir(directory);
        }
    }, 300000);

    it('resumes source user collection across HTTP requests and rejects a replaced set', async () => {
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const params = { multisite_mode: 'one-site-network-v1' };
        const first = await apiRequest(site, 'sql_chunk', params, { url });
        const cursor = first.chunks?.find(chunk => chunk.type === 'sql' && chunk.body.includes('INSERT INTO'))?.headers['x-cursor'];
        assert.ok(cursor, JSON.stringify(first.json || first.chunks));
        const resumed = await apiRequest(site, 'sql_chunk', { ...params, cursor }, { url });
        assert.equal(resumed.chunks?.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete', JSON.stringify(resumed.json || resumed.chunks));
        await apiRequest(site, 'sql_chunk', params, { url });
        const stale = await apiRequest(site, 'sql_chunk', { ...params, cursor }, { url });
        assert.ok(!stale.chunks?.some(chunk => chunk.type === 'sql'));
        assert.ok(JSON.stringify(stale.json || stale.chunks).includes('replaced or are missing'));
    });

    it('rejects an existing SQLite target without changing its tables or rows', () => {
        const directory = createTempDir('e2e-multisite-sqlite-occupied');
        const path = join(directory, 'target.sqlite');
        try {
            queryMysqlOnSqlite(path, 'CREATE TABLE keep_this (value text)');
            queryMysqlOnSqlite(path, "INSERT INTO keep_this VALUES ('Existing local data')");
            const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, directory, 'pull-db', {
                secret: getSiteSecret(site), skipPreflight: true, autoResume: false,
                extraArgs: ['--target-engine=sqlite', `--target-sqlite-path=${path}`,
                    '--target-db=sqlite_database', '--new-site-url=http://target.test', '--site-admin=shared'],
            });
            assert.equal(result.exitCode, 1, result.stdout + result.stderr);
            assert.ok((result.stdout + result.stderr).includes('empty target database; found table keep_this'));
            assert.deepEqual(queryMysqlOnSqlite(path, 'SHOW TABLES').map(Object.values).flat(), ['keep_this']);
            assert.deepEqual(queryMysqlOnSqlite(path, 'SELECT value FROM keep_this'), [{ value: 'Existing local data' }]);
        } finally { cleanupTempDir(directory); }
    });

    for (const stage of ['database-initialize', 'sql', 'database-cleanup']) {
        for (const when of ['before', 'after']) {
            it(`resumes SQLite apply after process death ${when} saving ${stage}`, async () => {
                const directory = createTempDir('e2e-multisite-sqlite-resume');
                const path = join(directory, 'target.sqlite');
                const marker = join(directory, 'paused');
                const url = `${fixture.sites[7].url}/?reprint-api`;
                let child;
                let finished;
                let output = '';
                try {
                    const dump = runImporter(url, directory, 'db-pull', { secret: getSiteSecret(site), autoResume: false });
                    assert.equal(dump.exitCode, 0, dump.stdout + dump.stderr);
                    const clientPath = process.env.CLIENT_PATH || join(import.meta.dirname, '../../../packages/reprint-client/bin/reprint-client');
                    child = spawn(process.env.E2E_WP_CLI_PHP_BINARY || 'php', [
                        join(import.meta.dirname, '../fixtures/pause-multisite-apply.php'),
                        clientPath, url, directory, 'sqlite_database', stage, when, marker, 'http://target.test', path,
                    ], { stdio: ['ignore', 'pipe', 'pipe'] });
                    finished = new Promise(resolve => child.once('exit', (code, signal) => resolve({ code, signal })));
                    child.stdout.on('data', data => { output = (output + data).slice(-16000); });
                    child.stderr.on('data', data => { output = (output + data).slice(-16000); });
                    for (let attempt = 0; attempt < 300 && !existsSync(marker) && child.exitCode === null; ++attempt) await sleep(100);
                    assert.ok(existsSync(marker), output);
                    child.kill('SIGKILL');
                    assert.equal((await finished).signal, 'SIGKILL');
                    const args = ['--target-engine=sqlite', `--target-sqlite-path=${path}`,
                        '--target-db=sqlite_database', '--new-site-url=http://target.test', '--site-admin=shared'];
                    if (stage === 'sql') {
                        const otherPath = join(directory, 'other.sqlite');
                        queryMysqlOnSqlite(otherPath, 'CREATE TABLE keep_this (value text)');
                        const changed = runImporter(url, directory, 'db-apply', {
                            secret: getSiteSecret(site), autoResume: false,
                            extraArgs: args.map(arg => arg === `--target-sqlite-path=${path}` ? `--target-sqlite-path=${otherPath}` : arg),
                        });
                        assert.equal(changed.exitCode, 1, changed.stdout + changed.stderr);
                        assert.ok((changed.stdout + changed.stderr).includes('Cannot change --target-sqlite-path'));
                        assert.deepEqual(queryMysqlOnSqlite(otherPath, 'SHOW TABLES').map(Object.values).flat(), ['keep_this']);
                    }
                    const resumed = runImporter(url, directory, 'db-apply', {
                        secret: getSiteSecret(site), autoResume: false, extraArgs: args,
                    });
                    assert.equal(resumed.exitCode, 0, resumed.stdout + resumed.stderr);
                    const prefix = version === 'mysql' ? 'network_' : 'wp_';
                    assert.deepEqual(queryMysqlOnSqlite(path, `SELECT blog_id FROM ${prefix}blogs`), [{ blog_id: 7 }]);
                    assert.deepEqual(queryMysqlOnSqlite(path, `SELECT post_content FROM ${prefix}7_posts WHERE ID=100`), [{ post_content: 'Only site 7' }]);
                    assert.ok(!queryMysqlOnSqlite(path, `SELECT user_login FROM ${prefix}users`).some(row => row.user_login === 'sibling-member'));
                } finally {
                    if (child && child.exitCode === null && child.signalCode === null) { child.kill('SIGKILL'); await finished; }
                    cleanupTempDir(directory);
                }
            }, 120000);
        }
    }

    it('reads child paths across batches without including other domains or adjacent paths', async () => {
        const origin = new URL(fixture.sites[7].url).origin;
        const expected = [];
        for (let index = 0; index < 2005; index++) expected.push(`/shop/child-${index}/`);
        expected.push('/shop/final/');
        runWp(getSiteDir(site), ['eval', `
            global $wpdb;
            $domain = wp_parse_url(home_url(), PHP_URL_HOST) . ':' . wp_parse_url(home_url(), PHP_URL_PORT);
            $wpdb->query("DELETE FROM {$wpdb->blogs} WHERE blog_id >= 10000");
            for ($index = 0; $index < 2005; ++$index) {
                // Sparse IDs catch pagination that advances by row count.
                $wpdb->insert($wpdb->blogs, array(
                    'blog_id' => 10000 + $index * 3, 'site_id' => 1,
                    'domain' => $domain, 'path' => '/shop/child-' . $index . '/',
                    'archived' => $index === 0 ? 1 : 0,
                ));
            }
            // More than one whole scan window has no matching paths.
            for ($index = 0; $index < 2005; ++$index) {
                $wpdb->insert($wpdb->blogs, array('blog_id' => 20000 + $index,
                    'site_id' => 1, 'domain' => 'other.example', 'path' => '/'));
            }
            $wpdb->insert($wpdb->blogs, array('blog_id' => 30000,
                'site_id' => 1, 'domain' => $domain, 'path' => '/shop/final/'));
            foreach (array(
                array($domain, '/shopper/child/'),
                array('other.example', '/shop/child/'),
                array($domain, '/shop/'),
            ) as $row) {
                $wpdb->insert($wpdb->blogs, array('site_id' => 1, 'domain' => $row[0], 'path' => $row[1]));
            }
        `], fixture.sites[7].url);
        const response = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, {
            url: `${fixture.sites[7].url}/?reprint-api`, method: 'GET',
        });
        assert.equal(response.status, 200, JSON.stringify(response.json).slice(0, 1000));
        assert.equal(response.json.database.db_engine, version === 'mysql' ? 'mysql' : 'sqlite');
        assert.deepEqual(response.json.database.wp.multisite.selection.nested_site_paths, { [origin]: expected });
        const posted = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, {
            url: `${fixture.sites[7].url}/?reprint-api`,
        });
        assert.equal(posted.status, 200, JSON.stringify(posted.json));
        assert.deepEqual(posted.json.database.wp.multisite.selection.nested_site_paths, { [origin]: expected });
        const batches = JSON.parse(runWp(getSiteDir(site), ['eval', `
            global $wpdb;
            $runtime = WordPress\\Reprint\\Server\\Plugin\\load_server_runtime();
            require_once $runtime;
            require_once WP_PLUGIN_DIR . '/reprint-server/wordpress/multisite.php';
            $context = WordPress\\Reprint\\Server\\Plugin\\get_multisite_export_context();
            $batch_sizes = array();
            // Observe actual wpdb results before the next query replaces them.
            add_filter('query', function ($query) use (&$batch_sizes, $wpdb) {
                if (strpos($wpdb->last_query, 'SELECT blog_id,') === 0) {
                    $batch_sizes[] = $wpdb->num_rows;
                }
                return $query;
            });
            reprint_get_multisite_nested_site_paths($context);
            $batch_sizes[] = $wpdb->num_rows;
            echo json_encode($batch_sizes);
        `], fixture.sites[7].url));
        assert.deepEqual(batches, [1000, 1000, 1000, 1000, 14], 'The adapter must never buffer the whole site list.');
    });
    it('keeps SQL wildcard characters literal and groups default-port spellings', () => {
        const paths = JSON.parse(runWp(getSiteDir(site), ['eval', `
            global $wpdb;
            $runtime = WordPress\\Reprint\\Server\\Plugin\\load_server_runtime();
            require_once $runtime;
            require_once WP_PLUGIN_DIR . '/reprint-server/wordpress/multisite.php';
            $context = WordPress\\Reprint\\Server\\Plugin\\get_multisite_export_context();
            $wpdb->query("DELETE FROM {$wpdb->blogs} WHERE blog_id >= 40000");
            foreach (array(
                array('network.test', '/shop%20_sale/child/'),
                array('network.test:443', '/admin/child/'),
                array('network.test', '/shopX20_sale/not-a-child/'),
                array('network.test', '/shop%20Xsale/not-a-child/'),
                array('network.test', '/shop%20_sale/'),
                array('network.test:8443', '/shop%20_sale/not-a-child/'),
            ) as $index => $row) {
                // Another network and an archived site still need protection.
                $wpdb->insert($wpdb->blogs, array('blog_id' => 40000 + $index,
                    'site_id' => 2, 'archived' => 1, 'domain' => $row[0], 'path' => $row[1]));
            }
            $context['home_url'] = 'https://network.test:443/shop%20_sale/';
            $context['site_url'] = 'https://network.test/admin/';
            echo json_encode(reprint_get_multisite_nested_site_paths($context));
        `], fixture.sites[7].url));
        assert.deepEqual(paths, { 'https://network.test': ['/shop%20_sale/child/', '/admin/child/'] });
    });

});
