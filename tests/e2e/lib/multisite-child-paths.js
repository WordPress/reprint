/**
 * Shared tests for test 35: select one child site from a multisite source
 * that stores its data in SQLite or MySQL.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir, getSiteSecret, getSiteDir,
    apiRequest, createMysqlConnection, queryMysqlOnSqlite, IS_WINDOWS_CLIENT,
} from './test-helpers.js';
import { ensureMultisite, runWp } from './multisite-setup.js';
import { ensureSqliteSite } from './sqlite-site.js';

/**
 * Define the child-path tests for one multisite source.
 *
 * Each source has a test file of its own, so the sources run in parallel.
 * Each also needs its own port for the migrated site's web server.
 */
export function describeMultisiteChildPaths(site, version, targetPort) {
    describe(`Multisite child paths: ${site} (${version})`, () => defineMultisiteChildPathTests(site, version, targetPort));
}

function defineMultisiteChildPathTests(site, version, targetPort) {
    let fixture;
    beforeAll(async () => {
        if (version !== 'mysql') await ensureSqliteSite(site, version, true);
        else await ensureMultisite(site);
        fixture = JSON.parse(readFileSync(join(getSiteDir(site), '.multisite-layer.json'), 'utf8'));
    });

    // Booting a pulled site under native Windows PHP is not a supported scenario.
    (IS_WINDOWS_CLIENT ? it.skip : it).each(['sqlite', 'mysql'])('migrates site 7 to a %s single-site target', async (engine) => {
        const directory = createTempDir(`e2e-${site}-to-${engine}`);
        const documentRoot = join(directory, 'site');
        const targetUrl = `http://127.0.0.1:${targetPort}`;
        const databaseName = `e2e_selected_target_${site.replace(/-/g, '_')}`;
        const sqlitePath = join(directory, 'target.sqlite');
        let server;
        let serverLog = '';
        const connection = await createMysqlConnection();
        try {
            runWp(getSiteDir(site), ['eval', `
                wp_update_post(['ID'=>100, 'post_content'=>'Only site 7']);
                global $wpdb;
                foreach (['empty' => '', 'null' => null, 'binary' => "a\\0b"] as $name => $value) {
                    $wpdb->delete($wpdb->postmeta, ['meta_key' => 'sqlite-probe-' . $name]);
                    $wpdb->insert($wpdb->postmeta, ['post_id' => 100, 'meta_key' => 'sqlite-probe-' . $name, 'meta_value' => $value]);
                }
            `], fixture.sites[7].url);
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
                    'values' => $wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE 'sqlite-probe-%' ORDER BY meta_key", ARRAY_A),
                ]);
            `);
            server = spawn(process.env.E2E_WP_CLI_PHP_BINARY || 'php', [
                '-S', `127.0.0.1:${targetPort}`, '-t', documentRoot, join(directory, 'runtime/runtime.php'),
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
            assert.deepEqual(target.values, [
                { meta_key: 'sqlite-probe-binary', meta_value: 'a\0b' },
                { meta_key: 'sqlite-probe-empty', meta_value: '' },
                { meta_key: 'sqlite-probe-null', meta_value: null },
            ]);
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
            // The pause fixture runs the importer in Linux PHP and kills it, and
            // a signal from WSL cannot reach a native Windows importer.
            it.skipIf(IS_WINDOWS_CLIENT)(`resumes SQLite apply after process death ${when} saving ${stage}`, async () => {
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
                    assert.deepEqual(queryMysqlOnSqlite(path, `SELECT blog_id FROM ${prefix}blogs`).map(row => Number(row.blog_id)), [7]);
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

}
