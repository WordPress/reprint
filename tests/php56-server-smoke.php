<?php

if ($argc !== 2 || !is_dir($argv[1])) {
    fail_php56_server_smoke('Usage: php php56-server-smoke.php <plugin-directory>');
}

$plugin_directory = realpath($argv[1]);
define('ABSPATH', sys_get_temp_dir() . '/reprint-php56-wordpress/');
date_default_timezone_set('UTC');

require $plugin_directory . '/lib.php';

if (\WordPress\Reprint\Server\Plugin\push_is_supported()) {
    fail_php56_server_smoke('Push must remain disabled below PHP 7.2.');
}

$server_runtime = \WordPress\Reprint\Server\Plugin\load_server_runtime();
if ($server_runtime === null) {
    fail_php56_server_smoke('The packaged server runtime could not be loaded.');
}
require_once $server_runtime;

if (!class_exists('WordPress\\Reprint\\Server\\HTTPServer')) {
    fail_php56_server_smoke('The packaged HTTP server class could not be loaded.');
}
if (\WordPress\Reprint\Server\HTTPServer::is_push_endpoint('preflight')) {
    fail_php56_server_smoke('The preflight endpoint was classified as push.');
}

$temporary_directory = sys_get_temp_dir() . '/reprint-php56-server-' . getmypid();
if (!mkdir($temporary_directory, 0700) && !is_dir($temporary_directory)) {
    fail_php56_server_smoke('The temporary server directory could not be created.');
}

try {
    $docroot = $temporary_directory . '/docroot';
    if (!mkdir($docroot, 0700) && !is_dir($docroot)) {
        fail_php56_server_smoke('The push smoke-test document root could not be created.');
    }
    $reprint_directory = $temporary_directory . '/push-state';
    $server = new \WordPress\Reprint\Server\HTTPServer([
        'push' => [
            'reprint_directory' => $reprint_directory,
            'docroot' => $docroot,
            'excluded_paths' => [],
        ],
    ]);
    ob_start();
    $server->dispatch([
        'endpoint' => 'push_create',
        'push_session_id' => str_repeat('a', 32),
    ]);
    $push_response = json_decode(ob_get_clean(), true);
    if (
        http_response_code() !== 503
        || !is_array($push_response)
        || !isset($push_response['status'])
        || $push_response['status'] !== 'rejected'
        || !isset($push_response['reason'])
        || $push_response['reason'] !== 'push_disabled'
        || !isset($push_response['detail'])
        || strpos($push_response['detail'], 'Push endpoints require PHP 7.2 or newer') === false
    ) {
        fail_php56_server_smoke('The packaged HTTP server returned the wrong push version response.');
    }
    if (file_exists($reprint_directory)) {
        fail_php56_server_smoke('The packaged HTTP server started push work below PHP 7.2.');
    }
    rmdir($docroot);
    http_response_code(200);

    ob_start();
    $preflight = endpoint_preflight(['directory' => [$temporary_directory]]);
    ob_end_clean();
    if (!isset($preflight['stats']['php']['version'])) {
        fail_php56_server_smoke('Preflight did not report the PHP version.');
    }

    ob_start();
    $output_stream = new WordPress\Reprint\Server\GzipOutputStream(true);
    $output_stream->write('php56-stream');
    $output_stream->finish();
    $stream_output = ob_get_clean();
    if ($stream_output !== 'php56-stream') {
        fail_php56_server_smoke('PHP 5.6 did not use the identity streaming fallback.');
    }

    if (strlen(WordPress\Reprint\Server\generate_random_bytes(16)) !== 16) {
        fail_php56_server_smoke('The PHP 5.6 random-byte fallback returned the wrong length.');
    }
} finally {
    rmdir($temporary_directory);
}

echo "PHP 5.6 server runtime smoke passed.\n";

function plugin_dir_path($path) {
    return dirname($path) . '/';
}

function fail_php56_server_smoke($message) {
    fwrite(STDERR, $message . "\n");
    exit(1);
}
