/**
 * Test 45: SiteGround host-specific plugin stripping
 *
 * Simulates a SiteGround hosting environment where sg-cachepress and
 * sg-security are installed and active.  Verifies that after the full
 * import pipeline (preflight -> files-pull -> db-pull -> db-apply ->
 * apply-runtime), both plugins are:
 *   1. Deactivated in the target database during db-apply
 *   2. Removed from the local filesystem during apply-runtime
 *   3. Unrelated plugins are preserved
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    getDbName, createMysqlConnection, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'packages', 'reprint-client', 'bin', 'reprint-client');

describe('Import: SiteGround plugin stripping', () => {
    const site = 'siteground-plugins';
    let tempDir;
    let runtimeDir;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'standard',
            files: 'sample',
            afterCreate: async (siteDir, dbName) => {
                // Create fake sg-cachepress plugin with a valid plugin header
                // so WordPress recognizes it as a plugin.
                const pluginsDir = join(siteDir, 'wp-content', 'plugins');

                mkdirSync(join(pluginsDir, 'sg-cachepress'), { recursive: true });
                writeFileSync(join(pluginsDir, 'sg-cachepress', 'sg-cachepress.php'),
`<?php
/**
 * Plugin Name: SG CachePress (fake)
 * Description: Fake SiteGround caching plugin for E2E testing
 * Version: 1.0.0
 */
`);

                mkdirSync(join(pluginsDir, 'sg-security'), { recursive: true });
                writeFileSync(join(pluginsDir, 'sg-security', 'sg-security.php'),
`<?php
/**
 * Plugin Name: SG Security (fake)
 * Description: Fake SiteGround security plugin for E2E testing
 * Version: 1.0.0
 */
`);

                // Activate both plugins via the database so they appear
                // in active_plugins when the DB is exported.
                const { createConnection } = await import('mysql2/promise');
                const conn = await createConnection({
                    host: '127.0.0.1',
                    user: 'e2e_admin',
                    password: 'e2e_password',
                    database: dbName,
                });
                const [rows] = await conn.query(
                    "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'"
                );
                let plugins = [];
                if (rows.length > 0 && rows[0].option_value) {
                    // Parse PHP serialized array using a simple regex approach.
                    // We know the format is a:N:{...} with s:LEN:"value"; entries.
                    const raw = rows[0].option_value;
                    const matches = [...raw.matchAll(/s:\d+:"([^"]+)"/g)];
                    plugins = matches.map(m => m[1]);
                }
                plugins.push('sg-cachepress/sg-cachepress.php');
                plugins.push('sg-security/sg-security.php');

                // Serialize as PHP array: a:N:{i:0;s:LEN:"value";...}
                const entries = plugins.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('');
                const serialized = `a:${plugins.length}:{${entries}}`;

                await conn.query(
                    "UPDATE wp_options SET option_value = ? WHERE option_name = 'active_plugins'",
                    [serialized]
                );
                await conn.end();
            },
        });

        tempDir = createTempDir('e2e-siteground-plugins');
        runtimeDir = join(tempDir, 'runtime');
    }, 120000);

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('preflight detects the site as siteground', () => {
        const result = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `preflight failed:\n${result.stderr}`);

        const state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, importUrl()), 'state.json'),
            'utf-8',
        ));
        assert.equal(state.webhost, 'siteground',
            `Expected webhost 'siteground', got '${state.webhost}'`);
    });

    it('files-pull downloads the site', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `files-pull failed:\n${result.stderr}`);
    });

    it('db-pull downloads the SQL dump', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `db-pull failed:\n${result.stderr}`);
        assert.ok(existsSync(join(tempDir, 'db.sql')), 'db.sql should exist');
    });

    describe('db-apply deactivates SG plugins', () => {
        beforeAll(async () => {
            // Create the target database before db-apply connects to it.
            const importDb = `${getDbName(site)}_import`;
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.query(`CREATE DATABASE \`${importDb}\``);
            await conn.end();

            const sourceDomain = new URL(getSiteUrl(site)).origin;
            const result = runImporter(importUrl(), tempDir, 'db-apply', {
                secret: getSiteSecret(site),
                extraArgs: [
                    '--target-engine=mysql',
                    `--target-db=${getDbName(site)}_import`,
                    '--target-user=e2e_admin',
                    '--target-pass=e2e_password',
                    '--target-host=127.0.0.1',
                    '--rewrite-url', sourceDomain, `http://127.0.0.1:9999`,
                ],
            });
            assert.equal(result.exitCode, 0, `db-apply failed:\n${result.stderr}`);
        });

        it('sg plugins are removed from active_plugins', () => {
            const importDb = `${getDbName(site)}_import`;

            // Query using PHP to use the same MySQL driver as the importer.
            const raw = execFileSync('php', ['-r', `
                \$pdo = new PDO('mysql:host=127.0.0.1;dbname=${importDb};charset=utf8mb4', 'e2e_admin', 'e2e_password');
                \$stmt = \$pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'");
                echo \$stmt->fetchColumn();
            `], { encoding: 'utf-8', timeout: 10000 }).trim();

            assert.ok(raw.length > 0, 'active_plugins option should exist');
            assert.ok(
                !raw.includes('sg-cachepress'),
                `active_plugins should not contain sg-cachepress, got: ${raw}`,
            );
            assert.ok(
                !raw.includes('sg-security'),
                `active_plugins should not contain sg-security, got: ${raw}`,
            );
            // Confirm non-SG plugins survived.
            assert.ok(
                raw.includes('site-export'),
                `active_plugins should still contain site-export, got: ${raw}`,
            );
        });

        it('audit log records the deactivations', () => {
            const auditLog = readFileSync(join(tempDir, 'audit.log'), 'utf-8');
            assert.ok(
                auditLog.includes('deactivated plugin') && auditLog.includes('sg-cachepress'),
                'audit log should record sg-cachepress deactivation',
            );
            assert.ok(
                auditLog.includes('deactivated plugin') && auditLog.includes('sg-security'),
                'audit log should record sg-security deactivation',
            );
        });
    });

    describe('apply-runtime removes SG plugin files', () => {
        beforeAll(() => {
            const flatDir = join(tempDir, 'flattened');
            const flatResult = runImporter(importUrl(), tempDir, 'flat-docroot', {
                secret: getSiteSecret(site),
                extraArgs: [`--flatten-to=${flatDir}`],
            });
            assert.equal(flatResult.exitCode, 0,
                `flat-docroot failed:\n${flatResult.stderr}`);

            execFileSync('php', [
                IMPORTER_PATH,
                'apply-runtime',
                importUrl(),
                `--state-dir=${tempDir}`,
                `--flat-document-root=${flatDir}`,
                `--runtime=php-builtin`,
                `--output-dir=${runtimeDir}`,
                `--port=9999`,
            ], {
                encoding: 'utf-8',
                timeout: 30000,
            });
        });

        it('sg-cachepress directory is removed from disk', () => {
            const flatDir = join(tempDir, 'flattened');
            assert.ok(
                !existsSync(join(flatDir, 'wp-content', 'plugins', 'sg-cachepress')),
                'sg-cachepress directory should not exist after apply-runtime',
            );
        });

        it('sg-security directory is removed from disk', () => {
            const flatDir = join(tempDir, 'flattened');
            assert.ok(
                !existsSync(join(flatDir, 'wp-content', 'plugins', 'sg-security')),
                'sg-security directory should not exist after apply-runtime',
            );
        });

        it('unrelated plugins are preserved on disk', () => {
            const flatDir = join(tempDir, 'flattened');
            assert.ok(
                existsSync(join(flatDir, 'wp-content', 'plugins', 'site-export')),
                'Reprint Server plugin should still exist after apply-runtime',
            );
        });
    });
});
