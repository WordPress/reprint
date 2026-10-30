<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';
require_once __DIR__ . '/../../lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php';

final class OpenBasedirPullTest extends TestCase {
    private string $root;
    private string $site;
    private string $remote_reprint_api_url;

    /** @var resource|null */
    private $server_process = null;

    /** @var resource|null */
    private $progress_stream = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('curl') || !extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The restricted-host pull tests require curl and pdo_sqlite.');
        }

        $this->root = realpath(sys_get_temp_dir()) . '/open-basedir-pull-' . bin2hex(random_bytes(6));
        $this->site = $this->root . '/site';
        foreach (['plugins/ordinary', 'mu-plugins', 'themes/ordinary', 'uploads'] as $directory) {
            mkdir($this->site . '/wp-content/' . $directory, 0700, true);
        }
        mkdir($this->root . '/shared/theme', 0700, true);
        mkdir($this->root . '/request-uploads', 0700);
        file_put_contents($this->root . '/shared/advice.php', '<?php // Host-only plugin');
        file_put_contents($this->site . '/index.php', '<?php echo "Site copy";');
        file_put_contents($this->site . '/wp-load.php', '<?php // Runtime supplied by the router.');
        file_put_contents($this->site . '/wp-content/plugins/ordinary/ordinary.php', '<?php // Ordinary plugin');
        file_put_contents($this->site . '/wp-content/mu-plugins/ordinary.php', '<?php // Site plugin');
        file_put_contents($this->site . '/wp-content/themes/ordinary/style.css', '/* Site theme */');
        file_put_contents($this->site . '/wp-content/uploads/large.bin', str_repeat('site bytes', 600000));
        symlink($this->root . '/shared/advice.php', $this->site . '/wp-content/mu-plugins/host-advice.php');
        symlink($this->root . '/shared', $this->site . '/wp-content/plugins/host-plugin');
        symlink($this->root . '/shared/theme', $this->site . '/wp-content/themes/host-theme');
        symlink('ordinary', $this->site . '/wp-content/plugins/linked-ordinary');

        $pdo = new \PDO('sqlite:' . $this->root . '/database.sqlite');
        $driver = new \WP_SQLite_Driver(new \WP_SQLite_Connection(['pdo' => $pdo]), 'wordpress');
        $driver->query('CREATE TABLE wp_posts (ID bigint unsigned NOT NULL PRIMARY KEY, post_title text NOT NULL)');
        $driver->query("INSERT INTO wp_posts VALUES (1, 'A post from the restricted host')");

        $this->remote_reprint_api_url = $this->start_server();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server_process)) {
            proc_terminate($this->server_process);
            proc_close($this->server_process);
        }
        if (is_resource($this->progress_stream)) {
            fclose($this->progress_stream);
        }
        if (isset($this->root)) {
            $this->remove_tree($this->root);
        }
        parent::tearDown();
    }

    public function testPreflightKeepsReadableContentAndReportsRestrictedEntries(): void
    {
        $client = $this->new_client();
        $client->run_preflight();

        $preflight = $client->get_state()->preflight_record();
        $this->assertSame(200, $preflight['http_code'], $preflight['error'] ?? 'Preflight failed.');
        $this->assertTrue($preflight['data']['ok']);
        $content = $preflight['data']['wp_content'];
        $inventory = $content['roots'][0];
        $this->assertContains(['name' => 'ordinary.php', 'type' => 'file'], $inventory['mu_plugins']);
        $this->assertContains(['name' => 'host-advice.php', 'type' => 'unknown'], $inventory['mu_plugins']);
        $this->assertContains(['name' => 'linked-ordinary', 'type' => 'dir'], $inventory['plugins']);
        $this->assertContains(['name' => 'host-plugin', 'type' => 'unknown'], $inventory['plugins']);
        $this->assertSame(['ordinary'], $inventory['themes']);
        $unavailable_paths = array_map('base64_decode', $content['unavailable_paths_b64']);
        $this->assertEqualsCanonicalizing([
            $this->site . '/wp-content/mu-plugins/host-advice.php',
            $this->site . '/wp-content/plugins/host-plugin',
            $this->site . '/wp-content/themes/host-theme',
        ], $unavailable_paths);
        rewind($this->progress_stream);
        $progress = stream_get_contents($this->progress_stream);
        $warnings = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", trim($progress))
        );
        $this->assertCount(3, $warnings);
        foreach ($warnings as $warning) {
            $this->assertSame('warning', $warning['type']);
            $this->assertSame('preflight', $warning['phase']);
            $this->assertContains($warning['path'], $unavailable_paths);
        }
    }

    public function testFilesPullCopiesReadableFilesAndReportsTheBlockedPaths(): void
    {
        $client = $this->new_client();
        $client->run_preflight();
        $this->assertSame(200, $client->get_state()->preflight_record()['http_code']);

        $client->run_files_pull();

        $this->assertSame('complete', $client->get_state()->active_resumable_command->completion_state);
        $files = $this->root . '/files';
        foreach (['index.php', 'wp-load.php', 'wp-content/plugins/ordinary/ordinary.php', 'wp-content/mu-plugins/ordinary.php', 'wp-content/themes/ordinary/style.css', 'wp-content/uploads/large.bin'] as $path) {
            $this->assertSame(file_get_contents($this->site . '/' . $path), file_get_contents($files . $this->site . '/' . $path));
        }
        $this->assertFileDoesNotExist($files . $this->site . '/wp-content/mu-plugins/host-advice.php');
        $this->assertFalse(is_link($files . $this->site . '/wp-content/mu-plugins/host-advice.php'));
        $audit = file_get_contents($this->root . '/state/audit.log');
        $this->assertStringContainsString('host-advice.php', $audit);
        $this->assertStringContainsString('Its contents may be missing from the pull.', $audit);
    }

    public function testDatabasePullStillExportsTheSiteData(): void
    {
        $client = $this->new_client();
        $client->run_preflight();
        $this->assertSame(200, $client->get_state()->preflight_record()['http_code']);

        $client->run_db_sync();

        $this->assertSame('complete', $client->get_state()->active_resumable_command->completion_state);
        $sql = file_get_contents($this->root . '/state/db.sql');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('wp_posts', $sql);
        $pdo = new \PDO('sqlite::memory:');
        $driver = new \WP_SQLite_Driver(new \WP_SQLite_Connection(['pdo' => $pdo]), 'restored');
        $stream = new \WP_MySQL_Naive_Query_Stream();
        $stream->append_sql($sql);
        $stream->mark_input_complete();
        while ($stream->next_query()) {
            $driver->query($stream->get_query());
        }
        $this->assertSame(
            [['ID' => '1', 'post_title' => 'A post from the restricted host']],
            $pdo->query('SELECT ID, post_title FROM wp_posts')->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    private function new_client(): \ImportClient
    {
        $client = new \ImportClient($this->remote_reprint_api_url, $this->root . '/state', $this->root . '/files', ['allow_http' => true]);
        $this->progress_stream = fopen('php://temp', 'w+');
        ( new \ReflectionProperty(\ImportClient::class, 'progress_fd') )->setValue($client, $this->progress_stream);
        return $client;
    }

    private function start_server(): string
    {
        $repository = dirname(__DIR__, 2);
        $router = $this->root . '/router.php';
        file_put_contents($router, sprintf(<<<'PHP'
<?php
require %s;
require %s;
require %s;
$site = __DIR__ . '/site';
define('ABSPATH', $site . '/');
define('WP_CONTENT_DIR', $site . '/wp-content');
define('SQLITE_DB_DROPIN_VERSION', '1.8.0');
$GLOBALS['@pdo'] = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$driver = new WP_SQLite_Driver(new WP_SQLite_Connection(['pdo' => $GLOBALS['@pdo']]), 'wordpress');
$GLOBALS['wpdb'] = (object) ['dbh' => $driver, 'prefix' => 'wp_', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci'];
$GLOBALS['table_prefix'] = 'wp_';
function get_option($name) { return $name === 'active_plugins' ? [] : 'http://site.example'; }
function get_theme_root() { return WP_CONTENT_DIR . '/themes'; }
\WordPress\Reprint\Server\HTTPServer::serve(['default_directory' => $site]);
PHP,
            // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The router needs safely quoted PHP path literals.
            var_export($repository . '/vendor/autoload.php', true),
            var_export($repository . '/lib/sqlite-database-integration/packages/mysql-on-sqlite/src/load.php', true),
            var_export($repository . '/packages/reprint-server/src/class-http-server.php', true)
            // phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
        ));

        $socket = stream_socket_server('tcp://127.0.0.1:0', $error_number, $error_message);
        $this->assertIsResource($socket, $error_message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $allowed_paths = implode(PATH_SEPARATOR, [
            $this->site,
            $repository,
            $router,
            $this->root . '/database.sqlite',
            $this->root . '/request-uploads',
        ]);
        $this->server_process = proc_open(
            [PHP_BINARY, '-d', 'open_basedir=' . $allowed_paths, '-d', 'upload_tmp_dir=' . $this->root . '/request-uploads', '-S', $address, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']],
            $pipes,
            $this->site
        );
        $this->assertIsResource($this->server_process);
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $connection = @stream_socket_client('tcp://' . $address, $error_number, $error_message, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://' . $address . '/?site-export-api&' . http_build_query(['directory' => $this->site]);
            }
            usleep(100000);
        }
        $this->fail('The restricted-host test server did not start.');
    }

    private function remove_tree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove_tree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
