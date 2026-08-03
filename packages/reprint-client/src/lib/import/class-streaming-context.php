<?php

namespace Reprint\Importer;

use Reprint\Importer\Filesystem\PulledFileContext;

/**
 * Context retained while one streaming response is processed.
 */
class StreamingContext extends PulledFileContext {
	/** @var callable|null */
	public $on_chunk = null;

	/** @var array<string,mixed>|null Last response statistics from the completion part. */
	public $response_stats = array();

	/** @var bool Whether the response contained its completion part. */
	public $saw_completion = false;
}
