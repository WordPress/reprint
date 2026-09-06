import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { apiRequest, createMysqlConnection } from '../lib/test-helpers.js';
import { ensureMultisite } from '../lib/multisite-setup.js';

const site = 'multisite-database';
const database = 'e2e_multisite_database_export';
const mode = { multisite_mode: 'one-site-network-v1', fragments_per_batch: 1 };

describe('Multisite database export over HTTP', () => {
    let fixture;
    beforeAll(async () => { fixture = await ensureMultisite(site); });
    afterAll(async () => {
        const connection = await createMysqlConnection();
        try { await connection.query(`DROP DATABASE IF EXISTS \`${database}\``); }
        finally { await connection.end(); }
    });

    it('imports only the chosen site and its shared users from the SQL response', async () => {
        const response = await apiRequest(site, 'sql_chunk', mode, { url: `${fixture.sites[7].url}/?reprint-api` });
        assert.equal(response.status, 200, JSON.stringify(response.json));
        assert.equal(response.chunks.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete');
        const sql = response.chunks.filter(chunk => ['sql', 'sql_session_setup'].includes(chunk.type));
        assert.ok(sql.length > 2, 'Exercise several SQL parts, not just a schema response');
        const connection = await createMysqlConnection();
        try {
            await connection.query(`DROP DATABASE IF EXISTS \`${database}\``);
            await connection.query(`CREATE DATABASE \`${database}\``);
            execFileSync('mysql', ['--host=127.0.0.1', '--user=e2e_admin', database], {
                env: { ...process.env, MYSQL_PWD: 'e2e_password' },
                input: Buffer.concat(sql.map(chunk => Buffer.from(chunk.body, 'binary'))),
            });
            const [tables] = await connection.query(`SHOW TABLES FROM \`${database}\``);
            assert.deepEqual(tables.map(row => Object.values(row)[0]).sort(), [
                'network_7_commentmeta', 'network_7_comments', 'network_7_links', 'network_7_options',
                'network_7_postmeta', 'network_7_posts', 'network_7_term_relationships', 'network_7_term_taxonomy',
                'network_7_termmeta', 'network_7_terms', 'network_blogmeta', 'network_blogs',
                'network_registration_log', 'network_signups', 'network_site', 'network_sitemeta',
                'network_usermeta', 'network_users',
            ].sort());
            const [posts] = await connection.query(`SELECT ID, post_content FROM \`${database}\`.network_7_posts WHERE post_type='post'`);
            assert.deepEqual(posts.map(row => [Number(row.ID), row.post_content]), [[100, 'Only site 7']]);
            const [users] = await connection.query(`SELECT user_login FROM \`${database}\`.network_users ORDER BY user_login`);
            assert.deepEqual(users.map(row => row.user_login), ['shared', 'shop-member']);
            const [blogs] = await connection.query(`SELECT blog_id FROM \`${database}\`.network_blogs`);
            assert.deepEqual(blogs.map(row => Number(row.blog_id)), [7]);
            const [metadata] = await connection.query(`SELECT meta_key, meta_value FROM \`${database}\`.network_usermeta`);
            assert.ok(metadata.some(row => row.meta_key === 'network_7_capabilities' && row.meta_value.includes('editor')));
            assert.ok(metadata.every(row => !['network_8_capabilities', 'network_capabilities', 'session_tokens'].includes(row.meta_key)));
        } finally { await connection.end(); }
    });

    it('rejects a cursor replayed against another site before sending SQL', async () => {
        const first = await apiRequest(site, 'sql_chunk', mode, { url: `${fixture.sites[7].url}/?reprint-api` });
        const cursor = first.chunks.find(chunk => chunk.type === 'sql')?.headers['x-cursor'];
        assert.ok(cursor, 'The selected-site response must provide a resume cursor');
        const response = await apiRequest(site, 'sql_chunk', { ...mode, cursor }, { url: `${fixture.sites[8].url}/?reprint-api` });
        assert.ok(!response.chunks?.some(chunk => chunk.type === 'sql'), 'A sibling must not return any SQL for this cursor');
        assert.ok(JSON.stringify(response.json || {}).includes('the selected multisite site changed'), JSON.stringify(response.json));
    });
});
