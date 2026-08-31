/**
 * Test 65: files-pull continues after partial source responses.
 *
 * The source pauses before every index batch. With a one-second source
 * execution budget, that forces several partial index responses. A single
 * files-pull process must continue them while local PHP memory has room.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    writeTestHooks, removeTestHooks,
    readHookState, clearHookState, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: files-pull internal loop', { timeout: 120000 }, () => {
    const site = 'files-pull-internal-loop';
    const hookState = `/srv/e2e-sites/.e2e-hook-state-${site}`;
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                const manyFilesDirectory = join(siteDir, 'test-data', 'many-files');
                mkdirSync(manyFilesDirectory, { recursive: true });
                for (let number = 1; number <= 400; number++) {
                    writeFileSync(
                        join(manyFilesDirectory, `file-${number}.txt`),
                        `content-${number}`,
                    );
                }
            },
        });
        tempDir = createTempDir('e2e-files-pull-internal-loop');
        clearHookState(site);
        writeTestHooks(site, [
            'function test_hook_before_index_batch(&$batch_items, $directory_stack) {',
            `    $state_file = '${hookState}';`,
            "    $state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : array('batches' => 0);",
            "    $state['batches']++;",
            '    e2e_write_hook_state($state_file, $state);',
            '    usleep(600000);',
            '}',
        ].join('\n'));
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('completes partial index responses in one process', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            autoResume: false,
            timeout: 120000,
            extraArgs: [
                '--max-exec=1',
                '--index-batch-start=100',
                '--index-batch-max=100',
            ],
        });

        assert.equal(
            result.exitCode,
            0,
            `Expected exit 0 from one files-pull process\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
        );
        assert.ok(
            readHookState(site).batches >= 3,
            'Expected the source to emit more than one index batch',
        );

        const state = JSON.parse(readFileSync(
            join(pullStateDirectory(tempDir, importUrl()), 'state.json'),
            'utf-8',
        ));
        assert.equal(
            state.active_resumable_command.completion_state,
            'complete',
            'Expected files-pull to complete within one process',
        );
    });
});
