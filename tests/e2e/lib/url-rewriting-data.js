import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const SOURCE_DOMAIN = 'http://127.0.0.1:8108';
const encodeWPBakeryValue = (value) => Buffer.from(encodeURIComponent(value)).toString('base64');
// These shapes come from the WPBakery and Divi records in the site-builder
// markup report. The URLs use this test site's origin so db-apply can map
// them, but the surrounding shortcode grammar is kept intact. In
// particular, artifact 575 supplies the broken-looking paragraph body,
// artifact 562 the percent-encoded table, and artifact 804 the image card.
// https://adamziel.github.io/llm-research/reports/site-builder-markup.html
export const SITE_BUILDER_CASES = [
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
    {
        name: 'WPBakery table with percent-encoded HTML and URL',
        slug: 'url-rewrite-wpbakery-percent-encoded-table',
        input: '[vc_table allow_html="1"]Download,%3Ca%20href%3D%22http%3A%2F%2F127.0.0.1%3A8108%2Fwp-content%2Fuploads%2Fmanual.pdf%22%3ELink%3C%2Fa%3E[/vc_table]',
        expected: '[vc_table allow_html="1"]Download,%3Ca%20href%3D%22https%3A%2F%2Ftarget.example.com%2Fwp-content%2Fuploads%2Fmanual.pdf%22%3ELink%3C%2Fa%3E[/vc_table]',
        rendered: '<div data-e2e-shortcode="vc_table">Download,<a href="https://target.example.com/wp-content/uploads/manual.pdf">Link</a></div>',
    },
    {
        name: 'WPBakery raw HTML with a Base64-encoded link',
        slug: 'url-rewrite-wpbakery-base64-html',
        input: '[vc_raw_html]PGEgaHJlZj0iaHR0cDovLzEyNy4wLjAuMTo4MTA4L21hbnVhbC5wZGYiPk1hbnVhbDwvYT4=[/vc_raw_html]',
        expected: '[vc_raw_html]PGEgaHJlZj0iaHR0cHM6Ly90YXJnZXQuZXhhbXBsZS5jb20vbWFudWFsLnBkZiI+TWFudWFsPC9hPg==[/vc_raw_html]',
        rendered: '<a href="https://target.example.com/manual.pdf">Manual</a>',
    },
    {
        name: 'WPBakery raw JS with URL-encoded Base64 content',
        slug: 'url-rewrite-wpbakery-base64-js',
        input: `[vc_raw_js]${encodeWPBakeryValue('<script>window.reprintUrl="http://127.0.0.1:8108/app.js";</script>')}[/vc_raw_js]`,
        expected: `[vc_raw_js]${encodeWPBakeryValue('<script>window.reprintUrl="https://target.example.com/app.js";</script>')}[/vc_raw_js]`,
        rendered: '<script>window.reprintUrl="https://target.example.com/app.js";</script>',
    },
    {
        name: 'WPBakery Google Maps safe iframe attribute',
        slug: 'url-rewrite-wpbakery-safe-map',
        input: `[vc_gmaps link="#E-8_${encodeWPBakeryValue('<iframe src="http://127.0.0.1:8108/map"></iframe>')}"]`,
        expected: `[vc_gmaps link="#E-8_${encodeWPBakeryValue('<iframe src="https://target.example.com/map"></iframe>')}"]`,
        rendered: '<iframe src="https://target.example.com/map"></iframe>',
    },
    {
        name: 'WPBakery button with a pipe-delimited link attribute',
        slug: 'url-rewrite-wpbakery-button-link',
        input: '[vc_btn title="Manual" link="url:http%3A%2F%2F127.0.0.1%3A8108%2Fmanual.pdf|title:Read%20it|target:%20_blank|"]',
        expected: '[vc_btn title="Manual" link="url:https%3A%2F%2Ftarget.example.com%2Fmanual.pdf|title:Read%20it|target:%20_blank|"]',
        rendered: '<a data-e2e-shortcode="vc_btn" href="https://target.example.com/manual.pdf">Manual</a>',
    },
];

// These are useful failures, not descriptions of current behavior. A
// migration user would reasonably expect each URL to move, but the URL is
// hidden behind an encoding layer the cautious processor does not decode.
export const SITE_BUILDER_EXPECTED_FAILURES = [
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


export async function installSiteBuilderShortcodes(siteDir) {
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
        return rawurldecode((string) base64_decode((string) $content, true));
    }

    function reprint_e2e_safe_shortcode($attributes) {
        $value = isset($attributes['link']) ? (string) $attributes['link'] : '';
        if (0 !== strncmp($value, '#E-8_', 5)) {
            return '';
        }

        return rawurldecode((string) base64_decode(substr($value, 5), true));
    }

    function reprint_e2e_button_shortcode($attributes) {
        $link = isset($attributes['link']) ? (string) $attributes['link'] : '';
        $url = '';
        foreach (explode('|', $link) as $field) {
            if (0 === strncmp($field, 'url:', 4)) {
                $url = rawurldecode(substr($field, 4));
            }
        }

        return '<a data-e2e-shortcode="vc_btn" href="' . esc_url($url) . '">' . esc_html((string) ($attributes['title'] ?? '')) . '</a>';
    }

    foreach (['vc_row', 'vc_column', 'vc_column_text', 'et_pb_section'] as $shortcode) {
        add_shortcode($shortcode, 'reprint_e2e_container_shortcode');
    }
    foreach (['vc_video', 'dipl_image_card'] as $shortcode) {
        add_shortcode($shortcode, 'reprint_e2e_media_shortcode');
    }
    add_shortcode('vc_table', 'reprint_e2e_table_shortcode');
    add_shortcode('vc_raw_html', 'reprint_e2e_raw_html_shortcode');
    add_shortcode('vc_raw_js', 'reprint_e2e_raw_html_shortcode');
    add_shortcode('vc_gmaps', 'reprint_e2e_safe_shortcode');
    add_shortcode('vc_btn', 'reprint_e2e_button_shortcode');
    register_block_type('reprint/e2e-shortcode', [
        'render_callback' => static function ($attributes) {
            return do_shortcode((string) ($attributes['shortcode'] ?? ''));
        },
    ]);
    `);
}

export async function createUrlRewritingData(conn) {
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
}
