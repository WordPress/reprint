<?php

/**
 * The importer's transport for a reverse (remote-driven) pull.
 *
 * The reverse transport is strictly sequential: the importer issues one export
 * request, the local worker runs it and brings the answer back on its next
 * outbound call. This object holds that one answer and serves the importer's
 * two fetch shapes from it: fetch_json() returns it in the buffered-JSON shape,
 * fetch_streaming() feeds it through the same multipart chunk handler the curl
 * path uses. The request after the answered one has nothing to serve, so it
 * throws TransportYield — unwinding the importer back to the exchange endpoint
 * with the command the worker must run next.
 *
 * There is no request/response matching: because the importer resumes
 * deterministically from its persisted cursor, the request it re-issues on
 * re-entry IS the one this answer belongs to. The single consumed flag is the
 * only bookkeeping — and it is why this is a shared object rather than importer
 * state: the exchange re-creates the importer client per re-entry, but the same
 * transport is kept across those passes so a result is consumed exactly once.
 */
final class ReverseTransport
{
    /** @var array|null The delivered answer { http_code, body }, or null on the first exchange. */
    private $result;

    /** @var bool One answer per exchange; every request after it must yield. */
    private $consumed = false;

    public function __construct( ?array $result )
    {
        $this->result = $result;
    }

    /**
     * Returns the delivered answer in ImportClient::fetch_json()'s return
     * shape — the buffered-JSON half of the importer's transport seam. Named
     * after its ImportClient counterpart on purpose: the guard there delegates
     * 1:1 to this method.
     */
    public function fetch_json( string $url ): array
    {
        $result    = $this->serve_delivered_result( array( "method" => "GET", "url" => $url ) );
        $http_code = isset( $result["http_code"] ) ? (int) $result["http_code"] : 0;
        $body      = isset( $result["body"] ) ? (string) $result["body"] : "";
        $json      = $body === "" ? null : json_decode( $body, true );

        return array(
            "ok"        => $http_code === 200 && $json !== null,
            "http_code" => $http_code,
            "elapsed"   => 0.0,
            "body"      => $body,
            "json"      => $json,
            "error"     => $http_code === 200
                ? null
                : "reverse-transport request failed with HTTP {$http_code}",
        );
    }

    /**
     * Feeds the delivered answer through the caller's multipart chunk handler —
     * the streaming half of the importer's transport seam, named after its
     * ImportClient::fetch_streaming() counterpart. The whole response body was
     * carried back by the worker; everything above the transport is oblivious
     * to the reversal.
     */
    public function fetch_streaming( string $url, ?array $post_data, callable $chunk_handler ): void
    {
        $result    = $this->serve_delivered_result( $this->build_export_command( $url, $post_data ) );
        $http_code = isset( $result["http_code"] ) ? (int) $result["http_code"] : 0;
        if ( $http_code !== 200 ) {
            throw new RuntimeException( "reverse-transport export request returned a non-200 status" );
        }

        $body     = isset( $result["body"] ) ? (string) $result["body"] : "";
        $boundary = $this->extract_multipart_boundary( $body );
        if ( $boundary === null ) {
            return;
        }

        $parser = new MultipartStreamParser( $boundary, $chunk_handler );
        $parser->feed( $body );
    }

    /**
     * Returns the worker-delivered result for the importer's current request,
     * or hands the request to the worker by unwinding.
     *
     * @throws TransportYield When no result is left to serve; it carries
     *     $command out to the exchange endpoint, which returns it to the
     *     worker as the next command to run.
     */
    private function serve_delivered_result( array $command ): array
    {
        if ( $this->result !== null && ! $this->consumed ) {
            $this->consumed = true;
            return $this->result;
        }
        throw new TransportYield( $command );
    }

    /**
     * Builds the wire command for one export request. A command is the
     * request's identity: its URL (endpoint, cursor, params) plus any POST
     * body. A CURLFile upload (the file_fetch batch list) is inlined base64 so
     * the outbound worker can reconstruct it against its own export engine.
     */
    private function build_export_command( string $url, ?array $post_data ): array
    {
        $command = array(
            "method" => $post_data === null ? "GET" : "POST",
            "url"    => $url,
        );
        if ( $post_data !== null ) {
            $body = array();
            foreach ( $post_data as $key => $value ) {
                if ( $value instanceof CURLFile ) {
                    $body[ $key ] = array(
                        "filename"    => basename( $value->getFilename() ),
                        "content_b64" => base64_encode(
                            (string) file_get_contents( $value->getFilename() )
                        ),
                    );
                } else {
                    $body[ $key ] = $value;
                }
            }
            $command["body"] = $body;
        }
        return $command;
    }

    /**
     * Recovers the multipart boundary from the response body. The exporter
     * announces it in a Content-Type header, which the worker's in-process
     * export run cannot capture — but the body opens with the "--<boundary>"
     * delimiter line, so read it back from there (the same fallback the curl
     * path uses when the header is stripped).
     */
    private function extract_multipart_boundary( string $body ): ?string
    {
        if ( strncmp( $body, "--", 2 ) !== 0 ) {
            return null;
        }
        $line_end = strpos( $body, "\n" );
        $first    = $line_end === false ? $body : substr( $body, 0, $line_end );
        $boundary = rtrim( substr( $first, 2 ), "\r\n" );
        return $boundary === "" ? null : $boundary;
    }
}
