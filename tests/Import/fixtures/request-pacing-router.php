<?php

// A host can reject a request before the real endpoint receives it. The test
// changes this proxy's response, never the client's saved state.
// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Local proxy fixture reads the endpoint; no WordPress form is involved.
$reprint_endpoint = $_POST['endpoint'] ?? '';
file_put_contents('requests.jsonl', json_encode(['endpoint' => $reprint_endpoint, 'time' => microtime(true)]) . "\n", FILE_APPEND);
if ($reprint_endpoint === file_get_contents('reject-next-endpoint') || $reprint_endpoint === file_get_contents('reject-all-endpoint')) {
    file_put_contents('reject-next-endpoint', '');
    http_response_code(429);
    echo 'Too Many Requests';
    return;
}

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

require_once __DIR__ . '/../../../vendor/autoload.php';
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
