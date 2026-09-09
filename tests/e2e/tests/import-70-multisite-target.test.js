import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    apiRequest, runImporter, createTempDir, cleanupTempDir, getSiteSecret, getSiteDir, createMysqlConnection, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureMultisite, runWp } from '../lib/multisite-setup.js';

const site = 'multisite-target';
// An IP destination must reach import and runtime setup, not a text-format gate.
const targetUrl = 'http://127.0.0.1:9247';
const databases = ['e2e_multisite_boot_target', 'e2e_multisite_existing_target'];
const clientPath = process.env.CLIENT_PATH || join(import.meta.dirname, '../../../packages/reprint-client/bin/reprint-client');

describe('Pull a selected site into a fresh single site', () => {
    let fixture;
    let server;
    const directories = [];
    beforeAll(async () => {
        fixture = await ensureMultisite(site);
        // ensureMultisite has already made the source tree writable only by nginx.
        execFileSync('sudo', ['-u', 'nginx', process.env.E2E_WP_CLI_PHP_BINARY || 'php',
            '/tmp/wp-cli.phar', `--path=${getSiteDir(site)}`, 'eval', `
            file_put_contents(WP_PLUGIN_DIR . '/network-fixture.php', "<?php /* Plugin Name: Network fixture */ define('SINGLE_SITE_NETWORK_PLUGIN', true);");
            if (!is_dir(WP_PLUGIN_DIR . '/pressable-cache-management')) { mkdir(WP_PLUGIN_DIR . '/pressable-cache-management'); }
            file_put_contents(WP_PLUGIN_DIR . '/pressable-cache-management/pressable-cache-management.php', "<?php /* Plugin Name: Excluded host fixture */ define('SINGLE_SITE_EXCLUDED_PLUGIN', true);");
            activate_plugin('network-fixture.php', '', true);
            file_put_contents(WP_PLUGIN_DIR . '/a-local-fixture.php', "<?php /* Plugin Name: Local fixture */ define('SINGLE_SITE_PLUGIN_ORDER', defined('SINGLE_SITE_NETWORK_PLUGIN'));");
            switch_to_blog(7);
            activate_plugin('a-local-fixture.php');
            restore_current_blog();
            activate_plugin('pressable-cache-management/pressable-cache-management.php', '', true);
        `], { encoding: 'utf8', timeout: 120000 });

        // CLI setup can finish before HTTP serves the selected-site API.
        // Require the HTTP response to select the new site 7
        // before tests start; keep this wait in setup, not in the importer.
        const deadline = Date.now() + 30000;
        let response;
        do {
            response = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, {
                // Give the first request the remaining setup time, not a
                // separate five-second cap. Each 404 response must share the
                // same deadline rather than start another full time budget.
                url: `${fixture.sites[7].url}/?reprint-api`, signal: AbortSignal.timeout(Math.max(1, deadline - Date.now())),
            });
            if (response.status === 200 && response.json?.database?.wp?.multisite?.selection?.site_id === 7) {
                return;
            }
            if (response.status !== 404 && response.status !== 200) {
                break;
            }
            await sleep(100);
        } while (Date.now() < deadline);
        throw new Error(`Site ${site} did not serve the selected-site API after CLI setup: ${JSON.stringify(response).slice(0, 500)}`);
    });
    afterAll(async () => {
        server?.kill('SIGTERM');
        const connection = await createMysqlConnection();
        try {
            for (const database of databases) await connection.query(`DROP DATABASE IF EXISTS \`${database}\``);
        } finally { await connection.end(); }
        for (const directory of directories) cleanupTempDir(directory);
    });

    it('boots source site 7 as a single site and serves old and new uploads', async () => {
        const directory = createTempDir('e2e-multisite-boot');
        directories.push(directory);
        const connection = await createMysqlConnection();
        try {
            await connection.query(`DROP DATABASE IF EXISTS \`${databases[0]}\``);
            await connection.query(`CREATE DATABASE \`${databases[0]}\``);
        } finally { await connection.end(); }
        const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, directory, 'pull', {
            secret: getSiteSecret(site), skipPreflight: true, autoResume: false,
            timeout: 240000, wallTimeout: 300000,
            extraArgs: [
                '--target-engine=mysql', '--target-host=127.0.0.1',
                '--target-user=e2e_admin', '--target-pass=e2e_password', `--target-db=${databases[0]}`,
                `--new-site-url=${targetUrl}`, '--site-admin=SHARED', '--exclude-host-plugins',
                '--runtime=php-builtin', '--start-runtime=none', `--flatten-to=${join(directory, 'site')}`,
            ],
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        const documentRoot = join(directory, 'site');
        const inspection = JSON.parse(runWp(documentRoot, ['eval', `
            $new = wp_upload_bits('new-target.txt', null, 'New target upload');
            echo json_encode([
                'multisite' => is_multisite(),
                'id' => get_current_blog_id(), 'prefix' => $GLOBALS['wpdb']->prefix,
                'users_table' => $GLOBALS['wpdb']->users, 'usermeta_table' => $GLOBALS['wpdb']->usermeta,
                'is_admin' => user_can(get_user_by('login', 'shared'), 'manage_options'),
                'member_roles' => get_user_by('login', 'shop-member')->roles,
                'network_plugin' => defined('SINGLE_SITE_NETWORK_PLUGIN'),
                'plugin_order' => SINGLE_SITE_PLUGIN_ORDER,
                'excluded_plugin' => defined('SINGLE_SITE_EXCLUDED_PLUGIN'),
                'plugins' => get_option('active_plugins'),
                'media' => wp_get_attachment_url(200),
                'new_url' => $new['url'], 'upload_error' => $new['error'],
            ]);
        `], targetUrl));
        assert.equal(inspection.multisite, false);
        assert.equal(inspection.id, 1);
        assert.equal(inspection.prefix, 'network_7_');
        assert.equal(inspection.users_table, 'network_users');
        assert.equal(inspection.usermeta_table, 'network_usermeta');
        assert.equal(inspection.is_admin, true, 'The explicit grant must accept the database-matched login');
        assert.deepEqual(inspection.member_roles, ['subscriber']);
        assert.equal(inspection.network_plugin, true);
        assert.equal(inspection.excluded_plugin, false);
        assert.deepEqual(inspection.plugins, ['network-fixture.php', 'a-local-fixture.php']);
        assert.equal(inspection.plugin_order, true);
        assert.equal(inspection.upload_error, false);
        assert.ok(inspection.media.startsWith(`${targetUrl}/wp-content/uploads/sites/7/`));
        assert.ok(inspection.new_url.startsWith(`${targetUrl}/wp-content/uploads/sites/7/`));

        let serverLog = '';
        server = spawn(process.env.E2E_WP_CLI_PHP_BINARY || 'php', [
            '-S', '127.0.0.1:9247', '-t', documentRoot, join(directory, 'runtime/runtime.php'),
        ], { stdio: ['ignore', 'pipe', 'pipe'] });
        server.stdout.on('data', data => { serverLog += data; });
        server.stderr.on('data', data => { serverLog += data; });
        let response;
        for (let attempt = 0; attempt < 100; ++attempt) {
            try { response = await fetch(`${targetUrl}/?p=100`); break; }
            catch { await sleep(100); }
        }
        assert.ok(response, serverLog);
        const html = await response.text();
        assert.equal(response.status, 200, html + serverLog);
        assert.ok(html.includes('Only site 7'));
        const media = await fetch(inspection.media);
        assert.equal(media.status, 200);
        assert.equal(await media.text(), 'Media on site 7');
        const newUpload = await fetch(inspection.new_url);
        assert.equal(newUpload.status, 200);
        assert.equal(await newUpload.text(), 'New target upload');
        const loginPage = await fetch(`${targetUrl}/wp-login.php`);
        const cookie = loginPage.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
        const targetPassword = runWp(documentRoot, ['user', 'reset-password', 'shared', '--skip-email', '--porcelain']).trim();
        assert.ok(targetPassword.length > 0);
        const login = await fetch(`${targetUrl}/wp-login.php`, {
            method: 'POST', redirect: 'manual',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie },
            body: new URLSearchParams({ log: 'shared', pwd: targetPassword, testcookie: '1', redirect_to: `${targetUrl}/wp-admin/options-general.php` }),
        });
        assert.equal(login.status, 302, await login.text());
        const authCookies = login.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
        assert.ok(authCookies.includes('wordpress_logged_in_'));
        const admin = await fetch(`${targetUrl}/wp-admin/options-general.php`, { headers: { Cookie: authCookies } });
        assert.equal(admin.status, 200, await admin.text());
        const networkAdmin = await fetch(`${targetUrl}/wp-admin/network/`, { redirect: 'manual', headers: { Cookie: authCookies } });
        assert.equal(networkAdmin.status, 500);
        assert.ok((await networkAdmin.text()).includes('Multisite support is not enabled.'));
    }, 300000);

    it('rejects a non-empty target without changing or adding database tables', async () => {
        const directory = createTempDir('e2e-multisite-existing');
        directories.push(directory);
        const connection = await createMysqlConnection();
        try {
            await connection.query(`DROP DATABASE IF EXISTS \`${databases[1]}\``);
            await connection.query(`CREATE DATABASE \`${databases[1]}\``);
            await connection.query(`CREATE TABLE \`${databases[1]}\`.keep_this (value text)`);
            await connection.query(`INSERT INTO \`${databases[1]}\`.keep_this VALUES ('Existing local data')`);
            const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, directory, 'pull-db', {
                secret: getSiteSecret(site), skipPreflight: true, autoResume: false,
                extraArgs: [
                    '--target-engine=mysql', '--target-host=127.0.0.1',
                    '--target-user=e2e_admin', '--target-pass=e2e_password', `--target-db=${databases[1]}`,
                    `--new-site-url=${targetUrl}`, '--site-admin=shared',
                ],
            });
            assert.equal(result.exitCode, 1);
            assert.ok((result.stdout + result.stderr).includes('empty target database; found table keep_this'));
            const [tables] = await connection.query(`SHOW TABLES FROM \`${databases[1]}\``);
            assert.deepEqual(tables.map(row => Object.values(row)[0]), ['keep_this']);
            const [rows] = await connection.query(`SELECT value FROM \`${databases[1]}\`.keep_this`);
            assert.deepEqual(rows.map(row => row.value), ['Existing local data']);
        } finally { await connection.end(); }
    });

    it('keeps a million other domains out of preflight, then migrates with a million child paths', async () => {
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const baseline = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, { url });
        assert.equal(baseline.status, 200, JSON.stringify(baseline));
        const sourceDatabase = runWp(getSiteDir(site), ['config', 'get', 'DB_NAME']).trim();
        const connection = await createMysqlConnection(sourceDatabase);
        const [[originalPost]] = await connection.query('SELECT post_content FROM network_7_posts WHERE ID=100');
        try {
            // Confirm WordPress's case handling before growing the directory. Its
            // own site lookup is not the operation this million-row test measures.
            assert.equal(runWp(getSiteDir(site), ['eval', `
                $selected = get_site_by_path('${new URL(fixture.sites[7].url).host}', '/SHOP/article');
                echo $selected->blog_id;
            `], fixture.sites[7].url).trim(), '7');
            const fixtureStarted = Date.now();
            // Create directory records in MySQL, not a million-entry PHP or JS list.
            // No extra site tables are created: this tests the site directory only.
            await connection.query('CREATE TABLE reprint_test_digits (digit int PRIMARY KEY)');
            await connection.query('INSERT INTO reprint_test_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
            await connection.query(`INSERT INTO network_blogs (blog_id, site_id, domain, path, registered, last_updated)
                SELECT 1000000 + first.digit + 10*second.digit + 100*third.digit + 1000*fourth.digit + 10000*fifth.digit + 100000*sixth.digit,
                    1, CONCAT('site-', first.digit, second.digit, third.digit, fourth.digit, fifth.digit, sixth.digit, '.network.test'),
                    '/', '2026-01-01 00:00:00', '2026-01-01 00:00:00'
                FROM reprint_test_digits first CROSS JOIN reprint_test_digits second CROSS JOIN reprint_test_digits third
                    CROSS JOIN reprint_test_digits fourth CROSS JOIN reprint_test_digits fifth CROSS JOIN reprint_test_digits sixth`);
            await connection.query('DROP TABLE reprint_test_digits');
            const response = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, { url });
            assert.equal(response.status, 200, JSON.stringify(response));
            assert.equal(response.json.database.wp.multisite.selection.site_id, 7);
            assert.ok(Buffer.byteLength(JSON.stringify(response.json)) < Buffer.byteLength(JSON.stringify(baseline.json)) + 4096,
                'Preflight must not return a list of network sites.');
            assert.deepEqual(response.json.database.wp.multisite.selection.nested_site_paths,
                baseline.json.database.wp.multisite.selection.nested_site_paths);
            // Warm WordPress, then measure growth from path collection in that
            // same process. Whole preflight requests also include unrelated
            // startup allocations. Check peak growth to catch a temporary list
            // even if a later filter discards it before the response is built.
            const collectionMemory = JSON.parse(runWp(getSiteDir(site), ['eval', `
                WordPress\\Reprint\\Server\\Plugin\\load_server_runtime();
                require_once WP_PLUGIN_DIR . '/reprint-server/wordpress/multisite.php';
                $context = WordPress\\Reprint\\Server\\Plugin\\get_multisite_export_context();
                $before = memory_get_usage();
                $peak_before = memory_get_peak_usage();
                $paths = WordPress\\Reprint\\Server\\Plugin\\get_multisite_nested_site_paths($context);
                echo json_encode(array('live_growth' => memory_get_usage() - $before,
                    'peak_growth' => memory_get_peak_usage() - $peak_before,
                    'paths' => array_sum(array_map('count', $paths))));
            `], fixture.sites[7].url));
            assert.equal(collectionMemory.paths, 0);
            assert.ok(collectionMemory.live_growth < 8 * 1024 * 1024, JSON.stringify(collectionMemory));
            assert.ok(collectionMemory.peak_growth < 8 * 1024 * 1024, JSON.stringify(collectionMemory));
            console.log('Million-site directory preflight bytes:', JSON.stringify({
                collectionMemory, fixtureElapsedMilliseconds: Date.now() - fixtureStarted,
                baselineMemory: baseline.json.memory.used_bytes, memory: response.json.memory.used_bytes,
                baselineResponse: Buffer.byteLength(JSON.stringify(baseline.json)), response: Buffer.byteLength(JSON.stringify(response.json)),
            }));

            // The same directory size, now all under the selected /shop base.
            // Only this case needs a path list. Keep it out of rewrite rules and
            // frequently saved progress JSON, and exercise the real SQL apply.
            const sourceOrigin = new URL(fixture.sites[7].url).origin;
            await connection.query(`UPDATE network_blogs SET domain=?, path=CONCAT('/shop/site-', LPAD(blog_id-1000000, 8, '0'), '/')
                WHERE blog_id BETWEEN 1000000 AND 1999999`, [new URL(sourceOrigin).host]);
            const nested = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, { url });
            assert.equal(nested.status, 200, nested.text?.slice(0, 300));
            const childPaths = nested.json.database.wp.multisite.selection.nested_site_paths[sourceOrigin];
            assert.equal(childPaths.length, 1000000);
            assert.ok(childPaths.includes('/shop/site-00000000/'));
            assert.ok(childPaths.includes('/shop/site-00999999/'));
            // PHP 5.6 uses more space per array entry than PHP 8.x. Both must
            // fit within the source's 512 MiB limit without buffered row objects.
            assert.ok(nested.json.memory.used_bytes < baseline.json.memory.used_bytes + 256 * 1024 * 1024);
            console.log('Million child paths source:', { memoryBytes: nested.json.memory.used_bytes, fixtureElapsedMilliseconds: Date.now() - fixtureStarted });
            const markup = `<a href="${sourceOrigin}/shop/site-00000000/article">first child</a>`
                + '<a href="/shop/site-00999999/article">last child</a>'
                + '<a href="/shop/site-01000000/article">selected page</a>'
                + '<a href="site-00000000/article">relative child</a>'
                + '<a href="about/article">relative selected page</a>'
                + '<a href="/shop/SITE-00000000/article">uppercase child</a>'
                + '<img src="https://cdn.example/assets/photo.jpg">'
                + `<p>${sourceOrigin}/shop/about/../site-00000000/article</p>`
                + `<p>${sourceOrigin}/shop/site-00000000/../selected</p>`
                + `<a href="${sourceOrigin}/shop/a;b/../site-00000000/article">punctuated child</a>`
                + `<a href="${sourceOrigin}/shop/a;b/../selected">punctuated selected</a>`
                + `<style>.child{background:url("${sourceOrigin}/shop/a,b/../site-00000000/image.png")}</style>`;
            await connection.query('UPDATE network_7_posts SET post_content=? WHERE ID=100', [markup]);
            const directory = createTempDir('e2e-million-child-paths');
            directories.push(directory);
            const database = 'e2e_million_child_paths';
            databases.push(database);
            await connection.query(`CREATE DATABASE \`${database}\``);
            const migrationStarted = Date.now();
            const migrated = runImporter(url, directory, 'pull-db', {
                secret: getSiteSecret(site), autoResume: false, timeout: 180000,
                extraArgs: [...targetArgs(database), '--rewrite-url', 'https://cdn.example/assets', 'https://new-cdn.example/assets'],
            });
            console.log('Million child paths SQL migration milliseconds:', Date.now() - migrationStarted);
            assert.equal(migrated.exitCode, 0, migrated.stdout + migrated.stderr);
            const [[post]] = await connection.query(`SELECT post_content FROM \`${database}\`.network_7_posts WHERE ID=100`);
            assert.equal(post.post_content, `<a href="${sourceOrigin}/shop/site-00000000/article">first child</a>`
                + `<a href="${sourceOrigin}/shop/site-00999999/article">last child</a>`
                + '<a href="/site-01000000/article">selected page</a>'
                + `<a href="${sourceOrigin}/shop/site-00000000/article">relative child</a>`
                + '<a href="/about/article">relative selected page</a>'
                + `<a href="${sourceOrigin}/shop/SITE-00000000/article">uppercase child</a>`
                + '<img src="https://new-cdn.example/assets/photo.jpg">'
                + `<p>${sourceOrigin}/shop/about/../site-00000000/article</p>`
                + `<p>${sourceOrigin}/shop/site-00000000/../selected</p>`
                + `<a href="${sourceOrigin}/shop/site-00000000/article">punctuated child</a>`
                + `<a href="${targetUrl}/selected">punctuated selected</a>`
                + `<style>.child{background:url("${sourceOrigin}/shop/site-00000000/image.png")}</style>`);
            const stateDirectory = pullStateDirectory(directory, url);
            const stateJson = readFileSync(join(stateDirectory, 'state.json'), 'utf8');
            const state = JSON.parse(stateJson);
            assert.ok(Buffer.byteLength(stateJson) < 100000, 'Progress JSON must not contain one million paths.');
            assert.equal(state.apply.nested_site_paths_file, state.preflight.nested_site_paths_file);
            assert.equal(JSON.parse(readFileSync(join(stateDirectory, state.apply.nested_site_paths_file), 'utf8'))[sourceOrigin].length, 1000000);
            assert.equal(state.apply.rewrite_url['https://cdn.example/assets'], 'https://new-cdn.example/assets');
            assert.ok(Object.keys(state.apply.rewrite_url).length <= 21, 'At most 20 generated rules plus the explicit CDN rule.');
        } finally {
            await connection.query('UPDATE network_7_posts SET post_content=? WHERE ID=100', [originalPost.post_content]);
            await connection.query('DELETE FROM network_blogs WHERE blog_id BETWEEN 1000000 AND 1999999');
            await connection.query('DROP TABLE IF EXISTS reprint_test_digits');
            await connection.end();
        }
    }, 300000);

    it('keeps child-path collection out of ordinary export context and never collects upload site IDs', () => {
        const observations = JSON.parse(runWp(getSiteDir(site), ['eval', `
            global $wpdb;
            WordPress\\Reprint\\Server\\Plugin\\load_server_runtime();
            require_once WP_PLUGIN_DIR . '/reprint-server/wordpress/multisite.php';
            define('SAVEQUERIES', true);
            $wpdb->queries = array();
            $context = WordPress\\Reprint\\Server\\Plugin\\get_multisite_export_context();
            $count = 0;
            foreach ($wpdb->queries as $query) {
                if (strpos($query[0], 'SELECT domain, path FROM') === 0) { ++$count; }
            }
            $context['site_url'] = str_replace('http:', 'https:', $context['site_url']);
            $paths = WordPress\\Reprint\\Server\\Plugin\\get_multisite_nested_site_paths($context);
            echo json_encode(array($count, isset($context['sibling_urls']), array_key_exists('sibling_site_ids', $context), array_key_exists('nested_site_paths', $context), count($paths)));
        `], fixture.sites[7].url));
        assert.deepEqual(observations, [0, false, false, false, 1]);
    });

    it('rejects invalid targets and stores one normalized IPv4 destination', async () => {
        const directory = createTempDir('e2e-multisite-input');
        directories.push(directory);
        const database = 'e2e_multisite_input_target';
        databases.push(database);
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const dump = runImporter(url, directory, 'db-pull', { secret: getSiteSecret(site), autoResume: false });
        assert.equal(dump.exitCode, 0, dump.stdout + dump.stderr);
        const connection = await createMysqlConnection();
        try {
            await connection.query(`CREATE DATABASE \`${database}\``);
            for (const target of ['http://256.1.1.1', 'http://[invalid]', 'https://target.test:65536', 'https://target.test/path']) {
                const result = runImporter(url, directory, 'db-apply', {
                    secret: getSiteSecret(site), autoResume: false,
                    extraArgs: [...targetArgs(database).filter(arg => !arg.startsWith('--new-site-url=')),
                        `--new-site-url=${target}`],
                });
                assert.equal(result.exitCode, 1, result.stdout + result.stderr);
                assert.ok((result.stdout + result.stderr).includes('--new-site-url'), result.stdout + result.stderr);
                const [tables] = await connection.query(`SHOW TABLES FROM \`${database}\``);
                assert.deepEqual(tables, [], 'Invalid user input must not create even the progress table.');
            }
            const result = runImporter(url, directory, 'db-apply', {
                secret: getSiteSecret(site), autoResume: false,
                extraArgs: [...targetArgs(database).filter(arg => !arg.startsWith('--new-site-url=')),
                    '--new-site-url=HTTP://0x7f000001:9247/'],
            });
            assert.equal(result.exitCode, 0, result.stdout + result.stderr);
            const [[home]] = await connection.query(`SELECT option_value FROM \`${database}\`.network_7_options WHERE option_name='home'`);
            assert.equal(home.option_value, targetUrl);
        } finally { await connection.end(); }
    });

    it('rejects direct MySQL output before replacing an existing site table', async () => {
        const directory = createTempDir('e2e-multisite-direct');
        directories.push(directory);
        const database = 'e2e_multisite_direct_target';
        databases.push(database);
        const connection = await createMysqlConnection();
        try {
            await connection.query(`CREATE DATABASE \`${database}\``);
            await connection.query(`CREATE TABLE \`${database}\`.network_7_posts (ID bigint PRIMARY KEY, post_content text)`);
            await connection.query(`INSERT INTO \`${database}\`.network_7_posts VALUES (999, 'Existing target content')`);
            const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, directory, 'db-pull', {
                secret: getSiteSecret(site), autoResume: false,
                extraArgs: ['--sql-output=mysql', '--mysql-host=127.0.0.1', '--mysql-user=e2e_admin',
                    '--mysql-password=e2e_password', `--mysql-database=${database}`],
            });
            const [rows] = await connection.query(`SELECT ID, post_content FROM \`${database}\`.network_7_posts`);
            assert.deepEqual(rows.map(row => [Number(row.ID), row.post_content]), [[999, 'Existing target content']]);
            const [tables] = await connection.query(`SHOW TABLES FROM \`${database}\``);
            assert.deepEqual(tables.map(row => Object.values(row)[0]), ['network_7_posts']);
            assert.equal(result.exitCode, 1);
            assert.ok((result.stdout + result.stderr).includes('Use pull-db with an empty MySQL target'));
        } finally { await connection.end(); }
    });

    it('waits for the target database lock before creating tables, then completes', async () => {
        const directory = createTempDir('e2e-multisite-lock');
        directories.push(directory);
        const database = 'e2e_multisite_lock_target';
        databases.push(database);
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const dump = runImporter(url, directory, 'db-pull', { secret: getSiteSecret(site), autoResume: false });
        assert.equal(dump.exitCode, 0, dump.stdout + dump.stderr);
        const connection = await createMysqlConnection();
        const lock = 'reprint-db-pull-' + createHash('sha256').update(database).digest('hex').slice(0, 40);
        let clientProcess;
        try {
            await connection.query(`CREATE DATABASE \`${database}\``);
            const [[held]] = await connection.query('SELECT GET_LOCK(?, 0) AS acquired', [lock]);
            assert.equal(Number(held.acquired), 1);
            clientProcess = startClient([clientPath, 'db-apply', url, `--state-dir=${directory}`, `--fs-root=${join(directory, 'fs-root')}`,
                ...targetArgs(database)]);
            let waiting = false;
            for (let attempt = 0; attempt < 600; ++attempt) {
                const [rows] = await connection.query("SELECT ID FROM information_schema.PROCESSLIST WHERE DB=? AND INFO LIKE 'SELECT GET_LOCK(%'", [database]);
                if (rows.length) { waiting = true; break; }
                if (clientProcess.child.exitCode !== null || clientProcess.child.signalCode !== null) break;
                await sleep(100);
            }
            assert.ok(waiting, 'The importer must wait for the target lock. ' + clientProcess.output());
            const [tables] = await connection.query(`SHOW TABLES FROM \`${database}\``);
            assert.deepEqual(tables, [], 'Waiting for another connection must not create the progress table');
            await connection.query('SELECT RELEASE_LOCK(?)', [lock]);
            const result = await clientProcess.finished;
            assert.equal(result.code, 0, clientProcess.output());
            const [sites] = await connection.query(`SELECT blog_id FROM \`${database}\`.network_blogs`);
            assert.deepEqual(sites.map(row => Number(row.blog_id)), [7]);
        } finally {
            if (clientProcess && clientProcess.child.exitCode === null && clientProcess.child.signalCode === null) {
                process.kill(-clientProcess.child.pid, 'SIGKILL');
                await clientProcess.finished;
            }
            await connection.end();
        }
    }, 180000);

    it('resumes cleanup after MySQL rejects the plugin-list replacement without losing site plugins', async () => {
        const directory = createTempDir('e2e-multisite-rejected-activation');
        directories.push(directory);
        const database = 'e2e_multisite_rejected_activation';
        databases.push(database);
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const dump = runImporter(url, directory, 'db-pull', { secret: getSiteSecret(site), autoResume: false });
        assert.equal(dump.exitCode, 0, dump.stdout + dump.stderr);
        const connection = await createMysqlConnection();
        const marker = join(directory, 'paused');
        const statePath = join(pullStateDirectory(directory, url), 'state.json');
        let clientProcess;
        try {
            await connection.query(`CREATE DATABASE \`${database}\``);
            clientProcess = startClient([join(import.meta.dirname, '../fixtures/pause-multisite-apply.php'),
                clientPath, url, directory, database, 'database-cleanup', 'after', marker, targetUrl]);
            for (let attempt = 0; attempt < 600 && !existsSync(marker); ++attempt) {
                if (clientProcess.child.exitCode !== null || clientProcess.child.signalCode !== null) break;
                await sleep(100);
            }
            assert.ok(existsSync(marker), clientProcess.output());
            process.kill(-clientProcess.child.pid, 'SIGKILL');
            assert.equal((await clientProcess.finished).signal, 'SIGKILL');
            assert.equal(JSON.parse(readFileSync(statePath, 'utf8')).active_resumable_command.current_stage, 'database-cleanup');

            // Inject one real SQL statement failure, after the dump is committed.
            // Earlier cleanup writes succeed; only the activation write fails.
            await connection.query(`CREATE TRIGGER \`${database}\`.reject_activation BEFORE INSERT ON \`${database}\`.network_7_options
                FOR EACH ROW BEGIN
                    IF NEW.option_name = 'active_plugins' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected activation write failure';
                    END IF;
                END`);
            const rejected = runImporter(url, directory, 'db-apply', {
                secret: getSiteSecret(site), autoResume: false, extraArgs: targetArgs(database),
            });
            assert.equal(rejected.exitCode, 1, rejected.stdout + rejected.stderr);
            assert.ok((rejected.stdout + rejected.stderr).includes('Injected activation write failure'));
            assert.equal(JSON.parse(readFileSync(statePath, 'utf8')).active_resumable_command.current_stage, 'database-cleanup');
            const [[partialGrant]] = await connection.query(`SELECT meta_value FROM \`${database}\`.network_usermeta m
                JOIN \`${database}\`.network_users u ON m.user_id=u.ID WHERE u.user_login='shared' AND meta_key='network_7_capabilities'`);
            assert.ok(partialGrant.meta_value.includes('s:13:"administrator";b:1;'), 'Earlier cleanup writes must have reached MySQL');
            const [activation] = await connection.query(`SELECT option_value FROM \`${database}\`.network_7_options WHERE option_name='active_plugins'`);
            assert.deepEqual(activation.map(row => row.option_value), ['a:1:{i:0;s:19:"a-local-fixture.php";}'],
                'The site-only plugin list must survive a rejected replacement');

            await connection.query(`DROP TRIGGER \`${database}\`.reject_activation`);
            const resumed = runImporter(url, directory, 'db-apply', {
                secret: getSiteSecret(site), autoResume: false, extraArgs: targetArgs(database),
            });
            assert.equal(resumed.exitCode, 0, resumed.stdout + resumed.stderr);
            const [[merged]] = await connection.query(`SELECT option_value FROM \`${database}\`.network_7_options WHERE option_name='active_plugins'`);
            assert.equal(merged.option_value, 'a:2:{i:0;s:19:"network-fixture.php";i:1;s:19:"a-local-fixture.php";}',
                'Resume must keep network-first order and remove the excluded cache plugin');
            const [grants] = await connection.query(`SELECT meta_key, meta_value FROM \`${database}\`.network_usermeta m
                JOIN \`${database}\`.network_users u ON m.user_id=u.ID WHERE u.user_login='shared'
                AND meta_key IN ('network_7_capabilities', 'network_7_user_level') ORDER BY meta_key`);
            assert.deepEqual(grants.map(row => [row.meta_key, row.meta_value]), [
                ['network_7_capabilities', 'a:2:{s:6:"editor";b:1;s:13:"administrator";b:1;}'],
                ['network_7_user_level', '10'],
            ], 'Repeated cleanup must preserve the existing role without adding duplicate grants');
            const [[member]] = await connection.query(`SELECT meta_value FROM \`${database}\`.network_usermeta m
                JOIN \`${database}\`.network_users u ON m.user_id=u.ID WHERE u.user_login='shop-member' AND meta_key='network_7_capabilities'`);
            assert.equal(member.meta_value, 'a:1:{s:10:"subscriber";b:1;}');
            const [[post]] = await connection.query(`SELECT post_content FROM \`${database}\`.network_7_posts WHERE ID=100`);
            assert.equal(post.post_content, 'Only site 7');
            const [tables] = await connection.query(`SHOW TABLES FROM \`${database}\``);
            assert.ok(tables.every(row => !Object.values(row)[0].startsWith('__reprint_')));
            assert.equal(JSON.parse(readFileSync(statePath, 'utf8')).active_resumable_command.completion_state, 'complete');
        } finally {
            if (clientProcess && clientProcess.child.exitCode === null && clientProcess.child.signalCode === null) {
                process.kill(-clientProcess.child.pid, 'SIGKILL');
                await clientProcess.finished;
            }
            await connection.end();
        }
    }, 180000);

    // Kill on both sides of each durable boundary: before progress-table creation,
    // before SQL starts, and before cleanup. No private state is rewritten.
    for (const [stage, when] of ['database-initialize', 'sql', 'database-cleanup']
        .flatMap(stage => ['before', 'after'].map(when => [stage, when]))) {
        it(`resumes after process death ${when} saving ${stage}`, async () => {
            const directory = createTempDir('e2e-multisite-killed');
            directories.push(directory);
            const database = `e2e_multisite_killed_${stage.replaceAll('-', '_')}_${when}`;
            databases.push(database);
            const url = `${fixture.sites[7].url}/?reprint-api`;
            const dump = runImporter(url, directory, 'db-pull', { secret: getSiteSecret(site), autoResume: false });
            assert.equal(dump.exitCode, 0, dump.stdout + dump.stderr);
            const connection = await createMysqlConnection();
            const marker = join(directory, 'paused');
            let clientProcess;
            try {
                await connection.query(`CREATE DATABASE \`${database}\``);
                clientProcess = startClient([join(import.meta.dirname, '../fixtures/pause-multisite-apply.php'),
                    clientPath, url, directory, database, stage, when, marker, targetUrl]);
                for (let attempt = 0; attempt < 600 && !existsSync(marker); ++attempt) {
                    if (clientProcess.child.exitCode !== null || clientProcess.child.signalCode !== null) break;
                    await sleep(100);
                }
                assert.ok(existsSync(marker), clientProcess.output());
                process.kill(-clientProcess.child.pid, 'SIGKILL');
                const killed = await clientProcess.finished;
                assert.equal(killed.signal, 'SIGKILL');
                const state = JSON.parse(readFileSync(join(pullStateDirectory(directory, url), 'state.json'), 'utf8'));
                const active = state.active_resumable_command;
                const priorStage = { 'database-initialize': 'database-start', sql: 'database-initialize', 'database-cleanup': 'sql' };
                assert.equal(active.current_stage, when === 'after' ? stage : priorStage[stage]);
                if (stage === 'sql') {
                    const otherDatabase = database + '_other';
                    databases.push(otherDatabase);
                    await connection.query(`CREATE DATABASE \`${otherDatabase}\``);
                    await connection.query(`CREATE TABLE \`${otherDatabase}\`.keep_this (value text)`);
                    await connection.query(`INSERT INTO \`${otherDatabase}\`.keep_this VALUES ('Existing local data')`);
                    const changedTarget = runImporter(url, directory, 'db-apply', {
                        secret: getSiteSecret(site), autoResume: false, extraArgs: targetArgs(otherDatabase),
                    });
                    assert.equal(changedTarget.exitCode, 1);
                    assert.ok((changedTarget.stdout + changedTarget.stderr).includes('Cannot change --target-db'));
                    const [[kept]] = await connection.query(`SELECT value FROM \`${otherDatabase}\`.keep_this`);
                    assert.equal(kept.value, 'Existing local data');

                    // Another application can populate the same target while
                    // the importer is stopped. Its old empty check is not enough.
                    await connection.query(`CREATE TABLE \`${database}\`.network_7_posts (ID bigint PRIMARY KEY, post_content text)`);
                    await connection.query(`INSERT INTO \`${database}\`.network_7_posts VALUES (999, 'Created while stopped')`);
                    const occupied = runImporter(url, directory, 'db-apply', {
                        secret: getSiteSecret(site), autoResume: false, extraArgs: targetArgs(database),
                    });
                    const [rows] = await connection.query(`SELECT ID, post_content FROM \`${database}\`.network_7_posts`);
                    assert.deepEqual(rows.map(row => [Number(row.ID), row.post_content]), [[999, 'Created while stopped']]);
                    assert.equal(occupied.exitCode, 1);
                    assert.ok((occupied.stdout + occupied.stderr).includes('empty target database; found table network_7_posts'));
                    await connection.query(`DROP TABLE \`${database}\`.network_7_posts`);
                }
                const resumed = runImporter(url, directory, 'db-apply', {
                    secret: getSiteSecret(site), autoResume: false, extraArgs: targetArgs(database),
                });
                assert.equal(resumed.exitCode, 0, resumed.stdout + resumed.stderr);
                const [sites] = await connection.query(`SELECT blog_id FROM \`${database}\`.network_blogs`);
                assert.deepEqual(sites.map(row => Number(row.blog_id)), [7]);
                const [[post]] = await connection.query(`SELECT post_content FROM \`${database}\`.network_7_posts WHERE ID=100`);
                assert.equal(post.post_content, 'Only site 7');
                const [[activation]] = await connection.query(`SELECT option_value FROM \`${database}\`.network_7_options WHERE option_name='active_plugins'`);
                assert.equal(activation.option_value, 'a:2:{i:0;s:19:"network-fixture.php";i:1;s:19:"a-local-fixture.php";}');
                const [[grant]] = await connection.query(`SELECT meta_value FROM \`${database}\`.network_usermeta m JOIN \`${database}\`.network_users u ON m.user_id=u.ID WHERE u.user_login='shared' AND m.meta_key='network_7_capabilities'`);
                assert.ok(grant.meta_value.includes('s:13:"administrator";b:1;'));
                const [tables] = await connection.query(`SHOW TABLES FROM \`${database}\``);
                assert.ok(tables.every(row => !Object.values(row)[0].startsWith('__reprint_')));
            } finally {
                if (clientProcess && clientProcess.child.exitCode === null && clientProcess.child.signalCode === null) {
                    process.kill(-clientProcess.child.pid, 'SIGKILL');
                    await clientProcess.finished;
                }
                await connection.end();
            }
        }, 180000);
    }
});

function targetArgs(database) {
    return ['--target-engine=mysql', '--target-host=127.0.0.1', '--target-user=e2e_admin', '--target-pass=e2e_password',
        `--target-db=${database}`, `--new-site-url=${targetUrl}`, '--site-admin=shared', '--exclude-host-plugins'];
}

function startClient(args) {
    const child = spawn(process.env.PHP_BINARY || 'php', args, { detached: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let output = '';
    child.stdout.on('data', data => { output += data; });
    child.stderr.on('data', data => { output += data; });
    const finished = new Promise((resolve, reject) => {
        child.once('error', reject);
        child.once('close', (code, signal) => resolve({ code, signal }));
    });
    return { child, finished, output: () => output };
}
