<?php

namespace Reprint\Importer\Cli;

/**
 * Resolves the importer version from a packaged VERSION file or Git.
 */
class ImporterVersionProvider {

    /** @var string */
    private $source_directory;

    public function __construct(string $source_directory)
    {
        $this->source_directory = rtrim($source_directory, '/');
    }

    public function get_version(): string
    {
        $version_file = $this->source_directory . '/VERSION';
        if (is_file($version_file)) {
            $contents = file_get_contents($version_file);
            if ($contents !== false) {
                return trim($contents);
            }
        }

        $exact_tag = trim( (string) shell_exec('git describe --exact-match --tags HEAD 2>/dev/null'));
        if ($exact_tag !== '') {
            return $exact_tag;
        }

        $latest_tag = trim( (string) shell_exec("git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1"));
        return ( $latest_tag !== '' ? $latest_tag : 'v0.0.0' ) . '-trunk';
    }
}
