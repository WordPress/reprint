/**
 * Test 60: Application firewall compatibility
 *
 * Runs a complete pull through a local HTTP reverse proxy which rejects
 * multipart file uploads unless they have a same-origin WordPress admin
 * Referer. The proxy streams every accepted request to the real E2E site.
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

    it('sends a same-origin WordPress admin Referer with multipart file_fetch requests', () => {
        const requestRecords = readFileSync(requestLogPath, 'utf-8')
            .trim()
            .split('\n')
            .filter(Boolean)
            .map(line => JSON.parse(line));
        const multipartFileFetchRequests = requestRecords.filter(
            record => record.isMultipartFileFetch,
        );
        assert.ok(
            multipartFileFetchRequests.length > 0,
            `Expected pull to make a multipart file_fetch request\n` +
            `stderr: ${pullResult.stderr}\nstdout: ${pullResult.stdout}`,
        );
        assert.ok(
            multipartFileFetchRequests.every(
                record => record.referer === `${firewallOrigin}/wp-admin/upload.php`,
            ),
            `Expected Referer ${firewallOrigin}/wp-admin/upload.php, got ` +
            multipartFileFetchRequests.map(record => JSON.stringify(record.referer)).join(', '),
        );
    });

    it('completes pull through the application firewall', () => {
        assert.equal(
            pullResult.exitCode,
            0,
            `Expected pull to succeed\nstderr: ${pullResult.stderr}\nstdout: ${pullResult.stdout}`,
        );

        const stateFile = join(pullStateDirectory(outputDirectory, importUrl), 'state.json');
        assert.ok(existsSync(stateFile), 'Expected pull/state.json to exist');
        assertPullPipelineComplete(JSON.parse(readFileSync(stateFile, 'utf-8')));
        assert.ok(
            existsSync(join(fsRootDir(outputDirectory), getSiteDir(site), 'test-data', 'hello.txt')),
            'Expected pull to write a source file through the application firewall',
        );
    });

    it('rejects a multipart file upload without the Referer', async () => {
        const form = new FormData();
        form.append(
            'file_list',
            new Blob(['[]'], { type: 'application/json' }),
            'file-list.json',
        );

        const response = await fetch(
            `${firewallOrigin}/?reprint-api&endpoint=file_fetch`,
            {
                method: 'POST',
                body: form,
            },
        );

        assert.equal(response.status, 403);
        assert.equal(response.headers.get('x-app-firewall'), 'blocked');
    });
});
