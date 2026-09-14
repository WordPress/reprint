<?php

// phpcs:disable WordPress.Security.NonceVerification -- This fixture uses the real Reprint HMAC verifier.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Record the exact multipart parameters and uploaded file received over HTTP.

// Model a strict query firewall in front of the real exporter.
if (array_diff(array_keys($_GET), ['reprint-api', 'site-export-api'])) {
    http_response_code(403);
    return;
}

require_once __DIR__ . '/../../../packages/reprint-server/src/class-http-server.php';
require_once __DIR__ . '/../../../packages/reprint-server/src/class-hmac-server.php';

$reprint_authentication = new \WordPress\Reprint\Server\HMACServer('multipart-test-secret');
$reprint_authentication_error = $reprint_authentication->verify(getallheaders(), '', $_FILES);
if ($reprint_authentication_error !== null) {
    http_response_code(403);
    return;
}

file_put_contents('parameters.json', json_encode($_POST));
file_put_contents('uploaded-file-list.json', file_get_contents($_FILES['file_list']['tmp_name']));
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
