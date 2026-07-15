/**
 * Test 54: a normal WordPress-root pull seeds the existing push baseline.
 *
 * The exporter is installed in wp-content/plugins and its durable staging
 * directory is inside ABSPATH. All traffic uses the public CLI and the real
 * nginx/FPM endpoint; no exporter files are mounted outside the site root.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, rmSync,
    writeFileSync,
} from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    cleanupTempDir, createTempDir, fsRootDir, getSiteDir, getSiteSecret,
    getSiteUrl, runImporter, runPush,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const IMPORTER_PATH = join(import.meta.dirname, '..', '..', '..', 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const PHP_VERSION_ID = Number(execFileSync(PHP_BINARY, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }));
const describeSupportedPush = PHP_VERSION_ID >= 80100 ? describe.sequential : describe.skip;

describeSupportedPush('Import: pull-seeded full-root push', { timeout: 300000 }, () => {
    const site = 'push-pull-roundtrip';
    const targetRoot = getSiteDir(site);
    const stagingDir = join(targetRoot, '.reprint-staging');
    const fixturePath = 'roundtrip-fixture';
    const targetFixture = join(targetRoot, fixturePath);
    const exporterPath = 'wp-content/plugins/site-export/index.php';
    let caseRoot;
    let stateDir;
    let localRoot;
    let url;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterConfig: async (siteDir) => configureStaging(siteDir, stagingDir),
        });
        configureStaging(targetRoot, stagingDir);
        execFileSync('sudo', ['rm', '-rf', targetFixture, stagingDir]);
        execFileSync('sudo', ['mkdir', '-p', stagingDir]);
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', stagingDir]);
        writeTarget('edit.txt', 'original edit value');
        writeTarget('delete.txt', 'delete this leaf');
        writeTarget('delete-tree/a/b.txt', 'delete this subtree');
        writeTarget('type-swap', 'file before type transition');
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', targetFixture]);
        caseRoot = createTempDir('e2e-pull-seeded-push');
        stateDir = join(caseRoot, 'state');
        url = `${getSiteUrl(site)}&directory=${encodeURIComponent(targetRoot)}`;
    });

    afterAll(() => {
        cleanupTempDir(caseRoot);
        execFileSync('sudo', ['rm', '-rf', targetFixture, stagingDir]);
    });

    it('publishes a local-ctime baseline only after the full pull completes', () => {
        const result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site),
            wallTimeout: 300000,
        });
        assert.equal(result.exitCode, 0, result.stderr);
        localRoot = join(fsRootDir(stateDir), targetRoot);
        assert.ok(existsSync(join(localRoot, 'index.php')), 'The pull omitted the WordPress root.');

        const journal = journalRoot(stateDir);
        assert.ok(existsSync(join(journal, 'last-sync-local-files.jsonl')));
        assert.ok(existsSync(join(journal, 'last-sync-local-files.identity.json')));
        const identity = JSON.parse(readFileSync(join(journal, 'last-sync-local-files.identity.json'), 'utf8'));
        assert.equal(Buffer.from(identity.managed_directory_b64, 'base64').toString(), targetRoot);
        assert.equal(Buffer.from(identity.local_root_b64, 'base64').toString(), localRoot);
    });

    it('keeps structural markers private and staging absent from the pulled baseline', () => {
        const baseline = snapshotEntries(join(journalRoot(stateDir), 'last-sync-local-files.jsonl'));
        const byPath = new Map(baseline.map((entry) => [entry.path, entry]));
        assert.equal(byPath.get('wp-content')?.type, 'tree-directory');
        assert.equal(byPath.get('wp-content/plugins')?.type, 'tree-directory');
        assert.equal(byPath.get(exporterPath)?.type, 'file');
        assert.ok(!baseline.some((entry) => entry.path === '.reprint-staging'
            || entry.path.startsWith('.reprint-staging/')));
        assert.ok(!existsSync(join(localRoot, '.reprint-staging')));
    });

    it('immediate push is a no-op and never plans exporter or structural entries', () => {
        const result = runPush(site, localRoot, stateDir, { url });
        const summary = assertSuccessfulPush(result);
        assert.equal(summary.changed, 0);
        assert.equal(summary.deleted, 0);

        const journal = journalRoot(stateDir);
        const plan = snapshotEntries(join(journal, 'local-paths-to-push.jsonl'));
        assert.deepEqual(plan, []);
        assert.ok(!plan.some((entry) => entry.type === 'tree-directory'));
        assert.ok(!plan.some((entry) => entry.path === exporterPath));
        assert.ok(!plan.some((entry) => entry.path === '.reprint-staging'
            || entry.path.startsWith('.reprint-staging/')));
        assert.ok(!existsSync(join(stagingDir, 'apply-sessions')), 'zero-delta push created a target session');
    });

    it('editing one ordinary leaf sends only that leaf', () => {
        writeFileSync(join(localRoot, fixturePath, 'edit.txt'), 'edited local value with a new size');
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 1);
        assert.equal(summary.deleted, 0);
        assert.deepEqual(plannedPaths(stateDir), [`${fixturePath}/edit.txt`]);
        assert.equal(readFileSync(join(targetFixture, 'edit.txt'), 'utf8'), 'edited local value with a new size');
    });

    it('adding one leaf plans it as the only positive change', () => {
        writeFileSync(join(localRoot, fixturePath, 'added.txt'), 'added locally');
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 1);
        assert.equal(summary.deleted, 0);
        assert.deepEqual(plannedPaths(stateDir), [`${fixturePath}/added.txt`]);
        assert.equal(readFileSync(join(targetFixture, 'added.txt'), 'utf8'), 'added locally');
    });

    it('deleting one leaf produces exactly one deletion', () => {
        rmSync(join(localRoot, fixturePath, 'delete.txt'));
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 0);
        assert.equal(summary.deleted, 1);
        assert.deepEqual(deletedPaths(stateDir), [`${fixturePath}/delete.txt`]);
        assert.ok(!existsSync(join(targetFixture, 'delete.txt')));
    });

    it('deleting a nonempty subtree collapses descendants into one root deletion', () => {
        rmSync(join(localRoot, fixturePath, 'delete-tree'), { recursive: true });
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 0);
        assert.equal(summary.deleted, 1);
        assert.deepEqual(deletedPaths(stateDir), [`${fixturePath}/delete-tree`]);
        assert.ok(!existsSync(join(targetFixture, 'delete-tree')));
    });

    it('a file-to-structural-directory transition sends its leaf and correct clear', () => {
        rmSync(join(localRoot, fixturePath, 'type-swap'));
        mkdirSync(join(localRoot, fixturePath, 'type-swap'), { recursive: true });
        writeFileSync(join(localRoot, fixturePath, 'type-swap/child.txt'), 'new child');
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 1);
        assert.equal(summary.deleted, 1);
        assert.deepEqual(plannedPaths(stateDir), [`${fixturePath}/type-swap/child.txt`]);
        assert.deepEqual(deletedPaths(stateDir), [`${fixturePath}/type-swap`]);
        assert.ok(lstatSync(join(targetFixture, 'type-swap')).isDirectory());
        assert.equal(readFileSync(join(targetFixture, 'type-swap/child.txt'), 'utf8'), 'new child');
    });

    it('sequential pushes advance the same baseline', () => {
        writeFileSync(join(localRoot, fixturePath, 'edit.txt'), 'third edit is longer again');
        rmSync(join(localRoot, fixturePath, 'added.txt'));
        let summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 1);
        assert.equal(summary.deleted, 1);

        summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 0);
        assert.equal(summary.deleted, 0);
    });

    it('editing the installed exporter is still rejected before live mutation', () => {
        const localExporter = join(localRoot, exporterPath);
        const targetExporter = join(targetRoot, exporterPath);
        const localBefore = readFileSync(localExporter);
        const targetBefore = readFileSync(targetExporter);
        writeFileSync(localExporter, Buffer.concat([localBefore, Buffer.from('\n// local protected edit\n')]));

        const rejected = runPush(site, localRoot, stateDir, { url });
        assert.notEqual(rejected.exitCode, 0);
        assert.match(rejected.stderr, /Protected target-relative path cannot be changed/);
        assert.doesNotMatch(rejected.stderr, /no compatible full-root baseline/);
        assert.deepEqual(readFileSync(targetExporter), targetBefore);
        assert.ok(!existsSync(join(targetRoot, '.maintenance')));

        writeFileSync(localExporter, localBefore);
        const aborted = runPush(site, localRoot, stateDir, { url, extraArgs: ['--abort'] });
        assert.equal(aborted.exitCode, 0, aborted.stderr);
    });

    it('a push without a compatible baseline fails safely with pull guidance', () => {
        const stateWithoutBaseline = join(caseRoot, 'state-without-baseline');
        const targetSentinel = readFileSync(join(targetFixture, 'edit.txt'));
        const rejected = runPush(site, localRoot, stateWithoutBaseline, { url, timeout: 300000 });
        assert.notEqual(rejected.exitCode, 0);
        assert.match(rejected.stderr, /no compatible full-root baseline/);
        assert.match(rejected.stderr, /unfiltered files-pull/);
        assert.match(rejected.stderr, /same --state-dir/);
        assert.deepEqual(readFileSync(join(targetFixture, 'edit.txt')), targetSentinel);
        assert.ok(!existsSync(join(targetRoot, '.maintenance')));
        const aborted = runPush(site, localRoot, stateWithoutBaseline, { url, extraArgs: ['--abort'] });
        assert.equal(aborted.exitCode, 0, aborted.stderr);
    });

    it('a filtered pull invalidates and cannot republish the full-root baseline', () => {
        let result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site), extraArgs: ['--abort'],
        });
        assert.equal(result.exitCode, 0, result.stderr);
        result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site), extraArgs: ['--filter=essential-files'], wallTimeout: 300000,
        });
        assert.equal(result.exitCode, 0, result.stderr);
        const journal = journalRoot(stateDir);
        assert.ok(!existsSync(join(journal, 'last-sync-local-files.jsonl')));
        assert.ok(!existsSync(join(journal, 'last-sync-local-files.identity.json')));

        const dryRun = runPush(site, localRoot, stateDir, { url, extraArgs: ['--dry-run'] });
        const summary = assertSuccessfulPush(dryRun, 'dry_run');
        assert.ok(summary.changed > 0);
        assert.ok(plannedPaths(stateDir).some((path) => path === exporterPath));
    });

    it('a later complete unfiltered pull publishes a replacement baseline', () => {
        let result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site), extraArgs: ['--abort'],
        });
        assert.equal(result.exitCode, 0, result.stderr);
        result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site), extraArgs: ['--filter=none'], wallTimeout: 300000,
        });
        assert.equal(result.exitCode, 0, result.stderr);
        const journal = journalRoot(stateDir);
        assert.ok(existsSync(join(journal, 'last-sync-local-files.jsonl')));
        assert.ok(existsSync(join(journal, 'last-sync-local-files.identity.json')));
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 0);
        assert.equal(summary.deleted, 0);
    });

    it('an interrupted fresh pull removes stale synchronization evidence first', async () => {
        writeRandomTarget('interrupted.bin', 64);
        let result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site), extraArgs: ['--abort'],
        });
        assert.equal(result.exitCode, 0, result.stderr);
        const journal = journalRoot(stateDir);
        assert.ok(existsSync(join(journal, 'last-sync-local-files.jsonl')));

        const running = startFilesPull(url, stateDir);
        await waitFor(
            () => !existsSync(join(journal, 'last-sync-local-files.jsonl')),
            60000,
            'The fresh pull never invalidated the prior push baseline.',
        );
        running.child.kill('SIGKILL');
        const interrupted = await running.completed;
        assert.notEqual(interrupted.exitCode, 0);
        assert.ok(!existsSync(join(journal, 'last-sync-local-files.jsonl')));
        assert.ok(!existsSync(join(journal, 'last-sync-local-files.identity.json')));
    });

    it('pull restart republishes only after eventual completion', () => {
        const result = runImporter(url, stateDir, 'files-pull', {
            secret: getSiteSecret(site), wallTimeout: 300000,
        });
        assert.equal(result.exitCode, 0, result.stderr);
        assert.equal(lstatSync(join(localRoot, fixturePath, 'interrupted.bin')).size, 64 * 1024 * 1024);
        const journal = journalRoot(stateDir);
        assert.ok(existsSync(join(journal, 'last-sync-local-files.jsonl')));
        assert.ok(existsSync(join(journal, 'last-sync-local-files.identity.json')));
        const summary = assertSuccessfulPush(runPush(site, localRoot, stateDir, { url }));
        assert.equal(summary.changed, 0);
        assert.equal(summary.deleted, 0);
    });

    function writeTarget(relativePath, contents) {
        const path = join(targetFixture, relativePath);
        execFileSync('sudo', ['mkdir', '-p', join(path, '..')]);
        execFileSync('sudo', ['tee', path], { input: contents, stdio: ['pipe', 'ignore', 'inherit'] });
        execFileSync('sudo', ['chown', 'nginx:nginx', path]);
    }

    function writeRandomTarget(relativePath, mebibytes) {
        const path = join(targetFixture, relativePath);
        execFileSync('sudo', ['mkdir', '-p', join(path, '..')]);
        execFileSync('sudo', [
            'dd', 'if=/dev/urandom', `of=${path}`, 'bs=1M', `count=${mebibytes}`, 'status=none',
        ]);
        execFileSync('sudo', ['chown', 'nginx:nginx', path]);
    }
});

function configureStaging(siteDir, stagingDir) {
    const configPath = join(siteDir, 'wp-config.php');
    let config = readFileSync(configPath, 'utf8');
    const definition = `define('SITE_EXPORT_STAGING_DIR', '${stagingDir}');`;
    if (/define\('SITE_EXPORT_STAGING_DIR'/.test(config)) {
        config = config.replace(/define\('SITE_EXPORT_STAGING_DIR'[^\n]+/, definition);
    } else {
        config = config.replace("if ( ! defined( 'ABSPATH' ) )", definition + "\nif ( ! defined( 'ABSPATH' ) )");
    }
    execFileSync('sudo', ['tee', configPath], { input: config, stdio: ['pipe', 'ignore', 'inherit'] });
    execFileSync('sudo', ['chown', 'nginx:nginx', configPath]);
    execFileSync('sudo', ['mkdir', '-p', stagingDir]);
    execFileSync('sudo', ['chown', '-R', 'nginx:nginx', stagingDir]);
}

function journalRoot(stateDir) {
    const pushRoot = join(stateDir, 'push');
    const directories = readdirSync(pushRoot);
    assert.equal(directories.length, 1, `Expected one target journal, got ${directories.join(', ')}`);
    return join(pushRoot, directories[0]);
}

function snapshotEntries(path) {
    if (!existsSync(path)) return [];
    return readFileSync(path, 'utf8').trim().split('\n').filter(Boolean).map((line) => {
        const entry = JSON.parse(line);
        return {
            ...entry,
            path: Buffer.from(entry.path, 'base64').toString(),
        };
    });
}

function plannedPaths(stateDir) {
    return snapshotEntries(join(journalRoot(stateDir), 'local-paths-to-push.jsonl'))
        .map((entry) => entry.path);
}

function deletedPaths(stateDir) {
    const stream = readFileSync(join(journalRoot(stateDir), 'local-delete-stream.bin'));
    if (stream.length === 0) return [];
    assert.equal(stream.at(-1), 0, 'The local delete stream must end at a NUL record boundary.');
    return stream.subarray(0, -1).toString().split('\0');
}

function assertSuccessfulPush(result, expectedStatus = 'complete') {
    assert.equal(result.exitCode, 0, `push failed\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);
    const lines = result.stdout.trim().split('\n').filter(Boolean);
    const summary = JSON.parse(lines.at(-1));
    assert.equal(summary.status, expectedStatus);
    return summary;
}

function startFilesPull(url, stateDir) {
    const child = spawn(PHP_BINARY, [
        IMPORTER_PATH, 'files-pull', url,
        `--state-dir=${stateDir}`, `--fs-root=${fsRootDir(stateDir)}`,
        `--secret=${getSiteSecret('push-pull-roundtrip')}`,
    ], { stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (data) => { stdout += data; });
    child.stderr.on('data', (data) => { stderr += data; });
    const completed = new Promise((resolve) => {
        child.on('close', (exitCode, signal) => resolve({ exitCode, signal, stdout, stderr }));
    });
    return { child, completed };
}

async function waitFor(predicate, timeout, message) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (predicate()) return;
        await sleep(5);
    }
    assert.fail(message);
}
