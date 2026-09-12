<?php
/** Pulls real Windows path selections and checks Linux filesystem limits. */
$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$source = json_decode(file_get_contents('/root/migration/source.json'), true, 512, JSON_THROW_ON_ERROR);

$failures = [];
// Use a fresh pull for each spelling; a shared state could hide a skipped selection.
foreach ($manifest['path_cases'] as $name => $case) {
    try {
        $root = '/root/path-tests/' . $name;
        $log_path = '/root/migration/path-' . $name . '.log';
        $command = [
            PHP_BINARY, 'packages/reprint-client/src/import.php', 'pull-files', $source['home'] . '/?reprint-api',
            '--secret=windows-migration-secret', '--state-dir=' . $root . '/state', '--fs-root=' . $root . '/files',
            '--include=' . $case['source'], '--progress=jsonl',
        ];
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
        $local_file = $root . '/files/' . $case['destination'];
        if (!is_file($local_file) || file_get_contents($local_file) !== $case['content']) {
            throw new RuntimeException('Path pull lost or misplaced the source file: ' . $local_file . "\n" . $log);
        }
        if (basename($local_file) === 'hello.txt' && file_exists(dirname($local_file) . '/HELLO.TXT')) {
            throw new RuntimeException('The Linux target must preserve filename case, not emulate Windows lookup.');
        }
        printf("PASS: %s -> %s\n", $case['source'], $local_file);
    } catch (Throwable $error) {
        $failures[] = $name;
        printf("FAIL: %s: %s\n", $name, $error->getMessage());
    }
}
if ($failures !== []) {
    throw new RuntimeException('Windows path cases failed: ' . implode(', ', $failures));
}
