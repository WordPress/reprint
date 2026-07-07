<?php

/**
 * The local (source) side of a reverse pull: outbound-only.
 *
 * The source never listens. This loop makes one outbound call at a time to the
 * remote's exchange endpoint, each call delivering the previous command's result
 * and receiving the next command, which it runs against its OWN export engine.
 * It is stateless — kill it and rerun; export commands are read-only and
 * cursor-driven, so re-running is safe. A hostile remote can only ask for what
 * the source already exposes over export.php.
 *
 * Two seams are injected:
 *   - $send_exchange_request: fn(string $request_json): string $response_json —
 *     the outbound HTTP POST to the remote's exchange endpoint (an in-process
 *     call in tests).
 *   - $run_export_request: fn(array $request): string — run the source's export
 *     engine for one request and return its raw response bytes (a loopback
 *     request to the source's own export.php in production).
 *
 * On any exchange failure — transport garbage, or an error the remote reports —
 * the worker throws; it never re-sends a result. The remote's persisted cursor
 * is the recovery mechanism: a rerun starts with no result, the remote re-asks
 * for the request it is missing, and the worker re-runs it. Re-sending could
 * hand a stale result to a newer request, since results carry no ids to match
 * against — so the exchange callable must not retry either (no curl --retry).
 */
final class ReverseTransportWorker
{
    /** @var callable fn(string $request_json): string $response_json */
    private $send_exchange_request;

    /** @var callable fn(array $request): string $raw_response_bytes */
    private $run_export_request;

    public function __construct( callable $send_exchange_request, callable $run_export_request )
    {
        $this->send_exchange_request = $send_exchange_request;
        $this->run_export_request    = $run_export_request;
    }

    /**
     * Drives the transfer to completion, one outbound exchange per export
     * command.
     *
     * @param int $max_exchanges Safety bound so a runaway remote cannot loop
     *     the worker forever.
     * @throws RuntimeException When the remote reports an importer error, the
     *     exchange response is malformed, or the bound is exceeded.
     */
    public function run( int $max_exchanges = 100000 ): void
    {
        $result = null;
        for ( $i = 0; $i < $max_exchanges; $i++ ) {
            $request = array();
            if ( $result !== null ) {
                $request["result"] = array(
                    "http_code" => $result["http_code"],
                    "body_b64"  => base64_encode( $result["body"] ),
                );
            }

            $response_json = (string) call_user_func( $this->send_exchange_request, (string) json_encode( $request ) );
            $response      = json_decode( $response_json, true );
            $status        = is_array( $response ) ? ( $response["status"] ?? null ) : null;

            if ( $status === "done" ) {
                return;
            }
            if ( $status === "error" ) {
                throw new RuntimeException(
                    "reverse transport worker: remote importer error: " .
                        (string) ( $response["message"] ?? "(no message)" )
                );
            }
            if ( $status !== "command" || ! is_array( $response["command"] ?? null ) ) {
                throw new RuntimeException(
                    "reverse transport worker: malformed exchange response: " . substr( $response_json, 0, 500 )
                );
            }

            $result = $this->execute_export_command( $response["command"] );
        }
        throw new RuntimeException( "reverse transport worker: exceeded max exchanges" );
    }

    /**
     * Runs one export command against the source's own export engine and
     * returns the { http_code, body } result to deliver on the next exchange.
     */
    private function execute_export_command( array $command ): array
    {
        $temp_files = array();
        $request    = $this->build_export_request( $command, $temp_files );

        try {
            $raw = (string) call_user_func( $this->run_export_request, $request );
        } finally {
            foreach ( $temp_files as $file ) {
                @unlink( $file );
            }
        }

        // curl would gunzip transparently; the export stream is gzip-encoded.
        if ( strncmp( $raw, "\x1f\x8b", 2 ) === 0 ) {
            $decoded = @gzdecode( $raw );
            if ( $decoded !== false ) {
                $raw = $decoded;
            }
        }

        return array( "http_code" => 200, "body" => $raw );
    }

    /**
     * Converts a wire command into export.php's synthetic request array. A
     * file_fetch batch list arrives inlined; the exporter reads it from a path
     * (config file_list_path), so materialize it and point there.
     *
     * @param array $temp_files Filled with paths the caller must clean up.
     */
    private function build_export_request( array $command, array &$temp_files ): array
    {
        $url   = (string) ( $command["url"] ?? "" );
        $query = (string) ( parse_url( $url, PHP_URL_QUERY ) ?? "" );

        $get = array();
        parse_str( $query, $get );

        $file_list = $command["body"]["file_list"] ?? null;
        if ( is_array( $file_list ) && isset( $file_list["content_b64"] ) ) {
            $tmp = tempnam( sys_get_temp_dir(), "reverse-transport-file-list-" );
            file_put_contents( $tmp, (string) base64_decode( (string) $file_list["content_b64"] ) );
            $get["file_list_path"] = $tmp;
            $temp_files[]          = $tmp;
        }

        return array(
            "get"    => $get,
            "post"   => array(),
            "body"   => "",
            "server" => array( "REQUEST_METHOD" => (string) ( $command["method"] ?? "GET" ) ),
        );
    }
}
