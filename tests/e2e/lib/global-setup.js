/**
 * Vitest globalSetup — downloads WP-CLI once before any test file runs.
 * Individual tests provision their own sites via ensureSite() in beforeAll.
 */
import { existsSync, openSync, closeSync, unlinkSync, writeFileSync, constants } from 'node:fs';
import { execSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';

const WP_CLI_PATH = '/tmp/wp-cli.phar';
const WP_CLI_READY = '/tmp/wp-cli.ready';
const WP_CLI_LOCK = '/tmp/wp-cli-downloading.lock';

async function ensureWpCli() {
    if (existsSync(WP_CLI_READY)) {
        return;
    }

    let acquired = false;
    try {
        const fd = openSync(WP_CLI_LOCK, constants.O_CREAT | constants.O_EXCL | constants.O_WRONLY);
        closeSync(fd);
        acquired = true;
    } catch {
        // Another process holds the lock — wait for it
    }

    if (acquired) {
        try {
            if (existsSync(WP_CLI_READY)) {
                return;
            }
            console.log('Downloading WP-CLI...');
            execSync(
                `curl -sfL "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -o "${WP_CLI_PATH}"`,
                { timeout: 120000 }
            );
            execSync(`chmod +x "${WP_CLI_PATH}"`);
            writeFileSync(WP_CLI_READY, 'ready\n');
            console.log(`WP-CLI ready at ${WP_CLI_PATH}`);
        } finally {
            try { unlinkSync(WP_CLI_LOCK); } catch {}
        }
    } else {
        const deadline = Date.now() + 120000;
        while (!existsSync(WP_CLI_READY)) {
            if (Date.now() > deadline) {
                throw new Error('Timed out waiting for WP-CLI download');
            }
            await sleep(500);
        }
    }
}

export async function setup() {
    await ensureWpCli();
}
