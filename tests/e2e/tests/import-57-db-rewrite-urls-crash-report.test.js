/**
 * Test 57: Live database URL rewrite crash report.
 *
 * Five consecutive CLI processes are killed while MySQL is applying five
 * different rows. The server finishes each one-row UPDATE, but the process
 * cannot save its cursor. The final process resumes the job and must report
 * each row whose result could not be distinguished from a concurrent change.
 * The same sequence runs against InnoDB and MyISAM.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    createTempDir, cleanupTempDir, createMysqlConnection, PHP_BINARY,
} from '../lib/test-helpers.js';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(
    PROJECT_ROOT,
    'packages',
    'reprint-client',
    'bin',
    'reprint-client',
);

// Playground PHP runs inside the Node process and cannot be killed as a
// separate PHP process. The native PHP matrix covers these real crashes.
const describeWithProcessCrashes = PHP_BINARY.includes('playground-php.sh')
    ? describe.skip
    : describe;

for (const engine of ['InnoDB', 'MyISAM']) {
    describeWithProcessCrashes(
        `Import: db-rewrite-urls ${engine} crash report`,
        { timeout: 300000 },
        () => {
            const engineName = engine.toLowerCase();
            const databaseName = `e2e_db_rewrite_crash_57_${engineName}`;
            const contentTable = 'rewrite_content';
            const rowCount = 5;
            const sourceUrl = `https://${engineName}-source.example.test`;
            const targetUrl = `https://${engineName}-target.example.test`;
            const duplicateTargetUrl = `https://${engineName}-processed-twice.example.test`;
            const lockPrefix = `reprint-e2e-57-${engineName}-`;
            let tempDir;
            let runningProcess = null;

            beforeAll(async () => {
                tempDir = createTempDir(`e2e-db-rewrite-crash-${engineName}`);
                const connection = await createMysqlConnection();
                await connection.query(`DROP DATABASE IF EXISTS \`${databaseName}\``);
                await connection.query(`CREATE DATABASE \`${databaseName}\``);
                await connection.query(
                    `CREATE TABLE \`${databaseName}\`.\`${contentTable}\` (` +
                    '`id` int NOT NULL, ' +
                    '`content` text NOT NULL, ' +
                    '`secondary_content` text NOT NULL, ' +
                    '`untouched` varbinary(16) NOT NULL, ' +
                    `PRIMARY KEY (\`id\`)) ENGINE=${engine}`
                );

                for (let id = 1; id <= rowCount; id++) {
                    await connection.query(
                        `INSERT INTO \`${databaseName}\`.\`${contentTable}\` ` +
                        '(`id`, `content`, `secondary_content`, `untouched`) VALUES (?, ?, ?, ?)',
                        [
                            id,
                            originalContent(id),
                            originalSecondaryContent(id),
                            untouchedBytes(id),
                        ],
                    );
                }

                // The named lock tells the test that MySQL is inside the
                // UPDATE. MySQL then sleeps long enough for SIGKILL to arrive.
                // The server finishes the statement after the client process
                // disappears, leaving the saved cursor on the selected row.
                await connection.query(
                    `CREATE TRIGGER \`${databaseName}\`.\`pause_rewrite_update\` ` +
                    `BEFORE UPDATE ON \`${databaseName}\`.\`${contentTable}\` ` +
                    'FOR EACH ROW SET @reprint_pause = IF(' +
                    `GET_LOCK(CONCAT('${lockPrefix}', NEW.\`id\`), 0) = 1, ` +
                    `SLEEP(2) + RELEASE_LOCK(CONCAT('${lockPrefix}', NEW.\`id\`)), 0)`
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

            it('reports five interrupted rows without changing their bytes twice', async () => {
                const controlConnection = await createMysqlConnection(databaseName);

                for (let id = 1; id <= rowCount; id++) {
                    const process = startRewrite(id === 1 ? [
                        '--target-engine=mysql',
                        '--target-host=127.0.0.1',
                        '--target-user=e2e_admin',
                        '--target-pass=e2e_password',
                        `--target-db=${databaseName}`,
                        '--rewrite-url', sourceUrl, targetUrl,
                        '--rewrite-url', targetUrl, duplicateTargetUrl,
                    ] : [
                        '--target-pass=e2e_password',
                    ]);

                    await waitForNamedLock(controlConnection, `${lockPrefix}${id}`);
                    assert.equal(
                        process.child.kill('SIGKILL'),
                        true,
                        `Expected to kill the process while updating row ${id}`,
                    );
                    const crashed = await waitForCompletion(process, 30000);
                    assert.equal(crashed.exitCode, null);
                    assert.equal(crashed.signal, 'SIGKILL');

                    await waitForCommittedContent(controlConnection, id);
                }

                await controlConnection.end();

                const finalProcess = startRewrite([
                    '--target-pass=e2e_password',
                ]);
                const completed = await waitForCompletion(finalProcess, 120000);
                assert.equal(
                    completed.exitCode,
                    0,
                    `Expected resume to complete\nstdout: ${completed.stdout}\n` +
                    `stderr: ${completed.stderr}`,
                );

                const reportPath = findReportPath();
                const reportLines = readFileSync(reportPath, 'utf8')
                    .trimEnd()
                    .split('\n')
                    .map(line => JSON.parse(line));
                assert.equal(reportLines.length, rowCount + 1);

                const [header, ...rowsToVerify] = reportLines;
                assert.deepEqual(
                    header.rewrite_url,
                    [
                        { from: sourceUrl, to: targetUrl },
                        { from: targetUrl, to: duplicateTargetUrl },
                    ],
                );
                assert.equal(header.type, 'job');
                assert.equal(header.command, 'db-rewrite-urls');
                assert.equal(header.version, 1);
                assert.match(header.job_id, /^[a-f0-9]{32}$/);

                assert.equal(new Set(rowsToVerify.map(row => row.id)).size, rowCount);
                for (let id = 1; id <= rowCount; id++) {
                    const row = rowsToVerify[id - 1];
                    assert.equal(row.type, 'row_to_verify');
                    assert.equal(row.reason, 'conditional_update_changed_no_rows');
                    assert.equal(row.table, contentTable);
                    assert.deepEqual(row.primary_key, {
                        id: { type: 'integer', value: id },
                    });
                    assert.deepEqual(row.columns, {
                        content: {
                            original_sha256: sha256(originalContent(id)),
                            intended_sha256: sha256(rewrittenContent(id)),
                        },
                        secondary_content: {
                            original_sha256: sha256(originalSecondaryContent(id)),
                            intended_sha256: sha256(rewrittenSecondaryContent(id)),
                        },
                    });
                }

                assert.match(completed.stdout, /5 rows? to verify/);
                const progressRecords = completed.stdout
                    .trimEnd()
                    .split('\n')
                    .map(line => JSON.parse(line));
                const finalProgress = progressRecords.find(
                    record => record.records_to_verify === rowCount
                );
                assert.equal(finalProgress.review_file, reportPath);

                const connection = await createMysqlConnection(databaseName);
                const [rows] = await connection.query(
                    `SELECT \`id\`, \`content\`, \`secondary_content\`, \`untouched\` ` +
                    `FROM \`${contentTable}\` ORDER BY \`id\``
                );
                const [tableCheck] = await connection.query(
                    `CHECK TABLE \`${contentTable}\``
                );
                await connection.end();

                assert.equal(tableCheck.at(-1).Msg_text, 'OK');
                assert.equal(rows.length, rowCount);
                for (let id = 1; id <= rowCount; id++) {
                    const row = rows[id - 1];
                    assert.equal(row.id, id);
                    assert.equal(row.content.toString(), rewrittenContent(id));
                    assert.equal(
                        row.secondary_content.toString(),
                        rewrittenSecondaryContent(id),
                    );
                    assert.deepEqual(row.untouched, untouchedBytes(id));
                    assert.doesNotMatch(row.content.toString(), /processed-twice/);
                    assert.doesNotMatch(row.secondary_content.toString(), /processed-twice/);
                }
            });

            function originalContent(id) {
                return `${sourceUrl}/content/${id}`;
            }

            function rewrittenContent(id) {
                return `${targetUrl}/content/${id}`;
            }

            function originalSecondaryContent(id) {
                return `before-${id} ${sourceUrl}/secondary/${id} after-${id}`;
            }

            function rewrittenSecondaryContent(id) {
                return `before-${id} ${targetUrl}/secondary/${id} after-${id}`;
            }

            function untouchedBytes(id) {
                return Buffer.from([0, id, 255, 82, 69, 80, 82, 73, 78, 84]);
            }

            function statePath() {
                return join(tempDir, 'db-rewrite-urls', 'pull', 'state.json');
            }

            function findReportPath() {
                assert.equal(existsSync(statePath()), true);
                const pullDirectory = join(tempDir, 'db-rewrite-urls', 'pull');
                const reportNames = readdirSync(pullDirectory).filter(
                    name => /^db-rewrite-urls-[a-f0-9]{32}\.jsonl$/.test(name)
                );
                assert.equal(reportNames.length, 1);
                return join(pullDirectory, reportNames[0]);
            }

            function startRewrite(extraArgs) {
                const child = spawn(PHP_BINARY, [
                    IMPORTER_PATH,
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

            async function waitForNamedLock(connection, lockName) {
                const deadline = Date.now() + 60000;
                while (Date.now() < deadline) {
                    const [[row]] = await connection.query(
                        'SELECT IS_USED_LOCK(?) AS connection_id',
                        [lockName],
                    );
                    if (row.connection_id !== null) {
                        return;
                    }
                    if (
                        runningProcess?.exitCode !== null ||
                        runningProcess?.signalCode !== null
                    ) {
                        break;
                    }
                    await sleep(5);
                }
                throw new Error(`Expected MySQL to start the UPDATE guarded by ${lockName}`);
            }

            async function waitForCommittedContent(connection, id) {
                const deadline = Date.now() + 30000;
                while (Date.now() < deadline) {
                    const [[row]] = await connection.query(
                        `SELECT \`content\` FROM \`${contentTable}\` WHERE \`id\` = ?`,
                        [id],
                    );
                    if (row?.content?.toString() === rewrittenContent(id)) {
                        return;
                    }
                    await sleep(10);
                }
                throw new Error(`Expected MySQL to commit the interrupted UPDATE for row ${id}`);
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

            function sha256(value) {
                return createHash('sha256').update(value).digest('hex');
            }
        },
    );
}
