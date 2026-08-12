<?php

namespace Reprint\Importer\Cli\Commands;

use Reprint\Importer\Cli\CliCommand;
use Reprint\Importer\Cli\CliOption;

/**
 * Generate local runtime configuration.
 */
class ApplyRuntimeCommand extends AbstractCliCommand {

	public function get_name(): string {
		return 'apply-runtime';
	}

	public function get_short_description(): string {
		return 'Generate server config and prepare the site to run locally';
	}

	public function get_usage(): string {
		return 'reprint apply-runtime <remote-reprint-api-url> --state-dir=DIR '
			. '(--fs-root=DIR|--flat-document-root=DIR) [options]';
	}

	public function get_long_description(): string {
		return "Generates server configuration (runtime.php, nginx.conf or start.sh)\n"
			. "from preflight data and removes production-only drop-ins and mu-plugins\n"
			. "that would crash outside the original host.\n"
			. "\n"
			. "If db-apply was run first, embeds the target database credentials\n"
			. "into runtime.php automatically.\n"
			. "\n"
			. "The remote Reprint API URL selects the state used to generate the\n"
			. "runtime configuration; no network calls are made.\n"
			. "\n"
			. "Pass --fs-root for the raw download directory (the remote document_root\n"
			. "path is appended automatically), or --flat-document-root for a directory\n"
			. "created by flat-docroot (used as-is). These are mutually exclusive.\n";
	}

	public function get_extra_help(): ?string {
		return "Runtime modes:\n"
			. "  nginx-fpm      — writes runtime.php + nginx.conf\n"
			. "  php-builtin    — writes runtime.php + start.sh\n"
			. "  playground-cli — writes runtime.php + blueprint.json\n"
			. "\n"
			. "Database configuration:\n"
			. "  When db-apply has been run before apply-runtime, the target database\n"
			. "  engine and credentials are read from state and included in runtime.php\n"
			. "  as DB_* constants. For MySQL targets this means DB_HOST, DB_NAME,\n"
			. "  DB_USER, and DB_PASSWORD. For SQLite targets, the sqlite-database-\n"
			. "  integration plugin is copied into the output directory and a lazy-\n"
			. "  loading \$wpdb proxy is generated in runtime.php (Playground-style,\n"
			. "  no files placed in the filesystem root).\n"
			. "\n"
			. "Output files (nginx-fpm):\n"
			. "  (output-dir)/runtime.php             PHP runtime (constants, route handlers)\n"
			. "  (output-dir)/nginx.conf              Nginx server block\n"
			. "\n"
			. "Output files (php-builtin):\n"
			. "  (output-dir)/runtime.php             PHP runtime (constants, routing, handlers)\n"
			. "  (output-dir)/start.sh                Shell script to launch the server\n"
			. "\n"
			. "Output files (playground-cli):\n"
			. "  (output-dir)/runtime.php             PHP runtime (constants, route handlers)\n"
			. "  (output-dir)/blueprint.json          Playground Blueprint\n"
			. "\n"
			. "Output files (sqlite target, additional):\n"
			. "  (output-dir)/sqlite-database-integration/   Plugin copy\n"
			. "\n"
			. "Examples:\n"
			. "  # From raw download directory:\n"
			. "  reprint apply-runtime https://example.com --state-dir=./state \\\n"
			. "    --fs-root=./files --output-dir=./runtime --runtime=php-builtin\n"
			. "\n"
			. "  # From flattened layout:\n"
			. "  reprint apply-runtime https://example.com --state-dir=./state \\\n"
			. "    --flat-document-root=./flat --output-dir=./runtime --runtime=php-builtin\n"
			. "\n"
			. "  bash ./runtime/start.sh\n";
	}

	public function get_filesystem_root_requirement(): string {
		return CliCommand::FILESYSTEM_ROOT_OR_FLAT;
	}

	protected function command_options(): array {
		return [
			$this->state_directory_option(),
			$this->filesystem_root_option(),
			$this->verbose_option(),
			$this->runtime_option(),
			$this->output_directory_option(),
			CliOption::value(
				'flat-document-root',
				'flat_document_root',
				'DIR',
				'Flattened layout directory (used as-is)'
			)->with_aliases( [ 'flattened-docroot' ] ),
			CliOption::value(
				'host',
				'host',
				'HOST',
				'Listen address (default: from rewrite URL, or localhost)'
			),
			CliOption::value(
				'port',
				'port',
				'PORT',
				'Listen port (default: from rewrite URL, or 8881)'
			)->cast_as( 'int' ),
		];
	}
}
