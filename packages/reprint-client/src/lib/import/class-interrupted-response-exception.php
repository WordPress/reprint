<?php

namespace Reprint\Importer;

use RuntimeException;

/**
 * Thrown when a streaming response loses its framing or ends before a valid
 * completion part.
 */
class InterruptedResponseException extends RuntimeException {}
