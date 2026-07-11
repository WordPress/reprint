<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../packages/reprint-exporter/src/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../packages/reprint-exporter/src/class-file-tree-producer.php';

// Local path-package installs can be stale until composer reinstall.
if (!function_exists('build_pdo_dsn')) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/utils.php';
}

if (!class_exists('Site_Export_HMAC_Client', false)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-hmac-client.php';
}

if (!class_exists('Site_Export_HMAC_Server', false)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-hmac-server.php';
}

if (!class_exists('Site_Export_HTTP_Server', false)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-http-server.php';
}

if (!class_exists('Site_Export_Staged_Push_Stream_Protocol', false)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-push-stream-protocol.php';
}

if (!class_exists('Site_Export_Staged_Endpoints', false)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-endpoints.php';
}

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
