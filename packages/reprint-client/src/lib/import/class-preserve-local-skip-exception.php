<?php

namespace Reprint\Importer;

use RuntimeException;

/**
 * A preserve-local rule prevented a local filesystem mutation.
 */
class PreserveLocalSkipException extends RuntimeException {}
