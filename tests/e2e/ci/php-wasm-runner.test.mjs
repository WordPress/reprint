import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFile, mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);

test('the runner rejects a downloaded extension before loading mismatched bytes', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'reprint-extension-'));
    let requests = 0;
    const server = createServer((request, response) => {
        ++requests;
        response.end('different extension bytes');
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    try {
        // Copy the unchanged runner next to a fixture artifact record. There is
        // no production URL override or injected transport; this uses real HTTP.
        const runner = await prepareRunner(directory, {
            url: `http://127.0.0.1:${server.address().port}/extension.so`,
            sha256: createHash('sha256').update('recorded extension bytes').digest('hex'),
        });
        await assert.rejects(run(process.execPath, [runner, '-r', 'echo "ran";'], {
            env: { ...process.env, WP_MYSQL_PARSER_EXTENSION: '1', PLAYGROUND_PHP_VERSION: '8.3' },
        }), /SHA-256 mismatch/);
        assert.equal(requests, 1);
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        await rm(directory, { recursive: true, force: true });
    }
});

test('extension downloads retry, reuse verified bytes offline, and replace a corrupt cache', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'reprint-extension-cache-'));
    // Use a real bundled PHP.wasm extension so each command must load and run it.
    const bytes = await readFile(new URL('../node_modules/@php-wasm/node-8-3/jspi/extensions/redis/redis.so', import.meta.url));
    const checksum = createHash('sha256').update(bytes).digest('hex');
    let requests = 0;
    let available = true;
    const server = createServer((request, response) => {
        ++requests;
        response.statusCode = available && requests > 1 ? 200 : 503;
        response.end(response.statusCode === 200 ? bytes : 'unavailable');
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        const runner = await prepareRunner(directory, {
            url: `http://127.0.0.1:${server.address().port}/extension.so`, sha256: checksum,
        });
        const args = ['--experimental-wasm-jspi', runner, '-r', 'echo extension_loaded("redis") ? "loaded" : "missing";'];
        const options = { env: { ...process.env, TMPDIR: directory,
            WP_MYSQL_PARSER_EXTENSION: '1', PLAYGROUND_PHP_VERSION: '8.3' } };
        assert.equal((await run(process.execPath, args, options)).stdout, 'loaded');
        assert.equal(requests, 2, 'A temporary HTTP 503 must be retried.');
        available = false;
        assert.equal((await run(process.execPath, args, options)).stdout, 'loaded');
        assert.equal(requests, 2, 'A verified extension must not need another network request.');
        available = true;
        await writeFile(join(directory, `reprint-wp-mysql-parser-${checksum}.so`), 'corrupt cache');
        assert.equal((await run(process.execPath, args, options)).stdout, 'loaded');
        assert.equal(requests, 3);
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
        await rm(directory, { recursive: true, force: true });
    }
});

async function prepareRunner(directory, artifact) {
    const runnerDirectory = join(directory, 'tests/e2e/ci');
    await mkdir(runnerDirectory, { recursive: true });
    await mkdir(join(directory, 'bin'));
    const runner = join(runnerDirectory, 'runner.mjs');
    await copyFile(new URL('./php-wasm-runner.mjs', import.meta.url), runner);
    await copyFile(new URL('../../../bin/download-verified.sh', import.meta.url), join(directory, 'bin/download-verified.sh'));
    await symlink(fileURLToPath(new URL('../node_modules', import.meta.url)), join(directory, 'tests/e2e/node_modules'), 'dir');
    await writeFile(join(runnerDirectory, 'wp-mysql-parser-extension.json'), JSON.stringify({ artifacts: { '8.3': artifact } }));
    return runner;
}
