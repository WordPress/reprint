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
}

$files = new DirectoryIterator($staging_directory . '/packages/reprint-server/src');
foreach ($files as $file) {
    if ($file->isFile() && preg_match('/^class-(?:push-|database-push|multipart-processor\.|mysqli-driver-pdo)/', $file->getFilename())) {
        unlink($file->getPathname());
    }
}

$features_file = $staging_directory . '/packages/reprint-server/src/build-features.php';
$features = str_replace(
    "define('REPRINT_PUSH_AVAILABLE', true);",
    "define('REPRINT_PUSH_AVAILABLE', false);",
    file_get_contents($features_file),
    $replacements
);
if ($replacements !== 1) {
    throw new RuntimeException('Expected one enabled REPRINT_PUSH_AVAILABLE definition in ' . $features_file . '; observed ' . $replacements . '.');
}
file_put_contents($features_file, $features);

// These integration manuals describe the push-capable GitHub distribution.
// The directory package ships readme.txt, which describes both distributions.
unlink($staging_directory . '/reprint-server-wp/README.md');
unlink($staging_directory . '/packages/reprint-server/README.md');
