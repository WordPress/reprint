/**
 * MySQL db-pull first downloads all of db.sql. A stopped
 * download must not touch the target, and the next process must finish the
 * file before applying it through db-apply.
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

describe('Import: MySQL db-pull through complete db.sql replay', { timeout: 300000 }, () => {
    const site = 'mysql-db-pull-pipeline';
    const guardTable = 'aa_mysql_pipeline_guard';
    const sqlModeTable = 'ab_mysql_pipeline_sql_mode';
    const childTable = 'ac_mysql_pipeline_child';
    const parentTable = 'ad_mysql_pipeline_parent';
    const targetDatabase = `${getDbName(site)}_pipeline_target`;
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function mysqlOutputArguments() {
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
                    const table = `mm_mysql_pipeline_${String(index).padStart(2, '0')}`;
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
                    `CREATE TABLE \`${sqlModeTable}\` (`
                    + "`id` INT NOT NULL, `value` ENUM('allowed') NOT NULL, "
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT IGNORE INTO \`${sqlModeTable}\` (id, value) `
                    + "VALUES (1, 'not-an-enum-member')"
                );
                await connection.query(
                    `CREATE TABLE \`${parentTable}\` (`
                    + '`id` INT NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(`INSERT INTO \`${parentTable}\` (id) VALUES (1)`);
                await connection.query(
                    `CREATE TABLE \`${childTable}\` (`
                    + '`id` INT NOT NULL, `parent_id` INT NOT NULL, PRIMARY KEY (`id`), '
                    + `CONSTRAINT \`fk_mysql_pipeline_parent\` FOREIGN KEY (parent_id) `
                    + `REFERENCES \`${parentTable}\` (id)) ENGINE=InnoDB`
                );
                await connection.query(
                    `INSERT INTO \`${childTable}\` (id, parent_id) VALUES (1, 1)`
                );
            },
        });
    });

    afterAll(async () => {
        const connection = await createMysqlConnection();
        try {
            await connection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
        } finally {
            await connection.end();
        }
    });

    it('resumes the dump before applying it with the dump session settings', async () => {
        const tempDir = createTempDir('e2e-mysql-db-pull-pipeline');
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

            const output = { stdout: '', stderr: '' };
            firstProcess = spawn(phpBinary, [
                importerPath,
                'db-pull',
                importUrl(),
                `--state-dir=${tempDir}`,
                `--fs-root=${fsRootDir(tempDir)}`,
                `--secret=${getSiteSecret(site)}`,
                ...mysqlOutputArguments(),
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

            const stateDirectory = pullStateDirectory(tempDir, importUrl());
            const statePath = join(stateDirectory, 'state.json');
            const checkpointDeadline = Date.now() + 60000;
            let interruptedState;
            let targetChanged = false;
            while (Date.now() < checkpointDeadline) {
                if (firstProcess.exitCode !== null || firstProcess.signalCode !== null) {
                    const result = await firstExit;
                    assert.fail(
                        `db-pull exited before a SQL checkpoint (${result.code}/${result.signal}):\n`
                        + output.stderr + output.stdout,
                    );
                }

                if (existsSync(statePath)) {
                    interruptedState = JSON.parse(readFileSync(statePath, 'utf8'));
                    if (
                        interruptedState.active_resumable_command?.command_name === 'db-pull'
                        && interruptedState.active_resumable_command?.current_stage === 'sql'
                        && interruptedState.active_resumable_command?.remote_cursor
                    ) {
                        break;
                    }
                }
                const [targetTables] = await adminConnection.query(
                    'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
                    + 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [targetDatabase, guardTable],
                );
                if (targetTables.length !== 0) {
                    targetChanged = true;
                    break;
                }
                await sleep(20);
            }

            assert.equal(
                targetChanged,
                false,
                'db-pull changed the target before saving a resumable dump checkpoint',
            );
            assert.ok(interruptedState, 'db-pull did not save a SQL download checkpoint');
            assert.equal(interruptedState.sql_output, 'file');
            assert.equal(interruptedState.pull_pipeline?.started_by_command, 'pull-db');
            assert.ok(existsSync(join(tempDir, 'db.sql')), 'db-pull did not retain db.sql');
            assert.ok(
                !existsSync(join(stateDirectory, 'database-pull-output.json')),
                'MySQL pipeline created a direct-output attempt',
            );

            assert.equal(firstProcess.kill('SIGKILL'), true);
            const killed = await firstExit;
            assert.equal(killed.code, null);
            assert.equal(killed.signal, 'SIGKILL');
            firstProcess = null;

            const resumed = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlOutputArguments(),
                autoResume: false,
                timeout: 180000,
                wallTimeout: 240000,
            });
            assert.equal(
                resumed.exitCode,
                0,
                `resumed db-pull failed:\n${resumed.stderr}\n${resumed.stdout}`,
            );

            const completedState = JSON.parse(readFileSync(statePath, 'utf8'));
            assert.equal(completedState.pull_pipeline.last_completed_stage, 'db-apply');
            assert.equal(completedState.active_resumable_command.command_name, 'db-apply');
            assert.equal(completedState.active_resumable_command.completion_state, 'complete');

            const comparison = await compareDatabases(getDbName(site), targetDatabase);
            assert.deepEqual(comparison.missingTables, []);
            assert.deepEqual(comparison.extraTables, []);
            assert.ok(
                Object.values(comparison.rowCounts).every(counts => counts.match),
                `row counts differ: ${JSON.stringify(comparison.rowCounts)}`,
            );

            const targetConnection = await createMysqlConnection(targetDatabase);
            try {
                const [sqlModeRows] = await targetConnection.query(
                    `SELECT value, value + 0 AS enumIndex FROM \`${sqlModeTable}\``
                );
                assert.equal(sqlModeRows.length, 1);
                assert.equal(sqlModeRows[0].value, '');
                assert.equal(Number(sqlModeRows[0].enumIndex), 0);
                const [[childRow]] = await targetConnection.query(
                    `SELECT parent_id FROM \`${childTable}\` WHERE id = 1`
                );
                assert.equal(Number(childRow.parent_id), 1);
            } finally {
                await targetConnection.end();
            }
        } finally {
            if (
                firstProcess
                && firstProcess.exitCode === null
                && firstProcess.signalCode === null
            ) {
                firstProcess.kill('SIGKILL');
                await firstExit;
            }
            await adminConnection.query(`DROP DATABASE IF EXISTS \`${targetDatabase}\``);
            await adminConnection.end();
            cleanupTempDir(tempDir);
        }
    });
});
