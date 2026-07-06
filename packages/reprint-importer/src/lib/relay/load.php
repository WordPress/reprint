<?php
/**
 * Loader for the reverse-transport relay classes.
 *
 * "Push as a remote-driven pull": the remote runs the importer and owns all
 * state; the local source only makes outbound requests.
 *
 * RelayTransport (with TransportYield) is the transport the importer talks to;
 * RelayExchange is the remote endpoint; RelaySource is the outbound-only local
 * side (standalone — it needs neither the importer nor the exchange).
 */

require_once __DIR__ . '/class-transport-yield.php';
require_once __DIR__ . '/class-relay-transport.php';
require_once __DIR__ . '/class-relay-exchange.php';
require_once __DIR__ . '/class-relay-source.php';
