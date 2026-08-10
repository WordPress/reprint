<?php

namespace Reprint\Importer;

/**
 * Thrown when a cURL request times out (CURLE_OPERATION_TIMEDOUT).
 * Callers catch this to save state and exit with "partial" status instead
 * of crashing with a fatal error — the next invocation resumes from the
 * last saved cursor.
 */
class CurlTimeoutException extends TransientInterruptionException {}
