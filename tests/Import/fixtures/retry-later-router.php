<?php

// Model an upstream outage in front of the real Reprint endpoint. The test
// controls the proxy response, never the importer's private state.
// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Local proxy fixture reads the request endpoint; no WordPress form is involved.
$reprint_endpoint = $_POST['endpoint'] ?? '';
file_put_contents('requests.log', $reprint_endpoint . "\n", FILE_APPEND);
// Supply site metadata without requiring a WordPress database. File indexes
// and file contents below are produced by the real endpoint.
if ($reprint_endpoint === 'preflight') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'protocol_version' => 3,
        'capabilities' => ['base64_path_parameters' => true],
        'runtime' => ['document_root' => getcwd() . '/remote', 'ini_get_all' => []],
        'wp_detect' => ['roots' => [['path' => getcwd() . '/remote']]],
    ]);
    return;
}
$reprint_status = (int) file_get_contents('proxy-status');
if ($reprint_endpoint === file_get_contents('proxy-endpoint') && !in_array($reprint_status, [200, -2], true)) {
    if ($reprint_status === 0 || $reprint_status === -1) {
        if ($reprint_status === -1) {
            header('Content-Length: 1000');
        }
        // A worker or proxy can cut the response short before completion.
        header('Content-Type: multipart/mixed; boundary=interrupted-export');
        echo "--interrupted-export\r\n";
        return;
    }
    http_response_code($reprint_status);
    echo 'Upstream unavailable';
    return;
}

// Stop the real endpoint after it has sent complete parts, before completion.
putenv('REPRINT_SERVER_TEST_MODE=1');
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter -- The endpoint defines this hook name and signature.
function test_hook_before_index_batch(&$batch_items, $stack) {
    global $reprint_endpoint, $reprint_status, $streaming_context;
    static $batches = 0;
    if (++$batches === 2 && $reprint_status === -2 && $reprint_endpoint === 'file_index') {
        file_put_contents('proxy-status', '200');
        $streaming_context['gz']->write("--{$streaming_context['boundary']}\r\n");
        $streaming_context['gz']->finish();
        exit;
    }
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The endpoint defines this hook name.
function test_hook_before_completion($status, $stream, $boundary) {
    global $reprint_endpoint, $reprint_status;
    if ($reprint_status === -2 && $reprint_endpoint === file_get_contents('proxy-endpoint')) {
        file_put_contents('proxy-status', '200');
        $stream->write("--{$boundary}\r\n");
        $stream->finish();
        exit;
    }
}

require_once __DIR__ . '/../../../packages/reprint-server/src/class-http-server.php';
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
