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
    assertPullPipelineComplete, writeTestHooks, removeTestHooks,
} from '../lib/test-helpers.js';
import { HmacClient } from '../lib/hmac-client.js';

describe('Export: POST-only pull requests', () => {
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

    it.each(['preflight', 'file_index', 'file_fetch', 'db_index', 'sql_chunk'])(
        'rejects authenticated GET %s with a POST instruction', async endpoint => {
            const response = await fetch(`${getSiteUrl(site)}&endpoint=${endpoint}`, {
                headers: new HmacClient(getSiteSecret(site)).getAuthHeaders(),
            });
            assert.equal(response.status, 405);
            assert.equal(response.headers.get('allow'), 'POST, OPTIONS');
            assert.match((await response.json()).error, /POST/);
        },
    );

    it('completes a file and database pull through the strict query firewall', async () => {
        const output = join(directory, 'pull');
        const importUrl = `${firewallUrl}&directory=${encodeURIComponent(getSiteDir(site))}`;
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
        const result = runImporter(importUrl, output, 'pull', {
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
            join(pullStateDirectory(output, importUrl), 'state.json'), 'utf8',
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
        assert.ok(records.every(record => record.method === 'POST' && !record.blocked), JSON.stringify(records));
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
            let body;
            let signedBody;
            if (contentType === 'application/json') {
                body = JSON.stringify(params);
                signedBody = body;
            } else {
                body = new URLSearchParams(params).toString();
                signedBody = body;
            }
            const response = await fetch(`${firewallUrl}&endpoint=db_index`, {
                method: 'POST', body,
                headers: {
                    ...new HmacClient(getSiteSecret(site)).getAuthHeaders(signedBody),
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
        const response = await fetch(`${firewallUrl}&endpoint=file_fetch`, {
            method: 'POST', body,
            headers: new HmacClient(getSiteSecret(site)).getAuthHeaders(fileList),
        });
        assert.equal(response.status, 200, await response.clone().text());
        assert.match(await response.text(), /Hello World/);
    });

    it('rejects the reported nested base64 query before it reaches PHP', async () => {
        const response = await fetch(`${firewallUrl}&endpoint=db_index&skip_rows%5B0%5D%5Bvalue_base64%5D=X2VkaXRfbG9jaw%3D%3D`);
        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-query-firewall'), 'blocked');
        assert.match(await response.text(), /Site homepage/);
    });

    it('rejects changed and unsigned POST bodies', async () => {
        for (const headers of [
            {},
            new HmacClient(getSiteSecret(site)).getAuthHeaders('{"directory":"/different"}'),
        ]) {
            const response = await fetch(`${firewallUrl}&endpoint=preflight`, {
                method: 'POST', body: JSON.stringify({ directory: getSiteDir(site) }),
                headers: { ...headers, 'Content-Type': 'application/json' },
            });
            assert.equal(response.status, 403);
            assert.ok((await response.json()).error);
            assert.equal(response.headers.get('x-query-firewall'), null);
        }
    });
});
