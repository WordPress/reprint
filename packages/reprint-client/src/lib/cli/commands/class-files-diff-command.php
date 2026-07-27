<?php

namespace Reprint\Importer\Cli\Commands;

/**
 * Report changes between the filesystem root and its local index.
 */
class FilesDiffCommand extends AbstractCliCommand {

    public function get_name(): string
    {
        return 'files-diff';
    }

    public function get_short_description(): string
    {
        return 'Compare local files with the local index';
    }

    public function get_usage(): string
    {
        return 'reprint files-diff <remote-reprint-api-url> --state-dir=DIR --fs-root=DIR';
    }

    public function get_long_description(): string
    {
        return "Shows which local paths a files-push would send or delete, comparing\n"
            . "the filesystem root at --fs-root with the local index for this remote\n"
            . "Reprint API URL. files-push writes that index after the target confirms\n"
            . "commit. Use the same remote Reprint API URL, state directory, and\n"
            . "filesystem root for both commands.\n"
            . "The output is a local minimized push operation plan before target\n"
            . "exclusions, not a path-for-path filesystem log. Like files-push, its\n"
            . "default-skipped paths include generated wp-content caches, version-\n"
            . "control data, node_modules, package-manager caches, OS metadata, and\n"
            . "editor scratch files.\n"
            . "Paths remain base64 text in JSONL output so\n"
            . "arbitrary filesystem names are preserved. No network calls are made\n"
            . "and no secret is required.\n";
    }

    public function get_extra_help(): ?string
    {
        return "Every run reports the complete diff from the beginning; there is\n"
            . "no partial resume to continue.\n";
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
        ];
    }
}
