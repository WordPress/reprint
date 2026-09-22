/**
 * Test 01: Basic File Sync via import.php
 * Tests files-pull completes, files match source, and restart behavior.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertRemoteIndexEntryCount, assertSiteMirror,
    fsRootDir, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Basic File Sync', () => {
    // Other suites index the shared basic site in parallel. Keep these extra files separate.
    const site = 'basic-file-sync';
    let tempDir;
    const runtimeFiles = {
        'wp-content/plugins/weglot/vendor/weglot/weglot-php/node_modules/@weglot/languages/dist/Languages.php': '<?php namespace WeglotLanguages; class Languages {}',
        'wp-content/plugins/js_composer/assets/lib/vendor/node_modules/animate.css/animate.min.css': '.animated{}',
        'wp-content/themes/example/node_modules/package/index.js': 'window.themeDependency = true;',
    };

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                for (const [path, contents] of Object.entries(runtimeFiles)) {
                    const sourcePath = join(siteDir, path);
                    mkdirSync(dirname(sourcePath), { recursive: true });
                    writeFileSync(sourcePath, contents);
                }
            },
        });
        tempDir = createTempDir('e2e-import-basic-files');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-pull completes successfully', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state file shows complete', () => {
        const stateFile = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
        assert.ok(existsSync(stateFile), 'Expected pull/state.json to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.command_name, 'files-pull');
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });

    it('pulls runtime files bundled inside node_modules', () => {
        for (const [path, contents] of Object.entries(runtimeFiles)) {
            assert.equal(readFileSync(join(fsRootDir(tempDir), getSiteDir(site), path), 'utf8'), contents);
        }
    });

    it('allows explicitly excluding a node_modules directory', () => {
        const excludedTempDir = createTempDir('e2e-excluded-node-modules');
        const excludedPath = 'wp-content/plugins/js_composer/assets/lib/vendor/node_modules';
        try {
            const result = runImporter(importUrl(), excludedTempDir, 'files-pull', {
                secret: getSiteSecret(site),
                extraArgs: [
                    `--include=${join(getSiteDir(site), 'wp-content/plugins')}`,
                    `--exclude=${join(getSiteDir(site), excludedPath)}`,
                ],
            });
            assert.equal(result.exitCode, 0, result.stderr + result.stdout);
            const importedRoot = join(fsRootDir(excludedTempDir), getSiteDir(site));
            assert.ok(!existsSync(join(importedRoot, excludedPath)));
            for (const [path, contents] of Object.entries(runtimeFiles)) {
                if (path.startsWith('wp-content/plugins/weglot/')) {
                    assert.equal(readFileSync(join(importedRoot, path), 'utf8'), contents);
                }
            }
        } finally {
            cleanupTempDir(excludedTempDir);
        }
    });

    it('fs-root file hashes match source site directory', () => {
        // The importer stores files at fs-root/<absolute-path>,
        // so the site dir content ends up at fs-root/srv/e2e-sites/<site>/
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('pull/remote-index.jsonl has entries', () => {
        const remoteIndexFile = join(pullStateDirectory(tempDir, importUrl()), 'remote-index.jsonl');
        assert.ok(existsSync(remoteIndexFile), 'Expected pull/remote-index.jsonl to exist');
        const lines = readFileSync(remoteIndexFile, 'utf-8').trim().split('\n').filter(l => l);
        assert.ok(lines.length > 0, 'Expected at least one index entry');
    });

    it('accounted for at least 3000 remote index entries', () => {
        assertRemoteIndexEntryCount(tempDir, importUrl());
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('re-running after completion refuses without --abort', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
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
        const restart = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });
        assert.equal(restart.exitCode, 0, `Expected restart exit 0, got ${restart.exitCode}\nstderr: ${restart.stderr}\nstdout: ${restart.stdout}`);

        // Remote index should still exist (restart preserves it)
        const remoteIndexFile = join(pullStateDirectory(tempDir, importUrl()), 'remote-index.jsonl');
        assert.ok(existsSync(remoteIndexFile), 'Expected remote index to be preserved after --abort');

        // Transient files should be cleaned up
        assert.ok(!existsSync(join(
            pullStateDirectory(tempDir, importUrl()),
            'remote-index.next.jsonl',
        )), 'Expected next remote index to be deleted');
        assert.ok(!existsSync(join(
            pullStateDirectory(tempDir, importUrl()),
            'fetch-list.jsonl',
        )), 'Expected fetch list to be deleted');
    });

    it('running after --abort performs a delta sync', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const stateFile = join(pullStateDirectory(tempDir, importUrl()), 'state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete');
    });
});
