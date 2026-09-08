<?php

// Model an upstream outage in front of the real Reprint endpoint. The test
// controls the proxy response, never the importer's private state.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Local proxy fixture reads the request endpoint; no WordPress form is involved.
$reprint_endpoint = $_GET['endpoint'] ?? '';
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
if ($reprint_endpoint === file_get_contents('proxy-endpoint') && $reprint_status !== 200) {
    if ($reprint_status === 0) {
        // A worker or proxy can cut the response short before completion.
        header('Content-Type: multipart/mixed; boundary=interrupted-export');
        echo "--interrupted-export\r\n";
        return;
    }
    http_response_code($reprint_status);
    echo 'Upstream unavailable';
    return;
}

require_once __DIR__ . '/../../../packages/reprint-server/src/class-http-server.php';
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
