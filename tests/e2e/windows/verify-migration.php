<?php
/** Checks migrated files, then boots WordPress with the generated Linux runtime. */

$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$source = json_decode(file_get_contents('/root/migration/source.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['os'] !== 'Windows' || $source['os'] !== 'Windows' || PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('Expected a native Windows source and a Linux target.');
}
foreach ($manifest['files'] as $relative_path => $expected_hash) {
    // Runtime generation replaces the source database credentials in wp-config.php.
    if ($relative_path === 'wp-config.php') {
        continue;
    }
    $local_path = '/root/migration/site/' . $relative_path;
    if (!is_file($local_path) || hash_file('sha256', $local_path) !== $expected_hash) {
        throw new RuntimeException('Missing or changed migrated file: ' . $relative_path);
    }
}
if (!is_dir('/root/migration/site/wp-content/uploads/migration/empty directory')) {
    throw new RuntimeException('The empty source directory was not migrated.');
}
$start_scripts = glob('/root/migration/runtime/start.sh');
if (count($start_scripts) !== 1) {
    throw new RuntimeException('Expected one generated Linux start.sh; found ' . json_encode($start_scripts));
}
$server = proc_open(['bash', $start_scripts[0]], [0 => ['pipe', 'r'], 1 => ['file', '/root/migration/runtime.log', 'a'], 2 => ['file', '/root/migration/runtime.log', 'a']], $pipes);
try {
    $response = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $response = @file_get_contents('http://127.0.0.1:8881/migration-check.php');
        if ($response !== false) {
            break;
        }
        usleep(100000);
    }
    if ($response === false) {
        throw new RuntimeException('The migrated WordPress site did not start: ' . file_get_contents('/root/migration/runtime.log'));
    }
    $target = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    $expected = $source;
    $expected['os'] = 'Linux';
    $expected['home'] = 'http://127.0.0.1:8881';
    $expected['siteurl'] = 'http://127.0.0.1:8881';
    $expected['nested']['image']['url'] = 'http://127.0.0.1:8881/wp-content/uploads/migration/hello.txt';
    $expected['post'] = str_replace($source['home'], 'http://127.0.0.1:8881', $source['post']);
    if ($target !== $expected) {
        throw new RuntimeException('Migrated WordPress data differs: ' . json_encode(['expected' => $expected, 'actual' => $target]));
    }
    $home = file_get_contents('http://127.0.0.1:8881/');
    if (strpos($home, 'Windows migration post') === false) {
        throw new RuntimeException('The migrated homepage did not render the source post.');
    }
    if (file_get_contents('http://127.0.0.1:8881/wp-content/uploads/migration/hello.txt') !== "Hello from Windows!\r\n") {
        throw new RuntimeException('The migrated upload could not be served.');
    }
    printf("PASS: migrated %d source files, empty directory, WordPress database, nested URLs, homepage, and upload from Windows to Linux.\n", count($manifest['files']));
} finally {
    fclose($pipes[0]);
    proc_terminate($server);
    proc_close($server);
}
