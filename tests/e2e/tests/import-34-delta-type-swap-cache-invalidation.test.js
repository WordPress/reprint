/**
 * Test 34: Delta type swaps across requests (single-process API server)
 *
 * Reproduces stale path-type behavior across sequential requests by using a
 * single-process PHP built-in server. Verifies both transitions:
 * - symlink -> file
 * - symlink -> directory (with nested file)
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, lstatSync, readFileSync } from 'node:fs';
import { execSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Delta type swaps cache invalidation', () => {
    const site = 'file-type-cache';
    const port = 8112;
    const scenarioName = 'delta-cache-type-swaps';
    const remoteRoot = join(getSiteDir(site), 'test-data', scenarioName);

    let apiServer = null;

    const sh = (v) => JSON.stringify(v);
    const sudoRun = (script) => {
        const oneLine = script
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .join('; ');
        execSync(`sudo bash -lc ${JSON.stringify(`set -euo pipefail; ${oneLine}`)}`);
    };

    async function startApiServer() {
        const fsRoot = getSiteDir(site);
        apiServer = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', fsRoot], {
            stdio: 'ignore',
        });

        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            if (apiServer.exitCode !== null) {
                throw new Error(`Built-in API server exited early with code ${apiServer.exitCode}`);
            }
            try {
                const res = await fetch(`http://127.0.0.1:${port}/`, { method: 'GET' });
                if (res.status >= 200) {
                    return;
                }
            } catch (_) {
                // retry
            }
            await sleep(100);
        }

        throw new Error(`Timed out waiting for API server on port ${port}`);
    }

    function stopApiServer() {
        if (apiServer && apiServer.exitCode === null) {
            apiServer.kill('SIGTERM');
        }
    }

    function importUrl() {
        return `http://127.0.0.1:${port}/?reprint-api&directory=${getSiteDir(site)}`;
    }

    function setupInitialRemoteLayout() {
        sudoRun(`
rm -rf ${sh(remoteRoot)}
mkdir -p ${sh(join(remoteRoot, 'targets', 'start-dir'))}
${`printf %b ${sh('start-dir-file\\n')} > ${sh(join(remoteRoot, 'targets', 'start-dir', 'a.txt'))}`}
${`printf %b ${sh('start-file\\n')} > ${sh(join(remoteRoot, 'targets', 'start-file.txt'))}`}
ln -s ${sh('targets/start-dir')} ${sh(join(remoteRoot, 'symlink-to-dir-to-file'))}
ln -s ${sh('targets/start-file.txt')} ${sh(join(remoteRoot, 'symlink-to-file-to-dir'))}
chown -R nginx:nginx ${sh(remoteRoot)}
`);
    }

    function applyDeltaRemoteChanges() {
        sudoRun(`
rm -f ${sh(join(remoteRoot, 'symlink-to-dir-to-file'))}
${`printf %b ${sh('became-file\\n')} > ${sh(join(remoteRoot, 'symlink-to-dir-to-file'))}`}

rm -f ${sh(join(remoteRoot, 'symlink-to-file-to-dir'))}
mkdir -p ${sh(join(remoteRoot, 'symlink-to-file-to-dir', 'x', 'y', 'z'))}
${`printf %b ${sh('became-directory\\n')} > ${sh(join(remoteRoot, 'symlink-to-file-to-dir', 'x', 'y', 'z', 'value.txt'))}`}

chown -R nginx:nginx ${sh(remoteRoot)}
`);
    }

    function runScenarioOnce(tempPrefix) {
        const tempDir = createTempDir(tempPrefix);
        setupInitialRemoteLayout();

        const initial = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(initial.exitCode, 0, `Expected exit 0\nstderr: ${initial.stderr}\nstdout: ${initial.stdout}`);

        applyDeltaRemoteChanges();

        // Abort previous completion so we can run a delta
        const abort = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(abort.exitCode, 0, `Expected abort exit 0\nstderr: ${abort.stderr}\nstdout: ${abort.stdout}`);

        const delta = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(delta.exitCode, 0, `Expected exit 0\nstderr: ${delta.stderr}\nstdout: ${delta.stdout}`);

        return tempDir;
    }

    beforeAll(async () => {
        await ensureSite(site);
        await startApiServer();
    });

    afterAll(() => {
        stopApiServer();
        execSync(`sudo rm -rf ${sh(remoteRoot)} 2>/dev/null || true`);
    });

    it('delta keeps symlink->file transition', () => {
        const tempDir = runScenarioOnce('e2e-delta-cache-type-swaps-file');
        try {
            const root = join(fsRootDir(tempDir), remoteRoot);
            const symlinkToDirToFile = join(root, 'symlink-to-dir-to-file');
            assert.ok(lstatSync(symlinkToDirToFile).isFile(), 'Expected symlink-to-dir-to-file to become a regular file');
            assert.equal(readFileSync(symlinkToDirToFile, 'utf-8'), 'became-file\n');
        } finally {
            cleanupTempDir(tempDir);
        }
    });

    it('delta keeps symlink->directory transition with nested data', () => {
        const tempDir = runScenarioOnce('e2e-delta-cache-type-swaps-dir');
        try {
            const root = join(fsRootDir(tempDir), remoteRoot);
            const nestedFile = join(root, 'symlink-to-file-to-dir', 'x', 'y', 'z', 'value.txt');
            assert.ok(existsSync(nestedFile), `Expected nested file at ${nestedFile}`);
            assert.equal(readFileSync(nestedFile, 'utf-8'), 'became-directory\n');
        } finally {
            cleanupTempDir(tempDir);
        }
    });
});
