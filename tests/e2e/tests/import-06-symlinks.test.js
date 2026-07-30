/**
 * Test 06: Symlinks via import.php
 * Tests that symlinks-outside and circular-symlinks sites sync properly.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, mkdirSync, writeFileSync, symlinkSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertFileCount, assertSiteMirror,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Symlinks', () => {
    describe('symlinks-outside', () => {
        const site = 'symlinks-outside';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site, {
                afterCreate: async (siteDir) => {
                    // External dir may be nginx-owned from a previous run
                    execSync('sudo rm -rf /tmp/e2e-external-data');
                    mkdirSync('/tmp/e2e-external-data', { recursive: true });
                    writeFileSync('/tmp/e2e-external-data/external.txt', 'External file\n');
                    symlinkSync('/tmp/e2e-external-data', join(siteDir, 'test-data', 'external-link'));
                },
                afterPermissions: async () => {
                    execSync('sudo chown -R nginx:nginx /tmp/e2e-external-data');
                },
            });
            tempDir = createTempDir('e2e-import-symlinks');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        }

        it('file sync completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-pull', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('regular files are downloaded and match source', () => {
            const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
            assertTreesMatch(getSiteDir(site), importedRoot);
        });

        it('indexed at least 3000 files from remote', () => {
            assertFileCount(tempDir);
        });

        it('imported files form a valid WordPress site mirror', () => {
            assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
        });

        it('sync completed without error despite symlinks', () => {
            const stateFile = join(tempDir, '.import-state.json');
            assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.active_resumable_command.completion_state, 'complete', 'Expected status to be complete');
        });
    });

    describe('circular-symlinks', () => {
        const site = 'circular-symlinks';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site, {
                afterCreate: async (siteDir) => {
                    const dataDir = join(siteDir, 'test-data');
                    symlinkSync(join(dataDir, 'link-b'), join(dataDir, 'link-a'));
                    symlinkSync(join(dataDir, 'link-a'), join(dataDir, 'link-b'));
                    symlinkSync(join(dataDir, 'self-link'), join(dataDir, 'self-link'));
                },
            });
            tempDir = createTempDir('e2e-import-circular');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        }

        it('file sync completes within timeout (no infinite loop)', () => {
            const result = runImporter(importUrl(), tempDir, 'files-pull', {
                secret: getSiteSecret(site),
                timeout: 60000,
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('regular files are downloaded and match source', () => {
            const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
            assertTreesMatch(getSiteDir(site), importedRoot);
        });
    });
});
