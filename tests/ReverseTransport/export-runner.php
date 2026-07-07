<?php
/**
 * Source-side export runner for the reverse-transport tests.
 *
 * Stands in for the loopback request a production reverse-transport source would
 * make to its own site's export.php: reads one synthetic request (JSON on
 * stdin), runs the real export engine, and streams the response to stdout —
 * which export.php writes to after tearing down output buffering. The parent
 * captures stdout over a pipe, exactly as the exporter's own serve() tests do.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../packages/reprint-exporter/src/export.php';
require_once __DIR__ . '/../../packages/reprint-exporter/src/class-http-server.php';

$raw = stream_get_contents(STDIN);
$request = json_decode((string) $raw, true);
if (!is_array($request)) {
    fwrite(STDERR, "reverse-transport export runner: invalid request json\n");
    exit(1);
}

$server = new Site_Export_HTTP_Server();
$server->handle_request($request);
