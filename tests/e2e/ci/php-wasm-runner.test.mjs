import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFile, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
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
        await copyFile(new URL('./php-wasm-runner.mjs', import.meta.url), join(directory, 'runner.mjs'));
        await symlink(fileURLToPath(new URL('../node_modules', import.meta.url)), join(directory, 'node_modules'), 'dir');
        await writeFile(join(directory, 'wp-mysql-parser-extension.json'), JSON.stringify({ artifacts: {
            '8.3': {
                url: `http://127.0.0.1:${server.address().port}/extension.so`,
                sha256: createHash('sha256').update('recorded extension bytes').digest('hex'),
            },
        } }));
        await assert.rejects(run(process.execPath, [join(directory, 'runner.mjs'), '-r', 'echo "ran";'], {
            env: { ...process.env, WP_MYSQL_PARSER_EXTENSION: '1', PLAYGROUND_PHP_VERSION: '8.3' },
        }), /SHA-256 mismatch for wp_mysql_parser PHP 8\.3/);
        assert.equal(requests, 1);
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        await rm(directory, { recursive: true, force: true });
    }
});
