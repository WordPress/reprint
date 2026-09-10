<?php

namespace Reprint\Importer;

/**
 * Thrown when a cURL request times out (CURLE_OPERATION_TIMEDOUT).
 * Callers save the last durable cursor and retry the request until the
 * no-progress limit is reached.
 */
class CurlTimeoutException extends TransientInterruptionException {}
