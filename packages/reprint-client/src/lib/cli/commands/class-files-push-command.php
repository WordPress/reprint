<?php

namespace Reprint\Importer\Cli\Commands;

use Reprint\Importer\Cli\CliOption;

/**
 * Push one filesystem root to a remote Reprint API.
 */
class FilesPushCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'files-push';
    }

    public function get_short_description(): string
    {
        return 'Push one local file tree without database work';
    }

    public function get_usage(): string
    {
        return 'reprint files-push <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR --secret=TOKEN [--force-http] [--verbose]';
    }

    public function get_long_description(): string
    {
        return "Sends the existing filesystem root at --fs-root to the remote Reprint API.\n"
            . "This is a low-level, files-only command: it performs no database work,\n"
            . "plan display, confirmation prompt, automatic retry, or automatic restart.\n"
            . "It does not require pull preflight.\n"
            . "\n"
            . "Each process runs one sender until it completes, reaches a caller time or\n"
            . "memory boundary, or receives a signal handled by this PHP runtime.\n"
            . "Re-run the same command after exit 2.\n"
            . "After a restart result, the next run starts a fresh plan.\n";
    }

    public function get_extra_help(): ?string
    {
        return "Exit outcomes:\n"
            . "  0  File push complete\n"
            . "  2  Partial, interrupted, or restart; run the command again\n"
            . "  1  Failed request or command error\n";
    }

    protected function framework_options(): array
    {
        return [];
    }

    protected function command_options(): array
    {
        return [
            $this->state_directory_option(),
            $this->filesystem_root_option(false),
            $this->secret_option(),
            CliOption::flag(
                'force-http',
                'force_http',
                'Allow a trusted plain-HTTP target; anyone able to observe or alter the connection can read or modify transferred content'
            ),
            $this->verbose_option(),
        ];
    }
}
