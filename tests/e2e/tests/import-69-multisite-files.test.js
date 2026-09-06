import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { apiRequest, apiRequestWithFileList, getSiteDir } from '../lib/test-helpers.js';
import { ensureMultisite } from '../lib/multisite-setup.js';

const site = 'multisite-files';
const mode = { multisite_mode: 'one-site-network-v1' };

describe('Multisite file selection over HTTP', () => {
    let fixture;
    beforeAll(async () => { fixture = await ensureMultisite(site); });

    for (const selectedId of [7, 1]) {
        it(`indexes and transfers only shared code and site ${selectedId} uploads`, async () => {
            const url = `${fixture.sites[selectedId].url}/?reprint-api`;
            const index = await apiRequest(site, 'file_index', { ...mode, list_dir: getSiteDir(site), batch_size: 50000 }, { url });
            assert.equal(index.status, 200, JSON.stringify(index.json));
            assert.equal(index.chunks.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete');
            const paths = index.chunks.filter(chunk => chunk.type === 'index_batch')
                .flatMap(chunk => chunk.json).map(entry => Buffer.from(entry.path, 'base64').toString());
            const media = fixture.sites[selectedId].media_file;
            const core = join(getSiteDir(site), 'wp-includes/version.php');
            assert.ok(paths.includes(media), 'Index must include the selected upload');
            assert.ok(paths.includes(core), 'Index must include shared WordPress code');
            for (const [id, record] of Object.entries(fixture.sites)) {
                if (Number(id) !== selectedId) assert.ok(!paths.includes(record.media_file), `Index must exclude site ${id} uploads`);
            }
            assert.ok(!paths.includes(join(getSiteDir(site), 'wp-config.php')));
            const response = await apiRequestWithFileList(site, [media, core], mode, { url });
            assert.equal(response.chunks.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete');
            const files = response.chunks.filter(chunk => chunk.type === 'file');
            assert.deepEqual(files.map(chunk => Buffer.from(chunk.headers['x-file-path'], 'base64').toString()).sort(), [media, core].sort());
            for (const chunk of files) {
                const path = Buffer.from(chunk.headers['x-file-path'], 'base64').toString();
                const bytes = Buffer.from(chunk.body, 'binary');
                assert.deepEqual(bytes, readFileSync(path));
                if (path === media) assert.equal(bytes.toString(), `Media on site ${selectedId}`);
            }
        });
    }

    it('refuses a direct fetch of sibling uploads and source credentials without sending file bytes', async () => {
        const url = `${fixture.sites[7].url}/?reprint-api`;
        for (const path of [fixture.sites[1].media_file, fixture.sites[8].media_file,
            join(getSiteDir(site), 'wp-config.php'), join(getSiteDir(site), 'wp-content/plugins/reprint-server/secret.php')]) {
            const response = await apiRequestWithFileList(site, [path], mode, { url });
            assert.ok(response.chunks?.every(chunk => chunk.type !== 'file'), 'No excluded file bytes may be sent');
            const error = response.chunks.find(chunk => chunk.type === 'error');
            assert.equal(error?.json?.message, `Path is outside the selected multisite site: ${path}`);
        }
    });
});
