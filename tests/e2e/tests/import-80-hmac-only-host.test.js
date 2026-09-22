/**
 * Test 78: a host whose openssl_verify is disabled stays on HMAC. The token
 * works exactly as before this change, a key is refused with
 * requires_token_auth, and preflight still lists the openssl extension —
 * proving the rule keys off function_exists, not extension_loaded.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import {
    runImporter, getSiteUrl, getSiteSecret, getSiteDir, countAuditLogRequests, createMysqlConnection,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: HMAC-only host', { timeout: 180000 }, () => {
    const site = 'hmac-only-host';
    const importDb = 'e2e_hmac_only_host_78';

    beforeAll(async () => {
        await ensureSite(site);
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.query(`CREATE DATABASE \`${importDb}\``);
        await connection.end();
    });

    afterAll(async () => {
        const connection = await createMysqlConnection();
        await connection.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await connection.end();
    });

    /** The preflight command prints its stored record as one JSON line. */
    function preflightRecord(stdout) {
        for (const line of stdout.split('\n')) {
            try {
                const record = JSON.parse(line);
                if (record && record.data && record.data.php) {
                    return record;
                }
            } catch {
                // Not a JSON line.
            }
        }
        assert.fail(`preflight printed no record:\n${stdout}`);
    }

    it('a key is refused with requires_token_auth — this proves the pool disabled openssl_verify', () => {
        const stateDir = mkdtempSync(join(tmpdir(), 'h-'));
        const keygen = runImporter(getSiteUrl(site), stateDir, 'keygen', { autoResume: false, skipPreflight: true });
        assert.equal(keygen.exitCode, 0, keygen.stdout + keygen.stderr);
        const result = runImporter(getSiteUrl(site), stateDir, 'preflight', { autoResume: false });
        assert.notEqual(result.exitCode, 0);
        assert.match(result.stdout + result.stderr, /AUTH_REQUIRES_TOKEN|connection-token authentication only/);
    });

    it('token preflight works as before, and the extension is still reported as loaded', () => {
        const result = runImporter(getSiteUrl(site), mkdtempSync(join(tmpdir(), 'h-')), 'preflight', {
            secret: getSiteSecret(site),
            useToken: true,
            autoResume: false,
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
        const extensionVersions = preflightRecord(result.stdout).data.php.extension_versions;
        assert.ok(Object.hasOwn(extensionVersions, 'openssl'),
            `preflight extension_versions lists openssl: ${JSON.stringify(extensionVersions)}`);
    });

    it('a key on disk is refused with one request and no retry', () => {
        const stateDir = mkdtempSync(join(tmpdir(), 'h-'));
        // A token preflight first, so the refused run below is a single
        // files-index request rather than preflight's User-Agent rotation.
        const tokenPreflight = runImporter(getSiteUrl(site), stateDir, 'preflight', {
            secret: getSiteSecret(site),
            useToken: true,
            autoResume: false,
        });
        assert.equal(tokenPreflight.exitCode, 0, tokenPreflight.stdout + tokenPreflight.stderr);
        const keygen = runImporter(getSiteUrl(site), stateDir, 'keygen', { autoResume: false, skipPreflight: true });
        assert.equal(keygen.exitCode, 0, keygen.stdout + keygen.stderr);
        const requestsBefore = countAuditLogRequests(stateDir);

        // No credential flag: the client finds key.pem in the state directory.
        const result = runImporter(getSiteUrl(site), stateDir, 'files-index', { autoResume: false, skipPreflight: true });
        assert.notEqual(result.exitCode, 0);
        assert.match(result.stdout + result.stderr, /AUTH_REQUIRES_TOKEN|connection-token authentication only/);
        assert.equal(countAuditLogRequests(stateDir) - requestsBefore, 1, 'exactly one request, no retry');
    });

    it('token pull completes', () => {
        const importUrl = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(importUrl, mkdtempSync(join(tmpdir(), 'h-')), 'pull', {
            secret: getSiteSecret(site),
            useToken: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--target-user=e2e_admin',
                '--target-pass=e2e_password',
                `--target-db=${importDb}`,
                '--new-site-url=http://localhost:9999',
                '--runtime=none',
            ],
        });
        assert.equal(result.exitCode, 0, result.stdout + result.stderr);
    });
});
