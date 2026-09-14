<?php
/** Pulls real Windows path selections and checks Linux filesystem limits. */
$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$source = json_decode(file_get_contents('/root/migration/source.json'), true, 512, JSON_THROW_ON_ERROR);

// The real plugin must reject path lookups before reaching native filesystem calls.
$request = curl_init($source['home'] . '/?reprint-api&endpoint=resolve_windows_path&source_path_b64=' . rawurlencode(base64_encode('D:/')));
curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($request);
$status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
curl_close($request);
if ($status !== 403) {
    throw new RuntimeException('Unauthenticated Windows path lookup must return HTTP 403; got ' . $status);
}

$failures = [];
// Use a fresh pull for each spelling; a shared state could hide a skipped selection.
foreach ($manifest['path_cases'] as $name => $case) {
    try {
        $root = '/root/path-tests/' . $name;
        $log_path = '/root/migration/path-' . $name . '.log';
        $command = [
            PHP_BINARY, 'packages/reprint-client/src/import.php', 'pull-files', $source['home'] . '/?reprint-api',
            '--secret=windows-migration-secret', '--state-dir=' . $root . '/state', '--fs-root=' . $root . '/files',
            '--progress=jsonl',
        ];
        foreach ((array) $case['source'] as $selection) {
            $command[] = '--include=' . $selection;
        }
        foreach ($case['options'] ?? [] as $option) {
            $command[] = $option;
        }
        // A repeated failure must not resume past an uninspected or unwritten file.
        for ($attempt = 1; $attempt <= (isset($case['error']) ? 2 : 1); ++$attempt) {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log_path, 'w'], 2 => ['file', $log_path, 'a']], $pipes);
            fclose($pipes[0]);
            $exit_code = proc_close($process);
            $log = file_get_contents($log_path);
            if (isset($case['error'])) {
                if ($exit_code === 0 || strpos($log, $case['error']) === false) {
                    throw new RuntimeException('Expected a clear failure for ' . $name . ', got exit ' . $exit_code . ":\n" . $log);
                }
                printf("PASS: %s attempt %d fails with %s, without reporting success.\n", $name, $attempt, $case['error']);
            } elseif ($exit_code !== 0) {
                throw new RuntimeException('Path pull failed for ' . $name . ":\n" . $log);
            }
        }
        if (isset($case['error'])) {
            continue;
        }
        $expected_files = $case['files'] ?? [$case];
        foreach ($expected_files as $expected_file) {
            $local_file = $root . '/files/' . $expected_file['destination'];
            $actual_content = is_file($local_file) ? file_get_contents($local_file) : false;
            if ($actual_content !== $expected_file['content']) {
                $actual = $actual_content === false ? 'missing file' : strlen($actual_content) . ' bytes, SHA-256 ' . hash('sha256', $actual_content);
                throw new RuntimeException(
                    'Path pull did not preserve ' . $local_file . ': expected ' . strlen($expected_file['content'])
                    . ' bytes, SHA-256 ' . hash('sha256', $expected_file['content']) . '; got ' . $actual . "\n" . $log
                );
            }
            if (basename($local_file) === 'hello.txt' && file_exists(dirname($local_file) . '/HELLO.TXT')) {
                throw new RuntimeException('The Linux target must preserve filename case, not emulate Windows lookup.');
            }
        }
        foreach ($case['directories'] ?? [] as $directory) {
            if (!is_dir($root . '/files/' . $directory)) {
                throw new RuntimeException('Missing literal empty directory: ' . $directory);
            }
        }
        foreach ($case['links'] ?? [] as $link) {
            if (!is_link($root . '/files/' . $link)) {
                throw new RuntimeException('The downloaded link must remain a link: ' . $link);
            }
        }
        foreach ($case['absent'] ?? [] as $absent) {
            if (file_exists($root . '/files/' . $absent)) {
                throw new RuntimeException('The no-follow pull downloaded an outside target: ' . $absent);
            }
        }
        if (isset($case['unique_basename'])) {
            $copies = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/files', FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getFilename() === $case['unique_basename']) {
                    ++$copies;
                }
            }
            if ($copies !== 1) {
                throw new RuntimeException('Equivalent volume paths created ' . $copies . ' copies of ' . $case['unique_basename']);
            }
        }
        printf("PASS: %s copied %d expected files.\n", $name, count($expected_files));
    } catch (Throwable $error) {
        $failures[] = $name;
        printf("FAIL: %s: %s\n", $name, $error->getMessage());
    }
}
if ($failures !== []) {
    throw new RuntimeException('Windows path cases failed: ' . implode(', ', $failures));
}
