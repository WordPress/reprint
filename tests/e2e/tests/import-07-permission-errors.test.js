/**
 * Test 07: Permission Errors via import.php
 * Tests chmod-denied file indexing stops at an unreadable directory and
 * mysql-restricted sites complete gracefully.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    readAuditLog,
    pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Permission Errors', () => {
    describe('chmod-denied', () => {
        const site = 'chmod-denied';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site, {
                afterCreate: async (siteDir) => {
                    const dataDir = join(siteDir, 'test-data');
                    writeFileSync(join(dataDir, 'unreadable.txt'), 'secret content');
                    mkdirSync(join(dataDir, 'unreadable-dir'), { recursive: true });
                    writeFileSync(join(dataDir, 'unreadable-dir', 'inside.txt'), 'inside');
                },
                afterPermissions: async (siteDir) => {
                    execSync(`sudo chmod 000 "${siteDir}/test-data/unreadable.txt"`);
                    execSync(`sudo chmod 000 "${siteDir}/test-data/unreadable-dir"`);
                },
            });
            tempDir = createTempDir('e2e-import-chmod');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        }

        it('file sync stops rather than confirming an incomplete remote index', () => {
            const result = runImporter(importUrl(), tempDir, 'files-pull', {
                secret: getSiteSecret(site),
            });
            assert.notEqual(result.exitCode, 0, `Expected file indexing to fail\nstdout: ${result.stdout}`);
            assert.ok(
                result.stderr.includes('Remote file indexing could not scan'),
                `Expected a pointed remote-index error\nstderr: ${result.stderr}`
            );
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('REMOTE ERROR'), 'Expected REMOTE ERROR in audit log');
            assert.ok(audit.includes('type=dir_open'), 'Expected type=dir_open in audit log');
            const unreadableDirectory = join(getSiteDir(site), 'test-data', 'unreadable-dir');
            assert.ok(
                audit.includes(Buffer.from(unreadableDirectory).toString('base64')),
                'Expected the unreadable directory path in the audit log'
            );
        });
    });

    describe('mysql-restricted', () => {
        const site = 'mysql-restricted';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site, {
                wpConfig: {
                    DB_USER: 'e2e_restricted',
                    DB_PASSWORD: 'e2e_restricted_pw',
                    DB_NAME: 'e2e_mysql_restricted',
                },
                customDb: async (dbName, conn) => {
                    await conn.query(`
CREATE TABLE wp_secret_table (
    id INT PRIMARY KEY,
    secret_data TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wp_secret_table VALUES (1, 'top secret');
                    `);
                },
                afterPermissions: async () => {
                    execSync(
                        `mysql -u e2e_admin -pe2e_password -h 127.0.0.1 -e "GRANT SELECT ON e2e_mysql_restricted.* TO 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw'; FLUSH PRIVILEGES;" 2>/dev/null || true`
                    );
                },
            });
            tempDir = createTempDir('e2e-import-mysql-restricted');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        }

        it('db-pull completes', () => {
            const result = runImporter(importUrl(), tempDir, 'db-pull', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const sqlFile = join(tempDir, 'db.sql');
            assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');
        });

        it('state shows complete', () => {
            const stateFile = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });

        it('SQL dump contains both tables', () => {
            const sqlFile = join(tempDir, 'db.sql');
            const sql = readFileSync(sqlFile, 'utf-8');
            assert.ok(sql.includes('wp_options'), 'Expected wp_options table in SQL dump');
            assert.ok(sql.includes('wp_secret_table'), 'Expected wp_secret_table in SQL dump');
        });
    });
});
