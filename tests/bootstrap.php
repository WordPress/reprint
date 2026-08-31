<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../packages/reprint-server/src/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../packages/reprint-server/src/class-file-tree-producer.php';

require_once __DIR__ . '/../packages/reprint-server/src/class-file-index-processor.php';

// Local path-package installs can be stale until composer reinstall.
if (!function_exists('WordPress\\Reprint\\Server\\build_pdo_dsn')) {
    require_once __DIR__ . '/../packages/reprint-server/src/utils.php';
}

if (!class_exists('Site_Export_HMAC_Client', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-hmac-client.php';
}

if (!class_exists('WordPress\\Reprint\\Server\\HMACServer', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-hmac-server.php';
}

if (!class_exists('WordPress\\Reprint\\Server\\HTTPServer', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-http-server.php';
}

if (!class_exists('WordPress\\Reprint\\Server\\MultipartProcessor', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-multipart-processor.php';
}

if (!class_exists('WordPress\\Reprint\\Server\\PushException', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-push-exception.php';
}

if (!class_exists('WordPress\\Reprint\\Server\\PushEndpoints', false)) {
    require_once __DIR__ . '/../packages/reprint-server/src/class-push-endpoints.php';
}

if (!class_exists('WordPress\\Reprint\\Server\\PushSession', false)) {
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

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
