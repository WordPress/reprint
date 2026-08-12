<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Apply the pulled SQL to a local database.
 */
class DatabaseApplyCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'db-apply';
	}

	public function get_short_description(): string {
		return 'Apply the SQL dump to a local MySQL or SQLite database';
	}

	public function get_long_description(): string {
		return "Reads db.sql from --state-dir, optionally rewrites URLs, and executes\n"
			. "all statements against a target database. Resumable. Saves target\n"
			. "database credentials to state for use by apply-runtime.\n";
	}

	public function get_extra_help(): ?string {
		return "MySQL example:\n"
			. "  reprint db-apply https://example.com --state-dir=./state --fs-root=./files \\\n"
			. "    --target-user=root --target-db=wp_new \\\n"
			. "    --rewrite-url https://old.com https://new.com\n"
			. "\n"
			. "SQLite example:\n"
			. "  reprint db-apply https://example.com --state-dir=./state --fs-root=./files \\\n"
			. "    --target-engine=sqlite --target-sqlite-path=/path/to/db.sqlite \\\n"
			. "    --rewrite-url https://old.com https://new.com\n";
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
			$this->abort_option(),
			$this->verbose_option(),
			...$this->database_target_options(),
		];
	}
}
