/**
 * Follow a migrated WordPress page through generated CSS to its image and font.
 * No builder cache flush is allowed to hide a stale URL or a removed stylesheet.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync, readFileSync, readdirSync, copyFileSync, openSync, closeSync } from 'node:fs';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';
import { setTimeout as sleep } from 'node:timers/promises';
import { ensureSite } from '../lib/site-setup.js';
import { runImporter, createTempDir, cleanupTempDir, getSiteUrl, getSiteDir, getSiteSecret } from '../lib/test-helpers.js';

describe('Import: generated CSS asset URLs', () => {
    const site = 'generated-css-assets';
    const stylesheetPath = '/wp-content/uploads/elementor/css/post-7.css';
    const importedStylesheetPath = '/wp-content/uploads/bb-plugin/cache/layout.css';
    const imagePath = '/wp-content/uploads/migration-assets/photo.svg';
    const fontPath = '/wp-content/uploads/migration-assets/site-font.woff2';
    let temporaryDirectory;
    let flatDirectory;
    let runtimeDirectory;
    let targetUrl;
    let sourceUrl;
    let stylesheet;
    let importedStylesheet;
    let server;
    let serverLog;

    beforeAll(async () => {
        await ensureSite(site, {
            db: 'standard', files: 'sample',
            afterCreate: async (siteDirectory) => {
                sourceUrl = new URL(getSiteUrl(site)).origin;
                stylesheet = `@import url("${sourceUrl}${importedStylesheetPath}");\n`
                    + `.migration-hero{background-image:url("${sourceUrl}${imagePath}?v=1#image")}\n`;
                importedStylesheet = `@font-face{font-family:Migration;src:url(//${new URL(sourceUrl).host}${fontPath})}\n`
                    + '.external{background:url(https://cdn.example.test/keep.png)}\n';
                for (const path of [stylesheetPath, importedStylesheetPath, imagePath]) {
                    mkdirSync(join(siteDirectory, path, '..'), { recursive: true });
                }
                writeFileSync(join(siteDirectory, stylesheetPath), stylesheet);
                writeFileSync(join(siteDirectory, importedStylesheetPath), importedStylesheet);
                writeFileSync(join(siteDirectory, imagePath), '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>');
                const bundledFont = findFont(join(siteDirectory, 'wp-content/themes'));
                assert.ok(bundledFont, 'The WordPress test installation must include a real WOFF2 font');
                copyFileSync(bundledFont, join(siteDirectory, fontPath));
                const muPlugins = join(siteDirectory, 'wp-content/mu-plugins');
                mkdirSync(muPlugins, { recursive: true });
                writeFileSync(join(muPlugins, 'migration-assets.php'), `<?php
/** Plugin Name: Customer migration asset fixture */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('migration-generated-css', content_url('/uploads/elementor/css/post-7.css'));
});
`);
            },
        });
        sourceUrl = new URL(getSiteUrl(site)).origin;
        stylesheet = readFileSync(join(getSiteDir(site), stylesheetPath), 'utf8');
        importedStylesheet = readFileSync(join(getSiteDir(site), importedStylesheetPath), 'utf8');
        temporaryDirectory = createTempDir('e2e-generated-css');
        flatDirectory = join(temporaryDirectory, 'flat');
        runtimeDirectory = join(temporaryDirectory, 'runtime');
        const listener = createServer();
        listener.listen(0, '127.0.0.1');
        await once(listener, 'listening');
        const port = listener.address().port;
        await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));
        // Database URL rewriting currently supports DNS-name targets. CSS IP
        // targets are covered by the downloader unit tests.
        targetUrl = `http://localhost:${port}`;
        const result = runImporter(`${getSiteUrl(site)}&directory=${getSiteDir(site)}`, temporaryDirectory, 'pull', {
            secret: getSiteSecret(site), skipPreflight: true, timeout: 180000,
            extraArgs: [
                '--runtime=php-builtin', '--start-runtime=none', '--target-engine=sqlite',
                `--new-site-url=${targetUrl}`, `--flatten-to=${flatDirectory}`, `--output-dir=${runtimeDirectory}`,
            ],
        });
        assert.equal(result.exitCode, 0, `Pull failed:\n${result.stdout}\n${result.stderr}`);
        serverLog = join(temporaryDirectory, 'target-server.log');
        const log = openSync(serverLog, 'a');
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', flatDirectory, join(runtimeDirectory, 'runtime.php')], {
            stdio: ['ignore', log, log],
        });
        closeSync(log);
        let lastResponse = 'No HTTP response';
        for (let attempt = 0; attempt < 100; ++attempt) {
            try {
                const response = await fetch(targetUrl, { redirect: 'manual' });
                const body = await response.text();
                lastResponse = `HTTP ${response.status}, Location: ${response.headers.get('location')}, body: ${body.slice(0, 500)}`;
                if (response.status === 200) return;
            } catch { /* The server may not have bound the socket yet. */ }
            if (server.exitCode !== null) break;
            await sleep(100);
        }
        assert.fail(`The migrated site did not serve HTTP 200: ${lastResponse}\n${readFileSync(serverLog, 'utf8').slice(-4000)}`);
    }, 240000);

    afterAll(async () => {
        if (server && server.exitCode === null) {
            server.kill();
            await once(server, 'exit');
        }
        if (temporaryDirectory) cleanupTempDir(temporaryDirectory);
    });

    it('keeps both generated stylesheet files and only changes mapped URL prefixes', () => {
        assert.equal(readFileSync(join(flatDirectory, stylesheetPath), 'utf8'), stylesheet.replaceAll(sourceUrl, targetUrl));
        assert.equal(readFileSync(join(flatDirectory, importedStylesheetPath), 'utf8'),
            importedStylesheet.replaceAll(`//${new URL(sourceUrl).host}`, `//${new URL(targetUrl).host}`));
        assert.equal(readFileSync(join(getSiteDir(site), stylesheetPath), 'utf8'), stylesheet, 'Source CSS must not change');
    });

    it('serves the migrated page, both generated stylesheets, image, and real font from the target', async () => {
        const page = await fetch(targetUrl, { redirect: 'manual' });
        assert.equal(page.status, 200);
        const html = await page.text();
        assert.ok(html.includes(`${targetUrl}${stylesheetPath}`), 'WordPress must enqueue the target stylesheet URL');
        const stylesheets = [stylesheetPath, importedStylesheetPath];
        const assetUrls = [];
        for (const path of stylesheets) {
            const response = await fetch(`${targetUrl}${path}`, { redirect: 'manual' });
            assert.equal(response.status, 200, path);
            const css = await response.text();
            assert.ok(!css.includes(sourceUrl), `CSS must not reference the old origin: ${path}`);
            for (const match of css.matchAll(/url\(["']?([^"')]+)["']?\)/g)) {
                const url = new URL(match[1], targetUrl);
                if (url.host === 'cdn.example.test') continue;
                assert.equal(url.origin, targetUrl, `Asset must use the target: ${url}`);
                assetUrls.push(url);
            }
        }
        assert.ok(assetUrls.some(url => url.pathname === imagePath));
        assert.ok(assetUrls.some(url => url.pathname === fontPath));
        for (const url of assetUrls) {
            const response = await fetch(url, { redirect: 'manual' });
            assert.equal(response.status, 200, url.href);
            if (!stylesheets.includes(url.pathname)) {
                assert.deepEqual(Buffer.from(await response.arrayBuffer()), readFileSync(join(getSiteDir(site), url.pathname)));
            }
        }
    });
});

/** Reuse a real font shipped with the test WordPress theme, without a network font dependency. */
function findFont(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name);
        if (entry.isFile() && entry.name.endsWith('.woff2')) return path;
        if (entry.isDirectory()) {
            const font = findFont(path);
            if (font) return font;
        }
    }
    return null;
}
