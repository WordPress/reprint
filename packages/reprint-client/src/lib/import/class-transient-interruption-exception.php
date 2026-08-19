<?php

namespace Reprint\Importer;

/**
 * Thrown when a temporary transfer or HTTP response failure can be requested
 * again from its last durable cursor.
 */
class TransientInterruptionException extends InterruptedResponseException {}
