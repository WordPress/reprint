<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Report counts and sizes from the next remote index and fetch lists.
 */
class FilesStatsCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'files-stats';
	}

	public function get_short_description(): string {
		return 'Show file counts and sizes from the next remote index';
	}

	public function get_long_description(): string {
		return "Reads the next remote index and fetch lists to report (no network calls):\n"
			. "\n"
			. "  - Total indexed files and their combined size\n"
			. "  - Files not yet downloaded and their combined size\n"
			. "\n"
			. "Output is JSON with 'indexed' and 'pending' sections.\n"
			. "Requires a prior files-index or files-pull run.\n";
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
		];
	}
}
