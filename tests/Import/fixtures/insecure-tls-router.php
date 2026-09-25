<?php

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Authenticate the exact incoming request.
// phpcs:disable WordPress.Security.NonceVerification -- Reprint HMAC replaces WordPress form nonces.

// Use the production request authentication and endpoint dispatch behind TLS.
require_once __DIR__ . '/../../../vendor/autoload.php';
$reprint_authentication = new \WordPress\Reprint\Server\HMACServer('tls-test-secret');
if (\WordPress\Reprint\Server\HTTPServer::is_push_endpoint($_GET['endpoint'] ?? '')) {
    $reprint_authentication_error = $reprint_authentication->verify_envelope(
        getallheaders(),
        $_SERVER['REQUEST_METHOD'],
        Site_Export_HMAC_Client::request_target($_SERVER['REQUEST_URI'])
    );
} else {
    $reprint_authentication_error = $reprint_authentication->verify(getallheaders(), file_get_contents('php://input'), $_FILES);
}
if ($reprint_authentication_error !== null) {
    http_response_code(401);
    echo json_encode(['error' => $reprint_authentication_error]);
    return;
}
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
    'push' => [
        'reprint_directory' => getcwd() . '/push',
        'docroot' => getcwd() . '/remote',
        'excluded_paths' => [],
    ],
]);
