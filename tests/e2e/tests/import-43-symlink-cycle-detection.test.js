/**
 * Test 43: Symlink cycle detection in the file indexer
 *
 * When --follow-symlinks is active, the server-side indexer follows
 * symlinks into directories.  If a symlink creates a cycle (pointing
 * back to an ancestor directory), the indexer must detect it and stop
 * rather than producing an infinite (or massively duplicated) index.
 *
 * This test creates two cycle patterns seen on WP.com Atomic sites:
 *
 *   1. A self-referencing symlink: siteDir/loop -> siteDir
 *      Following it once leads back to the same directory.
 *
 *   2. A symlink to a parent: siteDir/sub/parent -> siteDir
 *      Following it from inside a subdirectory leads back to the root.
 *
 * Without cycle detection the index would grow without bound (or until
 * the OS path length limit).  With it, each real directory is visited
 * at most once per branch of the traversal tree.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, writeFileSync, mkdirSync,
    symlinkSync,
} from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    countJsonlLines,
    fsRootDir, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Symlink cycle detection', () => {
    const site = 'symlink-cycle';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                // Pattern 1: self-referencing symlink (like /srv/htdocs/srv -> /srv)
                symlinkSync('.', join(siteDir, 'loop'));

                // Pattern 2: subdirectory with a symlink back to the root
                mkdirSync(join(siteDir, 'sub'), { recursive: true });
                writeFileSync(join(siteDir, 'sub', 'child.txt'), 'child file');
                symlinkSync('..', join(siteDir, 'sub', 'parent'));

                // A regular file to verify indexing still works
                writeFileSync(join(siteDir, 'marker.txt'), 'marker');
            },
        });

        tempDir = createTempDir('e2e-symlink-cycle');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('files-pull completes without hanging', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks'],
        });
        assert.equal(
            result.exitCode, 0,
            `files-pull should complete (not hang on cycle)\nstderr: ${result.stderr}`,
        );
    });

    it('index does not contain massively duplicated entries', () => {
        // A WordPress site has ~4000-6000 files.  Without cycle detection
        // the self-symlink would double that on every depth level.  With
        // cycle detection the total should stay in the normal range.
        const nextRemoteIndexFile = join(
            pullStateDirectory(tempDir, importUrl()),
            'remote-index.next.jsonl',
        );
        assert.ok(existsSync(nextRemoteIndexFile), 'Next remote index should exist');

        const count = countJsonlLines(nextRemoteIndexFile);
        // The site has ~4-6K real files.  Allow up to 2x for one level
        // of symlink following, but not 3x+ which indicates a cycle leak.
        assert.ok(
            count < 15000,
            `Expected fewer than 15000 index entries (cycle detection should ` +
            `prevent duplication), got ${count}`,
        );
    });

    it('the marker file is indexed exactly once per reachable path', () => {
        const nextRemoteIndexFile = join(
            pullStateDirectory(tempDir, importUrl()),
            'remote-index.next.jsonl',
        );
        const content = readFileSync(nextRemoteIndexFile, 'utf-8');
        const lines = content.split('\n').filter(l => l.trim());

        // Decode all paths and count how many end with /marker.txt
        let markerCount = 0;
        for (const line of lines) {
            const entry = JSON.parse(line);
            const path = Buffer.from(entry.path, 'base64').toString('utf-8');
            if (path.endsWith('/marker.txt')) {
                markerCount++;
            }
        }

        // marker.txt should appear a bounded number of times:
        // once for the real path, possibly once more through the
        // first-level symlink follow.  But NOT dozens of times from
        // recursive symlink expansion.
        assert.ok(
            markerCount <= 3,
            `marker.txt should appear at most 3 times in the index ` +
            `(real path + one symlink level), got ${markerCount}`,
        );
    });

    it('regular files are still downloaded correctly', () => {
        const importedRoot = fsRootDir(tempDir);
        const siteDir = getSiteDir(site);
        const markerPath = join(importedRoot, siteDir, 'marker.txt');
        assert.ok(
            existsSync(markerPath),
            `marker.txt should be downloaded at ${markerPath}`,
        );
        assert.equal(
            readFileSync(markerPath, 'utf-8'),
            'marker',
            'marker.txt content should match',
        );
    });
});
