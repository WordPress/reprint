<?php

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Test-selected multipart settings.
// phpcs:disable WordPress.Security.NonceVerification -- Local test response, not a WordPress form.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw multipart bytes, not HTML.
header('Content-Type: multipart/mixed; boundary=buffered-body');
$reprint_body_type = $_GET['type'];
$reprint_body_bytes = (int) $_GET['bytes'];
$reprint_body_parts = (int) ( $_GET['parts'] ?? 1 );
$reprint_body_sized = (bool) ( $_GET['sized'] ?? true );
$reprint_body_deflater = null;
if (!empty($_GET['gzip'])) {
    header('Content-Encoding: gzip');
    $reprint_body_deflater = deflate_init(ZLIB_ENCODING_GZIP);
}
$reprint_body_write = static function (string $bytes) use ($reprint_body_deflater): void {
    echo $reprint_body_deflater === null ? $bytes : deflate_add($reprint_body_deflater, $bytes, ZLIB_NO_FLUSH);
    flush();
};
for ($reprint_body_part = 0; $reprint_body_part < $reprint_body_parts; ++$reprint_body_part) {
    $reprint_body_write("--buffered-body\r\nX-Chunk-Type: {$reprint_body_type}\r\n" .
        ( $reprint_body_sized ? "Content-Length: {$reprint_body_bytes}\r\n" : '' ) . "\r\n");
    for ($reprint_body_offset = 0; $reprint_body_offset < $reprint_body_bytes; $reprint_body_offset += 16384) {
        $reprint_body_write(str_repeat('x', min(16384, $reprint_body_bytes - $reprint_body_offset)));
    }
    $reprint_body_write("\r\n");
}
$reprint_body_write("--buffered-body\r\nX-Chunk-Type: completion\r\nContent-Length: 0\r\n\r\n\r\n--buffered-body--\r\n");
if ($reprint_body_deflater !== null) {
    echo deflate_add($reprint_body_deflater, '', ZLIB_FINISH);
}
