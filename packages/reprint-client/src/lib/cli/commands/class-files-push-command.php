<?php

namespace Reprint\Importer\Cli\Commands;

use Reprint\Importer\Cli\CliOption;

/**
 * Push local paths beneath the remote document root from the filesystem root.
 */
class FilesPushCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'files-push';
	}

	public function get_short_description(): string {
		return 'Push one local file tree without database work';
	}

	public function get_usage(): string {
		return 'reprint files-push <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR --secret=TOKEN [--force-http] [--progress=MODE] [--verbose]';
	}

	public function get_long_description(): string {
		return "Sends the remote document root's local tree beneath --fs-root.\n"
			. "This is a low-level, files-only command: it performs no database work,\n"
			. "plan display, confirmation prompt, automatic retry, or automatic restart.\n"
			. "It requires saved preflight data for the remote document root.\n"
			. "\n"
			. "Each process runs one sender until it completes, reaches a caller time or\n"
			. "memory boundary, or receives a signal handled by this PHP runtime.\n"
			. "Re-run the same command after exit 2.\n"
			. "After a restart result, the next run starts a fresh plan.\n";
	}

	public function get_extra_help(): ?string {
		return "Progress output:\n"
			. "  auto   Use tty on a terminal and jsonl otherwise (default)\n"
			. "  tty    Force the single interactive progress bar\n"
			. "  jsonl  Force one JSON object per line\n"
			. "Explicit tty and jsonl modes cannot be combined with --verbose.\n"
			. "\n"
			. "Exit outcomes:\n"
			. "  0  File push complete\n"
			. "  2  Partial, interrupted, or restart; run the command again\n"
			. "  1  Failed request or command error\n";
	}

	protected function framework_options(): array {
		return [ $this->progress_output_mode_option() ];
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option( false ),
			$this->secret_option(),
			CliOption::flag(
				'force-http',
				'force_http',
				'Allow a trusted plain-HTTP target; anyone able to observe or alter the connection can read or modify transferred content'
			),
			$this->verbose_option(),
		];
	}
}
