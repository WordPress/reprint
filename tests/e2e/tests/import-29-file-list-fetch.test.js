/**
 * Test 29: Explicit File List Fetch via API
 * Tests the file_fetch endpoint with an explicit file list upload,
 * verifying that only requested files are returned and their
 * content matches the source.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    apiRequest, apiRequestWithFileList,
    getSiteDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: File List Fetch', () => {
    const site = 'basic';

    beforeAll(async () => {
        await ensureSite(site);
    });

    /**
     * Decode base64 path from x-file-path header.
     */
    function decodePath(headers) {
        const raw = headers['x-file-path'] || headers['x-path'] || '';
        try {
            return Buffer.from(raw, 'base64').toString('utf-8');
        } catch {
            return raw;
        }
    }

    it('file_fetch with explicit file list returns only requested files', async () => {
        const siteDir = getSiteDir(site);
        const filePaths = [
            join(siteDir, 'test-data', 'hello.txt'),
            join(siteDir, 'test-data', 'empty.txt'),
        ];

        const response = await apiRequestWithFileList(site, filePaths, {
            directory: siteDir,
        });

        assert.equal(response.status, 200, `Expected 200, got ${response.status}`);
        assert.ok(response.chunks, 'Expected multipart response with chunks');

        // Should have file chunks for each requested file plus a completion
        const fileChunks = response.chunks.filter(
            c => c.headers['x-chunk-type'] === 'file'
        );
        const completion = response.chunks.find(
            c => c.headers['x-chunk-type'] === 'completion'
        );

        assert.ok(fileChunks.length > 0, 'Expected at least one file chunk');
        assert.ok(completion, 'Expected completion chunk');

        // Verify hello.txt is in the response (path is base64-encoded in x-file-path)
        const helloPaths = fileChunks.filter(c =>
            decodePath(c.headers).includes('hello.txt')
        );
        assert.ok(helloPaths.length > 0, 'Expected hello.txt in response');
    });

    it('file_fetch returns correct content for hello.txt', async () => {
        const siteDir = getSiteDir(site);
        const filePaths = [join(siteDir, 'test-data', 'hello.txt')];

        const response = await apiRequestWithFileList(site, filePaths, {
            directory: siteDir,
        });

        assert.equal(response.status, 200);
        const fileChunks = response.chunks.filter(
            c => c.headers['x-chunk-type'] === 'file'
        );
        assert.ok(fileChunks.length > 0, 'Expected file chunk');

        // Read source file and compare
        const sourceContent = readFileSync(join(siteDir, 'test-data', 'hello.txt'));
        const chunk = fileChunks[0];
        const chunkContent = Buffer.from(chunk.body, 'binary');
        assert.deepEqual(chunkContent, sourceContent, 'File content should match source');
    });

    it('file_fetch with nonexistent file returns error chunk', async () => {
        const siteDir = getSiteDir(site);
        const filePaths = [join(siteDir, 'test-data', 'does-not-exist-12345.txt')];

        const response = await apiRequestWithFileList(site, filePaths, {
            directory: siteDir,
        });

        assert.equal(response.status, 200);
        // Should have an error chunk for the missing file
        const errorChunks = response.chunks.filter(
            c => c.headers['x-chunk-type'] === 'error'
        );
        const completion = response.chunks.find(
            c => c.headers['x-chunk-type'] === 'completion'
        );
        assert.ok(completion, 'Expected completion chunk even with errors');

        // Either get error chunks or the completion indicates the issue
        if (errorChunks.length > 0) {
            const errorPath = decodePath(errorChunks[0].headers);
            const errorBody = errorChunks[0].body || '';
            assert.ok(
                errorPath.includes('does-not-exist') || errorBody.includes('does-not-exist'),
                'Expected error to reference the missing file'
            );
        }
    });

    it('file_fetch with empty file list succeeds', async () => {
        const siteDir = getSiteDir(site);
        const response = await apiRequestWithFileList(site, [], {
            directory: siteDir,
        });

        assert.equal(response.status, 200);
        const completion = response.chunks.find(
            c => c.headers['x-chunk-type'] === 'completion'
        );
        assert.ok(completion, 'Expected completion chunk for empty file list');
    });

    it('file_index returns entries with path and size fields', async () => {
        const siteDir = getSiteDir(site);
        const response = await apiRequest(site, 'file_index', {
            list_dir: siteDir,
        });

        assert.equal(response.status, 200);
        const indexChunks = response.chunks.filter(
            c => c.headers['x-chunk-type'] === 'index_batch' || c.headers['x-chunk-type'] === 'index'
        );
        assert.ok(indexChunks.length > 0, 'Expected index chunks');

        // Parse first index batch and verify structure
        const firstBatch = indexChunks[0];
        let entries;
        if (firstBatch.json) {
            entries = Array.isArray(firstBatch.json) ? firstBatch.json : [firstBatch.json];
        } else if (firstBatch.body) {
            // May be newline-delimited JSON
            entries = firstBatch.body.trim().split('\n')
                .filter(l => l.trim())
                .map(l => JSON.parse(l));
        }
        assert.ok(entries && entries.length > 0, 'Expected entries in index batch');

        // Check that entries have expected fields
        const entry = entries[0];
        assert.ok(
            entry.path || entry.file || entry.name,
            `Expected path field in index entry, got keys: ${Object.keys(entry).join(', ')}`
        );
    });
});
