<?php

/**
 * The local (source) side of a reverse pull.
 *
 * The source site is outbound-only: it never listens. This worker loops making
 * one outbound request at a time to the remote's relay_exchange. Each request
 * delivers the result of the previous command and receives the next command,
 * which the worker executes against its *own* export engine and answers on the
 * following request. The worker is stateless — kill it and rerun; it
 * re-exchanges and re-executes idempotent, read-only export commands.
 *
 * Two seams are injected so the same worker serves the demo and production:
 *   - $exchange: fn(array $request): array — the outbound call to the remote.
 *     (In the demo an in-process RelayExchange::handle; in production an HMAC
 *     POST to the remote's relay_exchange endpoint.)
 *   - $source_execute: fn(array $command): array — run one export command
 *     against the local source and return its result. (In production this runs
 *     export.php against the source's own configured roots, so a hostile remote
 *     can only ask for what the source already exposes.)
 */
final class RelaySourceWorker
{
    /** @var callable fn(array $request): array */
    private $exchange;

    /** @var callable fn(array $command): array */
    private $source_execute;

    /** @var array<int,array> A transcript of the exchange, for inspection. */
    private array $transcript = array();

    public function __construct( callable $exchange, callable $source_execute )
    {
        $this->exchange       = $exchange;
        $this->source_execute = $source_execute;
    }

    /**
     * Drive the transfer to completion, one outbound exchange per command.
     *
     * @param int $max_exchanges Safety bound so a misbehaving remote cannot
     *   loop the worker forever.
     */
    public function run( int $max_exchanges = 100000 ): void
    {
        $last_command_id = null;
        $result          = null;

        for ( $i = 0; $i < $max_exchanges; $i++ ) {
            $request = array( "last_command_id" => $last_command_id );
            if ( $result !== null ) {
                $request["result"] = $result;
            }

            $response = call_user_func( $this->exchange, $request );

            if ( ( $response["status"] ?? null ) === "done" ) {
                return;
            }
            if ( ( $response["status"] ?? null ) !== "command" ) {
                throw new RuntimeException( "relay worker: unexpected exchange response" );
            }

            // Execute the export command against the local source, outbound
            // only. The result rides the next request's body.
            $result          = call_user_func( $this->source_execute, $response["command"] );
            $last_command_id = (string) $response["command_id"];

            $this->transcript[] = array(
                "exchange" => $i + 1,
                "command"  => $response["command"],
            );
        }

        throw new RuntimeException( "relay worker: exceeded max exchanges (runaway remote?)" );
    }

    /** @return array<int,array> */
    public function transcript(): array
    {
        return $this->transcript;
    }
}
