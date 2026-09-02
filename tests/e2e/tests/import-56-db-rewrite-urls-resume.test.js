/**
 * Test 56: Live database URL rewrite interruption and resume.
 *
 * A real db-rewrite-urls CLI process receives SIGTERM after it has saved a
 * record boundary. A new process resumes the same lifecycle. Update triggers
 * and the final processed-record count confirm that no content row is handled
 * twice across the two processes.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    createTempDir, cleanupTempDir, createMysqlConnection, PHP_BINARY,
} from '../lib/test-helpers.js';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const CLIENT_PATH = process.env.CLIENT_PATH || join(
    PROJECT_ROOT,
    'packages',
    'reprint-client',
    'bin',
    'reprint-client',
);

// Playground PHP runs inside the Node process and does not expose PHP's pcntl
// signal handler. The native PHP matrix covers the real SIGTERM lifecycle.
const describeWithSignals = PHP_BINARY.includes('playground-php.sh')
    ? describe.skip
    : describe;
describeWithSignals(
    'Import: live database URL rewrite interruption and resume',
    { timeout: 300000 },
    () => {
        const databaseName = 'e2e_db_rewrite_urls_resume_56';
        const contentTable = 'a_rewrite_content';
        const updateTable = 'z_rewrite_updates';
        const rowCount = 1000;
        const sourceUrl = 'https://resume-source.example.test';
        const targetUrl = 'https://resume-target.example.test';
        // The second mapping makes repeated processing visible: the first
        // decision produces targetUrl, while a second would produce this URL.
        const duplicateTargetUrl = 'https://processed-twice.example.test';
        let tempDir;
        let runningProcess = null;

        beforeAll(async () => {
            tempDir = createTempDir('e2e-db-rewrite-urls-resume');
            const connection = await createMysqlConnection();
            await connection.query(`DROP DATABASE IF EXISTS \`${databaseName}\``);
            await connection.query(`CREATE DATABASE \`${databaseName}\``);
            await connection.query(
                `CREATE TABLE \`${databaseName}\`.\`${contentTable}\` (` +
                '`id` int NOT NULL, ' +
                '`content` text NOT NULL, ' +
                'PRIMARY KEY (`id`))'
            );
            await connection.query(
                `CREATE TABLE \`${databaseName}\`.\`${updateTable}\` (` +
                '`id` int NOT NULL, ' +
                '`updates` int NOT NULL DEFAULT 0, ' +
                'PRIMARY KEY (`id`))'
            );

            const contentValues = [];
            const updateValues = [];
            const contentParams = [];
            const updateParams = [];
            for (let id = 1; id <= rowCount; id++) {
                contentValues.push('(?, ?)');
                contentParams.push(id, `${sourceUrl}/content/${id}`);
                updateValues.push('(?, 0)');
                updateParams.push(id);
            }
            await connection.query(
                `INSERT INTO \`${databaseName}\`.\`${contentTable}\` (` +
                '`id`, `content`) VALUES ' + contentValues.join(', '),
                contentParams,
            );
            await connection.query(
                `INSERT INTO \`${databaseName}\`.\`${updateTable}\` (` +
                '`id`, `updates`) VALUES ' + updateValues.join(', '),
                updateParams,
            );
            await connection.query(
                `CREATE TRIGGER \`${databaseName}\`.\`count_rewrite_updates\` ` +
                `AFTER UPDATE ON \`${databaseName}\`.\`${contentTable}\` ` +
                `FOR EACH ROW UPDATE \`${databaseName}\`.\`${updateTable}\` ` +
                'SET `updates` = `updates` + 1 WHERE `id` = NEW.`id`'
            );
            await connection.end();
        });

        afterAll(async () => {
            if (runningProcess && runningProcess.exitCode === null) {
                const child = runningProcess;
                child.kill('SIGKILL');
                await new Promise(resolve => child.once('close', resolve));
            }
            cleanupTempDir(tempDir);
            const connection = await createMysqlConnection();
            await connection.query(`DROP DATABASE IF EXISTS \`${databaseName}\``);
            await connection.end();
        });

        it('resumes after SIGTERM without processing or updating content twice', async () => {
            const firstProcess = startRewrite([
                '--target-engine=mysql',
                '--target-host=127.0.0.1',
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${databaseName}`,
                '--rewrite-url', sourceUrl, targetUrl,
                '--rewrite-url', targetUrl, duplicateTargetUrl,
            ]);

            const progressDeadline = Date.now() + 120000;
            let progressBeforeStop = null;
            while (Date.now() < progressDeadline) {
                const state = readState();
                const rewriteState = state?.database_url_rewrite;
                if (
                    rewriteState?.current_table === contentTable &&
                    rewriteState.records_changed > 0 &&
                    rewriteState.records_changed < rowCount
                ) {
                    progressBeforeStop = rewriteState.records_processed;
                    assert.equal(
                        firstProcess.child.kill('SIGTERM'),
                        true,
                        'Expected to deliver SIGTERM to db-rewrite-urls',
                    );
                    break;
                }
                if (
                    firstProcess.child.exitCode !== null ||
                    firstProcess.child.signalCode !== null
                ) {
                    break;
                }
                await sleep(10);
            }

            assert.ok(
                progressBeforeStop !== null,
                'Expected the first process to save partial content progress before it completed',
            );
            const stopped = await waitForCompletion(firstProcess, 30000);
            assert.equal(
                stopped.exitCode,
                2,
                `Expected SIGTERM to stop at a durable boundary with exit 2\n` +
                `stdout: ${stopped.stdout}\nstderr: ${stopped.stderr}`,
            );
            assert.equal(stopped.signal, null, 'Expected the PHP signal handler to finish the step');

            const partialState = readState();
            assert.equal(
                partialState.active_resumable_command.completion_state,
                'partial',
                'Expected the stopped lifecycle to retain partial progress',
            );
            assert.ok(
                partialState.database_url_rewrite.records_processed >= progressBeforeStop,
                'Expected the stopped process to retain its last durable record boundary',
            );
            assert.ok(
                partialState.database_url_rewrite.records_changed < rowCount,
                'Expected interruption before the content table completed',
            );

            const resumedProcess = startRewrite([
                // Target identity and URL mapping come from the partial state;
                // only the password must be supplied again.
                '--target-pass=e2e_password',
            ]);
            const resumed = await waitForCompletion(resumedProcess, 120000);
            assert.equal(
                resumed.exitCode,
                0,
                `Expected resume to complete\nstdout: ${resumed.stdout}\nstderr: ${resumed.stderr}`,
            );

            const connection = await createMysqlConnection(databaseName);
            const [[contentCounts]] = await connection.query(
                `SELECT COUNT(*) AS total, ` +
                `SUM(\`content\` = CONCAT(?, '/content/', \`id\`)) AS rewritten_once, ` +
                `SUM(\`content\` = CONCAT(?, '/content/', \`id\`)) AS rewritten_twice ` +
                `FROM \`${contentTable}\``,
                [targetUrl, duplicateTargetUrl],
            );
            const [[updateCounts]] = await connection.query(
                `SELECT SUM(\`updates\`) AS total, ` +
                `MIN(\`updates\`) AS minimum, MAX(\`updates\`) AS maximum ` +
                `FROM \`${updateTable}\``
            );
            await connection.end();

            assert.equal(Number(contentCounts.total), rowCount);
            assert.equal(Number(contentCounts.rewritten_once), rowCount);
            assert.equal(Number(contentCounts.rewritten_twice), 0);
            assert.deepEqual(
                {
                    total: Number(updateCounts.total),
                    minimum: Number(updateCounts.minimum),
                    maximum: Number(updateCounts.maximum),
                },
                { total: rowCount, minimum: 1, maximum: 1 },
                'Expected every changed content row to receive exactly one UPDATE',
            );

            const completeState = readState();
            assert.equal(
                completeState.active_resumable_command.completion_state,
                'complete',
            );
            assert.equal(
                completeState.database_url_rewrite.records_processed,
                rowCount * 2,
                'Expected one record decision for each row in the two tables',
            );
            assert.equal(
                completeState.database_url_rewrite.records_changed,
                rowCount,
                'Expected each URL-bearing content row to change exactly once',
            );
        });

        function statePath() {
            return join(tempDir, 'db-rewrite-urls', 'pull', 'state.json');
        }

        function readState() {
            if (!existsSync(statePath())) {
                return null;
            }
            try {
                return JSON.parse(readFileSync(statePath(), 'utf8'));
            } catch {
                return null;
            }
        }

        function startRewrite(extraArgs) {
            const child = spawn(PHP_BINARY, [
                CLIENT_PATH,
                'db-rewrite-urls',
                `--state-dir=${tempDir}`,
                '--progress=jsonl',
                ...extraArgs,
            ], {
                env: { ...process.env },
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            runningProcess = child;

            let stdout = '';
            let stderr = '';
            child.stdout.on('data', chunk => { stdout += chunk.toString(); });
            child.stderr.on('data', chunk => { stderr += chunk.toString(); });

            const completion = new Promise((resolve, reject) => {
                child.once('error', reject);
                child.once('close', (exitCode, signal) => {
                    if (runningProcess === child) {
                        runningProcess = null;
                    }
                    resolve({ exitCode, signal, stdout, stderr });
                });
            });

            return { child, completion };
        }

        async function waitForCompletion(process, timeoutMilliseconds) {
            return Promise.race([
                process.completion,
                sleep(timeoutMilliseconds).then(() => {
                    process.child.kill('SIGKILL');
                    throw new Error(
                        `db-rewrite-urls did not exit within ${timeoutMilliseconds}ms`
                    );
                }),
            ]);
        }
    },
);
