import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { apiRequest, createMysqlConnection, getSiteDir } from '../lib/test-helpers.js';
import { ensureMultisite, runWp } from '../lib/multisite-setup.js';

const site = 'multisite-database';
const database = 'e2e_multisite_database_export';
const mode = { multisite_mode: 'one-site-network-v1', fragments_per_batch: 1, max_allowed_packet: 1048576 };

describe('Multisite database export over HTTP', () => {
    let fixture;
    let sourceCredentials;
    beforeAll(async () => {
        fixture = await ensureMultisite(site);
        // This member has no content, and WordPress created the profile row
        // before the role row. Target-side membership checks would lose it.
        runWp(getSiteDir(site), ['eval', `
            $member = get_user_by('login', 'shop-member');
            update_user_meta($member->ID, 'description', str_repeat('member profile ', 100000));
            get_password_reset_key(get_user_by('login', 'shared'));
        `]);
        sourceCredentials = JSON.parse(runWp(getSiteDir(site), ['eval', "global $wpdb; echo json_encode($wpdb->get_results('SELECT ID, user_pass, user_activation_key FROM ' . $wpdb->users . ' ORDER BY ID', ARRAY_A));"]));
        assert.ok(sourceCredentials.some(user => user.user_activation_key.length > 0));
    });
    afterAll(async () => {
        runWp(getSiteDir(site), ['user', 'meta', 'update', 'shop-member', 'description', '']);
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
            const [[profile]] = await connection.query(`SELECT m.meta_value FROM \`${database}\`.network_usermeta m
                JOIN \`${database}\`.network_users u ON u.ID=m.user_id WHERE u.user_login='shop-member' AND m.meta_key='description'`);
            const expectedProfile = 'member profile '.repeat(100000);
            assert.equal(profile.meta_value.length, expectedProfile.length, 'Keep every oversized profile byte');
            assert.equal(createHash('sha256').update(profile.meta_value).digest('hex'),
                createHash('sha256').update(expectedProfile).digest('hex'));
            assert.ok(sql.filter(chunk => chunk.body.includes('UPDATE `network_usermeta`')).length > 1,
                'The profile must travel in several UPDATE parts');
        } finally { await connection.end(); }
    });

    it('excludes shared login credentials from HTTP SQL and resumed responses', async () => {
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const first = await apiRequest(site, 'sql_chunk', mode, { url });
        const cursor = first.chunks?.find(chunk => chunk.type === 'sql' && chunk.body.includes('INSERT INTO `network_users`'))?.headers['x-cursor'];
        assert.ok(cursor, 'Resume while the shared users table is being exported');
        const resumed = await apiRequest(site, 'sql_chunk', { ...mode, cursor }, { url });
        assert.ok(resumed.chunks?.some(chunk => chunk.type === 'sql' && chunk.body.includes(Buffer.from('shop-member').toString('base64'))), 'The resumed request must read another shared user');
        for (const response of [first, resumed]) {
            assert.equal(response.status, 200, JSON.stringify(response.json));
            assert.equal(response.chunks.find(chunk => chunk.type === 'completion')?.headers['x-status'], 'complete');
            const cursors = response.chunks.filter(chunk => chunk.headers['x-cursor'])
                .map(chunk => Buffer.from(chunk.headers['x-cursor'], 'base64').toString('utf8')).join('\n');
            const received = response.raw.toString('binary') + cursors;
            for (const user of sourceCredentials) {
                for (const field of ['user_pass', 'user_activation_key']) {
                    if (user[field] === '') continue;
                    for (const value of [user[field], Buffer.from(user[field]).toString('base64'), Buffer.from(user[field]).toString('hex')]) {
                        assert.ok(!received.includes(value), `The HTTP export must not contain source ${field} for user ${user.ID}`);
                    }
                }
            }
        }
        const after = JSON.parse(runWp(getSiteDir(site), ['eval', "global $wpdb; echo json_encode($wpdb->get_results('SELECT ID, user_pass, user_activation_key FROM ' . $wpdb->users . ' ORDER BY ID', ARRAY_A));"]));
        assert.deepEqual(after, sourceCredentials, 'Export must not change source credentials');
    });

    it('rejects a cursor replayed against another site before sending SQL', async () => {
        const first = await apiRequest(site, 'sql_chunk', mode, { url: `${fixture.sites[7].url}/?reprint-api` });
        const cursor = first.chunks.find(chunk => chunk.type === 'sql')?.headers['x-cursor'];
        assert.ok(cursor, 'The selected-site response must provide a resume cursor');
        const response = await apiRequest(site, 'sql_chunk', { ...mode, cursor }, { url: `${fixture.sites[8].url}/?reprint-api` });
        assert.ok(!response.chunks?.some(chunk => chunk.type === 'sql'), 'A sibling must not return any SQL for this cursor');
        assert.ok(JSON.stringify(response.json || {}).includes('the selected multisite site changed'), JSON.stringify(response.json));
    });

    it('rejects a saved cursor when the same URL now selects a different site', async () => {
        const url = `${fixture.sites[7].url}/?reprint-api`;
        const first = await apiRequest(site, 'sql_chunk', mode, { url });
        const cursor = first.chunks.find(chunk => chunk.type === 'sql')?.headers['x-cursor'];
        assert.ok(cursor, 'The original site must provide a resume cursor');
        try {
            // A network administrator can reassign a path between requests.
            // The URL still resolves, but its blog ID is no longer the same.
            runWp(getSiteDir(site), ['eval', `
                update_blog_details(7, ['path' => '/moved-shop/']);
                update_blog_details(8, ['path' => '/shop/']);
            `]);
            const preflight = await apiRequest(site, 'preflight', mode, { url });
            assert.equal(preflight.status, 200, JSON.stringify(preflight.json));
            assert.equal(preflight.json.database.wp.multisite.selection.site_id, 8);
            const response = await apiRequest(site, 'sql_chunk', { ...mode, cursor }, { url });
            assert.ok(!response.chunks?.some(chunk => chunk.type === 'sql'), 'The reassigned URL must not return SQL for the old site');
            assert.ok(JSON.stringify(response.json || {}).includes('the selected multisite site changed'), JSON.stringify(response.json));
        } finally {
            runWp(getSiteDir(site), ['eval', `
                update_blog_details(8, ['path' => '/sibling/']);
                update_blog_details(7, ['path' => '/shop/']);
            `]);
        }
    });
});
