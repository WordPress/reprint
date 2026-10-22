import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync } from 'node:crypto';
import { chromium } from 'playwright';

const siteUrl = process.env.REPRINT_ADMIN_TEST_URL;
const username = process.env.REPRINT_ADMIN_TEST_USER;
const password = process.env.REPRINT_ADMIN_TEST_PASSWORD;
let browser;

before(async () => {
    assert.ok(siteUrl && username && password, 'Set REPRINT_ADMIN_TEST_URL, REPRINT_ADMIN_TEST_USER, and REPRINT_ADMIN_TEST_PASSWORD for a disposable WordPress site.');
    assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(siteUrl).hostname), 'These tests change access. Use a loopback-only test site.');
    browser = await chromium.launch();
});

after(async () => {
    await browser?.close();
});

test('authorization, enrollment errors, adding tools, push consent, and removal work without JavaScript', async () => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    try {
        const page = await login(context);
        const adminUrl = `${siteUrl}/wp-admin/tools.php?page=reprint-server`;
        const publicKeyField = page.getByRole('textbox', { name: 'Public key', exact: true });
        const authorizeButton = page.getByRole('button', { name: 'Authorize tool', exact: true });
        const management = page.locator('.reprint-server-manage-access');
        assert.equal(await page.getByRole('heading', { name: 'Authorize a tool to copy this site', exact: true }).isVisible(), true);
        assert.equal(await publicKeyField.isVisible(), true);
        assert.equal(await page.locator('.reprint-server-page .notice').count(), 0);
        assert.equal(await page.locator('#reprint-server-api-url').inputValue(), `${siteUrl}/?reprint-api`);
        assert.equal(await page.locator('.reprint-server-key-table').count(), 0, 'Start with no enrolled keys.');

        await publicKeyField.fill('not a public key');
        await submit(page, authorizeButton);
        assert.match(await page.locator('.notice-error').innerText(), /not a usable public key/);
        assert.equal(await publicKeyField.isVisible(), true);

        const publicKey = generatePublicKey();
        await publicKeyField.fill(publicKey);
        await submit(page, authorizeButton);
        assert.equal(await page.getByRole('heading', { name: 'Tool authorized', exact: true }).isVisible(), true);
        assert.equal(await page.getByText('Return to your tool to start or resume the copy.', { exact: true }).isVisible(), true);
        assert.equal(await page.getByText('This key allows downloads. It cannot change files on this site.', { exact: true }).isVisible(), true);
        assert.equal(await publicKeyField.count(), 0);
        assert.equal(await page.locator('.reprint-server-page .notice').count(), 0);
        const firstKeyId = await page.locator('.reprint-server-key-table tbody tr code').textContent();
        await submit(page, page.getByRole('link', { name: 'Authorize another tool', exact: true }));
        assert.equal(page.url(), adminUrl, 'Another authorization starts without the previous result in the URL.');
        assert.equal(await publicKeyField.isVisible(), true);
        assert.equal(await management.getAttribute('open'), null);
        assert.equal(await page.locator('.reprint-server-key-table').isVisible(), false);
        assert.equal(await page.locator('#reprint-server-api-url').isVisible(), true);
        const fieldBox = await publicKeyField.boundingBox();
        const urlBox = await page.locator('#reprint-server-api-url').boundingBox();
        assert.ok(fieldBox.y < urlBox.y, 'Authorization must come before the URL.');
        assert.equal(await page.getByText('Server details', { exact: true }).count(), 0);
        assert.equal(await page.getByText('Host details', { exact: true }).count(), 0);

        const keyHelp = page.getByText('I don’t have a public key yet', { exact: true });
        await keyHelp.focus();
        await page.keyboard.press('Enter');
        await page.getByText('Using the Reprint CLI?', { exact: true }).click();
        assert.equal(await page.locator('.reprint-server-authorization pre').innerText(), `reprint keygen '${siteUrl}/?reprint-api' --state-dir=./reprint-state --insecure`);
        await keyHelp.click();
        await publicKeyField.fill('not a public key');
        await submit(page, authorizeButton);
        assert.match(await page.locator('.notice-error').innerText(), /not a usable public key/);
        assert.equal(await publicKeyField.isVisible(), true);
        assert.equal(await management.getAttribute('open'), null);
        assert.equal(await page.locator('.reprint-server-key-table tbody tr').count(), 1);
        await publicKeyField.fill(publicKey);
        await submit(page, authorizeButton);
        assert.match(await page.locator('.notice-info').innerText(), /already enrolled/);
        assert.equal(await publicKeyField.isVisible(), true);
        assert.equal(await management.getAttribute('open'), null);
        assert.equal(await page.locator('.reprint-server-key-table tbody tr').count(), 1);

        await publicKeyField.fill(generatePublicKey());
        await submit(page, authorizeButton);
        assert.equal(await page.locator('.reprint-server-key-table tbody tr').count(), 2);
        assert.equal(await page.getByRole('heading', { name: 'Tool authorized', exact: true }).isVisible(), true);
        assert.equal(await publicKeyField.count(), 0);
        const secondKeyId = await page.locator('.reprint-server-key-table tbody tr code').nth(1).textContent();
        await page.goto(adminUrl);
        assert.equal(await publicKeyField.isVisible(), true, 'A normal visit offers authorization even when keys are already saved.');
        await management.locator('summary').first().click();
        await page.getByRole('checkbox', { name: `Allow push for key ${firstKeyId}`, exact: true }).check();
        await page.reload();
        await management.locator('summary').first().click();
        assert.equal(await page.getByRole('checkbox', { name: `Allow push for key ${firstKeyId}`, exact: true }).isChecked(), false, 'Checking alone must not save consent.');
        await page.getByRole('checkbox', { name: `Allow push for key ${firstKeyId}`, exact: true }).check();
        await submit(page, page.getByRole('button', { name: `Save push access for key ${firstKeyId}`, exact: true }));
        assert.equal(await management.getAttribute('open'), '', 'The result keeps the controls visible.');
        assert.equal(await page.getByRole('checkbox', { name: `Allow push for key ${firstKeyId}`, exact: true }).isChecked(), true);
        assert.equal(await page.getByRole('checkbox', { name: `Allow push for key ${secondKeyId}`, exact: true }).isChecked(), false);

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(adminUrl);
        const mobileAuthorizeBox = await authorizeButton.boundingBox();
        assert.ok(mobileAuthorizeBox.y >= 0 && mobileAuthorizeBox.y + mobileAuthorizeBox.height <= 844, 'Authorization must be visible before scrolling on mobile.');
        assert.equal(await publicKeyField.isVisible(), true);
        assert.equal(await page.locator('#reprint-server-api-url').isVisible(), true);
        assert.equal(await management.getAttribute('open'), null);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await keyHelp.click();
        await page.getByText('Using the Reprint CLI?', { exact: true }).click();
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'CLI help must fit on mobile.');
        await keyHelp.click();
        await management.locator('summary').first().click();
        const removeButton = await page.getByRole('button', { name: `Remove key ${firstKeyId}`, exact: true }).boundingBox();
        assert.ok(removeButton.x >= 0 && removeButton.x + removeButton.width <= 390, 'Mobile key actions must not require sideways scrolling.');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await submit(page, page.getByRole('button', { name: `Remove key ${firstKeyId}`, exact: true }));
        assert.equal(await page.locator('.reprint-server-key-table tbody tr').count(), 1);
        await submit(page, page.getByRole('button', { name: `Remove key ${secondKeyId}`, exact: true }));
        assert.equal(await page.locator('.reprint-server-key-table').count(), 0);
        assert.equal(await management.count(), 0);
        assert.equal(await publicKeyField.isVisible(), true);
    } finally {
        await context.close();
    }
});

test('configured URL copying reports success and falls back to manual selection when denied', async () => {
    const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
    try {
        const page = await login(context);
        await page.getByRole('textbox', { name: 'Public key', exact: true }).fill(generatePublicKey());
        await submit(page, page.getByRole('button', { name: 'Authorize tool', exact: true }));
        const keyId = await page.locator('.reprint-server-key-table tbody tr code').textContent();
        assert.equal(await page.locator('#reprint-server-connection-heading').count(), 0);
        assert.equal(await page.locator('#reprint-server-api-url').isVisible(), true);
        assert.equal(await page.locator('.reprint-server-manage-access').getAttribute('open'), null);
        await page.getByRole('button', { name: 'Copy URL', exact: true }).click();
        await page.getByRole('status').filter({ hasText: 'Remote Reprint API URL copied.' }).waitFor();
        assert.equal(await page.evaluate(() => navigator.clipboard.readText()), `${siteUrl}/?reprint-api`);

        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: {
                writeText: () => Promise.reject(new DOMException('Clipboard permission denied', 'NotAllowedError')),
            } });
        });
        await page.reload();
        await page.getByRole('button', { name: 'Copy URL', exact: true }).click();
        await page.getByRole('status').filter({ hasText: 'Could not copy automatically.' }).waitFor();
        const selection = await page.locator('#reprint-server-api-url').evaluate(input => ({
            focused: document.activeElement === input,
            start: input.selectionStart,
            end: input.selectionEnd,
            length: input.value.length,
        }));
        assert.deepEqual(selection, { focused: true, start: 0, end: selection.length, length: selection.length });
        await page.evaluate(() => {
            Object.defineProperty(navigator, 'clipboard', { value: undefined });
            window.testCopyCalls = 0;
            document.execCommand = () => { window.testCopyCalls++; return false; };
        });
        await page.getByRole('button', { name: 'Copy URL', exact: true }).click();
        assert.equal(await page.evaluate(() => window.testCopyCalls), 1);
        assert.match(await page.locator('.reprint-server-copy-status').innerText(), /Could not copy automatically/);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.locator('.reprint-server-manage-access > summary').click();
        await submit(page, page.getByRole('button', { name: `Remove key ${keyId}`, exact: true }));
        assert.equal(await page.getByRole('textbox', { name: 'Public key', exact: true }).isVisible(), true);
    } finally {
        await context.close();
    }
});

async function login(context) {
    const page = await context.newPage();
    await page.goto(`${siteUrl}/wp-login.php`);
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await submit(page, page.locator('#wp-submit'));
    await page.goto(`${siteUrl}/wp-admin/tools.php?page=reprint-server`);
    await page.locator('.reprint-server-page').waitFor();
    return page;
}

function generatePublicKey() {
    return generateKeyPairSync('rsa', {
        modulusLength: 3072,
        publicKeyEncoding: { type: 'spki', format: 'pem' },
        privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    }).publicKey;
}

async function submit(page, button) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), button.click()]);
}
