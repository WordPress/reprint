<?php

namespace Reprint\Importer\Cli;

use ImportClient;
use ReprintProcessLock;
use Throwable;

/**
 * Coordinates CLI routing, input processing, command execution, and reporting.
 */
class ImporterCliApplication {

	/** @var string|null */
	protected $last_error_code;
	/** @var CliCommandRegistry */
	private $command_registry;
	/** @var CliArgumentParser */
	private $argument_parser;
	/** @var CliHelpRenderer */
	private $help_renderer;
	/** @var ImporterVersionProvider */
	private $version_provider;
	/** @var CliOutput */
	private $output;

	public function __construct(
		CliCommandRegistry $command_registry,
		CliArgumentParser $argument_parser,
		CliHelpRenderer $help_renderer,
		ImporterVersionProvider $version_provider,
		CliOutput $output
	) {
		$this->command_registry     = $command_registry;
		$this->argument_parser      = $argument_parser;
		$this->help_renderer        = $help_renderer;
		$this->version_provider     = $version_provider;
		$this->output               = $output;
	}

	/**
	 * Construct the production CLI dependency graph.
	 *
	 * @param resource $standard_output Standard output stream.
	 * @param resource $standard_error Standard error stream.
	 */
	public static function create_default(
		string $source_directory,
		$standard_output,
		$standard_error
	): self {
		$command_registry = CliCommandRegistry::create_default();
		$version_provider = new ImporterVersionProvider( $source_directory );
		return new self(
			$command_registry,
			new CliArgumentParser(),
			new CliHelpRenderer( $command_registry, $version_provider ),
			$version_provider,
			new CliOutput( $standard_output, $standard_error )
		);
	}

	/**
	 * Run one invocation without terminating the PHP process.
	 *
	 * @param array<int,string> $arguments Process arguments.
	 * @param bool $rethrow_execution_error Let an embedding caller handle operation failures.
	 */
	public function run( array $arguments, bool $rethrow_execution_error = false ): int {
		try {
			if ( isset( $arguments[1] ) && in_array( $arguments[1], [ '--version', '-V' ], true ) ) {
				$this->output->write( $this->version_provider->get_version() . "\n" );
				return 0;
			}
			if (
				count( $arguments ) < 2
				|| ( isset( $arguments[1] ) && in_array( $arguments[1], [ '--help', '-h', 'help' ], true ) )
			) {
				$this->output->write(
					$this->help_renderer->render_main_help(
						$this->output->standard_output_is_terminal()
					)
				);
				return 1;
			}

			$command = $this->command_registry->find( $arguments[1] );
			if ( $command === null ) {
				throw new CliInputException( "Unknown command: {$arguments[1]}" );
			}
			// install-exporter is a standalone guide — no URL, state-dir, or filesystem root needed.
			// Handle it before per-command --help so it always shows the full guide.
			if ( $command->get_name() === 'install-exporter' ) {
				$this->output->write(
					$this->help_renderer->render_install_exporter(
						$this->output->standard_output_is_terminal()
					)
				);
				return 0;
			}
			if ( $this->contains_help_option( $arguments ) ) {
				$this->output->write( $this->help_renderer->render_command_help( $command ) );
				return 0;
			}

			$invocation = $this->argument_parser->parse( $command, $arguments );

			return $this->run_business_command( $invocation, $rethrow_execution_error );
		} catch ( CliInputException $error ) {
			$this->output->write_error( rtrim( $error->getMessage(), "\n" ) . "\n" );
			return 1;
		}
	}

	/**
	 * @param array<int,string> $arguments Process arguments.
	 */
	private function contains_help_option( array $arguments ): bool {
		$command_arguments = array_slice( $arguments, 2 );
		return in_array( '--help', $command_arguments, true )
			|| in_array( '-h', $command_arguments, true );
	}

	private function run_business_command(
		CliInvocation $invocation,
		bool $rethrow_execution_error
	): int {
		try {
			return $this->execute_invocation( $invocation );
		} catch ( Throwable $error ) {
			$this->output->write_error(
				$this->render_execution_error(
					$error,
					$this->last_error_code,
					$this->output->standard_error_is_terminal()
				)
			);
			if ( $rethrow_execution_error ) {
				throw $error;
			}
			return 1;
		}
	}

	protected function execute_invocation( CliInvocation $invocation ): int {
		$this->last_error_code = null;
		$client                = null;
		$process_lock          = null;
		try {
			// Acquire the lock before local push state setup and audit writes so
			// each command owns every local state transition for its complete invocation.
			$process_lock = new ReprintProcessLock( $invocation->state_directory );

			$files_push_context = null;
			$files_diff_push_state_directory = null;
			if ( $invocation->command === 'files-push' ) {
				$files_push_context = ImportClient::prepare_files_push_context(
					$invocation->remote_reprint_api_url,
					$invocation->state_directory,
					$invocation->filesystem_root,
					$invocation->options
				);
			} elseif ( $invocation->command === 'files-diff' ) {
				$files_diff_push_state_directory = ImportClient::resolve_push_state_directory(
					$invocation->remote_reprint_api_url,
					$invocation->state_directory,
					$invocation->filesystem_root,
					'files-diff'
				);
			}

			$client = new ImportClient(
				$invocation->remote_reprint_api_url,
				$invocation->state_directory,
				$invocation->filesystem_root,
				$invocation->command
			);
			$client->audit_log_argv(
				$invocation->command,
				$invocation->original_arguments
			);

			$options = $invocation->options;
			if ( $files_push_context !== null ) {
				$options['files_push_context'] = $files_push_context;
			}
			if ( $files_diff_push_state_directory !== null ) {
				$options['files_diff_push_state_directory'] = $files_diff_push_state_directory;
			}
			$client->run( $options, $process_lock );

			return (int) $client->exit_code;
		} finally {
			$this->last_error_code = $client instanceof ImportClient
				? $client->last_error_code
				: null;
			if ( $process_lock instanceof ReprintProcessLock ) {
				$process_lock->close();
			}
		}
	}

	private function render_execution_error(
		Throwable $error,
		?string $error_code,
		bool $use_terminal_format
	): string {
		if ( $use_terminal_format ) {
			return "\nError: " . $error->getMessage() . "\n";
		}

		$payload = [
			'error'      => $error->getMessage(),
			'error_code' => $error_code,
			'exception'  => get_class( $error ),
			'file'       => $error->getFile(),
			'line'       => $error->getLine(),
		];
		$json    = json_encode( $payload );
		if ( $json !== false ) {
			return $json . "\n";
		}

		$json = json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( $json !== false ) {
			return $json . "\n";
		}

		return "{\"error\":\"Failed to encode the command error as JSON.\"}\n";
	}
}
