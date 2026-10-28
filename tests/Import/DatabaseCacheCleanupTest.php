<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class DatabaseCacheCleanupTest extends TestCase {

    private string $temp_dir;
    private ?PDO $mysql_root = null;
    private ?string $mysql_database_name = null;

    protected function setUp(): void
    {
        $this->temp_dir = sys_get_temp_dir() . '/reprint-theme-roots-' . uniqid();
        mkdir($this->temp_dir . '/files', 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->mysql_root !== null && $this->mysql_database_name !== null) {
            $this->mysql_root->exec('DROP DATABASE `' . $this->mysql_database_name . '`');
        }
        $this->mysql_root = null;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temp_dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temp_dir);
    }

    public static function cleanupProvider(): array
    {
        return [
            'MySQL' => ['mysql', 'wp_', null],
            'SQLite' => ['sqlite', 'wp_', null],
            'MySQL stops before cleanup' => ['mysql', 'custom_', 'before'],
            'SQLite stops before cleanup' => ['sqlite', 'custom_', 'before'],
            'MySQL stops after cleanup' => ['mysql', 'custom_', 'after'],
            'SQLite stops after cleanup' => ['sqlite', 'custom_', 'after'],
        ];
    }

    /**
     * @dataProvider cleanupProvider
     */
    public function testClearsImportedCachesWithoutChangingSiteSettings(
        string $engine,
        string $prefix,
        ?string $interrupt_at
    ): void {
        $options = $this->target_options($engine);
        $client = $this->new_client($prefix);
        $this->write_dump($prefix);
        if ($interrupt_at !== null) {
            $client->interrupt_at = $interrupt_at;
            try {
                $client->run($options);
                $this->fail('The first process must stop at the cleanup boundary.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Stop at the database cleanup boundary.', $error->getMessage());
            }
            $state = json_decode(file_get_contents($client->pull_state_directory . '/state.json'), true);
            $this->assertSame('database-cleanup', $state['active_resumable_command']['current_stage']);
            $database = $this->open_database($options);
            if ($interrupt_at === 'after') {
                $this->assert_site_options($database, $prefix);
            } else {
                $this->assertSame('/srv/shared-themes', $database->query(
                    "SELECT option_value FROM `{$prefix}options` WHERE option_name = 'stylesheet_root'"
                )->fetchColumn());
            }
            $database = null;
            // A new client reads the durable cleanup stage, not the first
            // process's in-memory completion status.
            $client = new \ImportClient(
                'https://source.example/?reprint-api',
                $this->temp_dir,
                $this->temp_dir . '/files'
            );
        }

        $client->run($options);

        $database = $this->open_database($options);
        $this->assert_site_options($database, $prefix);
        $this->assertSame(1, (int) $database->query('SELECT COUNT(*) FROM cleanup_probe')->fetchColumn());
        $this->assertSame(
            '/srv/another-site/themes',
            $database->query("SELECT option_value FROM other_options WHERE option_name = 'stylesheet_root'")->fetchColumn()
        );
        $state = json_decode(file_get_contents($client->pull_state_directory . '/state.json'), true);
        $this->assertSame('complete', $state['active_resumable_command']['completion_state']);
    }

    public static function targetProvider(): array
    {
        return ['MySQL' => ['mysql'], 'SQLite' => ['sqlite']];
    }

    /**
     * @dataProvider targetProvider
     */
    public function testCompletesWhenTheOptionsTableWasNotImported(string $engine): void
    {
        $options = $this->target_options($engine);
        $client = $this->new_client('wp_');
        $this->write_dump(null);

        $client->run($options);

        $this->assertSame('complete', $client->get_state()->active_resumable_command->completion_state);
    }

    /**
     * @dataProvider targetProvider
     */
    public function testDoesNotGuessTheWordPressPrefix(string $engine): void
    {
        $options = $this->target_options($engine);
        $client = $this->new_client(null);
        $this->write_dump('wp_');

        $client->run($options);

        $database = $this->open_database($options);
        $this->assertSame('/srv/shared-themes', $database->query(
            "SELECT option_value FROM wp_options WHERE option_name = 'stylesheet_root'"
        )->fetchColumn());
    }

    private function new_client(?string $prefix): \ImportClient
    {
        $client = new class(
            'https://source.example/?reprint-api',
            $this->temp_dir,
            $this->temp_dir . '/files'
        ) extends \ImportClient {
            public ?string $interrupt_at = null;

            public function save_state(): void
            {
                $state = $this->get_state()->active_resumable_command;
                if ($this->interrupt_at === 'after' && $state->completion_state === 'complete') {
                    throw new \RuntimeException('Stop at the database cleanup boundary.');
                }
                parent::save_state();
                if ($this->interrupt_at === 'before' && $state->current_stage === 'database-cleanup') {
                    throw new \RuntimeException('Stop at the database cleanup boundary.');
                }
            }
        };
        \write_current_pull_state($client, [
            'preflight' => ['data' => ['database' => ['wp' => ['table_prefix' => $prefix]]]],
        ]);
        return $client;
    }

    /**
     * @return array { Command options for a fresh target database.
     *     @type string $command            The db-apply command.
     *     @type string $target_engine      mysql or sqlite.
     *     @type string $progress           JSONL progress mode.
     *     @type string $target_db          Database name.
     *     @type string $target_sqlite_path Database file, for SQLite only.
     *     @type string $target_host        Server address, for MySQL only.
     *     @type int    $target_port        Server port, for MySQL only.
     *     @type string $target_user        Login, for MySQL only.
     *     @type string $target_pass        Password, for MySQL only.
     * }
     */
    private function target_options(string $engine): array
    {
        $options = ['command' => 'db-apply', 'target_engine' => $engine, 'progress' => 'jsonl'];
        if ($engine === 'sqlite') {
            return $options + [
                'target_db' => 'wordpress',
                'target_sqlite_path' => $this->temp_dir . '/wordpress.sqlite',
            ];
        }
        $options += [
            'target_host' => getenv('DB_HOST') ?: '127.0.0.1',
            'target_port' => (int) ( getenv('DB_PORT') ?: 3306 ),
            'target_user' => getenv('DB_USER') ?: 'root',
            'target_pass' => getenv('DB_PASS') ?: '',
        ];
        try {
            $this->mysql_root = $this->open_database($options);
        } catch (\PDOException $error) {
            $this->markTestSkipped('MySQL not reachable: ' . $error->getMessage());
        }
        $this->mysql_database_name = 'test_theme_roots_' . bin2hex(random_bytes(4));
        $this->mysql_root->exec('CREATE DATABASE `' . $this->mysql_database_name . '`');
        return $options + ['target_db' => $this->mysql_database_name];
    }

    /**
     * @param array $options { Target database connection options.
     *     @type string $target_engine      mysql or sqlite.
     *     @type string $target_sqlite_path Database file, for SQLite only.
     *     @type string $target_host        Server address, for MySQL only.
     *     @type int    $target_port        Server port, for MySQL only.
     *     @type string $target_user        Login, for MySQL only.
     *     @type string $target_pass        Password, for MySQL only.
     *     @type string $target_db          Database name, absent for the initial MySQL connection.
     * }
     */
    private function open_database(array $options): PDO
    {
        if ($options['target_engine'] === 'sqlite') {
            return new PDO('sqlite:' . $options['target_sqlite_path']);
        }
        $dsn = 'mysql:host=' . $options['target_host'] . ';port=' . $options['target_port'];
        if (isset($options['target_db'])) {
            $dsn .= ';dbname=' . $options['target_db'];
        }
        return new PDO($dsn, $options['target_user'], $options['target_pass']);
    }

    private function write_dump(?string $prefix): void
    {
        $sql = "CREATE TABLE cleanup_probe (id INT PRIMARY KEY);\nINSERT INTO cleanup_probe VALUES (1);\n"
            . "CREATE TABLE other_options (option_name varchar(191), option_value longtext);\n"
            . "INSERT INTO other_options VALUES ('stylesheet_root', '/srv/another-site/themes');\n";
        if ($prefix !== null) {
            $sql .= "CREATE TABLE `{$prefix}options` (option_name varchar(191) PRIMARY KEY, option_value longtext);\n"
                . "INSERT INTO `{$prefix}options` VALUES\n"
                . "('stylesheet_root', '/srv/shared-themes'),\n"
                . "('template_root', '/srv/parent-themes'),\n"
                . "('_site_transient_theme_roots', 'a:0:{}'),\n"
                . "('_site_transient_timeout_theme_roots', '9999999999'),\n"
                . "('rewrite_rules', 'a:1:{s:6:\"^old/$\";s:18:\"index.php?old=true\";}'),\n"
                . "('_site_transient_update_core', 'O:8:\"stdClass\":0:{}'),\n"
                . "('_site_transient_timeout_update_core', '9999999999'),\n"
                . "('_site_transient_update_plugins', 'O:8:\"stdClass\":0:{}'),\n"
                . "('_site_transient_timeout_update_plugins', '9999999999'),\n"
                . "('_site_transient_update_themes', 'O:8:\"stdClass\":0:{}'),\n"
                . "('_site_transient_timeout_update_themes', '9999999999'),\n"
                . "('_transient_dirsize_cache', 'a:1:{s:17:\"/srv/site/uploads\";i:4096;}'),\n"
                . "('_transient_timeout_dirsize_cache', '9999999999'),\n"
                . "('stylesheet', 'shop-child'),\n"
                . "('template', 'shop-parent'),\n"
                . "('theme_mods_shop-child', 'a:1:{s:5:\"color\";s:4:\"blue\";}'),\n"
                . "('permalink_structure', '/%postname%/'),\n"
                . "('upload_path', '/srv/custom-media'),\n"
                . "('upload_url_path', 'https://media.example/uploads'),\n"
                . "('cron', 'a:1:{s:7:\"version\";i:2;}'),\n"
                . "('core_updater.lock', '1234567890'),\n"
                . "('auto_updater.lock', '1234567890'),\n"
                . "('_transient_doing_cron', '1234567890'),\n"
                . "('_transient_timeout_doing_cron', '9999999999'),\n"
                . "('auto_update_plugins', 'a:1:{i:0;s:19:\"akismet/akismet.php\";}'),\n"
                . "('auto_update_themes', 'a:1:{i:0;s:11:\"shop-parent\";}'),\n"
                . "('_transient_plugin_cache', 'keep-me'),\n"
                . "('_transient_timeout_plugin_cache', '9999999999'),\n"
                . "('_site_transient_plugin_cache', 'keep-me-too'),\n"
                . "('_site_transient_timeout_plugin_cache', '9999999999');\n";
        }
        $sql .= '-- REPRINT SQL GROUP 82d10e87-ec1b-4aa2-a522-963dc82b6bb1 '
            . base64_encode(json_encode(['current_table' => null])) . "\n";
        file_put_contents($this->temp_dir . '/db.sql', $sql);
    }

    private function assert_site_options(PDO $database, string $prefix): void
    {
        $options = $database->query("SELECT option_name, option_value FROM `{$prefix}options`")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        ksort($options);
        $this->assertSame([
            '_site_transient_plugin_cache' => 'keep-me-too',
            '_site_transient_timeout_plugin_cache' => '9999999999',
            '_transient_doing_cron' => '1234567890',
            '_transient_plugin_cache' => 'keep-me',
            '_transient_timeout_doing_cron' => '9999999999',
            '_transient_timeout_plugin_cache' => '9999999999',
            'auto_update_plugins' => 'a:1:{i:0;s:19:"akismet/akismet.php";}',
            'auto_update_themes' => 'a:1:{i:0;s:11:"shop-parent";}',
            'auto_updater.lock' => '1234567890',
            'core_updater.lock' => '1234567890',
            'cron' => 'a:1:{s:7:"version";i:2;}',
            'permalink_structure' => '/%postname%/',
            'stylesheet' => 'shop-child',
            'template' => 'shop-parent',
            'theme_mods_shop-child' => 'a:1:{s:5:"color";s:4:"blue";}',
            'upload_path' => '/srv/custom-media',
            'upload_url_path' => 'https://media.example/uploads',
        ], $options);
    }
}
