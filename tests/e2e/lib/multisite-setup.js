import { readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { ensureSite } from './site-setup.js';
import { getSiteDir, getSiteUrl } from './test-helpers.js';

/** Each test file uses its own network so token and target tests cannot race. */
export async function ensureMultisite(name) {
    await ensureSite(name, {
        tablePrefix: 'network_', files: 'none',
        afterCreate: async (directory) => {
            convertToMultisite(directory, getSiteUrl(name));
        },
    });
    return JSON.parse(readFileSync(join(getSiteDir(name), '.multisite-layer.json'), 'utf8'));
}

/** Convert an installed MySQL or SQLite site to the same three-site fixture. */
export function convertToMultisite(directory, url) {
    runWp(directory, ['core', 'multisite-convert', '--title=Source network', '--base=/', '--skip-config']);
    const config = join(directory, 'wp-config.php');
    const constants = `define('MULTISITE', true);
define('SUBDOMAIN_INSTALL', false);
define('DOMAIN_CURRENT_SITE', '${new URL(url).host}');
define('PATH_CURRENT_SITE', '/');
define('SITE_ID_CURRENT_SITE', 1);
define('BLOG_ID_CURRENT_SITE', 1);
`;
    writeFileSync(config, readFileSync(config, 'utf8').replace('$table_prefix =', constants + '$table_prefix ='));
    runWp(directory, ['plugin', 'activate', 'reprint-server', '--network']);
    runWp(directory, ['eval-file', join(import.meta.dirname, '../fixtures/multisite-layer.php')]);
}

export function runWp(directory, args, url = null) {
    return execFileSync(process.env.E2E_WP_CLI_PHP_BINARY || 'php', [
        '/tmp/wp-cli.phar', '--allow-root', `--path=${directory}`,
        ...(url ? [`--url=${url}`] : []), ...args,
    ], { encoding: 'utf8', timeout: 120000, maxBuffer: 10 * 1024 * 1024 });
}
