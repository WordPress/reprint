<?php

// Model preflight without installing WordPress. File transfers below still
// use the real endpoint and its multipart protocol.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- The fixture selects the endpoint requested by the real CLI.
if (!is_file('response.json') && ( $_GET['endpoint'] ?? '' ) === 'preflight') {
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
// Supply source progress over the same HTTP/multipart wire as a file index.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Select the requested fixture endpoint.
if (is_file('source-progress.json') && ( $_GET['endpoint'] ?? '' ) === 'file_index') {
    $reprint_source_progress = json_decode(file_get_contents('source-progress.json'), true);
    header('Content-Type: multipart/mixed; boundary=source-progress');
    // Ordinary JSONL records are throttled for one second after the client starts.
    usleep(1100000);
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Send the fixture's multipart response, not HTML.
    foreach (['progress' => $reprint_source_progress['record']] + ( $reprint_source_progress['fails'] ? [
        'error' => ['error_type' => 'exception', 'message' => 'Source scan stopped by fixture.'],
    ] : [] ) as $reprint_chunk_type => $reprint_record) {
        $reprint_body = json_encode($reprint_record);
        echo "--source-progress\r\nContent-Type: application/json\r\nContent-Length: " . strlen($reprint_body)
            . "\r\nX-Chunk-Type: {$reprint_chunk_type}\r\n\r\n{$reprint_body}\r\n";
    }
    echo "--source-progress\r\nContent-Length: 0\r\nX-Chunk-Type: completion\r\nX-Status: complete\r\nX-Total-Entries: 0\r\n\r\n\r\n--source-progress--\r\n";
    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    return;
}

require __DIR__ . '/preflight-errors-router.php';
