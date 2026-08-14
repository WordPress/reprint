/**
 * A pull-db SIGTERM during db-apply must stop at the next SQL chunk boundary.
 * The wrapper must leave db-apply unfinished instead of retrying it or marking
 * the pipeline complete, and a later process must finish the replacement.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection, compareDatabases, fsRootDir,
    pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

// The PHP.wasm wrapper is a Node process. SIGTERM stops Node instead of being
// delivered to PHP, so it cannot exercise the importer's deferred signal path.
const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Import: pull-db shutdown during db-apply', { timeout: 300000 }, () => {
    const site = 'pull-db-shutdown';
    const lockedTable = 'aa_pull_db_shutdown_lock';
    const payloadTable = 'ab_pull_db_shutdown_payload';
    const targetDb = `${getDbName(site)}_import`;
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    let tempDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function targetArguments() {
        return [
            '--target-engine=mysql',
            '--target-host=127.0.0.1',
            '--target-user=e2e_admin',
            '--target-pass=e2e_password',
            `--target-db=${targetDb}`,
            '--progress=jsonl',
        ];
    }

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_dbName, connection) => {
                await connection.query(
                    `CREATE TABLE \`${lockedTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${lockedTable}\` (id, value) VALUES (1, 'locked-row')`
                );
                await connection.query(
                    `CREATE TABLE \`${payloadTable}\` (`
                    + '`id` INT NOT NULL, `value` LONGTEXT NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${payloadTable}\` (id, value) VALUES (?, ?)`,
                    [1, 'x'.repeat(192 * 1024)],
                );
            },
        });

        tempDir = createTempDir('e2e-pull-db-shutdown');
    });

    afterAll(async () => {
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
        const connection = await createMysqlConnection();
        try {
            await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        } finally {
            await connection.end();
        }
    });

    it('returns 2 without advancing the pipeline and completes in the next process', async () => {
        const adminConnection = await createMysqlConnection();
        let lockConnection;
        let pullProcess;
        let pullExit;
        let targetThreadId = null;

        try {
            await adminConnection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
            await adminConnection.query(`CREATE DATABASE \`${targetDb}\``);

            const setupConnection = await createMysqlConnection(targetDb);
            try {
                await setupConnection.query(
                    `CREATE TABLE \`${lockedTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
            } finally {
                await setupConnection.end();
            }

            lockConnection = await createMysqlConnection(targetDb);
            await lockConnection.query(`LOCK TABLES \`${lockedTable}\` WRITE`);

            const output = { stdout: '', stderr: '' };
            pullProcess = spawn(phpBinary, [
                importerPath,
                'pull-db',
                importUrl(),
                `--state-dir=${tempDir}`,
                `--fs-root=${fsRootDir(tempDir)}`,
                `--secret=${getSiteSecret(site)}`,
                ...targetArguments(),
            ], {
                env: { ...process.env },
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            pullProcess.stdout.setEncoding('utf8');
            pullProcess.stderr.setEncoding('utf8');
            pullProcess.stdout.on('data', chunk => { output.stdout += chunk; });
            pullProcess.stderr.on('data', chunk => { output.stderr += chunk; });
            pullExit = new Promise(resolve => {
                pullProcess.once('exit', (code, signal) => resolve({ code, signal }));
            });

            const statePath = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
            const lockDeadline = Date.now() + 120000;
            while (Date.now() < lockDeadline) {
                if (pullProcess.exitCode !== null || pullProcess.signalCode !== null) {
                    const result = await pullExit;
                    assert.fail(
                        `pull-db exited before db-apply blocked (${result.code}/${result.signal}):\n`
                        + output.stderr + output.stdout,
                    );
                }

                let atApplyStage = false;
                if (existsSync(statePath)) {
                    const state = JSON.parse(readFileSync(statePath, 'utf8'));
                    atApplyStage = state.pull_pipeline?.last_completed_stage === 'db-pull'
                        && state.active_resumable_command?.command_name === 'db-apply';
                }
                const [threads] = await adminConnection.query(
                    'SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST '
                    + 'WHERE DB = ? AND COMMAND = ? AND INFO LIKE ?',
                    [targetDb, 'Query', `%${lockedTable}%`],
                );
                if (atApplyStage && threads.length === 1) {
                    targetThreadId = Number(threads[0].ID);
                    break;
                }
                await sleep(25);
            }

            assert.notEqual(targetThreadId, null, 'pull-db did not block in db-apply');
            assert.equal(pullProcess.kill('SIGTERM'), true);

            // The db-apply signal policy keeps the signal blocked during the
            // active query. A wrapper using the generic policy exits here, so
            // remove its abandoned target session before releasing the lock.
            await sleep(250);
            const processExited = pullProcess.exitCode !== null
                || pullProcess.signalCode !== null;
            if (processExited) {
                try {
                    await adminConnection.query(`KILL CONNECTION ${targetThreadId}`);
                } catch (error) {
                    if (error.code !== 'ER_NO_SUCH_THREAD') {
                        throw error;
                    }
                }
            }

            await lockConnection.query('UNLOCK TABLES');
            await lockConnection.end();
            lockConnection = null;

            const stopped = await Promise.race([
                pullExit,
                sleep(60000).then(() => ({ timedOut: true })),
            ]);
            assert.equal(stopped.timedOut, undefined, 'pull-db did not stop after SIGTERM');
            assert.equal(
                stopped.code,
                2,
                `pull-db stopped as ${stopped.code}/${stopped.signal}:\n`
                + output.stderr + output.stdout,
            );

            const interruptedState = JSON.parse(readFileSync(statePath, 'utf8'));
            assert.equal(interruptedState.pull_pipeline.started_by_command, 'pull-db');
            assert.equal(interruptedState.pull_pipeline.last_completed_stage, 'db-pull');
            assert.equal(interruptedState.active_resumable_command.command_name, 'db-apply');
            assert.equal(interruptedState.active_resumable_command.completion_state, 'partial');
            assert.equal(interruptedState.apply.target_engine, 'mysql');
            assert.equal(interruptedState.apply.target_host, '127.0.0.1');
            assert.equal(interruptedState.apply.target_port, 3306);
            assert.equal(interruptedState.apply.target_user, 'e2e_admin');
            assert.equal(interruptedState.apply.target_pass, 'e2e_password');
            assert.equal(interruptedState.apply.target_db, targetDb);
            assert.ok(
                Number(interruptedState.apply?.statements_executed || 0) > 0,
                'db-apply did not finish the active SQL chunk before stopping',
            );

            const resumed = runImporter(importUrl(), tempDir, 'pull-db', {
                secret: getSiteSecret(site),
                extraArgs: targetArguments(),
                autoResume: false,
                timeout: 180000,
                wallTimeout: 240000,
            });
            assert.equal(
                resumed.exitCode,
                0,
                `resumed pull-db failed:\n${resumed.stderr}\n${resumed.stdout}`,
            );

            const completedState = JSON.parse(readFileSync(statePath, 'utf8'));
            assert.equal(completedState.pull_pipeline.last_completed_stage, 'db-apply');
            assert.equal(completedState.active_resumable_command.completion_state, 'complete');

            const comparison = await compareDatabases(getDbName(site), targetDb);
            assert.deepEqual(comparison.missingTables, []);
            assert.deepEqual(comparison.extraTables, []);
            assert.ok(
                Object.values(comparison.rowCounts).every(counts => counts.match),
                `row counts differ: ${JSON.stringify(comparison.rowCounts)}`,
            );
        } finally {
            if (
                pullProcess
                && pullProcess.exitCode === null
                && pullProcess.signalCode === null
            ) {
                pullProcess.kill('SIGKILL');
                await pullExit;
            }
            if (targetThreadId !== null) {
                try {
                    await adminConnection.query(`KILL CONNECTION ${targetThreadId}`);
                } catch {}
            }
            if (lockConnection) {
                try { await lockConnection.query('UNLOCK TABLES'); } catch {}
                await lockConnection.end();
            }
            await adminConnection.end();
        }
    });
});
