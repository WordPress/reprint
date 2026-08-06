<?php

namespace Reprint\Importer;

/**
 * Thrown when an interrupted response can be requested again from its last
 * durable cursor.
 */
class TransientInterruptionException extends InterruptedResponseException {}
