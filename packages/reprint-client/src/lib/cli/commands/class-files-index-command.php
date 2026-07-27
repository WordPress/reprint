<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Build or refresh the remote file index.
 */
class FilesIndexCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'files-index';
	}

	public function get_short_description(): string {
		return 'Index all remote files (initial) or detect changes (delta)';
	}

	public function get_long_description(): string {
		return "Streams the full remote directory tree over HTTP and writes each\n"
			. "entry (path, size, ctime, type, and directory emptiness) to\n"
			. "<remote-state-directory>/pull/remote-index.next.jsonl.\n"
			. "\n"
			. "On the first run, builds the complete index. On subsequent runs,\n"
			. "re-indexes and diffs against the prior snapshot to produce a\n"
			. "fetch list of changed files.\n"
			. "\n"
			. "When symlink-following is enabled, recursively discovers and indexes\n"
			. "additional directories outside the primary roots.\n"
			. "\n"
			. "Does not download any file contents.\n";
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
			$this->secret_option(),
			$this->abort_option(),
			$this->verbose_option(),
			$this->include_caches_option(),
			$this->extra_directory_option(),
		];
	}
}
