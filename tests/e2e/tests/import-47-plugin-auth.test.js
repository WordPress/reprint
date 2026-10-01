/**
 * Test 47: Reprint Server plugin authentication
 *
 * Installs the Reprint Server plugin in a stock WordPress site — no custom
 * setup, no afterCreate hooks — and verifies the default authentication
 * behaviour via HTTP.
 *
 * The point is to treat the plugin as a black box: activate it, hit the
 * endpoint, and confirm that unauthenticated and wrongly-keyed requests are
 * rejected while a request signed with the enrolled key succeeds.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest,
    getSiteUrl, getSiteDir,
    getHarnessKey,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Reprint Server plugin authentication', () => {
    const site = 'plugin-auth';

    beforeAll(async () => {
        await ensureSite(site);
    });

    it('rejects requests with no auth headers', async () => {
        const response = await fetch(getSiteUrl(site), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ endpoint: 'preflight', directory: getSiteDir(site) }),
        });
        assert.equal(response.status, 403,
            'Unauthenticated request must be rejected with 403');

        const body = await response.json();
        assert.ok(body.error,
            'Response must include an error message');
    });

    it('rejects a signature from a key that is not enrolled', async () => {
        const requestBody = JSON.stringify({ endpoint: 'preflight', directory: getSiteDir(site) });
        const stranger = getHarnessKey('not-the-right-key').signer;
        const response = await fetch(getSiteUrl(site), {
            method: 'POST',
            headers: {
                ...stranger.getAuthHeaders({ url: getSiteUrl(site) }),
                'Content-Type': 'application/json',
            },
            body: requestBody,
        });
        assert.equal(response.status, 403);
        const body = await response.json();
        assert.equal(body.reason, 'unknown_key');
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
});
