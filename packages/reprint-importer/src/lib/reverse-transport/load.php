<?php
/**
 * Loader for the reverse-transport classes.
 *
 * "Push as a remote-driven pull": the remote runs the importer and owns all
 * state; the local source only makes outbound requests.
 *
 * ReverseTransport (with TransportYield) is the transport the importer talks
 * to; ReverseTransportEndpoint is the remote endpoint; ReverseTransportSource
 * is the outbound-only local side (standalone — it needs neither the importer
 * nor the exchange).
 */

require_once __DIR__ . '/class-transport-yield.php';
require_once __DIR__ . '/class-reverse-transport.php';
require_once __DIR__ . '/class-reverse-transport-endpoint.php';
require_once __DIR__ . '/class-reverse-transport-source.php';
