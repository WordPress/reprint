import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { createConnection } from 'mysql2/promise';
import { apiRequest, getSiteDir, getSiteUrl, writeTestHooks, removeTestHooks, readHookState, clearHookState } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { runWp } from '../lib/multisite-setup.js';

// The PHP 8.2 CI job starts Oracle MySQL. MariaDB can choose a different
// subquery plan and must not stand in for the engine with this regression.
const describeWithMysql8 = process.env.E2E_MYSQL8_HOST ? describe : describe.skip;

describeWithMysql8('Selected-site user queries on a large MySQL network', () => {
    const site = 'multisite-query-budget';
    const sourceDatabase = 'e2e_multisite_query_budget_source';
    const targetDatabase = 'e2e_multisite_query_budget_target';
    const host = process.env.E2E_MYSQL8_HOST;
    const port = Number(process.env.E2E_MYSQL8_PORT || 3306);
    const user = process.env.E2E_MYSQL8_USER || 'root';
    const password = process.env.E2E_MYSQL8_PASS || '';
    let connection;
    let url;
    let tables;
    let expectedUserIds;

    beforeAll(async () => {
        connection = await createConnection({ host, port, user, password, multipleStatements: true });
        const [[version]] = await connection.query('SELECT VERSION() AS version');
        assert.match(version.version, /^8\.0\./, 'Run the query budget against Oracle MySQL 8.0');
        await connection.query(`CREATE DATABASE \`${sourceDatabase}\`; CREATE DATABASE \`${targetDatabase}\``);
        await ensureSite(site, {
            db: 'none', files: 'none', tablePrefix: 'network_',
            wpConfig: { DB_HOST: `${host}:${port}`, DB_USER: user, DB_PASSWORD: password, DB_NAME: sourceDatabase },
            afterCreate: (directory) => {
                runWp(directory, ['core', 'install', `--url=${new URL(getSiteUrl(site)).origin}`,
                    '--title=Query budget', '--admin_user=admin', '--admin_password=fixture-password',
                    '--admin_email=admin@example.test', '--skip-email']);
                runWp(directory, ['core', 'multisite-convert', '--title=Source network', '--base=/', '--skip-config']);
                const config = join(directory, 'wp-config.php');
                const constants = `define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);
define('DOMAIN_CURRENT_SITE', '${new URL(getSiteUrl(site)).host}');
define('PATH_CURRENT_SITE', '/');
define('SITE_ID_CURRENT_SITE', 1);
define('BLOG_ID_CURRENT_SITE', 1);
`;
                writeFileSync(config, readFileSync(config, 'utf8').replace('$table_prefix =', constants + '$table_prefix ='));
                runWp(directory, ['plugin', 'activate', 'reprint-server', '--network']);
                runWp(directory, ['eval-file', join(import.meta.dirname, '../fixtures/multisite-layer.php')]);
            },
        });
        url = JSON.parse(readFileSync(join(getSiteDir(site), '.multisite-layer.json'), 'utf8')).sites[7].url + '/?reprint-api';
        await connection.query(`USE \`${sourceDatabase}\`; SET SESSION sql_mode = ''`);
        // Most network users have no relationship to this site. Anonymous
        // comments force the old correlated query to scan without finding one.
        // A million comments and ten thousand selected members also expose the
        // derived UNION's repeated full-site scans across many profile batches.
        await connection.query(`
            CREATE TABLE digits (n int PRIMARY KEY);
            INSERT INTO digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
            CREATE TEMPORARY TABLE numbers (n int PRIMARY KEY);
            INSERT INTO numbers SELECT a.n+10*b.n+100*c.n+1000*d.n+10000*e.n
                FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d CROSS JOIN digits e
                WHERE e.n<2;
            INSERT INTO network_users (ID,user_login) SELECT 1000+n,CONCAT('bulk-',n) FROM numbers;
            INSERT INTO network_usermeta (user_id,meta_key,meta_value)
                SELECT 1000+n,'first_name',CONCAT('Name ',n) FROM numbers;
            INSERT INTO network_usermeta (user_id,meta_key,meta_value)
                SELECT 1000+n,'nickname',CONCAT('Nickname ',n) FROM numbers;
            INSERT INTO network_usermeta (user_id,meta_key,meta_value)
                SELECT 1000+numbers.n, profiles.meta_key, 'Profile value' FROM numbers CROSS JOIN
                (SELECT 'last_name' AS meta_key UNION ALL SELECT 'description'
                    UNION ALL SELECT 'rich_editing' UNION ALL SELECT 'syntax_highlighting') profiles;
            INSERT INTO network_usermeta (user_id,meta_key,meta_value)
                SELECT 1000+n,'network_8_capabilities','a:1:{s:6:"author";b:1;}' FROM numbers;
            INSERT INTO network_usermeta (user_id,meta_key,meta_value)
                SELECT 1000+n,'network_7_capabilities','a:1:{s:10:"subscriber";b:1;}' FROM numbers WHERE n<10000;
            INSERT INTO network_7_comments (comment_ID,comment_post_ID,user_id)
                SELECT 100000+numbers.n+20000*(a.n+10*b.n),100,0
                FROM numbers CROSS JOIN digits a CROSS JOIN digits b WHERE b.n<5;
            INSERT INTO network_7_comments (comment_ID,comment_post_ID,user_id) VALUES (99999,100,20997);
            INSERT INTO network_7_links (link_id,link_owner) SELECT 1000+n,20998 FROM numbers WHERE n<10000;
            INSERT INTO network_7_posts (ID,post_author) VALUES (99999,20999);
            DROP TEMPORARY TABLE numbers; DROP TABLE digits;
        `);
        const [members] = await connection.query("SELECT ID FROM network_users WHERE user_login IN ('shared','shop-member') ORDER BY ID");
        expectedUserIds = [...members.map(row => row.ID), ...Array.from({ length: 10000 }, (_, index) => 1000 + index), 20997, 20998, 20999];
        const [rows] = await connection.query('SHOW TABLES');
        tables = rows.map(row => Object.values(row)[0]);
        await connection.query('ANALYZE TABLE network_users,network_usermeta,network_7_posts,network_7_comments,network_7_links');
        const preflight = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, { url });
        assert.equal(preflight.status, 200, JSON.stringify(preflight.json));
        assert.equal(preflight.json.database.wp.multisite.selection.site_id, 7);
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        if (connection) {
            try { await connection.query(`DROP DATABASE IF EXISTS \`${sourceDatabase}\`; DROP DATABASE IF EXISTS \`${targetDatabase}\``); }
            finally { await connection.end(); }
        }
        execFileSync('sudo', ['rm', '-rf', getSiteDir(site)]);
    });

    for (const table of ['network_users', 'network_usermeta']) {
        it(`exports and resumes ${table} within the query budget`, async () => {
            const params = {
                multisite_mode: 'one-site-network-v1', fragments_per_batch: 250,
                db_query_time_limit: 5000, skip_tables: tables.filter(name => name !== table),
            };
            const first = await apiRequest(site, 'sql_chunk', params, { url, signal: AbortSignal.timeout(20000) });
            assert.equal(first.status, 200, JSON.stringify(first.json));
            assert.equal(first.chunks?.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete',
                `${table} must finish within one request's time budget: ${JSON.stringify(first.chunks?.filter(chunk => chunk.type === 'error'))}`);
            const firstInsert = first.chunks.findIndex(chunk => chunk.type === 'sql' && chunk.body.includes(`INSERT INTO \`${table}\``));
            assert.ok(firstInsert >= 0, 'Resume from a real INSERT, not a fabricated cursor');
            const resumed = await apiRequest(site, 'sql_chunk', {
                ...params, cursor: first.chunks[firstInsert].headers['x-cursor'],
            }, { url, signal: AbortSignal.timeout(20000) });
            assert.equal(resumed.chunks?.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete',
                `Resumed ${table} must finish within one request's time budget: ${JSON.stringify(resumed.chunks?.filter(chunk => chunk.type === 'error'))}`);
            // Keep only the confirmed prefix from the first request. The second
            // request must supply every remaining row across multiple batches.
            const sql = [...first.chunks.slice(0, firstInsert + 1), ...resumed.chunks]
                .filter(chunk => ['sql', 'sql_session_setup'].includes(chunk.type))
                .map(chunk => Buffer.from(chunk.body, 'binary'));
            execFileSync('mysql', [`--host=${host}`, `--port=${port}`, `--user=${user}`, targetDatabase], {
                env: { ...process.env, MYSQL_PWD: password }, input: Buffer.concat(sql),
            });
            if (table === 'network_users') {
                const [users] = await connection.query(`SELECT ID FROM \`${targetDatabase}\`.network_users ORDER BY ID`);
                assert.deepEqual(users.map(row => row.ID), expectedUserIds);
            } else {
                const [metadata] = await connection.query(`SELECT user_id,meta_key,meta_value FROM \`${targetDatabase}\`.network_usermeta ORDER BY umeta_id`);
                assert.deepEqual([...new Set(metadata.map(row => row.user_id))].sort((a, b) => a - b), expectedUserIds);
                assert.ok(metadata.every(row => !['network_8_capabilities','network_capabilities','session_tokens'].includes(row.meta_key)));
                const [expected] = await connection.query(`SELECT user_id,meta_key,meta_value FROM network_usermeta
                    WHERE user_id IN (${expectedUserIds.join(',')}) AND user_id>=1000 AND meta_key!='network_8_capabilities' ORDER BY umeta_id`);
                assert.deepEqual(metadata.filter(row => row.user_id >= 1000), expected);
            }
        });
    }

    it('rejects an overlapping request, keeps sibling exports independent, and rejects replaced cursors', async () => {
        clearHookState(site);
        writeTestHooks(site, `
function test_hook_after_gzip_init($gz, $boundary) {
    global $wpdb;
    if (get_current_blog_id() !== 7) { return; }
    e2e_write_hook_state('/srv/e2e-sites/.e2e-hook-state-${site}', ['connection_id' => (int) $wpdb->get_var('SELECT CONNECTION_ID()')]);
    // Hold a real request open before its first SQL part. No store is edited.
    // This represents a slow worker; another export must fail without waiting.
    sleep(3);
}
`);
        const params = { multisite_mode: 'one-site-network-v1', skip_tables: tables.filter(name => name !== 'network_7_options') };
        const firstRequest = apiRequest(site, 'sql_chunk', params, { url, signal: AbortSignal.timeout(20000) });
        try {
            const deadline = Date.now() + 10000;
            while (!readHookState(site)?.connection_id && Date.now() < deadline) {
                await new Promise(resolve => setTimeout(resolve, 20));
            }
            assert.ok(readHookState(site)?.connection_id, 'The first HTTP export must reach the lock-protected stream');
            const overlap = await apiRequest(site, 'sql_chunk', params, { url });
            assert.equal(overlap.status, 400, JSON.stringify(overlap.json));
            assert.match(JSON.stringify(overlap.json), /Another SQL export request/);
            const fixture = JSON.parse(readFileSync(join(getSiteDir(site), '.multisite-layer.json'), 'utf8'));
            const sibling = await apiRequest(site, 'sql_chunk', params, { url: fixture.sites[8].url + '/?reprint-api' });
            assert.equal(sibling.status, 200, JSON.stringify(sibling.json));
            const first = await firstRequest;
            assert.equal(first.chunks?.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete');
            const cursor = first.chunks.find(chunk => chunk.type === 'sql').headers['x-cursor'];
            removeTestHooks(site);
            const resumed = await apiRequest(site, 'sql_chunk', { ...params, cursor }, { url });
            assert.equal(resumed.status, 200, JSON.stringify(resumed.json));
            const replacement = await apiRequest(site, 'sql_chunk', params, { url });
            assert.equal(replacement.status, 200, JSON.stringify(replacement.json));
            const stale = await apiRequest(site, 'sql_chunk', { ...params, cursor }, { url });
            assert.equal(stale.status, 400, JSON.stringify(stale.json));
            assert.match(JSON.stringify(stale.json), /replaced/);
            const preflight = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, { url });
            assert.equal(preflight.status, 200, JSON.stringify(preflight.json));

        } finally {
            removeTestHooks(site);
            clearHookState(site);
            await firstRequest;
        }
    });
});
