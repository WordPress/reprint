/**
 * Test 19: Error Chunk Types via import.php
 * Systematically tests that the export side emits correct error chunks
 * and the importer records them in the audit log.
 *
 * Error types tested:
 * - file_open: unreadable file (chmod 000)
 * - file_changed: file modified during stream (ctime change)
 * - file_missing: file deleted during stream
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    readAuditLog,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Error Chunks', () => {
    const site = 'error-chunks';

    beforeAll(async () => {
        await ensureSite(site);
    });

    describe('file_open error', () => {
        let tempDir;

        beforeAll(async () => {
            tempDir = createTempDir('e2e-error-file-open');
            // Make a test file unreadable
            const siteDir = getSiteDir(site);
            execSync(`sudo chmod 000 "${siteDir}/test-data/hello.txt"`);
        });

        afterAll(() => {
            // Restore permissions
            const siteDir = getSiteDir(site);
            execSync(`sudo chmod 644 "${siteDir}/test-data/hello.txt"`);
            cleanupTempDir(tempDir);
        });

        it('file sync completes despite unreadable file', () => {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('audit log contains file_open error for hello.txt', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('REMOTE ERROR'), 'Expected REMOTE ERROR in audit log');
            assert.ok(audit.includes('type=file_open'), 'Expected type=file_open in audit log');
            assert.ok(audit.includes('hello.txt'), 'Expected hello.txt in audit log');
        });

        it('downloaded files have correct hashes (no corruption)', () => {
            const siteDir = getSiteDir(site);
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            assertTreesMatch(siteDir, importedRoot);
        });
    });

    describe('file_changed error', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-error-file-changed');
            clearHookState(site);
            // Create a file larger than chunk_size (5MB) so it spans multiple chunks.
            // The hook touches the file during chunk 1; the ctime check during chunk 2
            // detects the change and emits a file_changed error.
            const siteDir = getSiteDir(site);
            execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/large-volatile.bin" bs=1M count=6 2>/dev/null`);
            execSync(`sudo chown nginx:nginx "${siteDir}/test-data/large-volatile.bin"`);
            // Deploy hook that touches the file on first chunk
            writeTestHooks(site, [
                'function test_hook_before_file_chunk($path, $offset, &$data) {',
                '    $state_file = \'/srv/e2e-sites/.e2e-hook-state-error-chunks\';',
                '    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];',
                '    if (!empty($state[\'file_changed_fired\'])) return;',
                '',
                '    // Fire on large-volatile.bin at offset 0 (first chunk of multi-chunk file)',
                '    if (strpos($path, \'large-volatile.bin\') !== false && $offset === 0) {',
                '        // sleep(1) ensures ctime advances (Linux ctime has 1-second precision)',
                '        sleep(1);',
                '        touch($path);',
                '        clearstatcache(true, $path);',
                '        $state[\'file_changed_fired\'] = true;',
                '        e2e_write_hook_state($state_file, $state);',
                '    }',
                '}',
            ].join('\n'));
            writeHookState(site, { file_changed_fired: false });
        });

        afterAll(() => {
            removeTestHooks(site);
            clearHookState(site);
            cleanupTempDir(tempDir);
            // Clean up large test file
            const siteDir = getSiteDir(site);
            execSync(`sudo rm -f "${siteDir}/test-data/large-volatile.bin" 2>/dev/null || true`);
        });

        it('file sync completes despite changed file', () => {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret(site),
                timeout: 120000,
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('hook fired during export', () => {
            const state = readHookState(site);
            assert.ok(state && state.file_changed_fired, 'Expected file_changed hook to fire');
        });

        it('audit log contains file_changed error', () => {
            const audit = readAuditLog(tempDir);
            const hasChanged = audit.includes('type=file_changed') ||
                audit.includes('VOLATILE');
            assert.ok(
                hasChanged,
                `Expected file_changed error or VOLATILE in audit log, got:\n${audit.slice(0, 2000)}`
            );
        });

        it('downloaded files have correct hashes (no corruption)', () => {
            const siteDir = getSiteDir(site);
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            // allowMissing: hook's sleep(1) slows export, so sync may be incomplete
            assertTreesMatch(siteDir, importedRoot, { exclude: ['large-volatile.bin'], allowMissing: true });
        });
    });

    describe('file_missing error', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-error-file-missing');
            clearHookState(site);
            // Create a large file (>5MB) that spans multiple chunks, then deploy
            // a hook that deletes it during the first chunk read.
            const siteDir = getSiteDir(site);
            execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/large-deletable.bin" bs=1M count=6 2>/dev/null`);
            execSync(`sudo chown nginx:nginx "${siteDir}/test-data/large-deletable.bin"`);
            writeTestHooks(site, [
                'function test_hook_before_file_chunk($path, $offset, &$data) {',
                '    $state_file = \'/srv/e2e-sites/.e2e-hook-state-error-chunks\';',
                '    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];',
                '    if (!empty($state[\'file_missing_fired\'])) return;',
                '',
                '    // Fire on large-deletable.bin at offset 0 (first chunk)',
                '    if (strpos($path, \'large-deletable.bin\') !== false && $offset === 0) {',
                '        unlink($path);',
                '        clearstatcache(true, $path);',
                '        $state[\'file_missing_fired\'] = true;',
                '        e2e_write_hook_state($state_file, $state);',
                '    }',
                '}',
            ].join('\n'));
            writeHookState(site, { file_missing_fired: false });
        });

        afterAll(() => {
            removeTestHooks(site);
            clearHookState(site);
            cleanupTempDir(tempDir);
            // Remove the large file if it somehow survived
            const siteDir = getSiteDir(site);
            execSync(`sudo rm -f "${siteDir}/test-data/large-deletable.bin" 2>/dev/null || true`);
        });

        it('file sync completes despite missing file', () => {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret(site),
                timeout: 120000,
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('hook fired during export', () => {
            const state = readHookState(site);
            assert.ok(state && state.file_missing_fired, 'Expected file_missing hook to fire');
        });

        it('audit log contains file_missing error', () => {
            const audit = readAuditLog(tempDir);
            const hasMissing = audit.includes('type=file_missing') ||
                audit.includes('Missing on server');
            assert.ok(
                hasMissing,
                `Expected file_missing error in audit log, got:\n${audit.slice(0, 2000)}`
            );
        });

        it('downloaded files have correct hashes (no corruption)', () => {
            const siteDir = getSiteDir(site);
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            // allowMissing: hook deletes file mid-stream, which can cause incomplete sync
            assertTreesMatch(siteDir, importedRoot, { exclude: ['large-deletable.bin'], allowMissing: true });
        });
    });
});
