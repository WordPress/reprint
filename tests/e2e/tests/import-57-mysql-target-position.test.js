/**
 * A MySQL target records the source position in the same transaction as each
 * completed InnoDB INSERT. A replacement process continues after that position
 * without dropping and rebuilding the table.
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

describeWithHostPhpProcess('Import: source position saved in MySQL target', { timeout: 300000 }, () => {
    const site = 'mysql-target-cursor-resume';
    const sourceTable = 'aa_target_cursor_rows';
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
                    + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                );
                const rows = Array.from({ length: 600 }, (_, index) => [
                    index + 1,
                    `target-cursor-row-${index + 1}`,
                ]);
                for (let offset = 0; offset < rows.length; offset += 100) {
                    await connection.query(
                        `INSERT INTO \`${sourceTable}\` (id, value) VALUES ?`,
                        [rows.slice(offset, offset + 100)],
                    );
                }
            },
        });

        tempDir = createTempDir('e2e-mysql-target-cursor-resume');
        clearHookState(site);
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            '    static $sent_complete_target_insert = false;',
            '    static $paused_after_target_insert = false;',
            '    if ($sent_complete_target_insert && !$paused_after_target_insert) {',
            '        $paused_after_target_insert = true;',
            '        usleep(10000000);',
            '        return;',
            '    }',
            `    if (strpos($sql, 'INSERT INTO \`${sourceTable}\`') !== false`,
            `        && substr(rtrim($sql), -1) === ';') {`,
            '        $sent_complete_target_insert = true;',
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

    it('refuses any capitalization of the progress-table name in the source', async () => {
        const collisionDir = createTempDir('e2e-mysql-source-cursor-collision');
        const sourceConnection = await createMysqlConnection(getDbName(site));
        const targetConnection = await createMysqlConnection(targetDb);
        try {
            await sourceConnection.query(
                'CREATE TABLE `__REPRINT_DB_PULL_PROGRESS` ('
                + '`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY, '
                + '`note` VARCHAR(64) NOT NULL) ENGINE=InnoDB'
            );
            await sourceConnection.query(
                "INSERT INTO `__REPRINT_DB_PULL_PROGRESS` (id, note) VALUES (1, 'source row')"
            );

            const preflight = runImporter(importUrl(), collisionDir, 'preflight', {
                secret: getSiteSecret(site),
            });
            assert.equal(preflight.exitCode, 0, preflight.stderr + preflight.stdout);

            const result = runImporter(importUrl(), collisionDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArguments(),
            });
            assert.notEqual(result.exitCode, 0, 'db-pull should reject the reserved source table');

            const [[targetTable]] = await targetConnection.query(
                'SELECT COUNT(*) AS tableCount FROM INFORMATION_SCHEMA.TABLES '
                + 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [targetDb, '__reprint_db_pull_progress'],
            );
            assert.equal(Number(targetTable.tableCount), 0);
        } finally {
            await sourceConnection.query('DROP TABLE IF EXISTS `__REPRINT_DB_PULL_PROGRESS`');
            await sourceConnection.end();
            await targetConnection.end();
            cleanupTempDir(collisionDir);
        }
    });

    it('does not alter an unrelated target table with the progress-table name', async () => {
        const collisionDir = createTempDir('e2e-mysql-target-cursor-collision');
        const targetConnection = await createMysqlConnection(targetDb);
        try {
            await targetConnection.query(
                'CREATE TABLE `__reprint_db_pull_progress` ('
                + '`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY, '
                + '`note` VARCHAR(64) NOT NULL) ENGINE=InnoDB'
            );
            await targetConnection.query(
                "INSERT INTO `__reprint_db_pull_progress` (id, note) VALUES (1, 'keep this row')"
            );

            const preflight = runImporter(importUrl(), collisionDir, 'preflight', {
                secret: getSiteSecret(site),
            });
            assert.equal(preflight.exitCode, 0, preflight.stderr + preflight.stdout);

            const result = runImporter(importUrl(), collisionDir, 'db-pull', {
                secret: getSiteSecret(site),
                extraArgs: mysqlArguments(),
            });
            assert.notEqual(result.exitCode, 0, 'db-pull should reject the table-name collision');

            const [[row]] = await targetConnection.query(
                'SELECT note FROM `__reprint_db_pull_progress` WHERE id = 1'
            );
            assert.equal(row.note, 'keep this row');
        } finally {
            await targetConnection.query('DROP TABLE IF EXISTS `__reprint_db_pull_progress`');
            await targetConnection.end();
            cleanupTempDir(collisionDir);
        }
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

    it('continues an InnoDB table after its last committed SQL group', async () => {
        const first = spawnDatabasePull();

        // Kill only after another MySQL connection can see both the imported
        // rows and the position committed with them.
        const interruptedTarget = await createMysqlConnection(targetDb);
        let importedRowCount = 0;
        let savedSourceCursor = null;
        try {
            const targetDeadline = Date.now() + 60000;
            while (Date.now() < targetDeadline) {
                try {
                    const [[importedRows]] = await interruptedTarget.query(
                        `SELECT COUNT(*) AS rowCount FROM \`${sourceTable}\``
                    );
                    const [[savedPosition]] = await interruptedTarget.query(
                        'SELECT source_cursor FROM `__reprint_db_pull_progress` WHERE id = 1'
                    );
                    importedRowCount = Number(importedRows.rowCount);
                    savedSourceCursor = savedPosition?.source_cursor ?? null;
                    if (
                        importedRowCount > 0
                        && importedRowCount < 600
                        && typeof savedSourceCursor === 'string'
                        && savedSourceCursor.length > 0
                    ) {
                        break;
                    }
                } catch (error) {
                    if (error?.code !== 'ER_NO_SUCH_TABLE') {
                        throw error;
                    }
                }
                if (first.childProcess.exitCode !== null || first.childProcess.signalCode !== null) {
                    const result = await first.exit;
                    assert.fail(
                        `db-pull exited before MySQL committed partial work (${result.code}/${result.signal}):\n`
                        + first.output.stderr + first.output.stdout,
                    );
                }
                await sleep(25);
            }
        } finally {
            await interruptedTarget.end();
        }
        assert.ok(
            importedRowCount > 0 && importedRowCount < 600,
            `expected a partially imported table, got ${importedRowCount} rows`,
        );
        assert.equal(typeof savedSourceCursor, 'string');
        assert.ok(savedSourceCursor.length > 0);
        const decodedSourceCursor = JSON.parse(
            Buffer.from(savedSourceCursor, 'base64').toString('utf8')
        );
        assert.equal(decodedSourceCursor.current_table, sourceTable);
        assert.deepEqual(decodedSourceCursor.current_pk_columns, ['id']);
        const savedPrimaryKey = decodedSourceCursor.last_pk_values.id;
        const savedPrimaryKeyValue = (
            typeof savedPrimaryKey === 'object'
            && savedPrimaryKey !== null
            && typeof savedPrimaryKey.__binary__ === 'string'
        )
            ? Buffer.from(savedPrimaryKey.__binary__, 'base64').toString('utf8')
            : savedPrimaryKey;
        assert.equal(Number(savedPrimaryKeyValue), importedRowCount);

        assert.equal(first.childProcess.kill('SIGKILL'), true);
        const killed = await first.exit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');

        await sleep(100);
        removeTestHooks(site);

        writeHookState(site, { replacementDrops: 0 });
        writeTestHooks(site, [
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            `    if (strpos($sql, 'DROP TABLE IF EXISTS \`${sourceTable}\`') !== false) {`,
            '        $state[\'replacementDrops\'] = ($state[\'replacementDrops\'] ?? 0) + 1;',
            '        file_put_contents($state_file, json_encode($state));',
            '    }',
            '}',
        ].join('\n'));

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
            readHookState(site)?.replacementDrops,
            0,
            'the replacement process dropped an InnoDB table that had a committed source position',
        );

        const targetConnection = await createMysqlConnection(targetDb);
        try {
            const [summary] = await targetConnection.query(
                `SELECT COUNT(*) AS rowCount, MIN(id) AS firstId, MAX(id) AS lastId `
                + `FROM \`${sourceTable}\``
            );
            assert.deepEqual(
                {
                    rowCount: Number(summary[0].rowCount),
                    firstId: Number(summary[0].firstId),
                    lastId: Number(summary[0].lastId),
                },
                { rowCount: 600, firstId: 1, lastId: 600 },
            );
        } finally {
            await targetConnection.end();
        }
    });
});
