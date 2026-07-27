<?php

namespace Reprint\Importer\Cli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions report command-line input, not HTML output.

/**
 * Applies cross-option rules and creates a business invocation.
 */
class CliInvocationValidator {

	/**
	 * @param array<int,string> $original_arguments Original process arguments.
	 */
	public function validate(
		CliCommand $command,
		CliParsedInput $parsed,
		array $original_arguments
	): CliInvocation {
		if ( $command->requires_state_directory() && ! $parsed->state_directory ) {
			throw new CliInputException(
				"Error: --state-dir=DIR is required\n"
				. 'Usage: ' . $command->get_usage()
			);
		}

		$filesystem_root_requirement = $command->get_filesystem_root_requirement();
		$flat_document_root          = $parsed->options['flat_document_root'] ?? null;
		if ( $parsed->filesystem_root && $flat_document_root ) {
			throw new CliInputException(
				"Error: --fs-root and --flat-document-root are mutually exclusive.\n"
				. "Use --fs-root for the raw download directory, or --flat-document-root for a flattened layout."
			);
		}
		if (
			$filesystem_root_requirement === CliCommand::FILESYSTEM_ROOT_REQUIRED
			&& ! $parsed->filesystem_root
		) {
			throw new CliInputException(
				"Error: --fs-root=DIR is required\n"
				. 'Usage: ' . $command->get_usage()
			);
		}
		if (
			$filesystem_root_requirement === CliCommand::FILESYSTEM_ROOT_OR_FLAT
			&& ! $parsed->filesystem_root
			&& ! $flat_document_root
		) {
			throw new CliInputException(
				"Error: --fs-root=DIR is required\n"
				. 'Usage: ' . $command->get_usage()
			);
		}

		$state_directory = $parsed->state_directory ?? '';
		if ( $filesystem_root_requirement === CliCommand::FILESYSTEM_ROOT_UNUSED ) {
			// pull-metadata reads only state, but ImportClient still expects
			// a filesystem root path. Point it at state-dir rather than requiring an
			// otherwise-unused CLI option.
			$filesystem_root = $state_directory;
		} else {
			$filesystem_root = $parsed->filesystem_root ?: (string) $flat_document_root;
		}

		$options            = $parsed->options;
		$options['command'] = $command->get_name();

		return new CliInvocation(
			$command->get_name(),
			$parsed->positional_arguments['remote_reprint_api_url'],
			$state_directory,
			$filesystem_root,
			$options,
			$original_arguments
		);
	}
}
