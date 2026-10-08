/**
 * Test 79: on a host with OpenSSL a connection token is never accepted.
 * No keys enrolled → no_keys_enrolled. A key enrolled → requires_key_auth.
 * Either way the client makes one request and never retries.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import {
    runImporter, getSiteUrl, getSiteSecret, getSiteDir, countAuditLogRequests,
} from '../lib/test-helpers.js';
import { HmacClient, endpointUrl } from '../lib/hmac-client.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: token refused on an OpenSSL host', () => {
    const tokenOnlySite = 'token-only-openssl';
    const keyedSite = 'keyed-openssl';

    beforeAll(async () => {
        // An empty list, not an absent file: the option is not consulted either.
        await ensureSite(tokenOnlySite, { publicKeys: [] });
        await ensureSite(keyedSite);
    });

    it('token with no keys enrolled: 503 no_keys_enrolled', async () => {
        const url = endpointUrl(getSiteUrl(tokenOnlySite), 'preflight');
        const body = JSON.stringify({ directory: getSiteDir(tokenOnlySite) });
        const token = new HmacClient(getSiteSecret(tokenOnlySite));
        const response = await fetch(url, {
            method: 'POST',
            headers: { ...token.getAuthHeaders({ url }), 'Content-Type': 'application/json' },
            body,
        });
        assert.equal(response.status, 503);
        assert.equal((await response.json()).reason, 'no_keys_enrolled');
    });

    it('token with no keys enrolled: the client reports AUTH_NOT_CONFIGURED', () => {
        const result = runImporter(getSiteUrl(tokenOnlySite), mkdtempSync(join(tmpdir(), 'r-')), 'preflight', {
            secret: getSiteSecret(tokenOnlySite),
            useToken: true,
            autoResume: false,
        });
        assert.notEqual(result.exitCode, 0);
        assert.match(result.stdout + result.stderr, /requires key authentication and has no keys enrolled/);
    });

    it('token with a key enrolled: 403 requires_key_auth, one request, no retry', () => {
        const stateDir = mkdtempSync(join(tmpdir(), 'r-'));
        // A key-signed preflight first, so the refused run below is a single
        // files-index request rather than preflight's User-Agent rotation.
        const keyed = runImporter(getSiteUrl(keyedSite), stateDir, 'preflight', {
            secret: getSiteSecret(keyedSite),
            autoResume: false,
        });
        assert.equal(keyed.exitCode, 0, keyed.stdout + keyed.stderr);
        const requestsBefore = countAuditLogRequests(stateDir);

        const result = runImporter(getSiteUrl(keyedSite), stateDir, 'files-index', {
            secret: getSiteSecret(keyedSite),
            useToken: true,
            autoResume: false,
            skipPreflight: true,
        });
        assert.notEqual(result.exitCode, 0);
        assert.match(result.stdout + result.stderr, /AUTH_REQUIRES_KEY|accepts key authentication only/);
        assert.equal(countAuditLogRequests(stateDir) - requestsBefore, 1, 'exactly one request, no retry');
    });
});
