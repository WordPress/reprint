<?php
/** Runs path preparation in a new process using the client's real state reader and writer. */
require dirname(__DIR__, 3) . '/packages/reprint-client/src/import.php';

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$client = new ImportClient($input['url'], $input['state_dir'], $input['files_dir']);
// These checks exercise source resolution, not Windows destination path spelling.
$client->filesystem_root = str_replace('\\', '/', $client->filesystem_root);
$reflection = new ReflectionClass($client);
$reflection->getProperty('state')->setValue($client, $reflection->getMethod('load_state')->invoke($client));
if (!empty($input['reset'])) {
    $reflection->getMethod('reset_state')->invoke($client);
}
$client->get_state()->set_preflight_record(['timestamp' => $input['timestamp'], 'data' => $input['preflight']]);

$error = null;
try {
    $client->prepare_files_pull_options($input['options'], false);
    // Later stages save state even in the pre-cache implementation. This lets
    // the regression reach the second request instead of failing on a missing file.
    $client->save_state();
} catch (Exception $exception) {
    $error = $exception->getMessage();
}
echo json_encode([
    'error' => $error,
    'remap' => $reflection->getProperty('resolved_path_mappings')->getValue($client),
    'include' => $reflection->getProperty('pull_only_files_with_path_prefixes')->getValue($client),
    'exclude' => $reflection->getProperty('pull_excluded_files_with_path_prefixes')->getValue($client),
], JSON_THROW_ON_ERROR);
