<?php

namespace Reprint\Importer\Cli\Commands;

use Reprint\Importer\Cli\CliCommand;

/**
 * Show exporter plugin installation guidance.
 */
class InstallExporterCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'install-exporter';
    }

    public function get_level(): string
    {
        return 'high';
    }

    public function get_short_description(): string
    {
        return 'Show how to install the exporter plugin on your site';
    }

    public function get_long_description(): string
    {
        return "Prints the download URL for the exporter WordPress plugin that\n"
            . "matches this version of reprint, and step-by-step installation\n"
            . "instructions.\n"
            . "\n"
            . "The exporter plugin must be installed on the remote site before\n"
            . "any other reprint command can connect to it.\n";
    }

    public function get_positional_arguments(): array
    {
        return [];
    }

    public function requires_state_directory(): bool
    {
        return false;
    }

    public function get_filesystem_root_requirement(): string
    {
        return CliCommand::FILESYSTEM_ROOT_UNUSED;
    }

    protected function framework_options(): array
    {
        return [];
    }

    protected function command_options(): array
    {
        return [];
    }
}
