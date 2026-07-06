<?php

/**
 * The local source's execution of one relayed export command.
 *
 * In a reverse pull the remote never dials the source; instead it hands the
 * source an ordinary export request (as a relay command) and the source runs
 * its OWN export engine against its OWN roots and returns the bytes. This is
 * the read-only export API pointed backwards: a hostile remote can only ask for
 * what the source already exposes over export.php, never more.
 *
 * This class owns the reusable half — turning a relay command back into the
 * synthetic request array export.php expects, and gunzipping the streamed
 * response the way curl's Accept-Encoding would on the wire. HOW the export is
 * actually run is injected: in production the relay-source worker makes a
 * loopback request to the source's own export.php (which streams to its
 * response); a test drives the same request through a short-lived subprocess.
 * Either way the runner is handed the request and returns the raw response
 * bytes.
 */
final class RelayExportSource
{
    /** @var callable fn(array $request): string — run the source export, return raw response bytes. */
    private $run;

    /**
     * @param callable $run Given a request array {get, post, body, server},
     *   runs the source's export engine and returns its raw (possibly gzip)
     *   response body.
     */
    public function __construct( callable $run )
    {
        $this->run = $run;
    }

    /**
     * @param array $command { method, url, body? }
     * @return array { http_code, body }
     */
    public function execute( array $command ): array
    {
        $temp_files = array();
        $request    = $this->build_request( $command, $temp_files );

        try {
            $raw = (string) call_user_func( $this->run, $request );
        } finally {
            foreach ( $temp_files as $file ) {
                @unlink( $file );
            }
        }

        // The export stream is gzip-encoded; curl would gunzip transparently.
        if ( strncmp( $raw, "\x1f\x8b", 2 ) === 0 ) {
            $decoded = @gzdecode( $raw );
            if ( $decoded !== false ) {
                $raw = $decoded;
            }
        }

        return array( "http_code" => 200, "body" => $raw );
    }

    /**
     * Turn a relay command into export.php's synthetic request array.
     *
     * @param array $command
     * @param array $temp_files Filled with paths the caller must clean up.
     * @return array { get, post, body, server }
     */
    private function build_request( array $command, array &$temp_files ): array
    {
        $method = (string) ( $command["method"] ?? "GET" );
        $url    = (string) ( $command["url"] ?? "" );
        $query  = (string) ( parse_url( $url, PHP_URL_QUERY ) ?? "" );

        $get = array();
        parse_str( $query, $get );

        // A file_fetch batch list arrives inlined; the exporter reads it from a
        // path (config file_list_path), so materialize it and point there — no
        // $_FILES upload machinery needed.
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
            "server" => array( "REQUEST_METHOD" => $method ),
        );
    }
}
