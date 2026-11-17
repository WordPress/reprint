<?php

// Model preflight without installing WordPress. File transfers below still
// use the real endpoint and its multipart protocol.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- The fixture selects the endpoint requested by the real CLI.
if (!is_file('response.json') && ( $_GET['endpoint'] ?? '' ) === 'preflight') {
    require_once __DIR__ . '/../../../vendor/autoload.php';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON fixture, not HTML.
    echo json_encode([
        'ok' => true,
        'protocol_version' => 3,
        'path_format' => \WordPress\Reprint\Server\Utils::native_path_format(),
        'filesystem' => ['ok' => true],
        'database' => ['connected' => true],
        'wp_detect' => ['roots' => [['path' => getcwd() . '/remote']]],
    ]);
    return;
}
require __DIR__ . '/preflight-errors-router.php';
