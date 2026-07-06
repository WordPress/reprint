<?php
/**
 * Loader for the reverse-transport relay classes.
 *
 * These implement "push as a remote-driven pull": the remote runs the importer
 * and owns all state; the local source only makes outbound requests. See
 * markdown/REVERSE-TRANSPORT.md.
 *
 * Classes are loaded leaf-first.
 */

require_once __DIR__ . '/class-transport-yield.php';
require_once __DIR__ . '/class-relay-transport.php';
require_once __DIR__ . '/class-relay-exchange.php';
require_once __DIR__ . '/class-relay-source-worker.php';
require_once __DIR__ . '/class-relay-export-source.php';
require_once __DIR__ . '/class-relay-import-driver.php';
