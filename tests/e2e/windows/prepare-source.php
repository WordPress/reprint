<?php
/** Provisions a real Windows WordPress site and records its file hashes. */

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('The source must run native Windows PHP.');
}
[$script, $site_directory, $site_url, $manifest_path] = $argv;
// These files live outside WordPress so each --include spelling is tested on its own.
foreach (['C', 'D'] as $drive) {
    $directory = $drive . ':/Reprint path cases/Mixed Case';
    mkdir($directory, 0777, true);
    file_put_contents($directory . '/hello.txt', 'source drive ' . $drive);
}
$path_cases = [
    'drive' => ['source' => 'C:\\Reprint path cases\\Mixed Case', 'destination' => 'C:/Reprint path cases/Mixed Case', 'content' => 'source drive C'],
    'forward' => ['source' => 'D:/Reprint path cases/Mixed Case', 'destination' => 'D:/Reprint path cases/Mixed Case', 'content' => 'source drive D'],
    'lowercase-drive' => ['source' => 'd:\\Reprint path cases\\Mixed Case', 'destination' => 'D:/Reprint path cases/Mixed Case', 'content' => 'source drive D'],
    'mixed' => ['source' => 'D:\\Reprint path cases/Mixed Case\\', 'destination' => 'D:/Reprint path cases/Mixed Case', 'content' => 'source drive D'],
    'share' => ['source' => '\\\\localhost\\d$\\Reprint path cases\\Mixed Case', 'destination' => 'UNC/LOCALHOST/D$/Reprint path cases/Mixed Case', 'content' => 'source drive D'],
    'mixed-share' => ['source' => '\\\\localhost\\d$/Reprint path cases/Mixed Case', 'destination' => 'UNC/LOCALHOST/D$/Reprint path cases/Mixed Case', 'content' => 'source drive D'],
];
// NTFS accepts these names; ext4 cannot store their 256-byte UTF-8 components.
$long_name = str_repeat('é', 126) . '.txt';
foreach (['file', 'directory'] as $type) {
    $directory = 'D:/Reprint path cases/long-' . $type;
    mkdir($directory);
    $file_path = $directory . '/' . $long_name;
    if ($type === 'directory') {
        mkdir($file_path);
        $file_path .= '/hello.txt';
    }
    if (file_put_contents($file_path, 'must not be silently skipped') === false) {
        throw new RuntimeException('Could not create the Windows-only filename: ' . $file_path);
    }
    $path_cases['long-' . $type] = ['source' => $directory, 'error' => 'File name too long'];
}
$database = new PDO('mysql:host=127.0.0.1;port=3308', 'root', 'root');
$database_os = $database->query('SELECT @@version_compile_os')->fetchColumn();
if (stripos($database_os, 'win') !== 0) {
    throw new RuntimeException('The source database must run on Windows; got ' . $database_os);
}
$database->exec('CREATE DATABASE migration_source');
$config = <<<'PHP'
<?php
 define('DB_NAME', 'migration_source');
 define('DB_USER', 'root');
 define('DB_PASSWORD', 'root');
 define('DB_HOST', '127.0.0.1:3308');
 define('DB_CHARSET', 'utf8mb4');
 define('DB_COLLATE', '');
 define('DISABLE_WP_CRON', true);
 $table_prefix = 'wp_';
 if (!defined('ABSPATH')) {
     define('ABSPATH', __DIR__ . '/');
 }
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
$portable_paths = [
    "[draft] #100% & dollar$ 'quote'.txt",
    '日本語/café 😀.txt',
    "cafe\u{0301}.txt",
    'café.txt',
    ' leading space/.hidden',
    '%2e%2e/literal percent.txt',
    str_repeat('a', 251) . '.txt',
    str_repeat('nested/', 45) . 'long path.txt',
];
foreach ($portable_paths as $relative_path) {
    $file_path = $upload_directory . '/' . $relative_path;
    if (!is_dir(dirname($file_path))) {
        mkdir(dirname($file_path), 0777, true);
    }
    if (file_put_contents($file_path, $relative_path) !== strlen($relative_path)) {
        throw new RuntimeException('Could not create the Windows path fixture: ' . $file_path);
    }
}
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
file_put_contents($manifest_path, json_encode(['os' => PHP_OS_FAMILY, 'files' => $hashes, 'path_cases' => $path_cases], JSON_THROW_ON_ERROR));
printf("Prepared %d files on %s at %s\n", count($hashes), PHP_OS_FAMILY, ABSPATH);
