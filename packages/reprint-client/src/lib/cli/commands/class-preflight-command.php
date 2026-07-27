<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Probe and cache the remote environment.
 */
class PreflightCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'preflight';
    }

    public function get_short_description(): string
    {
        return 'Probe the remote site and cache its environment';
    }

    public function get_long_description(): string
    {
        return "Contacts the remote site and collects environment details:\n"
            . "PHP/MySQL versions, memory limits, filesystem access, database\n"
            . "connectivity, WordPress version, plugins, themes, directory layout,\n"
            . "and runtime scripts (auto_prepend_file, auto_append_file).\n"
            . "\n"
            . "Results are saved to state for use by later commands.\n"
            . "Prints the full response as pretty-printed JSON.\n"
            . "Exits 0 if the site reported OK, 1 otherwise.\n";
    }

    protected function command_options(): array
    {
        return [
            $this->state_directory_option(),
            $this->filesystem_root_option(),
            $this->secret_option(),
        ];
    }
}
