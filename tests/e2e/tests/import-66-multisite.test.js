/**
 * A real HTTP pull from a populated multisite network, followed by WordPress
 * boot, login, admin, media reads, and a new upload at the target.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { inspect } from 'node:util';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir, getSiteDir, getSiteUrl, getSiteSecret,
    createMysqlConnection, apiRequest, apiRequestWithFileList,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const site = 'multisite';
const sourcePort = new URL(getSiteUrl(site)).port;
const php = process.env.E2E_WP_CLI_PHP_BINARY || 'php';
const fixtureScript = join(import.meta.dirname, '../fixtures/multisite-network.php');

describe('Pull one site out of an overlapping multisite network', () => {
    let fixture;
    let sourceChecksums;
    const targets = [];

    beforeAll(async () => {
        await ensureSite(site, {
            tablePrefix: 'network_',
            files: 'none',
            afterCreate: async (directory) => {
                wp(directory, ['core', 'multisite-convert', '--title=Source network', '--base=/', '--skip-config']);
                const configPath = join(directory, 'wp-config.php');
                const constants = `define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);
define('DOMAIN_CURRENT_SITE', '127.0.0.1:${sourcePort}');
define('PATH_CURRENT_SITE', '/');
define('SITE_ID_CURRENT_SITE', 1);
define('BLOG_ID_CURRENT_SITE', 1);
`;
                writeFileSync(configPath, readFileSync(configPath, 'utf8').replace('$table_prefix =', constants + '$table_prefix ='));
                wp(directory, ['plugin', 'activate', 'reprint-server', '--network']);
                wp(directory, ['eval-file', fixtureScript]);
                // A fresh boot sees the catalog written after WordPress first cached its language directory.
                wp(directory, ['eval', "update_site_option('WPLANG', 'pl_PL');"]);
            },
        });
        fixture = JSON.parse(readFileSync(join(getSiteDir(site), '.multisite-fixture.json'), 'utf8'));
        assert.deepEqual(Object.keys(fixture.sites), ['1', '7', '8', '9']);
        for (const [id, path] of [[1, ''], [7, '/shop'], [8, '/sibling']]) {
            const url = `http://127.0.0.1:${sourcePort}${path}`;
            assert.equal(fixture.sites[id].url, url, `Site ${id} must retain the local server port`);
            const preflight = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' }, { url: `${url}/?reprint-api` });
            assert.equal(preflight.status, 200, JSON.stringify(preflight.json));
            assert.equal(preflight.json.database.wp.multisite.selection.site_id, id);
        }
        assert.deepEqual(JSON.parse(wp(getSiteDir(site), ['eval', 'echo json_encode([get_locale(), get_option("WPLANG"), get_site_option("WPLANG")]);'], fixture.sites[7].url)), ['pl_PL', false, 'pl_PL']);
        sourceChecksums = await checksums();
    });

    afterAll(async () => {
        const connection = await createMysqlConnection();
        for (const target of targets) {
            target.server?.kill('SIGTERM');
            await connection.query(`DROP DATABASE IF EXISTS \`${target.database}\``);
            cleanupTempDir(target.directory);
        }
        await connection.end();
    });

    for (const selectedId of [7, 1]) {
        it(`migrates site ${selectedId} while keeping sibling data at the source`, async () => {
            const selected = fixture.sites[selectedId];
            const directory = createTempDir(`e2e-multisite-${selectedId}`);
            const target = {
                directory, database: `e2e_multisite_target_${selectedId}`,
                documentRoot: join(directory, 'site'), port: 9140 + selectedId,
            };
            targets.push(target);
            const connection = await createMysqlConnection();
            await connection.query(`DROP DATABASE IF EXISTS \`${target.database}\``);
            await connection.query(`CREATE DATABASE \`${target.database}\``);
            await connection.end();
            const targetUrl = `http://localhost:${target.port}`;
            const sourceUrl = `${selected.url}/?reprint-api`;
            const result = runImporter(sourceUrl, directory, 'pull', {
                secret: getSiteSecret(site), skipPreflight: true,
                timeout: 240000, wallTimeout: 300000, autoResume: false,
                extraArgs: [
                    '--target-engine=mysql', '--target-host=127.0.0.1',
                    '--target-user=e2e_admin', '--target-pass=e2e_password',
                    `--target-db=${target.database}`, `--new-site-url=${targetUrl}`,
                    `--site-admin=${selectedId === 7 ? 'chosen' : 'admin'}`,
                    '--runtime=php-builtin', '--start-runtime=none',
                    `--flatten-to=${target.documentRoot}`,
                ],
            });
            assert.equal(result.exitCode, 0, `${result.stderr}\n${result.stdout}`);

            const imported = await createMysqlConnection(target.database);
            try {
                const [tables] = await imported.query('SHOW TABLES');
                const tableNames = tables.map(row => Object.values(row)[0]);
                assert.ok(tableNames.includes(`${selected.prefix}posts`));
                assert.ok(tableNames.every(name => !name.endsWith('reprint_users')), 'Source user discovery tables must not reach the target');
                assert.ok(!tableNames.includes('network_8_posts'));
                assert.ok(!tableNames.includes('network_9_posts'));
                assert.ok(!tableNames.includes(selectedId === 7 ? 'network_posts' : 'network_7_posts'));
                const [sites] = await imported.query('SELECT blog_id FROM network_blogs');
                assert.deepEqual(sites.map(row => Number(row.blog_id)), [selectedId]);
                const [networks] = await imported.query('SELECT id FROM network_site');
                assert.deepEqual(networks.map(row => Number(row.id)), [1]);
                if (selectedId === 7) {
                    const [users] = await imported.query('SELECT user_login FROM network_users ORDER BY user_login');
                    assert.deepEqual(users.map(row => row.user_login), ['chosen', 'commenter', 'empty-member', 'former-author', 'link-author']);
                    const [metadata] = await imported.query('SELECT meta_key, meta_value FROM network_usermeta');
                    assert.ok(metadata.some(row => row.meta_key === 'network_7_capabilities' && row.meta_value.includes('editor')));
                    assert.ok(metadata.every(row => !['network_8_capabilities', 'network_capabilities', 'session_tokens', '_application_passwords', 'source_plugin_private'].includes(row.meta_key)));
                }
                const [privateSettings] = await imported.query("SELECT * FROM network_sitemeta WHERE meta_key='private_network_plugin_setting'");
                assert.equal(privateSettings.length, 0);
                const [[post]] = await imported.query(`SELECT post_content FROM \`${selected.prefix}posts\` WHERE ID=100`);
                assert.ok(post.post_content.includes(`site-${selectedId}-content`));
                const [[comment]] = await imported.query(`SELECT comment_content, user_id FROM \`${selected.prefix}comments\` WHERE comment_post_ID=100`);
                assert.equal(comment.comment_content, `site-${selectedId}-comment`);
                assert.equal(Number(comment.user_id), fixture.users.commenter);
                const [[term]] = await imported.query(`SELECT m.meta_value FROM \`${selected.prefix}term_relationships\` r JOIN \`${selected.prefix}term_taxonomy\` t USING (term_taxonomy_id) JOIN \`${selected.prefix}termmeta\` m USING (term_id) WHERE r.object_id=100 AND m.meta_key='site_marker'`);
                assert.equal(term.meta_value, `site-${selectedId}`);
                // The main site's root also contains /sibling. Its saved path
                // set keeps those links remote without adding rewrite rules.
                const siblingOrigin = `http://127.0.0.1:${sourcePort}`;
                assert.ok(post.post_content.includes(`${siblingOrigin}/sibling/`));
                const relativeOrigin = siblingOrigin;
                assert.ok(post.post_content.includes(`href="${relativeOrigin}/sibling/?p=100"`));
                assert.ok(post.post_content.includes('href="/local-page"'));
            } finally {
                await imported.end();
            }

            const uploadRelative = `wp-content/uploads${selectedId === 1 ? '' : '/sites/' + selectedId}`;
            const mediaRelative = selected.media_file.slice(selected.media_file.indexOf('/wp-content/') + 1);
            assert.equal(createHash('sha256').update(readFileSync(join(target.documentRoot, mediaRelative))).digest('hex'), selected.media_sha256);
            assert.ok(!existsSync(join(target.documentRoot, 'wp-content/uploads/sites/8')));
            assert.ok(!existsSync(join(target.documentRoot, 'wp-content/plugins/reprint-server/secret.php')));
            if (selectedId === 7) {
                const mainMedia = fixture.sites[1].media_file;
                assert.ok(!existsSync(join(target.documentRoot, mainMedia.slice(mainMedia.indexOf('/wp-content/') + 1))));
            }

            const inspection = JSON.parse(wp(target.documentRoot, ['eval', `
                $uploads = wp_upload_dir();
                $new = wp_upload_bits('after-migration.txt', null, 'new-target-media');
                echo json_encode([
                    'multisite' => is_multisite(), 'locale' => get_locale(),
                    'translation' => __('Migration language fixture'),
                    'site_id' => get_current_blog_id(), 'prefix' => $GLOBALS['wpdb']->prefix,
                    'users_table' => $GLOBALS['wpdb']->users,
                    'usermeta_table' => $GLOBALS['wpdb']->usermeta,
                    'is_admin' => user_can(get_user_by('login', '${selectedId === 7 ? 'chosen' : 'admin'}'), 'manage_options'),
                    'created_user' => wp_create_user('target-only', 'target-password', 'target-only@example.test'),
                    'network_plugin' => defined('MULTISITE_SHARED_NETWORK_PLUGIN'),
                    'mu_plugin' => defined('MULTISITE_SHARED_MU_PLUGIN'),
                    'upload_url' => $uploads['baseurl'], 'new_url' => $new['url'],
                    'attachment' => wp_get_attachment_url(200),
                    'thumbnail' => get_post_thumbnail_id(100),
                    'thumbnail_url' => wp_get_attachment_image_url(200, 'thumbnail'),
                    'thumbnail_metadata' => wp_get_attachment_metadata(200)['sizes']['thumbnail'],
                    'settings' => get_option('site_plugin_settings'),
                ]);
            `], targetUrl));
            assert.equal(inspection.multisite, false);
            assert.equal(inspection.site_id, 1);
            assert.equal(inspection.locale, selectedId === 7 ? 'pl_PL' : 'en_US');
            assert.equal(inspection.translation, selectedId === 7 ? 'Test języka migracji' : 'Migration language fixture');
            assert.equal(inspection.prefix, selected.prefix);
            assert.equal(inspection.users_table, 'network_users');
            assert.equal(inspection.usermeta_table, 'network_usermeta');
            assert.equal(inspection.is_admin, true);
            assert.equal(typeof inspection.created_user, 'number');
            const usersDatabase = await createMysqlConnection(target.database);
            try {
                const [[created]] = await usersDatabase.query("SELECT ID FROM network_users WHERE user_login='target-only'");
                assert.equal(Number(created.ID), inspection.created_user);
                const [tables] = await usersDatabase.query('SHOW TABLES');
                assert.ok(!tables.some(row => Object.values(row)[0] === 'network_7_users'));
            } finally { await usersDatabase.end(); }
            assert.equal(inspection.network_plugin, true);
            assert.equal(inspection.mu_plugin, true);
            assert.equal(inspection.upload_url, `${targetUrl}/${uploadRelative}`);
            assert.ok(inspection.new_url.startsWith(inspection.upload_url + '/'));
            assert.equal(Number(inspection.thumbnail), 200);
            assert.equal(inspection.settings.url, inspection.attachment);
            assert.equal(inspection.settings.sibling_media, fixture.sites[8].media_url);
            assert.deepEqual(inspection.settings.cross_site_reference, { site_id: 8, post_id: 100 });
            assert.equal(inspection.thumbnail_metadata.width, 150);
            assert.equal(inspection.thumbnail_metadata.height, 150);
            assert.ok(inspection.attachment.startsWith(inspection.upload_url + '/'));

            let serverLog = '';
            target.server = spawn(php, ['-S', `127.0.0.1:${target.port}`, '-t', target.documentRoot, join(directory, 'runtime/runtime.php')], { stdio: ['ignore', 'pipe', 'pipe'] });
            target.server.stdout.on('data', data => { serverLog += data; });
            target.server.stderr.on('data', data => { serverLog += data; });
            // A refused connection after the server accepted a request hides
            // the first failure. Retain that error and the child's exit reason.
            target.server.on('exit', (code, signal) => { serverLog += `PHP server exited: code=${code}, signal=${signal}\n`; });
            let response;
            let firstRequestError;
            let lastRequestError;
            for (let attempt = 0; attempt < 100; ++attempt) {
                try { response = await fetch(`${targetUrl}/?p=100`); break; }
                catch (error) { firstRequestError ??= error; lastRequestError = error; await sleep(100); }
            }
            assert.ok(response, serverLog + '\n' + inspect({ firstRequestError, lastRequestError }, { depth: 4 }));
            const html = await response.text();
            assert.equal(response.status, 200, html + serverLog);
            assert.ok(html.includes(`site-${selectedId}-content`));
            assert.ok(html.includes('network-plugin-site-1'));
            const media = await fetch(inspection.attachment);
            assert.equal(media.status, 200);
            assert.equal(createHash('sha256').update(Buffer.from(await media.arrayBuffer())).digest('hex'), selected.media_sha256);
            const thumbnail = await fetch(inspection.thumbnail_url);
            assert.equal(thumbnail.status, 200);
            assert.equal(createHash('sha256').update(Buffer.from(await thumbnail.arrayBuffer())).digest('hex'), selected.thumbnail_sha256);
            assert.equal(await (await fetch(inspection.new_url)).text(), 'new-target-media');
            if (selectedId === 7) {
                const loginPage = await fetch(`${targetUrl}/wp-login.php`);
                const cookie = loginPage.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
                const login = await fetch(`${targetUrl}/wp-login.php`, {
                    method: 'POST', redirect: 'manual',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie },
                    body: new URLSearchParams({ log: 'chosen', pwd: 'multisite-password', testcookie: '1', redirect_to: `${targetUrl}/wp-admin/options-general.php` }),
                });
                assert.equal(login.status, 302, await login.text());
                const authCookies = login.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
                assert.ok(authCookies.includes('wordpress_logged_in_'));
                const admin = await fetch(`${targetUrl}/wp-admin/options-general.php`, { headers: { Cookie: authCookies } });
                assert.equal(admin.status, 200, await admin.text());
            }
            target.server.kill('SIGTERM');
            target.server = null;
            assert.deepEqual(await checksums(), sourceChecksums, 'Source WordPress tables must not change');
            for (const sourceSite of Object.values(fixture.sites)) {
                assert.equal(createHash('sha256').update(readFileSync(sourceSite.media_file)).digest('hex'), sourceSite.media_sha256);
            }
        }, 300000);
    }

    it('rejects an existing target database before importing site tables', async () => {
        const target = { directory: createTempDir('e2e-multisite-nonempty'), database: 'e2e_multisite_nonempty' };
        targets.push(target);
        const connection = await createMysqlConnection();
        await connection.query(`CREATE DATABASE \`${target.database}\``);
        await connection.query(`CREATE TABLE \`${target.database}\`.keep_this (value text)`);
        await connection.query(`INSERT INTO \`${target.database}\`.keep_this VALUES ('existing local data')`);
        try {
            const result = runImporter(`${fixture.sites[7].url}/?reprint-api`, target.directory, 'pull-db', {
                secret: getSiteSecret(site), skipPreflight: true, autoResume: false,
                extraArgs: ['--target-engine=mysql', '--target-host=127.0.0.1',
                    '--target-user=e2e_admin', '--target-pass=e2e_password', `--target-db=${target.database}`,
                    '--new-site-url=http://localhost:9147', '--site-admin=chosen'],
            });
            assert.equal(result.exitCode, 1);
            assert.ok((result.stdout + result.stderr).includes('empty target database; found table keep_this'));
            const [[row]] = await connection.query(`SELECT value FROM \`${target.database}\`.keep_this`);
            assert.equal(row.value, 'existing local data');
            const [tables] = await connection.query(`SHOW TABLES FROM \`${target.database}\``);
            assert.deepEqual(tables.map(row => Object.values(row)[0]), ['keep_this']);
        } finally {
            await connection.end();
        }
    });

    it('rejects direct requests for sibling media and source credentials', async () => {
        const url = `${fixture.sites[7].url}/?reprint-api`;
        for (const path of [fixture.sites[1].media_file, fixture.sites[8].media_file, fixture.sites[9].media_file,
            join(getSiteDir(site), 'wp-config.php'), join(getSiteDir(site), 'wp-content/plugins/reprint-server/secret.php')]) {
            const response = await apiRequestWithFileList(site, [path], { multisite_mode: 'one-site-network-v1' }, { url });
            const error = response.chunks.find(chunk => chunk.type === 'error');
            assert.equal(error?.json?.message, `Path is outside the selected multisite site: ${path}`, JSON.stringify(response));
            assert.ok(response.chunks.every(chunk => chunk.type !== 'file'), 'No excluded file bytes may be sent');
        }
    });

    it('rejects plugin tables even when registered in WordPress table lists', async () => {
        const connection = await createMysqlConnection('e2e_multisite');
        const plugin = join(getSiteDir(site), 'wp-content/mu-plugins/registered-table.php');
        await connection.query('CREATE TABLE network_plugin_site (id int PRIMARY KEY, value text)');
        try {
            execFileSync('sudo', ['tee', plugin], { input: "<?php $GLOBALS['wpdb']->tables[] = 'plugin_site';\n" });
            const response = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' });
            assert.ok(JSON.stringify(response).includes('No multisite migration rule exists for table network_plugin_site.'), JSON.stringify(response));
        } finally {
            execFileSync('sudo', ['rm', '-f', plugin]);
            await connection.query('DROP TABLE network_plugin_site');
            await connection.end();
        }
    });

    it('rejects unknown shared plugin storage before exporting it', async () => {
        const connection = await createMysqlConnection('e2e_multisite');
        await connection.query('CREATE TABLE network_plugin_shared (id int PRIMARY KEY, value text)');
        try {
            const response = await apiRequest(site, 'preflight', { multisite_mode: 'one-site-network-v1' });
            assert.ok(JSON.stringify(response).includes('network_plugin_shared'));
        } finally {
            await connection.query('DROP TABLE network_plugin_shared');
            await connection.end();
        }
    });

    async function checksums() {
        const connection = await createMysqlConnection('e2e_multisite');
        try {
            const [tables] = await connection.query('SHOW TABLES');
            // Only Reprint's two site-specific working tables may be added.
            // Keep checking every WordPress/plugin table and all of its rows.
            const names = tables.map(row => Object.values(row)[0])
                .filter(name => !['network_reprint_users', 'network_7_reprint_users'].includes(name)).sort();
            const [rows] = await connection.query('CHECKSUM TABLE ' + names.map(name => `\`${name}\``).join(',') + ' EXTENDED');
            return rows;
        } finally {
            await connection.end();
        }
    }
});

function wp(directory, args, url = null) {
    return execFileSync(php, [
        '/tmp/wp-cli.phar', '--allow-root', `--path=${directory}`,
        ...(url ? [`--url=${url}`] : []), ...args,
    ], { encoding: 'utf8', timeout: 120000, maxBuffer: 10 * 1024 * 1024 });
}
