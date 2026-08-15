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
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    createMysqlConnection, pullStateDirectory,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: URL Rewriting', () => {
    const site = 'url-rewriting';
    const importDb = 'e2e_url_rewriting_import_36';
    let tempDir;
    // db-apply still needs the source domain from the site registry. The test
    // cases spell out that domain so their input and expected output remain
    // readable string literals.
    const SOURCE_DOMAIN = new URL(getSiteUrl(site)).origin;
    const TARGET_DOMAIN = 'https://target.example.com';
    // These shapes come from the WPBakery and Divi records in the site-builder
    // markup report. The URLs use this test site's origin so db-apply can map
    // them, but the surrounding shortcode grammar is kept intact. In
    // particular, artifact 575 supplies the broken-looking paragraph body,
    // artifact 562 the percent-encoded table, and artifact 804 the image card.
    // https://adamziel.github.io/llm-research/reports/site-builder-markup.html
    const SITE_BUILDER_CASES = [
        {
            name: 'nested WPBakery escaped video shortcode',
            slug: 'url-rewrite-wpbakery-video',
            input: '[vc_row][vc_column width="1/2"][vc_video link="http:\\/\\/127.0.0.1:8108\\/wp-content\\/uploads\\/video.mp4"][/vc_column][/vc_row]',
            expected: '[vc_row][vc_column width="1/2"][vc_video link="https:\\/\\/target.example.com\\/wp-content\\/uploads\\/video.mp4"][/vc_column][/vc_row]',
            rendered: '<div data-e2e-shortcode="vc_row"><div data-e2e-shortcode="vc_column"><span data-e2e-shortcode="vc_video" data-url="https://target.example.com/wp-content/uploads/video.mp4"></span></div></div>',
        },
        {
            name: 'WPBakery entity-quoted CSS shortcode attribute',
            slug: 'url-rewrite-wpbakery-css',
            input: '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(http:\\/\\/127.0.0.1:8108\\/wp-content\\/uploads\\/hero.jpg?id=8086) !important;}&#187;]',
            expected: '[vc_column width=&#187;1\\/2&#8243; css=&#187;.vc_custom{background-image:url(https:\\/\\/target.example.com\\/wp-content\\/uploads\\/hero.jpg?id=8086) !important;}&#187;]',
            rendered: '<div data-e2e-shortcode="vc_column"></div>',
        },
        {
            name: 'WPBakery shortcode nested across broken-looking paragraph markup',
            slug: 'url-rewrite-wpbakery-mixed-html',
            input: '[vc_column_text]</p>\r\n<h4><strong>Monohull</strong></h4>\r\n<p>[vc_video link="http:\\/\\/127.0.0.1:8108\\/wp-content\\/uploads\\/tour.mp4"]</p>\r\n<p>[/vc_column_text]',
            expected: '[vc_column_text]</p>\r\n<h4><strong>Monohull</strong></h4>\r\n<p>[vc_video link="https:\\/\\/target.example.com\\/wp-content\\/uploads\\/tour.mp4"]</p>\r\n<p>[/vc_column_text]',
            rendered: '<div data-e2e-shortcode="vc_column_text"></p>\r\n<h4><strong>Monohull</strong></h4>\r\n<p><span data-e2e-shortcode="vc_video" data-url="https://target.example.com/wp-content/uploads/tour.mp4"></span></p>\r\n<p></div>',
        },
        {
            name: 'Divi shortcode inside a core HTML block',
            slug: 'url-rewrite-divi-in-core-html',
            input: '<!-- wp:html --><p>[et_pb_section background_image=”http:\\/\\/127.0.0.1:8108\\/wp-content\\/uploads\\/hero.jpg”][/et_pb_section]</p><!-- /wp:html -->',
            expected: '<!-- wp:html --><p>[et_pb_section background_image=”https:\\/\\/target.example.com\\/wp-content\\/uploads\\/hero.jpg”][/et_pb_section]</p><!-- /wp:html -->',
            rendered: '<p><div data-e2e-shortcode="et_pb_section"></div></p>',
        },
        {
            name: 'Divi image card with entities and pipe-delimited state',
            slug: 'url-rewrite-divi-image-card',
            input: '[dipl_image_card title="Social Media Strategy" image="http:\\/\\/127.0.0.1:8108\\/wp-content\\/uploads\\/investment-plan.jpg" icon="&#xe0e3;||divi||400" content_bg_color_gradient_stops="#141252 0%|#303b90 100%"][/dipl_image_card]',
            expected: '[dipl_image_card title="Social Media Strategy" image="https:\\/\\/target.example.com\\/wp-content\\/uploads\\/investment-plan.jpg" icon="&#xe0e3;||divi||400" content_bg_color_gradient_stops="#141252 0%|#303b90 100%"][/dipl_image_card]',
            rendered: '<span data-e2e-shortcode="dipl_image_card" data-url="https://target.example.com/wp-content/uploads/investment-plan.jpg"></span>',
        },
        {
            name: 'shortcode stored inside block JSON attributes',
            slug: 'url-rewrite-shortcode-block-attribute',
            input: '<!-- wp:reprint/e2e-shortcode {"shortcode":"[vc_video link=\\"http:\\/\\/127.0.0.1:8108\\/video.mp4\\"]"} /-->',
            expected: '<!-- wp:reprint/e2e-shortcode {"shortcode":"[vc_video link=\\"https:\\/\\/target.example.com\\/video.mp4\\"]"} /-->',
            rendered: '<span data-e2e-shortcode="vc_video" data-url="https://target.example.com/video.mp4"></span>',
        },
        {
            name: 'SiteOrigin JSON encoded inside an HTML attribute',
            slug: 'url-rewrite-siteorigin-input-value',
            input: '[vc_column_text]<input type="hidden" value="{&quot;url&quot;:&quot;http:\\/\\/127.0.0.1:8108\\/hero.jpg&quot;}">[/vc_column_text]',
            expected: '[vc_column_text]<input type="hidden" value="{&quot;url&quot;:&quot;https:\\/\\/target.example.com\\/hero.jpg&quot;}">[/vc_column_text]',
            rendered: '<div data-e2e-shortcode="vc_column_text"><input type="hidden" value="{&quot;url&quot;:&quot;https:\\/\\/target.example.com\\/hero.jpg&quot;}"></div>',
        },
    ];

    // These are useful failures, not descriptions of current behavior. A
    // migration user would reasonably expect each URL to move, but the URL is
    // hidden behind an encoding layer the cautious processor does not decode.
    const SITE_BUILDER_EXPECTED_FAILURES = [
        {
            name: 'WPBakery table with percent-encoded HTML and URL',
            slug: 'url-rewrite-wpbakery-percent-encoded-table',
            input: '[vc_table allow_html="1"]Download,%3Ca%20href%3D%22http%3A%2F%2F127.0.0.1%3A8108%2Fwp-content%2Fuploads%2Fmanual.pdf%22%3ELink%3C%2Fa%3E[/vc_table]',
            expected: '[vc_table allow_html="1"]Download,%3Ca%20href%3D%22http%3A%2F%2Ftarget.example.com%2Fwp-content%2Fuploads%2Fmanual.pdf%22%3ELink%3C%2Fa%3E[/vc_table]',
            rendered: '<div data-e2e-shortcode="vc_table">Download,<a href="http://target.example.com/wp-content/uploads/manual.pdf">Link</a></div>',
        },
        {
            name: 'WPBakery raw HTML with a Base64-encoded link',
            slug: 'url-rewrite-wpbakery-base64-html',
            input: '[vc_raw_html]PGEgaHJlZj0iaHR0cDovLzEyNy4wLjAuMTo4MTA4L21hbnVhbC5wZGYiPk1hbnVhbDwvYT4=[/vc_raw_html]',
            expected: '[vc_raw_html]PGEgaHJlZj0iaHR0cDovL3RhcmdldC5leGFtcGxlLmNvbS9tYW51YWwucGRmIj5NYW51YWw8L2E+[/vc_raw_html]',
            rendered: '<a href="http://target.example.com/manual.pdf">Manual</a>',
        },
        {
            name: 'percent-encoded redirect URL in a shortcode attribute',
            slug: 'url-rewrite-percent-encoded-query-url',
            input: '[vc_video link="https://archive.example/watch?next=http%3A%2F%2F127.0.0.1%3A8108%2Fvideo.mp4"]',
            expected: '[vc_video link="https://archive.example/watch?next=http%3A%2F%2Ftarget.example.com%2Fvideo.mp4"]',
            rendered: `<span data-e2e-shortcode="vc_video" data-url="https://archive.example/watch?next=${encodeURIComponent('http://target.example.com/video.mp4')}"></span>`,
        },
        {
            name: 'JSON Unicode escapes for every source authority byte',
            slug: 'url-rewrite-json-unicode-escaped-authority',
            input: '[vc_video link="http:\\u002f\\u002f127\\u002e0\\u002e0\\u002e1\\u003a8108\\u002fvideo.mp4"]',
            expected: '[vc_video link="http:\\u002f\\u002ftarget\\u002eexample\\u002ecom\\u002fvideo.mp4"]',
            rendered: '<span data-e2e-shortcode="vc_video" data-url="http://target.example.com/video.mp4"></span>',
        },
    ];

    beforeAll(async () => {
        assert.equal(SOURCE_DOMAIN, 'http://127.0.0.1:8108');

        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                const muPluginDir = join(siteDir, 'wp-content', 'mu-plugins');
                mkdirSync(muPluginDir, { recursive: true });
                writeFileSync(join(muPluginDir, 'e2e-site-builder-shortcodes.php'), `<?php
function reprint_e2e_container_shortcode($attributes, $content = null, $tag = '') {
    return '<div data-e2e-shortcode="' . esc_attr($tag) . '">' . do_shortcode((string) $content) . '</div>';
}

function reprint_e2e_media_shortcode($attributes, $content = null, $tag = '') {
    $url_attribute = 'vc_video' === $tag ? 'link' : 'image';
    $url = isset($attributes[$url_attribute]) ? (string) $attributes[$url_attribute] : '';
    $json_decoded_url = json_decode('"' . str_replace('"', '\\"', $url) . '"');
    if (is_string($json_decoded_url)) {
        $url = $json_decoded_url;
    }
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<span data-e2e-shortcode="' . esc_attr($tag) . '" data-url="' . esc_url($url) . '"></span>';
}

function reprint_e2e_table_shortcode($attributes, $content = null) {
    return '<div data-e2e-shortcode="vc_table">' . rawurldecode((string) $content) . '</div>';
}

function reprint_e2e_raw_html_shortcode($attributes, $content = null) {
    return (string) base64_decode((string) $content, true);
}

foreach (['vc_row', 'vc_column', 'vc_column_text', 'et_pb_section'] as $shortcode) {
    add_shortcode($shortcode, 'reprint_e2e_container_shortcode');
}
foreach (['vc_video', 'dipl_image_card'] as $shortcode) {
    add_shortcode($shortcode, 'reprint_e2e_media_shortcode');
}
add_shortcode('vc_table', 'reprint_e2e_table_shortcode');
add_shortcode('vc_raw_html', 'reprint_e2e_raw_html_shortcode');
register_block_type('reprint/e2e-shortcode', [
    'render_callback' => static function ($attributes) {
        return do_shortcode((string) ($attributes['shortcode'] ?? ''));
    },
]);
`);
            },
            customDb: async (dbName, conn) => {
                // WordPress tables already exist from wp core install.
                // Just INSERT additional test data into existing tables.

                // HTML content with URLs (new option_name, no conflict)
                await conn.query(
                    `INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)`,
                    ['html_option', `<a href="${SOURCE_DOMAIN}/about">About</a> <img src="${SOURCE_DOMAIN}/logo.png"/>`]
                );

                // Serialized PHP with URLs (SHOULD be rewritten with updated s:N: prefixes)
                const serialized = `a:2:{s:7:"siteurl";s:${SOURCE_DOMAIN.length}:"${SOURCE_DOMAIN}";s:4:"home";s:${SOURCE_DOMAIN.length}:"${SOURCE_DOMAIN}";}`;
                await conn.query(
                    `INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)`,
                    ['serialized_option', serialized]
                );

                // Insert a post with block markup and plain text URLs
                const blockMarkup = `<!-- wp:image {"src":"${SOURCE_DOMAIN}/wp-content/uploads/photo.jpg"} --><figure><img src="${SOURCE_DOMAIN}/wp-content/uploads/photo.jpg"/></figure><!-- /wp:image -->`;
                await conn.query(
                    `INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, NOW(), NOW(), ?, 'URL Rewrite Test Post', ?, 'publish', 'open', 'open', '', 'url-rewrite-test', '', '', NOW(), NOW(), '', 0, ?, 0, 'post', '', 0)`,
                    [blockMarkup, `Visit ${SOURCE_DOMAIN}/blog for more`, `${SOURCE_DOMAIN}/?p=999`]
                );

                // Get the ID of the post we just inserted
                const [[{ id: postId }]] = await conn.query(
                    `SELECT LAST_INSERT_ID() as id`
                );

                // Plain URL in meta_value
                await conn.query(
                    `INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)`,
                    [postId, '_plain_url', `${SOURCE_DOMAIN}/some-page`]
                );

                // Value with no URLs (should be unchanged)
                await conn.query(
                    `INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)`,
                    [postId, '_no_urls', 'Just a regular string with no URLs']
                );

                for (const testCase of [...SITE_BUILDER_CASES, ...SITE_BUILDER_EXPECTED_FAILURES]) {
                    await conn.query(
                        `INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, NOW(), NOW(), ?, ?, '', 'publish', 'open', 'open', '', ?, '', '', NOW(), NOW(), '', 0, ?, 0, 'post', '', 0)`,
                        [testCase.input, testCase.name, testCase.slug, `${SOURCE_DOMAIN}/${testCase.slug}`]
                    );
                }
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
