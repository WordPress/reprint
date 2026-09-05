<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

use WordPress\Reprint\Server\MultisiteDatabaseSelection;

/** Exercises site selection against MySQL, including resumable oversized reads. */
class MultisiteSelectionTest extends MySQLDumpProducerTestBase
{
    /** Only selected content, related users, and permitted shared settings travel. */
    public function test_selected_site_dump_excludes_sibling_data(): void
    {
        $this->create_network();
        $sql = $this->getDumpSQL([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'batch_size' => 2,
        ]);
        $target = $this->executeDumpInNewDatabase($sql);
        $tables = $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('network_7_posts', $tables);
        $this->assertNotContains('network_posts', $tables);
        $this->assertNotContains('network_8_posts', $tables);
        $this->assertNotContains('network_shared_plugin', $tables);
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval',
            $target->query('SELECT ID FROM network_users ORDER BY ID')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['7'], array_map('strval',
            $target->query('SELECT blog_id FROM network_blogs')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['1'], array_map('strval',
            $target->query('SELECT id FROM network_site')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['active_sitewide_plugins', 'allowedthemes'], $target->query(
            'SELECT meta_key FROM network_sitemeta ORDER BY meta_key')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['first_name', 'network_7_capabilities', 'network_7_capabilities'], $target->query(
            'SELECT meta_key FROM network_usermeta ORDER BY umeta_id')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['blogname'], $target->query(
            'SELECT option_name FROM network_7_options')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(0, (int) $target->query('SELECT COUNT(*) FROM network_signups')->fetchColumn());
        $this->assertSame('selected', $target->query('SELECT meta_value FROM network_blogmeta')->fetchColumn());
        $this->assertSame('shop', $target->query('SELECT post_title FROM network_7_posts')->fetchColumn());
    }

    /** Site 1 uses the base prefix without selecting the rest of the database. */
    public function test_main_site_does_not_select_numbered_site_tables(): void
    {
        $this->create_network();
        $target = $this->executeDumpInNewDatabase($this->getDumpSQL([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 1, 1),
        ]));
        $tables = $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('network_posts', $tables);
        $this->assertNotContains('network_7_posts', $tables);
        $this->assertSame('main', $target->query('SELECT post_title FROM network_posts')->fetchColumn());
    }

    /** Every fragment boundary can resume without losing a large selected value. */
    public function test_resume_every_fragment_keeps_selection_and_oversized_values(): void
    {
        $this->create_network();
        $value = str_repeat('selected text ', 800);
        $this->pdo->prepare('UPDATE network_7_posts SET post_title = ?')->execute([$value]);
        $options = [
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
            'max_statement_size' => 2048,
            'batch_size' => 2,
        ];
        $producer = $this->createProducer($options);
        $sql = '';
        $steps = 0;
        while ($producer->next_sql_fragment()) {
            $sql .= $producer->get_sql_fragment() . "\n";
            $options['cursor'] = $producer->get_reentrancy_cursor();
            $producer = $this->createProducer($options);
            $this->assertLessThan(500, ++$steps);
        }
        $target = $this->executeDumpInNewDatabase($sql);
        $this->assertSame($value, $target->query('SELECT post_title FROM network_7_posts')->fetchColumn());
        $this->assertGreaterThan(20, $steps);
        $this->assertNotContains('network_8_posts', $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** A client cannot carry its database cursor from site 7 to site 8. */
    public function test_resume_with_a_different_site_is_rejected(): void
    {
        $this->create_network();
        $producer = $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
        ]);
        $producer->next_sql_fragment();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected multisite site');
        $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 8, 1),
            'cursor' => $producer->get_reentrancy_cursor(),
        ]);
    }

    /** A selected cursor cannot resume as an unfiltered database dump. */
    public function test_resume_without_selection_is_rejected(): void
    {
        $this->create_network();
        $producer = $this->createProducer([
            'multisite_selection' => new MultisiteDatabaseSelection('network_', 7, 1),
        ]);
        $producer->next_sql_fragment();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected multisite site');
        $this->createProducer(['cursor' => $producer->get_reentrancy_cursor()]);
    }

    /** Builds overlapping IDs, memberships, authors, and network records. */
    private function create_network(): void
    {
        foreach (['network_', 'network_7_', 'network_8_'] as $prefix) {
            $this->pdo->exec("CREATE TABLE {$prefix}posts (ID bigint PRIMARY KEY, post_author bigint, post_title longtext);
                CREATE TABLE {$prefix}comments (comment_ID bigint PRIMARY KEY, user_id bigint);
                CREATE TABLE {$prefix}links (link_id bigint PRIMARY KEY, link_owner bigint);
                CREATE TABLE {$prefix}options (option_id bigint PRIMARY KEY, option_name varchar(191), option_value longtext)");
        }
        $this->pdo->exec("CREATE TABLE network_users (ID bigint PRIMARY KEY, user_login varchar(60));
            INSERT INTO network_users VALUES (1,'member'),(2,'empty-member'),(3,'former-author'),(4,'commenter'),(5,'link-author'),(6,'sibling');
            CREATE TABLE network_usermeta (umeta_id bigint PRIMARY KEY, user_id bigint, meta_key varchar(255), meta_value longtext);
            INSERT INTO network_usermeta VALUES
                (1,1,'first_name','Shared'),(2,1,'network_7_capabilities','a:1:{s:6:\"editor\";b:1;}'),
                (3,1,'network_8_capabilities','sibling-role'),(4,2,'network_7_capabilities','member'),
                (5,6,'network_8_capabilities','sibling'),(6,6,'first_name','Private'),
                (7,1,'session_tokens','private-session'),(8,1,'_application_passwords','private-password');
            CREATE TABLE network_blogs (blog_id bigint PRIMARY KEY, site_id bigint, domain varchar(200), path varchar(100));
            INSERT INTO network_blogs VALUES (1,1,'main.test','/'),(7,1,'shop.test','/'),(8,2,'other.test','/');
            CREATE TABLE network_blogmeta (meta_id bigint PRIMARY KEY, blog_id bigint, meta_key varchar(255), meta_value longtext);
            INSERT INTO network_blogmeta VALUES (1,7,'test','selected'),(2,8,'test','sibling');
            CREATE TABLE network_site (id bigint PRIMARY KEY, domain varchar(200), path varchar(100));
            INSERT INTO network_site VALUES (1,'main.test','/'),(2,'other.test','/');
            CREATE TABLE network_sitemeta (meta_id bigint PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext);
            INSERT INTO network_sitemeta VALUES (1,1,'active_sitewide_plugins','a:0:{}'),(2,1,'allowedthemes','a:0:{}'),
                (3,2,'active_sitewide_plugins','private'),(4,1,'site_admins','private'),(5,1,'plugin_secret','private');
            CREATE TABLE network_signups (signup_id bigint PRIMARY KEY, user_login varchar(60));
            INSERT INTO network_signups VALUES (1,'pending-private');
            CREATE TABLE network_shared_plugin (id bigint PRIMARY KEY, value text);
            INSERT INTO network_shared_plugin VALUES (1,'private');
            INSERT INTO network_posts VALUES (1,6,'main');
            INSERT INTO network_7_posts VALUES (1,3,'shop');
            INSERT INTO network_8_posts VALUES (1,6,'sibling');
            INSERT INTO network_7_comments VALUES (1,4);
            INSERT INTO network_7_links VALUES (1,5);
            INSERT INTO network_7_options VALUES (1,'blogname','Shop'),(2,'reprint_server_connection_token','private'),(3,'reprint_server_push_authorized_token_fingerprint','private'),(4,'site_export_secret','private')");
    }
}
