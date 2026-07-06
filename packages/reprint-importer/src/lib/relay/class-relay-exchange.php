<?php

/**
 * The remote's single reverse-transport endpoint.
 *
 * There is no separate importer process and no long-held socket. Each call is
 * one bounded request from the local worker that both delivers the previous
 * command's result and takes the next. The handler:
 *   1. builds a RelayTransport primed with the delivered result;
 *   2. runs the importer *inline* — the driver resumes from its own persisted
 *      cursor, consumes the delivered result (writes/stages, advances state),
 *      and issues its next request;
 *   3. that next request throws TransportYield, which is caught here and
 *      returned as the next command; or the driver returns, meaning done.
 *
 * The driver is injected, so the demo passes a small mirror driver while the
 * real files-pull/db-pull would be adapted to the same seam. All transfer
 * state (cursors, index, apply, final writes) lives with the driver on the
 * remote; the worker holds nothing.
 */
final class RelayExchange
{
    /** @var callable fn(RelayTransport $transport): void — one importer step. */
    private $driver;

    /**
     * @param callable $driver Runs the importer far enough to consume the
     *   delivered result and issue exactly one next request (which yields),
     *   or return when the transfer is complete.
     */
    public function __construct( callable $driver )
    {
        $this->driver = $driver;
    }

    /**
     * @param array $request { last_command_id?: string, result?: array }
     * @return array { status: "done" } | { status: "command", command_id, command }
     */
    public function handle( array $request ): array
    {
        $transport = new RelayTransport(
            isset( $request["last_command_id"] ) ? (string) $request["last_command_id"] : null,
            isset( $request["result"] ) && is_array( $request["result"] ) ? $request["result"] : null
        );

        try {
            call_user_func( $this->driver, $transport );
            return array( "status" => "done" );
        } catch ( TransportYield $yield ) {
            return array(
                "status"     => "command",
                "command_id" => $yield->command_id,
                "command"    => $yield->command,
            );
        }
    }
}
