/**
 * Test 60: Application firewall compatibility
 *
 * Runs a complete pull through a local HTTP reverse proxy which rejects
 * Reprint requests unless they have a same-origin WordPress admin Referer.
 * After preflight reports base64 path support, the proxy also rejects clear
 * path query values. Before forwarding each streaming endpoint, it returns two
 * potentially transient HTTP errors. The third request reaches the real E2E
 * site, so the pull must recover without hitting its three-failure limit.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { fork } from 'node:child_process';
import { once } from 'node:events';
import {
    existsSync,
    readFileSync,
    unlinkSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    assertPullPipelineComplete,
    cleanupTempDir,
    createMysqlConnection,
    createTempDir,
    fsRootDir,
    getSiteDir,
    getSiteSecret,
    getSiteUrl,
    pullStateDirectory,
    readAuditLog,
    runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Application firewall compatibility', { timeout: 240000 }, () => {
    const site = 'basic';
    const importDb = 'e2e_app_firewall_60';
    let firewallProcess;
    let firewallOrigin;
    let importUrl;
    let outputDirectory;
    let pullResult;
    let requestLogPath;

    beforeAll(async () => {
        await ensureSite(site);

        outputDirectory = createTempDir('e2e-app-firewall');
        requestLogPath = join(
            tmpdir(),
            `reprint-app-firewall-${process.pid}-${Date.now()}.jsonl`,
        );
        writeFileSync(requestLogPath, '');

        const firewallFixturePath = fileURLToPath(
            new URL('../lib/app-firewall-fixture.js', import.meta.url),
        );
        firewallProcess = fork(
            firewallFixturePath,
            [getSiteUrl(site), requestLogPath],
            { stdio: ['ignore', 'pipe', 'pipe', 'ipc'] },
        );
        const [readyMessage] = await once(firewallProcess, 'message');
        firewallOrigin = `http://127.0.0.1:${readyMessage.port}`;
        importUrl = `${firewallOrigin}/?reprint-api&directory=${encodeURIComponent(getSiteDir(site))}`;

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.query(`CREATE DATABASE \`${importDb}\``);
        await connection.end();

        pullResult = runImporter(importUrl, outputDirectory, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            timeout: 120000,
            wallTimeout: 240000,
            extraArgs: [
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${importDb}`,
                '--new-site-url=http://localhost:9999',
                '--runtime=none',
            ],
        });
    }, 360000);

    function readRequestRecords() {
        return readFileSync(requestLogPath, 'utf-8')
            .trim()
            .split('\n')
            .filter(Boolean)
            .map(line => JSON.parse(line));
    }

    afterAll(async () => {
        cleanupTempDir(outputDirectory);

        if (firewallProcess && firewallProcess.exitCode === null) {
            firewallProcess.kill('SIGTERM');
            await once(firewallProcess, 'exit');
        }

        if (requestLogPath && existsSync(requestLogPath)) {
            unlinkSync(requestLogPath);
        }

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.end();
    });

    it('sends a same-origin WordPress admin Referer with every Reprint request', () => {
        const requestRecords = readRequestRecords()
            .filter(record => record.isReprintRequest);
        assert.ok(
            requestRecords.length > 0,
            `Expected pull to make Reprint requests\n` +
            `stderr: ${pullResult.stderr}\nstdout: ${pullResult.stdout}`,
        );
        assert.ok(
            requestRecords.every(
                record => record.referer === `${firewallOrigin}/wp-admin/upload.php`,
            ),
            `Expected Referer ${firewallOrigin}/wp-admin/upload.php, got ` +
            requestRecords.map(record => JSON.stringify(record.referer)).join(', '),
        );
        assert.ok(requestRecords.some(record => record.method === 'GET'));
        assert.ok(requestRecords.some(record => record.method === 'POST'));
        assert.ok(
            requestRecords.some(
                record =>
                    record.method === 'POST' &&
                    record.endpoint === 'file_fetch' &&
                    record.contentType.startsWith('multipart/form-data;'),
            ),
        );
        const endpoints = new Set(requestRecords.map(record => record.endpoint));
        for (const endpoint of [
            'preflight',
            'file_index',
            'file_fetch',
            'db_index',
            'sql_chunk',
        ]) {
            assert.ok(endpoints.has(endpoint), `Expected pull to request ${endpoint}`);
        }
    });

    it('base64-encodes filesystem path query values', () => {
        const encodedPaths = readRequestRecords()
            .filter(record => record.isReprintRequest && record.endpoint !== 'preflight')
            .flatMap(record => {
                const url = new URL(record.path, firewallOrigin);
                return ['directory', 'list_dir', 'pulled_before']
                    .flatMap(parameter => url.searchParams.getAll(parameter));
            });

        assert.ok(encodedPaths.length > 0, 'Expected path query values');
        for (const encodedPath of encodedPaths) {
            const decodedPath = Buffer.from(encodedPath, 'base64');
            assert.equal(decodedPath.toString('base64'), encodedPath);
            assert.ok(decodedPath.toString().startsWith('/'));
        }
    });

    it('retries two potentially transient HTTP errors at every pull streaming endpoint', () => {
        const expectedStatusesByEndpoint = new Map([
            ['file_index', [408, 418]],
            ['file_fetch', [425, 429]],
            ['db_index', [500, 502]],
            ['sql_chunk', [503, 504]],
        ]);
        const requestRecords = readRequestRecords();
        const auditLog = readAuditLog(outputDirectory);

        for (const [endpoint, expectedStatuses] of expectedStatusesByEndpoint) {
            const endpointRecords = requestRecords.filter(
                record => record.endpoint === endpoint,
            );
            assert.deepEqual(
                endpointRecords.slice(0, 3).map(record => record.action),
                ['http-error', 'http-error', 'proxy'],
                `Expected two potentially transient HTTP errors and then a ` +
                `proxied ${endpoint} request`,
            );
            assert.deepEqual(
                endpointRecords
                    .filter(record => record.action === 'http-error')
                    .map(record => record.injectedStatus),
                expectedStatuses,
                `Expected the planned HTTP errors for ${endpoint}`,
            );

            const retryLines = auditLog
                .split('\n')
                .filter(line => line.includes(
                    `TEMPORARY REQUEST FAILURE | ${endpoint} |`,
                ));
            for (let index = 0; index < expectedStatuses.length; index++) {
                const expectedStatus = expectedStatuses[index];
                const retryLine = retryLines.find(
                    line => line.includes(`HTTP ${expectedStatus}`),
                );
                assert.ok(
                    retryLine,
                    `Expected ${endpoint} to record HTTP ${expectedStatus}`,
                );
                assert.ok(
                    retryLine.includes(
                        `consecutive_interrupted_responses=${index + 1}/3`,
                    ),
                    `Expected ${endpoint} HTTP ${expectedStatus} to record ` +
                    `failure ${index + 1} of 3`,
                );
                assert.ok(
                    retryLine.includes('cursor_moved=no'),
                    `Expected ${endpoint} HTTP ${expectedStatus} to make no cursor progress`,
                );
            }
        }
    });

    it('completes pull through the application firewall', () => {
        assert.equal(
            pullResult.exitCode,
            0,
            `Expected pull to succeed\nstderr: ${pullResult.stderr}\nstdout: ${pullResult.stdout}`,
        );

        const stateFile = join(pullStateDirectory(outputDirectory, importUrl), 'state.json');
        assert.ok(existsSync(stateFile), 'Expected pull/state.json to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assertPullPipelineComplete(state);
        assert.equal(
            state.consecutive_interrupted_responses,
            0,
            'Expected a successful response to clear the potentially transient failure count',
        );
        assert.ok(
            existsSync(join(fsRootDir(outputDirectory), getSiteDir(site), 'test-data', 'hello.txt')),
            'Expected pull to write a source file through the application firewall',
        );
    });

    it('rejects a Reprint GET without the Referer', async () => {
        const response = await fetch(
            `${firewallOrigin}/?reprint-api&endpoint=preflight`,
        );

        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-app-firewall'), 'blocked');
    });

    it('rejects a clear absolute path after preflight', async () => {
        const response = await fetch(
            `${firewallOrigin}/?reprint-api&endpoint=file_index&directory=/srv/site`,
            { headers: { Referer: `${firewallOrigin}/wp-admin/upload.php` } },
        );

        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-app-firewall'), 'blocked');
    });
});
