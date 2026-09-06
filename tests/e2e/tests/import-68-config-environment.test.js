/** Missing variables and oversized configs must warn without executing PHP or exposing values. */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ensureSite } from '../lib/site-setup.js';
import { getSiteDir } from '../lib/test-helpers.js';
import { withMigratedWordPress } from '../lib/migrated-wordpress.js';

describe('Import: external wp-config environment', () => {
    const variable = 'REPRINT_E2E_CONFIG_SECRET';
    const secret = 'migration-environment-secret-do-not-print';
    const sites = ['config-environment-present', 'config-environment-missing', 'config-environment-large'];

    beforeAll(async () => {
        for (const site of sites) {
            await ensureSite(site, {
                files: 'none',
                afterCreate: async (siteDirectory) => {
                    const path = join(siteDirectory, 'wp-config.php');
                    const config = readFileSync(path, 'utf8');
                    // This is valid source configuration: HTTP requests work,
                    // but importing must not execute it in the CLI process.
                    const prefix = `<?php
if (PHP_SAPI === 'cli') { throw new RuntimeException('COPIED_CONFIG_EXECUTED'); }
$migration_secret = getenv('${variable}') ?: '${secret}';
// getenv('REPRINT_E2E_COMMENT_ONLY')
$example = "getenv('REPRINT_E2E_QUOTED_EXAMPLE')";
`;
                    const padding = site === 'config-environment-large' ? '/*' + 'x'.repeat(262144) + '*/\n' : '';
                    writeFileSync(path, prefix + padding + config.slice(5));
                },
            });
        }
    });

    it('loads the target without a missing-variable warning when the importing process has the value', async () => {
        const previous = process.env[variable];
        process.env[variable] = secret;
        try {
            await withMigratedWordPress(sites[0], ({ result, temporaryDirectory, flatDirectory }) => {
                const output = result.stdout + result.stderr + readFileSync(join(temporaryDirectory, 'audit.log'), 'utf8');
                assert.ok(!output.includes('config_environment_missing'), output);
                assert.ok(!output.includes('config_not_inspected'), output);
                assert.ok(!output.includes(secret), 'Neither CLI output nor the audit log may print the value');
                assert.equal(readFileSync(join(flatDirectory, 'wp-config.php'), 'utf8'),
                    readFileSync(join(getSiteDir(sites[0]), 'wp-config.php'), 'utf8'));
            });
        } finally {
            if (previous === undefined) delete process.env[variable];
            else process.env[variable] = previous;
        }
    }, 240000);

    it('reports the missing name and still loads the target using the config fallback', async () => {
        const previous = process.env[variable];
        delete process.env[variable];
        try {
            await withMigratedWordPress(sites[1], ({ result, temporaryDirectory, flatDirectory }) => {
                const warnings = result.stdout.split('\n').filter(line => line.startsWith('{')).map(line => JSON.parse(line))
                    .filter(event => event.reason === 'config_environment_missing');
                assert.deepEqual(warnings.map(event => event.variable_name), [variable]);
                assert.equal(warnings[0].config_path, join(flatDirectory, 'wp-config.php'));
                assert.match(warnings[0].message, /not set in this process/);
                const audit = readFileSync(join(temporaryDirectory, 'audit.log'), 'utf8');
                assert.ok(audit.includes(variable), 'The audit log must name the missing variable');
                assert.ok(!(result.stdout + result.stderr + audit).includes(secret));
                assert.equal(readFileSync(join(flatDirectory, 'wp-config.php'), 'utf8'),
                    readFileSync(join(getSiteDir(sites[1]), 'wp-config.php'), 'utf8'));
            });
        } finally {
            if (previous !== undefined) process.env[variable] = previous;
        }
    }, 240000);

    it('reports an oversized config as uninspected instead of presenting a partial scan as complete', async () => {
        await withMigratedWordPress(sites[2], ({ result, temporaryDirectory, flatDirectory }) => {
            const warnings = result.stdout.split('\n').filter(line => line.startsWith('{')).map(line => JSON.parse(line))
                .filter(event => event.type === 'warning');
            assert.equal(warnings.filter(event => event.reason === 'config_not_inspected').length, 1);
            assert.ok(!warnings.some(event => event.reason === 'config_environment_missing'));
            assert.match(warnings.find(event => event.reason === 'config_not_inspected').message, /256 KiB/);
            assert.ok(!(result.stdout + result.stderr + readFileSync(join(temporaryDirectory, 'audit.log'), 'utf8')).includes(secret));
            assert.equal(readFileSync(join(flatDirectory, 'wp-config.php'), 'utf8'),
                readFileSync(join(getSiteDir(sites[2]), 'wp-config.php'), 'utf8'));
        });
    }, 240000);
});
