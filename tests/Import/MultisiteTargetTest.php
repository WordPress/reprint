<?php

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../MySQLDumpProducer/MySQLDumpProducerTestBase.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/class-multisite-target.php';

use Reprint\Importer\MultisiteTarget;
use Reprint\Importer\Database\PdoDatabaseConnection;

/** Tests bare network domains, preserved upload paths, and explicit target access. */
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
    }

    /** Invalid destinations must fail before opening the target database. */
    public function test_invalid_target_urls_are_rejected(): void
    {
        foreach (['https://target.test/shop', 'https://target.test/?query=1', 'https://target.test/#fragment', 'ftp://target.test', 'http://127.0.0.1:9000'] as $url) {
            try {
                new MultisiteTarget(['site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_'], $url, 'chosen');
                $this->fail('Accepted invalid target URL: ' . $url);
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('--new-site-url', $error->getMessage());
            }
        }
    }

    /** Only an explicitly named, imported user may become the new network administrator. */
    public function test_an_unimported_network_administrator_is_rejected(): void
    {
        $this->pdo->exec("CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60)); INSERT INTO wp_users VALUES (1,'source-admin')");
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The requested network administrator was not imported: chosen');
        $this->target()->configure_database(new PdoDatabaseConnection($this->pdo));
    }

    /** Cleanup can run twice without renumbering the selected site or duplicating options. */
    public function test_target_configuration_is_idempotent(): void
    {
        $this->pdo->exec("CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60));
            INSERT INTO wp_users VALUES (5,'chosen');
            CREATE TABLE wp_site (id bigint PRIMARY KEY, domain varchar(200), path varchar(100));
            INSERT INTO wp_site VALUES (1,'network.test','/');
            CREATE TABLE wp_blogs (blog_id bigint PRIMARY KEY, site_id bigint, domain varchar(200), path varchar(100));
            INSERT INTO wp_blogs VALUES (7,1,'network.test','/shop/');
            CREATE TABLE wp_7_options (option_id bigint AUTO_INCREMENT PRIMARY KEY, option_name varchar(191), option_value longtext, autoload varchar(20));
            CREATE TABLE wp_sitemeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext)");
        $database = new PdoDatabaseConnection($this->pdo);
        $target = $this->target();
        $target->configure_database($database);
        $target->configure_database($database);
        $this->assertSame('localhost:9000', $this->pdo->query('SELECT domain FROM wp_blogs')->fetchColumn());
        $this->assertSame('7', (string) $this->pdo->query('SELECT blog_id FROM wp_blogs')->fetchColumn());
        $this->assertSame('wp-content/uploads/sites/7', $this->pdo->query("SELECT option_value FROM wp_7_options WHERE option_name='upload_path'")->fetchColumn());
        $this->assertSame(serialize(['chosen']), $this->pdo->query("SELECT meta_value FROM wp_sitemeta WHERE meta_key='site_admins'")->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_7_options')->fetchColumn());
        $this->assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM wp_sitemeta')->fetchColumn());
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
    public function test_wp_config_retains_base_prefix_and_selected_main_id(): void
    {
        $config = $this->target()->get_wp_config(['db'=>'clone','user'=>'local','pass'=>"a'b",'host'=>'127.0.0.1','port'=>3306]);
        $this->assertStringContainsString("\$table_prefix = 'wp_';", $config);
        $this->assertStringContainsString("define('BLOG_ID_CURRENT_SITE', 7)", $config);
        $this->assertStringContainsString("define('SUBDOMAIN_INSTALL', false)", $config);
        $this->assertStringContainsString("define('DB_NAME', 'clone')", $config);
        $this->assertStringNotContainsString('network.test', $config);
        $this->assertStringNotContainsString('SUNRISE', $config);
        $fresh = $this->target()->get_wp_config(['db'=>'clone','user'=>'local','pass'=>"a'b",'host'=>'127.0.0.1','port'=>3306]);
        $this->assertNotSame($config, $fresh, 'Each target configuration receives fresh login salts');
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
