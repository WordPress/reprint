<?php

namespace Reprint\Importer\Cli;

/**
 * Produces CLI help from registered command synopses.
 */
class CliHelpRenderer {

	private const GLOBAL_OPTION_ORDER = [
		'secret',
		'abort',
		'verbose',
		'no-follow-symlinks',
		'on-fs-root-nonempty',
		'include-caches',
		'adaptive',
		'step',
		'steps',
	];

	/** @var CliCommandRegistry */
	private $command_registry;

	/** @var ImporterVersionProvider */
	private $version_provider;

	public function __construct(
		CliCommandRegistry $command_registry,
		ImporterVersionProvider $version_provider
	) {
		$this->command_registry = $command_registry;
		$this->version_provider = $version_provider;
	}

	public function render_main_help( bool $use_terminal_colors ): string {
		$magenta = $use_terminal_colors ? "\033[35m" : '';
		$blue    = $use_terminal_colors ? "\033[38;5;63m" : '';
		$reset   = $use_terminal_colors ? "\033[0m" : '';
		$output  = "{$magenta} ___         {$blue}___         _          _   {$reset}\n";
		$output  .= "{$magenta}| _ \\  ___  {$blue}| _ \\  _ _  (_)  _ _   | |_ {$reset}\n";
		$output  .= "{$magenta}|   / / -_) {$blue}|  _/ | '_| | | | ' \\  |  _|{$reset}\n";
		$output  .= "{$magenta}|_|_\\ \\___| {$blue}|_|   |_|   |_| |_||_|  \\__|{$reset}\n\n";
		$output  .= "Mirror any WordPress site over HTTP.\n";
		$output  .= 'Version ' . $this->version_provider->get_version() . "\n\n";
		$output  .= "Usage: reprint <command> <remote-reprint-api-url> [options]\n\n";

		$high_level_commands = [];
		$low_level_commands  = [];
		$maximum_name_length = 0;
		foreach ( $this->command_registry->get_commands() as $name => $command ) {
			$maximum_name_length = max( $maximum_name_length, strlen( $name ) );
			if ( $command->get_level() === 'high' ) {
				$high_level_commands[ $name ] = $command;
			} else {
				$low_level_commands[ $name ] = $command;
			}
		}

		$output .= "Commands:\n";
		$output .= $this->render_command_list( $high_level_commands, $maximum_name_length );
		$output .= "\nLow-level commands:\n";
		$output .= $this->render_command_list( $low_level_commands, $maximum_name_length );
		$output .= "\nRun 'reprint <command> --help' for command-specific help.\n\n";

		$required_options = $this->collect_main_help_options( 'required' );
		if ( $required_options ) {
			$output .= "Required options:\n";
			$output .= $this->render_option_list( $required_options );
			$output .= "\n";
		}

		$output .= "Shared options (see command help for availability):\n";
		$output .= $this->render_option_list(
			$this->sort_global_options( $this->collect_main_help_options( 'global' ) ),
			[ '--version, -V' => 'Print version and exit' ]
		);
		$output .= "\nExit codes:\n";
		$output .= "  0  Command completed successfully\n";
		$output .= "  2  Partial progress — run the same command again to continue\n";
		$output .= "  1  Error\n\n";
		$output .= "Resumable commands keep their command-specific work under --state-dir.\n";
		$output .= "Run command-specific help for continuation and cancellation behavior.\n";

		return $output;
	}

	/**
	 * @param array<string,CliCommand> $commands Commands keyed by name.
	 */
	private function render_command_list( array $commands, int $maximum_name_length ): string {
		$output = '';
		foreach ( $commands as $name => $command ) {
			$output .= '  ' . str_pad( $name, $maximum_name_length + 2 )
				. $command->get_short_description() . "\n";
		}
		return $output;
	}

	/**
	 * @return array<int,CliOption>
	 */
	private function collect_main_help_options( string $section ): array {
		$options_by_name = [];
		foreach ( $this->command_registry->get_commands() as $command ) {
			foreach ( $command->get_options() as $option ) {
				if ( $option->main_help_section === $section && ! isset( $options_by_name[ $option->name ] ) ) {
					$options_by_name[ $option->name ] = $option;
				}
			}
		}
		return array_values( $options_by_name );
	}

	/**
	 * @param array<int,CliOption> $options Options to render.
	 * @param array<string,string> $additional Additional usage and description pairs.
	 */
	private function render_option_list( array $options, array $additional = [] ): string {
		$lines = [];
		foreach ( $options as $option ) {
			if ( $option->help !== null ) {
				$lines[] = [ $this->render_option_usage( $option ), $option->help ];
			}
		}
		foreach ( $additional as $usage => $description ) {
			$lines[] = [ $usage, $description ];
		}

		$maximum_usage_length = 0;
		foreach ( $lines as $line ) {
			$maximum_usage_length = max( $maximum_usage_length, strlen( $line[0] ) );
		}
		$description_column = max( $maximum_usage_length + 2, 21 );

		$output = '';
		foreach ( $lines as $line ) {
			[ $usage, $description ] = $line;
			if ( strlen( $usage ) >= $description_column ) {
				$output .= "  {$usage}\n";
				$output .= str_repeat( ' ', $description_column + 2 ) . "{$description}\n";
			} else {
				$output .= '  ' . str_pad( $usage, $description_column ) . "{$description}\n";
			}
		}
		return $output;
	}

	private function render_option_usage( CliOption $option ): string {
		$name = "--{$option->name}";
		if ( $option->short_name !== null ) {
			$name .= ", -{$option->short_name}";
		}
		if ( $option->type === 'value' || $option->type === 'value-or-next' ) {
			return "{$name}=" . ( $option->placeholder ?? 'VALUE' );
		}
		if ( $option->type === 'two-arguments' ) {
			return "{$name} " . ( $option->argument_labels ?? 'ARG1 ARG2' );
		}
		return $name;
	}

	/**
	 * @param array<int,CliOption> $options Options shown in main help.
	 * @return array<int,CliOption>
	 */
	private function sort_global_options( array $options ): array {
		usort( $options, [ $this, 'compare_global_options' ] );
		return $options;
	}

	public function render_command_help( CliCommand $command ): string {
		$output = 'Usage: ' . $command->get_usage() . "\n\n";
		$output .= $command->get_long_description();

		$options = [];
		foreach ( $command->get_options() as $option ) {
			if ( $option->help !== null && $option->show_in_command_help ) {
				$options[] = $option;
			}
		}
		if ( $options ) {
			usort( $options, [ $this, 'compare_command_options' ] );
			$output .= "\nOptions:\n";
			$output .= $this->render_option_list( $options );
		}
		if ( $command->get_extra_help() !== null ) {
			$output .= "\n";
			$output .= $command->get_extra_help();
		}

		return $output . "\n";
	}

	public function render_install_exporter( bool $use_terminal_colors ): string {
		$version                = $this->version_provider->get_version();
		$is_development_version = str_contains( $version, '-trunk' ) || $version === 'v0.0.0';
		$bold                   = $use_terminal_colors ? "\033[1m" : '';
		$dim                    = $use_terminal_colors ? "\033[2m" : '';
		$cyan                   = $use_terminal_colors ? "\033[36m" : '';
		$reset                  = $use_terminal_colors ? "\033[0m" : '';
		$repository             = 'WordPress/reprint';
		$zip_url                = "https://github.com/{$repository}/releases/download/{$version}/reprint-exporter-wp.zip";

		$output = "{$bold}Install the RePrint Exporter Plugin{$reset}\n\n";
		$output .= "The exporter plugin must be installed on the WordPress site you\n";
		$output .= "want to mirror. It exposes the HTTP API that reprint connects to.\n\n";
		$output .= "{$bold}Step 1: Download the plugin{$reset}\n\n";
		if ( $is_development_version ) {
			$output .= "  You are running an unreleased development build ({$version}).\n";
			$output .= "  Install the exporter plugin from the same branch:\n\n";
			$output .= "  {$dim}composer build:exporter-plugin{$reset}\n\n";
			$output .= "  Then upload reprint-exporter-wp.zip through wp-admin,\n";
			$output .= "  or symlink reprint-exporter-wp/ into wp-content/plugins/.\n";
		} else {
			$output .= "  {$cyan}{$zip_url}{$reset}\n";
		}
		$output .= "\n{$bold}Step 2: Install on your WordPress site{$reset}\n\n";
		$output .= "  1. Log in to wp-admin\n";
		$output .= "  2. Go to Plugins → Add New Plugin → Upload Plugin\n";
		$output .= "  3. Upload reprint-exporter-wp.zip and activate it\n\n";
		$output .= "{$bold}Step 3: Configure the shared secret{$reset}\n\n";
		$output .= "  1. In wp-admin, go to Site Export (in the sidebar)\n";
		$output .= "  2. Enter a shared secret and save\n";
		$output .= "  3. Use the same secret with reprint:\n\n";
		$output .= "     {$dim}php reprint.phar preflight https://your-site.com \\\n";
		$output .= "       --secret=YOUR_SECRET \\\n";
		$output .= "       --state-dir=./state --fs-root=./files{$reset}\n\n";

		return $output;
	}

	private function compare_global_options( CliOption $first, CliOption $second ): int {
		$first_position  = array_search( $first->name, self::GLOBAL_OPTION_ORDER, true );
		$second_position = array_search( $second->name, self::GLOBAL_OPTION_ORDER, true );
		$first_position  = $first_position === false ? PHP_INT_MAX : $first_position;
		$second_position = $second_position === false ? PHP_INT_MAX : $second_position;
		return $first_position <=> $second_position;
	}

	private function compare_command_options( CliOption $first, CliOption $second ): int {
		$first_is_shared  = in_array(
			$first->main_help_section,
			[ 'required', 'global' ],
			true
		) ? 1 : 0;
		$second_is_shared = in_array(
			$second->main_help_section,
			[ 'required', 'global' ],
			true
		) ? 1 : 0;
		return $first_is_shared - $second_is_shared;
	}
}
