<?php

namespace Reprint\Importer\Cli;

use InvalidArgumentException;
use Reprint\Importer\Cli\Commands\ApplyRuntimeCommand;
use Reprint\Importer\Cli\Commands\DatabaseApplyCommand;
use Reprint\Importer\Cli\Commands\DatabaseDomainsCommand;
use Reprint\Importer\Cli\Commands\DatabaseIndexCommand;
use Reprint\Importer\Cli\Commands\DatabasePullCommand;
use Reprint\Importer\Cli\Commands\FilesDiffCommand;
use Reprint\Importer\Cli\Commands\FilesIndexCommand;
use Reprint\Importer\Cli\Commands\FilesPullCommand;
use Reprint\Importer\Cli\Commands\FilesPushCommand;
use Reprint\Importer\Cli\Commands\FilesStatsCommand;
use Reprint\Importer\Cli\Commands\FlatDocumentRootCommand;
use Reprint\Importer\Cli\Commands\PullMetadataCommand;
use Reprint\Importer\Cli\Commands\InstallExporterCommand;
use Reprint\Importer\Cli\Commands\PreflightAssertCommand;
use Reprint\Importer\Cli\Commands\PreflightCommand;
use Reprint\Importer\Cli\Commands\PullCommand;
use Reprint\Importer\Cli\Commands\PullDatabaseCommand;
use Reprint\Importer\Cli\Commands\PullFilesCommand;

/**
 * Resolves registered command names and aliases.
 */
class CliCommandRegistry {

	/** @var array<string,CliCommand> Canonical command name to command. */
	private $commands = [];

	/** @var array<string,string> Alias to canonical command name. */
	private $aliases = [];

	/**
	 * @param array<int,CliCommand> $commands Commands to register.
	 */
	public function __construct( array $commands ) {
		foreach ( $commands as $command ) {
			$name = $command->get_name();
			if ( isset( $this->commands[ $name ] ) || isset( $this->aliases[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions describe CLI registration errors, not HTML output.
				throw new InvalidArgumentException( "The CLI command name \"{$name}\" is registered more than once." );
			}
			$this->commands[ $name ] = $command;
			foreach ( $command->get_aliases() as $alias ) {
				if ( isset( $this->commands[ $alias ] ) || isset( $this->aliases[ $alias ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions describe CLI registration errors, not HTML output.
					throw new InvalidArgumentException( "The CLI command alias \"{$alias}\" is registered more than once." );
				}
				$this->aliases[ $alias ] = $name;
			}
		}
	}

	public static function create_default(): self {
		return new self( [
			new PullCommand(),
			new PullFilesCommand(),
			new PullDatabaseCommand(),
			new InstallExporterCommand(),
			new PreflightCommand(),
			new PreflightAssertCommand(),
			new FilesPullCommand(),
			new FilesDiffCommand(),
			new FilesPushCommand(),
			new FilesIndexCommand(),
			new FilesStatsCommand(),
			new DatabasePullCommand(),
			new DatabaseIndexCommand(),
			new DatabaseDomainsCommand(),
			new PullMetadataCommand(),
			new DatabaseApplyCommand(),
			new FlatDocumentRootCommand(),
			new ApplyRuntimeCommand(),
		] );
	}

	public function find( string $name ): ?CliCommand {
		$canonical_name = $this->aliases[ $name ] ?? $name;
		return $this->commands[ $canonical_name ] ?? null;
	}

	/**
	 * @return array<string,CliCommand> Commands keyed by canonical name.
	 */
	public function get_commands(): array {
		return $this->commands;
	}
}
