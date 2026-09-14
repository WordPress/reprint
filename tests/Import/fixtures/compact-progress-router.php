<?php

// Model preflight without installing WordPress. File transfers below still
// use the real endpoint and its multipart protocol.
// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- The fixture selects the endpoint requested by the real CLI.
if (!is_file('response.json') && ( $_POST['endpoint'] ?? '' ) === 'preflight') {
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON fixture, not HTML.
    echo json_encode([
        'ok' => true,
        'protocol_version' => 3,
        'filesystem' => ['ok' => true],
        'database' => ['connected' => true],
        'wp_detect' => ['roots' => [['path' => getcwd() . '/remote']]],
    ]);
    return;
}
require __DIR__ . '/preflight-errors-router.php';
