<?php

namespace Reprint\Importer\Cli;

/**
 * Validated input supplied to the command runner.
 */
class CliInvocation {

	/** @var string */
	public $command;

	/** @var string */
	public $remote_reprint_api_url;

	/** @var string */
	public $state_dir;

	/** @var string */
	public $filesystem_root;

	/** @var array<string,mixed> */
	public $options;

	/** @var array<int,string> */
	public $original_arguments;

	/**
	 * @param string $command Canonical command name.
	 * @param string $remote_reprint_api_url Remote Reprint API URL which selects the remote state directory.
	 * @param string $state_dir Caller-selected state directory containing Reprint state for one filesystem root.
	 * @param string $filesystem_root Filesystem root read or changed by the command.
	 * @param array $options Validated command options.
	 * @param array<int,string> $original_arguments Original process arguments for the audit log.
	 */
	private function __construct(
		string $command,
		string $remote_reprint_api_url,
		string $state_dir,
		string $filesystem_root,
		array $options,
		array $original_arguments
	) {
		$this->command                = $command;
		$this->remote_reprint_api_url = $remote_reprint_api_url;
		$this->state_dir              = $state_dir;
		$this->filesystem_root        = $filesystem_root;
		$this->options                = $options;
		$this->original_arguments     = $original_arguments;
	}

	/**
	 * Validate parsed command input and create a command invocation.
	 *
	 * @param array<string,string> $positional_arguments Parsed positional arguments keyed by synopsis name.
	 * @param array<string,mixed> $options Parsed associative arguments keyed by invocation option name.
	 * @param array<int,string> $original_arguments Original process arguments.
	 */
	public static function from_parsed_input(
		CliCommand $command,
		array $positional_arguments,
		?string $state_dir,
		?string $filesystem_root,
		array $options,
		array $original_arguments
	): self {
		if ( $command->requires_state_directory() && ! $state_dir ) {
			throw new CliInputException(
				"Error: --state-dir=DIR is required\n"
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI input errors are plain-text terminal messages, not HTML output.
				. 'Usage: ' . $command->get_usage()
			);
		}

		$filesystem_root_requirement = $command->get_filesystem_root_requirement();
		$flat_document_root          = $options['flat_document_root'] ?? null;
		if ( $filesystem_root && $flat_document_root ) {
			throw new CliInputException(
				"Error: --fs-root and --flat-document-root are mutually exclusive.\n"
				. "Use --fs-root for the raw download directory, or --flat-document-root for a flattened layout."
			);
		}
		if (
			$filesystem_root_requirement === CliCommand::FILESYSTEM_ROOT_REQUIRED
			&& ! $filesystem_root
		) {
			throw new CliInputException(
				"Error: --fs-root=DIR is required\n"
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI input errors are plain-text terminal messages, not HTML output.
				. 'Usage: ' . $command->get_usage()
			);
		}
		if (
			$filesystem_root_requirement === CliCommand::FILESYSTEM_ROOT_OR_FLAT
			&& ! $filesystem_root
			&& ! $flat_document_root
		) {
			throw new CliInputException(
				"Error: --fs-root=DIR is required\n"
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI input errors are plain-text terminal messages, not HTML output.
				. 'Usage: ' . $command->get_usage()
			);
		}

		$state_dir = $state_dir ?? '';
		if ( $filesystem_root_requirement === CliCommand::FILESYSTEM_ROOT_UNUSED ) {
			// pull-metadata reads only state, but ImportClient still expects
			// a filesystem root path. Point it at state-dir rather than requiring an
			// otherwise-unused CLI option.
			$filesystem_root = $state_dir;
		} else {
			$filesystem_root = $filesystem_root ?: (string) $flat_document_root;
		}

		$options['command'] = $command->get_name();

		return new self(
			$command->get_name(),
			$positional_arguments['remote_reprint_api_url'],
			$state_dir,
			$filesystem_root,
			$options,
			$original_arguments
		);
	}
}
