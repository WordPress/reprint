<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Pull remote table metadata.
 */
class DatabaseIndexCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'db-index';
	}

	public function get_short_description(): string {
		return 'Pull table metadata from the remote database';
	}

	public function get_long_description(): string {
		return "Fetches table metadata (name, estimated rows, data size) from\n"
			. "the remote server and writes it to --state-dir/db-tables.jsonl.\n"
			. "Useful for planning before a full db-pull.\n";
	}

	public function get_extra_help(): ?string {
		return "Output files:\n"
			. "  db-tables.jsonl  One JSON object per table\n";
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
			$this->secret_option(),
			$this->abort_option(),
			$this->verbose_option(),
		];
	}
}
