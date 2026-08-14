/**
 * A nontransactional INSERT can survive process death before its source
 * position is saved. The replacement process must start at that table's
 * DROP + CREATE pair rather than repeat the INSERT against the old table.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection, fsRootDir,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Import: replayable MySQL table replacement', { timeout: 300000 }, () => {
    const site = 'mysql-table-replacement-resume';
    const sourceTable = 'aa_replay_table';
    const targetDb = `${getDbName(site)}_import`;
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    let tempDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function mysqlArguments() {
        return [
            '--sql-output=mysql',
            '--mysql-host=127.0.0.1',
            '--mysql-user=e2e_admin',
            '--mysql-password=e2e_password',
            `--mysql-database=${targetDb}`,
            '--sql-fragments-start=1',
            '--sql-fragments-min=1',
            '--sql-fragments-max=1',
            '--progress=jsonl',
        ];
    }

    function spawnDatabasePull() {
        const output = { stdout: '', stderr: '' };
        const childProcess = spawn(phpBinary, [
            importerPath,
            'db-pull',
            importUrl(),
            `--state-dir=${tempDir}`,
            `--fs-root=${fsRootDir(tempDir)}`,
            `--secret=${getSiteSecret(site)}`,
            ...mysqlArguments(),
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
                await connection.query(
                    `CREATE TABLE \`${sourceTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=MyISAM'
                );
                await connection.query(
                    `INSERT INTO \`${sourceTable}\` (id, value) `
                    + "VALUES (1, 'row-written-before-process-death')"
                );
            },
        });

        tempDir = createTempDir('e2e-mysql-table-replacement-resume');
        clearHookState(site);
        writeHookState(site, { injected: false });
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            `    if (empty($state['injected']) && strpos($sql, 'INSERT INTO \`${sourceTable}\`') !== false) {`,
            "        $sql .= \"\\nSELECT SLEEP(60);\";",
            '        $state[\'injected\'] = true;',
            '        file_put_contents($state_file, json_encode($state));',
            '    }',
            '}',
        ].join('\n'));

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.query(`CREATE DATABASE \`${targetDb}\``);
        await connection.end();

        const preflight = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(
            preflight.exitCode,
            0,
            `preflight failed:\n${preflight.stderr}\n${preflight.stdout}`,
        );
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.end();
    });

    it('starts the unfinished table again after process death', async () => {
        const first = spawnDatabasePull();
        const admin = await createMysqlConnection();
        let oldTargetConnectionId = null;
        const deadline = Date.now() + 60000;

        while (Date.now() < deadline) {
            const [processes] = await admin.query(
                'SELECT ID, INFO FROM INFORMATION_SCHEMA.PROCESSLIST '
                + 'WHERE DB = ? AND INFO LIKE \'SELECT SLEEP(60)%\'',
                [targetDb],
            );
            if (readHookState(site)?.injected && processes.length > 0) {
                oldTargetConnectionId = Number(processes[0].ID);
                break;
            }
            if (first.childProcess.exitCode !== null || first.childProcess.signalCode !== null) {
                const result = await first.exit;
                assert.fail(
                    `db-pull exited before the target reached SLEEP (${result.code}/${result.signal}):\n`
                    + first.output.stderr + first.output.stdout,
                );
            }
            await sleep(25);
        }

        assert.ok(oldTargetConnectionId, 'the target never reached the statement after the MyISAM INSERT');
        assert.equal(first.childProcess.kill('SIGKILL'), true);
        const killed = await first.exit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');

        const target = await createMysqlConnection(targetDb);
        const [[persistedRow]] = await target.query(
            `SELECT id, value FROM \`${sourceTable}\``
        );
        await target.end();
        assert.deepEqual(
            { id: Number(persistedRow.id), value: persistedRow.value },
            { id: 1, value: 'row-written-before-process-death' },
        );

        removeTestHooks(site);
        const resumed = spawnDatabasePull();

        const waitDeadline = Date.now() + 10000;
        while (Date.now() < waitDeadline) {
            const [oldConnection] = await admin.query(
                'SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = ?',
                [oldTargetConnectionId],
            );
            if (oldConnection.length === 0) {
                break;
            }
            const [waiters] = await admin.query(
                'SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST '
                + 'WHERE DB = ? AND ID <> ? AND INFO LIKE \'SELECT GET_LOCK(%\'',
                [targetDb, oldTargetConnectionId],
            );
            if (waiters.length > 0) {
                try {
                    await admin.query(`KILL CONNECTION ${oldTargetConnectionId}`);
                } catch (error) {
                    if (Number(error.errno) !== 1094) {
                        throw error;
                    }
                }
                oldTargetConnectionId = null;
                break;
            }
            if (resumed.childProcess.exitCode !== null || resumed.childProcess.signalCode !== null) {
                break;
            }
            await sleep(25);
        }

        if (oldTargetConnectionId !== null) {
            try {
                await admin.query(`KILL CONNECTION ${oldTargetConnectionId}`);
            } catch (error) {
                if (Number(error.errno) !== 1094) {
                    throw error;
                }
            }
        }
        await admin.end();

        const resumedResult = await resumed.exit;
        assert.equal(
            resumedResult.code,
            0,
            `resumed db-pull failed (${resumedResult.code}/${resumedResult.signal}):\n`
            + resumed.output.stderr + resumed.output.stdout,
        );

        const imported = await createMysqlConnection(targetDb);
        try {
            const [rows] = await imported.query(
                `SELECT id, value FROM \`${sourceTable}\` ORDER BY id`
            );
            assert.deepEqual(
                rows.map(row => ({ id: Number(row.id), value: row.value })),
                [{ id: 1, value: 'row-written-before-process-death' }],
            );
        } finally {
            await imported.end();
        }
    });
});
