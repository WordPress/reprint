<?php

namespace Reprint\Importer;

/**
 * Thrown after temporary requests repeatedly fail without durable cursor
 * progress. The CLI maps this terminal result to exit code 3.
 */
class RetryLaterException extends TransientInterruptionException {}
