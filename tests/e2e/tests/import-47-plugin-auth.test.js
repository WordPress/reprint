/**
 * Test 47: Reprint Server plugin authentication
 *
 * Installs the Reprint Server plugin in a stock WordPress site — no custom
 * setup, no afterCreate hooks — and verifies the default authentication
 * behaviour via HTTP.
 *
 * The point is to treat the plugin as a black box: activate it, hit the
 * endpoint, and confirm that unauthenticated and wrongly-authenticated
 * requests are rejected while correctly-signed ones succeed.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest,
    getSiteUrl, getSiteDir,
    createHmacClient,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Reprint Server plugin authentication', () => {
    const site = 'plugin-auth';

    beforeAll(async () => {
        await ensureSite(site);
    });

    it('rejects requests with no auth headers', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir(site));

        const response = await fetch(url.toString());
        assert.equal(response.status, 403,
            'Unauthenticated request must be rejected with 403');

        const body = await response.json();
        assert.ok(body.error,
            'Response must include an error message');
    });

    it('rejects requests signed with the wrong connection token', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir(site));

        const clientWithWrongConnectionToken = createHmacClient('not-the-right-connection-token');
        const response = await fetch(url.toString(), {
            headers: clientWithWrongConnectionToken.getAuthHeaders(''),
        });
        assert.equal(response.status, 403,
            'Request signed with the wrong connection token must be rejected with 403');

        const body = await response.json();
        assert.ok(body.error,
            'Response must include an error message');
    });

    it('accepts requests signed with the correct connection token', async () => {
        const response = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(response.status, 200,
            'Correctly-signed request must be accepted');
    });
});
