import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { fork } from 'node:child_process';
import { once } from 'node:events';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ensureSite } from '../lib/site-setup.js';
import {
    createTempDir, cleanupTempDir, getSiteDir, getSiteUrl, getSiteSecret,
} from '../lib/test-helpers.js';
import { HmacClient } from '../lib/hmac-client.js';

describe('Export: GET and POST rollout', () => {
    const site = 'basic';
    let directory;
    let firewall;
    let firewallUrl;

    beforeAll(async () => {
        await ensureSite(site);
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
        cleanupTempDir(directory);
    });

    it('keeps authenticated GET preflight working for deployed clients', async () => {
        const response = await fetch(`${getSiteUrl(site)}&endpoint=preflight`, {
            headers: new HmacClient(getSiteSecret(site)).getAuthHeaders(),
        });
        assert.equal(response.status, 200);
        assert.ok((await response.json()).capabilities);
    });

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
