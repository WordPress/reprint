/**
 * Test 50: Mid-file resume after a body-stream cutoff.
 *
 * Specifically guards the contract introduced by the "stream file parts
 * directly to disk" change: now that bytes hit the local file before a
 * multipart part finishes, a request cut mid-body leaves a partially-
 * written file on disk. The importer must resume that exact file —
 * not start over (truncation) and not append duplicates (overlap) —
 * and the server-side cursor must cooperate by skipping the bytes the
 * importer already has.
 *
 * Setup: a 2 MiB random binary file. With --file-chunk-max=262144, the
 * file is sliced into eight chunks. A test_hook_before_file_chunk hook
 * exits PHP on the second chunk, which forces the failure mid-file
 * (the first chunk has been written, the file is incomplete).
 *
 * The source exits before it writes the boundary which would confirm the
 * first part. The importer must leave its cursor at the preceding boundary,
 * replay the unconfirmed bytes, and replace rather than append them. After
 * removing the hook, files-sync resumes and completes. Final assertion:
 * SHA-256 of the imported file equals the source.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, statSync, writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import { createHash, randomBytes } from 'node:crypto';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    writeTestHooks, removeTestHooks,
    writeHookState, clearHookState,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Mid-file Body Resume', { timeout: 180000 }, () => {
    const site = 'file-body-resume';
    const fileRel = 'test-data/big-binary.jpg';
    const fileSize = 2 * 1024 * 1024;
    let tempDir;
    let sourceSha256;
    let sourceFilePath;

    beforeAll(async () => {
        // Pre-generate the file in Node so we know its SHA before it
        // ever lands on the test site.
        //
        // Random bytes (rather than repetitive content) so a duplicated
        // chunk during resume couldn't hide behind matching content. We
        // mix in a NUL byte every 64 bytes to force the exporter's
        // text/binary classifier onto the binary path deterministically —
        // the classifier rejects anything with NULs in the head, and
        // pure crypto-random bytes would occasionally pass UTF-8
        // validation by chance and trip the gzip path. Combined with the
        // .jpg extension (which the classifier already treats as binary
        // without sniffing), this keeps the test independent of PR 194's
        // gzip-decision logic.
        const bytes = Buffer.from(randomBytes(fileSize));
        for (let i = 0; i < bytes.length; i += 64) {
            bytes[i] = 0;
        }
        sourceSha256 = createHash('sha256').update(bytes).digest('hex');

        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                sourceFilePath = join(siteDir, fileRel);
                const dir = join(siteDir, 'test-data');
                // Use sudo via execSync — the site dir is owned by nginx
                // by the time afterCreate runs in some site-setup paths,
                // but for newly-created sites Node still has write access.
                // Falling back to writeFileSync covers the common case.
                writeFileSync(sourceFilePath, bytes);
            },
        });

        tempDir = createTempDir('e2e-mid-file-resume');
        clearHookState(site);
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        try {
            execSync(`sudo rm -f /srv/e2e-sites/.e2e-hook-fired-${site}`);
        } catch (e) { /* ignore */ }
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('first run crashes mid-file on the second body chunk', () => {
        // Hook exits when we hit a non-first chunk of the specific file
        // we care about. Two non-obvious bits:
        //
        //   1. Path filter. WordPress core ships files larger than the
        //      chunk size; without the filter the hook would crash on
        //      whichever WP file the producer happens to reach first.
        //   2. Self-disabling via a marker file. removeTestHooks() deletes
        //      the hook PHP source, but PHP-FPM workers keep the function
        //      in memory across requests — so a worker that already loaded
        //      the hook would still call it on the resume run and crash
        //      again. The marker check makes the function a no-op once
        //      it has fired.
        const marker = `${'/srv/e2e-sites'}/.e2e-hook-fired-${site}`;
        writeTestHooks(site, [
            "function test_hook_before_file_chunk($path, $offset, &$data) {",
            `    if (file_exists('${marker}')) { return; }`,
            "    if ($offset > 0 && substr($path, -strlen('big-binary.jpg')) === 'big-binary.jpg') {",
            `        @file_put_contents('${marker}', '1');`,
            "        exit(1);",
            "    }",
            "}",
        ].join('\n'));

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--file-chunk-start=262144',
                '--file-chunk-max=262144',
            ],
            autoResume: false,
        });
        assert.equal(result.exitCode, 2,
            `Expected first run to retain partial progress after the interrupted response\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);
    });

    it('partial file is on disk and smaller than source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const localPath = join(importedRoot, fileRel);
        assert.ok(existsSync(localPath),
            'Expected the partially-downloaded file to exist on disk; the streaming change should have flushed the first chunk before the crash');
        const partialSize = statSync(localPath).size;
        assert.ok(partialSize > 0 && partialSize < fileSize,
            `Expected a partial file (0 < size < ${fileSize}), got ${partialSize}`);
    });

    it('state does not checkpoint the unconfirmed file part', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const localPath = join(importedRoot, fileRel);
        const serializedLocalPath = `base64:${Buffer.from(localPath).toString('base64')}`;
        const stateFile = join(tempDir, '.reprint/pull/state.json');
        assert.ok(existsSync(stateFile), 'Expected import state file to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.notEqual(
            state.current_file,
            serializedLocalPath,
            'Expected the unconfirmed file part to remain outside the checkpoint',
        );
    });

    it('resume completes after removing the hook', () => {
        removeTestHooks(site);

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--file-chunk-start=262144',
                '--file-chunk-max=262144',
            ],
        });
        assert.equal(result.exitCode, 0,
            `Expected resume to complete\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);

        const stateFile = join(tempDir, '.reprint/pull/state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.active_resumable_command.completion_state, 'complete', `Expected status=complete after resume, got ${state.active_resumable_command.completion_state}`);
    });

    it('resumed file matches source byte-for-byte (no gap, no duplication)', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const localPath = join(importedRoot, fileRel);
        const localSize = statSync(localPath).size;
        // Size mismatch is the smoking gun for either a gap (size < fileSize)
        // or duplicated bytes from the first pass (size > fileSize). We
        // assert size first so the failure message is clearer than a hash
        // diff would be.
        assert.equal(localSize, fileSize,
            `Resumed file size mismatch — gap or duplication. Expected ${fileSize}, got ${localSize}`);

        const localSha = createHash('sha256').update(readFileSync(localPath)).digest('hex');
        assert.equal(localSha, sourceSha256,
            'Resumed file content must hash identically to the source — any difference means the resume produced wrong bytes');
    });
});
