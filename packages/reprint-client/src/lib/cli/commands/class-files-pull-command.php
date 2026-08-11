<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Pull remote files into the filesystem root.
 */
class FilesPullCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'files-pull';
	}

	public function get_aliases(): array {
		return [ 'files-sync' ];
	}

	public function get_short_description(): string {
		return 'Pull all files (initial) or only changes (delta)';
	}

	public function get_long_description(): string {
		return "Downloads files from the remote site into --fs-root.\n"
			. "\n"
			. "On the first run, indexes the full remote directory tree and then\n"
			. "downloads every file. On subsequent runs, writes the next remote index,\n"
			. "compares it with the remote index, and downloads only what changed.\n"
			. "Interrupted pulls resume from the last saved cursor.\n"
			. "\n"
			. "Runs files-index internally to write the next remote index.\n";
	}

	public function get_extra_help(): ?string {
		return "Path selection:\n"
			. "  --include=SOURCE   Include only this source path prefix; repeatable.\n"
			. "  --exclude=SOURCE   Exclude this source path prefix; repeatable.\n"
			. "  Exclusions win when include and exclude prefixes overlap.\n"
			. "\n"
			. "Output files:\n"
			. "  (filesystem root)/                       Downloaded files\n"
			. "  remotes/<md5-of-trimmed-remote-reprint-api-url>/local_index.jsonl\n"
			. "                                           Local index advanced by completed pull mutations\n"
			. "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/remote-index.jsonl\n"
			. "                                           Remote index\n"
			. "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/remote-index.next.jsonl\n"
			. "                                           Next remote index\n"
			. "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/fetch-list.jsonl\n"
			. "                                           Files pending download\n"
			. "  remotes/<md5-of-trimmed-remote-reprint-api-url>/pull/state.json\n"
			. "                                           Resumable pull state\n"
			. "  audit.log                       Audit log\n";
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
