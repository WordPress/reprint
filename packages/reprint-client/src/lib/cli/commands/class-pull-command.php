<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Clone a complete remote site.
 */
class PullCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'pull';
    }

    public function get_level(): string
    {
        return 'high';
    }

    public function get_short_description(): string
    {
        return 'Clone a remote site (preflight + files + database + apply)';
    }

    public function get_long_description(): string
    {
        return "Full site clone in a single command. Composes lower-level commands into\n"
            . "a resumable pipeline:\n"
            . "\n"
            . "  1. Preflight — probe the remote site environment\n"
            . "  2. Files     — download all remote files into --fs-root\n"
            . "  3. Database  — download the SQL dump\n"
            . "  4. Apply     — apply SQL to a local database (if --target-db)\n"
            . "  5. Flatten   — reassemble into standard WP layout (if --flatten-to)\n"
            . "  6. Runtime   — generate server config (default: php-builtin)\n"
            . "  7. Start     — launch the selected runtime when supported\n"
            . "\n"
            . "Each step resumes automatically after an interrupted response. If the process is\n"
            . "interrupted, re-run the same command to resume from where it left off.\n"
            . "Running pull again after completion performs a delta sync.\n"
            . "\n"
            . "Use --filter=essential-files to defer uploads and other large wp-content\n"
            . "entries while still completing the rest of the pull.\n"
            . "\n"
            . "The ?site-export-api query parameter is added automatically if missing,\n"
            . "so you can pass just the site URL.\n";
    }

    public function get_extra_help(): ?string
    {
        return "Examples:\n"
            . "  # Download files and database without applying SQL:\n"
            . "  reprint pull https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files\n"
            . "\n"
            . "  # Full clone with MySQL database apply and URL rewriting:\n"
            . "  reprint pull https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
            . "    --target-user=root --target-db=wp_local \\\n"
            . "    --new-site-url=http://localhost:8881\n"
            . "\n"
            . "  # Complete the main pull now, defer the heavier file tail:\n"
            . "  reprint pull https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
            . "    --filter=essential-files --target-engine=sqlite --runtime=none\n"
            . "\n"
            . "  # Full clone with SQLite, flattened layout, and PHP built-in server:\n"
            . "  reprint pull https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
            . "    --target-engine=sqlite \\\n"
            . "    --new-site-url=http://localhost:8881 \\\n"
            . "    --flatten-to=./site --runtime=php-builtin --output-dir=./runtime\n"
            . "\n"
            . "  # Prepare a Playground runtime but let another process start it:\n"
            . "  reprint pull https://example.com \\\n"
            . "    --secret=TOKEN --state-dir=./state --fs-root=./files \\\n"
            . "    --runtime=playground-cli --start-runtime=none --output-dir=./runtime\n";
    }

    protected function command_options(): array
    {
        return [
            $this->state_directory_option(),
            $this->filesystem_root_option(),
            $this->secret_option(),
            $this->abort_option(),
            $this->verbose_option(),
            $this->no_follow_symlinks_option(),
            ...$this->follow_symlinks_options(),
            $this->filesystem_nonempty_option(),
            $this->include_caches_option(),
            $this->filter_option(),
            ...$this->database_target_options(),
            $this->flatten_target_option(),
            $this->force_option(),
            $this->runtime_option(),
            $this->start_runtime_option(),
            $this->output_directory_option(),
        ];
    }
}
