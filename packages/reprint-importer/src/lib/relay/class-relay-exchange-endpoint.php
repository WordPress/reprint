<?php

/**
 * JSON wire adapter for the remote's single reverse-transport endpoint.
 *
 * RelayExchange works in arrays; a real relay_exchange HTTP request/response is
 * JSON. The only value that is not already JSON-safe is the delivered result's
 * body — raw (gunzipped) multipart bytes — so it rides the wire base64-encoded.
 * Both the remote handler and the source worker go through this one class so
 * the contract lives in a single place.
 *
 * Wire request:  { "last_command_id": string|null, "result": { "http_code": int, "body_b64": string } | null }
 * Wire response: { "status": "done" } | { "status": "command", "command_id": string, "command": {...} }
 */
final class RelayExchangeEndpoint
{
    /** @var callable fn(Relay_Transport... $transport): void — the importer driver (RelayImportDriver). */
    private $driver;

    public function __construct( callable $driver )
    {
        $this->driver = $driver;
    }

    /**
     * The remote HTTP body handler: JSON request in, JSON response out.
     */
    public function handle_json( string $request_json ): string
    {
        $request  = self::decode_request( $request_json );
        $response = ( new RelayExchange( $this->driver ) )->handle( $request );
        return (string) json_encode( $response );
    }

    /**
     * Source side: turn the worker's array request into the wire JSON,
     * base64-encoding the binary result body.
     */
    public static function encode_request( array $request ): string
    {
        $wire = array( "last_command_id" => $request["last_command_id"] ?? null );
        if ( isset( $request["result"] ) && is_array( $request["result"] ) ) {
            $wire["result"] = array(
                "http_code" => (int) ( $request["result"]["http_code"] ?? 0 ),
                "body_b64"  => base64_encode( (string) ( $request["result"]["body"] ?? "" ) ),
            );
        }
        return (string) json_encode( $wire );
    }

    /**
     * Source side: parse the wire JSON response back into the worker's array.
     */
    public static function decode_response( string $response_json ): array
    {
        $decoded = json_decode( $response_json, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Remote side: parse the wire JSON request, base64-decoding the result body
     * back to raw bytes for RelayExchange.
     */
    private static function decode_request( string $request_json ): array
    {
        $decoded = json_decode( $request_json, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        $request = array( "last_command_id" => $decoded["last_command_id"] ?? null );
        if ( isset( $decoded["result"] ) && is_array( $decoded["result"] ) ) {
            $request["result"] = array(
                "http_code" => (int) ( $decoded["result"]["http_code"] ?? 0 ),
                "body"      => (string) base64_decode( (string) ( $decoded["result"]["body_b64"] ?? "" ) ),
            );
        }
        return $request;
    }
}
