<?php

namespace Reprint\Importer\Filesystem;

/**
 * Mutable file state retained while one remote file arrives in chunks.
 */
class PulledFileContext {
	/** @var resource|null */
	public $file_handle = null;

	/** @var string|null */
	public $file_path = null;

	/** @var int|null */
	public $file_ctime = null;

	/** @var int */
	public $file_bytes_written = 0;

	/** @var bool */
	public $skip_current_file = false;
}
