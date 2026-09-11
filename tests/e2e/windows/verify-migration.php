<?php
/** Checks migrated files, then boots WordPress with the generated Linux runtime. */

$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$source = json_decode(file_get_contents('/root/migration/source.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['os'] !== 'Windows' || $source['os'] !== 'Windows' || PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('Expected a native Windows source and a Linux target.');
}
foreach ($manifest['files'] as $relative_path => $expected_hash) {
    // Flattening may rewrite paths in wp-config.php; the runtime supplies target DB constants.
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
foreach (['runtime', 'raw-runtime'] as $runtime) {
    $start_script = '/root/migration/' . $runtime . '/start.sh';
    if (!is_file($start_script)) {
        throw new RuntimeException('The Linux runtime start.sh was not generated.');
    }
    $server = proc_open(['bash', $start_script], [0 => ['pipe', 'r'], 1 => ['file', '/root/migration/runtime.log', 'a'], 2 => ['file', '/root/migration/runtime.log', 'a']], $pipes);
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

}

// Use a fresh pull for each spelling; a shared state could hide a skipped selection.
foreach ($manifest['path_cases'] as $name => $case) {
    $root = '/root/path-tests/' . $name;
    $log_path = '/root/migration/path-' . $name . '.log';
    $command = [
        PHP_BINARY, 'packages/reprint-client/src/import.php', 'files-pull', $source['home'] . '/?reprint-api',
        '--secret=windows-migration-secret', '--state-dir=' . $root . '/state', '--fs-root=' . $root . '/files',
        '--include=' . $case['source'], '--progress=jsonl',
    ];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log_path, 'w'], 2 => ['file', $log_path, 'a']], $pipes);
    fclose($pipes[0]);
    $exit_code = proc_close($process);
    $log = file_get_contents($log_path);
    if (isset($case['error'])) {
        if ($exit_code === 0 || strpos($log, $case['error']) === false) {
            throw new RuntimeException('Expected a clear failure for ' . $name . ', got exit ' . $exit_code . ":\n" . $log);
        }
        printf("PASS: %s fails with %s, without reporting success.\n", $name, $case['error']);
        continue;
    }
    if ($exit_code !== 0) {
        throw new RuntimeException('Path pull failed for ' . $name . ":\n" . $log);
    }
    $local_file = $root . '/files/' . $case['destination'] . '/hello.txt';
    if (!is_file($local_file) || file_get_contents($local_file) !== $case['content']) {
        throw new RuntimeException('Path pull lost or misplaced the source file: ' . $local_file . "\n" . $log);
    }
    printf("PASS: %s -> %s\n", $case['source'], $local_file);
}
