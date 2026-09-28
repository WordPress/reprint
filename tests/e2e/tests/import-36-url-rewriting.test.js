/**
 * Test 36: URL Rewriting via db-apply
 *
 * Tests the full round-trip:
 * 1. Create site with known content containing source URLs in various formats
 * 2. Run db-pull
 * 3. Run db-apply with --rewrite-url to apply SQL to target database
 * 4. Verify URLs are rewritten in all value types, including serialized PHP
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    createMysqlConnection, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { SITE_BUILDER_CASES, SITE_BUILDER_EXPECTED_FAILURES, installSiteBuilderShortcodes, createUrlRewritingData } from '../lib/url-rewriting-data.js';

describe('Import: URL Rewriting', () => {
    const site = 'url-rewriting';
    const importDb = 'e2e_url_rewriting_import_36';
    let tempDir;
    // db-apply still needs the source domain from the site registry. The test
    // cases spell out that domain so their input and expected output remain
    // readable string literals.
    const SOURCE_DOMAIN = new URL(getSiteUrl(site)).origin;
    const TARGET_DOMAIN = 'https://target.example.com';
    beforeAll(async () => {
        assert.equal(SOURCE_DOMAIN, 'http://127.0.0.1:8108');

        await ensureSite(site, {
            afterCreate: installSiteBuilderShortcodes,
            customDb: async (_dbName, conn) => {
                await createUrlRewritingData(conn);
            },
        });
        tempDir = createTempDir('e2e-url-rewriting');
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function renderPostContent(postContent) {
        const php = "require $argv[1] . '/wp-load.php'; echo do_shortcode(do_blocks(base64_decode($argv[2], true)));";
        return execFileSync(
            process.env.PHP_BINARY || 'php',
            ['-r', php, getSiteDir(site), Buffer.from(postContent).toString('base64')],
            { encoding: 'utf8' }
        ).trim();
    }

    it('db-pull completes and produces db.sql', () => {
        const result = runImporter(importUrl(), tempDir, 'db-pull', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

        const domainsFile = join(pullStateDirectory(tempDir, importUrl()), 'domains.json');
        assert.ok(!existsSync(domainsFile), 'Expected db-pull not to create pull/domains.json');
    });

    it('db-apply with URL mapping rewrites URLs in target database', async () => {
        // Create target database
        const conn = await createMysqlConnection();
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        // Run db-apply with URL mapping
        const result = runImporter(importUrl(), tempDir, 'db-apply', {
            secret: getSiteSecret(site),
            extraArgs: [
                `--target-user=e2e_admin`,
                `--target-pass=e2e_password`,
                `--target-db=${importDb}`,
                `--rewrite-url`, SOURCE_DOMAIN, TARGET_DOMAIN,
            ],
        });

        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('siteurl and home options are rewritten', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[siteurl]] = await conn.query(
            "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'"
        );
        const [[home]] = await conn.query(
            "SELECT option_value FROM wp_options WHERE option_name = 'home'"
        );
        await conn.end();

        assert.ok(siteurl, 'Expected siteurl row');
        assert.ok(home, 'Expected home row');
        assert.ok(
            siteurl.option_value.includes('target.example.com'),
            `Expected siteurl to contain target domain, got: ${siteurl.option_value}`
        );
        assert.ok(
            home.option_value.includes('target.example.com'),
            `Expected home to contain target domain, got: ${home.option_value}`
        );
        assert.ok(
            !siteurl.option_value.includes(SOURCE_DOMAIN),
            `Expected siteurl to NOT contain source domain, got: ${siteurl.option_value}`
        );
    });

    it('HTML option URLs are rewritten', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT option_value FROM wp_options WHERE option_name = 'html_option'"
        );
        await conn.end();

        assert.ok(row, 'Expected html_option row');
        assert.ok(
            row.option_value.includes('target.example.com/about'),
            `Expected rewritten href, got: ${row.option_value}`
        );
        assert.ok(
            row.option_value.includes('target.example.com/logo.png'),
            `Expected rewritten img src, got: ${row.option_value}`
        );
        assert.ok(
            !row.option_value.includes(SOURCE_DOMAIN),
            `Expected no source domain in HTML, got: ${row.option_value}`
        );
    });

    it('serialized PHP values ARE rewritten with correct s:N: lengths', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT option_value FROM wp_options WHERE option_name = 'serialized_option'"
        );
        await conn.end();

        assert.ok(row, 'Expected serialized_option row');
        const val = row.option_value;
        // Target domain should be present, source domain should be gone
        assert.ok(
            val.includes('target.example.com'),
            `Expected serialized PHP to contain target domain, got: ${val}`
        );
        assert.ok(
            !val.includes(SOURCE_DOMAIN),
            `Expected serialized PHP to NOT contain source domain, got: ${val}`
        );
        // Verify it still starts with serialized array format
        assert.ok(
            val.startsWith('a:'),
            `Expected serialized PHP format, got: ${val.substring(0, 10)}`
        );
        // Verify s:N: byte lengths are correct for the target domain URL.
        // The rewriter preserves the original URL's trailing-slash style,
        // so a bare origin like "https://target.example.com" stays without
        // a trailing slash.
        const targetLen = TARGET_DOMAIN.length;
        assert.ok(
            val.includes(`s:${targetLen}:"${TARGET_DOMAIN}"`),
            `Expected correct s:N: prefix for target URL (s:${targetLen}:), got: ${val}`
        );
    });

    it('block markup URLs are rewritten', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT post_content FROM wp_posts WHERE post_name = 'url-rewrite-test'"
        );
        await conn.end();

        assert.ok(row, 'Expected url-rewrite-test post');
        assert.ok(
            row.post_content.includes('target.example.com'),
            `Expected block markup to contain target domain, got: ${row.post_content}`
        );
        assert.ok(
            !row.post_content.includes(SOURCE_DOMAIN),
            `Expected block markup to NOT contain source domain, got: ${row.post_content}`
        );
    });

    it.each(SITE_BUILDER_CASES)('$name survives URL rewriting and still renders', async (testCase) => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            'SELECT post_content FROM wp_posts WHERE post_name = ?',
            [testCase.slug]
        );
        await conn.end();

        assert.ok(row, `Expected ${testCase.slug} post`);
        assert.equal(row.post_content, testCase.expected);
        assert.equal(renderPostContent(row.post_content), testCase.rendered);
    });

    for (const testCase of SITE_BUILDER_EXPECTED_FAILURES) {
        it.fails(`${testCase.name} should move with the site and still render`, async () => {
            const conn = await createMysqlConnection(importDb);
            const [[row]] = await conn.query(
                'SELECT post_content FROM wp_posts WHERE post_name = ?',
                [testCase.slug]
            );
            await conn.end();

            assert.ok(row, `Expected ${testCase.slug} post`);
            assert.deepEqual(
                {
                    stored: row.post_content,
                    rendered: renderPostContent(row.post_content),
                },
                {
                    stored: testCase.expected,
                    rendered: testCase.rendered,
                }
            );
        });
    }

    it('plain text URLs in post_excerpt are rewritten', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT post_excerpt FROM wp_posts WHERE post_name = 'url-rewrite-test'"
        );
        await conn.end();

        assert.ok(row, 'Expected url-rewrite-test post');
        assert.ok(
            row.post_excerpt.includes('target.example.com/blog'),
            `Expected excerpt to contain rewritten URL, got: ${row.post_excerpt}`
        );
    });

    it('plain URL meta values are rewritten', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_plain_url'"
        );
        await conn.end();

        assert.ok(row, 'Expected _plain_url meta row');
        assert.ok(
            row.meta_value.includes('target.example.com/some-page'),
            `Expected meta URL to be rewritten, got: ${row.meta_value}`
        );
    });

    it('values with no URLs are unchanged', async () => {
        const conn = await createMysqlConnection(importDb);
        const [[row]] = await conn.query(
            "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_no_urls'"
        );
        await conn.end();

        assert.ok(row, 'Expected _no_urls meta row');
        assert.equal(
            row.meta_value,
            'Just a regular string with no URLs',
            `Expected unchanged value, got: ${row.meta_value}`
        );
    });
});
