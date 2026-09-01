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
        const spatialColumns = [
            'location',
            'route',
            'boundary',
            'locations',
            'routes',
            'boundaries',
            'shape',
            'shapes',
        ];
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
        let sourceSpatialBytes;

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
                        + '`optional_shape` GEOMETRY NULL, '
                        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
                    );
                    await connection.query(
                        `INSERT INTO \`${geometryTable}\` (id, label) VALUES `
                        + "(1, 'zero-byte values'), (2, 'valid values'), "
                        + "(3, 'empty collection'), (4, 'invalid polygon'), "
                        + "(5, 'more valid values')"
                    );
                    await connection.query(
                        `ALTER TABLE \`${geometryTable}\` `
                        + "ADD COLUMN `location` POINT NOT NULL COMMENT 'Map point', "
                        + 'ADD COLUMN `route` LINESTRING NOT NULL, '
                        + "ADD COLUMN `boundary` POLYGON NOT NULL COMMENT 'Map area', "
                        + 'ADD COLUMN `locations` MULTIPOINT NOT NULL, '
                        + 'ADD COLUMN `routes` MULTILINESTRING NOT NULL, '
                        + 'ADD COLUMN `boundaries` MULTIPOLYGON NOT NULL, '
                        + 'ADD COLUMN `shape` GEOMETRY NOT NULL, '
                        + 'ADD COLUMN `shapes` GEOMETRYCOLLECTION NOT NULL'
                    );
                    await connection.query(
                        `UPDATE \`${geometryTable}\` SET `
                        + "location = ST_GeomFromText('POINT(7 8)', 0), "
                        + "route = ST_GeomFromText('LINESTRING(0 0,1 1,2 1)'), "
                        + "boundary = ST_GeomFromText('POLYGON((0 0,0 2,2 2,0 0))'), "
                        + "locations = ST_GeomFromText('MULTIPOINT(0 0,1 1)'), "
                        + "routes = ST_GeomFromText('MULTILINESTRING((0 0,1 1),(2 2,3 3))'), "
                        + 'boundaries = ST_GeomFromText('
                        + "'MULTIPOLYGON(((0 0,0 1,1 1,0 0)),((2 2,2 3,3 3,2 2)))'), "
                        + "shape = ST_GeomFromText('POINT(9 10)'), "
                        + 'shapes = ST_GeomFromText('
                        + "'GEOMETRYCOLLECTION(POINT(1 2),LINESTRING(0 0,1 1))') "
                        + 'WHERE id IN (2, 3, 4, 5)'
                    );
                    await connection.query(
                        `UPDATE \`${geometryTable}\` SET `
                        + "shapes = ST_GeomFromText('GEOMETRYCOLLECTION()') WHERE id = 3"
                    );
                    await connection.query(
                        `UPDATE \`${geometryTable}\` SET `
                        + 'boundary = ST_GeomFromText('
                        + "'POLYGON((0 0,2 2,0 2,2 0,0 0))') WHERE id = 4"
                    );
                    await connection.query(
                        `UPDATE \`${geometryTable}\` SET `
                        + "optional_shape = ST_GeomFromText('POINT(11 12)') WHERE id = 2"
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
                    `SELECT id, ${spatialColumns.map(column =>
                        `HEX(CAST(\`${column}\` AS BINARY)) AS \`${column}\``
                    ).join(', ')} FROM \`${geometryTable}\` ORDER BY id`
                );
                sourceSpatialBytes = sourceRows;
                for (const column of spatialColumns) {
                    assert.equal(sourceRows[0][column], '');
                    assert.ok(sourceRows[1][column].length > 0);
                }
                assert.ok(sourceRows[2].shapes.length > 0);
                const [[sourceEmptyCollection]] = await source.query(
                    'SELECT ST_AsText(shapes) AS value, '
                    + '(shapes IS NULL) AS is_null, '
                    + '(optional_shape IS NULL) AS optional_is_null '
                    + `FROM \`${geometryTable}\` WHERE id = 3`
                );
                assert.deepEqual(sourceEmptyCollection, {
                    value: 'GEOMETRYCOLLECTION EMPTY',
                    is_null: 0,
                    optional_is_null: 1,
                });
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
            // This exceeds the --max-exec=1 budget below so the ALTER ends
            // one HTTP request and the next SQL fragment requires a resume.
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
                    const [[validValues]] = await targetDatabase.query(
                        `SELECT ${spatialColumns.map(column =>
                            `ST_AsText(\`${column}\`) AS \`${column}\``
                        ).join(', ')} FROM \`${geometryTable}\` WHERE id = 2`
                    );
                    assert.deepEqual(validValues, {
                        location: 'POINT(7 8)',
                        route: 'LINESTRING(0 0,1 1,2 1)',
                        boundary: 'POLYGON((0 0,0 2,2 2,0 0))',
                        locations: target === mysql8Target
                            ? 'MULTIPOINT((0 0),(1 1))'
                            : 'MULTIPOINT(0 0,1 1)',
                        routes: 'MULTILINESTRING((0 0,1 1),(2 2,3 3))',
                        boundaries: 'MULTIPOLYGON(((0 0,0 1,1 1,0 0)),((2 2,2 3,3 3,2 2)))',
                        shape: 'POINT(9 10)',
                        shapes: 'GEOMETRYCOLLECTION(POINT(1 2),LINESTRING(0 0,1 1))',
                    });

                    const [[valueStates]] = await targetDatabase.query(
                        `SELECT ${spatialColumns.map(column =>
                            `(\`${column}\` IS NULL) AS \`${column}\``
                        ).join(', ')}, optional_shape IS NULL AS optional_shape `
                        + `FROM \`${geometryTable}\` WHERE id = 1`
                    );
                    assert.deepEqual(valueStates, target === mysql8Target
                        ? {
                            location: 1,
                            route: 1,
                            boundary: 1,
                            locations: 1,
                            routes: 1,
                            boundaries: 1,
                            shape: 1,
                            shapes: 1,
                            optional_shape: 1,
                        }
                        : {
                            location: 0,
                            route: 0,
                            boundary: 0,
                            locations: 0,
                            routes: 0,
                            boundaries: 0,
                            shape: 0,
                            shapes: 0,
                            optional_shape: 1,
                        });

                    const [[specialValues]] = await targetDatabase.query(
                        'SELECT '
                        + 'ST_AsText(shapes) AS empty_collection, '
                        + '(shapes IS NULL) AS empty_collection_is_null '
                        + `FROM \`${geometryTable}\` WHERE id = 3`
                    );
                    assert.deepEqual(specialValues, {
                        empty_collection: 'GEOMETRYCOLLECTION EMPTY',
                        empty_collection_is_null: 0,
                    });
                    const [[invalidPolygon]] = await targetDatabase.query(
                        `SELECT ST_AsText(boundary) AS value FROM \`${geometryTable}\` WHERE id = 4`
                    );
                    assert.equal(
                        invalidPolygon.value,
                        'POLYGON((0 0,2 2,0 2,2 0,0 0))',
                    );
                    if (target === mysql8Target) {
                        const [[validity]] = await targetDatabase.query(
                            `SELECT ST_IsValid(boundary) AS value `
                            + `FROM \`${geometryTable}\` WHERE id = 4`
                        );
                        assert.equal(validity.value, 0);
                    }

                    const [srids] = await targetDatabase.query(
                        `SELECT id, ST_SRID(location) AS srid FROM \`${geometryTable}\` `
                        + 'WHERE id IN (2, 5) ORDER BY id'
                    );
                    assert.deepEqual(srids, [
                        { id: 2, srid: 0 },
                        { id: 5, srid: 0 },
                    ]);

                    const [targetSpatialBytes] = await targetDatabase.query(
                        `SELECT id, ${spatialColumns.map(column =>
                            `HEX(CAST(\`${column}\` AS BINARY)) AS \`${column}\``
                        ).join(', ')} FROM \`${geometryTable}\` `
                        + `${target === mysql8Target ? 'WHERE id > 1 ' : ''}ORDER BY id`
                    );
                    assert.deepEqual(
                        targetSpatialBytes,
                        target === mysql8Target ? sourceSpatialBytes.slice(1) : sourceSpatialBytes,
                    );

                    const [columns] = await targetDatabase.query(
                        `SHOW FULL COLUMNS FROM \`${geometryTable}\``
                    );
                    const columnsByName = Object.fromEntries(
                        columns.map(column => [column.Field, column])
                    );
                    assert.equal(
                        columnsByName.location.Null,
                        target === mysql8Target ? 'YES' : 'NO',
                    );
                    assert.equal(columnsByName.location.Comment, 'Map point');
                    assert.equal(
                        columnsByName.boundary.Null,
                        target === mysql8Target ? 'YES' : 'NO',
                    );
                    assert.equal(columnsByName.boundary.Comment, 'Map area');
                    for (const column of spatialColumns) {
                        assert.equal(
                            columnsByName[column].Null,
                            target === mysql8Target ? 'YES' : 'NO',
                        );
                    }
                } finally {
                    await targetDatabase.end();
                }
            });
        }

        it('stops MariaDB to MySQL 8 SRID 4326 before changing coordinate meaning', async () => {
            const table = 'zz_axis_order_failure';
            const source = await createMysqlConnection(getDbName(site));
            const failureTarget = {
                ...mysql8Target,
                database: `${mysql8Target.database}_${table}`,
            };
            const tempDirectory = createTempDir(`e2e-spatial-failure-${table}`);
            tempDirectories.push(tempDirectory);
            try {
                await source.query(`DROP TABLE IF EXISTS \`${table}\``);
                await source.query(
                    `CREATE TABLE \`${table}\` (`
                    + '`id` INT PRIMARY KEY, `location` POINT) ENGINE=InnoDB'
                );
                await source.query(
                    `INSERT INTO \`${table}\` VALUES `
                    + "(42, ST_GeomFromText('POINT(7 8)', 4326))"
                );

                const admin = await targetConnection(failureTarget);
                try {
                    await admin.query(`DROP DATABASE IF EXISTS \`${failureTarget.database}\``);
                    await admin.query(`CREATE DATABASE \`${failureTarget.database}\``);
                } finally {
                    await admin.end();
                }

                const run = () => runImporter(importUrl(), tempDirectory, 'pull-db', {
                    secret: getSiteSecret(site),
                    timeout: 240000,
                    wallTimeout: 300000,
                    maxResumeAttempts: 2,
                    extraArgs: targetArguments(failureTarget),
                });
                const first = run();
                assert.notEqual(first.exitCode, 0, 'The unsupported spatial row was imported.');
                const firstOutput = `${first.stderr}\n${first.stdout}`;
                assert.match(firstOutput, /\[SPATIAL_AXIS_ORDER_UNSAFE\]/);
                assert.match(firstOutput, /Table: `zz_axis_order_failure`/);
                assert.match(firstOutput, /Row: `id` = 42/);
                assert.match(firstOutput, /Column: `location`, SRID 4326/);
                assert.match(firstOutput, /Source: MariaDB/);
                assert.match(firstOutput, /Target: MySQL/);
                assert.match(firstOutput, /The row was not inserted\./);

                const resumed = run();
                assert.notEqual(resumed.exitCode, 0, 'Resume skipped the unsupported spatial row.');
                assert.match(`${resumed.stderr}\n${resumed.stdout}`, /\[SPATIAL_AXIS_ORDER_UNSAFE\]/);

                const targetDatabase = await targetConnection(failureTarget, failureTarget.database);
                try {
                    const [[rowCount]] = await targetDatabase.query(
                        `SELECT COUNT(*) AS value FROM \`${table}\``
                    );
                    assert.equal(rowCount.value, 0);
                } finally {
                    await targetDatabase.end();
                }
            } finally {
                await source.query(`DROP TABLE IF EXISTS \`${table}\``);
                await source.end();
                try {
                    const admin = await targetConnection(failureTarget);
                    await admin.query(`DROP DATABASE IF EXISTS \`${failureTarget.database}\``);
                    await admin.end();
                } catch {
                    // Keep cleanup from hiding the test failure.
                }
            }
        });
    },
);
