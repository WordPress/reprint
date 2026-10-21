/**
 * Vitest globalSetup — checks the recorded WP-CLI before tests provision sites.
 * Concurrent setup calls can share the helper's atomic cache replacement.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export async function setup() {
    execFileSync(fileURLToPath(new URL('../ci/download-wp-cli.sh', import.meta.url)), [], {
        timeout: 120000,
        stdio: 'inherit',
    });
}
