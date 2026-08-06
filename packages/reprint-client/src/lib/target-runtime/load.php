<?php
/**
 * Target runtime applier loader.
 */

require_once __DIR__ . '/route-handlers/wpcloud-thumbnail-generator.php';
require_once __DIR__ . '/route-handlers/remote-upload-proxy.php';
require_once __DIR__ . '/interface-runtime-applier.php';
require_once __DIR__ . '/class-nginx-fpm-applier.php';
require_once __DIR__ . '/class-php-builtin-applier.php';
require_once __DIR__ . '/class-playground-cli-applier.php';
require_once __DIR__ . '/functions.php';
