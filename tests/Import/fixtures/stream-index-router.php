<?php

// A disconnected response stops inside a part; retries start at the last
// complete part's cursor, as they do against the real exporter below.
if (is_file('response.json')) {
    $reprint_index_response = json_decode(file_get_contents('response.json'), true);
    header('Content-Type: multipart/mixed; boundary=boundary-index');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Test the cursor sent by the actual importer.
    $reprint_index_resuming = !empty($_POST['cursor']);
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Multipart protocol bytes, not HTML.
    echo $reprint_index_resuming
        ? ( $reprint_index_response['body_on_resume'] ?? $reprint_index_response['body'] )
        : $reprint_index_response['body'];
    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    return;
}

require __DIR__ . '/preflight-errors-router.php';
