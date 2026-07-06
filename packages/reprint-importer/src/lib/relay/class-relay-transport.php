<?php

/**
 * The importer-side transport for a reverse (remote-driven) pull.
 *
 * A normal transport dials the source and returns the response. This one does
 * not dial anything: it is constructed for a single relay_exchange call,
 * primed with the result the local worker just delivered for the previous
 * command. When the importer issues that same request again (on re-entry from
 * its cursor), the result is handed back and the importer runs forward. When
 * the importer issues its *next* request — one there is no result for yet — the
 * transport throws TransportYield, unwinding to the exchange handler,
 * which returns that command to the worker. So each exchange advances the
 * importer by exactly one request, paced by the worker's outbound cadence.
 *
 * The command is the same export request envelope the direct transport would
 * build (endpoint, params, cursor); only who carries it changes.
 */
final class RelayTransport
{
    /** @var ?string Fingerprint of the command the delivered result answers. */
    private ?string $delivered_id;

    /** @var ?array The delivered result, or null on the first exchange. */
    private ?array $delivered_result;

    /** @var bool One result per exchange; the next request must yield. */
    private bool $consumed = false;

    public function __construct( ?string $delivered_id, ?array $delivered_result )
    {
        $this->delivered_id     = $delivered_id;
        $this->delivered_result = $delivered_result;
    }

    /**
     * A stable id for a command: two identical requests fingerprint alike, so
     * a re-issued request after re-entry matches the result delivered for it.
     */
    public static function command_id( array $command ): string
    {
        return md5( (string) json_encode( $command ) );
    }

    /**
     * Return the delivered result for this command, or yield it to the worker.
     *
     * @throws TransportYield when the result is not in hand.
     */
    public function request( array $command ): array
    {
        $id = self::command_id( $command );
        if ( ! $this->consumed && $this->delivered_id !== null && $id === $this->delivered_id ) {
            $this->consumed = true;
            return $this->delivered_result;
        }
        throw new TransportYield( $id, $command );
    }
}
