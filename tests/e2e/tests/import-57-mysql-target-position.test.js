/**
 * A MySQL target records the source position in the same transaction as each
 * completed INSERT. A replacement process still starts the dump from the
 * beginning until table replacement can make target-side continuation safe.
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
    clearHookState,
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
            '    usleep(5000);',
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

    it('records committed rows and safely starts a replacement process over', async () => {
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

        assert.equal(first.childProcess.kill('SIGKILL'), true);
        const killed = await first.exit;
        assert.equal(killed.code, null);
        assert.equal(killed.signal, 'SIGKILL');

        await sleep(100);
        removeTestHooks(site);

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
