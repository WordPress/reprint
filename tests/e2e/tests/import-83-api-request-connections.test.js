import { it } from 'vitest';
import assert from 'node:assert/strict';
import { fork } from 'node:child_process';
import { once } from 'node:events';
import { apiRequest } from '../lib/test-helpers.js';

it('can make API requests after blocking work outlasts an idle connection', async () => {
    const server = fork(new URL('../lib/idle-http-fixture.js', import.meta.url), [], {
        stdio: ['ignore', 'pipe', 'pipe', 'ipc'],
    });
    try {
        const [port] = await once(server, 'message');
        const options = { url: `http://127.0.0.1:${port}/?reprint-api` };
        // Fill both connections Node's fetch pool can alternate between.
        for (let request = 0; request < 5; ++request) {
            assert.equal((await apiRequest('basic', 'preflight', {}, options)).status, 200);
        }
        // Like runImporter's synchronous child process, this prevents Node
        // from handling the server's idle close before the next API request.
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 1000);
        const response = await apiRequest('basic', 'preflight', {}, options);
        assert.equal(response.status, 200);
        assert.equal(response.text, 'ready');
    } finally {
        server.kill();
        await once(server, 'exit');
    }
});
