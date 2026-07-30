/**
 * Test 25: State File Corruption via import.php
 * Tests importer behavior when bootstrap state is corrupted or contains
 * unexpected data. Verifies the importer recovers gracefully.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { writeFileSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, readAuditLog,
    fsRootDir,
    getPullStatePath,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: State Corruption', () => {
    const site = 'basic';

    beforeAll(async () => {
        await ensureSite(site);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    describe('corrupted JSON in state file', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-state-corrupt-json');
            // Write invalid JSON to state file
            writeFileSync(join(tempDir, '.import-state.json'), '{invalid json here!!!');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('importer recovers from corrupted state and completes', () => {
            // The importer detects corrupt JSON, renames the file, and starts fresh
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0 (graceful recovery)\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = getPullStatePath(tempDir);
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });

        it('audit log records corruption warning', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(
                audit.includes('corrupt') || audit.includes('Warning') || audit.includes('starting fresh'),
                `Expected corruption warning in audit log, got:\n${audit.slice(0, 1000)}`
            );
        });

        it('corrupt state file was renamed', () => {
            const files = require('node:fs').readdirSync(tempDir);
            const corruptFiles = files.filter(f => f.includes('.corrupt.'));
            assert.ok(corruptFiles.length > 0, 'Expected corrupt state file to be renamed');
        });

        it('files match source after recovery', () => {
            const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
            assertTreesMatch(getSiteDir(site), importedRoot);
        });
    });

    describe('state file with wrong command', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-state-wrong-cmd');
            // Write valid state but for a different command
            writeFileSync(join(tempDir, '.import-state.json'), JSON.stringify({
                active_resumable_command: {
                    command_name: 'db-pull',
                    completion_state: 'complete',
                },
            }));
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('running a different command starts fresh (ignores mismatched state)', () => {
            // The importer sees command mismatch and treats it as a fresh start
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = getPullStatePath(tempDir);
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.active_resumable_command.command_name, 'files-pull', 'Expected command to be updated');
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });
    });

    describe('--abort flag', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-state-restart');
            // Write a partial state to simulate interrupted transfer
            writeFileSync(join(tempDir, '.import-state.json'), JSON.stringify({
                active_resumable_command: {
                    command_name: 'files-pull',
                    completion_state: 'in_progress',
                    remote_cursor: 'some-old-cursor',
                },
            }));
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('--abort clears state and exits', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--abort'],
            });
            assert.equal(result.exitCode, 0, `Expected exit 0 with --abort\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = getPullStatePath(tempDir);
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.notEqual(state.active_resumable_command.completion_state, 'in_progress', 'Expected status to be cleared');
            assert.ok(!state.active_resumable_command.remote_cursor, 'Expected cursor to be cleared');
        });

        it('running after --abort completes fresh sync', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = getPullStatePath(tempDir);
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });

        it('files match source after restart', () => {
            const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
            assertTreesMatch(getSiteDir(site), importedRoot);
        });
    });
});
