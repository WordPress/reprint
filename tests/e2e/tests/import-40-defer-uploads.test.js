/**
 * Test 40: --filter=essential-files / --filter=skipped-earlier
 *
 * Tests that --filter=essential-files skips uploads during files-sync
 * and that --filter=skipped-earlier downloads them in a separate run.
 *
 * The remote site has:
 *   - Standard WordPress files (wp-admin, wp-includes, wp-content/themes, etc.)
 *   - Test data files
 *   - Explicit upload files under wp-content/uploads/2024/{01,06}/
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, readAuditLog,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { mkdirSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';

const UPLOAD_FILES = [
    'wp-content/uploads/2024/01/photo.jpg',
    'wp-content/uploads/2024/01/banner.png',
    'wp-content/uploads/2024/01/document.pdf',
    'wp-content/uploads/2024/06/summer.jpg',
];

describe('Import: --filter', () => {
    const site = 'defer-uploads';
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                // Create upload files that should be filtered out by essential-files
                const uploadsDir = join(remoteSiteDir, 'wp-content', 'uploads', '2024', '01');
                mkdirSync(uploadsDir, { recursive: true });
                writeFileSync(join(uploadsDir, 'photo.jpg'), randomBytes(4096));
                writeFileSync(join(uploadsDir, 'banner.png'), randomBytes(2048));
                writeFileSync(join(uploadsDir, 'document.pdf'), randomBytes(1024));

                const uploadsDir2 = join(remoteSiteDir, 'wp-content', 'uploads', '2024', '06');
                mkdirSync(uploadsDir2, { recursive: true });
                writeFileSync(join(uploadsDir2, 'summer.jpg'), randomBytes(3072));
            },
        });
        siteDir = getSiteDir(site);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    // ------------------------------------------------------------------
    // Test: essential-files skips uploads, skipped-earlier downloads them
    // ------------------------------------------------------------------
    describe('essential-files then skipped-earlier', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-filter-essential');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('--filter=essential-files completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--filter=essential-files'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('state shows complete with filter persisted', () => {
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.equal(state.active_resumable_command.command_name, 'files-pull');
            assert.equal(state.active_resumable_command.completion_state, 'complete');
            assert.equal(state.filter, 'essential-files');
        });

        it('upload files are NOT in the fs-root', () => {
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(!existsSync(join(importedRoot, f)),
                    `Expected upload file to NOT exist: ${f}`);
            }
        });

        it('essential files were downloaded', () => {
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            assert.ok(existsSync(join(importedRoot, 'wp-load.php')),
                'Expected wp-load.php to exist');
            assert.ok(existsSync(join(importedRoot, 'wp-config.php')),
                'Expected wp-config.php to exist');
            assert.ok(existsSync(join(importedRoot, 'test-data', 'hello.txt')),
                'Expected test-data/hello.txt to exist');
        });

        it('skipped download list remains on disk', () => {
            assert.ok(existsSync(join(tempDir, '.import-download-list-skipped.jsonl')),
                'Expected skipped download list to remain on disk');
        });

        it('audit log shows essential files complete', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('ESSENTIAL FILES COMPLETE'),
                'Expected ESSENTIAL FILES COMPLETE in audit log');
        });

        // Now download the skipped files
        it('--filter=skipped-earlier downloads the uploads', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--filter=skipped-earlier'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('upload files now exist in the fs-root', () => {
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(existsSync(join(importedRoot, f)),
                    `Expected upload file to exist after skipped-earlier: ${f}`);
            }
        });

        it('skipped download list was cleaned up', () => {
            assert.ok(!existsSync(join(tempDir, '.import-download-list-skipped.jsonl')),
                'Expected skipped download list to be cleaned up');
        });

        it('state shows complete after skipped-earlier (not left in_progress)', () => {
            // Regression: the skipped-earlier fetch must mark the sync
            // complete. If it leaves status="in_progress", the filter-change
            // guard blocks the next delta re-pull below.
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.equal(state.active_resumable_command.completion_state, 'complete',
                `Expected completion_state=complete after skipped-earlier, got ${state.active_resumable_command?.completion_state}`);
        });

        it('a subsequent essential-files delta re-pull succeeds', () => {
            // Regression: switching the filter back to essential-files after a
            // completed skipped-earlier fetch must be allowed (the guard only
            // blocks filter changes mid-sync). Previously this threw
            // "Cannot change --filter from 'skipped-earlier' to
            // 'essential-files' while a sync is in progress."
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--filter=essential-files'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0 on delta re-pull\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('all files match source', () => {
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            assertTreesMatch(siteDir, importedRoot);
        });
    });

    // ------------------------------------------------------------------
    // Test: essential-files survives resume cycles
    // ------------------------------------------------------------------
    describe('--filter=essential-files survives resume', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-filter-resume');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('completes with forced resume via --max-exec=3', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--filter=essential-files', '--max-exec=3'],
                timeout: 120000,
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('state preserves filter across resume cycles', () => {
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.equal(state.filter, 'essential-files');
            assert.equal(state.active_resumable_command.completion_state, 'complete');
        });

        it('uploads were NOT downloaded', () => {
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(!existsSync(join(importedRoot, f)),
                    `Expected upload file to NOT exist: ${f}`);
            }
        });

        it('skipped list remains on disk', () => {
            assert.ok(existsSync(join(tempDir, '.import-download-list-skipped.jsonl')),
                'Expected skipped download list to remain');
        });
    });

    // ------------------------------------------------------------------
    // Test: without --filter, everything downloads in one shot
    // ------------------------------------------------------------------
    describe('no filter downloads everything', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-filter-none');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync without --filter completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('no skipped download list was created', () => {
            assert.ok(!existsSync(join(tempDir, '.import-download-list-skipped.jsonl')),
                'Expected no skipped download list without --filter');
        });

        it('uploads were downloaded normally', () => {
            const importedRoot = join(fsRootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(existsSync(join(importedRoot, f)),
                    `Expected upload file to exist: ${f}`);
            }
        });
    });

    // ------------------------------------------------------------------
    // Test: --filter=skipped-earlier without prior essential-files errors
    // ------------------------------------------------------------------
    describe('skipped-earlier without prior essential-files errors', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-filter-skipped-no-prior');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('errors when no prior essential-files run exists', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--filter=skipped-earlier'],
                autoResume: false,
            });
            assert.equal(result.exitCode, 1,
                `Expected exit 1\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
            assert.ok(
                result.stderr.includes('skipped-earlier') || result.stderr.includes('essential-files'),
                `Expected error about missing essential-files run, got: ${result.stderr}`,
            );
        });
    });
});
