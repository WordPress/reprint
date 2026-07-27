<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Extract domains from the pulled SQL dump.
 */
class DatabaseDomainsCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'db-domains';
    }

    public function get_short_description(): string
    {
        return 'Extract domains from the pulled SQL dump';
    }

    public function get_long_description(): string
    {
        return "Prints domains found in the SQL dump, one per line.\n"
            . "\n"
            . "If <remote-state-directory>/pull/domains.json exists (cached by db-pull), it is read\n"
            . "directly. Otherwise, db.sql is scanned and the result is cached\n"
            . "for future calls. No network calls.\n"
            . "\n"
            . "Example:\n"
            . "  reprint db-domains https://example.com --state-dir=/path/to/state\n";
    }

    protected function command_options(): array
    {
        return [
            $this->state_directory_option(),
            $this->filesystem_root_option(),
        ];
    }
}
