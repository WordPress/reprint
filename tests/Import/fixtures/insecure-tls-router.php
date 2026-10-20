<?php

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Authenticate the exact incoming request.
// phpcs:disable WordPress.Security.NonceVerification -- Reprint HMAC replaces WordPress form nonces.

file_put_contents(getcwd() . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);

// Use the production request authentication and endpoint dispatch behind TLS.
require_once __DIR__ . '/../../../vendor/autoload.php';
// This fixture models a host without openssl_verify(), so HMACServer keeps
// accepting the connection token.
\WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
// This fixture exposes two configured routes: a direct HTTP endpoint and its TLS relay.
$reprint_api_urls = json_decode(file_get_contents('api-urls.json'), true);
$reprint_api_url = $reprint_api_urls[0];
foreach ($reprint_api_urls as $reprint_configured_url) {
    if (\WordPress\Reprint\Server\Utils::url_origin($reprint_configured_url) === ( $_SERVER['HTTP_X_AUTH_ORIGIN'] ?? '' )) {
        $reprint_api_url = $reprint_configured_url;
        break;
    }
}
$reprint_authentication = new \WordPress\Reprint\Server\HMACServer('tls-test-secret', $reprint_api_url);
$reprint_authentication_error = $reprint_authentication->verify(
    getallheaders(),
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI']
);
if ($reprint_authentication_error !== null) {
    http_response_code(401);
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
