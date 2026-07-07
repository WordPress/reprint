<?php

/**
 * The local (source) side of a reverse pull: outbound-only.
 *
 * The source never listens. This loop makes one outbound call at a time to the
 * remote's relay_exchange, each call delivering the previous command's result
 * and receiving the next command, which it runs against its OWN export engine.
 * It is stateless — kill it and rerun; export commands are read-only and
 * cursor-driven, so re-running is safe. A hostile remote can only ask for what
 * the source already exposes over export.php.
 *
 * Two seams are injected:
 *   - $exchange: fn(string $request_json): string $response_json — the outbound
 *     HTTP POST to the remote (an in-process call in tests).
 *   - $run_export: fn(array $request): string — run the source's export engine
 *     for one request and return its raw response bytes (a loopback request to
 *     the source's own export.php in production).
 *
 * On any exchange failure — transport garbage, or an error the remote reports —
 * the worker throws; it never re-sends a result. The remote's persisted cursor
 * is the recovery mechanism: a rerun starts with no result, the remote re-asks
 * for the request it is missing, and the worker re-runs it. Re-sending could
 * hand a stale result to a newer request, since results carry no ids to match
 * against — so the exchange callable must not retry either (no curl --retry).
 */
final class RelaySource
{
    /** @var callable fn(string): string */
    private $exchange;

    /** @var callable fn(array): string */
    private $run_export;

    public function __construct( callable $exchange, callable $run_export )
    {
        $this->exchange   = $exchange;
        $this->run_export = $run_export;
    }

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

            $response_json = (string) call_user_func( $this->exchange, (string) json_encode( $request ) );
            $response      = json_decode( $response_json, true );
            $status        = is_array( $response ) ? ( $response["status"] ?? null ) : null;

            if ( $status === "done" ) {
                return;
            }
            if ( $status === "error" ) {
                throw new RuntimeException(
                    "relay source: remote importer error: " .
                        (string) ( $response["message"] ?? "(no message)" )
                );
            }
            if ( $status !== "command" || ! is_array( $response["command"] ?? null ) ) {
                throw new RuntimeException(
                    "relay source: malformed exchange response: " . substr( $response_json, 0, 500 )
                );
            }

            $result = $this->execute( $response["command"] );
        }
        throw new RuntimeException( "relay source: exceeded max exchanges" );
    }

    /**
     * Run one export command against the source and return { http_code, body }.
     */
    private function execute( array $command ): array
    {
        $temp_files = array();
        $request    = $this->build_request( $command, $temp_files );

        try {
            $raw = (string) call_user_func( $this->run_export, $request );
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
     * Turn a relay command into export.php's synthetic request array. A
     * file_fetch batch list arrives inlined; the exporter reads it from a path
     * (config file_list_path), so materialize it and point there.
     *
     * @param array $temp_files Filled with paths the caller must clean up.
     */
    private function build_request( array $command, array &$temp_files ): array
    {
        $url   = (string) ( $command["url"] ?? "" );
        $query = (string) ( parse_url( $url, PHP_URL_QUERY ) ?? "" );

        $get = array();
        parse_str( $query, $get );

        $file_list = $command["body"]["file_list"] ?? null;
        if ( is_array( $file_list ) && isset( $file_list["content_b64"] ) ) {
            $tmp = tempnam( sys_get_temp_dir(), "relay-file-list-" );
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
