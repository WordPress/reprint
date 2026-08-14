/**
 * A new MySQL process resumes after the dump header, but still needs the
 * header's connection settings before it executes later table data.
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
    const sqlModeTable = 'zz_session_setup_sql_mode';
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
            '--max-exec=1',
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
            '        file_put_contents($state_file, json_encode($state));',
            '        do {',
            '            usleep(10000);',
            '            $state = json_decode(file_get_contents($state_file), true);',
            '        } while (empty($state[\'release\']));',
            '    } else {',
            '        file_put_contents($state_file, json_encode($state));',
            '    }',
            '}',
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = json_decode(file_get_contents($state_file), true);',
            '    $state[\'batches\'] = ($state[\'batches\'] ?? 0) + 1;',
            '    file_put_contents($state_file, json_encode($state));',
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
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${targetDb}\``);
        await connection.end();
    });

    it('runs the dump connection settings before resumed SQL', async () => {
        const first = spawnDatabasePull();
        const statePath = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
        const deadline = Date.now() + 60000;
        let savedCursor = null;

        while (Date.now() < deadline) {
            if (first.childProcess.exitCode !== null || first.childProcess.signalCode !== null) {
                const result = await first.exit;
                assert.fail(
                    `db-pull exited before the source paused (${result.code}/${result.signal}):\n`
                    + first.output.stderr + first.output.stdout,
                );
            }

            const hookState = readHookState(site);
            if (existsSync(statePath)) {
                const state = JSON.parse(readFileSync(statePath, 'utf8'));
                savedCursor = state.active_resumable_command?.remote_cursor || null;
            }
            if (hookState?.paused && savedCursor) {
                break;
            }
            await sleep(25);
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
});
