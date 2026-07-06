<?php

/**
 * One outstanding export request's answer, for a reverse (remote-driven) pull.
 *
 * The reverse transport is strictly sequential: the importer issues one export
 * request, the local worker runs it and brings the answer back on its next
 * outbound call. This object carries that one answer. request() hands it to the
 * importer's next request and then throws TransportYield for the request after
 * it — which unwinds the importer back to the exchange handler so the worker can
 * be handed the next command.
 *
 * There is no request/response matching: because the importer resumes
 * deterministically from its persisted cursor, the request it re-issues on
 * re-entry IS the one this answer belongs to. The single consumed flag is the
 * only bookkeeping — and it is why this is a shared object rather than importer
 * state: the exchange re-creates the importer client per re-entry, but the same
 * transport is kept across those passes so a result is consumed exactly once.
 */
final class RelayTransport
{
    /** @var array|null The delivered answer, or null on the first exchange. */
    private $result;

    /** @var bool One answer per exchange; every request after it must yield. */
    private $consumed = false;

    public function __construct( ?array $result )
    {
        $this->result = $result;
    }

    /**
     * @throws TransportYield when there is no answer left to give.
     */
    public function request( array $command ): array
    {
        if ( $this->result !== null && ! $this->consumed ) {
            $this->consumed = true;
            return $this->result;
        }
        throw new TransportYield( $command );
    }
}
