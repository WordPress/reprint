<?php

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Database\PdoDatabaseConnection;
use Reprint\Importer\MultisiteTarget;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';

final class MultisiteSqliteTargetTest extends TestCase {
    public function test_cleanup_uses_the_existing_sqlite_connection_and_can_run_twice(): void
    {
        $pdo = new WP_PDO_MySQL_On_SQLite('mysql-on-sqlite:path=:memory:;dbname=target');
        $database = new PdoDatabaseConnection($pdo, $pdo->get_connection()->get_pdo());
        $target = new MultisiteTarget(['site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_'], 'http://target.test');
        $target->assert_empty_database($database);
        foreach ([
            'CREATE TABLE wp_users (ID bigint PRIMARY KEY, user_login varchar(60))',
            "INSERT INTO wp_users VALUES (1,'alice')",
            'CREATE TABLE wp_usermeta (umeta_id bigint AUTO_INCREMENT PRIMARY KEY, user_id bigint, meta_key varchar(255), meta_value longtext)',
            'CREATE TABLE wp_sitemeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext)',
            "INSERT INTO wp_sitemeta (site_id,meta_key,meta_value) VALUES (1,'active_sitewide_plugins','a:0:{}'),(1,'WPLANG','pl_PL')",
            'CREATE TABLE wp_7_options (option_id bigint AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20))',
            "INSERT INTO wp_7_options (option_name,option_value,autoload) VALUES ('active_plugins','a:0:{}','yes')",
        ] as $sql) {
            $pdo->exec($sql);
        }
        $database->execute("UPDATE wp_sitemeta SET meta_value=? WHERE meta_key='active_sitewide_plugins'", [serialize(['network.php' => 1])]);
        $database->execute("UPDATE wp_7_options SET option_value=? WHERE option_name='active_plugins'", [serialize(['site.php'])]);
        $target->configure_database($database, 'alice');
        $target->configure_database($database, 'alice');
        $this->assertSame('pl_PL', $database->query("SELECT option_value FROM wp_7_options WHERE option_name='WPLANG'")->fetchColumn());
        $this->assertSame(2, (int) $database->query('SELECT COUNT(*) FROM wp_usermeta')->fetchColumn());
        $this->assertSame(['network.php', 'site.php'], unserialize($database->query("SELECT option_value FROM wp_7_options WHERE option_name='active_plugins'")->fetchColumn()));
        $this->assertSame('wp-content/uploads/sites/7', $database->query("SELECT option_value FROM wp_7_options WHERE option_name='upload_path'")->fetchColumn());
        $database->execute("UPDATE wp_7_options SET option_value='' WHERE option_name='WPLANG'");
        $target->configure_database($database, 'alice');
        $this->assertSame('', $database->query("SELECT option_value FROM wp_7_options WHERE option_name='WPLANG'")->fetchColumn());
        $database->execute("DELETE FROM wp_7_options WHERE option_name='active_plugins'");
        $target->configure_database($database, 'alice');
        $this->assertSame(['network.php'], unserialize($database->query("SELECT option_value FROM wp_7_options WHERE option_name='active_plugins'")->fetchColumn()));
        $database->close();
    }

    public function test_target_lock_excludes_another_import_and_close_releases_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlite-target-');
        $first = new PdoDatabaseConnection(new PDO('sqlite:' . $path));
        $second = new PdoDatabaseConnection(new PDO('sqlite:' . $path));
        try {
            $first->lock_sqlite_database();
            try {
                $second->lock_sqlite_database();
                $this->fail('Two imports must not write the same SQLite target.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Another Reprint import', $error->getMessage());
            }
            $first->close();
            $first->close();
            $second->lock_sqlite_database();
            $second->exec('CREATE TABLE resumed (id INTEGER)');
            $this->assertTrue(file_exists($path . '.reprint-import.lock'));
        } finally {
            $first->close();
            $second->close();
            unlink($path);
            unlink($path . '.reprint-import.lock');
        }
    }

    public function test_sqlite_config_keeps_selected_table_names_without_mysql_credentials(): void
    {
        $target = new MultisiteTarget(['site_id'=>7, 'network_id'=>1, 'base_prefix'=>'wp_'], 'http://target.test');
        $config = $target->get_wp_config(['engine'=>'sqlite', 'db'=>'target', 'sqlite_path'=>'/tmp/target.sqlite']);
        $this->assertStringContainsString("\$table_prefix = 'wp_7_';", $config);
        $this->assertStringContainsString("define('CUSTOM_USER_TABLE', 'wp_users')", $config);
        $this->assertStringContainsString("define('DB_NAME', 'target')", $config);
        $this->assertStringNotContainsString('MULTISITE', $config);
    }
}
