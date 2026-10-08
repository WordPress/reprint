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

require_once __DIR__ . '/../../../vendor/autoload.php';

// This fixture models a host without openssl_verify(), so HMACServer keeps
// accepting the connection token.
\WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
$reprint_authentication = new \WordPress\Reprint\Server\HMACServer('zipwp-test-secret');
// The test reaches this router as an HTTP proxy, so PHP reports the absolute
// request target. A site behind the proxy receives only the path and query,
// which is what the client signed.
$reprint_request_target = preg_replace('#^[A-Za-z][A-Za-z0-9+.-]*://[^/?]*#', '', $_SERVER['REQUEST_URI']);
$reprint_authentication_error = $reprint_authentication->verify(
    getallheaders(),
    $_SERVER['REQUEST_METHOD'],
    $reprint_request_target
);
if ($reprint_authentication_error !== null) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $reprint_authentication_error,
        'code' => 401,
        'reason' => $reprint_authentication->last_error_reason(),
        'auth_version' => \WordPress\Reprint\Server\Utils::AUTH_VERSION,
    ]);
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
