<?php

namespace Reprint\Importer\Pull;

use RuntimeException;

/**
 * Signals that Pull already emitted the stage-specific error/status output.
 */
class PullFailureReportedException extends RuntimeException {

}
