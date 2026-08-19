/**
 * Test 02: SQL Sync via import.php
 * Tests db-pull and db-index commands produce correct output.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, compareDatabases, createMysqlConnection, apiRequest,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

// Run the same export twice: once on a site with ext-pdo_mysql, and once on a
// site whose PHP-FPM master was started without it, where create_db_connection()
// exports through WordPress's own $wpdb instead. Both must produce the same dump.
describe.each([
    ['basic', 'e2e_basic_import_02'],
    ['basic-no-pdo-mysql', 'e2e_basic_no_pdo_mysql_import_02'],
])('Import: SQL Sync (%s)', (site, importDb) => {
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir(`e2e-import-sql-${site}`);
        // Ensure import DB doesn't exist
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    // Guards the setup rather than the export. If a -no-pdo-mysql site were
    // ever served by the ordinary FPM master — a renamed ini, a runner that
    // doesn't read noPdoMysql — everything below would still pass while
    // exercising the PDO path, and the wpdb route would silently lose its
    // coverage. pdo_mysql being absent is what sends create_db_connection()
    // through $wpdb, so asserting the extension and a working database
    // together pins which route the export took.
    it('exports over the database driver this site is meant to use', async () => {
        const response = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        const preflight = response.json;
        const hasPdoMysql = preflight.php.extensions.includes('pdo_mysql');

        if (site.endsWith('-no-pdo-mysql')) {
            assert.equal(hasPdoMysql, false,
                'Expected pdo_mysql to be absent so the export runs through $wpdb; ' +
                'this site is being served by the wrong PHP-FPM master.');
        } else {
            assert.equal(hasPdoMysql, true,
                'Expected pdo_mysql to be present for the ordinary PDO path.');
        }

        assert.ok(preflight.database.connected,
            `Expected a usable database, got error: ${preflight.database.error}`);
        assert.ok(preflight.database.can_query, 'Expected can_query=true');
    });

    it('db-pull completes and produces db.sql', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        assert.ok(sql.includes('INSERT INTO'), 'Expected INSERT INTO in db.sql');
    });

    it('imported database matches source', async () => {
        const conn = await createMysqlConnection();
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        const sqlFile = join(tempDir, 'db.sql');
        execSync(`mysql -u e2e_admin -pe2e_password -h 127.0.0.1 ${importDb} < ${JSON.stringify(sqlFile)}`, {
            timeout: 30000,
            stdio: 'pipe',
        });

        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });

    it('db-index produces db-tables.jsonl with table names', () => {
        const pfDir = createTempDir('e2e-import-sqlpf');
        try {
            const result = runImporter(importUrl(), pfDir, 'db-index', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}`);

            const tablesFile = join(pfDir, 'db-tables.jsonl');
            assert.ok(existsSync(tablesFile), 'Expected db-tables.jsonl to exist');

            const lines = readFileSync(tablesFile, 'utf-8').trim().split('\n').filter(l => l);
            assert.ok(lines.length > 0, 'Expected at least one table entry');

            const tables = lines.map(l => JSON.parse(l));
            const tableNames = tables.map(t => t.table || t.name || t.TABLE_NAME || Object.values(t)[0]);
            assert.ok(tableNames.some(n => typeof n === 'string' && n.includes('wp_')),
                `Expected wp_ prefixed table names, got: ${JSON.stringify(tableNames.slice(0, 3))}`);
        } finally {
            cleanupTempDir(pfDir);
        }
    });

    it('re-running db-pull without --abort fails with useful message', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code');
        const output = result.stdout + result.stderr;
        assert.ok(output.includes('--abort'), `Expected message mentioning --abort, got: ${output}`);
    });
});
