<?php

require dirname(__DIR__, 2) . '/packages/reprint-client/src/import.php';
require dirname(__DIR__, 2) . '/packages/reprint-client/src/lib/database-push/class-database-push-processor.php';

$client = new MultipartPushStreamClient([
    'remote_reprint_api_url' => $argv[1],
    'allow_http' => true,
    'hmac_client' => new Site_Export_HMAC_Client('database-push-test-secret'),
    'request_context_headers' => ['User-Agent' => 'Reprint database push test'],
    'chunk_bytes' => 16384,
]);
$processor = DatabasePushProcessor::start($client, $argv[2], [
    'dsn' => 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    'user' => getenv('DB_USER'),
    'pass' => getenv('DB_PASS'),
], 'wp_', ['https://local.test' => 'https://production.example.com']);
try {
    while ($processor->next_step()) {
        echo $processor->get_status()['phase'] . "\n";
        flush();
        // This caller yields between real bounded steps. The parent kills it
        // while its multipart request is open, without running finally/close.
        usleep(20000);
    }
} finally {
    $processor->close();
    $client->close();
}
