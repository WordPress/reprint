#!/usr/bin/env php
<?php

declare(strict_types=1);

use WordPress\Reprint\Build\Php56SyntaxValidator;

const REPRINT_PHP56_TEMPORARY_VARIABLE_PREFIX = '$__reprint_php56_';

$project_root = dirname(__DIR__);
$tool_root = $project_root . '/tools/php56-build';
$autoload = $tool_root . '/vendor/autoload.php';
$rector = $tool_root . '/vendor/bin/rector';

if ($argc !== 2) {
    fail('Usage: php bin/downgrade-exporter-plugin.php <staging-root>');
}
if (!is_file($autoload) || !is_file($rector)) {
    fail('Install the PHP 5.6 build tool with: composer install --no-dev --working-dir=tools/php56-build');
}

$staging_root = realpath($argv[1]);
if ($staging_root === false || !is_dir($staging_root)) {
    fail(sprintf('The staging root does not exist: %s', $argv[1]));
}
if ($staging_root === realpath($project_root)) {
    fail('Refusing to downgrade the maintained source tree. Pass a separate staging root.');
}

$paths = [
    $staging_root . '/packages/reprint-server/src',
    $staging_root . '/reprint-server-wp/index.php',
    $staging_root . '/reprint-server-wp/lib.php',
    $staging_root . '/reprint-server-wp/compat.php',
    $staging_root . '/reprint-server-wp/wordpress',
];
foreach ($paths as $path) {
    if (!file_exists($path)) {
        fail(sprintf('The exporter staging tree is incomplete. Missing %s.', $path));
    }
    $source_path = $project_root . substr($path, strlen($staging_root));
    if (realpath($path) === realpath($source_path)) {
        fail(sprintf('Refusing to downgrade a path from the maintained source tree: %s.', $path));
    }
}

assert_reserved_variables_are_unused($paths);

$arguments = array_map('escapeshellarg', $paths);
$command = escapeshellarg($rector)
    . ' process '
    . implode(' ', $arguments)
    . ' --config '
    . escapeshellarg($tool_root . '/rector.php')
    . ' --no-progress-bar --no-diffs --clear-cache';
passthru($command, $status);
if ($status !== 0) {
    fail(sprintf('The PHP 5.6 Rector downgrade failed with exit code %d.', $status));
}

require_once $autoload;

try {
    (new Php56SyntaxValidator())->assertPaths($paths);
} catch (Throwable $throwable) {
    fail($throwable->getMessage());
}

echo "Generated exporter PHP contains none of the unsupported syntax checked by the PHP 5.6 build.\n";

/**
 * @param string[] $paths Files and directories to inspect.
 */
function assert_reserved_variables_are_unused(array $paths): void
{
    foreach (php_files($paths) as $file) {
        $code = file_get_contents($file);
        if ($code === false) {
            fail(sprintf('Could not read staged PHP file %s.', $file));
        }
        foreach (token_get_all($code) as $token) {
            if (
                is_array($token)
                && $token[0] === T_VARIABLE
                && strncmp($token[1], REPRINT_PHP56_TEMPORARY_VARIABLE_PREFIX, strlen(REPRINT_PHP56_TEMPORARY_VARIABLE_PREFIX)) === 0
            ) {
                fail(sprintf(
                    'Staged PHP uses the reserved downgrade variable %s in %s on line %d.',
                    $token[1],
                    $file,
                    $token[2]
                ));
            }
        }
    }
}

/**
 * @param string[] $paths Files and directories to inspect.
 * @return string[]
 */
function php_files(array $paths): array
{
    $files = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            if (substr($path, -4) === '.php') {
                $files[] = $path;
            }
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
