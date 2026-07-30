/**
 * Test 33: Preflight state path encoding
 *
 * Verifies that path-like fields inside preflight state are persisted as
 * base64 strings and decoded correctly on subsequent loads.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Preflight state path encoding', () => {
    const site = 'basic';
    let tempDir;

    function importUrlWithDirectory() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function assertEncoded(value, label) {
        assert.equal(typeof value, 'string', `Expected ${label} to be a string`);
        assert.ok(value.startsWith('base64:'), `Expected ${label} to be base64-encoded, got: ${value}`);
    }

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-preflight-path-encoding');

        const result = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('stores requested preflight path fields as base64 in state file', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const data = state.preflight?.data;
        assert.ok(data, 'Expected preflight.data in state');

        const searched = data.wp_detect?.searched || [];
        assert.ok(Array.isArray(searched) && searched.length > 0, 'Expected wp_detect.searched entries');
        for (const [idx, path] of searched.entries()) {
            assertEncoded(path, `wp_detect.searched[${idx}]`);
        }

        const roots = data.wp_detect?.roots || [];
        assert.ok(Array.isArray(roots) && roots.length > 0, 'Expected wp_detect.roots entries');
        for (const [idx, root] of roots.entries()) {
            if (typeof root.path === 'string') {
                assertEncoded(root.path, `wp_detect.roots[${idx}].path`);
            }
            if (typeof root.wp_load_path === 'string') {
                assertEncoded(root.wp_load_path, `wp_detect.roots[${idx}].wp_load_path`);
            }
            if (typeof root.wp_config_path === 'string') {
                assertEncoded(root.wp_config_path, `wp_detect.roots[${idx}].wp_config_path`);
            }
        }

        const runtime = data.runtime || {};
        for (const key of ['temp_dir', 'document_root', 'script_filename', 'cwd']) {
            if (typeof runtime[key] === 'string') {
                assertEncoded(runtime[key], `runtime.${key}`);
            }
        }

        // ini_get_all is NOT a path field — it should be a plain object, not encoded.
        assert.ok(
            typeof runtime.ini_get_all === 'object' && runtime.ini_get_all !== null,
            'runtime.ini_get_all should be a plain object (not base64-encoded)'
        );

        const directories = data.filesystem?.directories || [];
        assert.ok(Array.isArray(directories) && directories.length > 0, 'Expected filesystem.directories entries');
        for (const [idx, dir] of directories.entries()) {
            if (typeof dir.path === 'string') {
                assertEncoded(dir.path, `filesystem.directories[${idx}].path`);
            }
        }

        const htaccessFiles = data.htaccess?.files || [];
        if (Array.isArray(htaccessFiles)) {
            for (const [idx, file] of htaccessFiles.entries()) {
                if (typeof file.path === 'string') {
                    assertEncoded(file.path, `htaccess.files[${idx}].path`);
                }
            }
        }

        const contentRoots = data.wp_content?.roots || [];
        if (Array.isArray(contentRoots)) {
            for (const [idx, root] of contentRoots.entries()) {
                if (typeof root.root === 'string') {
                    assertEncoded(root.root, `wp_content.roots[${idx}].root`);
                }
                if (typeof root.content_dir === 'string') {
                    assertEncoded(root.content_dir, `wp_content.roots[${idx}].content_dir`);
                }
            }
        }
    });

    it('decodes encoded preflight roots when loading state', () => {
        const result = runImporter(getSiteUrl(site), tempDir, 'files-index', {
            secret: getSiteSecret(site),
            skipPreflight: true,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });
});
