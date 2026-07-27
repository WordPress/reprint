<?php

namespace Reprint\Importer\Cli;

/**
 * A registered command and the synopsis used to parse it.
 */
interface CliCommand {

    public const FILESYSTEM_ROOT_REQUIRED = 'required';
    public const FILESYSTEM_ROOT_OR_FLAT  = 'root_or_flat';
    public const FILESYSTEM_ROOT_UNUSED   = 'unused';

    public function get_name(): string;

    /**
     * @return array<int,string>
     */
    public function get_aliases(): array;

    public function get_level(): string;

    public function get_short_description(): string;

    public function get_long_description(): string;

    public function get_extra_help(): ?string;

    public function get_usage(): string;

    /**
     * @return array<int,CliPositionalArgument>
     */
    public function get_positional_arguments(): array;

    /**
     * @return array<int,CliOption>
     */
    public function get_options(): array;

    public function requires_state_directory(): bool;

    public function get_filesystem_root_requirement(): string;
}
