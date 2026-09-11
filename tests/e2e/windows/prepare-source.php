<?php
/** Provisions a real Windows WordPress site and records its file hashes. */

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('The source must run native Windows PHP.');
}
[$script, $site_directory, $site_url, $manifest_path] = $argv;
$database = new PDO('mysql:host=127.0.0.1', 'root', 'root');
$database->exec('CREATE DATABASE migration_source');
$config = <<<'PHP'
<?php
 define('DB_NAME', 'migration_source');
 define('DB_USER', 'root');
 define('DB_PASSWORD', 'root');
 define('DB_HOST', '127.0.0.1');
 define('DB_CHARSET', 'utf8mb4');
 define('DB_COLLATE', '');
 define('DISABLE_WP_CRON', true);
 $table_prefix = 'wp_';
 define('ABSPATH', __DIR__ . '/');
 require_once ABSPATH . 'wp-settings.php';
PHP;
file_put_contents($site_directory . '/wp-config.php', $config);
file_put_contents($site_directory . '/wp-content/plugins/reprint-server/secret.php', "<?php return 'windows-migration-secret';\n");
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = parse_url($site_url, PHP_URL_HOST) . ':8081';
require $site_directory . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_install('Windows migration site', 'migration', 'migration@example.test', true, '', 'migration-password');
update_option('home', $site_url);
update_option('siteurl', $site_url);
$result = activate_plugin('reprint-server/index.php');
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
$upload_directory = $site_directory . '/wp-content/uploads/migration';
mkdir($upload_directory, 0777, true);
mkdir($upload_directory . '/empty directory');
file_put_contents($upload_directory . '/large file.bin', str_repeat("Windows to Linux\0\xff\r\n", 300000));
file_put_contents($upload_directory . '/hello.txt', "Hello from Windows!\r\n");
file_put_contents($upload_directory . '/zażółć 你好.txt', "Unicode filename on Windows\n");
update_option('migration_nested_urls', ['image' => ['url' => $site_url . '/wp-content/uploads/migration/hello.txt']]);
$post_id = wp_insert_post([
    'post_title' => 'Windows migration post',
    'post_name' => 'windows-migration-post',
    'post_status' => 'publish',
    'post_content' => '<a href="' . $site_url . '/wp-content/uploads/migration/hello.txt">Migrated file</a>',
]);
if (!$post_id || is_wp_error($post_id)) {
    throw new RuntimeException('The source post could not be created.');
}
file_put_contents($site_directory . '/migration-check.php', <<<'PHP'
<?php
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json');
$table_rows = [];
foreach ($wpdb->tables() as $table) {
    // Loading WordPress may create or expire caches on either host.
    $where = $table === $wpdb->options ? " WHERE option_name NOT REGEXP '^_(site_)?transient_'" : '';
    $table_rows[$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`$where");
}
echo json_encode([
    'os' => PHP_OS_FAMILY,
    'table_rows' => $table_rows,
    'home' => home_url(),
    'siteurl' => site_url(),
    'nested' => get_option('migration_nested_urls'),
    'post' => get_page_by_path('windows-migration-post', OBJECT, 'post')->post_content,
    'plugin_active' => in_array('reprint-server/index.php', get_option('active_plugins'), true),
]);
PHP);
$hashes = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site_directory, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile()) {
        $relative_path = str_replace('\\', '/', substr($file->getPathname(), strlen($site_directory) + 1));
        $hashes[$relative_path] = hash_file('sha256', $file->getPathname());
    }
}
file_put_contents($manifest_path, json_encode(['os' => PHP_OS_FAMILY, 'files' => $hashes], JSON_THROW_ON_ERROR));
printf("Prepared %d files on %s at %s\n", count($hashes), PHP_OS_FAMILY, ABSPATH);
