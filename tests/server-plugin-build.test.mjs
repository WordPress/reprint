import assert from 'node:assert/strict';
import { execFile, spawn } from 'node:child_process';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { createHash, generateKeyPairSync, randomBytes, sign } from 'node:crypto';
import { once } from 'node:events';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);
const project = new URL('../', import.meta.url).pathname;

test('WordPress.org excludes push while the GitHub package retains it', async () => {
    const root = await mkdtemp(join(tmpdir(), 'reprint-plugin-build-test-'));
    try {
        for (const target of ['wordpress-org', 'github']) {
            const archive = join(root, `${target}.zip`);
            await run('bash', ['bin/build-server-plugin.sh', archive, target], { cwd: project, maxBuffer: 4 * 1024 * 1024 });
            const extracted = join(root, target);
            await mkdir(extracted);
            await run('unzip', ['-q', archive, '-d', extracted]);
            const plugin = target === 'github' ? extracted : join(extracted, 'reprint');
            const server = join(plugin, 'vendor/wp-php-toolkit/reprint-server/src');
            const files = await readdir(server);
            for (const filename of ['class-push-session.php', 'class-push-endpoints.php', 'class-database-push.php', 'class-database-push-endpoints.php', 'class-multipart-processor.php', 'class-mysqli-driver-pdo.php', 'class-mysqli-driver-pdo-statement.php']) {
                assert.equal(files.includes(filename), target === 'github', `${target}: ${filename}`);
            }
            const utilities = await readFile(join(server, 'class-utils.php'), 'utf8');
            for (const symbol of ['function connect_mysql(', 'function normalize_excluded_paths(', 'function remove_local_files_and_directories(']) {
                assert.equal(utilities.includes(symbol), target === 'github', `${target}: ${symbol}`);
            }
            const library = await readFile(join(plugin, 'lib.php'), 'utf8');
            const configuration = await readFile(join(plugin, 'wordpress/configuration.php'), 'utf8');
            const page = await readFile(join(plugin, 'wordpress/reprint-server.php'), 'utf8');
            for (const [source, symbol] of [[library, 'function update_push_authorization('], [configuration, 'function change_key_push_access('], [page, 'function handle_push_access_save('], [page, 'reprint_server_save_key_push_access']]) {
                assert.equal(source.includes(symbol), target === 'github', `${target}: ${symbol}`);
            }
            await run('php', ['tests/server-plugin-package.php', plugin, '', target], { cwd: project });
            await checkRequests(plugin, target, root);
        }
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('unknown build targets and malformed push regions stop packaging', async () => {
    await assert.rejects(run('bash', ['bin/build-server-plugin.sh', 'unused.zip', 'unknown'], { cwd: project }), /observed target: unknown/);
    const root = await mkdtemp(join(tmpdir(), 'reprint-build-markers-'));
    try {
        await mkdir(join(root, 'reprint-server-wp'));
        await mkdir(join(root, 'packages/reprint-server/src'), { recursive: true });
        for (const [markers, message] of [
            ['// @reprint-push-end', /Unmatched push end marker/],
            ['// @reprint-push-start', /Unclosed push region/],
            ['// @reprint-push-start\n// @reprint-push-start', /Nested push region/],
        ]) {
            await writeFile(join(root, 'reprint-server-wp/lib.php'), `<?php\n${markers}\n`);
            await assert.rejects(run('php', ['bin/prepare-wordpress-org-plugin.php', root], { cwd: project }), message);
        }
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

/** Exercise the packaged entry point even when trusted host configuration grants push. */
async function checkRequests(plugin, target, root) {
    const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 3072 });
    const publicKeyBytes = publicKey.export({ type: 'spki', format: 'der' });
    const keyId = createHash('sha256').update(publicKeyBytes).digest('hex').slice(0, 16);
    /** Sign each request with the enrolled test key. */
    const request = async (url, options = {}) => {
        const nonce = randomBytes(16).toString('hex');
        const timestamp = String(Date.now() / 1000);
        const parsed = new URL(url);
        const message = ['reprint-rsa-sha256-v1', keyId, nonce, timestamp, options.method || 'GET', parsed.pathname + parsed.search].join('\n');
        return fetch(url, { ...options, headers: {
            'X-Auth-Key-Id': keyId, 'X-Auth-Nonce': nonce, 'X-Auth-Timestamp': timestamp,
            'X-Auth-Signature': sign('sha256', Buffer.from(message), privateKey).toString('base64'),
        } });
    };
    const docroot = join(root, `${target}-docroot`);
    await mkdir(docroot);
    await writeFile(join(docroot, 'keep.txt'), 'original');
    const listener = createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', docroot, join(project, 'tests/fixtures/server-plugin-build-router.php')], {
        env: { ...process.env, REPRINT_TEST_PLUGIN: plugin, REPRINT_TEST_DOCROOT: docroot, REPRINT_SERVER_PUSH_ENABLED: 'true', REPRINT_TEST_PUBLIC_KEY: publicKeyBytes.toString('base64') },
        stdio: ['ignore', 'ignore', 'pipe'],
    });
    let log = '';
    server.stderr.on('data', chunk => { log += chunk; });
    const stopped = once(server, 'exit');
    const url = `http://127.0.0.1:${port}/?reprint-api&endpoint=`;
    try {
        let preflight;
        for (let attempt = 0; attempt < 100; ++attempt) {
            try {
                preflight = await request(url + 'preflight');
                break;
            } catch {
                await new Promise(resolve => setTimeout(resolve, 50));
            }
        }
        assert.ok(preflight, log);
        const preflightBody = await preflight.json();
        assert.equal(preflight.status, 200, JSON.stringify(preflightBody) + log);
        assert.ok(preflightBody.php.version);
        const form = new FormData();
        form.set('file_list', new Blob([JSON.stringify([{ path: Buffer.from(join(docroot, 'keep.txt')).toString('base64') }])]), 'paths.json');
        const downloaded = await request(url + 'file_fetch', { method: 'POST', body: form });
        assert.equal(downloaded.status, 200);
        assert.match(await downloaded.text(), /original/);
        const session = 'a'.repeat(32);
        const endpoints = target === 'github' ? ['push_create', 'push_remove'] : [
            'push_create', 'push_upload', 'push_status', 'push_commit', 'push_remove',
            'push_db_create', 'push_db_upload', 'push_db_status', 'push_db_commit', 'push_db_cleanup', 'push_db_discard', 'push_future_probe',
        ];
        for (const endpoint of endpoints) {
            const response = await request(`${url}${endpoint}&push_session_id=${session}`, { method: 'POST' });
            const body = await response.json();
            assert.equal(response.status, target === 'github' ? 200 : 403, JSON.stringify(body) + log);
            if (target === 'wordpress-org') assert.equal(body.reason, 'push_disabled');
            else assert.equal(body.status, endpoint === 'push_create' ? 'created' : 'accepted');
        }
        if (target === 'wordpress-org') {
            const hostResponse = await fetch(`${url}push_create&host_auth=1&push_session_id=${session}`, { method: 'POST' });
            assert.equal(hostResponse.status, 403);
            assert.equal((await hostResponse.json()).reason, 'push_disabled');
            await assert.rejects(readdir(`${docroot}-push-state`), { code: 'ENOENT' });
        }
        assert.equal(await readFile(join(docroot, 'keep.txt'), 'utf8'), 'original');
    } finally {
        server.kill();
        await stopped;
    }
}
