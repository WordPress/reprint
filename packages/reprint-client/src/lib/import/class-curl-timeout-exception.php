<?php

namespace Reprint\Importer;

/**
 * Thrown when a cURL request times out (CURLE_OPERATION_TIMEDOUT).
 * Callers save the last durable cursor and rethrow this exception so the
 * CLI exits 3. A later invocation resumes from that saved cursor.
 */
class CurlTimeoutException extends TransientInterruptionException {}
