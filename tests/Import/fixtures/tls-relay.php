<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Report socket errors in this CLI-only fixture.

// Terminate TLS in front of php -S without changing the real Reprint router.
$reprint_context = stream_context_create(['ssl' => [
    'local_cert' => $argv[1],
    'local_pk' => $argv[2],
]]);
$reprint_listener = stream_socket_server('tcp://127.0.0.1:0', $reprint_error_number, $reprint_error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $reprint_context);
if ($reprint_listener === false) {
    throw new RuntimeException($reprint_error);
}
fwrite(STDOUT, stream_socket_get_name($reprint_listener, false) . "\n");
while (true) {
    $reprint_connection = stream_socket_accept($reprint_listener, 30);
    if ($reprint_connection === false) {
        break;
    }
    stream_set_timeout($reprint_connection, 5);
    if (!@stream_socket_enable_crypto($reprint_connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($reprint_connection);
        continue;
    }
    $reprint_upstream = stream_socket_client('tcp://' . $argv[3], $reprint_error_number, $reprint_error, 5);
    if ($reprint_upstream === false) {
        throw new RuntimeException($reprint_error);
    }
    stream_set_timeout($reprint_upstream, 5);
    while (!feof($reprint_connection) && !feof($reprint_upstream)) {
        $reprint_read = [$reprint_connection, $reprint_upstream];
        $reprint_write = null;
        $reprint_except = null;
        if (!stream_select($reprint_read, $reprint_write, $reprint_except, 5)) {
            break;
        }
        foreach ($reprint_read as $reprint_source) {
            stream_set_blocking($reprint_source, false);
            $reprint_bytes = fread($reprint_source, 8192);
            stream_set_blocking($reprint_source, true);
            $reprint_destination = $reprint_source === $reprint_connection ? $reprint_upstream : $reprint_connection;
            while ($reprint_bytes !== false && $reprint_bytes !== '') {
                $reprint_written = fwrite($reprint_destination, $reprint_bytes);
                if ($reprint_written === false || $reprint_written === 0) {
                    break 3;
                }
                $reprint_bytes = substr($reprint_bytes, $reprint_written);
            }
        }
    }
    fclose($reprint_connection);
    fclose($reprint_upstream);
}
