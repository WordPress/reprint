/**
 * A MariaDB source can contain zero-byte spatial values after adding a NOT
 * NULL spatial column to a populated table. The complete pull-db pipeline
 * must move that source into both MariaDB and Oracle MySQL 8 targets.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createConnection } from 'mysql2/promise';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, getDbName,
    createMysqlConnection, assertPullPipelineComplete,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const describeWithMysql8Target = process.env.E2E_MYSQL8_HOST
    ? describe
    : describe.skip;

describeWithMysql8Target(
    'Import: MariaDB zero-byte spatial values across database engines',
    { timeout: 300000 },
    () => {
        const site = 'empty-geometry-cross-engine';
        const geometryTable = 'aa_empty_geometry_upgrade';
        const mysql8Target = {
            name: 'Oracle MySQL 8',
            host: process.env.E2E_MYSQL8_HOST,
            port: Number(process.env.E2E_MYSQL8_PORT || 3306),
            user: process.env.E2E_MYSQL8_USER || 'root',
            password: process.env.E2E_MYSQL8_PASS || '',
            database: 'e2e_empty_geometry_mysql8_import_65',
            versionPattern: /^8\.0\./,
        };
        const targets = [
            {
                name: 'MariaDB',
                host: '127.0.0.1',
                port: 3306,
                user: 'e2e_admin',
                password: 'e2e_password',
                database: 'e2e_empty_geometry_mariadb_import_65',
                versionPattern: /MariaDB/,
            },
            mysql8Target,
        ];
        const tempDirectories = [];

        function importUrl() {
            return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        }

        function targetConnection(target, database = null) {
            return createConnection({
                host: target.host,
                port: target.port,
                user: target.user,
                password: target.password,
                database: database || undefined,
                connectTimeout: 10000,
            });
        }

        function targetArguments(target) {
            return [
                '--target-engine=mysql',
                `--target-host=${target.host}`,
                `--target-port=${target.port}`,
                `--target-user=${target.user}`,
                `--target-pass=${target.password}`,
                `--target-db=${target.database}`,
            ];
        }

        beforeAll(async () => {
            await ensureSite(site, {
                files: 'none',
                customDb: async (_databaseName, connection) => {
                    await connection.query(
                        `CREATE TABLE \`${geometryTable}\` (`
                        + '`id` BIGINT NOT NULL, `label` VARCHAR(64) NOT NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${geometryTable}\` (id, label) VALUES `
                        + "(1, 'zero-byte values'), (2, 'valid values')"
                    );
                    await connection.query(
                        `ALTER TABLE \`${geometryTable}\` `
                        + "ADD COLUMN `location` POINT NOT NULL COMMENT 'Map point', "
                        + "ADD COLUMN `boundary` POLYGON NOT NULL COMMENT 'Map area'"
                    );
                    await connection.query(
                        `UPDATE \`${geometryTable}\` SET `
                        + "location = ST_GeomFromText('POINT(7 8)'), "
                        + "boundary = ST_GeomFromText('POLYGON((0 0,0 2,2 2,0 0))') "
                        + 'WHERE id = 2'
                    );
                },
            });

            const source = await createMysqlConnection(getDbName(site));
            try {
                const [[sourceVersion]] = await source.query('SELECT VERSION() AS version');
                assert.match(
                    sourceVersion.version,
                    /MariaDB/,
                    `The source must be MariaDB, got ${sourceVersion.version}`,
                );
                const [sourceRows] = await source.query(
                    `SELECT id, OCTET_LENGTH(CAST(location AS BINARY)) AS locationBytes, `
                    + 'OCTET_LENGTH(CAST(boundary AS BINARY)) AS boundaryBytes '
                    + `FROM \`${geometryTable}\` ORDER BY id`
                );
                assert.equal(Number(sourceRows[0].locationBytes), 0);
                assert.equal(Number(sourceRows[0].boundaryBytes), 0);
                assert.ok(Number(sourceRows[1].locationBytes) > 0);
                assert.ok(Number(sourceRows[1].boundaryBytes) > 0);
            } finally {
                await source.end();
            }

            writeTestHooks(site, `
function test_hook_after_gzip_init($gz, $boundary) {
    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';
    $state = json_decode(file_get_contents($state_file), true);
    $state['sql_requests'] = ($state['sql_requests'] ?? 0) + 1;
    e2e_write_hook_state($state_file, $state);
}

function test_hook_before_sql_batch(&$sql, $cursor) {
    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';
    $state = json_decode(file_get_contents($state_file), true);
    $is_nullable_spatial_alter =
        strpos($sql, 'ALTER TABLE \`${geometryTable}\`') !== false &&
        strpos($sql, 'MODIFY COLUMN \`location\`') !== false &&
        strpos($sql, 'MODIFY COLUMN \`boundary\`') !== false;

    if ($is_nullable_spatial_alter) {
        $state['alter_batches'] = ($state['alter_batches'] ?? 0) + 1;
        $state['alter_request'] = $state['sql_requests'];
        if (empty($state['pause_injected'])) {
            $state['pause_injected'] = true;
            e2e_write_hook_state($state_file, $state);
            usleep(1100000);
            return;
        }
    } elseif (
        !empty($state['alter_request']) &&
        $state['sql_requests'] > $state['alter_request']
    ) {
        $state['resumed_after_alter'] = true;
    }

    e2e_write_hook_state($state_file, $state);
}
`);
        });

        afterAll(async () => {
            removeTestHooks(site);
            clearHookState(site);
            for (const directory of tempDirectories) {
                cleanupTempDir(directory);
            }
            for (const target of targets) {
                try {
                    const connection = await targetConnection(target);
                    await connection.query(`DROP DATABASE IF EXISTS \`${target.database}\``);
                    await connection.end();
                } catch {
                    // Keep cleanup from hiding the test failure.
                }
            }
        });

        for (const target of targets) {
            it(`runs pull-db from MariaDB into ${target.name}`, async () => {
                const tempDirectory = createTempDir(
                    `e2e-empty-geometry-${target.name.toLowerCase().replaceAll(' ', '-')}`
                );
                tempDirectories.push(tempDirectory);
                clearHookState(site);
                writeHookState(site, {
                    sql_requests: 0,
                    alter_batches: 0,
                    pause_injected: false,
                    resumed_after_alter: false,
                });

                const admin = await targetConnection(target);
                try {
                    const [[targetVersion]] = await admin.query('SELECT VERSION() AS version');
                    assert.match(
                        targetVersion.version,
                        target.versionPattern,
                        `Expected ${target.name}, got ${targetVersion.version}`,
                    );
                    if (target === mysql8Target) {
                        assert.doesNotMatch(targetVersion.version, /MariaDB/);
                    }
                    await admin.query(`DROP DATABASE IF EXISTS \`${target.database}\``);
                    await admin.query(`CREATE DATABASE \`${target.database}\``);
                } finally {
                    await admin.end();
                }

                const result = runImporter(importUrl(), tempDirectory, 'pull-db', {
                    secret: getSiteSecret(site),
                    timeout: 240000,
                    wallTimeout: 300000,
                    maxResumeAttempts: 100,
                    extraArgs: [
                        ...targetArguments(target),
                        '--max-exec=1',
                        '--sql-fragments-start=1',
                        '--sql-fragments-min=1',
                        '--sql-fragments-max=1',
                    ],
                });
                assert.equal(
                    result.exitCode,
                    0,
                    `pull-db into ${target.name} failed (exit ${result.exitCode})\n`
                    + `stderr: ${result.stderr.slice(-6000)}\n`
                    + `stdout: ${result.stdout.slice(-6000)}`,
                );

                const state = JSON.parse(readFileSync(
                    join(pullStateDirectory(tempDirectory, importUrl()), 'state.json'),
                    'utf-8',
                ));
                assertPullPipelineComplete(state, 'pull-db');

                const hookState = readHookState(site);
                assert.equal(hookState?.pause_injected, true);
                assert.equal(hookState?.alter_batches, 1,
                    'The nullable spatial ALTER should be exported once');
                assert.equal(hookState?.resumed_after_alter, true,
                    'The source did not continue from a later request after the ALTER');
                assert.ok(hookState?.sql_requests >= 2,
                    `Expected at least two SQL requests, got ${hookState?.sql_requests}`);

                const targetDatabase = await targetConnection(target, target.database);
                try {
                    const [rows] = await targetDatabase.query(
                        `SELECT id, label, ST_AsText(location) AS location, `
                        + `ST_AsText(boundary) AS boundary FROM \`${geometryTable}\` ORDER BY id`
                    );
                    assert.deepEqual(rows, [
                        {
                            id: 1,
                            label: 'zero-byte values',
                            location: null,
                            boundary: null,
                        },
                        {
                            id: 2,
                            label: 'valid values',
                            location: 'POINT(7 8)',
                            boundary: 'POLYGON((0 0,0 2,2 2,0 0))',
                        },
                    ]);

                    const [columns] = await targetDatabase.query(
                        `SHOW FULL COLUMNS FROM \`${geometryTable}\``
                    );
                    const columnsByName = Object.fromEntries(
                        columns.map(column => [column.Field, column])
                    );
                    assert.equal(columnsByName.location.Null, 'YES');
                    assert.equal(columnsByName.location.Comment, 'Map point');
                    assert.equal(columnsByName.boundary.Null, 'YES');
                    assert.equal(columnsByName.boundary.Comment, 'Map area');
                } finally {
                    await targetDatabase.end();
                }
            });
        }
    },
);
