/**
 * Test 53: files-pull --staged-apply against a real WordPress site
 *
 * The pull downloads into a staged store under --state-dir and lands in
 * --fs-root in one rename window at the end — the same staged store and
 * apply engine push uses, driven from the pull side. The live tree never
 * holds a half-written file; a mid-transfer failure leaves it untouched.
 *
 * The deterministic-interruption, delta, preserve-local, and cross-device
 * nuances live in the PHPUnit CLI suite (StagedPullCliTest); this scenario
 * covers the real-site dimension: a full WordPress tree over nginx +
 * PHP-FPM arrives byte-identical through the staged path.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: staged pull applies in one rename window', () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-staged-pull');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function countStagedFiles() {
        const staged = join(tempDir, '.pull-staging', 'files');
        if (!existsSync(staged)) {
            return 0;
        }
        const walk = (dir) => readdirSync(dir, { withFileTypes: true }).reduce(
            (count, entry) => count + (entry.isDirectory()
                ? walk(join(dir, entry.name))
                : 1),
            0
        );
        return walk(staged);
    }

    it('pulls the full site through staging, byte-identical', () => {
        const result = runImporter(importUrl(), tempDir, 'files-pull', {
            secret: getSiteSecret(site),
            extraArgs: ['--staged-apply'],
        });

        assert.equal(result.exitCode, 0, `staged pull failed:\n${result.stderr}\n${result.stdout}`);
        assert.ok(
            result.stdout.includes('staged_apply'),
            `apply summary expected in output:\n${result.stdout.slice(-2000)}`
        );
        assertTreesMatch(getSiteDir(site), join(fsRootDir(tempDir), getSiteDir(site)));
        assert.equal(
            countStagedFiles(),
            0,
            'the apply window consumes the staged transfer'
        );
    }, 120000);
});
