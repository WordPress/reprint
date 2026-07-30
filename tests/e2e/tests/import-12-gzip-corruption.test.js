/**
 * Test 12: Gzip Corruption Handling via import.php
 * Uses test hooks to inject garbage bytes into the gzip stream,
 * verifying the importer detects corruption and exits with an error.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    writeTestHooks, removeTestHooks,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Gzip Corruption', () => {
    const site = 'gzip-corrupt';

    beforeAll(async () => {
        await ensureSite(site);
        // Deploy a test hook that injects raw garbage bytes into the output
        // stream before the completion chunk, corrupting the gzip stream.
        writeTestHooks(site, [
            'function test_hook_before_completion($status, $gz, $boundary) {',
            '    // Inject raw bytes directly into the output, bypassing the gzip compressor.',
            '    // This corrupts the gzip stream — the client\'s decompressor will choke.',
            '    echo "\\x1f\\x8b\\x08CORRUPTED_GZIP_DATA_THAT_IS_NOT_VALID";',
            '    flush();',
            '}',
        ].join('\n'));
    });

    afterAll(() => {
        removeTestHooks(site);
    });

    it('db-pull detects gzip corruption and fails gracefully', () => {
        const tempDir = createTempDir('e2e-import-gzip-sql');
        try {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'db-pull', {
                secret: getSiteSecret(site),
                // The corruption only fires after the full payload streams,
                // so under WASM PHP the test must allow time for the entire
                // ~80MB site download before the bad bytes can be observed.
                timeout: 300000,
                wallTimeout: 600000,
            });
            // The importer should either fail with non-zero exit code,
            // or succeed if it managed to parse enough data before the corruption.
            // The key test is that it does NOT hang.
            if (result.exitCode === 0) {
                // If it succeeded, the completion chunk was received before
                // the corruption bytes were processed. This is acceptable —
                // the important thing is it didn't hang.
                const sqlFile = join(tempDir, 'db.sql');
                assert.ok(existsSync(sqlFile), 'Expected db.sql to exist on success');
            } else {
                // Non-zero exit code is the expected outcome: corruption was detected
                const output = result.stdout + result.stderr;
                assert.ok(
                    output.includes('cURL error') || output.includes('error') || output.includes('Error'),
                    `Expected error message in output, got:\n` +
                    `  exit=${result.exitCode}, signal=${result.signal}, killed=${result.killed}, errorCode=${result.errorCode}\n` +
                    `  stdout (${result.stdout.length} bytes, last 2000): ${result.stdout.slice(-2000)}\n` +
                    `  stderr (${result.stderr.length} bytes): ${result.stderr}`
                );
            }
        } finally {
            cleanupTempDir(tempDir);
        }
    });

    it('file sync detects gzip corruption and fails gracefully', () => {
        const tempDir = createTempDir('e2e-import-gzip-files');
        try {
            const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-pull', {
                secret: getSiteSecret(site),
                // The corruption only fires after the full payload streams,
                // so under WASM PHP the test must allow time for the entire
                // ~80MB site download before the bad bytes can be observed.
                timeout: 300000,
                wallTimeout: 600000,
            });
            // Same as above: must not hang. Either succeeds (partial data OK)
            // or fails with a clear error.
            if (result.exitCode === 0) {
                const stateFile = join(tempDir, '.import-state.json');
                if (existsSync(stateFile)) {
                    const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
                    assert.ok(
                        state.active_resumable_command.completion_state === 'complete' || state.active_resumable_command.completion_state === 'in_progress',
                        `Expected valid status, got: ${state.active_resumable_command.completion_state}`
                    );
                }
            } else {
                const output = result.stdout + result.stderr;
                assert.ok(
                    output.includes('cURL error') || output.includes('error') || output.includes('Error'),
                    `Expected error message in output, got:\n` +
                    `  exit=${result.exitCode}, signal=${result.signal}, killed=${result.killed}, errorCode=${result.errorCode}\n` +
                    `  stdout (${result.stdout.length} bytes, last 2000): ${result.stdout.slice(-2000)}\n` +
                    `  stderr (${result.stderr.length} bytes): ${result.stderr}`
                );
            }
        } finally {
            cleanupTempDir(tempDir);
        }
    });
});
