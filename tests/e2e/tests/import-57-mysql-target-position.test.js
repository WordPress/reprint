/**
 * A MySQL target records each completed SQL group and its source position
 * together. A replacement process continues from that target position.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection, fsRootDir,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState, readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Import: source position saved in MySQL target', { timeout: 300000 }, () => {
    const site = 'mysql-target-cursor-resume';
    const sourceTable = 'aa_target_cursor_rows';
    const myisamSourceTable = 'ab_target_cursor_myisam_rows';
    const progressTable = '__reprint_db_pull_progress_728f9e0b-42f7-4d85-a7f3-8f53e90a6f4c';
    const targetDb = `${getDbName(site)}_import`;
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const importerPath = process.env.IMPORTER_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    const activeChildren = new Set();
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
            '--sql-fragments-start=5000',
            '--sql-fragments-min=5000',
            '--sql-fragments-max=5000',
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
            childProcess.once('exit', (code, signal) => {
                activeChildren.delete(childProcess);
                resolve({ code, signal });
            });
        });
        activeChildren.add(childProcess);
        return { childProcess, output, exit };
    }

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            customDb: async (_dbName, connection) => {
                for (const [table, engine] of [
                    [sourceTable, 'InnoDB'],
                    [myisamSourceTable, 'MyISAM'],
                ]) {
                    const key = engine === 'MyISAM'
                        ? 'UNIQUE KEY `replay_key` (`id`, `value`)'
                        : 'PRIMARY KEY (`id`)';
                    await connection.query(
                        `CREATE TABLE \`${table}\` (`
                        + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                        + `${key}) ENGINE=${engine}`
                    );
                    const rows = Array.from({ length: 600 }, (_, index) => [
                        index + 1,
                        `${table}-row-${index + 1}`,
                    ]);
                    for (let offset = 0; offset < rows.length; offset += 100) {
                        await connection.query(
                            `INSERT INTO \`${table}\` (id, value) VALUES ?`,
                            [rows.slice(offset, offset + 100)],
                        );
                    }
                }
            },
        });

        tempDir = createTempDir('e2e-mysql-target-cursor-resume');
        clearHookState(site);
        writeHookState(site, {
            pauseTable: sourceTable,
            pauseNextBatch: false,
            paused: false,
            countDrops: false,
            drops: {},
        });
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            '    if (!empty($state[\'countDrops\'])) {',
            `        foreach (['${sourceTable}', '${myisamSourceTable}'] as $table) {`,
            '            if (strpos($sql, "DROP TABLE IF EXISTS `{$table}`") !== false) {',
            '                $state[\'drops\'][$table] = ($state[\'drops\'][$table] ?? 0) + 1;',
            '            }',
            '        }',
            '    }',
            '    if (!empty($state[\'pauseNextBatch\'])) {',
            '        $state[\'pauseNextBatch\'] = false;',
            '        $state[\'paused\'] = true;',
            '        file_put_contents($state_file, json_encode($state));',
            '        usleep(10000000);',
            '        return;',
            '    }',
            '    $pause_table = $state[\'pauseTable\'] ?? null;',
            '    if ($pause_table !== null',
            '        && strpos($sql, "INSERT INTO `{$pause_table}`") !== false',
            '        && substr(rtrim($sql), -1) === \';\') {',
            '        $state[\'pauseNextBatch\'] = true;',
            '    }',
            '    file_put_contents($state_file, json_encode($state));',
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

    it('logs and skips the progress table when the source is also a Reprint target', async () => {
        writeHookState(site, {
            ...readHookState(site),
            pauseTable: null,
            pauseNextBatch: false,
            paused: false,
        });
        const collisionDir = createTempDir('e2e-mysql-source-cursor-collision');
        const sourceConnection = await createMysqlConnection(getDbName(site));
        const targetConnection = await createMysqlConnection(targetDb);
        const sourceProgressTable = progressTable.toUpperCase();
        try {
            await sourceConnection.query(
                `CREATE TABLE \`${sourceProgressTable}\` (`
                + '`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY, '
                + '`note` VARCHAR(64) NOT NULL) ENGINE=InnoDB'
            );
            await sourceConnection.query(
                `INSERT INTO \`${sourceProgressTable}\` (id, note) VALUES (1, 'source row')`
            );

            const preflight = runImporter(importUrl(), collisionDir, 'preflight', {
                secret: getSiteSecret(site),
            });
            assert.equal(preflight.exitCode, 0, preflight.stderr + preflight.stdout);

            const result = runImporter(importUrl(), collisionDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArguments(),
            });
            assert.equal(result.exitCode, 0, result.stderr + result.stdout);
            assert.match(
                readAuditLog(collisionDir),
                new RegExp(`SKIPPING SOURCE TABLE IF PRESENT .*${progressTable}`),
            );

            const [targetColumns] = await targetConnection.query(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
                + 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [targetDb, progressTable],
            );
            assert.deepEqual(
                targetColumns.map(column => column.COLUMN_NAME),
                ['id', 'source_hash', 'source_cursor'],
            );
        } finally {
            await sourceConnection.query(`DROP TABLE IF EXISTS \`${sourceProgressTable}\``);
            await sourceConnection.end();
            await targetConnection.end();
            cleanupTempDir(collisionDir);
        }
    });

    afterAll(async () => {
        for (const childProcess of activeChildren) {
            childProcess.kill('SIGKILL');
        }
        removeTestHooks(site);
        clearHookState(site);
        if (tempDir) {
            cleanupTempDir(tempDir);
        }
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.end();
    });

    it('continues InnoDB and keyed MyISAM tables from the position saved by MySQL', async () => {
        writeHookState(site, {
            pauseTable: sourceTable,
            pauseNextBatch: false,
            paused: false,
            countDrops: false,
            drops: {},
        });
        const first = spawnDatabasePull();

        async function waitForSavedTablePosition(table, databasePull) {
            const interruptedTarget = await createMysqlConnection(targetDb);
            let importedRowCount = 0;
            let savedSourceCursor = null;
            try {
                const targetDeadline = Date.now() + 60000;
                while (Date.now() < targetDeadline) {
                    try {
                        const [[importedRows]] = await interruptedTarget.query(
                            `SELECT COUNT(*) AS rowCount FROM \`${table}\``
                        );
                        const [[savedPosition]] = await interruptedTarget.query(
                            `SELECT source_cursor FROM \`${progressTable}\` WHERE id = 1`
                        );
                        importedRowCount = Number(importedRows.rowCount);
                        savedSourceCursor = savedPosition?.source_cursor ?? null;
                        if (
                            importedRowCount > 0
                            && importedRowCount < 600
                            && typeof savedSourceCursor === 'string'
                            && savedSourceCursor.length > 0
                            && readHookState(site)?.paused === true
                        ) {
                            const decoded = JSON.parse(
                                Buffer.from(savedSourceCursor, 'base64').toString('utf8')
                            );
                            if (decoded.current_table === table) {
                                return { importedRowCount, savedSourceCursor, decoded };
                            }
                        }
                    } catch (error) {
                        if (error?.code !== 'ER_NO_SUCH_TABLE') {
                            throw error;
                        }
                    }
                    if (
                        databasePull.childProcess.exitCode !== null
                        || databasePull.childProcess.signalCode !== null
                    ) {
                        const result = await databasePull.exit;
                        assert.fail(
                            `db-pull exited before MySQL saved partial work (${result.code}/${result.signal}):\n`
                            + databasePull.output.stderr + databasePull.output.stdout,
                        );
                    }
                    await sleep(25);
                }
            } finally {
                await interruptedTarget.end();
            }
            assert.fail(`db-pull did not save a partial position for ${table}`);
        }

        const firstPosition = await waitForSavedTablePosition(sourceTable, first);
        assert.deepEqual(firstPosition.decoded.current_pk_columns, ['id']);
        const savedPrimaryKey = firstPosition.decoded.last_pk_values.id;
        const savedPrimaryKeyValue = (
            typeof savedPrimaryKey === 'object'
            && savedPrimaryKey !== null
            && typeof savedPrimaryKey.__binary__ === 'string'
        )
            ? Buffer.from(savedPrimaryKey.__binary__, 'base64').toString('utf8')
            : savedPrimaryKey;
        assert.equal(Number(savedPrimaryKeyValue), firstPosition.importedRowCount);

        assert.equal(first.childProcess.kill('SIGKILL'), true);
        const firstKilled = await first.exit;
        assert.equal(firstKilled.code, null);
        assert.equal(firstKilled.signal, 'SIGKILL');

        const hookState = readHookState(site);
        writeHookState(site, {
            ...hookState,
            pauseTable: myisamSourceTable,
            pauseNextBatch: false,
            paused: false,
            countDrops: true,
            drops: {},
        });

        const lockName = 'reprint-db-pull-'
            + createHash('sha256').update(targetDb).digest('hex').slice(0, 40);
        const heldLock = await createMysqlConnection(targetDb);
        const [[lockResult]] = await heldLock.query('SELECT GET_LOCK(?, 0) AS acquired', [lockName]);
        assert.equal(Number(lockResult.acquired), 1);

        const second = spawnDatabasePull();
        try {
            let waitingForLock = false;
            const lockDeadline = Date.now() + 10000;
            while (Date.now() < lockDeadline) {
                const [[waiting]] = await heldLock.query(
                    'SELECT COUNT(*) AS waiting FROM INFORMATION_SCHEMA.PROCESSLIST '
                    + "WHERE INFO LIKE 'SELECT GET_LOCK(%'"
                );
                waitingForLock = Number(waiting.waiting) > 0;
                if (waitingForLock) {
                    break;
                }
                if (second.childProcess.exitCode !== null || second.childProcess.signalCode !== null) {
                    const result = await second.exit;
                    assert.fail(
                        `replacement db-pull exited instead of waiting (${result.code}/${result.signal}):\n`
                        + second.output.stderr + second.output.stdout,
                    );
                }
                await sleep(25);
            }
            assert.equal(waitingForLock, true, 'replacement db-pull did not wait for the target lock');
        } finally {
            await heldLock.query('SELECT RELEASE_LOCK(?)', [lockName]);
            await heldLock.end();
        }

        const secondPosition = await waitForSavedTablePosition(myisamSourceTable, second);
        assert.deepEqual(secondPosition.decoded.current_pk_columns, []);
        assert.equal(
            readHookState(site)?.drops?.[sourceTable] ?? 0,
            0,
            'replacement db-pull restarted the completed part of the InnoDB table',
        );

        assert.equal(second.childProcess.kill('SIGKILL'), true);
        const secondKilled = await second.exit;
        assert.equal(secondKilled.code, null);
        assert.equal(secondKilled.signal, 'SIGKILL');

        const finalHookState = readHookState(site);
        writeHookState(site, {
            ...finalHookState,
            pauseTable: null,
            pauseNextBatch: false,
            paused: false,
            drops: {
                ...finalHookState.drops,
                [myisamSourceTable]: 0,
            },
        });

        const replacement = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            extraArgs: mysqlArguments(),
            wallTimeout: 240000,
        });
        assert.equal(
            replacement.exitCode,
            0,
            `replacement db-pull failed:\n${replacement.stderr}\n${replacement.stdout}`,
        );
        assert.equal(
            readHookState(site)?.drops?.[myisamSourceTable] ?? 0,
            0,
            'replacement db-pull restarted the completed part of the MyISAM table',
        );

        const targetConnection = await createMysqlConnection(targetDb);
        try {
            for (const table of [sourceTable, myisamSourceTable]) {
                const [summary] = await targetConnection.query(
                    `SELECT COUNT(*) AS rowCount, MIN(id) AS firstId, MAX(id) AS lastId `
                    + `FROM \`${table}\``
                );
                assert.deepEqual(
                    {
                        rowCount: Number(summary[0].rowCount),
                        firstId: Number(summary[0].firstId),
                        lastId: Number(summary[0].lastId),
                    },
                    { rowCount: 600, firstId: 1, lastId: 600 },
                );
            }
        } finally {
            await targetConnection.end();
        }
    });
});
