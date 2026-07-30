/**
 * Test 15: Volatile Files and Deleted Dirs via import.php
 * Uses test hooks to modify files and delete directories during export,
 * verifying the importer detects and reports these changes.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, assertTreesMatch, readAuditLog,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Volatile Files', () => {
    describe('volatile-file site', () => {
        const site = 'volatile-file';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site);
            tempDir = createTempDir('e2e-import-volatile');
            clearHookState(site);
            // Create a large file (>5MB) so it spans multiple chunks.
            // The hook touches it on chunk 1; the ctime check on chunk 2 detects the change.
            const siteDir = getSiteDir(site);
            execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/large-volatile.bin" bs=1M count=6 2>/dev/null`);
            execSync(`sudo chown nginx:nginx "${siteDir}/test-data/large-volatile.bin"`);
            writeTestHooks(site, [
                'function test_hook_before_file_chunk($path, $offset, &$data) {',
                '    $state_file = \'/srv/e2e-sites/.e2e-hook-state-volatile-file\';',
                '    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];',
                '    if (!empty($state[\'fired\'])) return;',
                '',
                '    // Fire on large-volatile.bin at offset 0 (first chunk of multi-chunk file)',
                '    if (strpos($path, \'large-volatile.bin\') !== false && $offset === 0) {',
                '        // sleep(1) ensures ctime advances (Linux ctime has 1-second precision)',
                '        sleep(1);',
                '        touch($path);',
                '        clearstatcache(true, $path);',
                '        $state[\'fired\'] = true;',
                '        file_put_contents($state_file, json_encode($state));',
                '    }',
                '}',
            ].join('\n'));
            writeHookState(site, { fired: false });
        });

        afterAll(() => {
            removeTestHooks(site);
            clearHookState(site);
            cleanupTempDir(tempDir);
            // Clean up large test file
            const siteDir = getSiteDir(site);
            execSync(`sudo rm -f "${siteDir}/test-data/large-volatile.bin" 2>/dev/null || true`);
        });

        it('file sync completes', () => {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret(site),
                timeout: 120000,
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('audit log records file_changed for large-volatile.bin', () => {
            const audit = readAuditLog(tempDir);
            // The hook touched large-volatile.bin mid-stream, which should trigger
            // either a REMOTE ERROR with type=file_changed, or a VOLATILE entry
            const hasRemoteError = audit.includes('REMOTE ERROR') && audit.includes('file_changed');
            const hasVolatile = audit.includes('VOLATILE');
            assert.ok(
                hasRemoteError || hasVolatile,
                `Expected file_changed error or VOLATILE entry in audit log, got:\n${audit.slice(0, 2000)}`
            );
        });

        it('downloaded files have correct hashes (no corruption)', () => {
            const siteDir = getSiteDir(site);
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            // allowMissing: hook's sleep(1) slows export, so sync may be incomplete
            assertTreesMatch(siteDir, importedRoot, { exclude: ['large-volatile.bin'], allowMissing: true });
        });

        it('hook fired during export', () => {
            const state = readHookState(site);
            assert.ok(state && state.fired, 'Expected hook to have fired');
        });
    });

    describe('dir-deleted site', () => {
        const site = 'dir-deleted';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site);
            tempDir = createTempDir('e2e-import-dir-deleted');
            clearHookState(site);
            // Deploy hook that deletes the test-data/subdir directory on first dir scan.
            writeTestHooks(site, [
                'function test_hook_during_dir_scan($dir, &$entries) {',
                '    $state_file = \'/srv/e2e-sites/.e2e-hook-state-dir-deleted\';',
                '    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];',
                '    if (!empty($state[\'fired\'])) return;',
                '',
                '    // Only fire when scanning test-data directory',
                '    if (strpos($dir, \'test-data\') !== false && strpos($dir, \'test-data/\') === false) {',
                '        $subdir = $dir . \'/subdir\';',
                '        if (is_dir($subdir)) {',
                '            $files = new RecursiveIteratorIterator(',
                '                new RecursiveDirectoryIterator($subdir, RecursiveDirectoryIterator::SKIP_DOTS),',
                '                RecursiveIteratorIterator::CHILD_FIRST',
                '            );',
                '            foreach ($files as $f) {',
                '                if ($f->isDir()) {',
                '                    rmdir($f->getRealPath());',
                '                } else {',
                '                    unlink($f->getRealPath());',
                '                }',
                '            }',
                '            rmdir($subdir);',
                '            $state[\'fired\'] = true;',
                '            file_put_contents($state_file, json_encode($state));',
                '        }',
                '    }',
                '}',
            ].join('\n'));
            writeHookState(site, { fired: false });
        });

        afterAll(async () => {
            removeTestHooks(site);
            clearHookState(site);
            cleanupTempDir(tempDir);
            // Recreate deleted subdir for future test runs
            const siteDir = getSiteDir(site);
            execSync(`sudo mkdir -p "${siteDir}/test-data/subdir/nested"`);
            execSync(`sudo bash -c 'echo "Test file content" > "${siteDir}/test-data/subdir/test.txt"'`);
            execSync(`sudo bash -c 'echo "Nested file" > "${siteDir}/test-data/subdir/nested/deep.txt"'`);
            execSync(`sudo chown -R nginx:nginx "${siteDir}/test-data/subdir"`);
        });

        it('file sync completes', () => {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = join(tempDir, '.reprint/pull/state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });

        it('readable files are still downloaded', () => {
            const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least some files downloaded');

            assert.ok(
                [...hashes.keys()].some(p => p.includes('hello.txt')),
                'Expected hello.txt to be present'
            );
        });

        it('files from deleted subdir are not in the import', () => {
            // The hook deleted test-data/subdir during the directory scan.
            // The scanner silently skips entries that no longer stat, so
            // files from subdir should not be indexed or downloaded.
            const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
            const allFiles = [...hashDirectory(importedRoot).keys()];
            const deletedSubdirPrefix = join('test-data', 'subdir');
            const subdirFiles = allFiles.filter(
                p => p === deletedSubdirPrefix || p.startsWith(`${deletedSubdirPrefix}/`)
            );
            assert.equal(
                subdirFiles.length, 0,
                `Expected no files from deleted subdir, but found: ${subdirFiles.join(', ')}`
            );
        });

        it('hook fired during export', () => {
            const state = readHookState(site);
            assert.ok(state && state.fired, 'Expected hook to have fired');
        });
    });
});
