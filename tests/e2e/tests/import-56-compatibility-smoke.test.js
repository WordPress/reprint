/**
 * Test 56: importer/exporter compatibility smoke coverage
 *
 * Supported importer runtimes perform a real pull from the configured
 * exporter. PHP 7.4 and 8.0 run the same public push command and prove the
 * runtime requirement is reported before any network request is attempted.
 */
import { describe, it } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    cleanupTempDir, createTempDir, fsRootDir, getSiteDir, getSiteSecret, getSiteUrl,
    IMPORTER_PATH, PHP_BINARY, runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const importerVersionId = Number(execFileSync(PHP_BINARY, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }));
const pushIsSupported = importerVersionId >= 80100;

describe('Import: compatibility smoke', { timeout: 180000 }, () => {
    it.skipIf(!pushIsSupported)('preflights and pulls with the configured importer and exporter PHP versions', async () => {
        await ensureSite('basic');
        const stateDir = createTempDir('e2e-compatibility-pull');
        try {
            const url = `${getSiteUrl('basic')}&directory=${getSiteDir('basic')}`;
            const preflight = runImporter(url, stateDir, 'preflight', { secret: getSiteSecret('basic') });
            assert.equal(preflight.exitCode, 0, preflight.stderr);
            const result = runImporter(
                url,
                stateDir,
                'files-pull',
                { secret: getSiteSecret('basic') },
            );

            assert.equal(result.exitCode, 0, result.stderr);
            assert.ok(existsSync(join(fsRootDir(stateDir), getSiteDir('basic'), 'index.php')),
                'The compatibility pull omitted the WordPress entry point.');
        } finally {
            cleanupTempDir(stateDir);
        }
    });

    it.skipIf(pushIsSupported)('reports the PHP 8.1 requirement before making a request', async () => {
        const caseRoot = mkdtempSync(join(tmpdir(), 'reprint-push-requirement-'));
        const sourceRoot = join(caseRoot, 'source');
        const stateDir = join(caseRoot, 'state');
        mkdirSync(sourceRoot);
        writeFileSync(join(sourceRoot, 'file.txt'), 'unsupported runtime');
        let acceptedConnections = 0;
        const server = createServer((socket) => {
            ++acceptedConnections;
            socket.end("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        });
        await new Promise((resolve, reject) => {
            server.once('error', reject);
            server.listen(0, '127.0.0.1', resolve);
        });
        const address = server.address();
        assert.ok(address && typeof address === 'object');

        try {
            const child = spawn(PHP_BINARY, [
                IMPORTER_PATH,
                'push',
                `http://127.0.0.1:${address.port}/?reprint-api`,
                `--source-root=${sourceRoot}`,
                `--state-dir=${stateDir}`,
                '--secret=compatibility-secret',
                '--allow-http',
            ], { stdio: ['ignore', 'pipe', 'pipe'] });
            let stdout = '';
            let stderr = '';
            child.stdout.on('data', (chunk) => { stdout += chunk; });
            child.stderr.on('data', (chunk) => { stderr += chunk; });
            const exitCode = await new Promise((resolve, reject) => {
                const timeout = setTimeout(() => {
                    child.kill('SIGKILL');
                    reject(new Error('Unsupported-runtime push did not exit within 30 seconds.'));
                }, 30000);
                child.once('error', (error) => {
                    clearTimeout(timeout);
                    reject(error);
                });
                child.once('exit', (code) => {
                    clearTimeout(timeout);
                    resolve(code);
                });
            });

            assert.notEqual(exitCode, 0, stdout);
            assert.match(stderr, /reprint push requires PHP 8\.1 or newer/);
            assert.equal(acceptedConnections, 0, 'The unsupported importer contacted the target before reporting its runtime requirement.');
        } finally {
            await new Promise((resolve) => server.close(resolve));
            rmSync(caseRoot, { recursive: true, force: true });
        }
    });
});
