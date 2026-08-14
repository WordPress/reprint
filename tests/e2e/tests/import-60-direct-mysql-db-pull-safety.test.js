/**
 * Direct MySQL output saves source progress separately from MySQL changes. If
 * the process stops, a later process must refuse to send more SQL even when
 * another command has replaced the normal pull checkpoint.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection, fsRootDir, pullStateDirectory,
    writeTestHooks, removeTestHooks, readHookState, clearHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: direct MySQL db-pull safety', { timeout: 300000 }, () => {
    const site = 'direct-mysql-db-pull-safety';
    const guardTable = 'aa_direct_mysql_guard';
    const laterErrorTable = 'zz_direct_mysql_later_error';
    const targetDatabases = [
        `${getDbName(site)}_guard_target`,
        `${getDbName(site)}_later_error_target`,
    ];
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const importerSourcePath = join(projectRoot, 'packages', 'reprint-client', 'src', 'import.php');
    const phpBinary = process.env.PHP_BINARY || 'php';

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function mysqlOutputArguments(targetDatabase) {
        return [
            '--sql-output=mysql',
            `--mysql-database=${targetDatabase}`,
            '--mysql-host=127.0.0.1',
            '--mysql-user=e2e_admin',
            '--mysql-password=e2e_password',
            '--sql-fragments-start=1',
            '--sql-fragments-min=1',
            '--sql-fragments-max=1',
            '--progress=jsonl',
        ];
    }

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_databaseName, connection) => {
                await connection.query(
                    `CREATE TABLE \`${guardTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${guardTable}\` (id, value) VALUES (1, 'source-value')`
                );

                for (let index = 0; index < 40; index++) {
                    const table = `mm_direct_mysql_guard_${String(index).padStart(2, '0')}`;
                    await connection.query(
                        `CREATE TABLE \`${table}\` (`
                        + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${table}\` (id, value) VALUES (?, ?)`,
                        [index + 1, `checkpoint-${index + 1}`],
                    );
                }

                await connection.query(
                    `CREATE TABLE \`${laterErrorTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${laterErrorTable}\` (id, value) VALUES (1, 'source-value')`
                );
            },
        });
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        const connection = await createMysqlConnection();
        try {
            for (const targetDatabase of targetDatabases) {
                await connection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
            }
        } finally {
            await connection.end();
        }
    });

    it('rejects new target SQL after another command replaces the stopped pull checkpoint', async () => {
        const tempDir = createTempDir('e2e-direct-mysql-db-pull-guard');
        const targetDatabase = targetDatabases[0];
        const adminConnection = await createMysqlConnection();
        let firstProcess;
        let firstExit;

        try {
            await adminConnection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
            await adminConnection.query(`CREATE DATABASE \`${targetDatabase}\``);

            const preflight = runImporter(importUrl(), tempDir, 'preflight', {
                secret: getSiteSecret(site),
                autoResume: false,
            });
            assert.equal(
                preflight.exitCode,
                0,
                `preflight failed:\n${preflight.stderr}\n${preflight.stdout}`,
            );

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

            const output = { stdout: '', stderr: '' };
            firstProcess = spawn(phpBinary, [
                importerPath,
                'db-pull',
                importUrl(),
                `--state-dir=${tempDir}`,
                `--fs-root=${fsRootDir(tempDir)}`,
                `--secret=${getSiteSecret(site)}`,
                ...mysqlOutputArguments(targetDatabase),
            ], {
                env: { ...process.env },
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            firstProcess.stdout.setEncoding('utf8');
            firstProcess.stderr.setEncoding('utf8');
            firstProcess.stdout.on('data', chunk => { output.stdout += chunk; });
            firstProcess.stderr.on('data', chunk => { output.stderr += chunk; });
            firstExit = new Promise(resolve => {
                firstProcess.once('exit', (code, signal) => resolve({ code, signal }));
            });

            const statePath = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
            const pauseDeadline = Date.now() + 60000;
            let targetReceivedSql = false;
            while (Date.now() < pauseDeadline) {
                if (firstProcess.exitCode !== null || firstProcess.signalCode !== null) {
                    const result = await firstExit;
                    assert.fail(
                        `db-pull exited before the SQL pause (${result.code}/${result.signal}):\n`
                        + output.stderr + output.stdout,
                    );
                }

                if (readHookState(site)?.pause_started) {
                    try {
                        const [[row]] = await adminConnection.query(
                            'SELECT COUNT(*) AS matching_rows FROM '
                            + `\`${targetDatabase}\`.\`${guardTable}\` `
                            + "WHERE id = 1 AND value = 'source-value'"
                        );
                        if (Number(row.matching_rows) === 1) {
                            targetReceivedSql = true;
                            break;
                        }
                    } catch (error) {
                        if (error.code !== 'ER_NO_SUCH_TABLE') {
                            throw error;
                        }
                    }
                }
                await sleep(20);
            }

            assert.equal(
                targetReceivedSql,
                true,
                'db-pull did not execute target SQL before the source pause',
            );
            const stoppedState = JSON.parse(readFileSync(statePath, 'utf8'));
            assert.equal(stoppedState.sql_output, 'mysql');
            assert.equal(firstProcess.kill('SIGKILL'), true);
            const killed = await firstExit;
            assert.equal(killed.code, null);
            assert.equal(killed.signal, 'SIGKILL');
            firstProcess = null;

            const serverDeadline = Date.now() + 10000;
            while (!readHookState(site)?.pause_finished && Date.now() < serverDeadline) {
                await sleep(20);
            }
            assert.equal(
                readHookState(site)?.pause_finished,
                true,
                'source did not leave the pause after the client process stopped',
            );

            const targetConnection = await createMysqlConnection(targetDatabase);
            try {
                await targetConnection.query(
                    `INSERT INTO \`${guardTable}\` (id, value) VALUES (1, 'target-only-after-death') `
                    + 'ON DUPLICATE KEY UPDATE value = VALUES(value)'
                );
            } finally {
                await targetConnection.end();
            }

            // This is an ordinary public command, not out-of-band state editing.
            // It currently replaces active_resumable_command with db-index.
            const indexResult = runImporter(importUrl(), tempDir, 'db-index', {
                secret: getSiteSecret(site),
                autoResume: false,
            });
            assert.equal(
                indexResult.exitCode,
                0,
                `db-index failed:\n${indexResult.stderr}\n${indexResult.stdout}`,
            );
            const overwrittenState = JSON.parse(readFileSync(statePath, 'utf8'));
            assert.equal(overwrittenState.active_resumable_command.command_name, 'db-index');
            assert.equal(overwrittenState.active_resumable_command.completion_state, 'complete');

            const restarted = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlOutputArguments(targetDatabase),
                autoResume: false,
                timeout: 180000,
            });

            const checkConnection = await createMysqlConnection(targetDatabase);
            let targetValue;
            try {
                const [[row]] = await checkConnection.query(
                    `SELECT value FROM \`${guardTable}\` WHERE id = 1`
                );
                targetValue = row?.value;
            } finally {
                await checkConnection.end();
            }

            assert.equal(
                targetValue,
                'target-only-after-death',
                'a new db-pull changed the target after db-index replaced the normal checkpoint; '
                + `exit=${restarted.exitCode}\n${restarted.stderr}\n${restarted.stdout}`,
            );
            assert.equal(
                restarted.exitCode,
                1,
                `Expected the unfinished direct output to reject a new process:\n`
                + restarted.stderr + restarted.stdout,
            );
            const restartError = `${restarted.stderr}\n${restarted.stdout}`;
            assert.match(restartError, /db-pull stopped while writing SQL to MySQL\./);
            assert.match(
                restartError,
                /Reprint does not know which database changes MySQL kept\./,
            );
            assert.match(
                restartError,
                /Restore or reset the target database, then run db-pull --abort\./,
            );
        } finally {
            if (
                firstProcess
                && firstProcess.exitCode === null
                && firstProcess.signalCode === null
            ) {
                firstProcess.kill('SIGKILL');
                await firstExit;
            }
            removeTestHooks(site);
            clearHookState(site);
            await adminConnection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
            await adminConnection.end();
            cleanupTempDir(tempDir);
        }
    });

    it('exits nonzero when a later statement in one multi_query group fails', async () => {
        const tempDir = createTempDir('e2e-direct-mysql-later-statement-error');
        const targetDatabase = targetDatabases[1];
        const adminConnection = await createMysqlConnection();

        try {
            await adminConnection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
            await adminConnection.query(`CREATE DATABASE \`${targetDatabase}\``);

            const preflight = runImporter(importUrl(), tempDir, 'preflight', {
                secret: getSiteSecret(site),
                autoResume: false,
            });
            assert.equal(
                preflight.exitCode,
                0,
                `preflight failed:\n${preflight.stderr}\n${preflight.stdout}`,
            );

            clearHookState(site);
            writeTestHooks(site, [
                'function test_hook_before_sql_batch(&$sql, $cursor) {',
                "    if (strpos($sql, 'INSERT INTO `" + laterErrorTable + "`') === false) { return; }",
                '    $sql = "INSERT INTO `' + laterErrorTable + '` (id, value) "',
                '        . "VALUES (2, \'first statement reached target\');\\n"',
                '        . "THIS IS NOT VALID MYSQL;\\n";',
                `    file_put_contents('/srv/e2e-sites/.e2e-hook-state-${site}', json_encode([`,
                "        'later_statement_error_injected' => true,",
                '    ]));',
                '}',
            ].join('\n'));

            // PHP 7.4 and 8.0 default to MYSQLI_REPORT_OFF. Force that supported
            // runtime behavior when the E2E runner uses a newer PHP whose strict
            // default would throw before the importer's explicit errno check.
            const wrapperPath = join(tempDir, 'mysqli-report-off-importer.php');
            const escapedImporterSourcePath = importerSourcePath
                .replaceAll('\\', '\\\\')
                .replaceAll("'", "\\'");
            writeFileSync(
                wrapperPath,
                '<?php\n'
                + 'mysqli_report(MYSQLI_REPORT_OFF);\n'
                + "define('IMPORTER_WRAPPER_ENTRY', true);\n"
                + `require '${escapedImporterSourcePath}';\n`,
            );

            const result = spawnSync(phpBinary, [
                wrapperPath,
                'db-pull',
                importUrl(),
                `--state-dir=${tempDir}`,
                `--fs-root=${fsRootDir(tempDir)}`,
                `--secret=${getSiteSecret(site)}`,
                ...mysqlOutputArguments(targetDatabase),
            ], {
                env: { ...process.env },
                encoding: 'utf8',
                timeout: 180000,
                maxBuffer: 50 * 1024 * 1024,
            });

            assert.equal(
                readHookState(site)?.later_statement_error_injected,
                true,
                `source did not emit the two-statement failure group:\n${result.stderr}\n${result.stdout}`,
            );
            assert.notEqual(
                result.status,
                0,
                'db-pull reported success after MySQL rejected the later statement in a group; '
                + `stderr=${result.stderr}\nstdout=${result.stdout}`,
            );
        } finally {
            removeTestHooks(site);
            clearHookState(site);
            await adminConnection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
            await adminConnection.end();
            cleanupTempDir(tempDir);
        }
    });
});
