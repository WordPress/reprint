<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Pull and apply the database through the high-level pipeline.
 */
class PullDatabaseCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'pull-db';
    }

    public function get_level(): string
    {
        return 'high';
    }

    public function get_short_description(): string
    {
        return 'Pull and apply the database through the high-level pull pipeline';
    }

    public function get_long_description(): string
    {
        return "Runs the database side of the pull pipeline:\n"
            . "\n"
            . "  1. Preflight — probe the remote site environment\n"
            . "  2. db-pull — download the SQL dump into --state-dir/db.sql\n"
            . "  3. db-apply — apply the dump to a local database\n"
            . "\n"
            . "This gives the database the same retry and resume behavior as pull,\n"
            . "without running the file or runtime stages. With no MySQL target\n"
            . "options, pull-db applies the dump to SQLite by default.\n";
    }

    public function get_extra_help(): ?string
    {
        return "Examples:\n"
            . "  reprint pull-db https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
            . "    --target-engine=sqlite\n"
            . "\n"
            . "  reprint pull-db https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
            . "    --target-user=root --target-db=wp_local \\\n"
            . "    --new-site-url=http://localhost:8881\n";
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
            ...$this->database_target_options(),
        ];
    }
}
