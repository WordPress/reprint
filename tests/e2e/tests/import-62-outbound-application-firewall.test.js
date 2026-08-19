/**
 * Test 62: Outbound application firewall compatibility
 *
 * Runs a complete pull through a local reverse proxy which models two reported
 * CWAF response matches and the four-point blocking threshold. Clear PHP and
 * MySQL markers become HTTP 403 responses. The same values survive a full pull
 * because the relevant export bodies are compressed inside PHP before the
 * proxy sees them. The fixture is not a complete CWAF engine.
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

describe('Import: Outbound application firewall compatibility', { timeout: 240000 }, () => {
    const site = 'outbound-application-firewall';
    const importDb = 'e2e_outbound_application_firewall_62';
    const phpMarker = '<?php $stream = fopen("php://memory", "r");';
    const sqlMarker = 'You have an error in your SQL syntax;';
    let firewallProcess;
    let firewallOrigin;
    let importUrl;
    let outputDirectory;
    let pullResult;
    let responseLogPath;

    beforeAll(async () => {
        await ensureSite(site, {
            customDb: async (_dbName, connection) => {
                await connection.query(
                    'CREATE TABLE wp_outbound_firewall_test (message TEXT NOT NULL)',
                );
                await connection.execute(
                    'INSERT INTO wp_outbound_firewall_test (message) VALUES (?)',
                    [sqlMarker],
                );
            },
            afterCreate: async (siteDirectory) => {
                writeFileSync(
                    join(siteDirectory, 'test-data', 'outbound-firewall.php'),
                    phpMarker,
                );
            },
        });

        outputDirectory = createTempDir('e2e-outbound-app-firewall');
        responseLogPath = join(
            tmpdir(),
            `reprint-outbound-app-firewall-${process.pid}-${Date.now()}.jsonl`,
        );
        writeFileSync(responseLogPath, '');

        const firewallFixturePath = fileURLToPath(
            new URL(
                '../lib/outbound-application-firewall-fixture.js',
                import.meta.url,
            ),
        );
        firewallProcess = fork(
            firewallFixturePath,
            [getSiteUrl(site), responseLogPath],
            { stdio: ['ignore', 'pipe', 'pipe', 'ipc'] },
        );
        const [readyMessage] = await once(firewallProcess, 'message');
        firewallOrigin = `http://127.0.0.1:${readyMessage.port}`;
        importUrl =
            `${firewallOrigin}/?reprint-api&directory=` +
            encodeURIComponent(getSiteDir(site));

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

        if (responseLogPath && existsSync(responseLogPath)) {
            unlinkSync(responseLogPath);
        }

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.end();
    });

    it('blocks each clear response pattern at the published CWAF threshold', async () => {
        for (const marker of ['php', 'mysql']) {
            const response = await fetch(
                `${firewallOrigin}/__outbound-firewall-clear-${marker}-response`,
            );

            assert.equal(response.status, 403, marker);
            assert.equal(
                response.headers.get('x-app-firewall'),
                'outbound-response',
                marker,
            );
        }
    });

    it('keeps PHP and SQL patterns out of emitted export response bytes', () => {
        const responseRecords = readFileSync(responseLogPath, 'utf-8')
            .trim()
            .split('\n')
            .filter(Boolean)
            .map(line => JSON.parse(line));
        const exportRecords = responseRecords.filter(
            record => record.endpoint === 'file_fetch' || record.endpoint === 'sql_chunk',
        );
        const fileFetchRecords = exportRecords.filter(
            record => record.endpoint === 'file_fetch',
        );
        const sqlChunkRecords = exportRecords.filter(
            record => record.endpoint === 'sql_chunk',
        );

        assert.ok(
            fileFetchRecords.length > 0,
            'Expected the firewall to inspect a file_fetch response',
        );
        assert.ok(
            sqlChunkRecords.length > 0,
            'Expected the firewall to inspect a sql_chunk response',
        );
        assert.ok(
            fileFetchRecords.some(record => record.contentEncoding === 'gzip'),
            `Expected at least one gzip file_fetch response, got ` +
            JSON.stringify(fileFetchRecords),
        );
        assert.ok(
            sqlChunkRecords.every(record => record.contentEncoding === 'gzip'),
            `Expected gzip sql_chunk responses, got ` +
            JSON.stringify(sqlChunkRecords),
        );
        assert.ok(
            exportRecords.every(
                record =>
                    record.phpSourceMatch === false &&
                    record.mysqlLeakMatch === false &&
                    record.blocked === false,
            ),
            `Expected emitted bytes to clear outbound inspection: ` +
            JSON.stringify(exportRecords),
        );
    });

    it('completes the pull with the inspected source and database text intact', async () => {
        assert.equal(
            pullResult.exitCode,
            0,
            `Expected pull to succeed\nstderr: ${pullResult.stderr}\n` +
            `stdout: ${pullResult.stdout}`,
        );

        const stateFile = join(
            pullStateDirectory(outputDirectory, importUrl),
            'state.json',
        );
        assert.ok(existsSync(stateFile), 'Expected pull/state.json to exist');
        assertPullPipelineComplete(JSON.parse(readFileSync(stateFile, 'utf-8')));
        assert.equal(
            readFileSync(
                join(
                    fsRootDir(outputDirectory),
                    getSiteDir(site),
                    'test-data',
                    'outbound-firewall.php',
                ),
                'utf-8',
            ),
            phpMarker,
        );

        const connection = await createMysqlConnection(importDb);
        const [[row]] = await connection.query(
            'SELECT message FROM wp_outbound_firewall_test',
        );
        await connection.end();
        assert.equal(row.message, sqlMarker);
    });
});
