/**
 * Test 50: Pull start-runtime none
 *
 * Verifies that `pull --runtime=playground-cli --start-runtime=none` completes
 * the full clone and runtime generation without entering the blocking
 * server startup stage.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertPullPipelineComplete,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull start-runtime none', { timeout: 180000 }, () => {
    const site = 'basic';
    const runtimePort = 9490;
    let tempDir;
    let runtimeDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-manual-start');
        runtimeDir = join(tempDir, 'runtime');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('pull exits after runtime generation when start-runtime is none', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--runtime=playground-cli',
                '--start-runtime=none',
                '--target-engine=sqlite',
                '--new-site-url',
                `http://127.0.0.1:${runtimePort}`,
                `--output-dir=${runtimeDir}`,
            ],
        });

        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        assert.ok(!result.stdout.includes('Press Ctrl-C to stop.'),
            'pull should not enter the blocking start stage when start-runtime is none');
        assert.ok(!result.stdout.includes('Ready at http://'),
            'pull should not print the server-ready banner when start-runtime is none');
    });

    it('marks the pull complete and generates playground runtime files', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assertPullPipelineComplete(state);
        assert.equal(state.active_resumable_command.completion_state, 'complete');

        assert.ok(existsSync(join(runtimeDir, 'runtime.php')), 'runtime.php should exist');
        assert.ok(existsSync(join(runtimeDir, 'blueprint.json')), 'blueprint.json should exist');
        assert.ok(existsSync(join(runtimeDir, 'start.sh')), 'start.sh should exist');
    });
});
