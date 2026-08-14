/**
 * Test 59: File database-pull artifact guards.
 *
 * The first cases deliberately tamper with db.sql after a causal process stop
 * to lock the refusal when the file is shorter than the saved size. Another case
 * proves that an actual unfinished managed pull cannot feed its prefix to
 * db-apply as a one-shot arbitrary dump.
 */
import { describe, it, beforeAll, beforeEach, afterEach, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import {
    existsSync,
    readFileSync,
    truncateSync,
    unlinkSync,
} from 'node:fs';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter,
    createTempDir,
    cleanupTempDir,
    getSiteUrl,
    getSiteSecret,
    getSiteDir,
    fsRootDir,
    writeTestHooks,
    removeTestHooks,
    readHookState,
    clearHookState,
    pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: file db-pull artifact guards', { timeout: 300000 }, () => {
    const site = 'file-db-pull-artifact-guards';
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    const partialPullArguments = [
        '--sql-fragments-start=1',
        '--sql-fragments-min=1',
        '--sql-fragments-max=1',
        '--progress=jsonl',
    ];
    let tempDir;
    let activeProcess;
    let activeExit;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_dbName, connection) => {
                for (let index = 1; index <= 40; index++) {
                    const table = `artifact_guard_${String(index).padStart(2, '0')}`;
                    await connection.query(
                        `CREATE TABLE \`${table}\` (`
                        + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${table}\` (id, value) VALUES (?, ?)`,
                        [index, `artifact-guard-${index}`],
                    );
                }
            },
        });
    });

    beforeEach(() => {
        tempDir = createTempDir('e2e-file-db-pull-artifact-guards');
        clearHookState(site);
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = file_exists($state_file)',
            '        ? json_decode(file_get_contents($state_file), true)',
            '        : [];',
            "    $state['sql_batches'] = ($state['sql_batches'] ?? 0) + 1;",
            "    if ($state['sql_batches'] === 61) {",
            "        $state['pause_started'] = true;",
            '        file_put_contents($state_file, json_encode($state));',
            '        usleep(3000000);',
            "        $state['pause_finished'] = true;",
            '    }',
            '    file_put_contents($state_file, json_encode($state));',
            '}',
        ].join('\n'));
    });

    afterEach(async () => {
        if (
            activeProcess
            && activeProcess.exitCode === null
            && activeProcess.signalCode === null
        ) {
            activeProcess.kill('SIGKILL');
            await activeExit;
        }
        activeProcess = null;
        activeExit = null;
        removeTestHooks(site);
        clearHookState(site);
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    async function stopFilePullAfterSavedCursor() {
        const preflight = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
            autoResume: false,
        });
        assert.equal(
            preflight.exitCode,
            0,
            `preflight failed:\n${preflight.stderr}\n${preflight.stdout}`,
        );

        const output = { stdout: '', stderr: '' };
        activeProcess = spawn(phpBinary, [
            importerPath,
            'db-pull',
            importUrl(),
            `--state-dir=${tempDir}`,
            `--fs-root=${fsRootDir(tempDir)}`,
            `--secret=${getSiteSecret(site)}`,
            ...partialPullArguments,
        ], {
            env: { ...process.env },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        activeProcess.stdout.setEncoding('utf8');
        activeProcess.stderr.setEncoding('utf8');
        activeProcess.stdout.on('data', chunk => { output.stdout += chunk; });
        activeProcess.stderr.on('data', chunk => { output.stderr += chunk; });
        activeExit = new Promise(resolve => {
            activeProcess.once('exit', (code, signal) => resolve({ code, signal }));
        });

        const statePath = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
        const sqlPath = join(tempDir, 'db.sql');
        const pauseDeadline = Date.now() + 60000;
        let interruptedState = null;
        while (Date.now() < pauseDeadline) {
            if (activeProcess.exitCode !== null || activeProcess.signalCode !== null) {
                const result = await activeExit;
                assert.fail(
                    `db-pull exited before the SQL pause (${result.code}/${result.signal}):\n`
                    + output.stderr + output.stdout,
                );
            }

            const hookState = readHookState(site);
            if (hookState?.pause_started && existsSync(statePath) && existsSync(sqlPath)) {
                const state = JSON.parse(readFileSync(statePath, 'utf8'));
                const command = state.active_resumable_command;
                if (
                    command?.current_stage === 'sql'
                    && command.remote_cursor
                    && Number(state.sql_bytes || 0) > 0
                ) {
                    interruptedState = state;
                    break;
                }
            }
            await sleep(20);
        }

        assert.ok(interruptedState, 'db-pull did not save the file size before the pause');
        assert.equal(interruptedState.sql_output, 'file');
        assert.equal(activeProcess.kill('SIGKILL'), true);
        const killed = await activeExit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');
        activeProcess = null;
        activeExit = null;

        const serverDeadline = Date.now() + 10000;
        while (!readHookState(site)?.pause_finished && Date.now() < serverDeadline) {
            await sleep(20);
        }
        assert.equal(
            readHookState(site)?.pause_finished,
            true,
            'source did not leave the pause after the client process died',
        );
        await sleep(500);

        return { interruptedState, sqlPath };
    }

    it.each(['missing', 'shorter'])(
        'rejects a deliberately tampered %s db.sql before requesting the saved cursor',
        async artifactState => {
            const { interruptedState, sqlPath } = await stopFilePullAfterSavedCursor();
            const savedBytes = Number(interruptedState.sql_bytes);
            if (artifactState === 'missing') {
                unlinkSync(sqlPath);
            } else {
                assert.ok(savedBytes > 1, 'saved SQL size is too small to shorten');
                truncateSync(sqlPath, savedBytes - 1);
            }
            const sqlBatchesBeforeResume = readHookState(site).sql_batches;

            const resumed = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                autoResume: false,
                extraArgs: partialPullArguments,
            });

            assert.equal(
                resumed.exitCode,
                1,
                `Expected ${artifactState} db.sql to fail closed:\n`
                + resumed.stderr + resumed.stdout,
            );
            const hookState = readHookState(site);
            assert.equal(
                hookState.sql_batches,
                sqlBatchesBeforeResume,
                'db-pull requested a suffix after deliberate local artifact tampering',
            );
            assert.match(`${resumed.stderr}\n${resumed.stdout}`, /db\.sql/i);
        },
    );

    it('rejects db-apply while a managed file pull contains only a prefix', async () => {
        await stopFilePullAfterSavedCursor();
        const targetPath = join(tempDir, 'must-not-be-created.sqlite');
        const sqlBatchesBeforeApply = readHookState(site).sql_batches;

        const applied = runImporter(importUrl(), tempDir, 'db-apply', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: [
                '--target-engine=sqlite',
                `--target-sqlite-path=${targetPath}`,
                '--target-db=wordpress',
                '--progress=jsonl',
            ],
        });

        assert.equal(
            applied.exitCode,
            1,
            `Expected db-apply to reject an unfinished managed dump:\n`
            + applied.stderr + applied.stdout,
        );
        assert.equal(
            existsSync(targetPath),
            false,
            'db-apply opened the target before rejecting the unfinished dump',
        );
        assert.equal(readHookState(site).sql_batches, sqlBatchesBeforeApply);
        assert.match(`${applied.stderr}\n${applied.stdout}`, /still being downloaded/i);
    });

    it('rejects an output-mode change and preserves the file resume', async () => {
        const { sqlPath } = await stopFilePullAfterSavedCursor();

        const changedMode = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: ['--sql-output=stdout'],
        });
        assert.equal(
            changedMode.exitCode,
            1,
            `Expected output-mode change to fail:\n${changedMode.stderr}${changedMode.stdout}`,
        );
        assert.match(`${changedMode.stderr}\n${changedMode.stdout}`, /Cannot change --sql-output/);

        const resumed = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: partialPullArguments,
        });
        assert.equal(
            resumed.exitCode,
            0,
            `file db-pull did not resume after rejected mode change:\n`
            + resumed.stderr + resumed.stdout,
        );
        const sql = readFileSync(sqlPath, 'utf8');
        assert.match(sql, /artifact_guard_01/);
        assert.match(sql, /artifact_guard_40/);
    });
});
