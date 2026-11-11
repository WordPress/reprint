<?php
/**
 * Pulls the linked Linux source with a native Windows client, in the order a
 * desktop app runs the commands on a first pull, then boots the result.
 */

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('The client must run native Windows PHP.');
}
[$script, $source_url, $private_key_path, $work_directory] = $argv;
$import_script = dirname(__DIR__, 3) . '/packages/reprint-client/src/import.php';
$state_directory = $work_directory . '\\state';
$filesystem_root = $work_directory . '\\files';
$site_directory = $work_directory . '\\site';
$runtime_directory = $work_directory . '\\runtime';
$sqlite_path = $site_directory . '\\wp-content\\database\\.ht.sqlite';
$site_url = 'http://127.0.0.1:8883';

// A blank local install with a plugin the source does not have.
$wordpress_zip = $work_directory . '\\wordpress.zip';
copy('https://wordpress.org/wordpress-6.7.zip', $wordpress_zip);
$archive = new ZipArchive();
if ($archive->open($wordpress_zip) !== true || !$archive->extractTo($work_directory)) {
    throw new RuntimeException('Could not extract the blank local WordPress.');
}
$archive->close();
rename($work_directory . '\\wordpress', $site_directory);
mkdir($site_directory . '\\wp-content\\plugins\\local-only', 0777, true);
file_put_contents($site_directory . '\\wp-content\\plugins\\local-only\\local-only.php', "<?php\n/* Plugin Name: Local only */\n");
mkdir(dirname($sqlite_path), 0777, true);

$connection = [$source_url, '--allow-unsafe-http', '--private-key-path=' . $private_key_path, '--no-adaptive', '--state-dir=' . $state_directory, '--progress=jsonl'];
$commands = [
    'pull-files' => ['--mode=mirror', '--fs-root=' . $filesystem_root],
    'pull-db' => ['--target-engine=sqlite', '--target-sqlite-path=' . $sqlite_path, '--new-site-url=' . $site_url, '--fs-root=' . $filesystem_root],
    'merge-wp-content' => ['--from=' . $site_directory . '\\wp-content', '--fs-root=' . $filesystem_root],
    'flat-docroot' => ['--flatten-to=' . $site_directory, '--core-files=copy', '--force', '--fs-root=' . $filesystem_root],
    'apply-runtime' => ['--runtime=php-builtin', '--start-runtime=none', '--target-engine=sqlite', '--target-sqlite-path=' . $sqlite_path, '--output-dir=' . $runtime_directory, '--flat-document-root=' . $site_directory],
];
foreach ($commands as $command => $arguments) {
    run_client(array_merge([PHP_BINARY, $import_script, $command], $connection, $arguments), $work_directory . '\\' . $command . '.log');
}
// A later pull flattens again without --force; the links it made must count as its own.
run_client(array_merge([PHP_BINARY, $import_script, 'flat-docroot'], $connection, ['--flatten-to=' . $site_directory, '--core-files=copy', '--fs-root=' . $filesystem_root]), $work_directory . '\\flat-docroot-again.log');

$expected_files = [
    'wp-content\\plugins\\managed-plugin\\managed-plugin.php' => 'Plugin Name: Managed plugin',
    'wp-content\\plugins\\local-only\\local-only.php' => 'Plugin Name: Local only',
    'wp-content\\uploads\\2026\\01\\hello.txt' => 'Uploaded on Linux',
    'wp-config.php' => 'wpcloud_source',
    'wp-includes\\version.php' => '$wp_version',
];
foreach ($expected_files as $relative_path => $expected_contents) {
    $contents = @file_get_contents($site_directory . '\\' . $relative_path);
    if ($contents === false || strpos($contents, $expected_contents) === false) {
        describe_failure($site_directory, $relative_path, $state_directory . '\\audit.log');
        throw new RuntimeException("The flattened site does not read {$relative_path} through its links.");
    }
}

$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8883', '-t', $site_directory, $runtime_directory . '\\runtime.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $work_directory . '\\runtime.log', 'a'], 2 => ['file', $work_directory . '\\runtime.log', 'a']],
    $pipes
);
try {
    $homepage = false;
    for ($attempt = 0; $attempt < 50 && $homepage === false; ++$attempt) {
        usleep(200000);
        $homepage = @file_get_contents($site_url . '/');
    }
    foreach (['Linked Linux source post', 'Managed plugin output'] as $expected_text) {
        if ($homepage === false || strpos($homepage, $expected_text) === false) {
            throw new RuntimeException("The pulled site's homepage does not show \"{$expected_text}\":\n" . $homepage . "\n" . file_get_contents($work_directory . '\\runtime.log'));
        }
    }
    if (@file_get_contents($site_url . '/wp-content/uploads/2026/01/hello.txt') !== "Uploaded on Linux\n") {
        throw new RuntimeException('The pulled site does not serve its upload.');
    }
} finally {
    proc_terminate($server);
    proc_close($server);
}
echo "PASS: a native Windows client pulled, merged, flattened, and booted the linked Linux source.\n";

/** Runs one client command and stops with its output when it fails. */
function run_client(array $command, string $log_path): void
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log_path, 'w'], 2 => ['file', $log_path, 'a']], $pipes);
    fclose($pipes[0]);
    $exit_code = proc_close($process);
    echo "== {$command[2]} exited {$exit_code}\n";
    if ($exit_code !== 0) {
        throw new RuntimeException($command[2] . " failed:\n" . file_get_contents($log_path));
    }
}

/** Prints each link along a path and the audit lines naming its components. */
function describe_failure(string $site_directory, string $relative_path, string $audit_log_path): void
{
    $path = $site_directory;
    $names = [];
    foreach (explode('\\', $relative_path) as $component) {
        $path .= '\\' . $component;
        $names[] = $component;
        printf(
            "%s: exists=%s link=%s dir=%s readlink=%s realpath=%s\n",
            $path,
            var_export(file_exists($path), true),
            var_export(is_link($path), true),
            var_export(is_dir($path), true),
            var_export(@readlink($path), true),
            var_export(realpath($path), true)
        );
    }
    $searched_name = $names[count($names) - 2];
    foreach (file($audit_log_path) ?: [] as $line) {
        if (stripos($line, $searched_name) !== false) {
            echo substr($line, 0, 600), "\n";
        }
    }
    $pulled_tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($site_directory) . '\\files', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($pulled_tree as $pulled_path => $info) {
        if (stripos($pulled_path, $searched_name) !== false) {
            printf("pulled: %s link=%s\n", $pulled_path, var_export(is_link($pulled_path), true));
        }
    }
}
