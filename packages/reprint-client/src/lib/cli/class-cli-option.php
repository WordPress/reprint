<?php

namespace Reprint\Importer\Cli;

/**
 * One associative argument in a command synopsis.
 *
 * 'two-arguments' --name A B (repeatable, takes 2 arguments)
 * 'state_dir' | 'filesystem_root' → special local variables
 * argument_labels Labels for two-argument type help, e.g. 'FROM TO'
 */
class CliOption {

	/** @var string */
	public $name;

	/** @var string */
	public $type;

	/** @var string */
	public $target;

	/** @var string|null */
	public $placeholder = null;

	/** @var string|null */
	public $help = null;

	/** @var string|null */
	public $main_help_section = null;

	/** @var array<int,string> */
	public $aliases = [];

	/** @var string|null */
	public $short_name = null;

	/** @var string|null */
	public $cast = null;

	/** @var mixed */
	public $flag_value = true;

	/** @var array<int,mixed>|null */
	public $valid_values = null;

	/** @var string|null */
	public $argument_labels = null;

	/** @var bool */
	public $repeatable = false;

	/** @var bool */
	public $show_in_command_help = true;

	private function __construct( string $name, string $type, string $target ) {
		$this->name   = $name;
		$this->type   = $type;
		$this->target = $target;
	}

	public static function flag( string $name, string $target, ?string $help = null ): self {
		$option       = new self( $name, 'flag', $target );
		$option->help = $help;
		return $option;
	}

	public static function value(
		string $name,
		string $target,
		string $placeholder,
		?string $help = null
	): self {
		$option              = new self( $name, 'value', $target );
		$option->placeholder = $placeholder;
		$option->help        = $help;
		return $option;
	}

	public static function value_or_next(
		string $name,
		string $target,
		string $placeholder,
		?string $help = null
	): self {
		$option              = new self( $name, 'value-or-next', $target );
		$option->placeholder = $placeholder;
		$option->help        = $help;
		return $option;
	}

	public static function two_arguments(
		string $name,
		string $target,
		string $arguments,
		?string $help = null
	): self {
		$option                  = new self( $name, 'two-arguments', $target );
		$option->argument_labels = $arguments;
		$option->help            = $help;
		return $option;
	}

	/**
	 * @param array<int,string> $aliases Alternative long option names.
	 */
	public function with_aliases( array $aliases ): self {
		$option          = clone $this;
		$option->aliases = $aliases;
		return $option;
	}

	public function with_short_name( string $short_name ): self {
		$option             = clone $this;
		$option->short_name = $short_name;
		return $option;
	}

	public function cast_as( string $cast ): self {
		$option       = clone $this;
		$option->cast = $cast;
		return $option;
	}

	/**
	 * @param mixed $value Parsed value stored when the flag is present.
	 */
	public function storing( $value ): self {
		$option             = clone $this;
		$option->flag_value = $value;
		return $option;
	}

	/**
	 * @param array<int,mixed> $valid_values Accepted parsed values.
	 */
	public function accepting( array $valid_values ): self {
		$option               = clone $this;
		$option->valid_values = $valid_values;
		return $option;
	}

	public function repeated(): self {
		$option             = clone $this;
		$option->repeatable = true;
		return $option;
	}

	public function in_main_help( string $section ): self {
		$option                    = clone $this;
		$option->main_help_section = $section;
		return $option;
	}

	public function hidden_from_command_help(): self {
		$option                       = clone $this;
		$option->show_in_command_help = false;
		return $option;
	}

	/**
	 * Return every accepted long name, with the canonical name first.
	 *
	 * @return array<int,string>
	 */
	public function get_names(): array {
		return array_merge( [ $this->name ], $this->aliases );
	}
}
