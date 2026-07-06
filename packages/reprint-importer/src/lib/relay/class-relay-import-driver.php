<?php

/**
 * Drives the real importer inline for one relay exchange.
 *
 * This is the body of the remote's relay_exchange endpoint: given the transport
 * primed with the delivered result, it runs an ordinary importer command
 * (files-pull, db-pull, ...) exactly as a resumed CLI invocation would. The
 * importer resumes from its persisted cursor, consumes the delivered result via
 * the relay transport, advances and persists its state, then either issues its
 * next request — which throws TransportYield and unwinds back out of here to the
 * exchange handler — or completes.
 *
 * Because one exchange carries one result, the loop re-enters the importer (a
 * fresh client each pass, mirroring a real exit-code-2 resume) until it either
 * yields or finishes. A pass that only did local work (e.g. the diff stage)
 * and stopped with exit code 2 simply re-enters and continues; a pass that
 * reaches an unanswered request yields.
 */
final class RelayImportDriver
{
    /** @var callable fn(): object A fresh, relay-capable importer client bound to the same state/fs dirs. */
    private $client_factory;

    /** @var array run() options selecting the command, e.g. ['command' => 'files-pull']. */
    private $run_options;

    public function __construct( callable $client_factory, array $run_options )
    {
        $this->client_factory = $client_factory;
        $this->run_options    = $run_options;
    }

    /**
     * @throws TransportYield when the importer needs the next export request.
     */
    public function __invoke( RelayTransport $transport ): void
    {
        do {
            $client = call_user_func( $this->client_factory );
            $client->set_relay_transport( $transport );
            $client->run( $this->run_options );
            $exit_code = $client->exit_code;
        } while ( $exit_code === 2 );
    }
}
