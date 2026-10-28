import { describe, it, beforeAll, afterAll, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import { cpSync, mkdirSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { join } from 'node:path';
import { createMysqlConnection, createTempDir, getHarnessKey, runImporter } from '../lib/test-helpers.js';
import { ensureWpTemplate } from '../lib/site-setup.js';

const nativePhp = process.env.E2E_WP_CLI_PHP_BINARY || 'php';
const projectRoot = join(import.meta.dirname, '../../..');
const secret = 'database-cache-cleanup';
const databases = [];
const servers = [];
let root, sourceRoot, sourceUrl, snapshotDirectory;

describe('WordPress after database cleanup', () => {
    beforeAll(async () => {
        root = realpathSync(createTempDir('e2e-cache-behavior'));
        sourceRoot = join(root, 'source');
        snapshotDirectory = join(root, 'snapshot');
        await ensureWpTemplate();
        cpSync('/tmp/wordpress-template', sourceRoot, { recursive: true });
        const sourceDatabase = await newDatabase();
        writeFileSync(join(sourceRoot, 'wp-config.php'), `<?php
define('DB_HOST', '127.0.0.1'); define('DB_NAME', '${sourceDatabase}');
define('DB_USER', 'e2e_admin'); define('DB_PASSWORD', 'e2e_password');
define('DB_CHARSET', 'utf8mb4'); define('DISABLE_WP_CRON', true);
$table_prefix = 'cache_';
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
`);
        const router = join(root, 'source-router.php');
        writeFileSync(router, `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) return false;
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
unset($_SERVER['PATH_INFO']);
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
`);
        const sourceServer = await startServer(sourceRoot, router);
        sourceUrl = sourceServer.url + '/?reprint-api';
        wp('core', 'install', `--url=${sourceServer.url}`, '--title=Cache source', '--admin_user=admin',
            '--admin_password=password', '--admin_email=admin@example.com', '--skip-email');
        const plugin = join(sourceRoot, 'wp-content/plugins/reprint-server');
        cpSync(join(projectRoot, 'reprint-server-wp'), plugin, { recursive: true });
        writeFileSync(join(plugin, 'secret.php'), `<?php return '${secret}';`);
        writeFileSync(join(plugin, 'public-keys.php'), `<?php return [${JSON.stringify(getHarnessKey(secret).publicKey)}];`);
        wp('plugin', 'activate', 'reprint-server');
        wp('eval-file', join(import.meta.dirname, '../fixtures/database-cache-source.php'));
        // Record source behavior before exporting, through the same APIs
        // that supply the admin badges and upload-quota calculation.
        const source = await (await fetch(sourceServer.url + '/cache-probe.php')).json();
        assert.equal(source.updates.plugins, 1);
        assert.equal(source.updates.themes, 1);
        assert.equal(source.updates.wordpress, 1);
        assert.equal(source.size, 128);
        const sourceResponse = await fetch(sourceServer.url + '/shop/');
        assert.equal(sourceResponse.status, 200, sourceServer.log());
        const route = await sourceResponse.text();
        assert.match(route, /Hosting-only route/);
        runCommand(snapshotDirectory, 'db-pull');
        // The immutable SQL snapshot is shared. Each test imports into its own
        // database and boots WordPress in fresh PHP processes.
        await stopServer(sourceServer.child);
    });

    afterEach(async () => {
        for (const server of servers.splice(0)) await stopServer(server);
    });

    afterAll(async () => {
        for (const server of servers.splice(0)) await stopServer(server);
        const connection = await createMysqlConnection();
        try {
            for (const database of databases) await connection.query(`DROP DATABASE IF EXISTS \`${database}\``);
        } finally { await connection.end(); }
        if (root) rmSync(root, { recursive: true, force: true });
    });

    for (const engine of ['mysql', 'sqlite']) {
        it(`${engine}: loads local child and parent theme files while retaining theme settings`, async () => {
            const target = await createTarget(engine);
            writeFileSync(join(target.directory, 'shared-themes/cache-child/marker.txt'), 'Local child theme');
            const parent = join(target.directory, 'shared-themes/cache-parent/index.php');
            writeFileSync(parent, readFileSync(parent, 'utf8').replace('Source parent theme', 'Local parent theme'));
            const response = await fetch(target.url + '/?p=1');
            assert.equal(response.status, 200);
            assert.match(await response.text(), /Local parent theme Local child theme blue/);
            const state = await (await fetch(target.url + '/cache-probe.php')).json();
            assert.equal(state.stylesheet, 'cache-child');
            assert.equal(state.template, 'cache-parent');
        });

        it(`${engine}: serves the shop page instead of a removed hosting plugin's route`, async () => {
            const target = await createTarget(engine);
            const response = await fetch(target.url + '/shop/');
            assert.equal(response.status, 200);
            const html = await response.text();
            assert.match(html, /Local shop page/);
            assert.doesNotMatch(html, /Hosting-only route/);
        });

        it(`${engine}: does not show the source's update notices before local checks`, async () => {
            const target = await createTarget(engine);
            const { updates } = await (await fetch(target.url + '/cache-probe.php')).json();
            assert.equal(updates.plugins, 0);
            assert.equal(updates.themes, 0);
            assert.equal(updates.wordpress, 0);
        });

        it(`${engine}: measures local uploads when both hosts use the same absolute path`, async () => {
            // Hosts can both use /wordpress (or the same account path). Move the
            // stopped source aside, then boot the target at that identical path.
            const backup = join(root, 'source-backup');
            renameSync(sourceRoot, backup);
            try {
                const target = await createTarget(engine, sourceRoot, backup);
                const { size } = await (await fetch(target.url + '/cache-probe.php')).json();
                assert.equal(size, 11, 'The upload quota must count Local media, not the source-only file');
                await stopServer(target.child);
            } finally {
                rmSync(sourceRoot, { recursive: true, force: true });
                renameSync(backup, sourceRoot);
            }
        });
    }
});

/** Run WP-CLI against only the source fixture. */
function wp(...arguments_) {
    return execFileSync(nativePhp, ['/tmp/wp-cli.phar', '--allow-root', `--path=${sourceRoot}`, ...arguments_],
        { encoding: 'utf8', timeout: 120000 });
}

/** Allocate a database per source or target, never reusing another test's rows. */
async function newDatabase() {
    const database = `e2e_cache_${process.pid}_${databases.length}`;
    const connection = await createMysqlConnection();
    try { await connection.query(`CREATE DATABASE \`${database}\``); }
    finally { await connection.end(); }
    databases.push(database);
    return database;
}

/** Run the real importer without asking the stopped source for another preflight. */
function runCommand(stateDirectory, command, extraArgs = []) {
    const result = runImporter(sourceUrl, stateDirectory, command, {
        secret, skipPreflight: command !== 'db-pull', timeout: 120000,
        extraArgs: ['--exclude-host-plugins', ...extraArgs],
    });
    assert.equal(result.exitCode, 0, result.stdout + result.stderr);
}

/** Apply the real exported SQL, then serve a code-only copy through its generated runtime. */
async function createTarget(engine, directory = null, sourceDirectory = sourceRoot) {
    const stateDirectory = realpathSync(createTempDir('e2e-cache-target'));
    directory ??= join(stateDirectory, 'site');
    cpSync(snapshotDirectory, stateDirectory, { recursive: true });
    cpSync(sourceDirectory, directory, { recursive: true });
    // Model an essential-files pull: the local uploads differ from the source.
    rmSync(join(directory, 'wp-content/uploads'), { recursive: true, force: true });
    mkdirSync(join(directory, 'wp-content/uploads'), { recursive: true });
    writeFileSync(join(directory, 'wp-content/uploads/local.txt'), 'Local media');
    const database = await newDatabase();
    const runtimeDirectory = join(stateDirectory, 'runtime');
    const target = await startServer(directory, join(runtimeDirectory, 'runtime.php'));
    const targetArgs = engine === 'sqlite'
        ? ['--target-engine=sqlite', `--target-sqlite-path=${stateDirectory}/wordpress.sqlite`, '--target-db=wordpress']
        : ['--target-engine=mysql', '--target-host=127.0.0.1', '--target-user=e2e_admin',
            '--target-pass=e2e_password', `--target-db=${database}`];
    runCommand(stateDirectory, 'db-apply', [...targetArgs, `--new-site-url=${target.url}`]);
    execFileSync(process.env.PHP_BINARY || 'php', [
        process.env.CLIENT_PATH || join(projectRoot, 'packages/reprint-client/bin/reprint-client'),
        'apply-runtime', sourceUrl, '--allow-unsafe-http', `--state-dir=${stateDirectory}`,
        '--runtime=php-builtin', `--output-dir=${runtimeDirectory}`, `--flat-document-root=${directory}`,
        '--exclude-host-plugins',
    ], { encoding: 'utf8', timeout: 120000 });
    target.child.once('exit', () => rmSync(stateDirectory, { recursive: true, force: true }));
    return { ...target, directory };
}

/** PHP 7.4 rejects port zero. Choose a free port, then wait for PHP's startup message. */
async function startServer(directory, router) {
    const listener = createServer();
    listener.listen(0, '127.0.0.1');
    await once(listener, 'listening');
    const port = listener.address().port;
    await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));
    const child = spawn(nativePhp, ['-S', `127.0.0.1:${port}`, '-t', directory, router], { stdio: ['ignore', 'ignore', 'pipe'] });
    servers.push(child);
    let log = '';
    const url = await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('PHP server did not start: ' + log)), 30000);
        child.once('error', error => { clearTimeout(timeout); reject(error); });
        child.once('exit', () => { clearTimeout(timeout); reject(new Error(log)); });
        child.stderr.on('data', data => {
            log += data;
            const match = log.match(/Development Server \((http:\/\/127\.0\.0\.1:\d+)\) started/);
            if (match) { clearTimeout(timeout); resolve(match[1]); }
        });
    });
    return { child, url, log: () => log };
}

/** Release the PHP process before moving its document root or dropping its database. */
async function stopServer(child) {
    if (child.exitCode !== null || child.signalCode !== null) return;
    const exited = once(child, 'exit');
    child.kill('SIGTERM');
    await exited;
}
