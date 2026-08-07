<?php

namespace Reprint\Importer;

use RuntimeException;

/** Thrown when a local path type prevents the required directory from existing. */
class LocalPathConflictException extends RuntimeException {}
