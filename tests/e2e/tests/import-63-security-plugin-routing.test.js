/**
 * Test 63: Security plugin request hooks run before the Reprint API handler.
 *
 * Activates a plugin after Reprint. Its template_redirect callback marks the
 * response, which proves the later plugin loaded and inspected the API request
 * before Reprint answered it.
 */
import { afterAll, beforeAll, describe, it } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync, execSync } from 'node:child_process';
import { join } from 'node:path';
import { getSiteDir, getSiteUrl } from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Reprint Server: security plugin routing', { timeout: 120000 }, () => {
    const site = 'basic';
    const pluginSlug = 'reprint-security-hook-test';
    let pluginDirectory;

    beforeAll(async () => {
        await ensureSite(site);

        pluginDirectory = join(getSiteDir(site), 'wp-content', 'plugins', pluginSlug);
        execFileSync('sudo', ['mkdir', '-p', pluginDirectory]);
        execFileSync('sudo', ['tee', join(pluginDirectory, 'index.php')], {
            input: `<?php
/**
 * Plugin Name: Reprint security hook test
 */
add_action('template_redirect', static function() {
    if (isset($_GET['reprint-api'])) {
        header('X-Reprint-Security-Hook: ran');
    }
}, 1001);
`,
            stdio: ['pipe', 'ignore', 'pipe'],
        });

        const allowRoot = process.getuid?.() === 0 ? ' --allow-root' : '';
        execSync(
            `php /tmp/wp-cli.phar plugin activate ${pluginSlug}` +
                ` --path=${JSON.stringify(getSiteDir(site))}` +
                allowRoot,
            { timeout: 30000, stdio: 'pipe' },
        );
    });

    afterAll(() => {
        const allowRoot = process.getuid?.() === 0 ? ' --allow-root' : '';
        execSync(
            `php /tmp/wp-cli.phar plugin deactivate ${pluginSlug}` +
                ` --path=${JSON.stringify(getSiteDir(site))}` +
                allowRoot,
            { timeout: 30000, stdio: 'pipe' },
        );
        execFileSync('sudo', ['rm', '-rf', pluginDirectory]);
    });

    it('runs a later plugin template_redirect callback before answering', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const response = await fetch(url);
        const body = await response.json();

        assert.equal(response.status, 200);
        assert.equal(response.headers.get('x-reprint-security-hook'), 'ran');
        assert.equal(body.ok, true);
    });
});
