<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Reassemble pulled paths into a standard WordPress layout.
 */
class FlatDocumentRootCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'flat-docroot';
	}

	public function get_aliases(): array {
		return [ 'flat-document-root', 'flatten-docroot' ];
	}

	public function get_short_description(): string {
		return 'Reassemble pulled files into a standard WordPress layout';
	}

	public function get_long_description(): string {
		return "Creates a directory at --flatten-to with symlinks that map the\n"
			. "pulled files back into a vanilla WordPress directory structure.\n"
			. "\n"
			. "Uses preflight paths (ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR,\n"
			. "WPMU_PLUGIN_DIR, uploads basedir) to locate each component\n"
			. "within --fs-root, even when they reside in different parent\n"
			. "directories on the source server (e.g. WP Cloud with ABSPATH at\n"
			. "/srv/htdocs and WP_CONTENT_DIR at /tmp/__wp__/wp-content).\n"
			. "\n"
			. "No files are copied — only symlinks are created. Idempotent.\n"
			. "If a path that should be a symlink is a regular file or directory,\n"
			. "the command stops with an error unless --force is specified.\n";
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
			$this->verbose_option(),
			$this->flatten_target_option(),
			$this->force_option(),
		];
	}
}
