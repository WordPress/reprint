<?php

/**
 * The remote's single reverse-transport endpoint: JSON in, JSON out.
 *
 * There is no separate importer process and no held socket. Each call runs the
 * real importer inline. The importer resumes from its persisted cursor, consumes
 * the delivered result, advances, and issues its next request — which throws
 * TransportYield and unwinds back here, so the carried command is returned to
 * the worker; or it completes, and "done" is returned. The local worker's
 * outbound request is the only trigger, so nothing runs on the remote between
 * exchanges.
 *
 * One exchange carries one result, so the importer is re-entered (a fresh client
 * each pass, like an exit-2 resume) until it yields or completes. The SAME
 * ReverseTransport is shared across those passes so the result is consumed exactly
 * once even though the client is recreated.
 *
 * Wire request:  { "result": { "http_code": int, "body_b64": string } | null }
 * Wire response: { "status": "done" } | { "status": "command", "command": {...} }
 * The result body is raw (gunzipped) multipart bytes, so it rides base64.
 */
final class RelayExchange
{
    /** @var callable fn(): object A fresh, relay-capable importer client. */
    private $client_factory;

    /** @var array run() options selecting the command, e.g. ['command'=>'files-pull']. */
    private $run_options;

    public function __construct( callable $client_factory, array $run_options )
    {
        $this->client_factory = $client_factory;
        $this->run_options    = $run_options;
    }

    public function handle_json( string $request_json ): string
    {
        $request = json_decode( $request_json, true );
        $result  = null;
        if ( is_array( $request ) && isset( $request["result"] ) && is_array( $request["result"] ) ) {
            $result = array(
                "http_code" => (int) ( $request["result"]["http_code"] ?? 0 ),
                "body"      => (string) base64_decode( (string) ( $request["result"]["body_b64"] ?? "" ) ),
            );
        }

        $transport = new ReverseTransport( $result );
        try {
            do {
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
        }
    }
}
