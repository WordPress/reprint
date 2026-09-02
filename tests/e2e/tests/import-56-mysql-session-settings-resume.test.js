/**
 * A new MySQL process continues from the position saved in its target. It
 * reruns the dump's connection settings before it executes later table data.
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
    createMysqlConnection, fsRootDir, pullStateDirectory,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const describeWithHostPhpProcess = process.env.PHP_BINARY?.endsWith('/playground-php.sh')
    ? describe.skip
    : describe;

describeWithHostPhpProcess('Import: MySQL session settings after restart', { timeout: 300000 }, () => {
    const site = 'mysql-session-settings-resume';
    const setupTables = Array.from(
        { length: 32 },
        (_, index) => `aa_session_setup_${String(index + 1).padStart(2, '0')}`,
    );
    const applyCursorTable = 'zy_db_apply_cursor_rows';
    const sqlModeTable = 'zz_session_setup_sql_mode';
    const progressTable = '__reprint_db_pull_progress_49acb118-a97a-45c7-814d-8e670db7f6b4';
    const targetDb = `${getDbName(site)}_import`;
    const projectRoot = join(import.meta.dirname, '..', '..', '..');
    const clientPath = process.env.CLIENT_PATH
        || join(projectRoot, 'packages', 'reprint-client', 'bin', 'reprint-client');
    const phpBinary = process.env.PHP_BINARY || 'php';
    let tempDir;
    let fileTempDir;

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function targetMysqlArguments() {
        return [
            '--target-engine=mysql',
            '--target-host=127.0.0.1',
            '--target-user=e2e_admin',
            '--target-pass=e2e_password',
            `--target-db=${targetDb}`,
        ];
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
            '--max-exec=1',
            '--progress=jsonl',
        ];
    }

    function spawnDatabasePull() {
        const output = { stdout: '', stderr: '' };
        const childProcess = spawn(phpBinary, [
            clientPath,
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

    function spawnDatabaseApply(stateDir) {
        const output = { stdout: '', stderr: '' };
        const childProcess = spawn(phpBinary, [
            clientPath,
            'db-apply',
            importUrl(),
            `--state-dir=${stateDir}`,
            `--fs-root=${fsRootDir(stateDir)}`,
            `--secret=${getSiteSecret(site)}`,
            ...targetMysqlArguments(),
            '--progress=jsonl',
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
                for (const [index, table] of setupTables.entries()) {
                    await connection.query(
                        `CREATE TABLE \`${table}\` (`
                        + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${table}\` (id, value) VALUES (?, ?)`,
                        [index + 1, `session-row-${index + 1}`],
                    );
                }

                await connection.query(
                    `CREATE TABLE \`${applyCursorTable}\` (`
                    + '`id` INT NOT NULL, `value` VARCHAR(64) NOT NULL, '
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT INTO \`${applyCursorTable}\` (id, value) VALUES `
                    + "(1, 'apply-row-1'), (2, 'apply-row-2'), (3, 'apply-row-3')"
                );

                await connection.query(
                    `CREATE TABLE \`${sqlModeTable}\` (`
                    + "`id` INT NOT NULL, `value` ENUM('allowed') NOT NULL, "
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                await connection.query(
                    `INSERT IGNORE INTO \`${sqlModeTable}\` (id, value) `
                    + "VALUES (1, 'not-an-enum-member')"
                );
            },
        });

        tempDir = createTempDir('e2e-mysql-session-settings-resume');
        clearHookState(site);
        writeHookState(site, { requests: 0, batches: 0, release: false });
        writeTestHooks(site, [
            'function test_hook_after_gzip_init($gz, $boundary) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            '    $state[\'requests\'] = ($state[\'requests\'] ?? 0) + 1;',
            '    if ($state[\'requests\'] === 2) {',
            '        $state[\'paused\'] = true;',
            '        e2e_write_hook_state($state_file, $state);',
            '        do {',
            '            usleep(10000);',
            '            $state = json_decode(file_get_contents($state_file), true);',
            '        } while (empty($state[\'release\']));',
            '    } else {',
            '        e2e_write_hook_state($state_file, $state);',
            '    }',
            '}',
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            '    $state[\'batches\'] = ($state[\'batches\'] ?? 0) + 1;',
            '    e2e_write_hook_state($state_file, $state);',
            '    if ($state[\'requests\'] === 1 && $state[\'batches\'] === 60) {',
            '        usleep(1500000);',
            '    }',
            '}',
        ].join('\n'));

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.query(`CREATE DATABASE \`${targetDb}\``);
        await connection.end();

        const targetConnection = await createMysqlConnection(targetDb);
        try {
            // A fresh connection rejects ENUM index zero in strict mode. Both
            // restart cases begin after the dump header, so accepting this value
            // later proves that db-session-setup.sql restored the dump SQL mode.
            await targetConnection.query(
                "CREATE TEMPORARY TABLE `session_setup_probe` ("
                + "`value` ENUM('allowed') NOT NULL) ENGINE=InnoDB"
            );
            await assert.rejects(
                targetConnection.query(
                    "INSERT INTO `session_setup_probe` (value) VALUES ('')"
                ),
                error => Number(error.errno) === 1265,
                'a fresh target session did not reject the ENUM index-zero value',
            );
        } finally {
            await targetConnection.end();
        }

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
        if (fileTempDir) {
            cleanupTempDir(fileTempDir);
        }
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.end();
    });

    it('runs the dump connection settings before resumed SQL', async () => {
        const first = spawnDatabasePull();
        const deadline = Date.now() + 60000;
        let savedCursor = null;
        const targetMonitor = await createMysqlConnection(targetDb);

        try {
            while (Date.now() < deadline) {
                if (first.childProcess.exitCode !== null || first.childProcess.signalCode !== null) {
                    const result = await first.exit;
                    assert.fail(
                        `db-pull exited before the source paused (${result.code}/${result.signal}):\n`
                        + first.output.stderr + first.output.stdout,
                    );
                }

                const hookState = readHookState(site);
                try {
                    const [[savedPosition]] = await targetMonitor.query(
                        'SELECT source_cursor, file_byte_offset FROM `' + progressTable + '` WHERE id = 1'
                    );
                    savedCursor = savedPosition?.source_cursor ?? null;
                    if (savedPosition) {
                        assert.equal(savedPosition.file_byte_offset, null);
                    }
                } catch (error) {
                    if (error?.code !== 'ER_NO_SUCH_TABLE') {
                        throw error;
                    }
                }
                if (hookState?.paused && savedCursor) {
                    break;
                }
                await sleep(25);
            }
        } finally {
            await targetMonitor.end();
        }

        assert.ok(savedCursor, 'db-pull did not save a source position before the pause');
        assert.equal(first.childProcess.kill('SIGKILL'), true);
        const killed = await first.exit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');

        const hookState = readHookState(site);
        writeHookState(site, { ...hookState, release: true });
        await sleep(100);
        removeTestHooks(site);

        const resumed = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
            extraArgs: mysqlArguments(),
            wallTimeout: 240000,
        });
        assert.equal(
            resumed.exitCode,
            0,
            `resumed db-pull failed:\n${resumed.stderr}\n${resumed.stdout}`,
        );

        const targetConnection = await createMysqlConnection(targetDb);
        try {
            const [rows] = await targetConnection.query(
                `SELECT value, value + 0 AS enumIndex FROM \`${sqlModeTable}\``
            );
            assert.equal(rows.length, 1);
            assert.equal(rows[0].value, '');
            assert.equal(Number(rows[0].enumIndex), 0);
        } finally {
            await targetConnection.end();
        }
    });

    it("continues db.sql from MySQL's saved position with the saved session settings", async () => {
        fileTempDir = createTempDir('e2e-mysql-file-session-settings-resume');
        writeHookState(site, {
            apply_cursor_insert_open: false,
            pause_injected: 0,
        });
        // The exporter may split one INSERT across requests. Add the pause only
        // after its closing fragment so the downloaded SQL remains valid.
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            "    $statement_complete = substr(rtrim($sql), -1) === ';';",
            `    if (strpos($sql, 'INSERT INTO \`${applyCursorTable}\`') !== false) {`,
            "        $state['apply_cursor_insert_open'] = true;",
            '    }',
            "    if (!empty($state['apply_cursor_insert_open']) && $statement_complete) {",
            "        $sql .= \"\\nDO SLEEP(5) /* reprint_db_apply_cursor_rows */;\\n\";",
            "        $state['apply_cursor_insert_open'] = false;",
            "        $state['pause_injected'] = ($state['pause_injected'] ?? 0) + 1;",
            '    }',
            '    e2e_write_hook_state($state_file, $state);',
            '}',
        ].join('\n'));

        const preflight = runImporter(importUrl(), fileTempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(
            preflight.exitCode,
            0,
            `preflight failed:\n${preflight.stderr}\n${preflight.stdout}`,
        );

        const pulled = runImporter(importUrl(), fileTempDir, 'db-pull', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--sql-output=file',
                '--sql-fragments-start=1',
                '--sql-fragments-min=1',
                '--sql-fragments-max=1',
            ],
            wallTimeout: 240000,
        });
        const hookState = readHookState(site);
        removeTestHooks(site);
        assert.equal(
            pulled.exitCode,
            0,
            `file db-pull failed:\n${pulled.stderr}\n${pulled.stdout}`,
        );
        assert.equal(
            hookState?.pause_injected,
            1,
            'db-pull did not add one pause after the complete source INSERT',
        );
        assert.equal(
            existsSync(join(fileTempDir, 'db-session-setup.sql')),
            true,
            'db-pull did not save the MySQL session setup beside db.sql',
        );
        const sqlContents = readFileSync(join(fileTempDir, 'db.sql'), 'utf8');
        const sqlGroupMarkers = [...sqlContents.matchAll(
            /-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 ([A-Za-z0-9+/=]+)/g,
        )];
        assert.ok(sqlGroupMarkers.length > 0, 'db.sql did not keep the exporter SQL group boundaries');

        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.query(`CREATE DATABASE \`${targetDb}\``);
        await connection.end();

        const first = spawnDatabaseApply(fileTempDir);
        const deadline = Date.now() + 60000;
        let pausedInsideUncommittedRows = false;
        let rowsVisibleBeforeKill = null;
        let cursorSavedBeforeKill = null;
        let fileByteOffsetSavedBeforeKill = null;
        const targetMonitor = await createMysqlConnection(targetDb);

        try {
            while (Date.now() < deadline) {
                if (first.childProcess.exitCode !== null || first.childProcess.signalCode !== null) {
                    const result = await first.exit;
                    assert.fail(
                        `db-apply exited before its first saved position (${result.code}/${result.signal}):\n`
                        + first.output.stderr + first.output.stdout,
                    );
                }
                const [[running]] = await targetMonitor.query(
                    'SELECT COUNT(*) AS queryCount FROM INFORMATION_SCHEMA.PROCESSLIST '
                    + "WHERE STATE = 'User sleep' "
                    + "AND INFO LIKE '%reprint_db_apply_cursor_rows%' AND ID <> CONNECTION_ID()"
                );
                if (Number(running.queryCount) > 0) {
                    const [[savedPosition]] = await targetMonitor.query(
                        'SELECT COUNT(*) AS positionCount FROM `' + progressTable + '` WHERE id = 1'
                    );
                    if (Number(savedPosition.positionCount) === 1) {
                        const [[savedCursor]] = await targetMonitor.query(
                            'SELECT source_cursor, file_byte_offset FROM `' + progressTable + '` WHERE id = 1'
                        );
                        cursorSavedBeforeKill = savedCursor.source_cursor;
                        fileByteOffsetSavedBeforeKill = Number(savedCursor.file_byte_offset);
                        const [[visibleRows]] = await targetMonitor.query(
                            `SELECT COUNT(*) AS rowCount FROM \`${applyCursorTable}\``
                        );
                        rowsVisibleBeforeKill = Number(visibleRows.rowCount);
                        pausedInsideUncommittedRows = true;
                        break;
                    }
                }
                await sleep(5);
            }
        } finally {
            await targetMonitor.end();
        }

        assert.equal(pausedInsideUncommittedRows, true, 'db-apply did not reach the pause inside the apply-cursor SQL group');
        const savedMarker = sqlGroupMarkers.find((marker) => marker[1] === cursorSavedBeforeKill);
        assert.ok(savedMarker, 'MySQL did not save an exporter cursor from db.sql before the process stopped');
        const expectedFileByteOffset = Buffer.byteLength(
            sqlContents.slice(0, savedMarker.index + savedMarker[0].length) + '\n',
        );
        assert.equal(
            fileByteOffsetSavedBeforeKill,
            expectedFileByteOffset,
            'MySQL did not save the byte immediately after the matching db.sql marker',
        );
        assert.equal(
            rowsVisibleBeforeKill,
            0,
            `source rows became visible before the interrupted SQL group committed (${rowsVisibleBeforeKill} rows)`,
        );
        assert.equal(first.childProcess.kill('SIGKILL'), true);
        const killed = await first.exit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');
        await sleep(100);

        const resumed = runImporter(importUrl(), fileTempDir, 'db-apply', {
            secret: getSiteSecret(site),
            extraArgs: targetMysqlArguments(),
            wallTimeout: 240000,
        });
        assert.equal(
            resumed.exitCode,
            0,
            `resumed db-apply failed:\n${resumed.stderr}\n${resumed.stdout}`,
        );

        const sourceConnection = await createMysqlConnection(getDbName(site));
        let sourceApplyCursorRows;
        try {
            [sourceApplyCursorRows] = await sourceConnection.query(
                `SELECT id, value FROM \`${applyCursorTable}\` ORDER BY id`
            );
        } finally {
            await sourceConnection.end();
        }

        const targetConnection = await createMysqlConnection(targetDb);
        try {
            const [targetApplyCursorRows] = await targetConnection.query(
                `SELECT id, value FROM \`${applyCursorTable}\` ORDER BY id`
            );
            assert.deepEqual(
                targetApplyCursorRows.map(row => ({ id: Number(row.id), value: row.value })),
                sourceApplyCursorRows.map(row => ({ id: Number(row.id), value: row.value })),
                'db-apply did not replay the rolled-back source rows exactly',
            );

            const completedState = JSON.parse(readFileSync(
                join(pullStateDirectory(fileTempDir, importUrl()), 'state.json'),
                'utf8',
            ));
            assert.equal(completedState.apply.bytes_read, 0, 'db-apply copied the MySQL byte offset into local state');
            assert.ok(completedState.apply.statements_executed > 0, 'db-apply did not save the statement count');
            assert.equal(completedState.active_resumable_command.remote_cursor, null);

            const [[remainingProgressTable]] = await targetConnection.query(
                'SELECT COUNT(*) AS tableCount FROM INFORMATION_SCHEMA.TABLES '
                + 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [targetDb, progressTable],
            );
            assert.equal(Number(remainingProgressTable.tableCount), 0, 'db-apply left its cursor table behind');

            const [rows] = await targetConnection.query(
                `SELECT value, value + 0 AS enumIndex FROM \`${sqlModeTable}\``
            );
            assert.equal(rows.length, 1);
            assert.equal(rows[0].value, '');
            assert.equal(Number(rows[0].enumIndex), 0);
        } finally {
            await targetConnection.end();
        }
    });
});
