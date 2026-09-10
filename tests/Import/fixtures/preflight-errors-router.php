<?php

// Model gateway failures and preflight responses from other server versions
// over HTTP. Without a response fixture, exercise the real preflight endpoint.
if (is_file('response.json')) {
    $reprint_response = json_decode(file_get_contents('response.json'), true);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Select the fixture for the endpoint the real client requested.
    if ( ( $_GET['endpoint'] ?? '' ) === 'preflight' && isset($reprint_response['preflight_body'])) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is the fixture's JSON response.
        echo $reprint_response['preflight_body'];
        return;
    }
    http_response_code($reprint_response['http_code']);
    if ($reprint_response['http_code'] === 302) {
        header('Location: https://example.test/reprint-api');
    }
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Send the exact HTTP body the client must diagnose.
    echo $reprint_response['body'];
    return;
}

// Leave the real endpoint without database credentials, regardless of the
// developer's or CI worker's database settings.
putenv('DB_PASSWORD');
require_once __DIR__ . '/../../../packages/reprint-server/src/class-http-server.php';
\WordPress\Reprint\Server\HTTPServer::serve([
    'default_directory' => getcwd() . '/remote',
]);
