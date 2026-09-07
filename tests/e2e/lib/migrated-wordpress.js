import assert from 'node:assert/strict';
import { openSync, closeSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';
import { setTimeout as sleep } from 'node:timers/promises';
import { runImporter, createTempDir, cleanupTempDir, getSiteUrl, getSiteDir, getSiteSecret } from './test-helpers.js';

/**
 * Migrates a source WordPress site to SQLite and serves the generated PHP runtime.
 * Calls inspect with the CLI result, target HTML, and paths only after the target
 * renders the migrated post. The server and temporary files stay available until
 * inspect finishes; both are cleaned up even if migration or assertions fail.
 */
export async function withMigratedWordPress(site, inspect) {
    const temporaryDirectory = createTempDir(`e2e-migrated-${site}`);
    const flatDirectory = join(temporaryDirectory, 'flat');
    const runtimeDirectory = join(temporaryDirectory, 'runtime');
    const importUrl = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    let server;
    try {
        const listener = createServer();
        listener.listen(0, '127.0.0.1');
        await once(listener, 'listening');
        const port = listener.address().port;
        await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));
        const targetUrl = `http://localhost:${port}`;
        const result = runImporter(importUrl, temporaryDirectory, 'pull', {
            secret: getSiteSecret(site), skipPreflight: true, autoResume: false, timeout: 180000,
            extraArgs: [
                '--runtime=php-builtin', '--start-runtime=none', '--target-engine=sqlite',
                `--new-site-url=${targetUrl}`, `--flatten-to=${flatDirectory}`, `--output-dir=${runtimeDirectory}`,
            ],
        });
        assert.equal(result.exitCode, 0, `Pull failed:\n${result.stdout}\n${result.stderr}`);

        const serverLog = join(temporaryDirectory, 'target-server.log');
        const log = openSync(serverLog, 'a');
        // PHP_BINARY selects the importer under test and may be a Playground
        // wrapper. The requested php-builtin target needs a native PHP server,
        // so do not reuse that importer command to serve the migrated site.
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', flatDirectory, join(runtimeDirectory, 'runtime.php')], {
            stdio: ['ignore', log, log],
        });
        closeSync(log);
        let response;
        const deadline = Date.now() + 10000;
        while (Date.now() < deadline && server.exitCode === null) {
            try {
                response = await fetch(targetUrl, { redirect: 'manual', signal: AbortSignal.timeout(5000) });
                break;
            } catch {
                await sleep(50);
            }
        }
        assert.ok(response, `Target did not listen:\n${readFileSync(serverLog, 'utf8').slice(-4000)}`);
        const html = await response.text();
        assert.equal(response.status, 200,
            `Target returned HTTP ${response.status}, Location: ${response.headers.get('location')}\n${html.slice(0, 1000)}\n${readFileSync(serverLog, 'utf8').slice(-4000)}`);
        assert.ok(html.includes('Hello world!'), 'The target must render the migrated WordPress post');
        await inspect({ temporaryDirectory, flatDirectory, runtimeDirectory, importUrl, targetUrl, result, html });
    } finally {
        if (server && server.exitCode === null) {
            const exited = once(server, 'exit');
            server.kill();
            await exited;
        }
        cleanupTempDir(temporaryDirectory);
    }
}
