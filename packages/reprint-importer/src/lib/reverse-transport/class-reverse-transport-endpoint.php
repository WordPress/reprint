<?php

/**
 * The remote's single reverse-transport endpoint.
 *
 * There is no separate importer process and no held socket. Each call runs the
 * real importer inline. The importer resumes from its persisted cursor, consumes
 * the delivered result, advances, and issues its next request — which throws
 * TransportYield and unwinds back here, so the carried command is returned to
 * the source; or it completes, and "done" is returned. The local source's
 * outbound request is the only trigger, so nothing runs on the remote between
 * exchanges.
 *
 * One exchange carries one result, so the importer is re-entered (a fresh client
 * each pass, like an exit-2 resume) until it yields or completes. The SAME
 * ReverseTransport is shared across those passes so the result is consumed exactly
 * once even though the client is recreated.
 *
 * Wire request:  the raw (possibly gzip) result bytes as the request body —
 *                empty on the source's first exchange — with the reported HTTP
 *                status carried beside it (a header in production, an argument
 *                in-process). The body is deliberately NOT JSON-embedded: a
 *                file_fetch result can be many megabytes, and base64-in-JSON
 *                would force both sides to buffer it whole. Commands are small;
 *                results are not.
 * Wire response: { "status": "done" }
 *              | { "status": "command", "command": {...} }
 *              | { "status": "error", "message": string }
 * An importer failure is returned as the error status — this endpoint never
 * throws, so the source can tell a remote failure from transport garbage.
 */
final class ReverseTransportEndpoint
{
    /**
     * Commands whose every export request sits behind a persisted checkpoint.
     * That is the reverse transport's core invariant — the request a fresh
     * client re-issues on re-entry must be the one the banked result belongs
     * to. Commands that fire several requests per invocation without
     * checkpointing between them (preflight's runtime-file fetches, and
     * therefore the composite pull) would consume results with the wrong
     * request; grow this list only after verifying the checkpointing.
     */
    private const SUPPORTED_COMMANDS = array( "files-pull" );

    /**
     * Re-entry bound per exchange. A healthy exchange takes a handful of
     * passes (consume, local stages, yield); a pass loop that neither
     * finishes nor asks for anything must fail loudly, not spin inside one
     * HTTP request.
     */
    private const MAX_PASSES = 100;

    /** @var callable fn(): object A fresh importer client with the reverse transport set. */
    private $client_factory;

    /** @var array run() options selecting the command, e.g. ['command'=>'files-pull']. */
    private $run_options;

    public function __construct( callable $client_factory, array $run_options )
    {
        if ( ! in_array( $run_options["command"] ?? "", self::SUPPORTED_COMMANDS, true ) ) {
            throw new InvalidArgumentException(
                "reverse transport: unsupported command; only commands that persist a " .
                    "checkpoint before every export request can ride the reverse " .
                    "transport (supported: files-pull)"
            );
        }
        $this->client_factory = $client_factory;
        $this->run_options    = $run_options;
    }

    /**
     * Handles one exchange: banks the source-delivered result of the previous
     * export command, advances the importer by one request, and returns the
     * next command — or "done" / "error" — as the wire-response JSON.
     *
     * @param resource|null $result_stream Raw (possibly gzip) bytes of the
     *     previous command's response, read as a stream so a multi-megabyte
     *     result is never buffered here. Null on the source's first exchange.
     * @param int $result_http_code HTTP status the source's export run reported.
     */
    public function handle_exchange( $result_stream, int $result_http_code = 200 ): string
    {
        $transport = new ReverseTransport( $result_stream, $result_http_code );
        try {
            $passes = 0;
            do {
                if ( ++$passes > self::MAX_PASSES ) {
                    throw new RuntimeException(
                        "reverse transport: importer made no outbound progress"
                    );
                }
                $client = call_user_func( $this->client_factory );
                $client->set_reverse_transport( $transport );
                $client->run( $this->run_options );
            } while ( $client->exit_code === 2 );

            return (string) json_encode( array( "status" => "done" ) );
        } catch ( TransportYield $yield ) {
            return (string) json_encode(
                array(
                    "status"  => "command",
                    "command" => $yield->command,
                )
            );
        } catch ( \Throwable $e ) {
            return (string) json_encode(
                array(
                    "status"  => "error",
                    "message" => $e->getMessage(),
                )
            );
        }
    }
}
