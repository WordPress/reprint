/**
 * Test 01: Basic File Sync via import.php
 * Tests files-sync completes, files match source, and restart behavior.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertFileCount, assertSiteMirror,
    fsRootDir, getRemoteStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Basic File Sync', () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-basic-files');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-sync completes successfully', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state file shows complete', () => {
        const stateFile = join(tempDir, '.import-state.json');
        assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.command_name, 'files-pull');
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });

    it('fs-root file hashes match source site directory', () => {
        // The importer stores files at fs-root/<absolute-path>,
        // so the site dir content ends up at fs-root/srv/e2e-sites/<site>/
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('.remote-index.jsonl has entries', () => {
        const indexFile = join(
            getRemoteStateDirectory(tempDir, '.remote-index.jsonl'),
            '.remote-index.jsonl',
        );
        assert.ok(existsSync(indexFile), 'Expected .remote-index.jsonl to exist');
        const lines = readFileSync(indexFile, 'utf-8').trim().split('\n').filter(l => l);
        assert.ok(lines.length > 0, 'Expected at least one index entry');
    });

    it('indexed at least 3000 files from remote', () => {
        assertFileCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('re-running after completion refuses without --abort', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            autoResume: false,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        // In non-tty mode, the JSON output shows {"status":"complete"}
        // confirming the importer detected the completed state and returned early.
        assert.ok(
            result.stdout.includes('"status":"complete"') || result.stdout.includes('already complete'),
            `Expected completed status in output, got stdout: ${result.stdout}\nstderr: ${result.stderr}`,
        );
    });

    it('--abort clears sync progress and exits', () => {
        const restart = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(restart.exitCode, 0, `Expected restart exit 0, got ${restart.exitCode}\nstderr: ${restart.stderr}\nstdout: ${restart.stdout}`);

        const remoteStateDirectory =
            getRemoteStateDirectory(tempDir, '.remote-index.jsonl');

        // The target-observed index should still exist (restart preserves it)
        const indexFile = join(remoteStateDirectory, '.remote-index.jsonl');
        assert.ok(existsSync(indexFile), 'Expected target-observed index to be preserved after --abort');

        // Transient files should be cleaned up
        assert.ok(!existsSync(join(remoteStateDirectory, '.remote-index.next.jsonl')), 'Expected next target index to be deleted');
        assert.ok(!existsSync(join(remoteStateDirectory, 'pull-plan.jsonl')), 'Expected pull plan to be deleted');
    });

    it('running after --abort performs a delta sync', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });
});
