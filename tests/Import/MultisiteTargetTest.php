<?php

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../MySQLDumpProducer/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/class-multisite-target.php';

use Reprint\Importer\MultisiteTarget;
use Reprint\Importer\Database\PdoDatabaseConnection;

/** Tests preserved table names, upload paths, and explicit single-site access. */
class MultisiteTargetTest extends MySQLDumpProducerTestBase
{
    /** Shared assets move; links to other source sites remain remote. */
    public function test_url_mapping_does_not_replace_the_network_origin(): void
    {
        $mapping = $this->target()->get_url_mapping();
        $rewriter = new StructuredDataUrlRewriter($mapping);
        $this->assertSame('https://network.test/sibling/post', $rewriter->rewrite('https://network.test/sibling/post'));
        $this->assertSame('http://localhost:9000/post', $rewriter->rewrite('https://network.test/shop/post'));
        $serialized = serialize(['photo' => 'https://network.test/wp-content/uploads/sites/7/photo.jpg']);
        $this->assertSame(
            serialize(['photo' => 'http://localhost:9000/wp-content/uploads/sites/7/photo.jpg']),
            $rewriter->rewrite($serialized)
        );
        $this->assertSame('https://network.test', $mapping['https://network.test']);
        $this->assertSame('http://localhost:9000', $mapping['https://network.test/shop']);
        $this->assertSame('http://localhost:9000/wp-content/uploads/sites/7', $mapping['https://network.test/wp-content/uploads/sites/7']);
        $this->assertArrayNotHasKey('https://network.test/sibling', $mapping);
    }

    /** Network-root assets and sibling media can share the same source URL base. */
    public function test_network_media_aliases_do_not_capture_sibling_media(): void
    {
        $source = [
            'site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_',
            'home_url'=>'http://127.0.0.1:8142/shop', 'site_url'=>'http://127.0.0.1:8142/shop',
            'content_url'=>'http://127.0.0.1:8142/shop/wp-content',
            'network_content_url'=>'http://127.0.0.1:8142/wp-content',
            'uploads_url'=>'http://127.0.0.1:8142/shop/wp-content/uploads/sites/7',
        ];
        $target = new MultisiteTarget($source, 'http://localhost:9000');
        $rewriter = new StructuredDataUrlRewriter($target->get_url_mapping());
        $this->assertSame('http://localhost:9000/wp-content/uploads/sites/7/photo.png', $rewriter->rewrite('http://127.0.0.1:8142/wp-content/uploads/sites/7/photo.png'));
        $this->assertSame('http://127.0.0.1:8142/wp-content/uploads/sites/8/photo.png', $rewriter->rewrite('http://127.0.0.1:8142/wp-content/uploads/sites/8/photo.png'));
        $this->assertSame('http://127.0.0.1:8142/wp-content/uploads/main.png', $rewriter->rewrite('http://127.0.0.1:8142/wp-content/uploads/main.png'));
        $markup = '<a href="http://127.0.0.1:8142/shop/post">selected</a>';
        $this->assertSame('<a href="http://localhost:9000/post">selected</a>', $rewriter->rewrite($markup, StructuredDataUrlRewriter::BLOCK_MARKUP));
        $relative = '<a href="/sibling/post">sibling</a><a href="local-page">selected</a>';
        $this->assertSame(
            '<a href="http://127.0.0.1:8142/sibling/post">sibling</a><a href="/local-page">selected</a>',
            $rewriter->rewrite($relative, StructuredDataUrlRewriter::BLOCK_MARKUP)
        );
    }

    /** Old HTTP content still moves after HTTPS is enabled, without moving sibling media. */
    public function test_selected_bases_and_upload_exclusions_cover_both_schemes(): void
    {
        foreach (['http', 'https'] as $source_scheme) {
            foreach ([1, 7] as $site_id) {
                $source_origin = $source_scheme . '://network.test';
                $site_path = $site_id === 1 ? '' : '/shop';
                $upload_path = '/wp-content/uploads' . ( $site_id === 1 ? '' : '/sites/7' );
                $sibling_paths = $site_id === 1 ? ['/shop', '/sibling'] : ['', '/sibling'];
                $source = [
                    'site_id'=>$site_id, 'network_id'=>1, 'base_prefix'=>'wp_',
                    'home_url'=>$source_origin . $site_path, 'site_url'=>$source_origin . $site_path,
                    'content_url'=>$source_origin . $site_path . '/wp-content',
                    'network_content_url'=>$source_origin . '/wp-content',
                    'uploads_url'=>$source_origin . $site_path . $upload_path,
                ];
                foreach (['http://localhost:9000', $source_origin] as $target_url) {
                    $target = new MultisiteTarget($source, $target_url);
                    $rewriter = new StructuredDataUrlRewriter($target->get_url_mapping());
                    foreach (['http', 'https'] as $scheme) {
                        $origin = $scheme . '://network.test';
                        $cases = [$origin . $site_path . '/post' => $target_url . '/post'];
                        foreach (array_unique(['', $site_path]) as $content_path) {
                            $content_url = $origin . $content_path . '/wp-content';
                            $cases[$origin . $content_path . $upload_path . '/photo.png'] = $target_url . $upload_path . '/photo.png';
                            $cases[$content_url . '/plugins/shared/style.css'] = $target_url . '/wp-content/plugins/shared/style.css';
                            $sibling_media = $content_url . '/uploads/sites/8/photo.png';
                            $cases[$sibling_media] = $sibling_media;
                            if ($site_id !== 1) {
                                $main_media = $content_url . '/uploads/main.png';
                                $cases[$main_media] = $main_media;
                            }
                        }
                        foreach ($sibling_paths as $path) {
                            $cases[$origin . $path . '/post'] = ($site_id === 1 ? $target_url : $origin) . $path . '/post';
                        }
                        foreach ($cases as $url => $expected) {
                            $this->assertSame($expected, $rewriter->rewrite($url), $url);
                            $this->assertSame('<a href="' . $expected . '">link</a>', $rewriter->rewrite(
                                '<a href="' . $url . '">link</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
                            ), $url);
                        }
                    }
                    $relative_base = $site_id !== 1 || $target_url === $source_origin ? $source_origin : '';
                    $this->assertSame('<a href="' . $relative_base . '/sibling/post">link</a>', $rewriter->rewrite(
                        '<a href="/sibling/post">link</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
                    ));
                }
            }
        }
    }

    /** Page and upload rules do not grow with the number of network sites. */
    public function test_url_rules_do_not_depend_on_the_number_of_network_sites(): void
    {
        foreach ([1, 7] as $site_id) {
            $path = $site_id === 1 ? '' : '/shop';
            $uploads = '/wp-content/uploads' . ($site_id === 1 ? '' : '/sites/7');
            $source = [
                'site_id'=>$site_id, 'network_id'=>1, 'base_prefix'=>'network_',
                'home_url'=>'https://network.test' . $path,
                'site_url'=>'https://network.test' . $path,
                'content_url'=>'https://network.test' . $path . '/wp-content',
                'network_content_url'=>'https://network.test/wp-content',
                'uploads_url'=>'https://network.test' . $path . $uploads,
            ];
            $mapping = (new MultisiteTarget($source, 'https://target.test'))->get_url_mapping();
            // Older source fields must not make the mapper allocate per-site rules.
            $source['sibling_site_ids'] = range(2, 10001);
            $source['sibling_urls'] = array_map(function ($id) { return 'https://site-' . $id . '.network.test'; }, $source['sibling_site_ids']);
            $large_network_mapping = (new MultisiteTarget($source, 'https://target.test'))->get_url_mapping();
            $this->assertCount(count($mapping), $large_network_mapping);
            $this->assertSame($mapping, $large_network_mapping);
            $this->assertLessThanOrEqual(20, count($mapping));
            $rewriter = new StructuredDataUrlRewriter($mapping);
            foreach (array_unique(['', $path]) as $content_path) {
                foreach (['http', 'https'] as $scheme) {
                    $base = $scheme . '://network.test' . $content_path . '/wp-content/uploads';
                    foreach (['/main.jpg', '/sites/7/photo.jpg', '/sites/8/photo.jpg', '/sites/70/photo.jpg', '/sites/700/photo.jpg', '/sites/7-other/photo.jpg', '/sites-backup/photo.jpg'] as $suffix) {
                        $input = $base . $suffix;
                        $moves = $site_id === 1 ? strpos($suffix, '/sites/') !== 0 : strpos($suffix, '/sites/7/') === 0;
                        $expected = $moves ? 'https://target.test/wp-content/uploads' . $suffix : $input;
                        $this->assertSame($expected, $rewriter->rewrite($input), $input);
                        $this->assertSame('<img src="' . $expected . '">', $rewriter->rewrite('<img src="' . $input . '">', StructuredDataUrlRewriter::BLOCK_MARKUP));
                        $this->assertSame(str_replace('/', '\\/', $expected), $rewriter->rewrite(str_replace('/', '\\/', $input)));
                    }
                }
            }
        }
    }

    /** A different host never needs a directory lookup or an exclusion entry. */
    public function test_domain_sites_only_rewrite_the_selected_host(): void
    {
        $rewriter = new StructuredDataUrlRewriter((new MultisiteTarget([
            'site_id'=>7, 'network_id'=>1, 'base_prefix'=>'network_',
            'home_url'=>'https://shop.network.test', 'site_url'=>'https://shop.network.test',
            'content_url'=>'https://shop.network.test/wp-content',
            'network_content_url'=>'https://network.test/wp-content',
            'uploads_url'=>'https://shop.network.test/wp-content/uploads/sites/7',
        ], 'https://target.test'))->get_url_mapping());
        foreach (['http', 'https'] as $scheme) {
            foreach (['shop.network.test', 'news.network.test', 'other.shop.network.test', 'shop.network.test.evil.test'] as $host) {
                $input = $scheme . '://' . $host . '/article';
                $expected = $host === 'shop.network.test' ? 'https://target.test/article' : $input;
                $this->assertSame($expected, $rewriter->rewrite($input));
                $this->assertSame('<a href="' . $expected . '">link</a>', $rewriter->rewrite('<a href="' . $input . '">link</a>', StructuredDataUrlRewriter::BLOCK_MARKUP));
                $this->assertSame(serialize(['link'=>$expected]), $rewriter->rewrite(serialize(['link'=>$input])));
            }
        }
    }

    /** A parsed IDN origin must not hide the selected root's replacement. */
    public function test_normalized_source_origins_do_not_exclude_the_selected_root(): void
    {
        foreach (['https://café.test', 'HTTPS://SHOP.NETWORK.TEST', 'https://shop.network.test:443'] as $home) {
            $rewriter = new StructuredDataUrlRewriter((new MultisiteTarget([
                'site_id'=>7, 'network_id'=>1, 'base_prefix'=>'network_',
                'home_url'=>$home, 'site_url'=>$home,
                'content_url'=>$home . '/wp-content',
                'network_content_url'=>'https://network.test/wp-content',
                'uploads_url'=>$home . '/wp-content/uploads/sites/7',
            ], 'https://target.test'))->get_url_mapping());
            $this->assertSame('<a href="https://target.test/post">link</a>', $rewriter->rewrite(
                '<a href="' . $home . '/post">link</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
            ));
        }
    }

    /** A URL below /shop cannot tell us whether /shop/news is a separate site. */
    public function test_overlapping_site_paths_follow_the_selected_base(): void
    {
        $rewriter = new StructuredDataUrlRewriter($this->target()->get_url_mapping());
        $this->assertSame('http://localhost:9000/news/article', $rewriter->rewrite('https://network.test/shop/news/article'));
        $this->assertSame('https://network.test/news/article', $rewriter->rewrite('https://network.test/news/article'));
        $this->assertSame('https://network.test/shopping/article', $rewriter->rewrite('https://network.test/shopping/article'));
    }

    /** A directory record protects a child site, but not a similarly named page. */
    public function test_nested_site_paths_use_a_separate_lookup_set(): void
    {
        $mapping = $this->target()->get_url_mapping();
        $rewriter = new StructuredDataUrlRewriter($mapping, [
            'https://network.test' => ['/shop/news/', '/shop/teams/local/'],
        ]);
        foreach (['http', 'https'] as $scheme) {
            foreach (['/shop/news', '/shop/news/article', '/shop/teams/local/article', '/shop/%6eews/article'] as $path) {
                $url = $scheme . '://network.test' . $path;
                $this->assertSame($url, $rewriter->rewrite($url));
                $escaped = str_replace('/', '\\/', $url);
                $this->assertSame($escaped, $rewriter->rewrite($escaped));
                $this->assertSame('prefix ' . $escaped . '\\" suffix', $rewriter->rewrite('prefix ' . $escaped . '\\" suffix'));
                $markup = '<a href="' . $url . '">child</a>';
                $this->assertSame($markup, $rewriter->rewrite($markup, StructuredDataUrlRewriter::BLOCK_MARKUP));
            }
        }
        $this->assertSame('https://network.test/shop/newsletter/article', $rewriter->rewrite('https://network.test/shop/newsletter/article'));
        $this->assertSame('<a href="http://localhost:9000/newsletter/article">selected</a>', $rewriter->rewrite(
            '<a href="https://network.test/shop/newsletter/article">selected</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
        ));
        $this->assertSame('https://network.test/shop/teams/article', $rewriter->rewrite('https://network.test/shop/teams/article'));
        $this->assertSame('<a href="https://network.test/shop/news/article">child</a>', $rewriter->rewrite(
            '<a href="news/article">child</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
        ));
        $this->assertSame('https://network.test:8443/shop/news/article', $rewriter->rewrite('https://network.test:8443/shop/news/article'));
    }

    /** Unknown text has no reliable URL boundary, so shared-host links stay remote. */
    public function test_opaque_shared_host_urls_stay_unchanged_without_path_scans(): void
    {
        $rewriter = new StructuredDataUrlRewriter([
            'https://network.test' => 'https://target.test',
            'https://cdn.test' => 'https://target-cdn.test',
        ], ['https://network.test' => ['/news/']]);
        foreach ([
            'https://network.test/a;b/../news/article',
            'https://network.test/a,b/../news/article',
            'https://network.test/a(b)/../news/article',
            'https://network.test/selected/article',
            '`https://network.test/news`',
            "prefix \xff\x00 https://network.test/news\fmore",
        ] as $input) {
            $this->assertSame($input, $rewriter->rewrite($input));
        }
        $this->assertSame('https://target-cdn.test/image.png', $rewriter->rewrite('https://cdn.test/image.png'));

        // The fallback must not copy a huge suffix to guess where a URL ends.
        $input = 'https://network.test/selected/' . str_repeat('x', 8 * 1024 * 1024);
        $mapping = new CautiousURLBaseRewriteMapping(['https://network.test' => 'https://target.test'], [
            'https://network.test' => ['/news/'],
        ]);
        $memory_before = memory_get_usage();
        $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules($input, $mapping);
        $this->assertTrue($processor->next_url());
        $this->assertFalse($processor->replace_url_base());
        $this->assertSame($input, $processor->get_updated_text());
        $this->assertLessThan(1024 * 1024, memory_get_usage() - $memory_before);
    }

    /** WordPress's normal site directory routes /news/ and /NEWS/ to the same site. */
    public function test_child_site_paths_ignore_ascii_case_without_changing_url_bytes(): void
    {
        foreach (['/shop/news/', '/shop/NeWs/'] as $stored_path) {
            $rewriter = new StructuredDataUrlRewriter($this->target()->get_url_mapping(), [
                'https://network.test' => [$stored_path],
            ]);
            foreach (['/shop/NEWS/article', '/shop/News/article', '/shop/%4eEWS/article'] as $path) {
                $url = 'https://network.test' . $path;
                $this->assertSame($url, $rewriter->rewrite($url));
                $escaped = str_replace('/', '\\/', $url);
                $this->assertSame($escaped, $rewriter->rewrite($escaped));
                $markup = '<a href="' . $url . '">child</a>';
                $this->assertSame($markup, $rewriter->rewrite($markup, StructuredDataUrlRewriter::BLOCK_MARKUP));
            }
            $this->assertSame('<a href="http://localhost:9000/NEWSLETTER/article">selected</a>', $rewriter->rewrite(
                '<a href="https://network.test/shop/NEWSLETTER/article">selected</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
            ));
        }
    }

    /** Dot segments select a site after URL parsing, while plain-text spelling stays intact. */
    public function test_structured_urls_resolve_dot_segments_and_opaque_urls_stay_remote(): void
    {
        $rewriter = new StructuredDataUrlRewriter(['https://network.test' => 'https://target.test'], [
            'https://network.test' => ['/news/'],
        ]);
        foreach (['..', '%2e%2e', '.%2E', '%2e.'] as $parent) {
            $child = 'https://network.test/about/' . $parent . '/news/article';
            $selected = 'https://network.test/news/' . $parent . '/selected';
            foreach (['/', '\\/'] as $slash) {
                $this->assertSame(str_replace('/', $slash, $child), $rewriter->rewrite(str_replace('/', $slash, $child)));
                $this->assertSame(str_replace('/', $slash, $selected),
                    $rewriter->rewrite(str_replace('/', $slash, $selected)));
            }
            $this->assertSame('<a href="https://target.test/selected">selected</a>', $rewriter->rewrite(
                '<a href="' . $selected . '">selected</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
            ));
            $this->assertSame('<a href="https://network.test/news/article">child</a>', $rewriter->rewrite(
                '<a href="' . $child . '">child</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
            ));
        }
        $rewriter = new StructuredDataUrlRewriter($this->target()->get_url_mapping(), [
            'https://network.test' => ['/shop/news/'],
        ]);
        $outside = 'https://network.test/shop/../sibling/article';
        $this->assertSame($outside, $rewriter->rewrite($outside), 'Removing /shop must not move a URL that resolves outside /shop.');
    }

    /**
     * A million paths cost one set, not a million regexes searched for each link.
     * Use a fresh process so this measures the set, not memory retained by all
     * preceding tests in the suite.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_one_million_child_paths_keep_url_matching_cheap(): void
    {
        $memory_before = memory_get_usage();
        $paths = [];
        for ($id = 0; $id < 1000000; ++$id) {
            // sprintf() can retain its larger output allocation on PHP 8.4;
            // concatenation matches the short strings fetched from MySQL/JSON.
            $paths[] = '/shop/site-' . str_pad( (string) $id, 8, '0', STR_PAD_LEFT) . '/';
        }
        $mapping = $this->target()->get_url_mapping();
        $rewriter = new StructuredDataUrlRewriter($mapping, ['https://network.test' => $paths]);
        unset($paths);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_usage() - $memory_before, 'Retain paths as hash keys, not parsed URLs or rewrite rules.');
        $this->assertLessThanOrEqual(20, count($mapping));
        $start = microtime(true);
        for ($id = 0; $id < 2000; ++$id) {
            $path = sprintf('/shop/site-%08d/article', $id * 499);
            $url = 'https://network.test' . $path;
            $this->assertSame($url, $rewriter->rewrite($url));
            $markup = '<a href="' . $path . '">child</a>';
            $this->assertSame('<a href="' . $url . '">child</a>', $rewriter->rewrite($markup, StructuredDataUrlRewriter::BLOCK_MARKUP));
            $this->assertSame('<a href="http://localhost:9000/selected-' . $id . '">selected</a>', $rewriter->rewrite(
                '<a href="https://network.test/shop/selected-' . $id . '">selected</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
            ));
        }
        $this->assertLessThan(10, microtime(true) - $start, 'Each URL must check path segments, not scan one million site paths.');
    }

    /** HTML host parsing and cautious literal matching must reach the same set. */
    public function test_child_paths_accept_normalized_hosts_and_merge_overlapping_origins(): void
    {
        foreach (['HTTPS://NETWORK.TEST:443', 'https://café.test'] as $origin) {
            $rewriter = new StructuredDataUrlRewriter([$origin => 'https://target.test'], [$origin => ['/news/']]);
            // Structured HTML uses the URL parser's normalized host spelling.
            $normalized_origin = \WordPress\DataLiberation\URL\WPURL::parse($origin)->origin;
            $this->assertSame('<a href="' . $normalized_origin . '/news/article">child</a>', $rewriter->rewrite(
                '<a href="' . $origin . '/news/article">child</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
            ));
        }
        $rewriter = new StructuredDataUrlRewriter(['https://network.test' => 'https://target.test'], [
            'https://network.test' => ['/news/'], 'http://network.test' => ['/teams/'],
        ]);
        foreach (['news', 'teams'] as $path) {
            $url = 'https://network.test/' . $path . '/article';
            $this->assertSame($url, $rewriter->rewrite($url));
        }
        foreach (['', '/', '?page=1', '/selected'] as $suffix) {
            $this->assertSame('https://network.test' . $suffix, $rewriter->rewrite('https://network.test' . $suffix));
        }
        $rewriter = new StructuredDataUrlRewriter(['https://network.test:443' => 'https://target.test'], [
            'https://network.test' => ['/news/'],
        ]);
        $this->assertSame('https://network.test:443/news', $rewriter->rewrite('https://network.test:443/news'));
    }

    /** Deep URLs must not hash prefixes longer than any stored child-site path. */
    public function test_deep_urls_stop_checking_after_the_longest_child_path(): void
    {
        $rewriter = new StructuredDataUrlRewriter($this->target()->get_url_mapping(), [
            'https://network.test' => ['/shop/news/'],
        ]);
        $suffix = '/page/' . str_repeat('segment/', 200000);
        $start = microtime(true);
        $this->assertSame('https://network.test/shop' . $suffix, $rewriter->rewrite('https://network.test/shop' . $suffix));
        $lookup = new CautiousURLBaseRewriteMapping($this->target()->get_url_mapping(), [
            'https://network.test' => ['/shop/news/'],
        ]);
        $this->assertFalse($lookup->excludes_path('network.test', '/shop' . $suffix));
        $this->assertLessThan(3, microtime(true) - $start);
    }

    /** Missing URL bases must not scan a large value again for every matched URL. */
    public function test_upload_url_search_does_not_repeat_whole_value_scans(): void
    {
        $target = new MultisiteTarget([
            'site_id'=>7, 'network_id'=>1, 'base_prefix'=>'network_',
            'home_url'=>'https://network.test/shop', 'site_url'=>'https://network.test/shop',
            'content_url'=>'https://network.test/wp-content',
            'network_content_url'=>'https://network.test/wp-content',
            'uploads_url'=>'https://network.test/wp-content/uploads/sites/7',
        ], 'https://target.test');
        $rewriter = new StructuredDataUrlRewriter($target->get_url_mapping());
        $input = str_repeat('https://network.test/wp-content/uploads/sites/7/photo.jpg ', 16000);
        $start = microtime(true);
        $output = $rewriter->rewrite($input);
        $seconds = microtime(true) - $start;
        $this->assertSame(str_replace('network.test', 'target.test', $input), $output);
        // The old search needed about eight seconds for only 8,000 URLs locally.
        $this->assertLessThan(5, $seconds, 'URL searches must move forward instead of rescanning the remaining value.');
    }

    /** Only an explicitly named, imported user may become the new site administrator. */
    public function test_an_unimported_site_administrator_is_rejected(): void
    {
        $this->pdo->exec("CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60)); INSERT INTO wp_users VALUES (1,'source-admin')");
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The requested site administrator was not imported: chosen');
        $this->target()->configure_database(new PdoDatabaseConnection($this->pdo), 'chosen');
    }

    /** Cleanup can run twice without duplicating options or changing other users. */
    public function test_target_configuration_is_idempotent(): void
    {
        $this->pdo->exec("CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60));
            INSERT INTO wp_users VALUES (5,'chosen'), (6,'member');
            CREATE TABLE wp_usermeta (umeta_id bigint AUTO_INCREMENT PRIMARY KEY, user_id bigint, meta_key varchar(255), meta_value longtext);
            CREATE TABLE wp_site (id bigint PRIMARY KEY, domain varchar(200), path varchar(100));
            INSERT INTO wp_site VALUES (1,'network.test','/');
            CREATE TABLE wp_blogs (blog_id bigint PRIMARY KEY, site_id bigint, domain varchar(200), path varchar(100));
            INSERT INTO wp_blogs VALUES (7,1,'network.test','/shop/');
            CREATE TABLE wp_7_options (option_id bigint AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20));
            CREATE TABLE wp_sitemeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext)");
        $statement = $this->pdo->prepare('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
        $statement->execute([5, 'wp_7_capabilities', serialize(['editor'=>true, 'custom_capability'=>true])]);
        $statement->execute([6, 'wp_7_capabilities', serialize(['subscriber'=>true])]);
        $statement = $this->pdo->prepare('INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, ?, ?)');
        $statement->execute(['active_sitewide_plugins', serialize(['same/shared.php'=>2, 'network/shared.php'=>1, 'reprint-server/reprint-server.php'=>3])]);
        $statement = $this->pdo->prepare('INSERT INTO wp_7_options (option_name, option_value, autoload) VALUES (?, ?, \'yes\')');
        $statement->execute(['active_plugins', serialize(['local/local.php', 'same/shared.php', 'reprint-exporter/export.php'])]);
        $database = new PdoDatabaseConnection($this->pdo);
        $target = $this->target();
        $target->configure_database($database, 'chosen');
        $first = $this->pdo->query('SELECT * FROM wp_usermeta ORDER BY umeta_id')->fetchAll();
        $target->configure_database($database, 'chosen');
        $this->assertSame($first, $this->pdo->query('SELECT * FROM wp_usermeta ORDER BY umeta_id')->fetchAll());
        $this->assertSame(serialize(['editor'=>true, 'custom_capability'=>true, 'administrator'=>true]), $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=5 AND meta_key='wp_7_capabilities'")->fetchColumn());
        $this->assertSame(serialize(['subscriber'=>true]), $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=6")->fetchColumn());
        $this->assertSame('10', $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=5 AND meta_key='wp_7_user_level'")->fetchColumn());
        $this->assertSame('wp-content/uploads/sites/7', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='upload_path'")->fetchColumn());
        $this->assertSame(serialize(['network/shared.php', 'same/shared.php', 'local/local.php']), $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='active_plugins'")->fetchColumn());
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_7_options')->fetchColumn());
        $this->pdo->exec("INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'WPLANG', 'pl_PL')");
        $target->configure_database($database, 'chosen');
        $this->assertSame('pl_PL', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='WPLANG'")->fetchColumn());
        $this->pdo->exec("UPDATE wp_7_options SET option_value='' WHERE option_name='WPLANG'");
        $target->configure_database($database, 'chosen');
        $this->assertSame('', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='WPLANG'")->fetchColumn(), 'An explicit English choice must not inherit the network language');

        // Content authors can be imported without a current membership row.
        $this->pdo->exec("INSERT INTO wp_users VALUES (7, 'former-author')");
        $author_target = new MultisiteTarget(['site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_'], 'http://localhost:9000');
        $author_target->configure_database($database, 'former-author');
        $author_target->configure_database($database, 'former-author');
        $this->assertSame(serialize(['administrator'=>true]), $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=7 AND meta_key='wp_7_capabilities'")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_usermeta WHERE user_id=7')->fetchColumn());
    }

    /** An existing database must be rejected before any source DROP TABLE executes. */
    public function test_existing_target_database_is_rejected(): void
    {
        $this->pdo->exec('CREATE TABLE existing_data (id int PRIMARY KEY)');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty target database');
        $this->target()->assert_empty_database(new PdoDatabaseConnection($this->pdo));
    }

    /** Source constants and credentials are replaced, not layered behind target overrides. */
    public function test_wp_config_adopts_the_site_prefix_and_shared_user_tables(): void
    {
        foreach ([1 => 'wp_', 7 => 'wp_7_'] as $site_id => $prefix) {
            $target = new MultisiteTarget(['site_id'=>$site_id, 'network_id'=>1, 'base_prefix'=>'wp_'], 'http://localhost:9000');
            $config = $target->get_wp_config(['db'=>'clone','user'=>'local','pass'=>"a'b",'host'=>'127.0.0.1','port'=>3306]);
            $this->assertStringContainsString("\$table_prefix = '{$prefix}';", $config);
            $this->assertStringContainsString("define('CUSTOM_USER_TABLE', 'wp_users')", $config);
            $this->assertStringContainsString("define('CUSTOM_USER_META_TABLE', 'wp_usermeta')", $config);
            foreach (['MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE', 'SITE_ID_CURRENT_SITE', 'SUNRISE'] as $constant) {
                $this->assertStringNotContainsString($constant, $config);
            }
            $this->assertStringContainsString("define('DB_NAME', 'clone')", $config);
            $fresh = $target->get_wp_config(['db'=>'clone','user'=>'local','pass'=>"a'b",'host'=>'127.0.0.1','port'=>3306]);
            $this->assertNotSame($config, $fresh, 'Each target configuration receives fresh login salts');
        }
    }

    /** Produce the selected site with an explicit imported administrator. */
    private function target(): MultisiteTarget
    {
        return new MultisiteTarget([
            'site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_',
            'home_url'=>'https://network.test/shop', 'site_url'=>'https://network.test/shop',
            'content_url'=>'https://network.test/wp-content',
            'network_content_url'=>'https://network.test/wp-content',
            'uploads_url'=>'https://network.test/wp-content/uploads/sites/7',
        ], 'http://localhost:9000');
    }
}
