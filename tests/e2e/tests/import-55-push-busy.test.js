/**
 * Test 55: recoverable staged-push contention
 *
 * Holds the real exporter flock files or creates the real discard tombstone
 * from a second process. The public CLI must retry boundedly without a
 * production interruption hook or an injected transport.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync,
} from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { dirname, join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    cleanupTempDir, createTempDir, getSiteDir, getSiteSecret, getSiteUrl, runPush,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { HmacClient } from '../lib/hmac-client.js';

const IMPORTER_PATH = join(import.meta.dirname, '..', '..', '..', 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const PHP_VERSION_ID = Number(execFileSync(PHP_BINARY, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }));
// PHP.wasm cannot hold the exporter's host-filesystem flock. Native push lanes
// run this suite across the supported exporter/importer pairings instead.
const describeNativePushContention = PHP_VERSION_ID >= 80100 && !process.env.PLAYGROUND_PHP_VERSION
    ? describe.sequential
    : describe.skip;

describeNativePushContention('Import: recoverable push contention', { timeout: 180000 }, () => {
    const site = 'push-busy';
    const targetRoot = getSiteDir(site);
    const stagingDir = join(targetRoot, '.reprint-staging');
    const createLock = join(stagingDir, 'apply-sessions/create.lock');
    const targetLock = join(stagingDir, 'apply-sessions/target.lock');
    let caseRoot;
    let persistedCreation;
    let persistedCommit;
    let persistedDiscard;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterConfig: async (siteDir) => configureStaging(siteDir, stagingDir),
        });
        configureStaging(targetRoot, stagingDir);
        execFileSync('sudo', ['rm', '-rf', join(targetRoot, 'busy-fixture'), stagingDir]);
        execFileSync('sudo', ['mkdir', '-p', stagingDir]);
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', stagingDir]);
        caseRoot = createTempDir('e2e-push-busy');
    });

    afterAll(() => {
        execFileSync('sudo', ['rm', '-rf', stagingDir]);
        cleanupTempDir(caseRoot);
    });

    it('recovers automatically when session creation becomes available', async () => {
        const scenario = createScenario(caseRoot, 'transient-create', 'created after contention');
        const held = await holdFlock(createLock, caseRoot, 1200);
        const started = Date.now();

        const result = runPush(site, scenario.source, scenario.state);
        await held.completed;

        assertPush(result);
        assert.ok(Date.now() - started >= 900, 'push did not encounter the held creation lock');
        assert.equal(readFileSync(join(targetRoot, scenario.path), 'utf8'), scenario.contents);
    });

    it('fails boundedly when session creation stays busy', async () => {
        const scenario = createScenario(caseRoot, 'persistent-create', 'resume this creation');
        const held = await holdFlock(createLock, caseRoot);

        const result = runPush(site, scenario.source, scenario.state);
        held.release();
        await held.completed;

        assertBoundedBusyFailure(result);
        const checkpoint = readCheckpoint(scenario.state);
        assert.equal(checkpoint.phase, 'creating');
        assert.equal(checkpoint.session_id, null);
        assert.ok(typeof checkpoint.create_token === 'string');
        assert.ok(!existsSync(join(targetRoot, scenario.path)));
        persistedCreation = { ...scenario, createToken: checkpoint.create_token };
    });

    it('restarts from the creation checkpoint and recovers a busy upload', async () => {
        assert.ok(persistedCreation, 'persistent creation scenario did not run');
        const created = await stagedRequest(site, 'POST', 'staged_session_create', {
            create_token: persistedCreation.createToken,
        });
        assert.equal(created.response.status, 201);
        const sessionLock = join(stagingDir, 'apply-sessions', created.body.session_id, 'lock');
        const held = await holdFlock(sessionLock, caseRoot, 1400);
        const started = Date.now();

        const result = runPush(site, persistedCreation.source, persistedCreation.state);
        await held.completed;

        assertPush(result);
        assert.ok(Date.now() - started >= 1000, 'push did not encounter the held upload lock');
        assert.equal(readFileSync(join(targetRoot, persistedCreation.path), 'utf8'), persistedCreation.contents);
        assert.ok(!existsSync(checkpointPath(persistedCreation.state)));
    });

    it('recovers automatically when commit coordination becomes available', async () => {
        const scenario = createScenario(caseRoot, 'transient-commit', 'committed after contention');
        const held = await holdFlock(targetLock, caseRoot, 1400);
        const started = Date.now();

        const result = runPush(site, scenario.source, scenario.state);
        await held.completed;

        assertPush(result);
        assert.ok(Date.now() - started >= 1000, 'push did not encounter the held commit lock');
        assert.equal(readFileSync(join(targetRoot, scenario.path), 'utf8'), scenario.contents);
    });

    it('fails boundedly when commit coordination stays busy', async () => {
        const scenario = createScenario(caseRoot, 'persistent-commit', 'resume this commit');
        const held = await holdFlock(targetLock, caseRoot);

        const result = runPush(site, scenario.source, scenario.state);
        held.release();
        await held.completed;

        assertBoundedBusyFailure(result);
        const checkpoint = readCheckpoint(scenario.state);
        assert.equal(checkpoint.phase, 'committing');
        assert.ok(!existsSync(join(targetRoot, scenario.path)));
        persistedCommit = scenario;
    });

    it('restarts commit from its confirmed checkpoint after contention clears', () => {
        assert.ok(persistedCommit, 'persistent commit scenario did not run');

        const result = runPush(site, persistedCommit.source, persistedCommit.state);

        assertPush(result);
        assert.equal(readFileSync(join(targetRoot, persistedCommit.path), 'utf8'), persistedCommit.contents);
        assert.ok(!existsSync(checkpointPath(persistedCommit.state)));
    });

    it('recovers automatically when discard contention clears', async () => {
        const scenario = createScenario(caseRoot, 'transient-discard', 'cleaned after contention');
        const held = await holdFlock(createLock, caseRoot, 600);
        const running = startPush(site, scenario.source, scenario.state);
        await waitFor(() => checkpointExists(scenario.state), 30000, 'push did not persist its create token');
        const checkpoint = readCheckpoint(scenario.state);
        const tombstone = discardTombstone(stagingDir, checkpoint.create_token, getSiteSecret(site));
        execFileSync('sudo', ['mkdir', '-p', tombstone]);
        await held.completed;
        await waitFor(
            () => checkpointExists(scenario.state) && readCheckpoint(scenario.state).phase === 'cleaning',
            60000,
            'push did not reach discard cleanup',
        );
        await sleep(500);
        execFileSync('sudo', ['rm', '-rf', tombstone]);

        const result = await running.completed;

        assertPush(result);
        assert.equal(readFileSync(join(targetRoot, scenario.path), 'utf8'), scenario.contents);
        assert.ok(!existsSync(checkpointPath(scenario.state)));
    });

    it('fails boundedly when discard stays busy', async () => {
        const scenario = createScenario(caseRoot, 'persistent-discard', 'resume this cleanup');
        const held = await holdFlock(createLock, caseRoot, 600);
        const running = startPush(site, scenario.source, scenario.state);
        await waitFor(() => checkpointExists(scenario.state), 30000, 'push did not persist its create token');
        const checkpoint = readCheckpoint(scenario.state);
        const tombstone = discardTombstone(stagingDir, checkpoint.create_token, getSiteSecret(site));
        execFileSync('sudo', ['mkdir', '-p', tombstone]);
        await held.completed;

        const result = await running.completed;

        assertBoundedBusyFailure(result);
        assert.equal(readCheckpoint(scenario.state).phase, 'cleaning');
        assert.equal(readFileSync(join(targetRoot, scenario.path), 'utf8'), scenario.contents);
        persistedDiscard = { ...scenario, tombstone };
    });

    it('restarts cleanup from its confirmed checkpoint after contention clears', () => {
        assert.ok(persistedDiscard, 'persistent discard scenario did not run');
        execFileSync('sudo', ['rm', '-rf', persistedDiscard.tombstone]);

        const result = runPush(site, persistedDiscard.source, persistedDiscard.state);

        assertPush(result);
        assert.equal(readFileSync(join(targetRoot, persistedDiscard.path), 'utf8'), persistedDiscard.contents);
        assert.ok(!existsSync(checkpointPath(persistedDiscard.state)));
    });
});

function createScenario(caseRoot, name, contents) {
    const source = join(caseRoot, name, 'source');
    const state = join(caseRoot, name, 'state');
    const path = `busy-fixture/${name}.txt`;
    mkdirSync(join(source, 'busy-fixture'), { recursive: true });
    mkdirSync(state, { recursive: true });
    writeFileSync(join(source, path), contents);
    return { source, state, path, contents };
}

async function holdFlock(lockPath, caseRoot, milliseconds = null) {
    const ready = join(caseRoot, `lock-ready-${randomBytes(8).toString('hex')}`);
    const release = join(caseRoot, `lock-release-${randomBytes(8).toString('hex')}`);
    execFileSync('sudo', ['mkdir', '-p', dirname(lockPath)]);
    execFileSync('sudo', ['chown', 'nginx:nginx', dirname(lockPath)]);
    execFileSync('sudo', ['touch', lockPath]);
    execFileSync('sudo', ['chown', 'nginx:nginx', lockPath]);
    const script = [
        '$handle = fopen($argv[1], "c+b");',
        'if ($handle === false || !flock($handle, LOCK_EX)) { exit(2); }',
        'file_put_contents($argv[2], "ready\\n");',
        'if ($argv[3] === "") { while (!is_file($argv[4])) { usleep(10000); } }',
        'else { usleep((int) $argv[3]); }',
        'flock($handle, LOCK_UN);',
        'fclose($handle);',
    ].join(' ');
    const child = spawn('sudo', [
        'php', '-r', script, lockPath, ready,
        milliseconds === null ? '' : String(milliseconds * 1000), release,
    ], { stdio: ['ignore', 'ignore', 'pipe'] });
    let stderr = '';
    child.stderr.on('data', (data) => { stderr += data; });
    const completed = new Promise((resolve, reject) => {
        child.on('error', reject);
        child.on('close', (exitCode) => {
            try {
                assert.equal(exitCode, 0, `lock holder failed: ${stderr}`);
                execFileSync('sudo', ['rm', '-f', ready, release]);
                resolve();
            } catch (error) {
                reject(error);
            }
        });
    });
    await waitFor(() => existsSync(ready), 10000, `could not acquire ${lockPath}`);
    return {
        completed,
        release: () => writeFileSync(release, 'release\n'),
    };
}

function checkpointPath(state) {
    const pushRoot = join(state, 'push');
    const sites = readdirSync(pushRoot);
    assert.equal(sites.length, 1, `expected one push state directory below ${pushRoot}`);
    return join(pushRoot, sites[0], 'session.json');
}

function checkpointExists(state) {
    try {
        return existsSync(checkpointPath(state));
    } catch {
        return false;
    }
}

function readCheckpoint(state) {
    return JSON.parse(readFileSync(checkpointPath(state), 'utf8'));
}

function discardTombstone(stagingDir, createToken, secret) {
    const sessionId = createHmac('sha256', secret)
        .update(`reprint-multipart-push-create-v1:${createToken}`)
        .digest('hex')
        .slice(0, 32);
    return join(stagingDir, 'apply-sessions', `.discarding-${sessionId}`);
}

function assertPush(result) {
    assert.equal(result.exitCode, 0, `push failed\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);
}

function assertBoundedBusyFailure(result) {
    assert.notEqual(result.exitCode, 0, 'persistent busy unexpectedly completed');
    assert.match(result.stderr, /remained busy after 5 attempts/);
    assert.doesNotMatch(result.stderr, /"status"\s*:\s*"retry"/);
}

function startPush(site, sourceRoot, stateDir) {
    const child = spawn(PHP_BINARY, [
        IMPORTER_PATH, 'push', getSiteUrl(site),
        `--state-dir=${stateDir}`, `--source-root=${sourceRoot}`,
        `--secret=${getSiteSecret(site)}`, '--allow-http',
    ], { stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (data) => { stdout += data; });
    child.stderr.on('data', (data) => { stderr += data; });
    const completed = new Promise((resolve) => {
        child.on('close', (exitCode) => resolve({ exitCode, stdout, stderr }));
    });
    return { child, completed };
}

async function stagedRequest(site, method, endpoint, parameters) {
    const url = new URL(getSiteUrl(site));
    url.searchParams.set('endpoint', endpoint);
    for (const [name, value] of Object.entries(parameters)) url.searchParams.set(name, value);
    const headers = new HmacClient(getSiteSecret(site)).getEnvelopeAuthHeaders(method, url.toString());
    const response = await fetch(url, { method, headers });
    return { response, body: await response.json() };
}

async function waitFor(predicate, timeout, message) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (predicate()) return;
        await sleep(5);
    }
    assert.fail(message);
}

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
