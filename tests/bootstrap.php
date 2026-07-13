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

// The exporter is installed from a Composer path package in CI and release
// tests. Require the checkout copies here so tests exercise a changed source
// file before the package mirror is refreshed.
require_once __DIR__ . '/../packages/reprint-exporter/src/class-multipart-stream-parser.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-multipart-stream-input.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-apply-session.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-endpoints.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-staged-session-recovery-server.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-http-server.php';

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
