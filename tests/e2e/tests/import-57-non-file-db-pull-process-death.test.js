/**
 * A db-pull writing to stdout has no local SQL file to resume. Stop its
 * process after SQL reaches stdout, then verify that a later file-mode run
 * refuses to write only the unconsumed suffix of the dump.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, fsRootDir,
    writeTestHooks, removeTestHooks,
    readHookState, clearHookState, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: stdout db-pull process death', { timeout: 180000 }, () => {
    const site = 'stdout-db-pull-process-death';
    const earlyTable = 'aa_stdout_process_death_early';
    const lateTable = 'zz_stdout_process_death_late';
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    let tempDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_dbName, connection) => {
                const tables = [
                    earlyTable,
                    ...Array.from(
                        { length: 40 },
                        (_, index) => `mm_stdout_process_death_${String(index).padStart(2, '0')}`,
                    ),
                    lateTable,
                ];
                for (const [index, table] of tables.entries()) {
                    await connection.query(
                        `CREATE TABLE \`${table}\` (`
                        + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${table}\` (id, value) VALUES (?, ?)`,
                        [index + 1, `stdout-process-death-${index + 1}`],
                    );
                }
            },
        });
        tempDir = createTempDir('e2e-stdout-db-pull-process-death');
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

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
    });

    it('refuses a new file pull after a stdout process stops', async () => {
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
        const firstProcess = spawn(phpBinary, [
            importerPath,
            'db-pull',
            importUrl(),
            `--state-dir=${tempDir}`,
            `--fs-root=${fsRootDir(tempDir)}`,
            `--secret=${getSiteSecret(site)}`,
            '--sql-output=stdout',
            '--sql-fragments-start=1',
            '--sql-fragments-min=1',
            '--sql-fragments-max=1',
            '--progress=jsonl',
        ], {
            env: { ...process.env },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        firstProcess.stdout.setEncoding('utf8');
        firstProcess.stderr.setEncoding('utf8');
        firstProcess.stdout.on('data', chunk => { output.stdout += chunk; });
        firstProcess.stderr.on('data', chunk => { output.stderr += chunk; });
        const firstExit = new Promise(resolve => {
            firstProcess.once('exit', (code, signal) => resolve({ code, signal }));
        });

        const statePath = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
        const pauseDeadline = Date.now() + 60000;
        let interruptedState = null;
        while (Date.now() < pauseDeadline) {
            if (firstProcess.exitCode !== null || firstProcess.signalCode !== null) {
                const result = await firstExit;
                assert.fail(
                    `db-pull exited before the SQL pause (${result.code}/${result.signal}):\n`
                    + output.stderr + output.stdout,
                );
            }

            const hookState = readHookState(site);
            if (hookState?.pause_started && existsSync(statePath)) {
                const state = JSON.parse(readFileSync(statePath, 'utf8'));
                const command = state.active_resumable_command;
                const databasePullOutputStatus = join(
                    pullStateDirectory(tempDir, importUrl()),
                    'database-pull-output.json',
                );
                if (command?.current_stage === 'sql' && existsSync(databasePullOutputStatus)) {
                    interruptedState = state;
                    break;
                }
            }
            await sleep(20);
        }

        assert.ok(interruptedState, 'db-pull did not record direct output before the pause');
        assert.equal(interruptedState.sql_output, 'stdout');
        assert.equal(existsSync(join(tempDir, 'db.sql')), false);

        assert.equal(firstProcess.kill('SIGKILL'), true);
        const killed = await firstExit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');

        const serverDeadline = Date.now() + 10000;
        while (!readHookState(site)?.pause_finished && Date.now() < serverDeadline) {
            await sleep(20);
        }
        assert.equal(
            readHookState(site)?.pause_finished,
            true,
            'source did not leave the pause after the client process stopped',
        );

        const resumed = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: [
                '--sql-output=file',
                '--sql-fragments-start=1',
                '--sql-fragments-min=1',
                '--sql-fragments-max=1',
            ],
        });

        const sqlFile = join(tempDir, 'db.sql');
        if (resumed.exitCode === 0) {
            const sql = existsSync(sqlFile) ? readFileSync(sqlFile, 'utf8') : '';
            assert.fail(
                'db-pull silently continued the stdout cursor into db.sql; '
                + `early_table=${sql.includes(earlyTable)}, late_table=${sql.includes(lateTable)}`,
            );
        }
        assert.equal(
            resumed.exitCode,
            1,
            `Expected file-mode continuation to fail closed:\n${resumed.stderr}\n${resumed.stdout}`,
        );
        assert.equal(existsSync(sqlFile), false, 'db-pull created a suffix-only db.sql');
        const resumeError = `${resumed.stderr}\n${resumed.stdout}`;
        assert.match(resumeError, /db-pull stopped while writing SQL to stdout\./);
        assert.match(
            resumeError,
            /Reprint does not know how much SQL the receiving program or file got\./,
        );
        assert.match(
            resumeError,
            /Reset that program or file, then run db-pull --abort\./,
        );
        assert.match(
            resumeError,
            /Start again with --sql-output=file and apply db\.sql with db-apply\./,
        );

        const aborted = runImporter(importUrl(), tempDir, 'pull-db', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: [
                '--abort',
                '--target-engine=sqlite',
                `--target-sqlite-path=${join(tempDir, 'unused.sqlite')}`,
                '--target-db=wordpress',
            ],
        });
        assert.equal(
            aborted.exitCode,
            0,
            `pull-db --abort failed:\n${aborted.stderr}\n${aborted.stdout}`,
        );

        const restarted = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            extraArgs: [
                '--sql-output=file',
                '--sql-fragments-start=1',
                '--sql-fragments-min=1',
                '--sql-fragments-max=1',
            ],
        });
        assert.equal(
            restarted.exitCode,
            0,
            `fresh file db-pull failed after wrapper abort:\n`
            + restarted.stderr + restarted.stdout,
        );
        const restartedSql = readFileSync(sqlFile, 'utf8');
        assert.match(restartedSql, new RegExp(earlyTable));
        assert.match(restartedSql, new RegExp(lateTable));
    });
});
