/**
 * Test 53: direct delete-then-install push
 *
 * Uses the existing Docker WordPress/FPM/nginx environment, the real CLI and
 * signed staged endpoints, and filesystem inspection through persistent
 * volumes. No production callback controls commit timing: drift and process
 * interruption are introduced externally while real requests are in flight.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, readlinkSync,
    rmSync, symlinkSync, writeFileSync,
} from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { join, relative } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { randomBytes } from 'node:crypto';
import {
    cleanupTempDir, createTempDir, getSiteDir, getSiteSecret, getSiteUrl, runPush,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { HmacClient } from '../lib/hmac-client.js';

const IMPORTER_PATH = join(import.meta.dirname, '..', '..', '..', 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';

describe.sequential('Import: direct push apply', { timeout: 300000 }, () => {
    const site = 'push-direct';
    const targetRoot = getSiteDir(site);
    const stagingDir = join(targetRoot, '.reprint-staging');
    const targetFixture = join(targetRoot, 'push-fixture');
    let caseRoot;
    let sourceRoot;
    let stateDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterConfig: async (siteDir) => configureStaging(siteDir, stagingDir),
        });
        configureStaging(targetRoot, stagingDir);
        execFileSync('sudo', ['rm', '-rf', targetFixture, stagingDir]);
        execFileSync('sudo', ['mkdir', '-p', stagingDir]);
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', stagingDir]);
        caseRoot = createTempDir('e2e-push-direct');
        sourceRoot = join(caseRoot, 'source');
        stateDir = join(caseRoot, 'state');
        mkdirSync(sourceRoot, { recursive: true });
        mkdirSync(stateDir, { recursive: true });
    });

    afterAll(() => cleanupTempDir(caseRoot));

    it('applies basic and delta trees without target-side planning artifacts', () => {
        writeSource(sourceRoot, 'push-fixture/file.txt', 'first');
        writeSource(sourceRoot, 'push-fixture/nested/child.txt', 'nested');
        writeSource(sourceRoot, 'push-fixture/delete-tree/a/b.txt', 'delete subtree');
        mkdirSync(join(sourceRoot, 'push-fixture/empty'), { recursive: true });
        symlinkSync('file.txt', join(sourceRoot, 'push-fixture/link'));
        assertPush(runPush(site, sourceRoot, stateDir));

        writeSource(sourceRoot, 'push-fixture/file.txt', 'second');
        writeSource(sourceRoot, 'push-fixture/added.txt', 'added');
        rmSync(join(sourceRoot, 'push-fixture/delete-tree'), { recursive: true });
        assertPush(runPush(site, sourceRoot, stateDir));

        assert.deepEqual(logicalTree(join(sourceRoot, 'push-fixture')), logicalTree(targetFixture));
        const planDirectory = join(stateDir, 'push', readdirSync(join(stateDir, 'push'))[0]);
        const deletedPaths = readJsonLines(join(planDirectory, 'local-paths-to-delete.jsonl'))
            .map((record) => Buffer.from(record.path, 'base64').toString());
        assert.deepEqual(deletedPaths, ['push-fixture/delete-tree']);
        const obsoleteNames = new Set(['prepared', 'backups', 'actions', 'candidates', 'indexes', 'staged.jsonl']);
        for (const entry of recursiveNames(stagingDir)) {
            assert.ok(!obsoleteNames.has(entry), `obsolete target artifact exists: ${entry}`);
        }
    });

    it('covers the real file, symlink, empty-directory, and structural transitions', () => {
        rmSync(join(sourceRoot, 'push-fixture'), { recursive: true, force: true });
        const fixture = join(sourceRoot, 'push-fixture');
        writeSource(sourceRoot, 'push-fixture/file-to-file', 'old');
        writeSource(sourceRoot, 'push-fixture/file-to-symlink', 'old');
        symlinkSync('target-a', join(fixture, 'symlink-to-file'));
        symlinkSync('target-a', join(fixture, 'symlink-to-symlink'));
        writeSource(sourceRoot, 'push-fixture/directory-to-file/child', 'old');
        writeSource(sourceRoot, 'push-fixture/directory-to-symlink/child', 'old');
        writeSource(sourceRoot, 'push-fixture/directory-to-empty/child', 'old');
        writeSource(sourceRoot, 'push-fixture/file-to-empty', 'old');
        symlinkSync('target-a', join(fixture, 'symlink-to-empty'));
        writeSource(sourceRoot, 'push-fixture/file-to-structural', 'old');
        symlinkSync('target-a', join(fixture, 'symlink-to-structural'));
        mkdirSync(join(fixture, 'empty-to-structural'), { recursive: true });
        writeSource(sourceRoot, 'push-fixture/structural-stays/old', 'old');
        assertPush(runPush(site, sourceRoot, stateDir));

        writeFileSync(join(fixture, 'file-to-file'), 'new-value');
        rmSync(join(fixture, 'file-to-symlink'));
        symlinkSync('target-b', join(fixture, 'file-to-symlink'));
        rmSync(join(fixture, 'symlink-to-file'));
        writeFileSync(join(fixture, 'symlink-to-file'), 'new');
        rmSync(join(fixture, 'symlink-to-symlink'));
        symlinkSync('target-b', join(fixture, 'symlink-to-symlink'));
        replaceWithFile(join(fixture, 'directory-to-file'), 'new');
        replaceWithSymlink(join(fixture, 'directory-to-symlink'), 'target-b');
        replaceWithEmptyDirectory(join(fixture, 'directory-to-empty'));
        replaceWithEmptyDirectory(join(fixture, 'file-to-empty'));
        replaceWithEmptyDirectory(join(fixture, 'symlink-to-empty'));
        replaceWithStructuralDirectory(join(fixture, 'file-to-structural'));
        replaceWithStructuralDirectory(join(fixture, 'symlink-to-structural'));
        writeFileSync(join(fixture, 'empty-to-structural/child'), 'new');
        rmSync(join(fixture, 'structural-stays/old'));
        writeFileSync(join(fixture, 'structural-stays/new'), 'new');
        assertPush(runPush(site, sourceRoot, stateDir));

        assert.deepEqual(logicalTree(fixture), logicalTree(targetFixture));
        assert.equal(readFileSync(join(targetFixture, 'file-to-file'), 'utf8'), 'new-value');
        assert.equal(readlinkSync(join(targetFixture, 'file-to-symlink')), 'target-b');
        assert.equal(readFileSync(join(targetFixture, 'symlink-to-file'), 'utf8'), 'new');
        assert.equal(readlinkSync(join(targetFixture, 'symlink-to-symlink')), 'target-b');
    });

    it('replaces and recursively deletes symlinks without touching their referent', () => {
        execFileSync('sudo', ['sh', '-c', `printf safe > ${shellQuote(join(targetRoot, 'outside-push-sentinel'))}`]);
        rmSync(join(sourceRoot, 'push-fixture'), { recursive: true, force: true });
        mkdirSync(join(sourceRoot, 'push-fixture/symlink-safe'), { recursive: true });
        mkdirSync(join(sourceRoot, 'push-fixture/delete-with-link'), { recursive: true });
        symlinkSync('../../outside-push-sentinel', join(sourceRoot, 'push-fixture/symlink-safe/link'));
        symlinkSync('../../outside-push-sentinel', join(sourceRoot, 'push-fixture/delete-with-link/child-link'));
        assertPush(runPush(site, sourceRoot, stateDir));

        rmSync(join(sourceRoot, 'push-fixture/symlink-safe/link'));
        writeFileSync(join(sourceRoot, 'push-fixture/symlink-safe/link'), 'replacement');
        rmSync(join(sourceRoot, 'push-fixture/delete-with-link'), { recursive: true });
        assertPush(runPush(site, sourceRoot, stateDir));

        assert.equal(readFileSync(join(targetRoot, 'outside-push-sentinel'), 'utf8'), 'safe');
        assert.equal(readFileSync(join(targetFixture, 'symlink-safe/link'), 'utf8'), 'replacement');
        assert.ok(!existsSync(join(targetFixture, 'delete-with-link')));
    });

    it('resumes after externally killing the push PHP process during direct apply', async () => {
        rmSync(join(sourceRoot, 'push-fixture'), { recursive: true, force: true });
        for (let index = 0; index < 600; index++) {
            writeSource(sourceRoot, `push-fixture/interrupt/old/${index}.txt`, `old-${index}`);
        }
        assertPush(runPush(site, sourceRoot, stateDir));
        rmSync(join(sourceRoot, 'push-fixture/interrupt/old'), { recursive: true });
        for (let index = 0; index < 600; index++) {
            writeSource(sourceRoot, `push-fixture/interrupt/new/${index}.txt`, `new-${index}`);
        }

        const running = startPush(site, sourceRoot, stateDir);
        const oldDirectory = join(targetFixture, 'interrupt/old');
        let maintenancePresentAtFirstObservedMutation = false;
        await waitFor(() => {
            const markerWasPresent = existsSync(join(targetRoot, '.maintenance'));
            let remainingOldEntries = 0;
            try {
                remainingOldEntries = readdirSync(oldDirectory).length;
            } catch (error) {
                if (error.code !== 'ENOENT') throw error;
            }
            const mutationWasObserved = remainingOldEntries < 600
                || existsSync(join(targetFixture, 'interrupt/new/0.txt'));
            if (mutationWasObserved) {
                maintenancePresentAtFirstObservedMutation = markerWasPresent;
            }
            return mutationWasObserved;
        }, 90000, 'direct deletion or installation never began');
        assert.ok(maintenancePresentAtFirstObservedMutation, 'maintenance began after the first observed live mutation');
        await waitFor(() => existsSync(join(targetFixture, 'interrupt/new/0.txt')), 90000, 'direct installation never began');
        assert.ok(existsSync(join(targetRoot, '.maintenance')), 'maintenance was absent during incomplete apply');
        const home = new URL(getSiteUrl(site));
        home.search = '';
        assert.equal((await fetch(home)).status, 503);
        assert.ok(running.child.kill('SIGKILL'), 'active push PHP process exited before interruption');
        const interrupted = await running.completed;
        assert.notEqual(interrupted.exitCode, 0, `process interruption did not stop the active push: ${interrupted.stderr}`);
        assert.ok(existsSync(join(targetRoot, '.maintenance')), 'owned maintenance marker did not survive process death');
        assert.ok(!existsSync(oldDirectory), 'deletion had not completed before positive installation began');
        const activeSessions = readdirSync(join(stagingDir, 'apply-sessions')).filter((name) => /^[a-f0-9]{32}$/.test(name));
        assert.equal(activeSessions.length, 1);
        assert.ok(
            recursiveNames(join(stagingDir, 'apply-sessions', activeSessions[0], 'work', 'files')).length > 0,
            'process interruption left no pending staged values to resume',
        );

        assertPush(runPush(site, sourceRoot, stateDir));
        assert.deepEqual(logicalTree(join(sourceRoot, 'push-fixture')), logicalTree(targetFixture));
        assert.ok(!existsSync(join(targetRoot, '.maintenance')));
    });

    it('resumes delete bytes through the real signed endpoint without truncation', async () => {
        const created = await stagedRequest(site, 'POST', 'staged_session_create', {
            create_token: randomBytes(16).toString('hex'),
        });
        assert.equal(created.response.status, 201);
        const sessionId = created.body.session_id;
        const first = Buffer.from('first\0part');
        const accepted = await uploadDelete(site, sessionId, 0, first);
        assert.equal(accepted.body.accepted[0].accepted_bytes, first.length);

        // Deliberately discard the first response value, as a client would when
        // the response is lost, then replay the exact bytes.
        await uploadDelete(site, sessionId, 0, first);
        const replayed = await uploadDelete(site, sessionId, 0, Buffer.from('first\0partial\0'));
        assert.equal(replayed.body.accepted[0].accepted_bytes, Buffer.byteLength('first\0partial\0'));

        const different = await uploadDelete(site, sessionId, 0, Buffer.from('other\0'));
        assert.equal(different.response.status, 400);
        const gap = await uploadDelete(site, sessionId, 999, Buffer.from('later\0'));
        assert.equal(gap.response.status, 400);
        const status = await stagedRequest(site, 'GET', 'staged_session_status', { session_id: sessionId });
        assert.equal(status.body.delete_bytes, Buffer.byteLength('first\0partial\0'));

        const tailOffset = status.body.delete_bytes;
        await uploadDelete(site, sessionId, tailOffset, Buffer.from('unfinished'));
        await uploadDelete(site, sessionId, tailOffset + Buffer.byteLength('unfinished'), Buffer.alloc(0), true);
        const commit = await stagedRequest(site, 'POST', 'staged_session_commit', { session_id: sessionId });
        assert.equal(commit.response.status, 400);
        const after = await stagedRequest(site, 'GET', 'staged_session_status', { session_id: sessionId });
        assert.equal(after.body.delete_bytes, tailOffset + Buffer.byteLength('unfinished'));
        await stagedRequest(site, 'POST', 'staged_session_discard', { session_id: sessionId });
    });

    it('intentionally replaces compatible drift and stops on a symlink ancestor', async () => {
        rmSync(join(sourceRoot, 'push-fixture'), { recursive: true, force: true });
        writeSource(sourceRoot, 'push-fixture/live-drift/file.bin', 'x'.repeat(10 * 1024 * 1024));
        let running = startPush(site, sourceRoot, stateDir);
        await waitFor(() => sessionWorkExists(stagingDir, 'partial', 'push-fixture/live-drift/file.bin'), 60000, 'partial staged file was not observable');
        process.kill(running.child.pid, 'SIGSTOP');
        execFileSync('sudo', ['mkdir', '-p', join(targetFixture, 'live-drift')]);
        execFileSync('sudo', ['sh', '-c', `printf drift > ${shellQuote(join(targetFixture, 'live-drift/file.bin'))}`]);
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', join(targetFixture, 'live-drift')]);
        process.kill(running.child.pid, 'SIGCONT');
        let completed = await running.completed;
        assert.equal(completed.exitCode, 0, completed.stderr);
        assert.equal(lstatSync(join(targetFixture, 'live-drift/file.bin')).isFile(), true);
        assert.equal(readFileSync(join(targetFixture, 'live-drift/file.bin')).length, 10 * 1024 * 1024);

        writeSource(sourceRoot, 'push-fixture/conflict-parent/file.bin', 'y'.repeat(10 * 1024 * 1024));
        execFileSync('sudo', ['mkdir', '-p', join(targetRoot, 'outside-conflict')]);
        execFileSync('sudo', ['sh', '-c', `printf safe > ${shellQuote(join(targetRoot, 'outside-conflict/sentinel'))}`]);
        running = startPush(site, sourceRoot, stateDir);
        await waitFor(() => sessionWorkExists(stagingDir, 'partial', 'push-fixture/conflict-parent/file.bin'), 60000, 'conflicting path was not staged');
        process.kill(running.child.pid, 'SIGSTOP');
        execFileSync('sudo', ['ln', '-s', '../outside-conflict', join(targetFixture, 'conflict-parent')]);
        process.kill(running.child.pid, 'SIGCONT');
        completed = await running.completed;

        assert.notEqual(completed.exitCode, 0);
        const conflict = targetErrorFromPusher(completed.stderr);
        assert.equal(conflict.reason, 'live_tree_changed');
        assert.equal(conflict.operation, 'install');
        assert.equal(conflict.path_b64, Buffer.from('push-fixture/conflict-parent/file.bin').toString('base64'));
        assert.equal(conflict.conflict_path_b64, Buffer.from('push-fixture/conflict-parent').toString('base64'));
        assert.equal(conflict.staged_type, 'directory');
        assert.deepEqual(conflict.expected_live_types, ['absent', 'directory']);
        assert.equal(conflict.observed_live_identity.type, 'symlink');
        assert.match(conflict.detail, /conflicting path was left untouched/);
        assert.equal(readFileSync(join(targetRoot, 'outside-conflict/sentinel'), 'utf8'), 'safe');
        assert.equal(readlinkSync(join(targetFixture, 'conflict-parent')), '../outside-conflict');
        assert.ok(existsSync(join(targetRoot, '.maintenance')));
    });
});

describe.sequential('Import: incompatible live drift', { timeout: 180000 }, () => {
    const site = 'push-live-conflict';
    const target = getSiteDir(site);
    const staging = join(target, '.reprint-staging');

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterConfig: async (siteDir) => configureStaging(siteDir, staging),
        });
        configureStaging(target, staging);
        execFileSync('sudo', ['rm', '-rf', join(target, 'live-conflict'), staging]);
        execFileSync('sudo', ['mkdir', '-p', staging]);
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', staging]);
    });

    it('leaves an incompatible destination directory untouched with the complete error schema', async () => {
        const created = await stagedRequest(site, 'POST', 'staged_session_create', {
            create_token: randomBytes(16).toString('hex'),
        });
        assert.equal(created.response.status, 201);
        const sessionId = created.body.session_id;
        const payload = Buffer.from('staged file');
        const boundary = 'file-' + randomBytes(8).toString('hex');
        const uploadBody = multipartBody(boundary, {
            'X-Chunk-Type': 'file',
            'X-File-Path': Buffer.from('live-conflict').toString('base64'),
            'X-File-Size': String(payload.length),
            'X-Chunk-Offset': '0',
        }, payload);
        const uploaded = await stagedRequest(
            site, 'POST', 'staged_session_upload', { session_id: sessionId }, uploadBody,
            `multipart/mixed; boundary=${boundary}`,
        );
        assert.equal(uploaded.response.status, 200);
        const deletesCompleted = await uploadDelete(site, sessionId, 0, Buffer.alloc(0), true);
        assert.equal(deletesCompleted.response.status, 200);

        execFileSync('sudo', ['mkdir', '-p', join(target, 'live-conflict')]);
        execFileSync('sudo', ['sh', '-c', `printf safe > ${shellQuote(join(target, 'live-conflict/sentinel'))}`]);

        const rejected = await stagedRequest(site, 'POST', 'staged_session_commit', { session_id: sessionId });
        assert.equal(rejected.response.status, 409);
        assert.equal(rejected.body.reason, 'live_tree_changed');
        assert.equal(rejected.body.operation, 'install');
        assert.equal(rejected.body.path_b64, Buffer.from('live-conflict').toString('base64'));
        assert.equal(rejected.body.conflict_path_b64, Buffer.from('live-conflict').toString('base64'));
        assert.equal(rejected.body.staged_type, 'file');
        assert.deepEqual(rejected.body.expected_live_types, ['absent', 'file', 'symlink']);
        assert.equal(rejected.body.observed_live_identity.type, 'directory');
        assert.match(rejected.body.detail, /conflicting path was left untouched/);
        assert.equal(readFileSync(join(target, 'live-conflict/sentinel'), 'utf8'), 'safe');
        assert.ok(existsSync(join(target, '.maintenance')));
        const home = new URL(getSiteUrl(site));
        home.search = '';
        assert.equal((await fetch(home)).status, 503);
    });
});

describe.sequential('Import: cross-device push refusal', { timeout: 180000 }, () => {
    let caseRoot;

    beforeAll(async () => {
        await ensureSite('push-cross-device', {
            files: 'none',
            afterConfig: async (siteDir) => configureStaging(siteDir, '/srv/reprint-e2e-staging/push-cross-device'),
        });
        configureStaging(getSiteDir('push-cross-device'), '/srv/reprint-e2e-staging/push-cross-device');
        execFileSync('sudo', ['mkdir', '-p', '/srv/reprint-e2e-staging/push-cross-device']);
        execFileSync('sudo', ['chown', '-R', 'nginx:nginx', '/srv/reprint-e2e-staging/push-cross-device']);
        await ensureSite('push-mounted-parent', {
            files: 'none',
            afterConfig: async (siteDir) => configureStaging(siteDir, join(siteDir, '.reprint-staging')),
        });
        configureStaging(getSiteDir('push-mounted-parent'), join(getSiteDir('push-mounted-parent'), '.reprint-staging'));
        assert.notEqual(
            lstatSync('/srv/reprint-e2e-staging').dev,
            lstatSync(getSiteDir('push-cross-device')).dev,
            'cross-device Docker test requires --tmpfs /srv/reprint-e2e-staging',
        );
        assert.notEqual(
            lstatSync(join(getSiteDir('push-mounted-parent'), 'mounted-parent')).dev,
            lstatSync(getSiteDir('push-mounted-parent')).dev,
            'mount-boundary Docker test requires --tmpfs /srv/e2e-sites/push-mounted-parent/mounted-parent',
        );
        caseRoot = createTempDir('e2e-push-cross-device');
    });

    afterAll(() => cleanupTempDir(caseRoot));

    it('rejects session storage on another volume before accepting staged bytes', () => {
        const source = join(caseRoot, 'source-mismatch');
        const state = join(caseRoot, 'state-mismatch');
        writeSource(source, 'must-not-arrive', 'no copy fallback');
        const result = runPush('push-cross-device', source, state);
        assert.notEqual(result.exitCode, 0);
        const rejection = targetErrorFromPusher(result.stderr);
        assert.equal(rejection.reason, 'cross_device_filesystem');
        assert.equal(rejection.operation, 'stage');
        assert.equal(rejection.path_b64, '');
        assert.notEqual(rejection.staging_device, rejection.live_device);
        assert.ok(!existsSync(join(getSiteDir('push-cross-device'), 'must-not-arrive')));
        const sessions = '/srv/reprint-e2e-staging/push-cross-device/apply-sessions';
        if (existsSync(sessions)) {
            assert.equal(readdirSync(sessions).filter((name) => /^[a-f0-9]{32}$/.test(name)).length, 0);
        }
    });

    it('refuses a staged parent and recursive delete at a nested mounted volume', () => {
        const source = join(caseRoot, 'source-mounted');
        const state = join(caseRoot, 'state-mounted');
        mkdirSync(source, { recursive: true });
        const mountedSite = 'push-mounted-parent';
        const target = getSiteDir(mountedSite);
        const mountedParent = join(target, 'mounted-parent');
        execFileSync('sudo', ['mkdir', '-p', mountedParent]);
        execFileSync('sudo', ['sh', '-c', `printf safe > ${shellQuote(join(mountedParent, 'sentinel'))}`]);

        writeSource(source, 'mounted-parent/new.txt', 'must not stage');
        let result = runPush(mountedSite, source, state);
        assert.notEqual(result.exitCode, 0);
        let rejection = targetErrorFromPusher(result.stderr);
        assert.equal(rejection.reason, 'cross_device_filesystem');
        assert.equal(rejection.operation, 'stage');
        assert.equal(rejection.path_b64, Buffer.from('mounted-parent/new.txt').toString('base64'));
        assert.notEqual(rejection.staging_device, rejection.live_device);
        assert.ok(!existsSync(join(target, '.maintenance')));
        const aborted = runPush(mountedSite, source, state, { extraArgs: ['--abort'] });
        assert.equal(aborted.exitCode, 0, aborted.stderr);
        rmSync(source, { recursive: true });
        mkdirSync(source, { recursive: true });
        rmSync(state, { recursive: true, force: true });
        mkdirSync(state, { recursive: true });

        const dryRun = runPush(mountedSite, source, state, { extraArgs: ['--dry-run'] });
        assert.equal(dryRun.exitCode, 0, dryRun.stderr);
        const pushStateRoot = join(state, 'push', readdirSync(join(state, 'push'))[0]);
        writeFileSync(join(pushStateRoot, 'last-sync-local-files.jsonl'), [
            indexRecord('mounted-parent', 'tree-directory', 0),
            indexRecord('mounted-parent/sentinel', 'file', 4),
        ].join('\n') + '\n');
        result = runPush(mountedSite, source, state);
        assert.notEqual(result.exitCode, 0);
        rejection = targetErrorFromPusher(result.stderr);
        assert.equal(rejection.reason, 'cross_device_filesystem');
        assert.equal(rejection.operation, 'delete');
        assert.equal(rejection.path_b64, Buffer.from('mounted-parent').toString('base64'));
        assert.notEqual(rejection.staging_device, rejection.live_device);
        assert.equal(readFileSync(join(mountedParent, 'sentinel'), 'utf8'), 'safe');
        assert.ok(existsSync(join(target, '.maintenance')));
    });
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

function writeSource(root, path, contents) {
    const target = join(root, path);
    mkdirSync(join(target, '..'), { recursive: true });
    writeFileSync(target, contents);
}

function replaceWithFile(path, contents) {
    rmSync(path, { recursive: true, force: true });
    writeFileSync(path, contents);
}

function replaceWithSymlink(path, target) {
    rmSync(path, { recursive: true, force: true });
    symlinkSync(target, path);
}

function replaceWithEmptyDirectory(path) {
    rmSync(path, { recursive: true, force: true });
    mkdirSync(path, { recursive: true });
}

function replaceWithStructuralDirectory(path) {
    replaceWithEmptyDirectory(path);
    writeFileSync(join(path, 'child'), 'new');
}

function logicalTree(root) {
    const entries = [];
    function walk(directory) {
        for (const name of readdirSync(directory).sort()) {
            const path = join(directory, name);
            const relativePath = relative(root, path);
            const stat = lstatSync(path);
            if (stat.isSymbolicLink()) {
                entries.push([relativePath, 'symlink', readlinkSync(path)]);
            } else if (stat.isDirectory()) {
                entries.push([relativePath, 'directory']);
                walk(path);
            } else {
                entries.push([relativePath, 'file', readFileSync(path).toString('base64')]);
            }
        }
    }
    walk(root);
    return entries;
}

function recursiveNames(root) {
    let entries;
    try {
        entries = readdirSync(root);
    } catch (error) {
        if (error.code === 'ENOENT') return [];
        throw error;
    }
    const names = [];
    for (const name of entries) {
        const path = join(root, name);
        let stat;
        try {
            stat = lstatSync(path);
        } catch (error) {
            // The killed sender can leave one bounded target request finishing
            // while this observes the pending queue it is consuming.
            if (error.code === 'ENOENT') continue;
            throw error;
        }
        names.push(name);
        if (stat.isDirectory()) names.push(...recursiveNames(path));
    }
    return names;
}

function readJsonLines(path) {
    return readFileSync(path, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line));
}

function assertPush(result) {
    assert.equal(result.exitCode, 0, `push failed\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);
}

function targetErrorFromPusher(stderr) {
    const lines = stderr.trim().split('\n').filter(Boolean);
    const reported = JSON.parse(lines.at(-1));
    const responseStart = reported.error.indexOf('{');
    assert.notEqual(responseStart, -1, `pusher omitted the structured target response: ${reported.error}`);
    return JSON.parse(reported.error.slice(responseStart));
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

async function waitFor(predicate, timeout, message) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (predicate()) return;
        await sleep(5);
    }
    assert.fail(message);
}

function sessionWorkExists(stagingDir, workType, path) {
    const sessionsDir = join(stagingDir, 'apply-sessions');
    if (!existsSync(sessionsDir)) return false;
    for (const session of readdirSync(sessionsDir)) {
        if (/^[a-f0-9]{32}$/.test(session) && existsSync(join(sessionsDir, session, 'work', workType, path))) {
            return true;
        }
    }
    return false;
}

async function stagedRequest(site, method, endpoint, parameters, body = null, contentType = null) {
    const url = new URL(getSiteUrl(site));
    url.searchParams.set('endpoint', endpoint);
    for (const [name, value] of Object.entries(parameters)) url.searchParams.set(name, value);
    const headers = new HmacClient(getSiteSecret(site)).getEnvelopeAuthHeaders(method, url.toString());
    if (contentType !== null) headers['Content-Type'] = contentType;
    const response = await fetch(url, { method, headers, body: body === null ? undefined : body });
    return { response, body: await response.json() };
}

function uploadDelete(site, sessionId, offset, payload, complete = false) {
    const boundary = 'delete-' + randomBytes(8).toString('hex');
    const headers = {
        'X-Chunk-Type': 'delete-list',
        'X-Delete-Offset': String(offset),
    };
    if (complete) headers['X-Delete-Complete'] = '1';
    const body = multipartBody(boundary, headers, payload);
    return stagedRequest(
        site, 'POST', 'staged_session_upload', { session_id: sessionId }, body,
        `multipart/mixed; boundary=${boundary}`
    );
}

function multipartBody(boundary, headers, payload) {
    const lines = [`--${boundary}`];
    for (const [name, value] of Object.entries(headers)) lines.push(`${name}: ${value}`);
    lines.push(`Content-Length: ${payload.length}`, '', '');
    return Buffer.concat([
        Buffer.from(lines.join('\r\n')),
        payload,
        Buffer.from(`\r\n--${boundary}--\r\n`),
    ]);
}

function indexRecord(path, type, size) {
    return JSON.stringify({ path: Buffer.from(path).toString('base64'), type, size, ctime: 1 });
}

function shellQuote(value) {
    return `'${value.replaceAll("'", "'\\''")}'`;
}
