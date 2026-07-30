/**
 * Test 20: Request Cutoff and Resume via import.php
 * Uses test hooks to simulate PHP crashing mid-stream by calling exit()
 * after a few file index batches. Verifies the importer can resume
 * and eventually reach completion.
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
    fsRootDir,
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
            '    file_put_contents($state_file, json_encode($state));',
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

    it('first importer run exits partial after cutoff', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--max-exec=10'],
            autoResume: false,
        });
        assert.equal(
            result.exitCode,
            2,
            `Expected exit 2 after the interrupted file index response\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );

        const stateFile = join(tempDir, 'pull/state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(
            state.active_resumable_command.completion_state,
            'partial',
            'Expected files-pull to retain a partial checkpoint',
        );
        assert.equal(
            state.active_resumable_command.current_stage,
            'index',
            'Expected files-pull to remain in the index stage',
        );
    });

    it('hook state shows scan_count reached 5', () => {
        const state = readHookState(site);
        assert.ok(state, 'Expected hook state to exist');
        assert.ok(state.scan_count >= 5, `Expected scan_count >= 5, got ${state.scan_count}`);
    });

    it('importer resumes and completes after removing cutoff hook', () => {
        // Remove the crashing hook so subsequent requests succeed
        removeTestHooks(site);

        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        // Run the importer again — it should resume from saved state
        const result = runImporter(url, tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--max-exec=10'],
        });
        assert.equal(result.exitCode, 0, `Expected exit 0 on resume\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const stateFile = join(tempDir, 'pull/state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete', `Expected complete status, got ${state.active_resumable_command.completion_state}`);
    });

    it('all file hashes match source after resume', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
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
