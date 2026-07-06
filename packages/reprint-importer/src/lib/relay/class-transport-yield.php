<?php

/**
 * Thrown by RelayTransport when the importer asks for an export request whose
 * result is not in hand yet. It unwinds the importer cleanly back to the
 * relay_exchange handler, which returns the carried command to the local
 * worker. Re-entry resumes the importer from its persisted cursor and
 * re-issues the same request, now answered — so this is how a synchronous,
 * blocking importer loop is driven one request per outbound exchange without
 * a coroutine and without a second remote process.
 */
final class TransportYield extends Exception
{
    /** @var string Stable id of the pending command (fingerprint of its shape). */
    public string $command_id;

    /** @var array The export request the worker must execute next. */
    public array $command;

    public function __construct( string $command_id, array $command )
    {
        parent::__construct( "relay transport yielded command {$command_id}" );
        $this->command_id = $command_id;
        $this->command    = $command;
    }
}
