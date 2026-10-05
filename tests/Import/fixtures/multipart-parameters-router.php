<?php

// phpcs:disable WordPress.Security.NonceVerification -- This fixture uses the real Reprint HMAC verifier.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Record the exact multipart parameters and uploaded file received over HTTP.

// Model a query firewall in front of the real exporter. It lets a query
// parameter through only when it is the routing marker or its value holds
// nothing but ASCII letters, digits, and underscores. The reported firewall
// objected to base64 characters in query values.
foreach ($_GET as $reprint_query_key => $reprint_query_value) {
    if (in_array($reprint_query_key, ['reprint-api', 'site-export-api'], true)) {
        continue;
    }
    if (!is_string($reprint_query_value) || !preg_match('/^[A-Za-z0-9_]*\z/', $reprint_query_value)) {
        http_response_code(403);
        return;
    }
}

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../packages/reprint-server/src/class-http-server.php';
require_once __DIR__ . '/../../../packages/reprint-server/src/class-hmac-server.php';

// This fixture models a host without openssl_verify(), so HMACServer keeps
// accepting the connection token.
\WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests(false);
$reprint_authentication = new \WordPress\Reprint\Server\HMACServer('multipart-test-secret');
$reprint_authentication_error = $reprint_authentication->verify(
    getallheaders(),
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI']
);
if ($reprint_authentication_error !== null) {
    http_response_code(403);
    return;
}

file_put_contents('parameters.json', json_encode($_POST));
file_put_contents('request-target.txt', $_SERVER['REQUEST_URI']);
file_put_contents('uploaded-file-list.json', file_get_contents($_FILES['file_list']['tmp_name']));
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
