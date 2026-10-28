import assert from 'node:assert/strict';
import { execFile, spawn } from 'node:child_process';
import { cp, mkdtemp, mkdir, readFile, readdir, realpath, rm, writeFile } from 'node:fs/promises';
import { createHash, generateKeyPairSync, randomBytes, sign } from 'node:crypto';
import { once } from 'node:events';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { test } from 'node:test';

const run = promisify(execFile);
const project = fileURLToPath(new URL('../', import.meta.url));

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
            const entry = target === 'wordpress-org' ? 'reprint.php' : 'index.php';
            assert.ok((await readdir(plugin)).includes(entry), `${target}: missing ${entry}`);
            if (target === 'wordpress-org') assert.ok(!(await readdir(plugin)).includes('index.php'));
            const minimumPhp = target === 'wordpress-org' ? '7.2' : '5.6.20';
            const readme = await readFile(join(plugin, 'readme.txt'), 'utf8');
            assert.ok(readme.includes(`Requires PHP: ${minimumPhp}\n`), `${target}: wrong PHP requirement`);
            const manifest = JSON.parse(await readFile(join(plugin, 'composer.json'), 'utf8'));
            assert.equal(manifest.require.php, `>=${minimumPhp}`);
            assert.equal(manifest.config.platform.php, minimumPhp);
            const serverManifest = JSON.parse(await readFile(join(plugin, 'vendor/wp-php-toolkit/reprint-server/composer.json'), 'utf8'));
            assert.equal(serverManifest.require.php, `>=${minimumPhp}`);
            const server = join(plugin, 'vendor/wp-php-toolkit/reprint-server/src');
            const files = await readdir(server);
            for (const filename of ['class-push-session.php', 'class-push-endpoints.php', 'class-database-push.php', 'class-database-push-endpoints.php', 'class-multipart-processor.php', 'class-mysqli-driver-pdo.php', 'class-mysqli-driver-pdo-statement.php']) {
                assert.equal(files.includes(filename), target === 'github', `${target}: ${filename}`);
            }
            const features = await readFile(join(server, 'build-features.php'), 'utf8');
            assert.match(features, new RegExp("define\\('REPRINT_PUSH_AVAILABLE', " + (target === 'github' ? 'true' : 'false') + "\\)"));
            const utilities = await readFile(join(server, 'class-utils.php'), 'utf8');
            for (const symbol of ['function connect_mysql(', 'function normalize_excluded_paths(', 'function remove_local_files_and_directories(']) {
                assert.ok(utilities.includes(symbol), `${target}: shared utility ${symbol}`);
            }
            const library = await readFile(join(plugin, 'lib.php'), 'utf8');
            const configuration = await readFile(join(plugin, 'wordpress/configuration.php'), 'utf8');
            const page = await readFile(join(plugin, 'wordpress/reprint-server.php'), 'utf8');
            if (target === 'wordpress-org') {
                assert.doesNotMatch(page, /PLUGIN_DIR \. 'index\.php'/);
                assert.match(page, /register_activation_hook\(PLUGIN_FILE/);
                assert.match(page, /plugin_basename\(PLUGIN_FILE/);
                assert.match(page, /plugins_url\('wordpress\/reprint-server\.js', PLUGIN_FILE/);
                assert.match(page, /plugins_url\('wordpress\/reprint-server\.css', PLUGIN_FILE/);
            }
            for (const [source, symbol] of [[library, 'function update_push_authorization('], [configuration, 'function change_key_push_access('], [page, 'function handle_push_access_save('], [page, 'reprint_server_save_key_push_access']]) {
                assert.ok(source.includes(symbol), `${target}: shared source ${symbol}`);
            }
            await run('php', ['tests/server-plugin-package.php', plugin, '', target], { cwd: project });
            await checkRequests(plugin, target, root);
        }
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('WordPress.org deploy rebuilds rather than publishing a tampered release ZIP', async () => {
    const workflow = await readFile(join(project, '.github/workflows/release-wporg.yml'), 'utf8');
    const steps = workflow.split(/(?=^      - )/m);
    const build = steps.find(step => step.includes('name: Build WordPress.org plugin from tag'));
    assert.ok(build, 'Deployment must build the plugin from the checked-out tag, not a release asset.');
    const command = build.split('        run: |\n')[1].replace(/^ {10}/gm, '');
    const stamp = steps.find(step => step.includes('name: Stamp plugin version from git tag'));
    const stampCommand = stamp.match(/run: (.+)/)[1];
    const root = await mkdtemp(join(tmpdir(), 'reprint-deploy-build-'));
    try {
        // Use a separate checkout tree so version stamping cannot change maintained source.
        for (const path of ['bin', 'packages/reprint-server', 'reprint-server-wp', 'LICENSE']) {
            await cp(join(project, path), join(root, path), {
                recursive: true,
                filter: source => !source.includes('/vendor'),
            });
        }
        await mkdir(join(root, 'tests'));
        await cp(join(project, 'tests/server-plugin-package.php'), join(root, 'tests/server-plugin-package.php'));
        const env = { ...process.env, VERSION: '0.12.0' };
        await run('bash', ['-e', '-o', 'pipefail', '-c', stampCommand], { cwd: root, env });
        await run('bash', ['-e', '-o', 'pipefail', '-c', command], { cwd: root, env });
        const pluginLibrary = join(root, 'reprint/lib.php');
        const taggedLibrary = await readFile(pluginLibrary, 'utf8');
        const tamperedLibrary = taggedLibrary + "\ndefine('REPRINT_TAMPERED_RELEASE_ASSET', true);\n";
        await writeFile(pluginLibrary, tamperedLibrary);
        // Release assets can be replaced without changing their version or layout.
        await run('zip', ['-q', 'reprint-wordpress-org.zip', 'reprint/lib.php'], { cwd: root });
        const asset = await run('unzip', ['-p', 'reprint-wordpress-org.zip', 'reprint/lib.php'], { cwd: root });
        assert.equal(asset.stdout, tamperedLibrary);
        await run('php', ['tests/server-plugin-package.php', 'reprint', env.VERSION, 'wordpress-org'], { cwd: root });
        await rm(join(root, 'reprint'), { recursive: true });
        await run('bash', ['-e', '-o', 'pipefail', '-c', command], { cwd: root, env });
        assert.equal(await readFile(pluginLibrary, 'utf8'), taggedLibrary);
        await run('php', ['tests/server-plugin-package.php', 'reprint', env.VERSION, 'wordpress-org'], { cwd: root });
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('WordPress.org preparation keeps shared PHP files unchanged', async () => {
    const root = await mkdtemp(join(tmpdir(), 'reprint-build-features-'));
    try {
        for (const directory of ['reprint-server-wp', 'packages/reprint-server']) {
            await mkdir(join(root, directory), { recursive: true });
            await cp(join(project, directory, 'README.md'), join(root, directory, 'README.md'));
        }
        await cp(join(project, 'packages/reprint-server/src'), join(root, 'packages/reprint-server/src'), { recursive: true });
        const sharedFiles = ['lib.php', 'compat.php', 'wordpress/configuration.php', 'wordpress/reprint-server.php'];
        for (const file of sharedFiles) {
            await mkdir(dirname(join(root, 'reprint-server-wp', file)), { recursive: true });
            await cp(join(project, 'reprint-server-wp', file), join(root, 'reprint-server-wp', file));
        }
        await run('php', ['bin/prepare-wordpress-org-plugin.php', root], { cwd: project });
        for (const file of [
            ...sharedFiles.map(file => `reprint-server-wp/${file}`),
            'packages/reprint-server/src/class-http-server.php',
            'packages/reprint-server/src/class-utils.php',
            'packages/reprint-server/src/compat.php',
        ]) {
            assert.equal(await readFile(join(root, file), 'utf8'), await readFile(join(project, file), 'utf8'), file);
        }
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('unknown build targets stop packaging', async () => {
    await assert.rejects(run('bash', ['bin/build-server-plugin.sh', 'unused.zip', 'unknown'], { cwd: project }), /observed target: unknown/);
    await assert.rejects(run('php', ['bin/prepare-wordpress-org-plugin.php', project], { cwd: project }), /separate-staging-directory/);
});

test('WordPress.org package on its minimum PHP runtime', { skip: !process.env.REPRINT_PACKAGED_PLUGIN_DIRECTORY }, async () => {
    const root = await mkdtemp(join(tmpdir(), 'reprint-minimum-php-test-'));
    try {
        await checkRequests(process.env.REPRINT_PACKAGED_PLUGIN_DIRECTORY, 'wordpress-org', root);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

/** Exercise the packaged entry point even when trusted host configuration grants push. */
async function checkRequests(plugin, target, root) {
    const entry = (await readdir(plugin)).includes('reprint.php') ? 'reprint.php' : 'index.php';
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
        env: { ...process.env, REPRINT_TEST_PLUGIN: plugin, REPRINT_TEST_PLUGIN_ENTRY: entry, REPRINT_TEST_DOCROOT: docroot, REPRINT_SERVER_PUSH_ENABLED: 'true', REPRINT_TEST_PUBLIC_KEY: publicKeyBytes.toString('base64') },
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
        for (const scheme of ['key', 'hmac']) {
            const response = await fetch(`http://127.0.0.1:${port}/?admin_probe=${scheme}`);
            const text = await response.text();
            assert.equal(response.status, 200, text + log);
            const page = JSON.parse(text);
            const pluginFile = await realpath(join(plugin, entry));
            assert.equal(page.plugin_file, pluginFile);
            assert.equal(page.activation_file, pluginFile);
            assert.equal(page.push_supported, target === 'github');
            assert.equal(page.push_enabled, target === 'github');
            assert.equal(page.push_actions.length, target === 'github' ? 2 : 0);
            if (target === 'wordpress-org') {
                assert.doesNotMatch(page.html, /push/i);
                assert.equal(page.token_grant, 'unsupported');
                assert.equal(page.key_grant, 'unsupported');
                assert.equal(page.token_authorized, false);
                assert.equal(page.token_grant_written, false);
            } else {
                assert.match(page.html, /Allow push|Push access/);
            }
        }
        for (const [notice, message] of [
            ['saved', 'Push access updated.'],
            ['key_push_saved', 'Push access for the Migration Token updated.'],
            ['key_removed', 'Migration Token removed.'],
        ]) {
            const response = await fetch(`http://127.0.0.1:${port}/?admin_probe=key&reprint_server_notice=${notice}`);
            const text = await response.text();
            assert.equal(response.status, 200, text + log);
            const page = JSON.parse(text);
            if (target === 'wordpress-org' && notice !== 'key_removed') {
                assert.doesNotMatch(page.html, /push/i);
            } else {
                assert.ok(page.html.includes(message), `${target}: missing ${notice} notice`);
            }
        }
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
            for (const endpoint of endpoints) {
                const standalone = await fetch(`http://127.0.0.1:${port}/?standalone&endpoint=${endpoint}`);
                const body = await standalone.json();
                assert.equal(standalone.status, 403, JSON.stringify(body) + log);
                assert.equal(body.reason, 'push_disabled');
            }
            await assert.rejects(readdir(`${docroot}-push-state`), { code: 'ENOENT' });
        }
        assert.equal(await readFile(join(docroot, 'keep.txt'), 'utf8'), 'original');
    } finally {
        server.kill();
        await stopped;
    }
}
