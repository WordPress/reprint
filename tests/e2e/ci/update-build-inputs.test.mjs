import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);

test('release updates change versions and hashes together, without executing downloads', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'reprint-release-update-'));
    const files = ['bin/build-reprint-phar.sh', 'tests/e2e/ci/download-wp-cli.sh'];
    const releaseBytes = Buffer.from('This is downloaded, never executed.');
    const checksum = createHash('sha256').update(releaseBytes).digest('hex');
    let version = '99.0.0';
    let badDigest = false;
    let missingAsset = false;
    const server = createServer((request, response) => {
        if (request.url.startsWith('/api/')) {
            assert.equal(request.headers.authorization, 'Bearer test-only-token');
            const box = request.url.includes('box-project/box');
            const tag = `${box ? '' : 'v'}${version}`;
            const name = box ? 'box.phar' : `wp-cli-${version}.phar`;
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({
                tag_name: tag,
                assets: missingAsset && !box ? [] : [{
                    name,
                    browser_download_url: `${url}/${box ? 'box-project/box' : 'wp-cli/wp-cli'}/releases/download/${tag}/${name}`,
                    digest: `sha256:${badDigest && !box ? '0'.repeat(64) : checksum}`,
                }],
            }));
        } else {
            assert.equal(request.headers.authorization, undefined, 'Download hosts do not receive the API token.');
            response.end(releaseBytes);
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    try {
        for (const file of files) {
            await mkdir(join(directory, file, '..'), { recursive: true });
            await writeFile(join(directory, file), await readFile(new URL(`../../../${file}`, import.meta.url)));
        }
        const source = await readFile(new URL('../../../bin/update-build-inputs.mjs', import.meta.url), 'utf8');
        await writeFile(join(directory, 'bin/update-build-inputs.mjs'), source
            .replaceAll('https://api.github.com', `${url}/api`)
            .replaceAll('https://github.com', url));
        const update = () => run(process.execPath, [join(directory, 'bin/update-build-inputs.mjs')], {
            env: { ...process.env, GH_TOKEN: 'test-only-token' },
        });
        const original = await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8')));
        missingAsset = true;
        await assert.rejects(update(), /wp-cli\/wp-cli.*asset/);
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), original);
        missingAsset = false;
        badDigest = true;
        await assert.rejects(update(), /SHA-256 mismatch/);
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), original);
        badDigest = false;
        await update();
        const updated = await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8')));
        for (const file of updated) {
            assert.ok(file.includes('/99.0.0/') || file.includes('/v99.0.0/'));
            assert.ok(file.includes(checksum));
        }
        await update();
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), updated);
        version = '1.0.0';
        await update();
        assert.deepEqual(await Promise.all(files.map((file) => readFile(join(directory, file), 'utf8'))), updated);
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        await rm(directory, { recursive: true, force: true });
    }
});
