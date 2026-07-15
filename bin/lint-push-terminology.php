<?php

reprint_lint_push_terminology();

function reprint_lint_push_terminology() {
$repository_root = dirname( __DIR__ );
$fixture_path = $repository_root . '/tests/fixtures/push-terminology-denylist.json';
$rejected_spellings = json_decode( (string) file_get_contents( $fixture_path ), true );
if (!is_array($rejected_spellings)) {
    fwrite(STDERR, "Push terminology fixture is not a JSON list.\n");
    exit( 1 );
}

$strict_paths = [
    'AGENTS.md',
    'markdown/PUSH-SYNC.md',
    'markdown/PUSH-TERMINOLOGY.md',
    'packages/reprint-exporter/composer.json',
    'packages/reprint-exporter/src/export.php',
    'packages/reprint-exporter/src/class-push-exception.php',
    'packages/reprint-exporter/src/class-push-session.php',
    'packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php',
    'packages/reprint-importer/src/lib/upload/class-push-request-sizer.php',
    'reprint-exporter-wp/lib.php',
    'composer.lock',
    'reprint-exporter-wp/composer.lock',
    'tests/Import/MultipartPushStreamClientTest.php',
    'tests/HmacServerTest.php',
    'tests/PushCommitTest.php',
    'tests/PushSessionTest.php',
    'tests/bootstrap.php',
    'tests/phpunit.xml',
];

$required_fragments = [
    'packages/reprint-exporter/src/class-push-exception.php' => [
        'Site_Export_Push_Exception',
    ],
    'packages/reprint-exporter/src/class-push-session.php' => [
        'Site_Export_Push_Session',
        '/.reprint/push/',
        "'/push.json'",
        "'/commit.json'",
        'commit-state',
        'commit-state.lock',
        "'deleting_files'",
        "'installing_files'",
        "'work_deletes_byte_offset'",
        "'current_delete_path'",
        "'current_work_files_descendant'",
        "'commit_cursor'",
        "'non_recoverable_commit_failure'",
        "'lock_acquisition_failure'",
        "'filesystem_error'",
        "'corrupted_push_state'",
        "'unexpected_docroot_mutation'",
        "'same_device'",
        'reprint-push-session:',
    ],
    'packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php' => [
        "'push_upload'",
        "'push_session_id'",
    ],
    'tests/Import/MultipartPushStreamClientTest.php' => [
        'endpoint=push_upload&push_session_id=',
    ],
    'markdown/PUSH-TERMINOLOGY.md' => [
        'push.json',
        'commit.json',
        'commit-state',
        'non_recoverable_commit_failure',
    ],
];

$paths_output = [];
exec('cd ' . escapeshellarg($repository_root) . ' && git ls-files --cached --others --exclude-standard', $paths_output, $exit_code);
if ($exit_code !== 0) {
    fwrite(STDERR, "Could not list tracked and untracked files for the push terminology check.\n");
    exit( 1 );
}

$violations = [];
$mirror_directories = [
    'vendor/wp-php-toolkit/reprint-exporter/src',
    'reprint-exporter-wp/vendor/wp-php-toolkit/reprint-exporter/src',
];
$mirror_paths = [];
foreach ($mirror_directories as $mirror_directory) {
    $absolute_mirror_directory = $repository_root . '/' . $mirror_directory;
    if (!is_dir($absolute_mirror_directory)) {
        continue;
    }
    $mirror_files = [];
    exec('find ' . escapeshellarg($absolute_mirror_directory) . ' -type f', $mirror_files, $exit_code);
    if ($exit_code !== 0) {
        $violations[] = $mirror_directory . ': could not list the exporter mirror';
        continue;
    }
    foreach ($mirror_files as $mirror_file) {
        $mirror_paths[] = $mirror_file;
        $paths_output[] = substr($mirror_file, strlen($repository_root) + 1);
    }
}

foreach ($paths_output as $path) {
    foreach ($rejected_spellings as $spelling) {
        if (stripos($path, $spelling) !== false) {
            $violations[] = $path . ': filename contains a rejected spelling';
            break;
        }
    }
}

foreach ($mirror_paths as $mirror_path) {
    $contents = file($mirror_path);
    if ($contents === false) {
        $violations[] = $mirror_path . ': could not read exporter mirror file';
        continue;
    }
    foreach ($contents as $line_number => $line) {
        foreach ($rejected_spellings as $spelling) {
            if (stripos($line, $spelling) !== false) {
                $violations[] = $mirror_path . ':' . ( $line_number + 1 ) . ': contains a rejected spelling';
                break;
            }
        }
    }
}

foreach ($strict_paths as $path) {
    $absolute_path = $repository_root . '/' . $path;
    if (!is_file($absolute_path)) {
        $violations[] = $path . ': required push surface is missing';
        continue;
    }
    $contents = file($absolute_path);
    if ($contents === false) {
        $violations[] = $path . ': could not read file';
        continue;
    }
    foreach ($contents as $line_number => $line) {
        foreach ($rejected_spellings as $spelling) {
            if (stripos($line, $spelling) !== false) {
                $violations[] = $path . ':' . ( $line_number + 1 ) . ': contains a rejected spelling';
                break;
            }
        }
    }
}

foreach ($required_fragments as $path => $fragments) {
    $contents = file_get_contents($repository_root . '/' . $path);
    if (!is_string($contents)) {
        $violations[] = $path . ': could not read required push surface';
        continue;
    }
    foreach ($fragments as $fragment) {
        if (strpos($contents, $fragment) === false) {
            $violations[] = $path . ': missing required canonical fragment ' . $fragment;
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode("\n", $violations) . "\n");
    exit( 1 );
}

fwrite(STDOUT, "Push terminology check passed.\n");
}
