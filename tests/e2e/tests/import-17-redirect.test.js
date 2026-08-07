/**
 * Test 17: 301 Redirect Handling via client.php
 * Tests that the importer reports a clear error when hitting a 301 redirect,
 * since the importer does not follow redirects (CURLOPT_FOLLOWLOCATION=false).
 *
 * The redirect-301 site (port 8097) redirects all requests to port 8081 (basic).
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret,
    apiRequest,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: 301 Redirect', () => {
    beforeAll(async () => {
        // Ensure both the redirect source and target sites exist
        await ensureSite('redirect-301');
        await ensureSite('basic');
    });

    it('nginx returns 301 for the redirect site', async () => {
        // Verify the redirect is active at the HTTP level
        const response = await apiRequest('basic', 'status', {}, {
            url: getSiteUrl('redirect-301'),
            rawResponse: true,
            followRedirects: false,
        });
        assert.equal(response.status, 301, 'Expected 301 redirect from nginx');
        const location = response.headers.get('location');
        assert.ok(location, 'Expected Location header in redirect response');
        assert.ok(location.includes('8081'), 'Expected redirect to port 8081');
    });

    it('importer reports HTTP 301 error for files-pull', () => {
        const tempDir = createTempDir('e2e-import-redirect-files');
        try {
            // Use the redirect site URL but with the basic site's directory
            const url = `${getSiteUrl('redirect-301')}&directory=/srv/e2e-sites/basic`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret('redirect-301'),
                // The importer makes its own HTTP request after preflight; under
                // WASM PHP, both phases need ~12s of boot plus the actual handshake.
                timeout: 180000,
            });
            // The importer should fail because it doesn't follow redirects
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code for redirect');
            const output = result.stdout + result.stderr;
            assert.ok(
                output.includes('301') || output.includes('HTTP error'),
                `Expected 301 or HTTP error in output, got:\n` +
                `  exit=${result.exitCode}, signal=${result.signal}, killed=${result.killed}, errorCode=${result.errorCode}\n` +
                `  stdout (${result.stdout.length} bytes): ${result.stdout}\n` +
                `  stderr (${result.stderr.length} bytes): ${result.stderr}`
            );
        } finally {
            cleanupTempDir(tempDir);
        }
    });

    it('importer reports HTTP 301 error for db-pull', () => {
        const tempDir = createTempDir('e2e-import-redirect-sql');
        try {
            const url = `${getSiteUrl('redirect-301')}&directory=/srv/e2e-sites/basic`;
            const result = runImporter(url, tempDir, 'db-pull', {
                secret: getSiteSecret('redirect-301'),
                // The importer makes its own HTTP request after preflight; under
                // WASM PHP, both phases need ~12s of boot plus the actual handshake.
                timeout: 180000,
            });
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code for redirect');
            const output = result.stdout + result.stderr;
            assert.ok(
                output.includes('301') || output.includes('HTTP error'),
                `Expected 301 or HTTP error in output, got:\n` +
                `  exit=${result.exitCode}, signal=${result.signal}, killed=${result.killed}, errorCode=${result.errorCode}\n` +
                `  stdout (${result.stdout.length} bytes): ${result.stdout}\n` +
                `  stderr (${result.stderr.length} bytes): ${result.stderr}`
            );
        } finally {
            cleanupTempDir(tempDir);
        }
    });
});
