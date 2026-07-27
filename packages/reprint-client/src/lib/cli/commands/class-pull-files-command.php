<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Pull files through the high-level pipeline.
 */
class PullFilesCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'pull-files';
	}

	public function get_level(): string {
		return 'high';
	}

	public function get_short_description(): string {
		return 'Pull files through the high-level pull pipeline';
	}

	public function get_long_description(): string {
		return "Runs the file side of the pull pipeline:\n"
			. "\n"
			. "  1. Preflight — probe the remote site environment\n"
			. "  2. files-pull — download all files, or a selected subset\n"
			. "\n"
			. "This gives files the same retry and resume behavior as pull,\n"
			. "without running the database stages.\n";
	}

	public function get_extra_help(): ?string {
		return "Examples:\n"
			. "  reprint pull-files https://example.com \\\n"
			. "    --secret=TOKEN --state-dir=./state --fs-root=./files\n"
			. "\n"
			. "  reprint pull-files https://example.com \\\n"
			. "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
			. "    --only=:wp-content: --only=:wp-plugins:\n";
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
			$this->secret_option(),
			$this->abort_option(),
			$this->verbose_option(),
			$this->no_follow_symlinks_option(),
			...$this->follow_symlinks_options(),
			$this->filesystem_nonempty_option(),
			$this->include_caches_option(),
			$this->filter_option(),
			$this->extra_directory_option(),
			...$this->file_selection_options(),
		];
	}
}
