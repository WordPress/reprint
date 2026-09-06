import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir, getSiteSecret, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureMultisite, runWp } from '../lib/multisite-setup.js';

const site = 'multisite-target';
const targetUrl = 'http://localhost:9247';
const databases = ['e2e_multisite_boot_target', 'e2e_multisite_existing_target'];

describe('Pull a selected site into a fresh one-site network', () => {
    let fixture;
    let server;
    const directories = [];
    beforeAll(async () => { fixture = await ensureMultisite(site); });
    afterAll(async () => {
        server?.kill('SIGTERM');
        const connection = await createMysqlConnection();
        try {
            for (const database of databases) await connection.query(`DROP DATABASE IF EXISTS \`${database}\``);
        } finally { await connection.end(); }
        for (const directory of directories) cleanupTempDir(directory);
    });

    it('boots site 7 as the only network site and serves old and new uploads', async () => {
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
                `--new-site-url=${targetUrl}`, '--network-admin=shared',
                '--runtime=php-builtin', '--start-runtime=none', `--flatten-to=${join(directory, 'site')}`,
            ],
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        const documentRoot = join(directory, 'site');
        const inspection = JSON.parse(runWp(documentRoot, ['eval', `
            $new = wp_upload_bits('new-target.txt', null, 'New target upload');
            echo json_encode([
                'multisite' => is_multisite(), 'main' => is_main_site(),
                'id' => get_current_blog_id(), 'prefix' => $GLOBALS['wpdb']->prefix,
                'sites' => array_map('intval', get_sites(['fields' => 'ids'])),
                'admins' => get_super_admins(), 'media' => wp_get_attachment_url(200),
                'new_url' => $new['url'], 'upload_error' => $new['error'],
            ]);
        `], targetUrl));
        assert.equal(inspection.multisite, true);
        assert.equal(inspection.main, true);
        assert.equal(inspection.id, 7);
        assert.equal(inspection.prefix, 'network_7_');
        assert.deepEqual(inspection.sites, [7]);
        assert.deepEqual(inspection.admins, ['shared']);
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
                    `--new-site-url=${targetUrl}`, '--network-admin=shared',
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
});
