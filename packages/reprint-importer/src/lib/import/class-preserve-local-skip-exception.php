<?php

namespace Reprint\Importer;

use RuntimeException;

/**
 * Thrown by create_directory_if_missing() in preserve-local mode when a directory
 * component is not writable or a symlink blocks directory creation.
 * Callers catch this to skip the current file/directory/symlink gracefully.
 */
class PreserveLocalSkipException extends RuntimeException {}
