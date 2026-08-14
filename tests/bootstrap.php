<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../packages/reprint-server/src/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../packages/reprint-server/src/class-file-tree-producer.php';

require_once __DIR__ . '/../packages/reprint-server/src/class-file-index-processor.php';

// Local path-package installs can be stale until composer reinstall.
if (!function_exists('build_pdo_dsn')) {
    require_once __DIR__ . '/../packages/reprint-server/src/utils.php';
}

if (!class_exists('Site_Export_HMAC_Client', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-hmac-client.php';
}

if (!class_exists('Site_Export_HMAC_Server', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-hmac-server.php';
}

if (!class_exists('Site_Export_HTTP_Server', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-http-server.php';
}

if (!class_exists('Site_Export_Multipart_Processor', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-multipart-processor.php';
}

if (!class_exists('Site_Export_Push_Exception', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-push-exception.php';
}

if (!class_exists('Site_Export_Push_Endpoints', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-push-endpoints.php';
}

if (!class_exists('Site_Export_Push_Session', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-push-session.php';
}

/**
 * Persist a complete current pull-state schema for tests.
 *
 * @param ImportClient         $client  Client whose state directory receives the file.
 * @param array<string, mixed> $changes Values to apply to a new PullState.
 */
function write_current_pull_state(ImportClient $client, array $changes): void
{
    $data = array_replace_recursive((new PullState())->to_array(), $changes);
    $state = PullState::from_array($data);
    $property = (new ReflectionClass(ImportClient::class))->getProperty('state');
    $property->setAccessible(true);
    $property->setValue($client, $state);
    $client->save_state();
}

/**
 * Write the ownership artifacts produced for completed traversal roots.
 *
 * @param string       $pull_state_directory Pull state directory.
 * @param list<string> $remote_roots         Completed remote traversal roots.
 * @return string Opaque snapshot ID.
 */
function reprint_test_write_root_ownership_snapshot(
    string $pull_state_directory,
    array $remote_roots
): string {
    $snapshot_id = bin2hex(random_bytes(32));
    $snapshot_directory =
        $pull_state_directory . '/files-pull-ownership/snapshots';
    if (
        ! is_dir($snapshot_directory)
        && ! mkdir($snapshot_directory, 0777, true)
        && ! is_dir($snapshot_directory)
    ) {
        throw new RuntimeException(
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only temporary path, never HTML output.
            "Failed to create test ownership snapshot directory: {$snapshot_directory}."
        );
    }

    $path_rows = [];
    foreach ($remote_roots as $remote_root) {
        $kind = 'root';
        $path = $remote_root;
        while (true) {
            $line = json_encode([
                'kind' => $kind,
                'path_b64' => base64_encode($path),
            ], JSON_UNESCAPED_SLASHES) . "\n";
            $path_rows[] = [
                'kind' => $kind,
                'path' => $path,
                'line' => $line,
            ];
            if ($path === '/') {
                break;
            }
            $kind = 'ancestor';
            $path = dirname($path);
        }
    }
    usort(
        $path_rows,
        static function (array $left, array $right): int {
            return strcmp($left['line'], $right['line']);
        }
    );

    $paths = '';
    $lookup_records = [];
    foreach ($path_rows as $path_row) {
        $lookup_records[] =
            hash('sha256', $path_row['kind'] . "\0" . $path_row['path'])
            . ' ' . sprintf('%016x', strlen($paths)) . "\n";
        $paths .= $path_row['line'];
    }
    sort($lookup_records, SORT_STRING);

    $snapshot_prefix = $snapshot_directory . '/' . $snapshot_id;
    file_put_contents($snapshot_prefix . '.paths.jsonl', $paths);
    file_put_contents($snapshot_prefix . '.lookup', implode('', $lookup_records));
    return $snapshot_id;
}

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
