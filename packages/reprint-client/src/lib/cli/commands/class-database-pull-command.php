<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Pull the remote database as SQL.
 */
class DatabasePullCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'db-pull';
    }

    public function get_aliases(): array
    {
        return ['db-sync'];
    }

    public function get_short_description(): string
    {
        return 'Pull the database as a SQL dump (index + download)';
    }

    public function get_long_description(): string
    {
        return "Indexes remote tables, then streams the full SQL dump into\n"
            . "--state-dir/db.sql (default), to stdout, or directly into a\n"
            . "MySQL connection. Resumes from the last cursor if interrupted.\n"
            . "Discovered domains are cached for later use by db-apply.\n";
    }

    public function get_extra_help(): ?string
    {
        return "Output modes:\n"
            . "  file    Write to --state-dir/db.sql (default)\n"
            . "  stdout  Write raw SQL to stdout; progress goes to stderr\n"
            . "  mysql   Stream directly into a MySQL connection\n";
    }

    protected function command_options(): array
    {
        return [
            $this->state_directory_option(),
            $this->filesystem_root_option(),
            $this->secret_option(),
            $this->abort_option(),
            $this->verbose_option(),
            $this->maximum_allowed_packet_option(),
            ...$this->database_pull_output_options(),
        ];
    }
}
