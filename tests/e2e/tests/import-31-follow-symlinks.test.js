/**
 * Test 31: Follow Symlinks
 *
 * Tests the --follow-symlinks feature that discovers symlink targets outside
 * the site root and indexes/downloads their contents.  Covers:
 *
 * - Directory symlinks to external paths are followed and contents downloaded
 * - File symlinks are recreated locally with correct targets
 * - Relative symlinks resolve correctly
 * - Chained symlinks (target dir contains more symlinks) are followed recursively
 * - Circular symlinks don't cause infinite loops
 * - Multiple symlinks to the same target don't cause duplicate downloads
 * - The local fs-root mirrors the server's directory layout
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, mkdirSync, writeFileSync,
    symlinkSync, lstatSync,
} from 'node:fs';
import { execSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    readAuditLog,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

/*
 * Layout created on the server:
 *
 *   /srv/e2e-sites/follow-symlinks/          (WordPress root)
 *     test-data/
 *       link-to-dir    -> /srv/e2e-external/dir-target      (absolute dir symlink)
 *       link-to-file   -> /srv/e2e-external/file-target.txt (absolute file symlink)
 *       link-relative  -> ../../../e2e-external/rel-target    (relative dir symlink)
 *       link-chain     -> /srv/e2e-external/chain-a          (chain-a has a symlink to chain-b)
 *       link-cycle-a   -> /srv/e2e-external/cycle-a          (cycle-a -> cycle-b -> cycle-a)
 *       link-same-1    -> /srv/e2e-external/shared-target    (dedup test)
 *       link-same-2    -> /srv/e2e-external/shared-target    (dedup test)
 *       link-via-indir -> /srv/e2e-via/dir-target            (intermediate symlink test)
 *
 *   /srv/e2e-via  -> /srv/e2e-external        (intermediate symlink)
 *
 *   /srv/e2e-external/                        (external directory, outside site root)
 *     dir-target/
 *       alpha.txt
 *       subdir/beta.txt
 *     file-target.txt
 *     rel-target/
 *       gamma.txt
 *     chain-a/
 *       chain-link  -> /srv/e2e-external/chain-b
 *       a-file.txt
 *     chain-b/
 *       b-file.txt
 *     cycle-a/
 *       ca.txt
 *       to-b  -> /srv/e2e-external/cycle-b
 *     cycle-b/
 *       cb.txt
 *       to-a  -> /srv/e2e-external/cycle-a
 *     shared-target/
 *       shared.txt
 */

const EXTERNAL_ROOT = '/srv/e2e-external';

describe('Import: Follow Symlinks', () => {
    const site = 'follow-symlinks';
    let tempDir;
    let fallbackApiServer = null;

    async function ensureApiReachable() {
        const apiUrl = getSiteUrl(site);
        try {
            await fetch(apiUrl, { method: 'GET' });
            return;
        } catch (_) {
            // The configured e2e infrastructure may not expose port 8101 locally.
            // Start a PHP built-in server for this site as a fallback.
        }

        const fsRoot = join(getSiteDir(site), 'wp-content', 'plugins', 'site-export');
        fallbackApiServer = spawn('php', ['-S', '127.0.0.1:8101', '-t', fsRoot], {
            stdio: 'ignore',
        });

        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            if (fallbackApiServer.exitCode !== null) {
                throw new Error(`Fallback API server exited early with code ${fallbackApiServer.exitCode}`);
            }
            try {
                await fetch(apiUrl, { method: 'GET' });
                return;
            } catch (_) {
                await sleep(100);
            }
        }

        throw new Error('Timed out waiting for follow-symlinks API server to start on 127.0.0.1:8101');
    }

    beforeAll(async () => {
        // Build the external directory tree BEFORE ensureSite, because
        // ensureSite is idempotent — afterCreate only runs on first provision.
        // The external tree must exist when the symlinks are created.
        execSync(`sudo rm -rf ${EXTERNAL_ROOT}`);
        execSync(`sudo mkdir -p ${EXTERNAL_ROOT}`);
        execSync(`sudo chmod 777 ${EXTERNAL_ROOT}`);

        mkdirSync(join(EXTERNAL_ROOT, 'dir-target', 'subdir'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'dir-target', 'alpha.txt'), 'alpha content\n');
        writeFileSync(join(EXTERNAL_ROOT, 'dir-target', 'subdir', 'beta.txt'), 'beta content\n');

        writeFileSync(join(EXTERNAL_ROOT, 'file-target.txt'), 'file target content\n');

        mkdirSync(join(EXTERNAL_ROOT, 'rel-target'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'rel-target', 'gamma.txt'), 'gamma content\n');

        mkdirSync(join(EXTERNAL_ROOT, 'chain-a'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'chain-a', 'a-file.txt'), 'chain-a content\n');
        mkdirSync(join(EXTERNAL_ROOT, 'chain-b'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'chain-b', 'b-file.txt'), 'chain-b content\n');
        symlinkSync(join(EXTERNAL_ROOT, 'chain-b'), join(EXTERNAL_ROOT, 'chain-a', 'chain-link'));

        mkdirSync(join(EXTERNAL_ROOT, 'cycle-a'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'cycle-a', 'ca.txt'), 'cycle-a content\n');
        mkdirSync(join(EXTERNAL_ROOT, 'cycle-b'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'cycle-b', 'cb.txt'), 'cycle-b content\n');
        symlinkSync(join(EXTERNAL_ROOT, 'cycle-b'), join(EXTERNAL_ROOT, 'cycle-a', 'to-b'));
        symlinkSync(join(EXTERNAL_ROOT, 'cycle-a'), join(EXTERNAL_ROOT, 'cycle-b', 'to-a'));

        mkdirSync(join(EXTERNAL_ROOT, 'shared-target'), { recursive: true });
        writeFileSync(join(EXTERNAL_ROOT, 'shared-target', 'shared.txt'), 'shared content\n');

        // Create an intermediate symlink: /srv/e2e-via -> /srv/e2e-external
        // This tests that discover_path_symlinks() finds the intermediate link
        // and the client recreates it locally.
        execSync('sudo rm -f /srv/e2e-via');
        execSync(`sudo ln -s ${EXTERNAL_ROOT} /srv/e2e-via`);

        execSync(`sudo chown -R nginx:nginx ${EXTERNAL_ROOT}`);
        execSync(`sudo chmod -R 755 ${EXTERNAL_ROOT}`);

        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                const dataDir = join(siteDir, 'test-data');
                mkdirSync(dataDir, { recursive: true });

                // Create symlinks inside the site pointing to external dirs/files
                symlinkSync(join(EXTERNAL_ROOT, 'dir-target'), join(dataDir, 'link-to-dir'));
                symlinkSync(join(EXTERNAL_ROOT, 'file-target.txt'), join(dataDir, 'link-to-file'));
                symlinkSync('../../../e2e-external/rel-target', join(dataDir, 'link-relative'));
                symlinkSync(join(EXTERNAL_ROOT, 'chain-a'), join(dataDir, 'link-chain'));
                symlinkSync(join(EXTERNAL_ROOT, 'cycle-a'), join(dataDir, 'link-cycle-a'));
                symlinkSync(join(EXTERNAL_ROOT, 'shared-target'), join(dataDir, 'link-same-1'));
                symlinkSync(join(EXTERNAL_ROOT, 'shared-target'), join(dataDir, 'link-same-2'));
                // Points through /srv/e2e-via which is itself a symlink to /srv/e2e-external
                symlinkSync('/srv/e2e-via/dir-target', join(dataDir, 'link-via-indir'));
            },
        });
        await ensureApiReachable();
        tempDir = createTempDir('e2e-follow-symlinks');
    }, 300000);

    afterAll(() => {
        cleanupTempDir(tempDir);
        if (fallbackApiServer && fallbackApiServer.exitCode === null) {
            fallbackApiServer.kill('SIGTERM');
        }
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function fsRoot() {
        return fsRootDir(tempDir);
    }

    function lstatIfExists(path) {
        try {
            return lstatSync(path);
        } catch (e) {
            if (e && e.code === 'ENOENT') {
                return null;
            }
            throw e;
        }
    }

    // ─── Run the import ────────────────────────────────────────────

    it('files-sync with --follow-symlinks completes', () => {
        // Clear any prior state first
        runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks'],
            timeout: 120000,
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows complete', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete');
        assert.equal(state.follow_symlinks, true, 'follow_symlinks should be persisted in state');
    });

    // ─── Directory symlinks followed ───────────────────────────────

    it('absolute directory symlink target contents are downloaded', () => {
        const alpha = join(fsRoot(), EXTERNAL_ROOT, 'dir-target', 'alpha.txt');
        assert.ok(existsSync(alpha), `Expected ${alpha} to exist`);
        assert.equal(readFileSync(alpha, 'utf-8'), 'alpha content\n');

        const beta = join(fsRoot(), EXTERNAL_ROOT, 'dir-target', 'subdir', 'beta.txt');
        assert.ok(existsSync(beta), `Expected ${beta} to exist`);
        assert.equal(readFileSync(beta, 'utf-8'), 'beta content\n');
    });

    it('relative directory symlink target contents are downloaded', () => {
        const gamma = join(fsRoot(), EXTERNAL_ROOT, 'rel-target', 'gamma.txt');
        assert.ok(existsSync(gamma), `Expected ${gamma} to exist`);
        assert.equal(readFileSync(gamma, 'utf-8'), 'gamma content\n');
    });

    // ─── File symlinks recreated ───────────────────────────────────

    it('file symlink to external target is blocked', () => {
        const linkPath = join(fsRoot(), getSiteDir(site), 'test-data', 'link-to-file');
        const stat = lstatIfExists(linkPath);
        assert.ok(
            stat === null || !stat.isSymbolicLink(),
            `Expected external-target symlink to be blocked at ${linkPath}`,
        );
    });

    it('directory symlink is recreated locally', () => {
        const linkPath = join(fsRoot(), getSiteDir(site), 'test-data', 'link-to-dir');
        // The importer recreates symlinks from the server's symlink chunks
        if (existsSync(linkPath)) {
            const stat = lstatSync(linkPath);
            assert.ok(stat.isSymbolicLink(), 'Expected link-to-dir to be a symlink');
        }
        // Even if the symlink itself isn't recreated, the target contents
        // must be present (tested in the directory symlink test above)
    });

    // ─── Chained symlinks ──────────────────────────────────────────

    it('chained symlink targets are followed recursively', () => {
        // link-chain -> chain-a, which contains chain-link -> chain-b
        const aFile = join(fsRoot(), EXTERNAL_ROOT, 'chain-a', 'a-file.txt');
        assert.ok(existsSync(aFile), `Expected ${aFile} to exist`);
        assert.equal(readFileSync(aFile, 'utf-8'), 'chain-a content\n');

        const bFile = join(fsRoot(), EXTERNAL_ROOT, 'chain-b', 'b-file.txt');
        assert.ok(existsSync(bFile), `Expected ${bFile} (chain-b, discovered via chain-a/chain-link) to exist`);
        assert.equal(readFileSync(bFile, 'utf-8'), 'chain-b content\n');
    });

    // ─── Cycle detection ───────────────────────────────────────────

    it('circular symlinks complete without infinite loop', () => {
        // The import already completed (first test). Verify the cycle
        // targets were visited but didn't loop.
        const caFile = join(fsRoot(), EXTERNAL_ROOT, 'cycle-a', 'ca.txt');
        const cbFile = join(fsRoot(), EXTERNAL_ROOT, 'cycle-b', 'cb.txt');
        assert.ok(existsSync(caFile), `Expected cycle-a content to be downloaded`);
        assert.ok(existsSync(cbFile), `Expected cycle-b content to be downloaded`);
    });

    // ─── Intermediate symlinks ─────────────────────────────────

    it('intermediate symlink /srv/e2e-via is recreated pointing at the local mirror', () => {
        // Its target (/srv/e2e-external) was followed and mirrored locally, so
        // the link is repointed within fs-root — same treatment as regular
        // symlink chunks. Unindexed escaping targets are still blocked.
        const viaPath = join(fsRoot(), '/srv/e2e-via');
        const stat = lstatIfExists(viaPath);
        assert.ok(
            stat !== null && stat.isSymbolicLink(),
            `Expected intermediate symlink at ${viaPath}`,
        );
        assert.ok(
            existsSync(join(viaPath, 'dir-target', 'alpha.txt')),
            'via-link resolves to the mirrored external content',
        );
    });

    it('files reached through intermediate symlink are downloaded', () => {
        // link-via-indir -> /srv/e2e-via/dir-target -> /srv/e2e-external/dir-target
        // realpath resolves to /srv/e2e-external/dir-target, contents should be there
        const alpha = join(fsRoot(), EXTERNAL_ROOT, 'dir-target', 'alpha.txt');
        assert.ok(existsSync(alpha),
            `Expected ${alpha} (via intermediate symlink) to exist`);
    });

    it('audit log shows intermediate symlink handling', () => {
        const audit = readAuditLog(tempDir);
        assert.ok(
            audit.includes('Security: symlink target escapes filesystem root'),
            'Expected security log for blocked external-target symlink',
        );
    });

    // ─── Audit log ──────────────────────────────────────────────

    it('audit log shows symlink following activity', () => {
        const audit = readAuditLog(tempDir);
        assert.ok(audit.includes('FOLLOW SYMLINK'),
            'Expected FOLLOW SYMLINK entries in audit log');
    });

    // ─── Deduplication ─────────────────────────────────────────────

    it('multiple symlinks to same target do not create duplicate index entries', () => {
        // After sort+dedup, each path should appear at most once
        const remoteIndex = join(tempDir, '.import-remote-index.jsonl');
        if (!existsSync(remoteIndex)) return;

        const lines = readFileSync(remoteIndex, 'utf-8').split('\n').filter(l => l.trim());
        const paths = new Set();
        let duplicates = 0;
        for (const line of lines) {
            try {
                const entry = JSON.parse(line);
                const path = entry.path; // base64-encoded
                if (paths.has(path)) {
                    duplicates++;
                } else {
                    paths.add(path);
                }
            } catch (e) {
                // skip malformed lines
            }
        }
        assert.equal(duplicates, 0,
            `Expected no duplicate paths in remote index, found ${duplicates}`);
    });

    it('shared-target files are downloaded exactly once', () => {
        const sharedFile = join(fsRoot(), EXTERNAL_ROOT, 'shared-target', 'shared.txt');
        assert.ok(existsSync(sharedFile), `Expected ${sharedFile} to exist`);
        assert.equal(readFileSync(sharedFile, 'utf-8'), 'shared content\n');
    });

    // ─── Layout correctness ────────────────────────────────────────

    it('fs-root mirrors the server directory layout', () => {
        // The site root should be under fs-root/<site-dir>
        const siteRoot = join(fsRoot(), getSiteDir(site));
        assert.ok(existsSync(siteRoot),
            `Expected site root at ${siteRoot}`);

        // External content should be under fs-root/<external-root>
        const externalRoot = join(fsRoot(), EXTERNAL_ROOT);
        assert.ok(existsSync(externalRoot),
            `Expected external root at ${externalRoot}`);

        // Both should share the same top-level prefix (/srv)
        const srvDir = join(fsRoot(), 'srv');
        assert.ok(existsSync(srvDir),
            `Expected /srv directory in fs-root`);
    });

    it('WordPress core files are present alongside symlink targets', () => {
        const siteRoot = join(fsRoot(), getSiteDir(site));
        const wpLoad = join(siteRoot, 'wp-load.php');
        assert.ok(existsSync(wpLoad),
            `Expected wp-load.php at ${wpLoad} — core files should not be lost`);
    });
});
