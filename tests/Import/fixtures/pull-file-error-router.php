<?php

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw multipart protocol bytes, not HTML.
// The source chooses the multipart parts and their order.
header('Content-Type: multipart/mixed; boundary=scope');
$reprint_scope_parts = json_decode(file_get_contents('parts.json'), true);
foreach ($reprint_scope_parts as $reprint_scope_part) {
    $reprint_scope_body = $reprint_scope_part['body'] ?? '';
    echo "--scope\r\nContent-Length: " . strlen($reprint_scope_body) . "\r\n";
    foreach ($reprint_scope_part['headers'] as $reprint_scope_name => $reprint_scope_value) {
        echo $reprint_scope_name . ': ' . $reprint_scope_value . "\r\n";
    }
    echo "\r\n" . $reprint_scope_body . "\r\n";
}
echo "--scope--\r\n";
