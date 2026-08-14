/**
 * A stopped MySQL db-apply must replay the complete replacement dump. The
 * first 32 tables put statement 100 at their last INSERT, while the next
 * table's metadata lock keeps the process at that boundary until it is killed.
 * A restarted process must wait for that lingering target session to finish.
 * Later tables require both the replayed SQL mode and disabled foreign-key checks.
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

// The PHP.wasm wrapper is a Node process. Killing it closes the emulated MySQL
// connection immediately, so it cannot exercise a lingering PHP target session.
const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Import: MySQL db-apply process death', { timeout: 300000 }, () => {
    const site = 'db-apply-process-death';
    const checkpointTables = Array.from(
        { length: 32 },
        (_, index) => `aa_db_apply_${String(index + 1).padStart(2, '0')}`,
    );
    const lockedTable = 'ab_db_apply_lock';
    const sqlModeTable = 'ac_db_apply_sql_mode';
    const childTable = 'ad_db_apply_child';
    const parentTable = 'ae_db_apply_parent';
    const customTables = [
        ...checkpointTables, lockedTable, sqlModeTable, childTable, parentTable,
    ];
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

    function spawnDatabaseApply() {
        const output = { stdout: '', stderr: '' };
        const childProcess = spawn(phpBinary, [
            importerPath,
            'db-apply',
            importUrl(),
            `--state-dir=${tempDir}`,
            `--fs-root=${fsRootDir(tempDir)}`,
            `--secret=${getSiteSecret(site)}`,
            ...targetArguments(),
        ], {
            env: { ...process.env },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        childProcess.stdout.setEncoding('utf8');
        childProcess.stderr.setEncoding('utf8');
        childProcess.stdout.on('data', chunk => { output.stdout += chunk; });
        childProcess.stderr.on('data', chunk => { output.stderr += chunk; });
        const exit = new Promise(resolve => {
            childProcess.once('exit', (code, signal) => resolve({ code, signal }));
        });

        return { childProcess, output, exit };
    }

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_dbName, connection) => {
                for (const [index, table] of checkpointTables.entries()) {
                    await connection.query(
                        `CREATE TABLE \`${table}\` (`
                        + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${table}\` (id, value) VALUES (?, ?)`,
                        [index + 1, `checkpoint-row-${index + 1}`],
                    );
                }

                await connection.query(
                    `CREATE TABLE \`${lockedTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${lockedTable}\` (id, value) VALUES (1, 'locked-row')`
                );

                await connection.query(
                    `CREATE TABLE \`${sqlModeTable}\` (`
                    + "`id` INT NOT NULL, `value` ENUM('allowed') NOT NULL, "
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                // INSERT IGNORE creates ENUM's index-zero error value under the
                // source's strict default. The dump emits that value as '', which
                // a fresh strict target session rejects unless the SQL_MODE header
                // is replayed first.
                await connection.query(
                    `INSERT IGNORE INTO \`${sqlModeTable}\` (id, value) `
                    + "VALUES (1, 'not-an-enum-member')"
                );

                // Create the parent first, but name the child first so the dump
                // needs its header's disabled foreign-key checks during replay.
                await connection.query(
                    `CREATE TABLE \`${parentTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${parentTable}\` (id, value) VALUES (1, 'parent-row')`
                );
                await connection.query(
                    `CREATE TABLE \`${childTable}\` (`
                    + '`id` INT NOT NULL, `parent_id` INT NOT NULL, '
                    + 'PRIMARY KEY (`id`), '
                    + `CONSTRAINT \`fk_db_apply_process_death\` FOREIGN KEY (\`parent_id\`) `
                    + `REFERENCES \`${parentTable}\` (\`id\`)) ENGINE=InnoDB`
                );
                await connection.query(
                    `INSERT INTO \`${childTable}\` (id, parent_id) VALUES (1, 1)`
                );
            },
        });

        tempDir = createTempDir('e2e-db-apply-process-death');
        const pullResult = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            wallTimeout: 240000,
        });
        assert.equal(
            pullResult.exitCode,
            0,
            `db-pull failed:\n${pullResult.stderr}\n${pullResult.stdout}`,
        );
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

    it('waits for the lingering target session before replaying the dump', async () => {
        const adminConnection = await createMysqlConnection();
        let lockConnection;
        let firstApplyProcess;
        let firstApplyExit;
        let resumedApplyProcess;
        let resumedApplyExit;
        let targetThreadId = null;
        let resumedTargetThreadId = null;

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
                await setupConnection.query(
                    "CREATE TEMPORARY TABLE `db_apply_sql_mode_probe` ("
                    + "`value` ENUM('allowed') NOT NULL) ENGINE=InnoDB"
                );
                await assert.rejects(
                    setupConnection.query(
                        "INSERT INTO `db_apply_sql_mode_probe` (value) VALUES ('')"
                    ),
                    error => Number(error.errno) === 1265,
                    'a fresh target session did not reject the ENUM index-zero value with error 1265',
                );
            } finally {
                await setupConnection.end();
            }

            lockConnection = await createMysqlConnection(targetDb);
            await lockConnection.query(`LOCK TABLES \`${lockedTable}\` WRITE`);

            const firstApply = spawnDatabaseApply();
            firstApplyProcess = firstApply.childProcess;
            firstApplyExit = firstApply.exit;

            const statePath = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
            const checkpointDeadline = Date.now() + 60000;
            while (Date.now() < checkpointDeadline) {
                if (
                    firstApplyProcess.exitCode !== null
                    || firstApplyProcess.signalCode !== null
                ) {
                    const result = await firstApplyExit;
                    assert.fail(
                        `db-apply exited before statement 100 (${result.code}/${result.signal}):\n`
                        + firstApply.output.stderr + firstApply.output.stdout,
                    );
                }

                let statementsExecuted = 0;
                if (existsSync(statePath)) {
                    const state = JSON.parse(readFileSync(statePath, 'utf8'));
                    statementsExecuted = Number(state.apply?.statements_executed || 0);
                }
                const [threads] = await adminConnection.query(
                    'SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST '
                    + 'WHERE DB = ? AND COMMAND = ? AND INFO LIKE ?',
                    [targetDb, 'Query', `%${lockedTable}%`],
                );
                if (statementsExecuted === 100 && threads.length === 1) {
                    targetThreadId = Number(threads[0].ID);
                    break;
                }
                await sleep(25);
            }

            assert.notEqual(targetThreadId, null, 'db-apply did not stop after statement 100');
            const exitAfterKill = firstApplyExit;
            assert.equal(firstApplyProcess.kill('SIGKILL'), true);
            const killedResult = await exitAfterKill;
            assert.equal(killedResult.code, null);
            assert.equal(killedResult.signal, 'SIGKILL');

            const resumedApply = spawnDatabaseApply();
            resumedApplyProcess = resumedApply.childProcess;
            resumedApplyExit = resumedApply.exit;

            const advisoryLockDeadline = Date.now() + 10000;
            let advisoryLockWaiters = [];
            while (Date.now() < advisoryLockDeadline) {
                [advisoryLockWaiters] = await adminConnection.query(
                    'SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST '
                    + 'WHERE DB = ? AND COMMAND = ? AND INFO LIKE ? AND ID <> ?',
                    [targetDb, 'Query', '%GET_LOCK%', targetThreadId],
                );
                if (advisoryLockWaiters.length === 1) {
                    resumedTargetThreadId = Number(advisoryLockWaiters[0].ID);
                    break;
                }
                if (
                    resumedApplyProcess.exitCode !== null
                    || resumedApplyProcess.signalCode !== null
                ) {
                    const result = await resumedApplyExit;
                    assert.fail(
                        `restarted db-apply exited before waiting for the target lock `
                        + `(${result.code}/${result.signal}):\n`
                        + resumedApply.output.stderr + resumedApply.output.stdout,
                    );
                }
                await sleep(25);
            }

            assert.equal(
                advisoryLockWaiters.length,
                1,
                'db-apply did not wait for the target apply advisory lock',
            );
            const [blockedTargetThreads] = await adminConnection.query(
                'SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST '
                + 'WHERE ID = ? AND COMMAND = ? AND INFO LIKE ?',
                [targetThreadId, 'Query', `%${lockedTable}%`],
            );
            assert.equal(
                blockedTargetThreads.length,
                1,
                'the first target query stopped waiting for the metadata lock',
            );
            assert.equal(resumedApplyProcess.exitCode, null);
            assert.equal(resumedApplyProcess.signalCode, null);

            await lockConnection.query('UNLOCK TABLES');
            await lockConnection.end();
            lockConnection = null;

            const resumed = await resumedApplyExit;
            assert.equal(
                resumed.code,
                0,
                `restarted db-apply failed (${resumed.code}/${resumed.signal}):\n`
                + resumedApply.output.stderr + resumedApply.output.stdout,
            );
            assert.equal(resumed.signal, null);

            const comparison = await compareDatabases(getDbName(site), targetDb);
            assert.deepEqual(comparison.missingTables, []);
            assert.deepEqual(comparison.extraTables, []);
            assert.ok(
                Object.values(comparison.rowCounts).every(counts => counts.match),
                `row counts differ: ${JSON.stringify(comparison.rowCounts)}`,
            );

            const sourceConnection = await createMysqlConnection(getDbName(site));
            const targetConnection = await createMysqlConnection(targetDb);
            try {
                for (const table of customTables) {
                    const [sourceRows] = await sourceConnection.query(
                        `SELECT * FROM \`${table}\` ORDER BY id`
                    );
                    const [targetRows] = await targetConnection.query(
                        `SELECT * FROM \`${table}\` ORDER BY id`
                    );
                    assert.deepEqual(targetRows, sourceRows, `${table} differs after restart`);
                }
                const [sqlModeRows] = await targetConnection.query(
                    `SELECT value, value + 0 AS enumIndex FROM \`${sqlModeTable}\``
                );
                assert.equal(sqlModeRows.length, 1);
                assert.equal(sqlModeRows[0].value, '');
                assert.equal(Number(sqlModeRows[0].enumIndex), 0);
            } finally {
                await sourceConnection.end();
                await targetConnection.end();
            }
        } finally {
            if (
                firstApplyProcess
                && firstApplyProcess.exitCode === null
                && firstApplyProcess.signalCode === null
            ) {
                firstApplyProcess.kill('SIGKILL');
                await firstApplyExit;
            }
            if (
                resumedApplyProcess
                && resumedApplyProcess.exitCode === null
                && resumedApplyProcess.signalCode === null
            ) {
                resumedApplyProcess.kill('SIGKILL');
                await resumedApplyExit;
            }
            if (resumedTargetThreadId !== null) {
                try {
                    await adminConnection.query(`KILL CONNECTION ${resumedTargetThreadId}`);
                } catch {}
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
