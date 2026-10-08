import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { fork } from 'node:child_process';
import { once } from 'node:events';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ensureSite } from '../lib/site-setup.js';
import {
    createTempDir, cleanupTempDir, getSiteDir, getSiteUrl, getSiteSecret,
    runImporter, createMysqlConnection, fsRootDir, pullStateDirectory,
    assertPullPipelineComplete, writeTestHooks, removeTestHooks, createHmacClient,
} from '../lib/test-helpers.js';
import { endpointUrl } from '../lib/hmac-client.js';

describe('Export: POST body parameters with a query routing marker', () => {
    const site = 'post-requests';
    const importDb = 'e2e_post_requests_import';
    let directory;
    let firewall;
    let firewallUrl;

    beforeAll(async () => {
        await ensureSite(site, {
            customDb: async (_database, connection) => {
                await connection.query(`INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
                    VALUES (98765, '_edit_lock', 'omit this lock'),
                           (98765, '_edit_lock_extra', 'keep this value')`);
            },
        });
        directory = createTempDir('post-export');
        const log = join(directory, 'requests.jsonl');
        writeFileSync(log, '');
        firewall = fork(fileURLToPath(new URL('../lib/query-firewall-fixture.js', import.meta.url)),
            [getSiteUrl(site), log], { stdio: ['ignore', 'pipe', 'pipe', 'ipc'] });
        const [ready] = await once(firewall, 'message');
        firewallUrl = `http://127.0.0.1:${ready.port}/?reprint-api`;
    });

    afterAll(async () => {
        if (firewall && firewall.exitCode === null) {
            firewall.kill('SIGTERM');
            await once(firewall, 'exit');
        }
        removeTestHooks(site);
        cleanupTempDir(directory);
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS ${importDb}`);
        await connection.end();
    });

    it('accepts legacy query parameters for GET exports and multipart uploads', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.delete('reprint-api');
        url.searchParams.set('site-export-api', '');
        url.searchParams.set('endpoint', 'db_index');
        url.searchParams.set('directory', getSiteDir(site));
        url.searchParams.set('skip_rows[0][table_name_without_prefix]', 'postmeta');
        url.searchParams.set('skip_rows[0][column]', 'meta_key');
        url.searchParams.set('skip_rows[0][value_base64]', Buffer.from('_edit_lock').toString('base64'));
        // The key signature covers the request target, so each request
        // signs the exact URL and method it sends.
        const index = await fetch(url, {
            headers: createHmacClient(site).getAuthHeaders({ method: 'GET', url: url.toString() }),
        });
        assert.equal(index.status, 200, await index.clone().text());
        assert.match(index.headers.get('content-type'), /multipart\/mixed/);
        await index.arrayBuffer();

        // Older clients send the endpoint and options in the URL, but still
        // upload the file list as a multipart file. Keep that body intact.
        url.searchParams.set('endpoint', 'file_fetch');
        const fileList = JSON.stringify([{ path: Buffer.from(join(getSiteDir(site), 'test-data', 'hello.txt')).toString('base64') }]);
        const body = new FormData();
        body.set('file_list', new Blob([fileList], { type: 'application/json' }), 'files.json');
        const files = await fetch(url, {
            method: 'POST', body,
            headers: createHmacClient(site).getAuthHeaders({ method: 'POST', url: url.toString() }),
        });
        assert.equal(files.status, 200, await files.clone().text());
        assert.match(files.headers.get('content-type'), /multipart\/mixed/);
        assert.match(await files.text(), /Hello World/);
    });

    it('completes a file and database pull through the strict query firewall', async () => {
        writeFileSync(join(directory, 'requests.jsonl'), '');
        const output = join(directory, 'pull');
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS ${importDb}`);
        await connection.query(`CREATE DATABASE ${importDb}`);
        await connection.end();
        // A slow SQL batch exhausts the real response time budget. The next
        // request must continue from its body cursor because the WAF strips
        // X-Export-Cursor rather than forwarding it.
        writeTestHooks(site, `
function test_hook_before_sql_batch(&$sql, $cursor) {
    static $paused = false;
    if (!$paused) {
        $paused = true;
        usleep(1100000);
    }
}
`);
        const result = runImporter(firewallUrl, output, 'pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            timeout: 120000,
            wallTimeout: 240000,
            extraArgs: [
                '--target-user=e2e_admin', '--target-pass=e2e_password',
                `--target-db=${importDb}`, '--runtime=none',
                '--include=:abspath:/test-data',
                '--new-site-url=http://localhost:9999',
                '--max-exec=1', '--sql-fragments-start=1', '--sql-fragments-max=1',
            ],
        });
        assert.equal(result.exitCode, 0, result.stderr + '\n' + result.stdout);
        assertPullPipelineComplete(JSON.parse(readFileSync(
            join(pullStateDirectory(output, firewallUrl), 'state.json'), 'utf8',
        )));
        assert.equal(readFileSync(join(fsRootDir(output), getSiteDir(site), 'test-data', 'hello.txt'), 'utf8'), 'Hello World\n');
        const imported = await createMysqlConnection(importDb);
        const [rows] = await imported.query('SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 98765');
        await imported.end();
        assert.deepEqual(rows.map(row => ({ ...row })), [
            { meta_key: '_edit_lock_extra', meta_value: 'keep this value' },
        ]);
        const records = readFileSync(join(directory, 'requests.jsonl'), 'utf8')
            .trim().split('\n').map(line => JSON.parse(line));
        assert.ok(records.length > 0);
        // Endpoint names pass the firewall, and every other pull parameter travels in the body.
        assert.ok(records.every(record => record.method === 'POST' && record.stripped.length === 0), JSON.stringify(records));
        for (const endpoint of ['preflight', 'file_index', 'file_fetch', 'db_index', 'sql_chunk']) {
            assert.ok(records.some(record => record.endpoint === endpoint), endpoint);
        }
        assert.ok(records.filter(record => record.endpoint === 'sql_chunk').length > 1,
            'Expected SQL continuation using body cursors with cursor headers stripped');
    }, 240000);

    it.each(['application/json', 'application/x-www-form-urlencoded'])(
        'reads POST %s parameters through the WordPress plugin', async contentType => {
            // Exercise the JSON-string form as well as nested values in later pulls.
            const params = {
                directory: getSiteDir(site),
                skip_rows: JSON.stringify([{
                    table_name_without_prefix: 'postmeta',
                    column: 'meta_key',
                    value_base64: Buffer.from('_edit_lock').toString('base64'),
                }]),
            };
            const body = contentType === 'application/json'
                ? JSON.stringify(params) : new URLSearchParams(params).toString();
            const url = endpointUrl(firewallUrl, 'db_index');
            const response = await fetch(url, {
                method: 'POST', body,
                headers: {
                    ...createHmacClient(site).getAuthHeaders({ method: 'POST', url }),
                    'Content-Type': contentType,
                },
            });
            assert.equal(response.status, 200, await response.clone().text());
            assert.match(response.headers.get('content-type'), /multipart\/mixed/);
        },
    );

    it('accepts multipart file lists with the pull options in form fields', async () => {
        const fileList = JSON.stringify([{ path: Buffer.from(join(getSiteDir(site), 'test-data', 'hello.txt')).toString('base64') }]);
        const body = new FormData();
        body.set('directory', getSiteDir(site));
        body.set('file_list', new Blob([fileList], { type: 'application/json' }), 'files.json');
        const url = endpointUrl(firewallUrl, 'file_fetch');
        const response = await fetch(url, {
            method: 'POST', body,
            headers: createHmacClient(site).getAuthHeaders({ method: 'POST', url }),
        });
        assert.equal(response.status, 200, await response.clone().text());
        assert.match(await response.text(), /Hello World/);
    });

    it('dispatches the query endpoint and ignores a POST application/json endpoint', async () => {
        const params = { endpoint: 'preflight', directory: getSiteDir(site) };
        const url = `${endpointUrl(getSiteUrl(site), 'db_index')}&directory=/missing-query-directory`;
        const response = await fetch(url, {
            method: 'POST', body: JSON.stringify(params),
            headers: {
                ...createHmacClient(site).getAuthHeaders({ method: 'POST', url }),
                'Content-Type': 'application/json',
            },
        });
        // db_index streams a multipart response, and the body's directory wins over the query's.
        assert.equal(response.status, 200, await response.clone().text());
        assert.match(response.headers.get('content-type'), /multipart\/mixed/);
    });

    /** The firewall's record of the most recent request. */
    function lastFirewallRecord() {
        const lines = readFileSync(join(directory, 'requests.jsonl'), 'utf8').trim().split('\n');
        return JSON.parse(lines[lines.length - 1]);
    }

    it('strips a query value with characters other than letters, digits, and underscores', async () => {
        const url = `${endpointUrl(firewallUrl, 'preflight')}&directory=/srv`;
        const response = await fetch(url, {
            method: 'POST', body: JSON.stringify({ directory: getSiteDir(site) }),
            headers: {
                ...createHmacClient(site).getAuthHeaders({ method: 'POST', url }),
                'Content-Type': 'application/json',
            },
        });
        assert.ok(lastFirewallRecord().stripped.includes('directory'), JSON.stringify(lastFirewallRecord()));
        // The plugin received a different request target than the client signed.
        assert.equal(response.status, 403);
        assert.equal((await response.json()).reason, 'signature_mismatch');
    });

    it('strips the reported nested base64 query value', async () => {
        const response = await fetch(`${endpointUrl(firewallUrl, 'db_index')}&skip_rows%5B0%5D%5Bvalue_base64%5D=X2VkaXRfbG9jaw%3D%3D`);
        const record = lastFirewallRecord();
        assert.deepEqual(record.stripped, ['skip_rows[0][value_base64]']);
        assert.ok(record.forwardedPath.endsWith('endpoint=db_index'), record.forwardedPath);
        // The request reached PHP without the value. The e2e site is a key
        // host, so an unsigned request is refused there.
        assert.equal(response.status, 403);
        assert.equal((await response.json()).reason, 'requires_key_auth');
    });

    it('rejects an unsigned POST request', async () => {
        // A key signature covers the method and request target, not the
        // body, so only a missing or wrong signature is refused here.
        const response = await fetch(endpointUrl(firewallUrl, 'preflight'), {
            method: 'POST', body: JSON.stringify({ directory: getSiteDir(site) }),
            headers: { 'Content-Type': 'application/json' },
        });
        assert.equal(response.status, 403);
        const body = await response.json();
        assert.ok(body.error);
        assert.equal(body.auth_version, 2);
    });
});
