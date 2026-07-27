<?php

namespace Reprint\Importer\Cli;

/**
 * One positional argument in a command synopsis.
 */
class CliPositionalArgument {

	/** @var string */
	public $name;

	/** @var string */
	public $placeholder;

	/** @var string */
	public $description;

	/** @var bool */
	public $required;

	public function __construct(
		string $name,
		string $placeholder,
		string $description,
		bool $required = true
	) {
		$this->name        = $name;
		$this->placeholder = $placeholder;
		$this->description = $description;
		$this->required    = $required;
	}
}
