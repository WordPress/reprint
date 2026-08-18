/**
 * Test 60: Application firewall compatibility
 *
 * Runs a complete pull through a local HTTP reverse proxy which checks the
 * expected Referer, User-Agent, and Accept-Language headers. Before forwarding
 * each streaming endpoint, the proxy returns two potentially transient HTTP
 * errors. The third request reaches the real E2E site, so the pull must
 * recover without hitting its three-failure limit.
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
    const acceptedUserAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ' +
        'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
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

    it('reuses the first User-Agent accepted by the firewall', () => {
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
        const preflightRecords = requestRecords.filter(
            record => record.endpoint === 'preflight',
        );
        assert.deepEqual(
            preflightRecords.slice(0, 2).map(record => [
                record.userAgent,
                record.allowed,
            ]),
            [
                ['Reprint/1.0', false],
                [acceptedUserAgent, true],
            ],
            'Expected the firewall to reject the default and accept the browser User-Agent',
        );
        const acceptedRecords = requestRecords.filter(record => record.allowed);
        assert.ok(acceptedRecords.length > 0, 'Expected accepted Reprint requests');
        assert.ok(
            acceptedRecords.every(record => record.userAgent === acceptedUserAgent),
            `Expected accepted User-Agent ${acceptedUserAgent}, got ` +
            acceptedRecords.map(record => JSON.stringify(record.userAgent)).join(', '),
        );
        assert.ok(
            requestRecords.every(record => record.acceptLanguage === 'en-US,en;q=0.9'),
            `Expected Accept-Language en-US,en;q=0.9, got ` +
            requestRecords.map(record => JSON.stringify(record.acceptLanguage)).join(', '),
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

    it('loads the accepted User-Agent in a later importer process', () => {
        const laterOutputDirectory = createTempDir(
            'e2e-app-firewall-request-context',
        );
        const firstLaterRecord = readRequestRecords().length;

        try {
            const result = runImporter(
                importUrl,
                laterOutputDirectory,
                'files-index',
                {
                    secret: getSiteSecret(site),
                    autoResume: false,
                    timeout: 120000,
                    wallTimeout: 240000,
                },
            );
            const state = JSON.parse(readFileSync(
                join(
                    pullStateDirectory(laterOutputDirectory, importUrl),
                    'state.json',
                ),
                'utf-8',
            ));
            const requestRecords = readRequestRecords()
                .slice(firstLaterRecord)
                .filter(record => record.isReprintRequest);
            const preflightRecords = requestRecords.filter(
                record => record.endpoint === 'preflight',
            );
            const fileIndexRecord = requestRecords.find(
                record => record.endpoint === 'file_index',
            );

            assert.equal(
                result.exitCode,
                0,
                `Expected files-index to succeed\n` +
                `stderr: ${result.stderr}\nstdout: ${result.stdout}`,
            );
            assert.equal(state.user_agent, acceptedUserAgent);
            assert.deepEqual(
                preflightRecords.map(record => [
                    record.userAgent,
                    record.allowed,
                ]),
                [
                    ['Reprint/1.0', false],
                    [acceptedUserAgent, true],
                ],
            );
            assert.ok(
                fileIndexRecord,
                'Expected the later process to request file_index',
            );
            assert.equal(fileIndexRecord.userAgent, state.user_agent);
            assert.equal(fileIndexRecord.allowed, true);
            assert.equal(fileIndexRecord.action, 'proxy');
        } finally {
            cleanupTempDir(laterOutputDirectory);
        }
    });

    it('rejects a Reprint GET without the Referer', async () => {
        const response = await fetch(
            `${firewallOrigin}/?reprint-api&endpoint=preflight`,
            {
                headers: {
                    'Accept-Language': 'en-US,en;q=0.9',
                    'User-Agent': acceptedUserAgent,
                },
            },
        );

        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-app-firewall'), 'blocked');
    });

    it('rejects a Reprint GET without the User-Agent', async () => {
        const response = await fetch(
            `${firewallOrigin}/?reprint-api&endpoint=preflight`,
            {
                headers: {
                    'Accept-Language': 'en-US,en;q=0.9',
                    Referer: `${firewallOrigin}/wp-admin/upload.php`,
                    'User-Agent': '',
                },
            },
        );

        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-app-firewall'), 'blocked');
    });

    it('rejects a Reprint GET without Accept-Language', async () => {
        const response = await fetch(
            `${firewallOrigin}/?reprint-api&endpoint=preflight`,
            {
                headers: {
                    'Accept-Language': '',
                    Referer: `${firewallOrigin}/wp-admin/upload.php`,
                    'User-Agent': acceptedUserAgent,
                },
            },
        );

        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-app-firewall'), 'blocked');
    });
});
