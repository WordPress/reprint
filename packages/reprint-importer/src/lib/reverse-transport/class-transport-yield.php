<?php

/**
 * Unwinds the importer back to the exchange handler, carrying the export request
 * the local source must run next. It extends Error, not Exception, so it slips
 * past the importer's `catch (Exception)` command wrapper, which would otherwise
 * log an error and persist an error status over a plain control-flow signal.
 */
final class TransportYield extends Error
{
    /** @var array The export request {method, url, body?} the source must run. */
    public array $command;

    public function __construct( array $command )
    {
        parent::__construct( "reverse transport yielded" );
        $this->command = $command;
    }
}
