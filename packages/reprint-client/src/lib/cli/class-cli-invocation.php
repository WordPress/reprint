<?php

namespace Reprint\Importer\Cli;

/**
 * Validated input supplied to the business-operation runner.
 */
class CliInvocation {

	/** @var string */
	public $command;

	/** @var string */
	public $remote_reprint_api_url;

	/** @var string */
	public $state_directory;

	/** @var string */
	public $filesystem_root;

	/** @var array<string,mixed> */
	public $options;

	/** @var array<int,string> */
	public $original_arguments;

	/**
	 * @param string $command Canonical command name.
	 * @param string $remote_reprint_api_url Remote Reprint API URL which selects the remote state directory.
	 * @param string $state_directory Directory containing pull state.
	 * @param string $filesystem_root Filesystem root read or changed by the command.
	 * @param array $options Validated command options.
	 * @param array<int,string> $original_arguments Original process arguments for the audit log.
	 */
	public function __construct(
		string $command,
		string $remote_reprint_api_url,
		string $state_directory,
		string $filesystem_root,
		array $options,
		array $original_arguments
	) {
		$this->command                = $command;
		$this->remote_reprint_api_url = $remote_reprint_api_url;
		$this->state_directory        = $state_directory;
		$this->filesystem_root        = $filesystem_root;
		$this->options                = $options;
		$this->original_arguments     = $original_arguments;
	}
}
