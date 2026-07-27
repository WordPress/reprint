<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Assert that the remote site can be mirrored.
 */
class PreflightAssertCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'preflight-assert';
    }

    public function get_short_description(): string
    {
        return 'Verify the remote site can be mirrored (exits 0 or 1)';
    }

    public function get_long_description(): string
    {
        return "Runs the same check as the preflight command, then evaluates\n"
            . "key assertions:\n"
            . "\n"
            . "  - Remote site responded with HTTP 200\n"
            . "  - Preflight OK flag is set\n"
            . "  - Filesystem directories are accessible\n"
            . "  - Database connection works\n"
            . "\n"
            . "Prints a PASS/FAIL summary and exits 0 if all checks pass, 1 if not.\n";
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
