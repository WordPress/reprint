/**
 * Test 47: Reprint Server plugin authentication
 *
 * Installs the Reprint Server plugin in a stock WordPress site — no custom
 * setup, no afterCreate hooks — and verifies the default authentication
 * behaviour via HTTP.
 *
 * The point is to treat the plugin as a black box: activate it, hit the
 * endpoint, and confirm that unauthenticated, wrongly-keyed and token-signed
 * requests are rejected while a request signed with the enrolled key
 * succeeds. The test host has OpenSSL, so the plugin accepts keys only.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { createHash, createSign, randomBytes } from 'node:crypto';
import {
    apiRequest,
    getSiteUrl, getSiteDir, getSiteSecret,
    getHarnessKey,
} from '../lib/test-helpers.js';
import { HmacClient, KeySigner, endpointUrl } from '../lib/hmac-client.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Reprint Server plugin authentication', () => {
    const site = 'plugin-auth';

    beforeAll(async () => {
        await ensureSite(site);
    });

    it('rejects requests with no auth headers', async () => {
        const response = await fetch(endpointUrl(getSiteUrl(site), 'preflight'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ directory: getSiteDir(site) }),
        });
        assert.equal(response.status, 403,
            'Unauthenticated request must be rejected with 403');

        const body = await response.json();
        assert.ok(body.error,
            'Response must include an error message');
    });

    it('rejects a signature from a key that is not enrolled', async () => {
        const url = endpointUrl(getSiteUrl(site), 'preflight');
        const requestBody = JSON.stringify({ directory: getSiteDir(site) });
        const stranger = getHarnessKey('not-the-right-key').signer;
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                ...stranger.getAuthHeaders({ url }),
                'Content-Type': 'application/json',
            },
            body: requestBody,
        });
        assert.equal(response.status, 403);
        const body = await response.json();
        assert.equal(body.reason, 'unknown_key');
    });

    it('rejects a connection token on a host with OpenSSL', async () => {
        const url = endpointUrl(getSiteUrl(site), 'preflight');
        const requestBody = JSON.stringify({ directory: getSiteDir(site) });
        const token = new HmacClient(getSiteSecret(site));
        const response = await fetch(url, {
            method: 'POST',
            headers: { ...token.getAuthHeaders({ url }), 'Content-Type': 'application/json' },
            body: requestBody,
        });
        assert.equal(response.status, 403);
        const body = await response.json();
        assert.equal(body.reason, 'requires_key_auth');
    });

    it('accepts a correctly signed key request', async () => {
        const response = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(response.status, 200,
            'Correctly-signed request must be accepted');
        assert.ok(response.json && response.json.php,
            'preflight answered with its JSON report');
    });

    it('asks a released token client to update the client', async () => {
        const requestBody = JSON.stringify({ endpoint: 'preflight', directory: getSiteDir(site) });
        const response = await fetch(getSiteUrl(site), {
            method: 'POST',
            body: requestBody,
            headers: {
                // What a released token client sends, including X-Auth-Content-Hash.
                'X-Auth-Signature': '0'.repeat(64),
                'X-Auth-Nonce': 'a'.repeat(32),
                'X-Auth-Timestamp': (Date.now() / 1000).toFixed(6),
                'X-Auth-Content-Hash': createHash('sha256').update(requestBody).digest('hex'),
                'Content-Type': 'application/json',
            },
        });
        assert.equal(response.status, 403);
        const body = await response.json();
        assert.equal(body.reason, 'client_update_required');
        assert.match(body.error, /^Update the Reprint client to version \S+ or later\.$/);
    });

    it('serves a v0.10.12 key client', async () => {
        const url = getSiteUrl(site);
        // The enrolled harness key, signing the way v0.10.12 does.
        const { signer } = getHarnessKey(getSiteSecret(site));
        const nonce = randomBytes(16).toString('hex');
        const timestamp = (Date.now() / 1000).toFixed(6);
        const message = ['reprint-rsa-sha256-v1', signer.getKeyId(), nonce, timestamp, 'POST', KeySigner.requestTarget(url)].join('\n');
        const response = await fetch(url, {
            method: 'POST',
            body: JSON.stringify({ endpoint: 'preflight', directory: getSiteDir(site) }),
            headers: {
                'X-Auth-Key-Id': signer.getKeyId(),
                'X-Auth-Signature': createSign('sha256').update(message).sign(signer.privateKey, 'base64'),
                'X-Auth-Nonce': nonce,
                'X-Auth-Timestamp': timestamp,
                'Content-Type': 'application/json',
            },
        });
        assert.equal(response.status, 200, 'A v0.10.12 key request must reach preflight');
        const report = await response.json();
        assert.ok(report && report.php, 'preflight answered with its JSON report');
    });
});
