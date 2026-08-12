<?php

namespace Reprint\Importer\Cli\Commands;

use Reprint\Importer\Cli\CliCommand;

/**
 * Print local pull metadata for host integrations.
 */
class PullMetadataCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'pull-metadata';
	}

	public function get_aliases(): array {
		return [ 'import-metadata' ];
	}

	public function get_short_description(): string {
		return 'Print local pull metadata for host integrations as JSON';
	}

	public function get_usage(): string {
		return 'reprint pull-metadata <remote-reprint-api-url> --state-dir=DIR';
	}

	public function get_long_description(): string {
		return "Reads <remote-state-directory>/pull/state.json and prints pull\n"
			. "lifecycle and source-site metadata for host integrations. The remote\n"
			. "Reprint API URL selects the state; no network calls are made.\n";
	}

	public function get_extra_help(): ?string {
		return "Example:\n"
			. "  reprint pull-metadata https://example.com --state-dir=./state | jq '.hasCompletedOnce'\n";
	}

	public function get_filesystem_root_requirement(): string {
		return CliCommand::FILESYSTEM_ROOT_UNUSED;
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
		];
	}
}
