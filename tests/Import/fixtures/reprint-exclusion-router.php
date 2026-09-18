<?php

require getcwd() . '/configuration.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/packages/reprint-server/src/export.php';

(new WordPress\Reprint\Server\HTTPServer(['default_directory' => ABSPATH, 'multisite' => $multisite ?? null]))->handle_request();
