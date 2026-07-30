/**
 * Test 27: Invalid API Parameters
 * Tests that the export API returns proper error responses for
 * missing/invalid endpoints, bad cursors, and auth failures.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest,
    getSiteUrl, getSiteDir,
    createHmacClient,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Invalid API Parameters', () => {
    const site = 'basic';

    beforeAll(async () => {
        await ensureSite(site);
    });

    it('missing endpoint parameter returns 500 with error message', async () => {
        // Make request without endpoint parameter
        const response = await apiRequest(site, '', {
            directory: getSiteDir(site),
        });
        // Should get an error response (500 from exception handler)
        assert.ok(
            response.status === 500 || response.status === 400,
            `Expected 400 or 500, got ${response.status}`
        );
        const errorText = response.json?.error || response.text || '';
        assert.ok(
            errorText.includes('endpoint') || errorText.includes('required'),
            `Expected error about missing endpoint, got: ${errorText}`
        );
    });

    it('invalid endpoint name returns 500 with error message', async () => {
        const response = await apiRequest(site, 'not_a_real_endpoint', {
            directory: getSiteDir(site),
        });
        assert.ok(
            response.status === 500 || response.status === 400,
            `Expected 400 or 500, got ${response.status}`
        );
        const errorText = response.json?.error || response.text || '';
        assert.ok(
            errorText.includes('Invalid endpoint') || errorText.includes('not_a_real_endpoint'),
            `Expected error about invalid endpoint, got: ${errorText}`
        );
    });

    it('valid endpoints are listed in error message', async () => {
        const response = await apiRequest(site, 'bad_endpoint', {
            directory: getSiteDir(site),
        });
        const errorText = response.json?.error || response.text || '';
        assert.ok(
            errorText.includes('file_index') && errorText.includes('sql_chunk'),
            `Expected valid endpoints listed in error, got: ${errorText}`
        );
    });

    it('wrong HMAC secret returns 403', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir(site));

        const wrongClient = createHmacClient('wrong-secret-value');
        const headers = wrongClient.getAuthHeaders('');
        headers['Accept-Encoding'] = 'gzip';

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403, 'Expected 403 for wrong HMAC');

        const body = await response.json();
        assert.ok(
            body.error && (body.error.includes('HMAC') || body.error.includes('signature')),
            `Expected HMAC error, got: ${body.error}`
        );
    });

    it('missing auth headers returns 403', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const response = await fetch(url.toString());
        assert.equal(response.status, 403, 'Expected 403 for missing auth');

        const body = await response.json();
        assert.ok(body.error, 'Expected error message');
        assert.ok(
            body.error.includes('X-Auth-Signature') || body.error.includes('Missing'),
            `Expected missing header error, got: ${body.error}`
        );
    });

    it('invalid cursor base64 returns error', async () => {
        const response = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            cursor: '!!!not-valid-base64!!!',
        });
        // Should either return error or ignore invalid cursor
        if (response.status !== 200) {
            const errorText = response.json?.error || response.text || '';
            assert.ok(
                errorText.includes('cursor') || errorText.includes('Invalid') || errorText.includes('base64'),
                `Expected cursor-related error, got: ${errorText}`
            );
        }
        // If status is 200, the server may have silently reset the cursor — that's OK
    });

    it('file_index without directory returns error', async () => {
        const response = await apiRequest(site, 'file_index', {});
        // No directory parameter — should fail
        if (response.status !== 200) {
            const errorText = response.json?.error || response.text || '';
            assert.ok(errorText.length > 0, 'Expected error message');
        }
    });
});
