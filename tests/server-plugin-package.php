<?php
/** Check the generated plugin directory before publishing its ZIP. */

if ($argc < 2 || $argc > 3 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php server-plugin-package.php <plugin-directory> [release-version]\n");
    exit(1);
}

$reprint_plugin_directory = rtrim($argv[1], '/');
$reprint_project_directory = dirname(__DIR__);
foreach (['index.php', 'lib.php', 'compat.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'vendor/autoload.php'] as $reprint_relative_file) {
    reprint_require_plugin_package(is_file($reprint_plugin_directory . '/' . $reprint_relative_file), 'The plugin package is missing ' . $reprint_relative_file . '.');
}

// Every maintained PHP file and admin asset must survive staging and ZIP extraction.
foreach (['reprint-server-wp' => '', 'packages/reprint-server/src' => 'vendor/wp-php-toolkit/reprint-server/src/'] as $reprint_source_directory => $reprint_destination_prefix) {
    $reprint_source_directory = $reprint_project_directory . '/' . $reprint_source_directory;
    $reprint_source_files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reprint_source_directory, FilesystemIterator::SKIP_DOTS));
    foreach ($reprint_source_files as $reprint_source_file) {
        $reprint_relative_file = substr($reprint_source_file->getPathname(), strlen($reprint_source_directory) + 1);
        if (strpos($reprint_relative_file, 'vendor/') === 0 || in_array($reprint_relative_file, ['secret.php', 'public-keys.php'], true)) {
            continue;
        }
        if (!$reprint_source_file->isFile() || !in_array($reprint_source_file->getExtension(), ['php', 'css', 'js'], true)) {
            continue;
        }
        $reprint_packaged_file = $reprint_destination_prefix . $reprint_relative_file;
        reprint_require_plugin_package(is_file($reprint_plugin_directory . '/' . $reprint_packaged_file), 'The plugin package is missing ' . $reprint_packaged_file . '.');
    }
}

foreach (['secret.php', 'public-keys.php', '.gitignore'] as $reprint_relative_file) {
    reprint_require_plugin_package(!file_exists($reprint_plugin_directory . '/' . $reprint_relative_file), 'The plugin package must not contain ' . $reprint_relative_file . '.');
}
$reprint_packaged_files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reprint_plugin_directory, FilesystemIterator::SKIP_DOTS));
foreach ($reprint_packaged_files as $reprint_packaged_file) {
    reprint_require_plugin_package(!$reprint_packaged_file->isLink(), 'The plugin package contains a symlink at ' . $reprint_packaged_file->getPathname() . '.');
}
foreach (['LICENSE', 'vendor/wp-php-toolkit/reprint-server/LICENSE'] as $reprint_relative_file) {
    $reprint_license_file = $reprint_plugin_directory . '/' . $reprint_relative_file;
    reprint_require_plugin_package(is_file($reprint_license_file) && file_get_contents($reprint_license_file) === file_get_contents($reprint_project_directory . '/LICENSE'), 'The plugin package must include the repository GPL license at ' . $reprint_relative_file . '.');
}

$reprint_plugin_header = file_get_contents($reprint_plugin_directory . '/index.php');
$reprint_plugin_readme = file_get_contents($reprint_plugin_directory . '/readme.txt');
$reprint_plugin_version = reprint_plugin_package_header($reprint_plugin_header, 'Version');
foreach (['Plugin Name', 'Plugin URI', 'Requires at least', 'Requires PHP', 'Text Domain', 'License', 'License URI'] as $reprint_header_name) {
    reprint_plugin_package_header($reprint_plugin_header, $reprint_header_name);
}
foreach (['Requires at least', 'Requires PHP', 'License', 'License URI'] as $reprint_header_name) {
    reprint_require_plugin_package(reprint_plugin_package_header($reprint_plugin_readme, $reprint_header_name) === reprint_plugin_package_header($reprint_plugin_header, $reprint_header_name), 'The readme and plugin header disagree on ' . $reprint_header_name . '.');
}
reprint_require_plugin_package(reprint_plugin_package_header($reprint_plugin_readme, 'Stable tag') === $reprint_plugin_version, 'The readme Stable tag must match the plugin Version.');
reprint_require_plugin_package(reprint_plugin_package_header($reprint_plugin_header, 'Requires PHP') === '5.6.20', 'The generated plugin must declare PHP 5.6.20.');
if (isset($argv[2])) {
    reprint_require_plugin_package(preg_match('/^[0-9]+(?:\.[0-9]+)+$/', $reprint_plugin_version) === 1, 'A published plugin must have a numeric stable version; observed ' . $reprint_plugin_version . '.');
    reprint_require_plugin_package($reprint_plugin_version === $argv[2], 'The plugin Version must match release ' . $argv[2] . '; observed ' . $reprint_plugin_version . '.');
}

// Load only the packaged runtime; a repository vendor tree must not rescue the ZIP.
require $reprint_plugin_directory . '/vendor/autoload.php';
foreach (['Utils', 'HTTPServer', 'RequestAuthenticator'] as $reprint_class_name) {
    reprint_require_plugin_package(class_exists('WordPress\\Reprint\\Server\\' . $reprint_class_name), 'The packaged autoloader cannot load ' . $reprint_class_name . '.');
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standalone package test supplies WordPress's bootstrap constant.
define('ABSPATH', $reprint_plugin_directory . '/');
define('WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR', $reprint_plugin_directory . '/');
require $reprint_plugin_directory . '/lib.php';
reprint_require_plugin_package(constant('WordPress\\Reprint\\Server\\Plugin\\VERSION') === $reprint_plugin_version, 'The plugin header and runtime versions must match.');

fwrite(STDOUT, 'Plugin package verified: ' . $reprint_plugin_version . ".\n");

function reprint_require_plugin_package($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function reprint_plugin_package_header($contents, $reprint_header_name) {
    $matched = preg_match('/^[ \t*]*' . preg_quote($reprint_header_name, '/') . ':[ \t]*(.+)$/mi', $contents, $matches);
    reprint_require_plugin_package($matched === 1 && trim($matches[1]) !== '', 'The package is missing the ' . $reprint_header_name . ' header.');
    return trim($matches[1]);
}
