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
        $this->assertArrayNotHasKey('https://network.test', $mapping);
        $this->assertSame('http://localhost:9000', $mapping['https://network.test/shop']);
        $this->assertSame('http://localhost:9000/wp-content/uploads/sites/7', $mapping['https://network.test/wp-content/uploads/sites/7']);
        $this->assertSame('https://network.test/sibling', $mapping['https://network.test/sibling']);
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
            'sibling_urls'=>['http://127.0.0.1:8142', 'http://127.0.0.1:8142/sibling'],
            'sibling_site_ids'=>[1,8,9],
        ];
        $target = new MultisiteTarget($source, 'http://localhost:9000', 'chosen');
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
    public function test_selected_urls_and_sibling_exclusions_cover_both_schemes(): void
    {
        foreach (['http', 'https'] as $source_scheme) {
            foreach ([1, 7] as $site_id) {
                $source_origin = $source_scheme . '://network.test';
                $site_path = $site_id === 1 ? '' : '/shop';
                $upload_path = '/wp-content/uploads' . ( $site_id === 1 ? '' : '/sites/7' );
                $sibling_paths = $site_id === 1 ? ['/shop', '/sibling'] : ['', '/sibling'];
                $sibling_urls = [];
                foreach (['http', 'https'] as $scheme) {
                    foreach ($sibling_paths as $path) {
                        $sibling_urls[] = $scheme . '://network.test' . $path;
                    }
                }
                $source = [
                    'site_id'=>$site_id, 'network_id'=>1, 'base_prefix'=>'wp_',
                    'home_url'=>$source_origin . $site_path, 'site_url'=>$source_origin . $site_path,
                    'content_url'=>$source_origin . $site_path . '/wp-content',
                    'network_content_url'=>$source_origin . '/wp-content',
                    'uploads_url'=>$source_origin . $site_path . $upload_path,
                    'sibling_urls'=>$sibling_urls, 'sibling_site_ids'=>$site_id === 1 ? [7,8] : [1,8],
                ];
                foreach (['http://localhost:9000', $source_origin] as $target_url) {
                    $target = new MultisiteTarget($source, $target_url, 'chosen');
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
                            $cases[$origin . $path . '/post'] = $origin . $path . '/post';
                        }
                        foreach ($cases as $url => $expected) {
                            $this->assertSame($expected, $rewriter->rewrite($url), $url);
                            $this->assertSame('<a href="' . $expected . '">link</a>', $rewriter->rewrite(
                                '<a href="' . $url . '">link</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
                            ), $url);
                        }
                    }
                    $this->assertSame('<a href="' . $source_origin . '/sibling/post">link</a>', $rewriter->rewrite(
                        '<a href="/sibling/post">link</a>', StructuredDataUrlRewriter::BLOCK_MARKUP
                    ));
                }
            }
        }
    }

    /** Invalid destinations must fail before opening the target database. */
    public function test_invalid_target_urls_are_rejected(): void
    {
        foreach (['https://target.test/shop', 'https://target.test/?query=1', 'https://target.test/#fragment', 'ftp://target.test', 'http://127.0.0.1:9000', 'https://café.test', 'https://target.test.', 'https://target_test'] as $url) {
            try {
                new MultisiteTarget(['site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_'], $url, 'chosen');
                $this->fail('Accepted invalid target URL: ' . $url);
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('--new-site-url', $error->getMessage());
            }
        }
    }

    /** Only an explicitly named, imported user may become the new site administrator. */
    public function test_an_unimported_site_administrator_is_rejected(): void
    {
        $this->pdo->exec("CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60)); INSERT INTO wp_users VALUES (1,'source-admin')");
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The requested site administrator was not imported: chosen');
        $this->target()->configure_database(new PdoDatabaseConnection($this->pdo));
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
        $target->configure_database($database);
        $first = $this->pdo->query('SELECT * FROM wp_usermeta ORDER BY umeta_id')->fetchAll();
        $target->configure_database($database);
        $this->assertSame($first, $this->pdo->query('SELECT * FROM wp_usermeta ORDER BY umeta_id')->fetchAll());
        $this->assertSame(serialize(['editor'=>true, 'custom_capability'=>true, 'administrator'=>true]), $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=5 AND meta_key='wp_7_capabilities'")->fetchColumn());
        $this->assertSame(serialize(['subscriber'=>true]), $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=6")->fetchColumn());
        $this->assertSame('10', $this->pdo->query("SELECT meta_value FROM wp_usermeta WHERE user_id=5 AND meta_key='wp_7_user_level'")->fetchColumn());
        $this->assertSame('wp-content/uploads/sites/7', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='upload_path'")->fetchColumn());
        $this->assertSame(serialize(['network/shared.php', 'same/shared.php', 'local/local.php']), $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='active_plugins'")->fetchColumn());
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_7_options')->fetchColumn());
        $this->pdo->exec("INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'WPLANG', 'pl_PL')");
        $target->configure_database($database);
        $this->assertSame('pl_PL', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='WPLANG'")->fetchColumn());
        $this->pdo->exec("UPDATE wp_7_options SET option_value='' WHERE option_name='WPLANG'");
        $target->configure_database($database);
        $this->assertSame('', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='WPLANG'")->fetchColumn(), 'An explicit English choice must not inherit the network language');

        // Content authors can be imported without a current membership row.
        $this->pdo->exec("INSERT INTO wp_users VALUES (7, 'former-author')");
        $author_target = new MultisiteTarget(['site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_'], 'http://localhost:9000', 'former-author');
        $author_target->configure_database($database);
        $author_target->configure_database($database);
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
            $target = new MultisiteTarget(['site_id'=>$site_id, 'network_id'=>1, 'base_prefix'=>'wp_'], 'http://localhost:9000', 'chosen');
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
            'uploads_url'=>'https://network.test/wp-content/uploads/sites/7',
            'sibling_urls'=>['https://network.test/sibling'],
        ], 'http://localhost:9000', 'chosen');
    }
}
