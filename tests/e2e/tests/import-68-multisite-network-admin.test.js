import { describe, it, beforeAll, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { getSiteDir, getSiteUrl, getSiteSecret, createMysqlConnection, getDbName } from '../lib/test-helpers.js';
import { ensureMultisite, runWp } from '../lib/multisite-setup.js';
import { HmacClient } from '../lib/hmac-client.js';

const site = 'multisite-admin';
const token = getSiteSecret(site);
const origin = new URL(getSiteUrl(site)).origin;
const settingsUrl = `${origin}/wp-admin/network/settings.php?page=reprint-server`;
const actionUrl = `${origin}/wp-admin/admin-post.php`;
const option = 'reprint_server_connection_token';

describe('Multisite network token administration over HTTP', () => {
    beforeAll(async () => {
        await ensureMultisite(site);
        // The legacy file deliberately overrides options. Remove it so the
        // real settings form controls the token that authenticates requests.
        execFileSync('sudo', ['rm', '-f', join(getSiteDir(site), 'wp-content/plugins/reprint-server/secret.php')]);
    });
    beforeEach(resetToken);
    afterEach(resetToken);

    it('lets a network administrator rotate the token used by the selected-site endpoint', async () => {
        const cookie = await login('admin', 'password');
        const page = await fetch(settingsUrl, { headers: { Cookie: cookie } });
        const html = await page.text();
        assert.equal(page.status, 200, html);
        const nonce = html.match(/name="_wpnonce" value="([^"]+)"/)?.[1];
        assert.ok(nonce, 'The network settings form must provide its save nonce');
        const changedToken = 'changed-network-token';
        const save = await fetch(actionUrl, {
            method: 'POST', redirect: 'manual', headers: { Cookie: cookie },
            body: new URLSearchParams({ action: 'reprint_server_save_network_token', _wpnonce: nonce, [option]: changedToken }),
        });
        assert.equal(save.status, 302, await save.text());
        assert.equal(save.headers.get('location'), settingsUrl);
        const accepted = await fetch(`${origin}/shop/?reprint-api&endpoint=preflight&multisite_mode=one-site-network-v1`, {
            headers: new HmacClient(changedToken).getAuthHeaders(''),
        });
        const preflight = await accepted.json();
        assert.equal(accepted.status, 200, JSON.stringify(preflight));
        assert.equal(preflight.database.wp.multisite.selection.site_id, 7);
        const rejected = await fetch(`${origin}/shop/?reprint-api&endpoint=preflight&multisite_mode=one-site-network-v1`, {
            headers: new HmacClient(token).getAuthHeaders(''),
        });
        assert.equal(rejected.status, 403, await rejected.text());
    });

    it('refuses a site administrator access to the form and rejects a forged token save', async () => {
        const cookie = await login('shared', 'multisite-password');
        const page = await fetch(settingsUrl, { headers: { Cookie: cookie }, redirect: 'manual' });
        const html = await page.text();
        assert.equal(page.status, 403, html);
        assert.ok(!html.includes(token), 'The rejected page must not disclose the network token');
        const save = await fetch(actionUrl, {
            method: 'POST', redirect: 'manual', headers: { Cookie: cookie },
            body: new URLSearchParams({ action: 'reprint_server_save_network_token', _wpnonce: 'forged', [option]: 'site-admin-token' }),
        });
        assert.ok(save.status >= 400, `Unexpected status ${save.status}`);
        assert.ok((await save.text()).includes('You are not allowed to manage this network.'));
        const connection = await createMysqlConnection(getDbName(site));
        try {
            const [rows] = await connection.query('SELECT meta_value FROM network_sitemeta WHERE meta_key=?', [option]);
            assert.deepEqual(rows.map(row => row.meta_value), [token]);
            const [siteOptions] = await connection.query('SELECT option_value FROM network_8_options WHERE option_name=?', [option]);
            assert.deepEqual(siteOptions, []);
        } finally { await connection.end(); }
    });
});

function resetToken() {
    runWp(getSiteDir(site), ['eval', `update_site_option('${option}', '${token}');`]);
}

async function login(username, password) {
    const page = await fetch(`${origin}/wp-login.php`);
    const cookie = page.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
    const response = await fetch(`${origin}/wp-login.php`, {
        method: 'POST', redirect: 'manual', headers: { Cookie: cookie },
        body: new URLSearchParams({ log: username, pwd: password, testcookie: '1' }),
    });
    assert.equal(response.status, 302, await response.text());
    const authCookies = response.headers.getSetCookie().map(value => value.split(';')[0]).join('; ');
    assert.ok(authCookies.includes('wordpress_logged_in_'), 'Log in through WordPress rather than injecting a session');
    return authCookies;
}
