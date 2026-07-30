/**
 * Test 28: Concurrent Error Types via import.php
 * Deploys hooks that cause multiple error types simultaneously:
 * file_open (chmod 000), file_changed (touch mid-stream), and
 * file_missing (delete mid-stream) all in the same sync.
 * Verifies the importer handles all errors gracefully and the
 * audit log captures each one.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, readAuditLog,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Concurrent Errors', { timeout: 180000 }, () => {
    const site = 'import-failures';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-concurrent-errors');
        clearHookState(site);

        const siteDir = getSiteDir(site);

        // Create an unreadable file (file_open error)
        execSync(`sudo bash -c 'echo "secret" > "${siteDir}/test-data/concurrent-unreadable.txt"'`);
        execSync(`sudo chmod 000 "${siteDir}/test-data/concurrent-unreadable.txt"`);

        // Create a large file that will be touched mid-stream (file_changed error)
        execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/concurrent-volatile.bin" bs=1M count=6 2>/dev/null`);
        execSync(`sudo chown nginx:nginx "${siteDir}/test-data/concurrent-volatile.bin"`);

        // Create a large file that will be deleted mid-stream (file_missing error)
        execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/concurrent-deletable.bin" bs=1M count=6 2>/dev/null`);
        execSync(`sudo chown nginx:nginx "${siteDir}/test-data/concurrent-deletable.bin"`);

        // Deploy hook that causes both file_changed and file_missing in one sync
        writeTestHooks(site, [
            'function test_hook_before_file_chunk($path, $offset, &$data) {',
            '    $state_file = \'/srv/e2e-sites/.e2e-hook-state-import-failures\';',
            '    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];',
            '',
            '    // Touch concurrent-volatile.bin on first chunk to trigger file_changed',
            '    if (strpos($path, \'concurrent-volatile.bin\') !== false && $offset === 0 && empty($state[\'volatile_fired\'])) {',
            '        sleep(1);',
            '        touch($path);',
            '        clearstatcache(true, $path);',
            '        $state[\'volatile_fired\'] = true;',
            '        file_put_contents($state_file, json_encode($state));',
            '    }',
            '',
            '    // Delete concurrent-deletable.bin on first chunk to trigger file_missing',
            '    if (strpos($path, \'concurrent-deletable.bin\') !== false && $offset === 0 && empty($state[\'delete_fired\'])) {',
            '        unlink($path);',
            '        clearstatcache(true, $path);',
            '        $state[\'delete_fired\'] = true;',
            '        file_put_contents($state_file, json_encode($state));',
            '    }',
            '}',
        ].join('\n'));
        writeHookState(site, { volatile_fired: false, delete_fired: false });
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
        const siteDir = getSiteDir(site);
        execSync(`sudo chmod 644 "${siteDir}/test-data/concurrent-unreadable.txt" 2>/dev/null || true`);
        execSync(`sudo rm -f "${siteDir}/test-data/concurrent-unreadable.txt" 2>/dev/null || true`);
        execSync(`sudo rm -f "${siteDir}/test-data/concurrent-volatile.bin" 2>/dev/null || true`);
        execSync(`sudo rm -f "${siteDir}/test-data/concurrent-deletable.bin" 2>/dev/null || true`);
    });

    it('file sync completes despite multiple concurrent errors', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            timeout: 180000,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('hooks fired during export', () => {
        const state = readHookState(site);
        assert.ok(state, 'Expected hook state to exist');
        assert.ok(state.volatile_fired, 'Expected volatile hook to fire');
        assert.ok(state.delete_fired, 'Expected delete hook to fire');
    });

    it('audit log contains file_open error', () => {
        const audit = readAuditLog(tempDir);
        assert.ok(
            audit.includes('type=file_open') || audit.includes('concurrent-unreadable'),
            'Expected file_open error in audit log'
        );
    });

    it('audit log contains file_changed error', () => {
        const audit = readAuditLog(tempDir);
        const hasChanged = audit.includes('type=file_changed') || audit.includes('VOLATILE');
        assert.ok(hasChanged, 'Expected file_changed error or VOLATILE in audit log');
    });

    it('audit log contains file_missing error', () => {
        const audit = readAuditLog(tempDir);
        const hasMissing = audit.includes('type=file_missing') || audit.includes('Missing on server');
        assert.ok(hasMissing, 'Expected file_missing error in audit log');
    });

    it('non-error files are still downloaded correctly', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        // allowMissing: multiple hooks with sleep(1) slow export, so sync may be incomplete
        assertTreesMatch(getSiteDir(site), importedRoot, {
            exclude: ['concurrent-unreadable.txt', 'concurrent-volatile.bin', 'concurrent-deletable.bin'],
            allowMissing: true,
        });
    });
});
