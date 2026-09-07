import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir, getSiteSecret, getSiteDir, createMysqlConnection, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureMultisite, runWp } from '../lib/multisite-setup.js';

const site = 'multisite-target';
const targetUrl = 'http://localhost:9247';
const databases = ['e2e_multisite_boot_target', 'e2e_multisite_existing_target'];
const clientPath = process.env.CLIENT_PATH || join(import.meta.dirname, '../../../packages/reprint-client/bin/reprint-client');

describe('Pull a selected site into a fresh single site', () => {
    let fixture;
    let server;
    const directories = [];
    beforeAll(async () => {
        fixture = await ensureMultisite(site);
        runWp(getSiteDir(site), ['eval', `
            file_put_contents(WP_PLUGIN_DIR . '/network-fixture.php', "<?php /* Plugin Name: Network fixture */ define('SINGLE_SITE_NETWORK_PLUGIN', true);");
            if (!is_dir(WP_PLUGIN_DIR . '/redis-cache')) { mkdir(WP_PLUGIN_DIR . '/redis-cache'); }
            file_put_contents(WP_PLUGIN_DIR . '/redis-cache/redis-cache.php', "<?php /* Plugin Name: Excluded cache fixture */ define('SINGLE_SITE_EXCLUDED_PLUGIN', true);");
            activate_plugin('network-fixture.php', '', true);
            file_put_contents(WP_PLUGIN_DIR . '/a-local-fixture.php', "<?php /* Plugin Name: Local fixture */ define('SINGLE_SITE_PLUGIN_ORDER', defined('SINGLE_SITE_NETWORK_PLUGIN'));");
            switch_to_blog(7);
            activate_plugin('a-local-fixture.php');
            restore_current_blog();
            activate_plugin('redis-cache/redis-cache.php', '', true);
        `]);
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
                `--new-site-url=${targetUrl}`, '--site-admin=SHARED',
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
        const login = await fetch(`${targetUrl}/wp-login.php`, {
            method: 'POST', redirect: 'manual',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie },
            body: new URLSearchParams({ log: 'shared', pwd: 'multisite-password', testcookie: '1', redirect_to: `${targetUrl}/wp-admin/options-general.php` }),
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
                    clientPath, url, directory, database, stage, when, marker]);
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
        `--target-db=${database}`, `--new-site-url=${targetUrl}`, '--site-admin=shared'];
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
