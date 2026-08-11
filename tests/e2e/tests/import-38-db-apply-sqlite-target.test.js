import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    queryMysqlOnSqlite,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SQLite db-apply target', () => {
    const site = 'basic';
    const targetDb = 'wp_sqlite_import';
    const targetBaseUrl = 'https://sqlite-target.example.com';
    const sourceDomain = new URL(getSiteUrl(site)).origin;
    const expectedTargetUrl = `${new URL(sourceDomain).protocol}//sqlite-target.example.com`;
    let tempDir;
    let sqlitePath;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'sample',
            files: 'sample',
        });

        tempDir = createTempDir('e2e-db-apply-sqlite');
        sqlitePath = join(tempDir, 'target', 'wordpress.sqlite');
    }, 120000);

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('applies db.sql into SQLite via the upstream PDO-compatible driver', () => {
        const syncResult = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(syncResult.exitCode, 0,
            `Expected db-pull exit 0, got ${syncResult.exitCode}\nstderr: ${syncResult.stderr}\nstdout: ${syncResult.stdout}`);

        const applyResult = runImporter(importUrl(), tempDir, 'db-apply', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--target-engine=sqlite',
                `--target-sqlite-path=${sqlitePath}`,
                `--target-db=${targetDb}`,
                '--rewrite-url', sourceDomain, targetBaseUrl,
            ],
        });

        assert.equal(applyResult.exitCode, 0,
            `Expected db-apply exit 0, got ${applyResult.exitCode}\nstderr: ${applyResult.stderr}\nstdout: ${applyResult.stdout}`);
        assert.ok(existsSync(sqlitePath), `Expected SQLite database file at ${sqlitePath}`);

        const tables = queryMysqlOnSqlite(sqlitePath, 'SHOW TABLES', targetDb);
        const tableNames = tables.map(row => Object.values(row)[0]);
        for (const table of ['wp_options', 'wp_posts', 'wp_users']) {
            assert.ok(
                tableNames.includes(table),
                `Expected ${table} in SQLite import, got: ${tableNames.join(', ')}`,
            );
        }

        const options = queryMysqlOnSqlite(
            sqlitePath,
            "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home') ORDER BY option_name",
            targetDb,
        );

        assert.equal(options.length, 2, `Expected siteurl/home rows, got: ${JSON.stringify(options)}`);
        for (const row of options) {
            assert.ok(
                row.option_value.includes(expectedTargetUrl),
                `Expected rewritten target domain in ${row.option_name}, got: ${row.option_value}`,
            );
            assert.ok(
                !row.option_value.includes(sourceDomain),
                `Expected source domain to be removed from ${row.option_name}, got: ${row.option_value}`,
            );
        }
    });
});
