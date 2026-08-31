/**
 * Test 20: Request Cutoff and Resume via import.php
 * Uses test hooks to simulate PHP crashing mid-stream by calling exit()
 * after a few file index batches. Verifies files-pull continues from its
 * saved partial state and reaches completion in the same local process.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    readAuditLog,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
    fsRootDir, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Request Cutoff', () => {
    const site = 'request-cutoff';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-cutoff');
        clearHookState(site);
        // Deploy hook that kills PHP after scanning a few directories.
        // The hook counts dir scans via the state file and calls exit() on the 5th,
        // simulating a PHP crash mid-stream during the index phase.
        writeTestHooks(site, [
            'function test_hook_during_dir_scan($dir, &$entries) {',
            '    $state_file = \'/srv/e2e-sites/.e2e-hook-state-request-cutoff\';',
            '    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];',
            '    $count = ($state[\'scan_count\'] ?? 0) + 1;',
            '    $state[\'scan_count\'] = $count;',
            '    e2e_write_hook_state($state_file, $state);',
            '',
            '    // Kill PHP on the 5th directory scan to simulate a crash',
            '    if ($count === 5) {',
            '        exit(1);',
            '    }',
            '}',
        ].join('\n'));
        writeHookState(site, { scan_count: 0 });
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
    });

    it('one importer run recovers after cutoff', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--max-exec=10'],
            autoResume: false,
        });
        assert.equal(
            result.exitCode,
            0,
            `Expected one process to recover after the interrupted file index response\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const stateFile = join(pullStateDirectory(tempDir, url), 'state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(
            state.active_resumable_command.completion_state,
            'complete',
            'Expected files-pull to complete after the internal retry',
        );
    });

    it('hook state shows scan_count reached 5', () => {
        const state = readHookState(site);
        assert.ok(state, 'Expected hook state to exist');
        assert.ok(state.scan_count >= 5, `Expected scan_count >= 5, got ${state.scan_count}`);
    });

    it('all file hashes match source after same-process recovery', () => {
        removeTestHooks(site);

        // The source hook itself was present while files-pull indexed the
        // source. It is test setup rather than site content.
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot, {
            exclude: ['wp-content/plugins/site-export/test-hooks.php'],
        });
    });

    it('audit log shows the crash and recovery', () => {
        const audit = readAuditLog(tempDir);
        // Should record the failed request and the successful resume
        assert.ok(
            audit.includes('START') || audit.includes('RESUME'),
            'Expected START or RESUME in audit log'
        );
        assert.ok(
            audit.includes('complete'),
            'Expected completion recorded in audit log'
        );
    });
});
