/**
 * Test 26: Completion Chunk Headers via API
 * Verifies that each endpoint returns correct completion chunk headers
 * with status, timing, memory, and endpoint-specific metadata.
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    apiRequest,
    getSiteDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Completion Headers', () => {
    const site = 'basic';

    beforeAll(async () => {
        await ensureSite(site);
    });

    /**
     * Helper: find completion chunk in response.
     */
    function findCompletion(response) {
        assert.ok(response.chunks, 'Expected multipart response with chunks');
        const completion = response.chunks.find(
            c => c.headers['x-chunk-type'] === 'completion'
        );
        assert.ok(completion, 'Expected completion chunk');
        return completion;
    }

    describe('sql_chunk endpoint', () => {
        let completion;

        beforeAll(async () => {
            const response = await apiRequest(site, 'sql_chunk', {
                directory: getSiteDir(site),
            });
            assert.equal(response.status, 200);
            completion = findCompletion(response);
        });

        it('has X-Status header', () => {
            assert.ok(
                completion.headers['x-status'] === 'complete' || completion.headers['x-status'] === 'partial',
                `Expected complete or partial, got ${completion.headers['x-status']}`
            );
        });

        it('has X-Batches-Processed header', () => {
            const val = completion.headers['x-batches-processed'];
            assert.ok(val !== undefined, 'Expected X-Batches-Processed header');
            assert.ok(parseInt(val) >= 0, 'Expected non-negative batches');
        });

        it('has X-SQL-Bytes header', () => {
            const val = completion.headers['x-sql-bytes'];
            assert.ok(val !== undefined, 'Expected X-SQL-Bytes header');
            assert.ok(parseInt(val) >= 0, 'Expected non-negative bytes');
        });

        it('has X-Memory-Used header', () => {
            const val = completion.headers['x-memory-used'];
            assert.ok(val !== undefined, 'Expected X-Memory-Used header');
            assert.ok(parseInt(val) > 0, 'Expected positive memory usage');
        });

        it('has X-Time-Elapsed header', () => {
            const val = completion.headers['x-time-elapsed'];
            assert.ok(val !== undefined, 'Expected X-Time-Elapsed header');
            assert.ok(parseFloat(val) >= 0, 'Expected non-negative time');
        });
    });

    describe('file_index endpoint', () => {
        let completion;

        beforeAll(async () => {
            const response = await apiRequest(site, 'file_index', {
                list_dir: getSiteDir(site),
            });
            assert.equal(response.status, 200);
            completion = findCompletion(response);
        });

        it('has X-Status header', () => {
            assert.ok(
                completion.headers['x-status'] === 'complete' || completion.headers['x-status'] === 'partial',
                `Expected complete or partial, got ${completion.headers['x-status']}`
            );
        });

        it('has X-Total-Entries header', () => {
            const val = completion.headers['x-total-entries'];
            assert.ok(val !== undefined, 'Expected X-Total-Entries header');
            assert.ok(parseInt(val) > 0, 'Expected positive total entries');
        });

        it('has X-Batches-Emitted header', () => {
            const val = completion.headers['x-batches-emitted'];
            assert.ok(val !== undefined, 'Expected X-Batches-Emitted header');
            assert.ok(parseInt(val) >= 0, 'Expected non-negative batches');
        });

        it('has X-Memory-Used and X-Time-Elapsed', () => {
            assert.ok(completion.headers['x-memory-used'], 'Expected X-Memory-Used');
            assert.ok(completion.headers['x-time-elapsed'], 'Expected X-Time-Elapsed');
        });

        it('has index chunks before completion', async () => {
            const response = await apiRequest(site, 'file_index', {
                list_dir: getSiteDir(site),
            });
            const indexChunks = response.chunks.filter(
                c => c.headers['x-chunk-type'] === 'index_batch' || c.headers['x-chunk-type'] === 'index'
            );
            assert.ok(indexChunks.length > 0, 'Expected at least one index chunk');
            // Each index chunk should have JSON body with file entries
            const first = indexChunks[0];
            assert.ok(first.json || first.body, 'Expected index chunk to have content');
        });
    });

    describe('db_index endpoint', () => {
        let completion;

        beforeAll(async () => {
            const response = await apiRequest(site, 'db_index', {
                directory: getSiteDir(site),
            });
            assert.equal(response.status, 200);
            completion = findCompletion(response);
        });

        it('has X-Status header', () => {
            assert.equal(completion.headers['x-status'], 'complete');
        });

        it('has X-Tables-Processed header', () => {
            const val = completion.headers['x-tables-processed'];
            assert.ok(val !== undefined, 'Expected X-Tables-Processed header');
            assert.ok(parseInt(val) > 0, 'Expected at least one table');
        });

        it('has X-Rows-Estimated header', () => {
            const val = completion.headers['x-rows-estimated'];
            assert.ok(val !== undefined, 'Expected X-Rows-Estimated header');
            assert.ok(parseInt(val) >= 0, 'Expected non-negative row estimate');
        });
    });
});
