/**
 * Test 39: Runtime files (ini_get_all, auto_prepend/append scripts)
 *
 * Verifies that after preflight:
 * - ini_get_all is stored in preflight state with all PHP directives
 * - auto_prepend_file and auto_append_file values are captured
 * - Re-running preflight wipes and recreates runtime_files/
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Runtime files', () => {
    const site = 'basic';
    let tempDir;

    function importUrlWithDirectory() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-runtime-files');

        const result = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('preflight state contains ini_get_all with core PHP directives', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const iniAll = state.preflight?.data?.runtime?.ini_get_all;
        assert.ok(iniAll && typeof iniAll === 'object', 'runtime.ini_get_all should be an object');

        // Every PHP installation has these core directives.
        assert.ok('max_execution_time' in iniAll, 'Should contain max_execution_time');
        assert.ok('memory_limit' in iniAll, 'Should contain memory_limit');
        assert.ok('error_reporting' in iniAll, 'Should contain error_reporting');
        assert.ok('upload_max_filesize' in iniAll, 'Should contain upload_max_filesize');

        // ini_get_all(null, false) returns scalar values (local value only).
        const entry = iniAll['max_execution_time'];
        assert.equal(typeof entry, 'string', 'INI values should be strings (local value)');
    });

    it('ini_get_all includes auto_prepend_file and auto_append_file directives', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const iniAll = state.preflight?.data?.runtime?.ini_get_all;

        // These directives should always be present in ini_get_all,
        // even when empty — they're part of core PHP configuration.
        assert.ok('auto_prepend_file' in iniAll, 'ini_get_all should contain auto_prepend_file');
        assert.ok('auto_append_file' in iniAll, 'ini_get_all should contain auto_append_file');
    });

    it('audit log records runtime file activity', () => {
        const log = readAuditLog(tempDir);
        assert.ok(log.includes('RUNTIME FILES'), 'Audit log should mention RUNTIME FILES');
    });

    it('wipes runtime_files/ on preflight re-run', () => {
        // Create runtime_files/ with a stale file.
        const runtimeDir = join(tempDir, 'runtime_files');
        mkdirSync(runtimeDir, { recursive: true });
        const staleDir = join(runtimeDir, 'stale');
        mkdirSync(staleDir, { recursive: true });
        writeFileSync(join(staleDir, 'old.php'), 'should be deleted');
        assert.ok(existsSync(join(staleDir, 'old.php')), 'Stale file should exist before re-run');

        // Re-run preflight.
        const result = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        // Stale file should be gone.
        assert.ok(!existsSync(join(staleDir, 'old.php')), 'Stale file should be removed after preflight re-run');
    });
});
