/** Test 66: Reject a preview-rewritten preflight, then recover with octet-stream. */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { fork } from 'node:child_process';
import { once } from 'node:events';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    apiRequest, cleanupTempDir, createTempDir, fsRootDir,
    getSiteDir, getSiteSecret, getSiteUrl, pullStateDirectory, runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Preflight domain rewriting', { timeout: 180000 }, () => {
    const site = 'basic';
    let proxyProcess;
    let importUrl;
    let outputDirectory;
    let contentTypePath;
    let requestLogPath;

    beforeAll(async () => {
        await ensureSite(site);
        outputDirectory = createTempDir('e2e-preflight-domain-rewriting');
        contentTypePath = join(outputDirectory, 'content-type');
        requestLogPath = join(outputDirectory, 'requests.jsonl');
        writeFileSync(contentTypePath, 'application/json');
        writeFileSync(requestLogPath, '');

        // runImporter blocks this process, so the proxy needs its own process.
        proxyProcess = fork(fileURLToPath(new URL(
            '../lib/preflight-domain-rewriting-fixture.js', import.meta.url,
        )), [getSiteUrl(site), contentTypePath, requestLogPath], {
            stdio: ['ignore', 'inherit', 'inherit', 'ipc'],
        });
        const [ready] = await once(proxyProcess, 'message');
        importUrl = `http://127.0.0.1:${ready.port}/?reprint-api&directory=` +
            encodeURIComponent(getSiteDir(site));
    });

    afterAll(async () => {
        if (proxyProcess && proxyProcess.exitCode === null) {
            proxyProcess.kill('SIGTERM');
            await once(proxyProcess, 'exit');
        }
        cleanupTempDir(outputDirectory);
    });

    it('stops before file transfer on rewritten JSON and pulls after restoring octet-stream', async () => {
        const options = {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            extraArgs: ['--include=' + join(getSiteDir(site), 'test-data/hello.txt')],
        };
        const response = await apiRequest(site, 'preflight', {}, { url: importUrl, rawResponse: true });
        const rewrittenPreflight = await response.json();
        assert.equal(response.status, 200);
        assert.equal(response.headers.get('content-type'), 'application/json');
        assert.equal(rewrittenPreflight.ok, true);
        assert.equal(new URL(rewrittenPreflight.database.wp.home).hostname, 'preview.example.test');
        const originalDomain = Buffer.from(
            rewrittenPreflight.database.wp.home_domain_b64, 'base64',
        ).toString('utf-8');
        assert.equal(originalDomain, new URL(getSiteUrl(site)).hostname);

        const error = `The preflight response changed the site domain from '${originalDomain}' ` +
            "to 'preview.example.test'";
        for (const command of ['preflight', 'pull-files']) {
            const result = runImporter(importUrl, outputDirectory, command, options);
            assert.equal(result.exitCode, 1, result.stdout + result.stderr);
            assert.ok((result.stdout + result.stderr).includes(error), result.stdout + result.stderr);
        }
        const statePath = join(pullStateDirectory(outputDirectory, importUrl), 'state.json');
        const failedState = JSON.parse(readFileSync(statePath, 'utf-8'));
        assert.equal(failedState.preflight.data.ok, false);
        assert.ok(failedState.preflight.error.includes(error));
        const failedRequests = readFileSync(requestLogPath, 'utf-8').trim().split('\n').map(JSON.parse);
        assert.ok(failedRequests.every(record => record.endpoint === 'preflight'));
        const pulledFile = join(fsRootDir(outputDirectory), getSiteDir(site), 'test-data/hello.txt');
        assert.equal(existsSync(pulledFile), false);

        // Remove the override: the real endpoint must now supply octet-stream.
        writeFileSync(contentTypePath, '');
        const restoredResponse = await apiRequest(site, 'preflight', {}, { url: importUrl, rawResponse: true });
        const restoredPreflight = await restoredResponse.json();
        assert.ok(restoredResponse.headers.get('content-type').startsWith('application/octet-stream'));
        assert.equal(new URL(restoredPreflight.database.wp.home).hostname, originalDomain);
        assert.equal(
            restoredPreflight.database.wp.home_domain_b64,
            rewrittenPreflight.database.wp.home_domain_b64,
        );
        for (const command of ['preflight', 'pull-files']) {
            const result = runImporter(importUrl, outputDirectory, command, options);
            assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        }
        const restoredState = JSON.parse(readFileSync(statePath, 'utf-8'));
        assert.equal(restoredState.preflight.data.ok, true);
        assert.equal(restoredState.preflight.error, null);
        assert.equal(readFileSync(pulledFile, 'utf-8'), 'Hello World\n');
    });
});
