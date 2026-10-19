<?php

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw multipart protocol bytes, not HTML.
// phpcs:disable WordPress.Security.NonceVerification -- Local HTTP fixture consumes API fields, not WordPress forms.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Record the client's exact API fields in this local fixture.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/packages/reprint-server/src/export.php';

if (( $_GET['endpoint'] ?? '' ) !== 'file_fetch' || !is_file('responses.json')) {
    ( new WordPress\Reprint\Server\HTTPServer() )->handle_request();
    return;
}

// Only the source reply is replaced. The CLI still builds and uploads its own batch.
$reprint_response_paths = json_decode(file_get_contents($_FILES['file_list']['tmp_name']), true);
foreach ($reprint_response_paths as &$reprint_response_path) {
    $reprint_response_path['path'] = base64_decode($reprint_response_path['path']);
}
unset($reprint_response_path);
$reprint_response_number = is_file('requests.jsonl') ? count(file('requests.jsonl')) : 0;
file_put_contents('requests.jsonl', json_encode(['paths' => $reprint_response_paths, 'cursor' => $_POST['cursor'] ?? null]) . "\n", FILE_APPEND);
$reprint_response_replies = json_decode(file_get_contents('responses.json'), true);
$reprint_response_parts = $reprint_response_replies[$reprint_response_number] ?? [];
header('Content-Type: multipart/mixed; boundary=preserve');
foreach ($reprint_response_parts as $reprint_response_part) {
    $reprint_response_body = $reprint_response_part['body'] ?? '';
    echo "--preserve\r\nContent-Length: " . strlen($reprint_response_body) . "\r\n";
    foreach ($reprint_response_part['headers'] as $reprint_response_name => $reprint_response_value) {
        echo $reprint_response_name . ': ' . $reprint_response_value . "\r\n";
    }
    echo "\r\n" . $reprint_response_body . "\r\n";
}
echo "--preserve--\r\n";
