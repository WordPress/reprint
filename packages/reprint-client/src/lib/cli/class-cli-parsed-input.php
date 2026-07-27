<?php

namespace Reprint\Importer\Cli;

/**
 * Parser output before command-level validation.
 */
class CliParsedInput {

	/** @var array<string,string> */
	public $positional_arguments;

	/** @var string|null */
	public $state_directory;

	/** @var string|null */
	public $filesystem_root;

	/** @var array<string,mixed> */
	public $options;

	/**
	 * @param array<string,string> $positional_arguments Parsed positional arguments.
	 * @param string|null $state_directory State directory from --state-dir.
	 * @param string|null $filesystem_root Filesystem root from --fs-root.
	 * @param array<string,mixed> $options Parsed associative arguments.
	 */
	public function __construct(
		array $positional_arguments,
		?string $state_directory,
		?string $filesystem_root,
		array $options
	) {
		$this->positional_arguments = $positional_arguments;
		$this->state_directory      = $state_directory;
		$this->filesystem_root      = $filesystem_root;
		$this->options              = $options;
	}
}
