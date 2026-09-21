/** A real MySQL 5.5 WordPress source must import without changing destination SQL encoding. */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createConnection } from 'mysql2/promise';
import {
    runImporter, createTempDir, cleanupTempDir, getSiteUrl, getSiteSecret, getSiteDir,
    createMysqlConnection, assertPullPipelineComplete, assertSiteMirror, fsRootDir,
    pullStateDirectory, assertTreesMatch, writeTestHooks, removeTestHooks,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { runWp } from '../lib/multisite-setup.js';

const describeWithMysql55 = process.env.E2E_MYSQL55_HOST ? describe : describe.skip;

describeWithMysql55.each([
    'mysql55-source',
    'mysql55-source-no-pdo-mysql',
])('Import: MySQL 5.5 source (%s)', { timeout: 300000 }, (site) => {
    const sourceDatabase = `e2e_${site.replaceAll('-', '_')}`;
    const targetDatabase = `${sourceDatabase}_import`;
    const sourceOptions = {
        host: process.env.E2E_MYSQL55_HOST,
        port: Number(process.env.E2E_MYSQL55_PORT || 3306),
        user: process.env.E2E_MYSQL55_USER || 'root',
        password: process.env.E2E_MYSQL55_PASS || '',
        multipleStatements: true,
    };
    let source;
    let target;
    let outputDirectory;
    const url = () => `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;

    beforeAll(async () => {
        source = await createConnection(sourceOptions);
        const [[version]] = await source.query('SELECT VERSION() AS version');
        assert.match(version.version, /^5\.5\./, 'This test requires real MySQL 5.5');
        assert.doesNotMatch(version.version, /MariaDB/);
        await source.query(`CREATE DATABASE IF NOT EXISTS ${sourceDatabase}; USE ${sourceDatabase}`);
        await ensureSite(site, {
            db: 'none', tablePrefix: 'rp_',
            wpConfig: {
                DB_HOST: `${sourceOptions.host}:${sourceOptions.port}`,
                DB_NAME: sourceDatabase, DB_USER: sourceOptions.user, DB_PASSWORD: sourceOptions.password,
            },
            afterCreate: directory => {
                runWp(directory, ['core', 'install', `--url=${new URL(getSiteUrl(site)).origin}`,
                    '--title=MySQL 5.5 import', '--admin_user=admin', '--admin_password=fixture-password',
                    '--admin_email=admin@example.test', '--skip-email']);
                runWp(directory, ['plugin', 'activate', 'reprint-server']);
            },
        });
        // Rebuild only this test's data so a failed import can be rerun locally.
        await source.query(`DELETE FROM rp_postmeta WHERE post_id = 99001;
            INSERT INTO rp_postmeta (post_id, meta_key, meta_value) VALUES
            (99001, '_edit_lock', '123:1'), (99001, '_edit_last', '1'),
            (99001, NULL, 'keep null'), (99001, '_edit_lock_extra', 'keep similar');
            DROP TABLE IF EXISTS rp_legacy_keys;
            CREATE TABLE rp_legacy_keys (
                text_key VARCHAR(60) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
                binary_key VARBINARY(20) NOT NULL,
                payload LONGBLOB, amount DECIMAL(30,10), label ENUM('z','a'), flags SET('z','a'),
                happened DATETIME, shape GEOMETRY, PRIMARY KEY (text_key, binary_key)
            ) ENGINE=MyISAM`);
        // More than one 250-row query, with composite text/binary keys, raw
        // latin1, quote/backslash/NUL bytes, and a value larger than a SQL group.
        for (let index = 0; index < 510; index++) {
            const textKey = Buffer.from(`${String(Math.floor(index / 2)).padStart(4, '0')}-é`, 'latin1');
            const binaryKey = Buffer.from([index % 2, 0, 39, 92, 255]);
            const payload = Buffer.from([0, index % 256, 255, 39, 92]);
            await source.query(`INSERT INTO rp_legacy_keys VALUES (?, ?, ?, ?, ?, ?, ?, GeomFromText('POINT(1 2)'))`,
                [textKey, binaryKey, payload, '12345678901234567890.1234567890', 'a', 'z,a', '2001-02-03 04:05:06']);
        }
        await source.query("UPDATE rp_legacy_keys SET payload = REPEAT(UNHEX('ff'), 900000) WHERE text_key = UNHEX('303132352de9') AND binary_key = UNHEX('0100275cff')");
        await source.query(`DROP TABLE IF EXISTS rp_url_keys;
            CREATE TABLE rp_url_keys (url VARCHAR(190) PRIMARY KEY, payload LONGBLOB) ENGINE=InnoDB`);
        await source.query("INSERT INTO rp_url_keys VALUES (?, REPEAT(UNHEX('fe'), 900000))",
            [`${new URL(getSiteUrl(site)).origin}/oversized`]);
        target = await createMysqlConnection();
        await target.query(`DROP DATABASE IF EXISTS ${targetDatabase}`);
        await target.query(`CREATE DATABASE ${targetDatabase}`);
        await target.query(`USE ${targetDatabase}`);
        outputDirectory = createTempDir('e2e-mysql55');
        // Let a real response exceed its time budget at a complete SQL group.
        // The next HTTP request must rebuild the old server's key comparisons.
        writeTestHooks(site, `
            function test_hook_before_sql_batch(&$sql, $cursor) {
                if (strpos($sql, 'INSERT INTO \`rp_legacy_keys\`') !== false
                    || strpos($sql, 'UPDATE \`rp_legacy_keys\`') !== false) {
                    usleep(1100000);
                }
            }
        `);
    });

    afterAll(async () => {
        removeTestHooks(site);
        cleanupTempDir(outputDirectory);
        if (target) {
            await target.query(`DROP DATABASE IF EXISTS ${targetDatabase}`);
            await target.end();
        }
        if (source) await source.end();
    });

    it('imports the full site, including files, row exclusions, key cursors and oversized values', async () => {
        const result = runImporter(url(), outputDirectory, 'pull', {
            secret: getSiteSecret(site), timeout: 120000, wallTimeout: 300000,
            extraArgs: [
                '--target-user=e2e_admin', '--target-pass=e2e_password', `--target-db=${targetDatabase}`,
                '--new-site-url=http://localhost:9999', '--runtime=none',
                '--max-exec=1', '--sql-fragments-start=5', '--sql-fragments-min=5', '--sql-fragments-max=5',
            ],
        });
        assert.equal(result.exitCode, 0, `stdout: ${result.stdout}\nstderr: ${result.stderr}`);
        const state = JSON.parse(readFileSync(join(pullStateDirectory(outputDirectory, url()), 'state.json'), 'utf8'));
        assertPullPipelineComplete(state);
        assert.match(state.preflight.data.database.version, /^5\.5\./);
        assert.equal(state.preflight.data.php.extensions.includes('pdo_mysql'),
            !site.endsWith('-no-pdo-mysql'), 'Exercise both PDO and wpdb source connections');
        const importedRoot = join(fsRootDir(outputDirectory), getSiteDir(site));
        assertSiteMirror(importedRoot);
        assertTreesMatch(join(getSiteDir(site), 'test-data'), join(importedRoot, 'test-data'));

        const [metadata] = await target.query('SELECT meta_key, meta_value FROM rp_postmeta WHERE post_id = 99001 ORDER BY meta_id');
        assert.deepEqual(metadata.map(row => ({ ...row })), [
            { meta_key: '_edit_last', meta_value: '1' },
            { meta_key: null, meta_value: 'keep null' },
            { meta_key: '_edit_lock_extra', meta_value: 'keep similar' },
        ]);
        const comparisonQuery = `SELECT HEX(text_key) AS text_key, HEX(binary_key) AS binary_key,
            MD5(payload) AS payload_hash, OCTET_LENGTH(payload) AS payload_length, CAST(amount AS CHAR) AS amount, label, flags,
            CAST(happened AS CHAR) AS happened, HEX(shape) AS shape
            FROM rp_legacy_keys ORDER BY text_key, binary_key`;
        const [sourceRows] = await source.query(comparisonQuery);
        const [targetRows] = await target.query(comparisonQuery);
        assert.equal(targetRows.length, 510);
        assert.deepEqual(targetRows, sourceRows);
        const [[options]] = await target.query("SELECT option_value FROM rp_options WHERE option_name = 'home'");
        assert.equal(options.option_value, 'http://localhost:9999');
        const [[sourcePayload]] = await source.query('SELECT MD5(payload) AS hash FROM rp_url_keys');
        const [[targetPayload]] = await target.query('SELECT url, MD5(payload) AS hash FROM rp_url_keys');
        assert.equal(targetPayload.url, 'http://localhost:9999/oversized');
        assert.equal(targetPayload.hash, sourcePayload.hash,
            'Oversized UPDATEs must find the row after its URL primary key is rewritten');
        const sql = readFileSync(join(outputDirectory, 'db.sql'), 'utf8');
        assert.match(sql, /FROM_BASE64\(/, 'Destination SQL must keep base64 encoding');
        assert.doesNotMatch(sql, /UNHEX\(/, 'Source fallback must not change destination UPDATE keys');
        assert.match(sql, /UPDATE `rp_legacy_keys`/, 'The fixture must exercise oversized-value updates');
        const audit = readFileSync(join(outputDirectory, 'audit.log'), 'utf8');
        assert.ok((audit.match(/TUNER REQUEST \| endpoint=sql_chunk/g) || []).length > 1,
            'The import must resume across multiple HTTP requests');
    });

    it('streams directly into MySQL without a SQL file', async () => {
        const directory = createTempDir('e2e-mysql55-direct');
        try {
            const result = runImporter(url(), directory, 'db-pull', {
                secret: getSiteSecret(site), timeout: 120000, wallTimeout: 300000,
                extraArgs: ['--sql-output=mysql', '--mysql-user=e2e_admin',
                    '--mysql-password=e2e_password', `--mysql-database=${targetDatabase}`,
                    '--max-exec=1', '--sql-fragments-start=5', '--sql-fragments-min=5', '--sql-fragments-max=5'],
            });
            assert.equal(result.exitCode, 0, `stdout: ${result.stdout}\nstderr: ${result.stderr}`);
            const [sourceRows] = await source.query(
                'SELECT HEX(text_key) AS name, HEX(binary_key) AS suffix, MD5(payload) AS payload FROM rp_legacy_keys ORDER BY text_key, binary_key');
            const [targetRows] = await target.query(
                'SELECT HEX(text_key) AS name, HEX(binary_key) AS suffix, MD5(payload) AS payload FROM rp_legacy_keys ORDER BY text_key, binary_key');
            assert.equal(targetRows.length, 510);
            assert.deepEqual(targetRows, sourceRows);
            const [[locks]] = await target.query("SELECT COUNT(*) AS count FROM rp_postmeta WHERE meta_key = '_edit_lock'");
            assert.equal(Number(locks.count), 0);
        } finally {
            cleanupTempDir(directory);
        }
    });
});
