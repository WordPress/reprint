import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const run = promisify(execFile);
const downloader = fileURLToPath(new URL('../../../bin/download-verified.sh', import.meta.url));

test('downloads are verified before replacing the cache', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'reprint-download-'));
    const destination = join(directory, 'artifact with spaces');
    const expected = Buffer.from('recorded release bytes');
    const checksum = createHash('sha256').update(expected).digest('hex');
    let body = expected;
    let requests = 0;
    const server = createServer((request, response) => {
        ++requests;
        response.end(body);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}/artifact`;
    try {
        await run('bash', [downloader, url, checksum, destination]);
        assert.deepEqual(await readFile(destination), expected);
        await run('bash', [downloader, url, checksum, destination]);
        assert.equal(requests, 1, 'A verified cached artifact needs no download.');
        await writeFile(destination, 'old cached version');
        await run('bash', [downloader, url, checksum, destination]);
        assert.deepEqual(await readFile(destination), expected);
        body = Buffer.from('different download');
        await writeFile(destination, 'keep until a verified replacement arrives');
        await assert.rejects(run('bash', [downloader, url, checksum, destination]), /SHA-256 mismatch/);
        assert.equal(await readFile(destination, 'utf8'), 'keep until a verified replacement arrives');
        assert.deepEqual(await readdir(directory), ['artifact with spaces']);
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        await rm(directory, { recursive: true, force: true });
    }
});
