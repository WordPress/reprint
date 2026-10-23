<?php

require __DIR__ . '/../../../packages/reprint-client/src/import.php';

$reprint_index_client = new ImportClient($argv[1], $argv[2] . '/state', $argv[2] . '/files', ['allow_http' => true]);
$reprint_index_reflection = new ReflectionClass($reprint_index_client);
$reprint_index_reflection->getProperty('state')->setValue(
    $reprint_index_client,
    $reprint_index_reflection->getMethod('load_state')->invoke($reprint_index_client)
);
$reprint_index_client->get_state()->set_preflight_record([
    'data' => ['wp_detect' => ['roots' => [['path' => $argv[2] . '/remote']]]],
]);
$reprint_index_reflection->getMethod('initialize_tuner')->invoke($reprint_index_client, [
    'tuning_config' => ['index_batch_start' => 50000, 'duty' => 1],
]);
$reprint_index_client->save_state();
do {
    $reprint_index_complete = $reprint_index_reflection->getMethod('fetch_next_remote_index')->invoke($reprint_index_client);
} while (!$reprint_index_complete);
file_put_contents($argv[2] . '/result.json', json_encode(['peak_memory' => memory_get_peak_usage(true)]));
