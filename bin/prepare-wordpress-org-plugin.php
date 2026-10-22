<?php
/** Remove push implementations from a staged WordPress.org distribution. */

// Build failures must remain visible when the CLI disables display_errors.
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
});

$project_directory = dirname(__DIR__);
$staging_directory = isset($argv[1]) ? realpath($argv[1]) : false;
if ($argc !== 2 || $staging_directory === false || $staging_directory === realpath($project_directory)) {
    fwrite(STDERR, "Usage: php bin/prepare-wordpress-org-plugin.php <separate-staging-directory>\n");
    exit(1);
}

foreach (['reprint-server-wp', 'packages/reprint-server/src'] as $relative_directory) {
    $directory = $staging_directory . '/' . $relative_directory;
    if (!is_dir($directory) || realpath($directory) === realpath($project_directory . '/' . $relative_directory)) {
        throw new RuntimeException('The staged directory is missing or points at maintained source: ' . $directory . '.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'txt'], true)) {
            continue;
        }
        if ($relative_directory === 'packages/reprint-server/src' && preg_match('/^class-(?:push-|database-push|multipart-processor\.|mysqli-driver-pdo)/', $file->getFilename())) {
            unlink($file->getPathname());
            continue;
        }

        // Markers keep shared source in one place. Strip complete regions before
        // Composer builds its classmap, so removed classes cannot be autoloaded.
        $output = '';
        $inside_push_region = false;
        foreach (file($file->getPathname()) as $line_number => $line) {
            if (strpos($line, '@reprint-push-start') !== false) {
                if ($inside_push_region) {
                    throw new RuntimeException('Nested push region in ' . $file->getPathname() . ' on line ' . ($line_number + 1) . '.');
                }
                $inside_push_region = true;
            } elseif (strpos($line, '@reprint-push-end') !== false) {
                if (!$inside_push_region) {
                    throw new RuntimeException('Unmatched push end marker in ' . $file->getPathname() . ' on line ' . ($line_number + 1) . '.');
                }
                $inside_push_region = false;
            } elseif (!$inside_push_region) {
                $output .= preg_replace('/(?:\/\/|<!--) @reprint-pull-only (.*?)(?: -->)?\r?\n?$/', "$1\n", $line);
            }
        }
        if ($inside_push_region) {
            throw new RuntimeException('Unclosed push region in ' . $file->getPathname() . '.');
        }
        file_put_contents($file->getPathname(), $output);
    }
}

// These integration manuals describe the push-capable GitHub distribution.
// The directory package ships its target-specific readme.txt instead.
unlink($staging_directory . '/reprint-server-wp/README.md');
unlink($staging_directory . '/packages/reprint-server/README.md');
