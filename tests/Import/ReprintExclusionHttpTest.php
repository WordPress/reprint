<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

/** Exercise the real CLI, HTTP endpoints, SQL dump, and target database together. */
class ReprintExclusionHttpTest extends TestCase
{
    private string $root;
    private string $url;
    private string $source_database;
    private string $target_database;
    private PDO $database;
    /** @var resource|null Local PHP endpoint process. */
    private $server;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/reprint-exclusion-http-' . $id;
        mkdir($this->root . '/source/custom-plugins/other', 0700, true);
        mkdir($this->root . '/plugin', 0700, true);
        $this->root = realpath($this->root);
        file_put_contents($this->root . '/source/wp-load.php', '<?php');
        file_put_contents($this->root . '/source/index.php', '<?php');
        file_put_contents($this->root . '/source/custom-plugins/other/index.php', 'keep another plugin');
        file_put_contents($this->root . '/plugin/index.php', 'Reprint plugin fixture');
        file_put_contents($this->root . '/plugin/secret.php', str_repeat('source secret ', 100000));
        symlink($this->root . '/plugin', $this->root . '/source/custom-plugins/renamed');
        symlink($this->root . '/source/custom-plugins', $this->root . '/installed-plugins');
        $this->source_database = 'reprint_exclusion_source_' . $id;
        $this->target_database = 'reprint_exclusion_target_' . $id;
        $this->database = new PDO('mysql:host=' . getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec("CREATE DATABASE `{$this->source_database}`");
        $this->database->exec("CREATE DATABASE `{$this->target_database}`");
        $this->database->exec("USE `{$this->source_database}`");
        $this->database->exec('CREATE TABLE custom_options (option_id bigint unsigned AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20))');
        $options = [
            'reprint_server_connection_token' => str_repeat('a', 64),
            'reprint_server_push_authorized_token_fingerprint' => '',
            'site_export_secret' => 'old-secret',
            'site_export_push_authorized_token_fingerprint' => 'old-fingerprint',
            'active_plugins' => serialize(['renamed/index.php', 'other/index.php', 'renamed-extra/index.php']),
            'another_plugin_token' => 'keep-this-setting',
        ];
        $insert = $this->database->prepare('INSERT INTO custom_options (option_name, option_value, autoload) VALUES (?, ?, ?)');
        foreach ($options as $name => $value) {
            $insert->execute([$name, $value, $name === 'reprint_server_connection_token' ? 'yes' : 'no']);
        }
        // Supply WordPress installation facts to the real exporter without
        // requiring a second WordPress test installation or a fake transport.
        $configuration = '<?php $table_prefix = "custom_";' . "\n";
        foreach ([
            'DB_HOST' => getenv('DB_HOST'), 'DB_USER' => getenv('DB_USER'),
            'DB_PASSWORD' => getenv('DB_PASS'), 'DB_NAME' => $this->source_database,
            'ABSPATH' => $this->root . '/source/', 'WP_PLUGIN_DIR' => $this->root . '/installed-plugins',
            'WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR' => $this->root . '/plugin/',
        ] as $name => $value) {
            $configuration .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        $configuration .= 'function plugin_basename($file) { return "renamed/" . basename($file); }';
        file_put_contents($this->root . '/configuration.php', $configuration);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->url = 'http://' . $address . '/';
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/reprint-exclusion-router.php'], [
            0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a'],
        ], $pipes, $this->root);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail(file_get_contents($this->root . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (isset($this->database)) {
            $this->database->exec("DROP DATABASE IF EXISTS `{$this->source_database}`");
            $this->database->exec("DROP DATABASE IF EXISTS `{$this->target_database}`");
        }
        if (isset($this->root)) {
            (new \ReflectionMethod(\ImportClient::class, 'rmdir_recursive'))->invoke(null, $this->root);
        }
    }

    public static function exclusion_modes(): array
    {
        return [['--exclude-reprint', true], ['--include-reprint', false], ['', false]];
    }

    /** @dataProvider exclusion_modes */
    public function testPullExcludesFilesAndDatabaseStateTogether(string $flag, bool $excluded): void
    {
        $source_options = $this->database->query('SELECT * FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->run_client('pull', [$flag, '--runtime=none', '--start-runtime=none', '--new-site-url=http://destination.test',
            '--include=' . $this->root . '/source', '--include=' . $this->root . '/plugin',
            '--target-engine=mysql', '--target-host=' . getenv('DB_HOST'), '--target-user=' . getenv('DB_USER'),
            '--target-pass=' . getenv('DB_PASS'), '--target-db=' . $this->target_database]);

        $this->assertSame(!$excluded, file_exists($this->root . '/files' . $this->root . '/plugin/secret.php'));
        $this->assertSame(!$excluded, is_link($this->root . '/files' . $this->root . '/source/custom-plugins/renamed'));
        $this->assertFileExists($this->root . '/files' . $this->root . '/source/custom-plugins/other/index.php');
        $options = $this->database->query("SELECT option_name, option_value FROM `{$this->target_database}`.custom_options")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('keep-this-setting', $options['another_plugin_token']);
        $this->assertSame($excluded ? ['other/index.php', 'renamed-extra/index.php'] : ['renamed/index.php', 'other/index.php', 'renamed-extra/index.php'], unserialize($options['active_plugins']));
        $sql = file_get_contents($this->root . '/state/db.sql');
        foreach (array_slice(array_column($source_options, 'option_name'), 0, 4) as $name) {
            $this->assertSame(!$excluded, array_key_exists($name, $options));
            $this->assertSame(!$excluded, strpos($sql, base64_encode($name)) !== false, 'The excluded option must not enter db.sql.');
        }
        $this->assertSame($source_options, $this->database->query('SELECT * FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertFileExists($this->root . '/plugin/secret.php');
    }

    public function testDirectMysqlOutputAlsoRemovesActivation(): void
    {
        $this->run_client('preflight');
        $this->run_client('db-pull', ['--exclude-reprint', '--sql-output=mysql', '--mysql-host=' . getenv('DB_HOST'),
            '--mysql-user=' . getenv('DB_USER'), '--mysql-password=' . getenv('DB_PASS'), '--mysql-database=' . $this->target_database]);
        $options = $this->database->query("SELECT option_name, option_value FROM `{$this->target_database}`.custom_options")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertArrayNotHasKey('reprint_server_connection_token', $options);
        $this->assertSame(['other/index.php', 'renamed-extra/index.php'], unserialize($options['active_plugins']));
    }

    public function testSavedExclusionSurvivesSeparateCommands(): void
    {
        $this->run_client('preflight');
        $this->run_client('files-pull', ['--exclude-reprint', '--include=' . $this->root . '/source']);
        $this->run_client('db-pull');
        $sql = file_get_contents($this->root . '/state/db.sql');
        $this->assertStringNotContainsString(base64_encode('reprint_server_connection_token'), $sql);
        $this->assertFileDoesNotExist($this->root . '/files' . $this->root . '/source/custom-plugins/renamed');
    }

    public static function source_engines(): array
    {
        return [['mysql'], ['sqlite']];
    }

    /** @dataProvider source_engines */
    public function testDumpCanBeImportedWithoutReprintAndContainsNoActivationLeftovers(string $engine): void
    {
        $source = $this->database;
        if ($engine === 'sqlite') {
            $loader = dirname(__DIR__, 2) . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';
            require_once $loader;
            $dsn = 'mysql-on-sqlite:path=' . $this->root . '/source.sqlite;dbname=wordpress';
            $source = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $source->exec('CREATE TABLE custom_options (option_id bigint unsigned AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) UNIQUE, option_value longtext, autoload varchar(20))');
            $insert = $source->get_connection()->get_pdo()->prepare('INSERT INTO custom_options (option_name, option_value, autoload) VALUES (?, ?, ?)');
            foreach ($this->database->query('SELECT option_name, option_value, autoload FROM custom_options')->fetchAll(PDO::FETCH_NUM) as $row) {
                $insert->execute($row);
            }
            file_put_contents($this->root . '/configuration.php',
                'require_once ' . var_export($loader, true) . ';'
                . '$wpdb = (object) ["dbh" => new WP_PDO_MySQL_On_SQLite(' . var_export($dsn, true) . ')];'
                . '$GLOBALS["@pdo"] = $wpdb->dbh->get_connection()->get_pdo();'
                . '$GLOBALS["@pdo"]->sqliteCreateFunction("FROM_BASE64", "base64_decode");'
                . 'define("SQLITE_DB_DROPIN_VERSION", "3.0.0");', FILE_APPEND);
        }
        $source_options = $source->query('SELECT * FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->run_client('preflight');
        $this->run_client('db-pull', ['--exclude-reprint']);
        $target = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->target_database,
            getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $target->exec(file_get_contents($this->root . '/state/db.sql'));
        $options = $target->query('SELECT option_name, option_value FROM custom_options')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['other/index.php', 'renamed-extra/index.php'], unserialize($options['active_plugins']));
        $this->assertSame(['active_plugins', 'another_plugin_token'], array_keys($options));
        $this->assertSame($source_options, $source->query('SELECT * FROM custom_options ORDER BY option_id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testNetworkActivationIsFilteredInTheExportedSql(): void
    {
        foreach ([
            'custom_sitemeta' => '(meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(191), meta_value longtext)',
            'custom_users' => '(ID bigint unsigned PRIMARY KEY)',
            'custom_usermeta' => '(umeta_id bigint unsigned PRIMARY KEY, user_id bigint unsigned, meta_key varchar(191), meta_value longtext)',
            'custom_posts' => '(ID bigint unsigned PRIMARY KEY, post_author bigint unsigned)',
            'custom_comments' => '(comment_ID bigint unsigned PRIMARY KEY, user_id bigint unsigned)',
            'custom_links' => '(link_id bigint unsigned PRIMARY KEY, link_owner bigint unsigned)',
        ] as $table => $columns) {
            $this->database->exec("CREATE TABLE {$table} {$columns}");
        }
        $insert = $this->database->prepare('INSERT INTO custom_sitemeta (site_id, meta_key, meta_value) VALUES (?, ?, ?)');
        $insert->execute([7, 'active_sitewide_plugins', serialize(['renamed/index.php' => 12, 'other/index.php' => 34])]);
        $insert->execute([8, 'active_sitewide_plugins', serialize(['renamed/index.php' => 56])]);
        $before = $this->database->query('SELECT * FROM custom_sitemeta ORDER BY meta_id')->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($this->root . '/configuration.php',
            '$multisite = ["base_prefix" => "custom_", "site_id" => 1, "network_id" => 7];', FILE_APPEND);
        $request = curl_init($this->url);
        curl_setopt_array($request, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query([
            'endpoint' => 'sql_chunk', 'exclude_reprint' => true, 'multisite_mode' => 'one-site-network-v1',
        ]), CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '']);
        $response = curl_exec($request);
        $this->assertSame(200, curl_getinfo($request, CURLINFO_HTTP_CODE), (string) $response);
        curl_close($request);
        $this->assertSame(1, preg_match('/^--([^\r\n]+)/', $response, $boundary));
        $sql = '';
        $errors = [];
        $parser = new \Reprint\Importer\Protocol\MultipartStreamParser($boundary[1], static function ($part) use (&$sql, &$errors): void {
            if ($part['type'] !== 'body') {
                return;
            }
            if ($part['headers']['x-chunk-type'] === 'sql') {
                $sql .= $part['data'];
            } elseif ($part['headers']['x-chunk-type'] === 'error') {
                $errors[] = $part['data'];
            }
        });
        $parser->feed($response);
        $this->assertSame([], $errors);
        $target = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . $this->target_database,
            getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $target->exec($sql);
        $plugins = $target->query("SELECT meta_value FROM custom_sitemeta WHERE meta_key = 'active_sitewide_plugins'")->fetchColumn();
        $this->assertSame(['other/index.php' => 34], unserialize($plugins));
        $this->assertSame($before, $this->database->query('SELECT * FROM custom_sitemeta ORDER BY meta_id')->fetchAll(PDO::FETCH_ASSOC));
    }

    private function run_client(string $command, array $arguments = []): void
    {
        $log = $this->root . '/client.log';
        $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/packages/reprint-client/bin/reprint-client', $command,
            $this->url, '--state-dir=' . $this->root . '/state', '--fs-root=' . $this->root . '/files'], array_values(array_filter($arguments))), [
            0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a'],
        ], $pipes);
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($process), file_get_contents($log) . "\n" . file_get_contents($this->root . '/server.log'));
    }
}
