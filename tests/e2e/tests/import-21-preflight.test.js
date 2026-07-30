/**
 * Test 21: Preflight Endpoint via API
 * Tests the preflight discovery endpoint returns correct environment info,
 * WordPress detection, database connectivity, and filesystem status.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest,
    getSiteUrl, getSiteSecret, getSiteDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Preflight Endpoint', () => {
    const site = 'basic';
    let preflight;

    beforeAll(async () => {
        await ensureSite(site);
        const response = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(response.status, 200, `Expected 200, got ${response.status}`);
        preflight = response.json;
    });

    it('returns ok status', () => {
        assert.ok(preflight.ok, `Expected ok=true, got error: ${preflight.error}`);
    });

    it('reports protocol version', () => {
        assert.ok(Number.isInteger(preflight.protocol_version), `Expected integer protocol_version, got ${typeof preflight.protocol_version}`);
        assert.ok(preflight.protocol_version >= 1, `Expected protocol_version >= 1, got ${preflight.protocol_version}`);
    });

    it('detects WordPress installation', () => {
        assert.ok(preflight.wp_detect, 'Expected wp_detect in response');
        assert.ok(preflight.wp_detect.found, 'Expected WordPress to be found');
        assert.ok(Array.isArray(preflight.wp_detect.roots), 'Expected roots array');
        assert.ok(preflight.wp_detect.roots.length > 0, 'Expected at least one WP root');
    });

    it('reports PHP version and extensions', () => {
        assert.ok(preflight.php, 'Expected php section');
        assert.ok(preflight.php.version, 'Expected PHP version');
        assert.match(preflight.php.version, /^[78]\./, `Expected PHP 7.x or 8.x, got ${preflight.php.version}`);
        assert.ok(Array.isArray(preflight.php.extensions), 'Expected extensions array');
        assert.ok(preflight.php.extensions.includes('pdo_mysql'), 'Expected pdo_mysql extension');
        assert.ok(preflight.php.extensions.includes('zlib'), 'Expected zlib extension');
    });

    it('reports resource limits', () => {
        assert.ok(preflight.limits, 'Expected limits section');
        assert.ok(typeof preflight.limits.ini_max_execution_time === 'number', 'Expected numeric max_execution_time');
        assert.ok(typeof preflight.limits.max_request_bytes === 'number', 'Expected numeric max_request_bytes');
        assert.ok(preflight.limits.max_request_bytes > 0, 'Expected positive max_request_bytes');
    });

    it('reports memory info', () => {
        assert.ok(preflight.memory, 'Expected memory section');
        assert.ok(typeof preflight.memory.limit_bytes === 'number', 'Expected numeric memory limit');
        assert.ok(typeof preflight.memory.used_bytes === 'number', 'Expected numeric memory used');
        assert.ok(preflight.memory.limit_bytes > 0, 'Expected positive memory limit');
    });

    it('reports filesystem accessibility', () => {
        assert.ok(preflight.filesystem, 'Expected filesystem section');
        assert.ok(preflight.filesystem.ok, 'Expected filesystem ok');
        assert.ok(Array.isArray(preflight.filesystem.directories), 'Expected directories array');
        const siteDir = preflight.filesystem.directories.find(
            d => d.path === getSiteDir(site)
        );
        assert.ok(siteDir, `Expected directory entry for ${getSiteDir(site)}`);
        assert.ok(siteDir.exists, 'Expected directory to exist');
        assert.ok(siteDir.readable, 'Expected directory to be readable');
        assert.ok(siteDir.openable, 'Expected directory to be openable');
    });

    it('reports database connectivity', () => {
        assert.ok(preflight.database, 'Expected database section');
        assert.ok(preflight.database.credentials_found, 'Expected DB credentials found');
        assert.ok(preflight.database.connected, 'Expected DB connected');
    });

    it('reports the charset/collation WordPress connects with from wpdb', () => {
        assert.ok(preflight.database.wp, 'Expected database.wp section');
        assert.equal(
            preflight.database.wp.wpdb_charset,
            'utf8mb4',
            `Expected wpdb_charset='utf8mb4', got ${JSON.stringify(preflight.database.wp.wpdb_charset)}`
        );

        assert.ok(
            typeof preflight.database.wp.wpdb_collation === 'string' &&
                preflight.database.wp.wpdb_collation.startsWith('utf8mb4'),
            `Expected a resolved utf8mb4 collation, got ${JSON.stringify(preflight.database.wp.wpdb_collation)}`
        );
    });

    it('reports WordPress content info', () => {
        assert.ok(preflight.wp_content, 'Expected wp_content section');
        assert.ok(Array.isArray(preflight.wp_content.roots), 'Expected wp_content roots array');
        if (preflight.wp_content.roots.length > 0) {
            const root = preflight.wp_content.roots[0];
            assert.ok(Array.isArray(root.plugins), 'Expected plugins array');
            assert.ok(Array.isArray(root.themes), 'Expected themes array');
        }
    });

    it('db_index returns table list', async () => {
        const response = await apiRequest(site, 'db_index', {
            directory: getSiteDir(site),
        });
        assert.equal(response.status, 200, `Expected 200, got ${response.status}`);
        assert.ok(response.chunks, 'Expected multipart response with chunks');

        // Should have table_stats chunks and a completion chunk
        const tableChunks = response.chunks.filter(c => c.headers['x-chunk-type'] === 'table_stats');
        const completion = response.chunks.find(c => c.headers['x-chunk-type'] === 'completion');

        assert.ok(tableChunks.length > 0, 'Expected at least one table_stats chunk');
        assert.ok(completion, 'Expected completion chunk');
        assert.equal(completion.headers['x-status'], 'complete', 'Expected complete status');

        // Parse table stats and verify wp_ tables exist
        const tables = [];
        for (const chunk of tableChunks) {
            if (chunk.json) {
                if (Array.isArray(chunk.json)) {
                    tables.push(...chunk.json);
                } else {
                    tables.push(chunk.json);
                }
            }
        }
        const tableNames = tables.map(t => t.name || t.table || Object.values(t)[0]);
        assert.ok(
            tableNames.some(n => typeof n === 'string' && n.includes('wp_')),
            `Expected wp_ tables, got: ${JSON.stringify(tableNames.slice(0, 5))}`
        );
    });
});
