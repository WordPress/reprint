/**
 * Test 35: SQLite Export
 *
 * Sets up WordPress sites running on SQLite Database Integration 2.x and 3.x,
 * verifies that both export, and imports the 3.x dump into a real MySQL
 * database.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    apiRequest, createMysqlConnection, queryMysqlOnSqlite,
} from '../lib/test-helpers.js';
import { runWp } from '../lib/multisite-setup.js';
import { ensureSqliteSite } from '../lib/sqlite-site.js';

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

    it.each([site, legacySite])('keeps background cron disabled on %s', (siteName) => {
        const result = runWp(getSiteDir(siteName), ['eval',
            'echo defined("DISABLE_WP_CRON") && DISABLE_WP_CRON ? "disabled" : "enabled";']);
        assert.equal(result, 'disabled');
    });

    it.each([site, legacySite])('does not keep cached fixture PHP on %s', async (siteName) => {
        const response = await apiRequest(siteName, 'preflight');
        assert.equal(response.status, 200);
        assert.ok([undefined, '', '0', false].includes(response.json.runtime.ini_get_all['opcache.enable']),
            'SQLite fixture PHP must not remain cached across config changes.');
    });

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
