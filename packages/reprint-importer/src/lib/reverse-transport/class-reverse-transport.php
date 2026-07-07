<?php

/**
 * The importer's transport for a reverse (remote-driven) pull.
 *
 * The reverse transport is strictly sequential: the importer issues one export
 * request, the local source runs it and brings the answer back on its next
 * outbound call. This object holds that one answer as a STREAM and serves the
 * importer's two fetch shapes from it: fetch_json() reads it whole (JSON
 * endpoint responses are small), fetch_streaming() reads it in bounded chunks —
 * inflating incrementally when the export engine gzipped it — so a
 * multi-megabyte file_fetch response is never held in memory. The request after
 * the answered one has nothing to serve, so it throws TransportYield —
 * unwinding the importer back to the exchange endpoint with the command the
 * source must run next.
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
    /** @var resource|null Raw (possibly gzip) bytes of the delivered answer, or null on the first exchange. */
    private $result_stream;

    /** @var int HTTP status the source reported for the delivered answer. */
    private $result_http_code;

    /** @var bool One answer per exchange; every request after it must yield. */
    private $consumed = false;

    /**
     * @param resource|null $result_stream
     */
    public function __construct( $result_stream, int $result_http_code = 200 )
    {
        $this->result_stream    = $result_stream;
        $this->result_http_code = $result_http_code;
    }

    /**
     * Returns the delivered answer in ImportClient::fetch_json()'s return
     * shape — the buffered-JSON half of the importer's transport seam. Named
     * after its ImportClient counterpart on purpose: the guard there delegates
     * 1:1 to this method. JSON endpoint responses are small, so reading the
     * whole stream here is fine.
     */
    public function fetch_json( string $url ): array
    {
        $stream = $this->serve_delivered_result(
            static fn() => array(
                "method" => "GET",
                "url"    => $url,
            )
        );
        $body = $this->read_small_body( $stream );
        if ( $this->result_http_code !== 200 ) {
            throw new ReverseTransportHttpError( $this->result_http_code, $body );
        }
        $json = $body === "" ? null : json_decode( $body, true );

        return array(
            "ok"        => $json !== null,
            "http_code" => $this->result_http_code,
            "elapsed"   => 0.0,
            "body"      => $body,
            "json"      => $json,
            "error"     => $json !== null ? null : "Invalid JSON in reverse-transport response",
        );
    }

    /**
     * Feeds the delivered answer through the caller's multipart chunk handler —
     * the streaming half of the importer's transport seam, named after its
     * ImportClient::fetch_streaming() counterpart. The body is read in bounded
     * chunks and inflated incrementally when gzipped, the way curl decodes a
     * Content-Encoding: gzip response: a file_fetch result can be many
     * megabytes and is never buffered whole.
     */
    public function fetch_streaming( string $url, ?array $post_data, callable $chunk_handler ): void
    {
        $stream = $this->serve_delivered_result(
            fn() => $this->build_export_command( $url, $post_data )
        );
        if ( $this->result_http_code !== 200 ) {
            throw new ReverseTransportHttpError(
                $this->result_http_code,
                $this->read_small_body( $stream )
            );
        }

        // Sniff the gzip magic to decide on incremental inflation. Read
        // exactly two bytes — a short first read on a socket-backed stream
        // must not misclassify a gzipped body as raw. Both bytes are part of
        // the payload, so they enter the loop as the first raw chunk.
        $head = "";
        $need = 2;
        while ( $need > 0 && ! feof( $stream ) ) {
            $bytes = fread( $stream, $need );
            if ( $bytes === false ) {
                throw new RuntimeException( "reverse transport: failed to read the result stream" );
            }
            if ( $bytes === "" ) {
                break;
            }
            $head .= $bytes;
            $need  -= strlen( $bytes );
        }
        $inflate = strncmp( $head, "\x1f\x8b", 2 ) === 0
            ? inflate_init( ZLIB_ENCODING_GZIP )
            : null;

        $parser  = null;
        $pending = "";
        $raw     = $head;

        while ( $raw !== "" ) {
            // @ because inflate_add also raises a PHP warning on bad data;
            // the false return below is the real, handled signal.
            $chunk = $inflate ? @inflate_add( $inflate, $raw ) : $raw;
            if ( $chunk === false ) {
                // curl fails this as CURLE_BAD_CONTENT_ENCODING; swallowing it
                // here would silently truncate the transfer instead.
                throw new RuntimeException( "reverse transport: corrupt gzip in the result stream" );
            }

            if ( $parser !== null ) {
                $parser->feed( $chunk );
            } elseif ( $chunk !== "" ) {
                // The multipart boundary is announced on the body's first
                // "--<boundary>" line (no Content-Type header survives the
                // exchange), so hold decoded bytes only until that first
                // newline, then hand everything to the parser.
                $pending .= $chunk;
                if ( strpos( $pending, "\n" ) !== false ) {
                    $boundary = $this->extract_multipart_boundary( $pending );
                    if ( $boundary === null ) {
                        break; // Not multipart — the post-loop check throws with a snippet.
                    }
                    $parser = new MultipartStreamParser( $boundary, $chunk_handler );
                    $parser->feed( $pending );
                    $pending = "";
                }
            }

            if ( feof( $stream ) ) {
                break;
            }
            $raw = fread( $stream, 65536 );
            if ( $raw === false ) {
                throw new RuntimeException( "reverse transport: failed to read the result stream" );
            }
        }

        if ( $parser === null ) {
            // Same failure the curl path throws for a 200 body that is not
            // multipart (import.php's fetch_streaming tail) — the message
            // shape matters, downstream recovery classifies on it.
            $snippet = substr( $pending, 0, 500 );
            throw new RuntimeException(
                "Invalid response: missing multipart boundary. " .
                    ( $snippet !== "" ? "Body: {$snippet}" : "" )
            );
        }
    }

    /**
     * Reads a small body (JSON endpoint responses, error pages) whole,
     * gunzipping when the export engine compressed it. Capped at 1 MiB so a
     * rogue error body cannot balloon memory — only file_fetch results are
     * legitimately large, and those stream through fetch_streaming().
     *
     * @param resource $stream
     */
    private function read_small_body( $stream ): string
    {
        $body = (string) stream_get_contents( $stream, 1048576 );
        if ( strncmp( $body, "\x1f\x8b", 2 ) === 0 ) {
            $decoded = @gzdecode( $body );
            if ( $decoded !== false ) {
                $body = $decoded;
            }
        }
        return $body;
    }

    /**
     * Returns the stream of the source-delivered result for the importer's
     * current request, or hands the request to the source by unwinding.
     *
     * @param callable $build_command Builds the wire command; called only on
     *     the yield path, so the consume pass never pays for it (for
     *     file_fetch, building re-reads and base64-encodes the batch list).
     * @throws TransportYield When no result is left to serve; it carries the
     *     built command out to the exchange endpoint, which returns it to the
     *     source as the next command to run.
     * @return resource
     */
    private function serve_delivered_result( callable $build_command )
    {
        if ( $this->result_stream !== null && ! $this->consumed ) {
            $this->consumed = true;
            return $this->result_stream;
        }
        throw new TransportYield( $build_command() );
    }

    /**
     * Builds the wire command for one export request. A command is the
     * request's identity: its URL (endpoint, cursor, params) plus any POST
     * body. A CURLFile upload (the file_fetch batch list) is inlined base64 so
     * the outbound source can reconstruct it against its own export engine —
     * the one JSON-embedded payload that can reach megabytes, bounded by the
     * batch-list size the direct path also holds in memory when signing.
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
     * announces it in a Content-Type header, which the source's export run
     * cannot capture — but the body opens with the "--<boundary>" delimiter
     * line, so read it back from there (the same fallback the curl path uses
     * when the header is stripped).
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
