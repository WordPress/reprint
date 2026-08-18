/**
 * Test 60: Preflight with an open_basedir boundary above WordPress
 *
 * The site itself is allowed, but its parent is not. WordPress root
 * discovery must keep the root it finds instead of failing when its
 * speculative parent scan reaches the open_basedir boundary.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    apiRequest,
    getSiteDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Preflight with an open_basedir boundary', () => {
    const site = 'open-basedir';

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                writeFileSync(
                    join(siteDir, '.user.ini'),
                    `open_basedir=${siteDir}:/tmp\n`,
                );
            },
        });
    });

    it('keeps the detected WordPress root when its parent cannot be inspected', async () => {
        const siteDir = getSiteDir(site);
        const response = await apiRequest(site, 'preflight');

        assert.equal(
            response.status,
            200,
            `Expected HTTP 200, got ${response.status}: ${JSON.stringify(response.json || response.text)}`,
        );
        assert.equal(response.json.limits.open_basedir, `${siteDir}:/tmp`);
        assert.equal(response.json.ok, true, `Preflight failed: ${response.json.error}`);
        assert.ok(response.json.wp_detect.found, 'Expected WordPress to be found');
        assert.ok(
            response.json.wp_detect.roots.some(
                (root) => root.path === siteDir && root.wp_load && root.wp_config,
            ),
            `Expected a detected WordPress root at ${siteDir}`,
        );
    });
});
