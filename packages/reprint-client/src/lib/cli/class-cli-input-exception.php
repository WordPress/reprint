<?php

namespace Reprint\Importer\Cli;

use InvalidArgumentException;

/**
 * Reports invalid command-line input before any pull work starts.
 */
class CliInputException extends InvalidArgumentException {

}
