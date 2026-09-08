<?php

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- This local fixture inspects the exact headers and request target sent over the wire.
// phpcs:disable WordPress.Security.NonceVerification -- Requests use the real Reprint HMAC verifier below, not WordPress form nonces.

// Model the reported ZipWP temporary-site page in front of the real router.
file_put_contents('cookie.log', ( $_SERVER['HTTP_COOKIE'] ?? '' ) . "\n", FILE_APPEND);
if (empty($_COOKIE['zipwp_access'])) {
    header('Content-Type: text/html');
    echo '<html><body>Continue with temporary site</body></html>';
    return;
}

require_once __DIR__ . '/../../../packages/reprint-server/src/class-http-server.php';
require_once __DIR__ . '/../../../packages/reprint-server/src/class-hmac-server.php';
require_once __DIR__ . '/../../../packages/reprint-server/src/class-hmac-client.php';
require_once __DIR__ . '/../../../packages/reprint-server/src/class-push-session.php';

$reprint_authentication = new \WordPress\Reprint\Server\HMACServer('zipwp-test-secret');
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
    header('Content-Type: application/json');
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
