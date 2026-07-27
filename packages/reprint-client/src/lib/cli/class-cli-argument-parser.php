<?php

namespace Reprint\Importer\Cli;

use function WordPress\Reprint\Exporter\parse_size;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions report command-line input, not HTML output.

/**
 * Parses one command's positional and associative synopsis.
 */
class CliArgumentParser {

	/**
	 * @param array<int,string> $arguments Process arguments.
	 */
	public function parse( CliCommand $command, array $arguments ): CliInvocation {
		$argument_index       = 2;
		$positional_arguments = [];
		foreach ( $command->get_positional_arguments() as $argument ) {
			$value = $arguments[ $argument_index ] ?? null;
			if (
				$value === null
				|| strpos( $value, '--' ) === 0
				|| (
					$argument->name === 'remote_reprint_api_url'
					&& strpos( $value, '-' ) === 0
				)
			) {
				if ( $argument->required ) {
					throw new CliInputException(
						"Error: <{$argument->placeholder}> is required\n"
						. 'Usage: ' . $command->get_usage()
					);
				}
				continue;
			}
			$positional_arguments[ $argument->name ] = $value;
			++$argument_index;
		}

		$state_directory = null;
		$filesystem_root = null;
		$options         = [
			'abort'         => false,
			'verbose'       => false,
			'secret'        => null,
			'tuning_config' => [],
		];

		$argument_count = count( $arguments );
		while ( $argument_index < $argument_count ) {
			$raw_argument = $arguments[ $argument_index ];
			$match        = $this->match_option( $raw_argument, $command->get_options() );
			if ( $match === null ) {
				throw new CliInputException(
					$this->invalid_argument_message( $command, $raw_argument )
				);
			}

			$option    = $match['option'];
			$raw_value = $match['raw_value'];
			if ( $option->type === 'value-or-next' && $raw_value === null ) {
				if ( ! isset( $arguments[ $argument_index + 1 ] ) ) {
					$placeholder = $option->placeholder ?? 'VALUE';
					throw new CliInputException(
						"--{$option->name} requires one argument: {$placeholder}"
					);
				}
				$raw_value = $arguments[ ++$argument_index ];
			} elseif ( $option->type === 'two-arguments' ) {
				if ( ! isset( $arguments[ $argument_index + 1 ], $arguments[ $argument_index + 2 ] ) ) {
					$argument_labels = $option->argument_labels ?? 'ARG1 ARG2';
					throw new CliInputException(
						"--{$option->name} requires two arguments: {$argument_labels}"
					);
				}
				$raw_value = [
					$arguments[ ++$argument_index ],
					$arguments[ ++$argument_index ],
				];
			}

			$value = $option->type === 'flag'
				? $option->flag_value
				: (
				$option->type === 'two-arguments'
					? $raw_value
					: $this->cast_value( (string) $raw_value, $option )
				);
			if (
				$option->valid_values !== null
				&& ! in_array( $value, $option->valid_values, true )
			) {
				throw new CliInputException(
					"Invalid --{$option->name} value: {$raw_value}. Valid values: "
					. implode( ', ', $option->valid_values )
				);
			}

			$this->store_value(
				$option,
				$value,
				$state_directory,
				$filesystem_root,
				$options
			);
			++$argument_index;
		}

		return CliInvocation::from_parsed_input(
			$command,
			$positional_arguments,
			$state_directory,
			$filesystem_root,
			$options,
			$arguments
		);
	}

	/**
	 * @param array<int,CliOption> $options Accepted command options.
	 * @return array{option: CliOption, raw_value: mixed}|null
	 */
	private function match_option( string $argument, array $options ): ?array {
		foreach ( $options as $option ) {
			foreach ( $option->get_names() as $cli_name ) {
				if (
					$option->type === 'flag'
					&& (
						$argument === "--{$cli_name}"
						|| ( $option->short_name !== null && $argument === "-{$option->short_name}" )
					)
				) {
					return [
						'option'    => $option,
						'raw_value' => null,
					];
				}
				if ( $option->type === 'two-arguments' && $argument === "--{$cli_name}" ) {
					return [
						'option'    => $option,
						'raw_value' => null,
					];
				}
				if ( $option->type === 'value-or-next' && $argument === "--{$cli_name}" ) {
					return [
						'option'    => $option,
						'raw_value' => null,
					];
				}
				if ( $option->type === 'value' || $option->type === 'value-or-next' ) {
					$prefix = "--{$cli_name}=";
					if ( strpos( $argument, $prefix ) === 0 ) {
						return [
							'option'    => $option,
							'raw_value' => substr( $argument, strlen( $prefix ) ),
						];
					}
				}
			}
		}

		return null;
	}

	private function invalid_argument_message( CliCommand $command, string $argument ): string {
		if ( $argument === '--force-http' && $command->get_name() !== 'files-push' ) {
			return 'Error: --force-http is accepted only by files-push.';
		}
		if ( strpos( $argument, '-' ) === 0 ) {
			$option_name = explode( '=', $argument, 2 )[0];
			return "Error: {$command->get_name()} does not accept {$option_name}.";
		}
		return "Unexpected argument for {$command->get_name()}: {$argument}";
	}

	/**
	 * @return int|float|string
	 */
	private function cast_value( string $raw_value, CliOption $option ) {
		switch ( $option->cast ) {
			case 'int':
				return (int) $raw_value;
			case 'float':
				return (float) $raw_value;
			case 'size':
				return parse_size( $raw_value );
			default:
				return $raw_value;
		}
	}

	/**
	 * @param mixed $value Parsed value.
	 * @param array<string,mixed> $options Parsed options.
	 */
	private function store_value(
		CliOption $option,
		$value,
		?string &$state_directory,
		?string &$filesystem_root,
		array &$options
	): void {
		if ( $option->target === 'state_dir' ) {
			$state_directory = $value;
			return;
		}
		if ( $option->target === 'filesystem_root' ) {
			$filesystem_root = $value;
			return;
		}
		if ( strpos( $option->target, 'tuning_config.' ) === 0 ) {
			$tuning_key                              = substr( $option->target, strlen( 'tuning_config.' ) );
			$options['tuning_config'][ $tuning_key ] = $value;
			return;
		}
		if ( $option->type === 'two-arguments' || $option->repeatable ) {
			$options[ $option->target ]   = $options[ $option->target ] ?? [];
			$options[ $option->target ][] = $value;
			return;
		}
		$options[ $option->target ] = $value;
	}
}
