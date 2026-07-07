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
 *   - $send_exchange_request: fn(?resource $result_stream, int $result_http_code):
 *     string $response_json — the outbound POST to the remote's exchange
 *     endpoint; the stream is the raw result body (an in-process call in tests).
 *   - $run_export_request: fn(array $request): array{ http_code: int, stream: resource }
 *     — run the source's export engine for one request and return its raw
 *     response bytes as a stream (in production a loopback request to the
 *     source's own export.php, spooled to disk — a file_fetch result can be
 *     many megabytes and must never be returned as a string).
 *
 * On any exchange failure — transport garbage, or an error the remote reports —
 * the source throws; it never re-sends a result. The remote's persisted cursor
 * is the recovery mechanism: a rerun starts with no result, the remote re-asks
 * for the request it is missing, and the source re-runs it. Re-sending could
 * hand a stale result to a newer request, since results carry no ids to match
 * against — so the exchange callable must not retry either (no curl --retry).
 */
final class ReverseTransportSource
{
    /** @var callable fn(?resource $result_stream, int $result_http_code): string $response_json */
    private $send_exchange_request;

    /** @var callable fn(array $request): array{ http_code: int, stream: resource } */
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
     *     the source forever.
     * @throws RuntimeException When the remote reports an importer error, the
     *     exchange response is malformed, or the bound is exceeded.
     */
    public function run( int $max_exchanges = 100000 ): void
    {
        $result = null;
        for ( $i = 0; $i < $max_exchanges; $i++ ) {
            $response_json = $result === null
                ? (string) call_user_func( $this->send_exchange_request, null, 200 )
                : (string) call_user_func(
                    $this->send_exchange_request,
                    $result["stream"],
                    $result["http_code"]
                );
            if ( $result !== null ) {
                fclose( $result["stream"] );
                $result = null;
            }

            $response = json_decode( $response_json, true );
            $status   = is_array( $response ) ? ( $response["status"] ?? null ) : null;

            if ( $status === "done" ) {
                return;
            }
            if ( $status === "error" ) {
                throw new RuntimeException(
                    "reverse transport source: remote importer error: " .
                        (string) ( $response["message"] ?? "(no message)" )
                );
            }
            if ( $status !== "command" || ! is_array( $response["command"] ?? null ) ) {
                throw new RuntimeException(
                    "reverse transport source: malformed exchange response: " . substr( $response_json, 0, 500 )
                );
            }

            $result = $this->execute_export_command( $response["command"] );
        }
        throw new RuntimeException( "reverse transport source: exceeded max exchanges" );
    }

    /**
     * Runs one export command against the source's own export engine and
     * returns its { http_code, stream } result to deliver on the next
     * exchange. The response is left exactly as the engine produced it
     * (typically gzip) — the remote inflates incrementally while parsing, the
     * way curl would — so nothing here holds or decodes the body in memory.
     */
    private function execute_export_command( array $command ): array
    {
        $temp_files = array();
        $request    = $this->build_export_request( $command, $temp_files );

        try {
            $result = call_user_func( $this->run_export_request, $request );
        } finally {
            foreach ( $temp_files as $file ) {
                @unlink( $file );
            }
        }

        return array(
            "http_code" => (int) ( $result["http_code"] ?? 0 ),
            "stream"    => $result["stream"],
        );
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
