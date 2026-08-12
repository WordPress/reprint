<?php

namespace Reprint\Importer\Cli\Commands;

use ImportClient;
use Reprint\Importer\Cli\CliCommand;
use Reprint\Importer\Cli\CliOption;
use Reprint\Importer\Cli\CliPositionalArgument;

/**
 * Shared synopsis constructors for concrete commands.
 */
abstract class AbstractCliCommand implements CliCommand {

	public function get_aliases(): array {
		return [];
	}

	public function get_level(): string {
		return 'low';
	}

	public function get_extra_help(): ?string {
		return null;
	}

	public function get_usage(): string {
		$usage = 'reprint ' . $this->get_name();
		foreach ( $this->get_positional_arguments() as $argument ) {
			$value = '<' . $argument->placeholder . '>';
			$usage .= ' ' . ( $argument->required ? $value : "[{$value}]" );
		}
		foreach ( $this->get_options() as $option ) {
			if ( $option->main_help_section === 'required' ) {
				$usage .= ' ' . $this->render_option_usage( $option );
			}
		}
		return $usage . ' [options]';
	}

	public function get_positional_arguments(): array {
		return [
			new CliPositionalArgument(
				'remote_reprint_api_url',
				'remote-reprint-api-url',
				'Remote Reprint API URL.'
			),
		];
	}

	/**
	 * @return array<int,CliOption>
	 */
	final public function get_options(): array {
		return array_merge(
			$this->framework_options(),
			$this->command_options()
		);
	}

	/**
	 * Hidden execution controls accepted by every operational command.
	 *
	 * @return array<int,CliOption>
	 */
	protected function framework_options(): array {
		return [
			$this->progress_output_mode_option(),
			CliOption::flag(
				'adaptive',
				'tuning_config.enabled',
				'Enable adaptive request tuning (default: on)'
			)->in_main_help( 'global' )->hidden_from_command_help(),
			CliOption::flag( 'no-adaptive', 'tuning_config.enabled' )->storing( false ),
			CliOption::value(
				'step',
				'pipeline_step',
				'N',
				'Current pipeline step (1-indexed, for progress file)'
			)->cast_as( 'int' )->in_main_help( 'global' )->hidden_from_command_help(),
			CliOption::value(
				'steps',
				'pipeline_steps',
				'N',
				'Total pipeline steps (for progress file)'
			)->cast_as( 'int' )->in_main_help( 'global' )->hidden_from_command_help(),
			...$this->tuning_options(),
		];
	}

	/**
	 * @return array<int,CliOption>
	 */
	protected function tuning_options(): array {
		return [
			CliOption::value( 'duty', 'tuning_config.duty', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'duty-min', 'tuning_config.duty_min', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'duty-max', 'tuning_config.duty_max', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'throughput-alpha', 'tuning_config.throughput_ema_alpha', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'aimd-drop-ratio', 'tuning_config.aimd_drop_ratio', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'aimd-decrease-factor', 'tuning_config.aimd_decrease_factor', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'error-decrease-factor', 'tuning_config.error_decrease_factor', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'aimd-increase-file', 'tuning_config.aimd_increase_file_bytes', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'aimd-increase-index', 'tuning_config.aimd_increase_index_entries', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'aimd-increase-sql', 'tuning_config.aimd_increase_sql_fragments', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'error-backoff', 'tuning_config.error_backoff_requests', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'max-exec', 'tuning_config.max_execution_time', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'memory-threshold', 'tuning_config.memory_threshold', 'VALUE' )->cast_as( 'float' ),
			CliOption::value( 'file-chunk-start', 'tuning_config.file_chunk_start', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'file-chunk-min', 'tuning_config.file_chunk_min', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'file-chunk-max', 'tuning_config.file_chunk_max', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'index-batch-start', 'tuning_config.index_batch_start', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'index-batch-min', 'tuning_config.index_batch_min', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'index-batch-max', 'tuning_config.index_batch_max', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'sql-fragments-start', 'tuning_config.sql_fragments_start', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'sql-fragments-min', 'tuning_config.sql_fragments_min', 'VALUE' )->cast_as( 'int' ),
			CliOption::value( 'sql-fragments-max', 'tuning_config.sql_fragments_max', 'VALUE' )->cast_as( 'int' ),
			CliOption::flag( 'db-unbuffered', 'tuning_config.db_unbuffered' ),
			CliOption::value( 'db-query-time-limit', 'tuning_config.db_query_time_limit', 'VALUE' )->cast_as( 'int' ),
		];
	}

	/**
	 * Options explicitly accepted by this command.
	 *
	 * @return array<int,CliOption>
	 */
	abstract protected function command_options(): array;

	private function render_option_usage( CliOption $option ): string {
		if ( $option->type === 'value' || $option->type === 'value-or-next' ) {
			return "--{$option->name}=" . ( $option->placeholder ?? 'VALUE' );
		}
		if ( $option->type === 'two-arguments' ) {
			return "--{$option->name} " . ( $option->argument_labels ?? 'ARG1 ARG2' );
		}
		return "--{$option->name}";
	}

	public function requires_state_directory(): bool {
		return true;
	}

	public function get_filesystem_root_requirement(): string {
		return CliCommand::FILESYSTEM_ROOT_REQUIRED;
	}

	protected function state_directory_option(): CliOption {
		return CliOption::value(
			'state-dir',
			'state_dir',
			'DIR',
			'Directory for pull state files and SQL dumps'
		)->in_main_help( 'required' );
	}

	protected function filesystem_root_option( bool $accept_legacy_alias = true ): CliOption {
		return CliOption::value(
			'fs-root',
			'filesystem_root',
			'DIR',
			'Local directory read from or written to for site files'
		)->with_aliases( $accept_legacy_alias ? [ 'docroot' ] : [] )->in_main_help( 'required' );
	}

	protected function secret_option(): CliOption {
		return CliOption::value(
			'secret',
			'secret',
			'TOKEN',
			'HMAC shared secret for export API authentication'
		)->in_main_help( 'global' );
	}

	protected function progress_output_mode_option(): CliOption {
		return CliOption::value(
			'progress',
			'progress',
			'MODE',
			'Progress output: auto, tty, or jsonl (default: auto)'
		)->accepting( ImportClient::PROGRESS_OUTPUT_MODES )->in_main_help( 'global' );
	}

	protected function abort_option(): CliOption {
		return CliOption::flag(
			'abort',
			'abort',
			'Abort current sync and exit (preserves downloaded files)'
		)->in_main_help( 'global' );
	}

	protected function verbose_option(): CliOption {
		return CliOption::flag(
			'verbose',
			'verbose',
			'Show detailed request/response logs'
		)->with_short_name( 'v' )->in_main_help( 'global' );
	}

	protected function no_follow_symlinks_option(): CliOption {
		return CliOption::flag(
			'no-follow-symlinks',
			'follow_symlinks',
			'Do not follow symlinks pointing outside root directories'
		)->storing( false )->in_main_help( 'global' );
	}

	/**
	 * @return array<int,CliOption>
	 */
	protected function follow_symlinks_options(): array {
		return [
			CliOption::flag( 'follow-symlinks', 'follow_symlinks' ),
			CliOption::value(
				'follow-symlinks',
				'local_followed_symlinks_root',
				'DIR',
				'Follow symlinks, consolidating escaping (out-of-scope) targets into DIR '
				. '(a :fs-root: path or an absolute path within --fs-root), nested by source path. '
				. 'Bare --follow-symlinks is equivalent to --follow-symlinks=:fs-root:.'
			),
		];
	}

	protected function include_caches_option(): CliOption {
		return CliOption::flag(
			'include-caches',
			'include_caches',
			'Include generated caches, VCS metadata, OS junk and editor scratch files (skipped by default)'
		)->in_main_help( 'global' );
	}

	protected function filesystem_nonempty_option(): CliOption {
		return CliOption::value(
			'on-fs-root-nonempty',
			'fs_root_nonempty_behavior',
			'MODE',
			'What to do when filesystem root is non-empty (error|preserve-local)'
		)->with_aliases( [ 'on-docroot-nonempty' ] )->in_main_help( 'global' );
	}

	protected function filter_option(): CliOption {
		return CliOption::value(
			'filter',
			'filter',
			'MODE',
			null
		)->accepting( [ 'none', 'essential-files', 'skipped-earlier' ] );
	}

	protected function extra_directory_option(): CliOption {
		return CliOption::value(
			'extra-directory',
			'extra_directory',
			'DIR',
			'Additional remote directory to include in the export'
		);
	}

	protected function maximum_allowed_packet_option(): CliOption {
		return CliOption::value(
			'max-allowed-packet',
			'max_allowed_packet',
			'SIZE',
			'Client max_allowed_packet (e.g. 16M, 64M)'
		)->cast_as( 'size' );
	}

	/**
	 * @return array<int,CliOption>
	 */
	protected function database_target_options(): array {
		return [
			CliOption::value(
				'target-engine',
				'target_engine',
				'ENGINE',
				'Target database engine: mysql or sqlite'
			),
			CliOption::value(
				'target-host',
				'target_host',
				'HOST',
				'Target MySQL host (default: 127.0.0.1)'
			),
			CliOption::value(
				'target-port',
				'target_port',
				'PORT',
				'Target MySQL port (default: 3306)'
			)->cast_as( 'int' ),
			CliOption::value(
				'target-user',
				'target_user',
				'USER',
				'Target MySQL user (required for mysql)'
			),
			CliOption::value(
				'target-pass',
				'target_pass',
				'PASS',
				'Target MySQL password'
			),
			CliOption::value(
				'target-db',
				'target_db',
				'NAME',
				'Target DB name (required for mysql, optional for sqlite)'
			),
			CliOption::value(
				'target-sqlite-path',
				'target_sqlite_path',
				'PATH',
				'Target SQLite database file (default: <wp-content>/database/.ht.sqlite)'
			),
			CliOption::two_arguments(
				'rewrite-url',
				'rewrite_url',
				'FROM TO',
				'Rewrite FROM to TO (repeatable)',
			),
			CliOption::value_or_next(
				'new-site-url',
				'new_site_url',
				'URL',
				'New site URL (auto-creates --rewrite-url from export URL origin)'
			),
		];
	}

	/**
	 * @return array<int,CliOption>
	 */
	protected function file_selection_options(): array {
		return [
			CliOption::two_arguments(
				'remap',
				'remap',
				'SOURCE TARGET',
				'Place SOURCE (a :token: like :wp-uploads: or an absolute path) at TARGET '
				. '(a :fs-root: path or an absolute path within --fs-root); repeatable'
			),
			CliOption::value_or_next(
				'include',
				'include',
				'SOURCE',
				'Restrict the file pull to SOURCE (a :token: like :wp-content: or :wp-uploads:, or an absolute path); '
				. 'repeat for several. Default pulls everything'
			)->with_aliases( [ 'only' ] )->repeated(),
			CliOption::value_or_next(
				'exclude',
				'exclude',
				'SOURCE',
				'Omit SOURCE (a :token: like :wp-content: or :wp-uploads:, or an absolute path) from the file pull; '
				. 'repeat for several'
			)->repeated(),
		];
	}

	/**
	 * @return array<int,CliOption>
	 */
	protected function database_pull_output_options(): array {
		return [
			CliOption::value(
				'sql-output',
				'sql_output',
				'MODE',
				'Output mode: file (default), stdout, mysql'
			),
			CliOption::value(
				'mysql-host',
				'mysql_host',
				'HOST',
				'MySQL host (default: 127.0.0.1, for --sql-output=mysql)'
			),
			CliOption::value(
				'mysql-port',
				'mysql_port',
				'PORT',
				'MySQL port (default: 3306, for --sql-output=mysql)'
			),
			CliOption::value(
				'mysql-user',
				'mysql_user',
				'USER',
				'MySQL user (default: root, for --sql-output=mysql)'
			),
			CliOption::value(
				'mysql-password',
				'mysql_password',
				'PASS',
				'MySQL password (or set MYSQL_PASSWORD env)'
			),
			CliOption::value(
				'mysql-database',
				'mysql_database',
				'DB',
				'MySQL database (required for --sql-output=mysql)'
			),
		];
	}

	protected function flatten_target_option(): CliOption {
		return CliOption::value(
			'flatten-to',
			'flatten_to',
			'PATH',
			'Target directory for the flattened layout'
		);
	}

	protected function force_option(): CliOption {
		return CliOption::flag(
			'force',
			'force',
			'Remove conflicting non-symlink files and replace with symlinks'
		);
	}

	protected function runtime_option(): CliOption {
		return CliOption::value(
			'runtime',
			'runtime',
			'RUNTIME',
			'Target server runtime: php-builtin, playground-cli, nginx-fpm, or none'
		)->accepting( VALID_TARGET_RUNTIMES );
	}

	protected function start_runtime_option(): CliOption {
		return CliOption::value(
			'start-runtime',
			'start_runtime',
			'RUNTIME',
			'Runtime to launch after pull (php-builtin|playground-cli|nginx-fpm|none)'
		)->accepting( VALID_TARGET_RUNTIMES );
	}

	protected function output_directory_option(): CliOption {
		return CliOption::value(
			'output-dir',
			'output_dir',
			'DIR',
			'Directory for generated runtime files'
		);
	}
}
