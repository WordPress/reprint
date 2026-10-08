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

test('setup, enrollment errors, adding tools, push consent, and removal work without JavaScript', async () => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    try {
        const page = await login(context);
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Setup needed');
        assert.equal(await page.locator('.reprint-server-page .notice').count(), 0);
        assert.equal(await page.locator('#reprint-server-api-url').inputValue(), `${siteUrl}/?reprint-api`);
        assert.equal(await page.locator('.reprint-server-key-table').count(), 0, 'Start with no enrolled keys.');

        await page.getByRole('textbox', { name: 'Public key', exact: true }).fill('not a public key');
        await submit(page, page.getByRole('button', { name: 'Authorize tool', exact: true }));
        assert.match(await page.locator('.notice-error').innerText(), /not a usable public key/);
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Setup needed');

        const publicKey = generatePublicKey();
        await page.getByRole('textbox', { name: 'Public key', exact: true }).fill(publicKey);
        await submit(page, page.getByRole('button', { name: 'Authorize tool', exact: true }));
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Ready for downloads');
        assert.match(await page.locator('.reprint-server-next-action').innerText(), /Return to your tool/);
        assert.equal(await page.getByRole('textbox', { name: 'Public key', exact: true }).isVisible(), false);
        const firstKeyId = await page.locator('.reprint-server-key-table tbody tr code').innerText();

        await page.getByText('Authorize another tool', { exact: true }).click();
        await page.getByRole('textbox', { name: 'Public key', exact: true }).fill(publicKey);
        await submit(page, page.getByRole('button', { name: 'Authorize tool', exact: true }));
        assert.match(await page.locator('.notice-info').innerText(), /already enrolled/);
        assert.equal(await page.getByRole('textbox', { name: 'Public key', exact: true }).isVisible(), true);
        assert.equal(await page.locator('.reprint-server-key-table tbody tr').count(), 1);

        await page.getByRole('textbox', { name: 'Public key', exact: true }).fill(generatePublicKey());
        await submit(page, page.getByRole('button', { name: 'Authorize tool', exact: true }));
        assert.equal(await page.locator('.reprint-server-key-table tbody tr').count(), 2);
        const secondKeyId = await page.locator('.reprint-server-key-table tbody tr code').nth(1).innerText();

        await page.getByRole('checkbox', { name: `Allow push for key ${firstKeyId}`, exact: true }).check();
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Ready for downloads', 'Checking alone must not save consent.');
        await submit(page, page.getByRole('button', { name: `Save push access for key ${firstKeyId}`, exact: true }));
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Downloads and push enabled');
        assert.equal(await page.getByRole('checkbox', { name: `Allow push for key ${secondKeyId}`, exact: true }).isChecked(), false);

        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.locator('#reprint-server-api-url').isVisible(), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        const removeButton = await page.getByRole('button', { name: `Remove key ${firstKeyId}`, exact: true }).boundingBox();
        assert.ok(removeButton.x >= 0 && removeButton.x + removeButton.width <= 390, 'Mobile key actions must not require sideways scrolling.');
        await page.getByRole('button', { name: `Remove key ${firstKeyId}`, exact: true }).scrollIntoViewIfNeeded();
        assert.equal(await page.getByRole('button', { name: `Remove key ${firstKeyId}`, exact: true }).isVisible(), true);
        await submit(page, page.getByRole('button', { name: `Remove key ${firstKeyId}`, exact: true }));
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Ready for downloads');
        await submit(page, page.getByRole('button', { name: `Remove key ${secondKeyId}`, exact: true }));
        assert.equal(await page.locator('.reprint-server-status').innerText(), 'Setup needed');
        assert.equal(await page.locator('.reprint-server-key-table').count(), 0);
        assert.equal(await page.getByRole('textbox', { name: 'Public key', exact: true }).isVisible(), true);
    } finally {
        await context.close();
    }
});

test('URL copying reports success and falls back to manual selection when denied', async () => {
    const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
    try {
        const page = await login(context);
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
        assert.match(await page.getByRole('status').innerText(), /Could not copy automatically/);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
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
